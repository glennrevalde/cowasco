<?php
declare(strict_types=1);

/**
 * Loans: products and charges, applications and their approval, release with the charges and net proceeds,
 * the amortization schedule, collection with the payment allocation, penalties, the loan subsidiary ledger,
 * adjustments, waivers, restructuring and write-off.
 *
 * Every released loan keeps its own copy of the rate, method, penalty terms and schedule: a later change to
 * the product never touches it.
 */
final class Loans
{
    public const INTEREST_METHODS = ['diminishing' => 'Diminishing balance', 'straight' => 'Straight (add-on)'];
    public const AMORT_TYPES = ['equal_amortization' => 'Equal loan (equal amortization)', 'equal_principal' => 'Fixed principal (equal principal)'];
    public const CHARGE_METHODS = ['deducted' => 'Deducted from loan approved', 'separate' => 'Collected separately', 'capitalized' => 'Added to the loan (capitalized)'];
    public const CHARGE_BY = ['none' => 'None', 'fixed_rate' => 'Fixed rate (% of the loan)', 'fixed_amount' => 'Fixed amount', 'rate_by_term' => 'Customize rate by term range',
        'rate_by_amount' => 'Customize rate by amount range', 'amount_by_term' => 'Customize amount by term range', 'amount_by_amount' => 'Customize amount by amount range'];
    public const CHARGE_TYPES = ['financial' => 'Financial (income of the cooperative)', 'non_financial' => 'Non-financial (payable to a third party)', 'savings' => 'Savings (credited to a member savings / share capital product)'];
    /** Additional interest when a loan is past due (computed by the Internal Ledger of the loan, by the product) */
    public const ADDL_INTEREST = [
        'auto'     => 'By the interest method: diminishing loans on each missed amortization, add-on loans after maturity',
        'arrears'  => 'On the principal of each missed amortization, from its due date',
        'maturity' => 'On the principal balance after the maturity date',
        'none'     => 'None',
    ];
    public const PENALTY_BASES = ['arrears' => 'Loan arrears (unpaid principal + interest)', 'principal_arrears' => 'Principal in arrears', 'balance' => 'Outstanding balance'];
    public const APP_STATUSES = ['new' => 'New application', 'validated' => 'Validated', 'for_ci' => 'For credit investigation', 'for_approval' => 'For approval', 'approved' => 'Approved',
        'disapproved' => 'Disapproved', 'returned' => 'Returned for correction', 'released' => 'Released', 'cancelled' => 'Cancelled'];
    /** open: current, past_due, restructured (a loan that came out of a restructuring); closed: paid, renewed (paid off by a new loan), written_off, cancelled */
    public const LOAN_STATUSES = ['current' => 'Current', 'past_due' => 'Past due', 'restructured' => 'Restructured', 'paid' => 'Fully paid', 'renewed' => 'Renewed (paid by a new loan)',
        'written_off' => 'Written off', 'cancelled' => 'Cancelled'];
    public const OPEN_STATUSES = ['current', 'past_due', 'restructured'];

    private static array $products = [];

    // ------------------------------------------------------------------ products and charges

    public static function product(int $id): ?array
    {
        return self::$products[$id] ??= DB::row('SELECT * FROM loan_products WHERE id = :id', ['id' => $id]);
    }

    public static function resetProducts(): void
    {
        self::$products = [];
    }

    public static function productOptions(bool $activeOnly = true): array
    {
        $out = [];
        foreach (DB::all('SELECT id, code, name FROM loan_products' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, code') as $r) {
            $out[(int)$r['id']] = $r['code'] . ' - ' . $r['name'];
        }
        return $out;
    }

    /** The rate for a term and amount: a matching term/amount tier effective on the date, else the product default */
    public static function rateFor(array $p, string $term, string $amount, string $date): string
    {
        $rows = DB::all('SELECT * FROM loan_product_rates WHERE product_id = :p AND effective_date <= :d ORDER BY effective_date DESC, id DESC', ['p' => $p['id'], 'd' => $date]);
        foreach ($rows as $r) {
            $termOk = (float)$r['term_to'] <= 0 || ((float)$term >= (float)$r['term_from'] && (float)$term <= (float)$r['term_to']);
            $amtOk = !Dec::isPos($r['amount_to']) || (Dec::cmp($amount, $r['amount_from']) >= 0 && Dec::cmp($amount, $r['amount_to']) <= 0);
            if ($termOk && $amtOk) {
                return Dec::of($r['rate']);
            }
        }
        return Dec::of($p['rate_default']);
    }

    /** The charges of a product; with a date, only those in effect on it (a charge with an "Effective from" date starts then) */
    public static function productCharges(int $productId, ?string $date = null): array
    {
        return DB::all('SELECT c.*, pc.sort_order FROM loan_product_charges pc JOIN loan_charges c ON c.id = pc.charge_id WHERE pc.product_id = :p AND c.is_active = 1'
            . ($date ? ' AND (c.effective_date IS NULL OR c.effective_date <= :d)' : '') . ' ORDER BY pc.sort_order, c.name', ['p' => $productId] + ($date ? ['d' => $date] : []));
    }

    /** The amount of one charge for a loan amount and term: [amount, basis text, the rate (%) it was worked out with or null] */
    public static function chargeAmount(array $c, string $amount, string $term): array
    {
        $by = $c['charge_by'];
        if ($by === 'none') {
            return ['0', '', null];
        }
        if ($by === 'fixed_rate') {
            return [Dec::pct($amount, $c['value']), Dec::fix($c['value']) . ' %', Dec::fix($c['value'])];
        }
        if ($by === 'fixed_amount') {
            return [Dec::round($c['value'], 2), 'fixed ' . Dec::money($c['value']), null];
        }
        $key = in_array($by, ['rate_by_term', 'amount_by_term'], true) ? $term : $amount;
        $isRate = self::isRateCharge($c);
        foreach (DB::all('SELECT * FROM loan_charge_ranges WHERE charge_id = :c ORDER BY range_from', ['c' => $c['id']]) as $r) {
            if (Dec::cmp($key, $r['range_from']) >= 0 && Dec::cmp($key, $r['range_to']) <= 0) {
                return $isRate ? [Dec::pct($amount, $r['value']), Dec::fix($r['value']) . ' %', Dec::fix($r['value'])] : [Dec::round($r['value'], 2), 'fixed ' . Dec::money($r['value']), null];
            }
        }
        return ['0', 'no range matched', null];
    }

    /** A charge worked out as a rate (%) of the loan, rather than as an amount */
    public static function isRateCharge(array $c): bool
    {
        return in_array($c['charge_by'], ['fixed_rate', 'rate_by_term', 'rate_by_amount'], true);
    }

    /** Every active loan charge, the product's own first (the list of deductions the Loan Release Form can add) */
    public static function chargeChoices(?int $productId = null): array
    {
        return DB::all('SELECT x.* FROM (SELECT c.*, (SELECT MIN(pc.sort_order) FROM loan_product_charges pc WHERE pc.charge_id = c.id AND pc.product_id = :p) AS product_sort
                          FROM loan_charges c WHERE c.is_active = 1) x ORDER BY CASE WHEN x.product_sort IS NULL THEN 1 ELSE 0 END, x.product_sort, x.name', ['p' => (int)$productId]);
    }

    /**
     * The deductions as the Loan Release Form has them (added, deleted or changed by the user): each row charge_id, rate
     * (for a rate charge, % of the loan) and amount. The amount typed is the deduction; its basis says how it was arrived
     * at ("3.00 %" when it is the rate of the loan, the standard basis when it is the product's own amount, else "as
     * entered"). Returns the rows in the shape of charges().
     */
    public static function customCharges(array $p, string $amount, string $term, array $rows, ?string $date = null): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $cid = (int)($row['charge_id'] ?? 0);
            $amt = trim((string)($row['amount'] ?? ''));
            $rate = trim((string)($row['rate'] ?? ''));
            if (!$cid && $amt === '' && $rate === '') {
                continue;               // an empty row of the grid
            }
            $c = $cid ? DB::row('SELECT * FROM loan_charges WHERE id = :c', ['c' => $cid]) : null;
            if (!$c) {
                fail('Choose the charge of every deduction (or delete the empty row).');
            }
            if ((int)$c['is_active'] !== 1) {
                fail($c['name'] . ' is no longer in use (Loans > Loan Charges).');
            }
            if (isset($seen[$cid])) {
                fail($c['name'] . ' is listed twice in the deductions.');
            }
            $seen[$cid] = true;
            if ($c['charge_type'] === 'savings' && !$c['sav_product_id']) {
                fail($c['name'] . ' credits a savings product but none is set on Loans > Loan Charges.');
            }
            [$std, $stdBasis, $stdRate] = self::chargeAmount($c, $amount, $term);
            if ($rate !== '' && (!is_numeric($rate) || Dec::isNeg($rate) || Dec::cmp($rate, '100') > 0)) {
                fail('The rate of ' . $c['name'] . ' must be from 0 to 100 (%).');
            }
            if ($amt === '') {
                // a rate typed and no amount: the rate of the loan; nothing typed: the standard amount
                $amt = $rate !== '' && self::isRateCharge($c) ? Dec::pct($amount, $rate) : $std;
            }
            if (!is_numeric($amt) || Dec::isNeg($amt)) {
                fail('Enter the amount of ' . $c['name'] . '.');
            }
            $amt = Dec::round($amt, 2);
            if (!Dec::isPos($amt)) {
                continue;               // a deduction of zero is no deduction
            }
            if ($rate !== '' && self::isRateCharge($c) && Dec::cmp($amt, Dec::pct($amount, $rate)) === 0) {
                $basis = Dec::fix($rate) . ' %';
            } elseif (Dec::cmp($amt, $std) === 0 && $stdBasis !== '') {
                $basis = $stdBasis;
            } else {
                $basis = 'as entered';
            }
            $out[] = ['charge' => $c, 'amount' => $amt, 'basis' => $basis, 'rate' => $rate !== '' && self::isRateCharge($c) ? Dec::fix($rate) : $stdRate, 'custom' => true];
        }
        return $out;
    }

    /** Every charge of a product worked out for an amount and term */
    public static function charges(array $p, string $amount, string $term, ?string $date = null): array
    {
        $out = [];
        foreach (self::productCharges((int)$p['id'], $date ?? today()) as $c) {
            [$amt, $basis, $rate] = self::chargeAmount($c, $amount, $term);
            if (!Dec::isPos($amt)) {
                continue;
            }
            $out[] = ['charge' => $c, 'amount' => $amt, 'basis' => $basis, 'rate' => $rate];
        }
        return $out;
    }

    // ------------------------------------------------------------------ amortization schedule

    /**
     * The amortization schedule. Returns ['rows' => [[inst_no, due_date, principal, interest, total, balance_after]], 'total_interest', 'installments', 'due_date'].
     *  diminishing + equal_amortization : the annuity (the reference: 20,000 at 18 % for 12 months, monthly = 1,833.60)
     *  diminishing + equal_principal    : equal principal, interest on the declining balance
     *  straight                         : add-on interest for the whole term, split evenly
     * The period rate is the annual rate for the period of the mode of payment ("diminishing by same as mode of payment");
     * with diminishing_by = daily it is worked out on the actual days between amortization dates.
     */
    public static function schedule(string $principal, string $rate, string $term, string $termUnit, string $mode, string $interestMethod, string $amortType, string $startDate, int $calendar = 365, string $diminishingBy = 'same'): array
    {
        $principal = Dec::round($principal, 2);
        $n = Coop::installments($mode, $term, $termUnit);
        [, $modeDays, $modeMonths] = Coop::MODES[$mode] ?? Coop::MODES['monthly'];
        $dates = [];
        for ($k = 1; $k <= $n; $k++) {
            $dates[$k] = $mode === 'lump_sum' ? ($termUnit === 'months' ? Coop::addMonths($startDate, (int)round((float)$term)) : Coop::addDays($startDate, (int)round((float)$term))) : Coop::nthDue($startDate, $mode, $k - 1);
        }
        // the period rate, as a decimal string (rate is % per annum)
        $annual = Dec::div($rate, '100', 12);
        $periodRate = function (int $k, string $prevDate) use ($annual, $modeDays, $modeMonths, $calendar, $diminishingBy, $dates, $mode, $term, $termUnit): string {
            if ($mode === 'lump_sum') {
                return $termUnit === 'months' ? Dec::div(Dec::mul($annual, $term, 12), '12', 12) : Dec::div(Dec::mul($annual, $term, 12), (string)$calendar, 12);
            }
            if ($diminishingBy === 'daily') {
                $days = Coop::daysBetween($prevDate, $dates[$k]);
                return Dec::div(Dec::mul($annual, (string)$days, 12), (string)$calendar, 12);
            }
            if ($modeMonths > 0) {
                return Dec::div(Dec::mul($annual, (string)$modeMonths, 12), '12', 12);
            }
            return Dec::div(Dec::mul($annual, (string)$modeDays, 12), (string)$calendar, 12);
        };
        $rows = [];
        $bal = $principal;
        $totalInterest = '0';
        if ($interestMethod === 'straight' || $mode === 'lump_sum') {
            // add-on: interest for the whole term on the full principal, split evenly (lump sum: one instalment)
            $years = $termUnit === 'months' ? Dec::div($term, '12', 12) : Dec::div($term, (string)$calendar, 12);
            $totalInterest = Dec::round(Dec::mul(Dec::mul($principal, $annual, 12), $years, 12), 2);
            $perP = Dec::round(Dec::div($principal, (string)$n, 4), 2);
            $perI = Dec::round(Dec::div($totalInterest, (string)$n, 4), 2);
            $sumP = '0';
            $sumI = '0';
            for ($k = 1; $k <= $n; $k++) {
                $pk = $k === $n ? Dec::sub($principal, $sumP, 2) : $perP;
                $ik = $k === $n ? Dec::sub($totalInterest, $sumI, 2) : $perI;
                $bal = Dec::sub($bal, $pk, 2);
                $rows[] = ['inst_no' => $k, 'due_date' => $dates[$k], 'principal' => $pk, 'interest' => $ik, 'total' => Dec::add($pk, $ik, 2), 'balance_after' => $bal];
                $sumP = Dec::add($sumP, $pk, 2);
                $sumI = Dec::add($sumI, $ik, 2);
            }
        } elseif ($amortType === 'equal_principal') {
            $perP = Dec::round(Dec::div($principal, (string)$n, 4), 2);
            $sumP = '0';
            $prev = $startDate;
            for ($k = 1; $k <= $n; $k++) {
                $i = $periodRate($k, $prev);
                $ik = Dec::round(Dec::mul($bal, $i, 10), 2);
                $pk = $k === $n ? Dec::sub($principal, $sumP, 2) : $perP;
                $bal = Dec::sub($bal, $pk, 2);
                $rows[] = ['inst_no' => $k, 'due_date' => $dates[$k], 'principal' => $pk, 'interest' => $ik, 'total' => Dec::add($pk, $ik, 2), 'balance_after' => $bal];
                $sumP = Dec::add($sumP, $pk, 2);
                $totalInterest = Dec::add($totalInterest, $ik, 2);
                $prev = $dates[$k];
            }
        } else {
            // equal amortization (annuity): PMT = P i / (1 - (1+i)^-n), interest on the declining balance
            $i = $periodRate(1, $startDate);
            if (Dec::isZero($i)) {
                $pmt = Dec::round(Dec::div($principal, (string)$n, 4), 2);
            } else {
                $f = '1';
                $onePlus = Dec::add('1', $i, 12);
                for ($k = 0; $k < $n; $k++) {
                    $f = Dec::mul($f, $onePlus, 12);
                }
                $pmt = Dec::round(Dec::div(Dec::mul($principal, $i, 12), Dec::sub('1', Dec::div('1', $f, 12), 12), 12), 2);
            }
            $prev = $startDate;
            for ($k = 1; $k <= $n; $k++) {
                $ik = Dec::round(Dec::mul($bal, $diminishingBy === 'daily' ? $periodRate($k, $prev) : $i, 10), 2);
                $pk = $k === $n ? $bal : Dec::sub($pmt, $ik, 2);
                if (Dec::cmp($pk, $bal) > 0) {
                    $pk = $bal;
                }
                // the last amortization stays equal to the others: the few centavos of rounding the schedule carried
                // are taken up by its interest (20,000 at 18 % for 12 months: 12 x 1,833.60, interest 2,003.20)
                if ($k === $n && $diminishingBy !== 'daily') {
                    $alt = Dec::sub($pmt, $pk, 2);
                    if (!Dec::isNeg($alt) && Dec::cmp(Dec::abs(Dec::sub($alt, $ik, 2)), '0.05') <= 0) {
                        $ik = $alt;
                    }
                }
                $bal = Dec::sub($bal, $pk, 2);
                $rows[] = ['inst_no' => $k, 'due_date' => $dates[$k], 'principal' => $pk, 'interest' => $ik, 'total' => Dec::add($pk, $ik, 2), 'balance_after' => $bal];
                $totalInterest = Dec::add($totalInterest, $ik, 2);
                $prev = $dates[$k];
            }
        }
        return ['rows' => $rows, 'total_interest' => $totalInterest, 'installments' => $n, 'due_date' => $rows ? $rows[count($rows) - 1]['due_date'] : $startDate];
    }

    /** Term expressed in months (for eligibility and charges by term) */
    public static function termMonths(string $term, string $unit): string
    {
        return $unit === 'months' ? Dec::of($term) : Dec::round(Dec::div($term, '30', 4), 2);
    }

    // ------------------------------------------------------------------ eligibility

    /** The checks of the product against the member: [['check', 'ok' => bool, 'detail']] */
    public static function eligibility(array $m, array $p, string $amount, string $term, string $termUnit, ?int $excludeAppId = null, ?int $payoffLoanId = null): array
    {
        $out = [];
        $ok = fn(string $check, bool $pass, string $detail) => ['check' => $check, 'ok' => $pass, 'detail' => $detail];
        $out[] = $ok('Member is active', $m['status'] === 'active', Coop::MEMBER_STATUSES[$m['status']] ?? $m['status']);
        $out[] = $ok('No hold on the member', (int)$m['hold_flag'] !== 1, (int)$m['hold_flag'] === 1 ? (string)$m['hold_reason'] : 'none');
        if (Dec::isPos($p['amount_min'])) {
            $out[] = $ok('Amount at least the minimum', Dec::cmp($amount, $p['amount_min']) >= 0, 'minimum ' . Dec::money($p['amount_min']));
        }
        if (Dec::isPos($p['amount_max'])) {
            $out[] = $ok('Amount within the maximum', Dec::cmp($amount, $p['amount_max']) <= 0, 'maximum ' . Dec::money($p['amount_max']));
        }
        $termInUnit = $p['term_unit'] === $termUnit ? Dec::of($term) : ($p['term_unit'] === 'months' ? self::termMonths($term, $termUnit) : Dec::mul($term, '30', 2));
        $out[] = $ok('Term within ' . Dec::fix($p['term_min'], 0) . ' to ' . Dec::fix($p['term_max'], 0) . ' ' . $p['term_unit'],
            Dec::cmp($termInUnit, $p['term_min']) >= 0 && Dec::cmp($termInUnit, $p['term_max']) <= 0, $term . ' ' . $termUnit);
        if ((int)$p['req_member_months'] > 0) {
            $months = $m['membership_date'] ? (int)((new DateTime($m['membership_date']))->diff(new DateTime())->days / 30) : 0;
            $out[] = $ok('Member for at least ' . $p['req_member_months'] . ' month(s)', $months >= (int)$p['req_member_months'], $months . ' month(s) since ' . fdate($m['membership_date']));
        }
        if ((int)$p['req_age_min'] > 0 || (int)$p['req_age_max'] > 0) {
            $age = Members::age($m['birth_date']);
            $pass = $age !== null && ((int)$p['req_age_min'] === 0 || $age >= (int)$p['req_age_min']) && ((int)$p['req_age_max'] === 0 || $age <= (int)$p['req_age_max']);
            $out[] = $ok('Age within the product limits', $pass, $age === null ? 'no date of birth' : $age . ' years');
        }
        $sum = Coop::memberSummary((int)$m['id']);
        if (Dec::isPos($p['req_capital_value'])) {
            $need = $p['req_capital_mode'] === 'pct' ? Dec::pct($amount, $p['req_capital_value']) : Dec::of($p['req_capital_value']);
            $out[] = $ok('Paid-up share capital of at least ' . Dec::money($need), Dec::cmp($sum['share_capital'], $need) >= 0, 'paid-up ' . Dec::money($sum['share_capital']));
        }
        if (Dec::isPos($p['req_savings_value'])) {
            $need = $p['req_savings_mode'] === 'pct' ? Dec::pct($amount, $p['req_savings_value']) : Dec::of($p['req_savings_value']);
            $out[] = $ok('Savings of at least ' . Dec::money($need), Dec::cmp($sum['savings'], $need) >= 0, 'savings ' . Dec::money($sum['savings']));
        }
        if (Dec::isPos($p['req_ndi'])) {
            $ndi = Members::ndi((int)$m['id'])['ndi'];
            $out[] = $ok('Net disposable income of at least ' . Dec::money($p['req_ndi']), Dec::cmp($ndi, $p['req_ndi']) >= 0, 'NDI ' . Dec::money($ndi) . ' (Financial Information tab)');
        }
        if ($p['req_segment_id']) {
            $out[] = $ok('Member belongs to the required segmentation', (int)$m['segment_id'] === (int)$p['req_segment_id'], (string)DB::value('SELECT name FROM member_segments WHERE id = :s', ['s' => $p['req_segment_id']]));
        }
        // a renewal / restructuring pays the old loan off from the proceeds: that loan does not count against the new one
        $openSame = (int)DB::value("SELECT COUNT(*) FROM loan_accounts WHERE member_id = :m AND product_id = :p AND status IN ('current','past_due','restructured') AND id <> :x", ['m' => $m['id'], 'p' => $p['id'], 'x' => $payoffLoanId ?? 0]);
        $pendingSame = (int)DB::value("SELECT COUNT(*) FROM loan_applications WHERE member_id = :m AND product_id = :p AND status IN ('new','validated','for_ci','for_approval','approved') AND id <> :x", ['m' => $m['id'], 'p' => $p['id'], 'x' => $excludeAppId ?? 0]);
        $out[] = $ok('Within the maximum of ' . $p['max_loans'] . ' open loan(s) of this product', $openSame + $pendingSame < (int)$p['max_loans'], $openSame . ' open, ' . $pendingSame . ' pending');
        $arrears = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(s.total - s.principal_paid - s.interest_paid),0) AS amt FROM loan_schedule s JOIN loan_accounts l ON l.id = s.loan_id
                              WHERE l.member_id = :m AND l.status IN ('current','past_due','restructured') AND s.is_paid = 0 AND s.due_date < CURDATE() AND l.id <> :x", ['m' => $m['id'], 'x' => $payoffLoanId ?? 0]);
        $out[] = $ok('No amortization in arrears on other loans', (int)$arrears['n'] === 0, (int)$arrears['n'] ? $arrears['n'] . ' overdue amortization(s), ' . Dec::money($arrears['amt']) : 'none');
        $exposure = Dec::of(DB::value("SELECT COALESCE(SUM(l.principal_balance),0) FROM loan_comakers c JOIN loan_applications a ON a.id = c.application_id JOIN loan_accounts l ON l.id = a.loan_id
                                         WHERE c.comaker_member_id = :m AND l.status IN ('current','past_due','restructured')", ['m' => $m['id']]));
        $out[] = $ok('Co-maker exposure (loans guaranteed, for information)', true, Dec::money($exposure) . ' guaranteed as co-maker');
        return $out;
    }

    // ------------------------------------------------------------------ applications

    public static function application(int $id): ?array
    {
        return DB::row('SELECT a.*, m.full_name AS member_name, m.member_no, p.code AS product_code, p.name AS product_name, p.needs_ci, p.req_comakers, p.needs_collateral, p.charge_method, p.interest_method, p.amort_type AS product_amort_type
                          FROM loan_applications a JOIN members m ON m.id = a.member_id JOIN loan_products p ON p.id = a.product_id WHERE a.id = :id', ['id' => $id]);
    }

    /** The fields of Capacity to pay on a loan application */
    public const CAPACITY_FIELDS = ['monthly_income', 'spouse_income', 'ndi', 'dependents', 'employer_name', 'business_name', 'spouse_name'];

    /**
     * Where a new application of the member starts its Capacity to pay: the entries saved on the member's latest
     * application (the borrower's last loan), as last updated; for a first loan, the member's Financial Information.
     * Returns ['data' => the seven fields, 'source' => application|member, 'app_no' => .., 'date' => ..].
     */
    public static function capacityStart(int $memberId, ?int $exceptAppId = null): array
    {
        $last = DB::row('SELECT id, application_no, monthly_income, spouse_income, ndi, dependents, employer_name, business_name, spouse_name, COALESCE(updated_at, created_at) AS saved_at
                           FROM loan_applications WHERE member_id = :m AND id <> :x ORDER BY COALESCE(updated_at, created_at) DESC, id DESC LIMIT 1', ['m' => $memberId, 'x' => $exceptAppId ?? 0]);
        if ($last) {
            $data = [];
            foreach (self::CAPACITY_FIELDS as $k) {
                $data[$k] = in_array($k, ['monthly_income', 'spouse_income', 'ndi'], true) ? Dec::fix($last[$k]) : ($k === 'dependents' ? (string)(int)$last[$k] : (string)($last[$k] ?? ''));
            }
            return ['data' => $data, 'source' => 'application', 'app_id' => (int)$last['id'], 'app_no' => (string)$last['application_no'], 'date' => substr((string)$last['saved_at'], 0, 10)];
        }
        $m = Coop::member($memberId);
        $ndi = Members::ndi($memberId);
        $spouse = $m ? trim((string)DB::value("SELECT CONCAT_WS(' ', first_name, last_name) FROM member_dependents WHERE member_id = :m AND kind = 'spouse' ORDER BY id LIMIT 1", ['m' => $memberId])) : '';
        $deps = $m ? (int)DB::value("SELECT COUNT(*) FROM member_dependents WHERE member_id = :m AND kind <> 'spouse' AND is_dependent = 1", ['m' => $memberId]) : 0;
        return ['data' => ['monthly_income' => Dec::fix($ndi['member_income'] ?? '0'), 'spouse_income' => Dec::fix($ndi['spouse_income']), 'ndi' => Dec::fix($ndi['ndi']),
            'dependents' => (string)$deps, 'employer_name' => (string)($m['employer_name'] ?? ''), 'business_name' => '', 'spouse_name' => $spouse], 'source' => 'member', 'app_id' => null, 'app_no' => '', 'date' => ''];
    }

    /** The entries of a credit investigation that carry to the borrower's next one (the findings are each investigation's own) */
    public const CI_CARRY = ['spouse_name', 'current_address', 'previous_address', 'residence_type', 'house_type', 'building_type', 'structure_type', 'storeys', 'rooms',
        'lot_area', 'floor_area', 'estimated_value', 'informants'];

    /** The member's last credit investigation of another application, as last saved (with its application no.) */
    public static function ciPrevious(int $memberId, int $exceptAppId): ?array
    {
        return DB::row('SELECT c.*, a.application_no FROM loan_ci c JOIN loan_applications a ON a.id = c.application_id
                         WHERE c.member_id = :m AND c.application_id <> :a ORDER BY COALESCE(c.last_updated, c.date_reported) DESC, c.id DESC LIMIT 1', ['m' => $memberId, 'a' => $exceptAppId]);
    }

    /**
     * Where the credit investigation of an application starts: its own entries once saved; otherwise the borrower's last
     * investigation (the residence, the children, the sources of income, the assets, the references and the informants
     * as last saved), dated today and investigated by the user; for a first loan, the member's address and the spouse of
     * the application. Returns ['rec' => fields, 'lines' => [kind => rows], 'prev' => the last investigation or null].
     */
    public static function ciStart(array $app, ?array $ci): array
    {
        $byKind = function (int $ciId): array {
            $out = [];
            foreach (DB::all('SELECT kind, name, detail1, detail2, detail3, years, amount FROM loan_ci_lines WHERE ci_id = :c ORDER BY id', ['c' => $ciId]) as $l) {
                $out[$l['kind']][] = $l;
            }
            return $out;
        };
        if ($ci) {
            return ['rec' => $ci, 'lines' => $byKind((int)$ci['id']), 'prev' => null];
        }
        $user = Auth::user()['full_name'] ?? '';
        $prev = self::ciPrevious((int)$app['member_id'], (int)$app['id']);
        if ($prev) {
            $rec = ['date_reported' => today(), 'conducted_by' => $user];
            foreach (self::CI_CARRY as $k) {
                $rec[$k] = $prev[$k];
            }
            return ['rec' => $rec, 'lines' => $byKind((int)$prev['id']), 'prev' => $prev];
        }
        $m = Coop::member((int)$app['member_id']);
        return ['rec' => ['date_reported' => today(), 'spouse_name' => $app['spouse_name'] ?: null, 'current_address' => $m ? Members::address($m) : null, 'conducted_by' => $user], 'lines' => [], 'prev' => null];
    }

    /**
     * The lines of a credit investigation from its grids (kind => posted rows): a line is kept when any of its boxes is
     * filled, so an income with its employer and amount but no source name is saved too
     */
    public static function ciLines(array $grids): array
    {
        $lines = [];
        foreach ($grids as $kind => $rows) {
            foreach ((array)$rows as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $filled = array_filter(['name', 'detail1', 'detail2', 'detail3', 'years', 'amount'],
                    fn($f) => trim((string)($r[$f] ?? '')) !== '' && !(in_array($f, ['years', 'amount'], true) && Dec::isZero(Dec::of($r[$f]))));
                if (!$filled) {
                    continue;
                }
                $lines[] = ['kind' => (string)$kind, 'name' => mb_substr(trim((string)($r['name'] ?? '')), 0, 160) ?: null, 'detail1' => mb_substr(trim((string)($r['detail1'] ?? '')), 0, 160) ?: null,
                    'detail2' => mb_substr(trim((string)($r['detail2'] ?? '')), 0, 160) ?: null, 'detail3' => mb_substr(trim((string)($r['detail3'] ?? '')), 0, 160) ?: null,
                    'years' => trim((string)($r['years'] ?? '')) === '' ? null : Dec::fix(Dec::of($r['years']), 1), 'amount' => trim((string)($r['amount'] ?? '')) === '' ? null : Dec::fix(Dec::of($r['amount']))];
            }
        }
        return $lines;
    }

    public static function saveApplication(array $d, array $collaterals, array $comakers, ?int $id, string $pageKey): int
    {
        return DB::tx(function () use ($d, $collaterals, $comakers, $id, $pageKey) {
            $old = $id ? self::application($id) : null;
            if ($id && !$old) {
                fail('Application not found.');
            }
            if ($old && !in_array($old['status'], ['new', 'validated', 'returned', 'for_ci'], true)) {
                fail('An application that is ' . self::APP_STATUSES[$old['status']] . ' can no longer be edited.');
            }
            $m = Coop::member((int)$d['member_id']);
            if (!$m) {
                fail('Choose the member.');
            }
            Coop::requireBranch($m['branch_id'] ? (int)$m['branch_id'] : null);
            $p = self::product((int)$d['product_id']);
            if (!$p || (int)$p['is_active'] !== 1) {
                fail('Choose an active loan product.');
            }
            if (!valid_date($d['application_date'])) {
                fail('Enter the application date.');
            }
            $d['applied_amount'] = Dec::fix(Dec::of($d['applied_amount']));
            if (!Dec::isPos($d['applied_amount'])) {
                fail('Enter the amount applied for.');
            }
            if (!is_numeric($d['term']) || (float)$d['term'] <= 0) {
                fail('Enter the term.');
            }
            $d['term'] = Dec::fix($d['term'], 2);
            $d['term_unit'] = $d['term_unit'] === 'days' ? 'days' : 'months';
            if (!isset(Coop::MODES[$d['mode_of_payment']])) {
                $d['mode_of_payment'] = $p['mode_of_payment'];
            }
            $d['rate'] = isset($d['rate']) && $d['rate'] !== '' ? Dec::fix($d['rate'], 4) : self::rateFor($p, self::termMonths($d['term'], $d['term_unit']), $d['applied_amount'], $d['application_date']);
            $d['amort_type'] = isset(self::AMORT_TYPES[$d['amort_type'] ?? '']) ? $d['amort_type'] : $p['amort_type'];
            $d['branch_id'] = $m['branch_id'] ?: Coop::defaultBranchId();
            foreach (['monthly_income', 'spouse_income', 'ndi'] as $k) {
                $d[$k] = Dec::fix(Dec::of($d[$k] ?? '0'));
            }
            $d['dependents'] = (int)($d['dependents'] ?? 0);
            if (!empty($d['previous_loan_id'])) {
                $prev = self::loan((int)$d['previous_loan_id']);
                if (!$prev || (int)$prev['member_id'] !== (int)$m['id'] || !in_array($prev['status'], self::OPEN_STATUSES, true)) {
                    fail('The loan to renew or restructure must be an open loan of the same member.');
                }
                $d['previous_loan_id'] = (int)$prev['id'];
                $d['is_restructure'] = !empty($d['is_restructure']) ? 1 : 0;
            } elseif (array_key_exists('previous_loan_id', $d)) {
                $d['previous_loan_id'] = null;
                $d['is_restructure'] = 0;
            }
            $checks = self::eligibility($m, $p, $d['applied_amount'], $d['term'], $d['term_unit'], $id, !empty($d['previous_loan_id']) ? (int)$d['previous_loan_id'] : ($old['previous_loan_id'] ?? null));
            $d['eligibility_json'] = json_encode($checks, JSON_UNESCAPED_UNICODE);
            $sum = Coop::memberSummary((int)$m['id']);
            $d['snapshot_json'] = json_encode($sum + ['membership_date' => $m['membership_date'], 'age' => Members::age($m['birth_date']), 'civil_status' => $m['civil_status']], JSON_UNESCAPED_UNICODE);
            if ($old) {
                $d['updated_by'] = Auth::id();
                // the time the entries were last saved: Capacity to pay of the borrower's next loan starts from the latest
                $d['updated_at'] = date('Y-m-d H:i:s');
                unset($d['application_no'], $d['status']);
                DB::update('loan_applications', $d, 'id = :id', ['id' => $id]);
            } else {
                $d['application_no'] = Seq::next('loan_application');
                $d['status'] = 'new';
                $d['created_by'] = Auth::id();
                $d['officer_id'] = $d['officer_id'] ?? ($m['account_officer_id'] ?: Auth::id());
                $id = DB::insert('loan_applications', $d);
            }
            DB::run('DELETE FROM loan_collaterals WHERE application_id = :a', ['a' => $id]);
            foreach ($collaterals as $c) {
                if (trim((string)($c['collateral_type'] ?? '')) === '' && trim((string)($c['description'] ?? '')) === '') {
                    continue;
                }
                DB::insert('loan_collaterals', ['application_id' => $id, 'collateral_type' => mb_substr(trim((string)$c['collateral_type']), 0, 60) ?: 'Other',
                    'description' => mb_substr(trim((string)($c['description'] ?? '')), 0, 255) ?: null, 'ref_no' => mb_substr(trim((string)($c['ref_no'] ?? '')), 0, 80) ?: null,
                    'fmv' => Dec::fix(Dec::of($c['fmv'] ?? '0')), 'loan_value' => Dec::fix(Dec::of($c['loan_value'] ?? '0')),
                    'expiry_date' => valid_date($c['expiry_date'] ?? '') ? $c['expiry_date'] : null, 'insurance_expiry' => valid_date($c['insurance_expiry'] ?? '') ? $c['insurance_expiry'] : null]);
            }
            DB::run('DELETE FROM loan_comakers WHERE application_id = :a', ['a' => $id]);
            foreach ($comakers as $c) {
                $cm = !empty($c['comaker_member_id']) ? (int)$c['comaker_member_id'] : null;
                $name = trim((string)($c['name'] ?? ''));
                if (!$cm && $name === '') {
                    continue;
                }
                if ($cm === (int)$m['id']) {
                    fail('A member cannot be a co-maker of their own loan.');
                }
                if ($cm) {
                    $cmr = Coop::member($cm);
                    if (!$cmr || $cmr['status'] !== 'active') {
                        fail('Co-maker ' . ($cmr['full_name'] ?? $cm) . ' is not an active member.');
                    }
                    $name = $cmr['full_name'];
                }
                DB::insert('loan_comakers', ['application_id' => $id, 'comaker_member_id' => $cm, 'name' => mb_substr($name, 0, 160), 'address' => mb_substr(trim((string)($c['address'] ?? '')), 0, 255) ?: null,
                    'amount_guaranteed' => Dec::fix(Dec::of($c['amount_guaranteed'] ?? $d['applied_amount']))]);
            }
            $app = self::application($id);
            Audit::log($old ? 'edit' : 'create', 'loan', 'loan_application', $id, $app['application_no'] . ' ' . $app['member_name'], ['page_key' => $pageKey, 'amount' => (float)$app['applied_amount'],
                'before' => $old ?: [], 'after' => $app, 'changes' => $old ? null : [], 'snapshot' => $old ? null : $app]);
            return $id;
        });
    }

    /** Validate: all eligibility checks pass (or an override reason is given) and the co-maker / collateral / CI requirements are met */
    public static function validateApplication(int $id, string $overrideReason, string $pageKey): array
    {
        return DB::tx(function () use ($id, $overrideReason, $pageKey) {
            $app = self::application($id);
            if (!$app || !in_array($app['status'], ['new', 'returned', 'validated', 'for_ci'], true)) {
                fail('The application cannot be validated in its current state.');
            }
            $m = Coop::member((int)$app['member_id']);
            $p = self::product((int)$app['product_id']);
            $checks = self::eligibility($m, $p, $app['applied_amount'], $app['term'], $app['term_unit'], $id, $app['previous_loan_id'] ? (int)$app['previous_loan_id'] : null);
            $comakers = (int)DB::value('SELECT COUNT(*) FROM loan_comakers WHERE application_id = :a', ['a' => $id]);
            $checks[] = ['check' => 'Co-makers: ' . $p['req_comakers'] . ' required', 'ok' => $comakers >= (int)$p['req_comakers'], 'detail' => $comakers . ' listed'];
            if ((int)$p['needs_collateral'] === 1) {
                $coll = (int)DB::value('SELECT COUNT(*) FROM loan_collaterals WHERE application_id = :a', ['a' => $id]);
                $checks[] = ['check' => 'Collateral listed', 'ok' => $coll > 0, 'detail' => $coll . ' item(s)'];
            }
            if ((int)$p['needs_ci'] === 1) {
                // not a failed check: a validated application without one goes to "for credit investigation" next
                $checks[] = ['check' => 'Credit investigation on file', 'ok' => true, 'detail' => $app['ci_id'] ? 'yes' : 'not yet: the application goes to credit investigation next'];
            }
            $failed = array_filter($checks, fn($c) => !$c['ok']);
            $status = 'validated';
            if ($failed) {
                if (trim($overrideReason) === '' || !Access::can($pageKey, 'approve')) {
                    DB::update('loan_applications', ['eligibility_json' => json_encode($checks, JSON_UNESCAPED_UNICODE)], 'id = :id', ['id' => $id]);
                    return ['ok' => false, 'checks' => $checks];
                }
            }
            if ((int)$p['needs_ci'] === 1 && !$app['ci_id']) {
                $status = 'for_ci';
            }
            DB::update('loan_applications', ['status' => $status, 'eligibility_json' => json_encode($checks, JSON_UNESCAPED_UNICODE), 'updated_by' => Auth::id()], 'id = :id', ['id' => $id]);
            Audit::log('validate', 'loan', 'loan_application', $id, $app['application_no'], ['page_key' => $pageKey, 'reason' => $failed ? 'Override: ' . $overrideReason : 'All checks passed',
                'changes' => [['field' => 'status', 'old' => $app['status'], 'new' => $status]]]);
            return ['ok' => true, 'checks' => $checks, 'status' => $status];
        });
    }

    public static function submitForApproval(int $id, string $pageKey): void
    {
        $app = self::application($id);
        if (!$app || !in_array($app['status'], ['validated', 'for_ci'], true)) {
            fail('Validate the application first.');
        }
        if ((int)$app['needs_ci'] === 1 && !$app['ci_id']) {
            fail('The product needs a credit investigation before approval.');
        }
        DB::update('loan_applications', ['status' => 'for_approval', 'updated_by' => Auth::id()], 'id = :id', ['id' => $id]);
        Audit::log('submit', 'loan', 'loan_application', $id, $app['application_no'], ['page_key' => $pageKey, 'changes' => [['field' => 'status', 'old' => $app['status'], 'new' => 'for_approval']]]);
    }

    /** The approval levels an amount needs, from the matrix (every level whose range covers the amount) */
    public static function levelsFor(string $amount, int $productId): array
    {
        $rows = DB::all('SELECT * FROM loan_approval_matrix WHERE is_active = 1 AND (product_id IS NULL OR product_id = :p) ORDER BY level_no, id', ['p' => $productId]);
        $levels = [];
        foreach ($rows as $r) {
            if (Dec::cmp($amount, $r['amount_from']) >= 0 && (!Dec::isPos($r['amount_to']) || Dec::cmp($amount, $r['amount_to']) <= 0)) {
                $levels[(int)$r['level_no']] = $r;
            }
        }
        ksort($levels);
        return $levels;
    }

    /** One approver acts; the application is approved when every level of the matrix has approved */
    public static function act(int $id, string $action, ?string $approvedAmount, string $remarks, string $pageKey): void
    {
        DB::tx(function () use ($id, $action, $approvedAmount, $remarks, $pageKey) {
            $app = DB::row('SELECT * FROM loan_applications WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (!$app || $app['status'] !== 'for_approval') {
                fail('The application is not waiting for approval.');
            }
            if (!Access::can($pageKey, 'approve')) {
                fail('You are not allowed to approve loan applications.');
            }
            $levels = self::levelsFor($app['applied_amount'], (int)$app['product_id']);
            $done = DB::all("SELECT * FROM loan_approvals WHERE application_id = :a AND action = 'approved' AND acted_at >= (SELECT COALESCE(MAX(acted_at), '2000-01-01') FROM loan_approvals WHERE application_id = :a2 AND action <> 'approved') ORDER BY level_no", ['a' => $id, 'a2' => $id]);
            $doneLevels = array_map('intval', array_column($done, 'level_no'));
            foreach ($done as $dn) {
                if ((int)$dn['approver_id'] === Auth::id()) {
                    fail('You have already approved this application.');
                }
            }
            $myLevel = 1;
            $position = null;
            if ($levels) {
                $pending = array_diff(array_keys($levels), $doneLevels);
                if (!$pending) {
                    fail('Every approval level has already acted.');
                }
                $myLevel = (int)reset($pending);
                $rule = $levels[$myLevel];
                $position = $rule['position'];
                if ($rule['role_id'] && !in_array((int)$rule['role_id'], array_map(fn($r) => (int)$r['id'], Access::roles()), true) && !Access::isAdmin()) {
                    fail('Level ' . $myLevel . ' is approved by the role ' . DB::value('SELECT name FROM roles WHERE id = :r', ['r' => $rule['role_id']]) . '.');
                }
            }
            $amt = $approvedAmount !== null && $approvedAmount !== '' ? Dec::round($approvedAmount, 2) : Dec::of($app['approved_amount'] ?: $app['applied_amount']);
            if ($action === 'approved' && !Dec::isPos($amt)) {
                fail('Enter the approved amount.');
            }
            if ($action === 'approved' && Dec::cmp($amt, $app['applied_amount']) > 0) {
                fail('The approved amount cannot exceed the amount applied for (' . Dec::money($app['applied_amount']) . ').');
            }
            DB::insert('loan_approvals', ['application_id' => $id, 'level_no' => $myLevel, 'approver_id' => Auth::id(), 'position' => $position ?: implode(', ', Access::roleNames()),
                'approved_amount' => $action === 'approved' ? Dec::fix($amt) : null, 'action' => $action, 'remarks' => mb_substr($remarks, 0, 255) ?: null]);
            $new = $app['status'];
            if ($action === 'approved') {
                $allDone = !$levels || count(array_unique(array_merge($doneLevels, [$myLevel]))) >= count($levels);
                $new = $allDone ? 'approved' : 'for_approval';
                DB::update('loan_applications', ['approved_amount' => Dec::fix($amt), 'status' => $new, 'updated_by' => Auth::id()], 'id = :id', ['id' => $id]);
            } elseif ($action === 'disapproved') {
                $new = 'disapproved';
                DB::update('loan_applications', ['status' => $new, 'remarks' => mb_substr($remarks, 0, 255) ?: null, 'updated_by' => Auth::id()], 'id = :id', ['id' => $id]);
            } elseif ($action === 'returned') {
                $new = 'returned';
                DB::update('loan_applications', ['status' => $new, 'remarks' => mb_substr($remarks, 0, 255) ?: null, 'updated_by' => Auth::id()], 'id = :id', ['id' => $id]);
            } else {
                fail('Unknown action.');
            }
            Audit::log($action, 'loan', 'loan_application', $id, $app['application_no'], ['page_key' => $pageKey, 'amount' => (float)$amt, 'reason' => $remarks,
                'changes' => [['field' => 'status', 'old' => $app['status'], 'new' => $new]]]);
        });
    }

    public static function cancelApplication(int $id, string $reason, string $pageKey): void
    {
        $app = self::application($id);
        if (!$app || in_array($app['status'], ['released', 'cancelled'], true)) {
            fail('The application cannot be cancelled.');
        }
        if (trim($reason) === '') {
            fail('Enter the reason.');
        }
        DB::update('loan_applications', ['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 255), 'updated_by' => Auth::id()], 'id = :id', ['id' => $id]);
        Audit::log('cancel', 'loan', 'loan_application', $id, $app['application_no'], ['page_key' => $pageKey, 'reason' => $reason, 'changes' => [['field' => 'status', 'old' => $app['status'], 'new' => 'cancelled']]]);
    }

    /**
     * Why an application cannot be deleted, or null when it can: a released loan stays on record (its application
     * with it), and so does an application whose release was posted and later voided (the cancelled loan account
     * still points to it).
     */
    public static function deleteBlocker(array $app): ?string
    {
        if ($app['status'] === 'released' || !empty($app['loan_id'])) {
            return 'The loan of this application is released. A released loan and its application stay on record.';
        }
        $loan = DB::value('SELECT loan_no FROM loan_accounts WHERE application_id = :a LIMIT 1', ['a' => $app['id']]);
        if ($loan) {
            return 'Loan ' . $loan . ' was released from this application (the release was voided). Cancel the application instead.';
        }
        return null;
    }

    /**
     * Deletes a loan application that was never released, with what belongs to it: its co-makers, collateral,
     * approvals, requirements and credit investigation. The application as it was is kept in the audit trail;
     * an uploaded requirement file stays in storage. The application number is not used again.
     */
    public static function deleteApplication(int $id, string $reason, string $pageKey): array
    {
        if (trim($reason) === '') {
            fail('Enter the reason for deleting the application.');
        }
        return DB::tx(function () use ($id, $reason, $pageKey) {
            $app = DB::row('SELECT * FROM loan_applications WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (!$app) {
                fail('Application not found.');
            }
            $why = self::deleteBlocker($app);
            if ($why !== null) {
                fail($why);
            }
            $ci = DB::column('SELECT id FROM loan_ci WHERE application_id = :a', ['a' => $id]);
            $counts = [
                'co-makers' => (int)DB::value('SELECT COUNT(*) FROM loan_comakers WHERE application_id = :a', ['a' => $id]),
                'collateral' => (int)DB::value('SELECT COUNT(*) FROM loan_collaterals WHERE application_id = :a', ['a' => $id]),
                'approvals' => (int)DB::value('SELECT COUNT(*) FROM loan_approvals WHERE application_id = :a', ['a' => $id]),
                'requirements' => (int)DB::value('SELECT COUNT(*) FROM loan_application_docs WHERE application_id = :a', ['a' => $id]),
                'credit investigations' => count($ci),
            ];
            if ($ci) {
                $in = implode(',', array_map('intval', $ci));
                DB::run("DELETE FROM loan_ci_lines WHERE ci_id IN ($in)");
                DB::run("DELETE FROM loan_ci WHERE id IN ($in)");
            }
            foreach (['loan_comakers', 'loan_collaterals', 'loan_approvals', 'loan_application_docs'] as $t) {
                DB::run("DELETE FROM $t WHERE application_id = :a", ['a' => $id]);
            }
            DB::run('DELETE FROM loan_applications WHERE id = :id', ['id' => $id]);
            $member = (string)DB::value('SELECT full_name FROM members WHERE id = :m', ['m' => $app['member_id']]);
            $with = implode(', ', array_map(fn($k, $n) => $n . ' ' . $k, array_keys(array_filter($counts)), array_filter($counts)));
            Audit::log('delete', 'loan', 'loan_application', $id, $app['application_no'] . ' ' . $member, ['page_key' => $pageKey, 'reason' => $reason,
                'amount' => (float)$app['applied_amount'], 'snapshot' => $app + ['deleted_with' => $with ?: 'nothing else']]);
            return $app;
        });
    }

    // ------------------------------------------------------------------ release

    /**
     * What a release will look like (also printed on the release statement): charges, net proceeds, schedule.
     * $terms: term, term_unit, mode_of_payment, rate, amort_type, start_date, release_date, previous_loan_payoff, and
     *         charges: the deductions as the Loan Release Form has them (customCharges()); left out, the product's own
     */
    public static function releasePlan(array $app, array $terms): array
    {
        $p = self::product((int)$app['product_id']);
        $approved = Dec::round($app['approved_amount'] ?: $app['applied_amount'], 2);
        $date = !empty($terms['release_date']) && valid_date((string)$terms['release_date']) ? (string)$terms['release_date'] : null;
        $months = self::termMonths($terms['term'], $terms['term_unit']);
        $charges = isset($terms['charges']) && is_array($terms['charges'])
            ? self::customCharges($p, $approved, $months, $terms['charges'], $date)
            : self::charges($p, $approved, $months, $date);
        $totalCharges = Dec::sum(array_column($charges, 'amount'));
        $payoff = Dec::round($terms['previous_loan_payoff'] ?? '0', 2);
        $principal = $p['charge_method'] === 'capitalized' ? Dec::add($approved, $totalCharges, 2) : $approved;
        $deducted = $p['charge_method'] === 'deducted' ? $totalCharges : '0';
        $net = Dec::sub(Dec::sub($approved, $deducted, 2), $payoff, 2);
        $sched = self::schedule($principal, $terms['rate'], $terms['term'], $terms['term_unit'], $terms['mode_of_payment'], $p['interest_method'], $terms['amort_type'], $terms['start_date'], (int)$p['calendar_days'] ?: 365, $p['diminishing_by']);
        return ['product' => $p, 'approved' => $approved, 'principal' => $principal, 'charges' => $charges, 'total_charges' => $totalCharges, 'deducted' => $deducted,
            'payoff' => $payoff, 'net_proceeds' => $net, 'schedule' => $sched, 'charge_method' => $p['charge_method']];
    }

    /**
     * Releases an approved application: the loan account, its schedule and charges, the subsidiary ledger and the
     * voucher (Dr Loans Receivable; Cr cash / bank / savings / charges income / payables / savings products / previous loan).
     * The voucher is a Banking transaction (LoanVouchers): a Cash Voucher for a release in cash, a check (Write Checks)
     * for a release by cheque, credited to the cash or bank account chosen, under the class of the form. A release that
     * pays nothing out (credited to savings, or used up by the charges and a previous loan) is a loan release voucher.
     * $r: release_date, start_date, term, term_unit, mode_of_payment, rate, amort_type, release_mode (cash|cheque|savings),
     *     cash_account_id (the cash account, or the bank account of the cheque), cheque_no, credit_account_id, pn_no,
     *     previous_loan_id, shift_id (the teller shift whose drawer pays the cash), officer_id, charges (the deductions as
     *     the Loan Release Form has them, see customCharges(); left out, the product's own)
     */
    public static function release(int $appId, array $r, string $pageKey): array
    {
        return DB::tx(function () use ($appId, $r, $pageKey) {
            $app = DB::row('SELECT * FROM loan_applications WHERE id = :id FOR UPDATE', ['id' => $appId]);
            if (!$app) {
                fail('Application not found.');
            }
            if ($app['status'] !== 'approved') {
                fail('Only an approved application can be released (this one is ' . self::APP_STATUSES[$app['status']] . ').');
            }
            if ($app['loan_id']) {
                fail('This application was already released as loan ' . DB::value('SELECT loan_no FROM loan_accounts WHERE id = :l', ['l' => $app['loan_id']]) . '.');
            }
            $m = Coop::member((int)$app['member_id']);
            Coop::assertTransactable($m);
            Coop::requireBranch($app['branch_id'] ? (int)$app['branch_id'] : null);
            $p = self::product((int)$app['product_id']);
            Coop::checkDate($r['release_date']);
            if (!valid_date($r['start_date']) || $r['start_date'] < $r['release_date']) {
                fail('The start of payment must be on or after the release date.');
            }
            // the pre-release checklist of the product
            $reqs = $p['requirements_json'] ? (array)json_decode($p['requirements_json'], true) : [];
            if ($reqs) {
                $missing = DB::column('SELECT requirement FROM loan_application_docs WHERE application_id = :a AND is_complete = 0', ['a' => $appId]);
                $listed = DB::column('SELECT requirement FROM loan_application_docs WHERE application_id = :a', ['a' => $appId]);
                foreach ($reqs as $req) {
                    if (!in_array($req, $listed, true)) {
                        $missing[] = $req;
                    }
                }
                if ($missing) {
                    fail('Pre-release requirements not yet complete: ' . implode(', ', array_unique($missing)) . '.');
                }
            }
            $terms = [
                'term' => Dec::fix($r['term'] ?? $app['term'], 2), 'term_unit' => ($r['term_unit'] ?? $app['term_unit']) === 'days' ? 'days' : 'months',
                'mode_of_payment' => isset(Coop::MODES[$r['mode_of_payment'] ?? '']) ? $r['mode_of_payment'] : $app['mode_of_payment'],
                'rate' => Dec::fix($r['rate'] ?? $app['rate'], 4), 'amort_type' => isset(self::AMORT_TYPES[$r['amort_type'] ?? '']) ? $r['amort_type'] : ($app['amort_type'] ?: $p['amort_type']),
                'start_date' => $r['start_date'], 'release_date' => $r['release_date'], 'previous_loan_payoff' => '0',
            ];
            if (isset($r['charges']) && is_array($r['charges'])) {
                $terms['charges'] = $r['charges'];
            }
            $prev = !empty($r['previous_loan_id']) ? self::loan((int)$r['previous_loan_id'], true) : null;
            if ($prev) {
                if ((int)$prev['member_id'] !== (int)$m['id'] || !in_array($prev['status'], ['current', 'past_due', 'restructured'], true)) {
                    fail('The previous loan to pay off must be an open loan of the same member.');
                }
                $due = self::dues($prev, $r['release_date']);
                $terms['previous_loan_payoff'] = $due['payoff'];
            }
            $plan = self::releasePlan($app + ['approved_amount' => $app['approved_amount']], $terms);
            if (Dec::isNeg($plan['net_proceeds'])) {
                fail('The charges and the previous loan payoff (' . Dec::money(Dec::add($plan['deducted'], $plan['payoff'])) . ') exceed the approved amount.');
            }
            $limit = Access::limit('coop_max_release');
            $approver = Approval::check($pageKey, Approval::exceeds($limit, (float)$plan['approved']), 'loan release above your limit (' . Dec::money($plan['approved']) . ')');
            $recv = (int)($p['receivable_account_id'] ?: Accts::get('loans_receivable'));
            $intAcct = (int)($p['interest_account_id'] ?: Accts::get('loan_interest_income'));
            $memo = 'Loan release ' . $p['name'] . ' ' . $m['full_name'];
            $termText = rtrim(rtrim(Dec::fix($terms['term'], 2), '0'), '.') . ' ' . ($terms['term_unit'] === 'days' ? 'day(s)' : 'month(s)');
            $gl = [['account_id' => $recv, 'debit' => $plan['principal'], 'memo' => 'Release of loan payable in ' . $termText]];
            $mode = in_array($r['release_mode'] ?? 'cash', ['cash', 'cheque', 'savings'], true) ? (string)($r['release_mode'] ?? 'cash') : 'cash';
            $cashAcct = null;
            $creditAcct = null;
            $chequeNo = mb_substr(trim((string)($r['cheque_no'] ?? '')), 0, 30);
            if (Dec::isPos($plan['net_proceeds'])) {
                if ($mode === 'savings') {
                    $creditAcct = Savings::validate((int)($r['credit_account_id'] ?? 0), 'credit', $plan['net_proceeds'], $r['release_date'], ['is_system' => true]);
                    if ((int)$creditAcct['member_id'] !== (int)$m['id']) {
                        fail('The proceeds can only be credited to an account of the borrower.');
                    }
                    $gl[] = ['account_id' => Savings::ledgerAccount($creditAcct), 'credit' => $plan['net_proceeds'], 'memo' => 'Loan proceeds credited to ' . $creditAcct['account_no']];
                } else {
                    // the cash account (cash release) or the bank account (cheque release) of the voucher
                    $cashAcct = (int)($r['cash_account_id'] ?? 0);
                    if (!$cashAcct) {
                        fail($mode === 'cheque' ? 'Choose the bank account the cheque is drawn on.' : 'Choose the cash account the loan is released from.');
                    }
                    $acc = DB::row('SELECT account_type, is_active FROM accounts WHERE id = :a', ['a' => $cashAcct]);
                    if (!$acc || $acc['account_type'] !== 'Bank' || (int)$acc['is_active'] !== 1) {
                        fail('The ' . ($mode === 'cheque' ? 'bank' : 'cash') . ' account must be an active cash or bank account of the chart of accounts.');
                    }
                    if ($mode === 'cheque' && $chequeNo !== '') {
                        $used = DB::value("SELECT id FROM transactions WHERE txn_type = 'check' AND txn_no = :n AND account_id = :a AND is_voided = 0 LIMIT 1", ['n' => $chequeNo, 'a' => $cashAcct]);
                        if ($used) {
                            fail('Cheque no. ' . $chequeNo . ' of ' . account_label($cashAcct) . ' is already used (Banking > Write Checks). Enter the number of the cheque issued.');
                        }
                    }
                }
            }
            $savingsCharges = [];
            foreach ($plan['charges'] as $i => $ch) {
                $c = $ch['charge'];
                if ($plan['charge_method'] === 'separate') {
                    continue;       // collected on an official receipt later
                }
                if ($c['charge_type'] === 'savings') {
                    $acct = DB::row("SELECT a.* FROM sav_accounts a WHERE a.member_id = :m AND a.product_id = :p AND a.status IN ('active','pending') ORDER BY a.id LIMIT 1", ['m' => $m['id'], 'p' => $c['sav_product_id']]);
                    if (!$acct) {
                        $aid = Savings::open((int)$m['id'], (int)$c['sav_product_id'], $app['branch_id'] ? (int)$app['branch_id'] : null, $r['release_date'], null, 'sav.accounts');
                        DB::update('sav_accounts', ['status' => 'active', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $aid]);
                        $acct = Savings::account($aid);
                    }
                    $acct = Savings::validate((int)$acct['id'], 'credit', $ch['amount'], $r['release_date'], ['allow_pending' => true, 'is_system' => true]);
                    $gl[] = ['account_id' => Savings::ledgerAccount($acct), 'credit' => $ch['amount'], 'memo' => $c['name'] . ' credited to ' . $acct['account_no']];
                    $savingsCharges[] = [$acct, $ch];
                    $plan['charges'][$i]['sav_account_id'] = (int)$acct['id'];
                } else {
                    $acctId = (int)($c['account_id'] ?: ($c['charge_type'] === 'financial' ? Accts::get('loan_service_fee') : Accts::get('coop_insurance_payable')));
                    $gl[] = ['account_id' => $acctId, 'credit' => $ch['amount'], 'memo' => $c['name'] . ' on loan release'];
                }
            }
            if ($prev) {
                // the payoff of the previous loan, split exactly as applyPayment() will settle it
                $due = self::dues($prev, $r['release_date']);
                $prevInt = $prev['interest_method'] === 'diminishing' ? Dec::add($due['interest_due'], $due['interest_to_date'], 2) : Dec::of($due['interest_balance']);
                foreach (self::paymentLines($prev, $due['penalty_due'], $prevInt, $due['principal_balance'], 'Payoff of ' . $prev['loan_no']) as $g) {
                    $gl[] = $g;
                }
            }
            $loanNo = Coop::loanNo($p, $app['branch_id'] ? (int)$app['branch_id'] : null);
            $pnNo = !empty($r['pn_no']) ? mb_substr(trim((string)$r['pn_no']), 0, 30) : Seq::next('pn');
            // the voucher ID of the release (the LR- number series of the loan release vouchers)
            $voucherNo = Seq::next('loan_release');
            $head = ['txn_date' => $r['release_date'], 'memo' => $memo . ' ' . $loanNo, 'branch_id' => $app['branch_id'], 'member_id' => $m['id'],
                'account_id' => $cashAcct, 'ref_no' => $loanNo, 'approved_by' => $approver['id'] ?? null];
            if ($cashAcct) {
                // through Banking: a Cash Voucher, or a check numbered with the cheque no.
                $txnId = CoopLedger::postVoucher(LoanVouchers::TYPES[$mode], $head + ['amount' => $plan['net_proceeds'], 'txn_no' => $mode === 'cheque' ? $chequeNo : ''],
                    $gl, ['approved_by' => $approver['username'] ?? null]);
            } else {
                $txnId = CoopLedger::post('loan_release', $head + ['txn_no' => $voucherNo], $gl, ['approved_by' => $approver['username'] ?? null]);
            }
            $txn = Txn::load($txnId);
            LoanVouchers::forget($txnId);
            $row = [
                'loan_no' => $loanNo, 'application_id' => $appId, 'member_id' => $m['id'], 'product_id' => $p['id'], 'branch_id' => $app['branch_id'], 'pn_no' => $pnNo,
                'principal' => Dec::fix($plan['principal']), 'rate' => $terms['rate'], 'interest_method' => $p['interest_method'], 'amort_type' => $terms['amort_type'],
                'term' => $terms['term'], 'term_unit' => $terms['term_unit'], 'mode_of_payment' => $terms['mode_of_payment'], 'calendar_days' => (int)$p['calendar_days'] ?: 365,
                'release_date' => $r['release_date'], 'start_date' => $r['start_date'], 'due_date' => $plan['schedule']['due_date'],
                'release_mode' => $mode, 'cheque_no' => $mode === 'cheque' && $cashAcct ? mb_substr((string)$txn['txn_no'], 0, 40) : null, 'cash_account_id' => $cashAcct, 'credit_account_id' => $creditAcct['id'] ?? null,
                'receivable_account_id' => $recv, 'interest_account_id' => $intAcct, 'penalty_account_id' => $p['penalty_account_id'] ?: Accts::get('loan_penalty_income'),
                'penalty_rate' => $p['penalty_rate'], 'penalty_grace_days' => (int)$p['penalty_grace_days'], 'penalty_base' => $p['penalty_base'],
                'total_interest' => Dec::fix($plan['schedule']['total_interest']), 'total_charges' => Dec::fix($plan['total_charges']), 'net_proceeds' => Dec::fix($plan['net_proceeds']),
                'previous_loan_payoff' => Dec::fix($plan['payoff']), 'status' => 'current', 'principal_balance' => Dec::fix($plan['principal']), 'interest_balance' => Dec::fix($plan['schedule']['total_interest']),
                'officer_id' => $r['officer_id'] ?? $app['officer_id'], 'release_txn_id' => $txnId, 'created_by' => Auth::id(),
            ];
            if (LoanVouchers::ready()) {
                $row['voucher_no'] = $voucherNo;
            }
            $loanId = DB::insert('loan_accounts', $row);
            foreach ($plan['schedule']['rows'] as $row) {
                DB::insert('loan_schedule', ['loan_id' => $loanId, 'inst_no' => $row['inst_no'], 'due_date' => $row['due_date'], 'principal' => Dec::fix($row['principal']),
                    'interest' => Dec::fix($row['interest']), 'total' => Dec::fix($row['total']), 'balance_after' => Dec::fix($row['balance_after'])]);
            }
            foreach ($plan['charges'] as $ch) {
                $c = $ch['charge'];
                DB::insert('loan_charges_applied', ['loan_id' => $loanId, 'charge_id' => $c['id'], 'name' => $c['name'], 'charge_type' => $c['charge_type'], 'basis_text' => mb_substr((string)$ch['basis'], 0, 60),
                    'amount' => Dec::fix($ch['amount']), 'account_id' => $c['account_id'], 'sav_account_id' => $ch['sav_account_id'] ?? null, 'how' => $plan['charge_method']]);
            }
            DB::insert('loan_txns', ['loan_id' => $loanId, 'txn_date' => $r['release_date'], 'kind' => 'release', 'memo' => 'Release', 'principal_dr' => Dec::fix($plan['principal']),
                'interest_dr' => Dec::fix($plan['schedule']['total_interest']), 'principal_bal' => Dec::fix($plan['principal']), 'interest_bal' => Dec::fix($plan['schedule']['total_interest']),
                'penalty_bal' => '0.00', 'txn_id' => $txnId, 'teller_id' => Auth::id(), 'shift_id' => $r['shift_id'] ?? null, 'created_by' => Auth::id()]);
            if ($creditAcct) {
                Savings::move($creditAcct, 'LP', 'credit', $plan['net_proceeds'], $r['release_date'], ['txn_id' => $txnId, 'description' => 'Loan proceeds ' . $loanNo, 'ref_type' => 'loan', 'ref_id' => $loanId, 'is_system' => true, 'shift_id' => $r['shift_id'] ?? null]);
            }
            foreach ($savingsCharges as [$acct, $ch]) {
                $acct = Savings::account((int)$acct['id'], true);
                Savings::move($acct, 'LC', 'credit', $ch['amount'], $r['release_date'], ['txn_id' => $txnId, 'description' => $ch['charge']['name'] . ' from loan ' . $loanNo, 'ref_type' => 'loan', 'ref_id' => $loanId, 'is_system' => true]);
            }
            if ($prev) {
                self::applyPayment($prev, $due, $r['release_date'], $due['payoff'], ['payoff'], $txnId, ['memo' => 'Paid off by ' . (($r['restructure'] ?? false) ? 'restructuring' : 'renewal') . ' ' . $loanNo, 'kind' => 'restructure', 'shift_id' => $r['shift_id'] ?? null]);
                DB::update('loan_accounts', ['status' => 'renewed', 'restructured_to_id' => $loanId, 'closed_on' => $r['release_date']], 'id = :id', ['id' => $prev['id']]);
                // a restructuring (of a loan in arrears) keeps the new loan flagged as restructured for the portfolio reports;
                // a renewal is an ordinary current loan
                DB::update('loan_accounts', ['restructured_from_id' => $prev['id'], 'status' => !empty($r['restructure']) ? 'restructured' : 'current'], 'id = :id', ['id' => $loanId]);
                DB::run('UPDATE sav_holds SET released_at = NOW(), released_by = :u WHERE loan_account_id = :l AND released_at IS NULL', ['u' => Auth::id(), 'l' => $prev['id']]);
                Savings::refreshHolds((int)$prev['id']);
            }
            DB::update('loan_applications', ['status' => 'released', 'loan_id' => $loanId, 'updated_by' => Auth::id()], 'id = :id', ['id' => $appId]);
            // cash paid out of the teller's drawer: a cash-out of the shift (Teller Shift & Cash Count, Daily Collection & Disbursement Summary)
            if ($cashAcct && $mode === 'cash' && !empty($r['shift_id']) && Dec::isPos($plan['net_proceeds'])) {
                Teller::recordReceipt($txnId, 'cv', (string)$txn['txn_no'], (int)$m['id'], (int)$app['branch_id'], (int)$r['shift_id'], 'cash', $cashAcct, $plan['net_proceeds'],
                    [['kind' => 'loan_principal', 'ref_id' => $loanId, 'account_id' => $recv, 'description' => 'Loan proceeds ' . $loanNo, 'amount' => $plan['net_proceeds']]]);
            }
            // deductions added, deleted or changed on the release form, against the product's own
            $changed = '';
            if (isset($terms['charges'])) {
                $std = [];
                foreach (self::charges($p, $plan['approved'], self::termMonths($terms['term'], $terms['term_unit']), $r['release_date']) as $c) {
                    $std[$c['charge']['name']] = Dec::fix($c['amount']);
                }
                $now = [];
                foreach ($plan['charges'] as $c) {
                    $now[$c['charge']['name']] = Dec::fix($c['amount']);
                }
                if ($std != $now) {
                    $diff = [];
                    foreach (array_unique(array_merge(array_keys($std), array_keys($now))) as $name) {
                        $a = $std[$name] ?? null;
                        $b = $now[$name] ?? null;
                        if ($a !== $b) {
                            $diff[] = $name . ': ' . ($a === null ? 'added ' . Dec::money($b) : ($b === null ? 'deleted (' . Dec::money($a) . ')' : Dec::money($a) . ' to ' . Dec::money($b)));
                        }
                    }
                    $changed = 'Deductions changed on the release form: ' . implode('; ', $diff);
                }
            }
            Audit::log('release', 'loan', 'loan_account', $loanId, $loanNo . ' ' . $m['full_name'], ['page_key' => $pageKey, 'amount' => (float)$plan['principal'], 'approved_by' => $approver['username'] ?? null,
                'reason' => $changed !== '' ? mb_substr($changed, 0, 255) : null,
                'snapshot' => ['principal' => $plan['principal'], 'net_proceeds' => $plan['net_proceeds'], 'charges' => $plan['total_charges'], 'rate' => $terms['rate'], 'term' => $terms['term'] . ' ' . $terms['term_unit'],
                    'txn_id' => $txnId, 'voucher' => LoanVouchers::label($txn['txn_type']) . ' ' . $txn['txn_no'], 'voucher_id' => $voucherNo]]);
            return ['loan_id' => $loanId, 'txn_id' => $txnId, 'loan_no' => $loanNo, 'voucher_no' => $voucherNo, 'txn_type' => $txn['txn_type']];
        });
    }

    // ------------------------------------------------------------------ loan accounts, dues and penalties

    public static function loan(int $id, bool $lock = false): ?array
    {
        return DB::row('SELECT l.*, m.full_name AS member_name, m.member_no, m.hold_flag, m.hold_reason, m.alert_note, m.status AS member_status, p.name AS product_name, p.code AS product_code, p.past_due_basis
                          FROM loan_accounts l JOIN members m ON m.id = l.member_id JOIN loan_products p ON p.id = l.product_id WHERE l.id = :id' . ($lock ? ' FOR UPDATE' : ''), ['id' => $id]);
    }

    public static function byNo(string $no): ?array
    {
        $id = DB::value('SELECT id FROM loan_accounts WHERE loan_no = :n', ['n' => trim($no)]);
        return $id ? self::loan((int)$id) : null;
    }

    /**
     * The box that finds a loan by its borrower (assets/js/loan_pick.js): a search box named $name whose value is the loan
     * no.; while it is typed in, or as soon as it is clicked, a list under it shows the borrowers with outstanding loans
     * (name, loan product, loan no., balance), narrowed by the name, the loan no. or the loan product typed. Choosing one
     * puts its loan no. in the box and, with 'submit', sends the form. $opt: label, id, class, placeholder, submit.
     */
    public static function pickerBox(string $name, string $value, array $opt = []): string
    {
        $id = $opt['id'] ?? 'lp_' . preg_replace('/\W/', '', $name);
        return '<div class="' . h($opt['class'] ?? 'col-auto') . ' loan-pick-col"><label class="form-label" for="' . h($id) . '">' . h($opt['label'] ?? 'Borrower or loan no.') . '</label>'
            . '<div class="loan-pick" data-loan-pick data-api="' . h(url('api.coop', ['a' => 'loans'])) . '"' . (!empty($opt['submit']) ? ' data-submit' : '') . '>'
            . '<i class="bi bi-search" aria-hidden="true"></i>'
            . '<input type="search" class="form-control form-control-sm" id="' . h($id) . '" name="' . h($name) . '" value="' . h($value) . '" autocomplete="off" spellcheck="false"'
            . ' placeholder="' . h($opt['placeholder'] ?? 'Type a name, loan no. or loan product') . '" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="' . h($id) . '_list">'
            . '<div class="loan-pick-list" id="' . h($id) . '_list" role="listbox" hidden></div></div></div>';
    }

    /**
     * The loans with a balance (current, past due, restructured) of the branches the user sees, for a search-as-you-type
     * box (Loan Statement of Account): every word typed must be found at the start of a word of the borrower's name or
     * of the loan product, or in the loan no. or the member no. An empty search lists them all, by name. Returns
     * ['rows' => [id, loan_no, name, member_no, product, balance, status], 'total' => count of the matches].
     */
    public static function searchOutstanding(string $q, int $limit = 50): array
    {
        [$bf, $bp] = Coop::branchFilter('l.branch_id', 'lbr');
        $where = "l.status IN ('current','past_due','restructured')" . $bf;
        $prm = $bp;
        $words = array_slice(array_values(array_filter(preg_split('/[\s,]+/u', trim($q)) ?: [], fn($w) => $w !== '')), 0, 6);
        foreach ($words as $i => $w) {
            $like = strtr($w, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
            $where .= " AND (m.full_name LIKE :a$i OR m.full_name LIKE :b$i OR m.full_name LIKE :c$i OR p.name LIKE :d$i OR p.name LIKE :e$i
                             OR p.code LIKE :f$i OR l.loan_no LIKE :g$i OR m.member_no LIKE :h$i)";
            $prm += ["a$i" => $like . '%', "b$i" => '% ' . $like . '%', "c$i" => '%,' . $like . '%', "d$i" => $like . '%', "e$i" => '% ' . $like . '%',
                "f$i" => $like . '%', "g$i" => '%' . $like . '%', "h$i" => $like . '%'];
        }
        $from = "FROM loan_accounts l JOIN members m ON m.id = l.member_id JOIN loan_products p ON p.id = l.product_id WHERE $where";
        $total = (int)DB::value("SELECT COUNT(*) $from", $prm);
        $first = $words ? strtr($words[0], ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) : '';
        $rows = DB::all("SELECT l.id, l.loan_no, l.status, l.principal_balance, m.full_name, m.member_no, p.name AS product,
                                CASE WHEN l.loan_no = :exact THEN 0 WHEN m.full_name LIKE :first THEN 1 WHEN l.loan_no LIKE :firstno THEN 2 ELSE 3 END AS rank_no
                         $from ORDER BY rank_no, m.full_name, l.release_date, l.loan_no LIMIT " . max(1, min(200, $limit)),
            $prm + ['exact' => trim($q), 'first' => $first . '%', 'firstno' => $first . '%']);
        return ['total' => $total, 'rows' => array_map(fn($r) => ['id' => (int)$r['id'], 'loan_no' => $r['loan_no'], 'name' => $r['full_name'], 'member_no' => $r['member_no'],
            'product' => $r['product'], 'balance' => Dec::money($r['principal_balance']), 'principal_balance' => Dec::fix($r['principal_balance']), 'status' => $r['status'] === 'past_due' ? 'Past due' : ($r['status'] === 'restructured' ? 'Restructured' : 'Current')], $rows)];
    }

    public static function scheduleRows(int $loanId): array
    {
        return DB::all('SELECT * FROM loan_schedule WHERE loan_id = :l ORDER BY inst_no', ['l' => $loanId]);
    }

    /**
     * The penalty earned on the overdue amortizations from the last penalty computation (or from the end of the
     * grace period) through $asOf, by the loan's own terms:
     *   penalty = base x annual penalty rate / 100 x days / calendar days
     * base: the unpaid principal + interest of each overdue amortization (arrears), its unpaid principal
     * (principal_arrears), or the outstanding principal from the first overdue amortization (balance).
     * Counted incrementally, so a partial payment lowers the base from the day it is made.
     * Returns the new charge (not yet on the ledger), what is already charged and the detail.
     */
    public static function penaltyThrough(array $loan, string $asOf): array
    {
        $rows = Dec::isPos($loan['penalty_rate']) ? DB::all('SELECT * FROM loan_schedule WHERE loan_id = :l AND due_date < :d AND is_paid = 0 ORDER BY inst_no', ['l' => $loan['id'], 'd' => $asOf]) : [];
        [$new, $detail] = self::penaltyOn($loan, $rows, $asOf, $loan['last_penalty_date'] ?: null);
        $charged = Dec::of(DB::value("SELECT COALESCE(SUM(penalty_dr),0) FROM loan_txns WHERE loan_id = :l AND is_voided = 0 AND kind = 'penalty'", ['l' => $loan['id']]));
        return ['total' => Dec::add($charged, $new, 2), 'charged' => $charged, 'new' => $new, 'detail' => $detail];
    }

    /**
     * The penalty of penaltyThrough() on given schedule rows (due_date, inst_no, principal, interest, principal_paid,
     * interest_paid): the rows due before $asOf and not fully paid count, from the end of their grace period or from
     * $since. $loan gives penalty_rate, penalty_grace_days, penalty_base, calendar_days and, for the base "balance",
     * principal_balance. Returns [new penalty, detail]. Also used by Import Loans before the loan exists.
     */
    public static function penaltyOn(array $loan, array $rows, string $asOf, ?string $since): array
    {
        $rate = Dec::of($loan['penalty_rate']);
        $grace = (int)$loan['penalty_grace_days'];
        $cal = (string)((int)$loan['calendar_days'] ?: 365);
        $new = '0';
        $detail = [];
        $calc = fn(string $base, int $days) => Dec::round(Dec::div(Dec::mul(Dec::mul($base, $rate, 10), (string)$days, 10), Dec::mul('100', $cal, 4), 10), 2);
        if (!Dec::isPos($rate)) {
            return [$new, $detail];
        }
        $rows = array_values(array_filter($rows, fn($s) => $s['due_date'] < $asOf
            && (Dec::isPos(Dec::sub($s['principal'], $s['principal_paid'], 2)) || Dec::isPos(Dec::sub($s['interest'], $s['interest_paid'], 2)))));
        usort($rows, fn($a, $b) => (int)$a['inst_no'] <=> (int)$b['inst_no']);
        if ($loan['penalty_base'] === 'balance') {
            if ($rows) {
                $start = Coop::addDays($rows[0]['due_date'], $grace);
                if ($since && $since > $start) {
                    $start = $since;
                }
                $days = Coop::daysBetween($start, $asOf);
                if ($days > 0 && Dec::isPos($loan['principal_balance'])) {
                    $new = $calc(Dec::of($loan['principal_balance']), $days);
                    $detail[] = ['inst_no' => 0, 'due_date' => $rows[0]['due_date'], 'from' => $start, 'base' => Dec::of($loan['principal_balance']), 'days' => $days, 'penalty' => $new];
                }
            }
            return [$new, $detail];
        }
        foreach ($rows as $s) {
            $unpaidP = Dec::sub($s['principal'], $s['principal_paid'], 2);
            $unpaidI = Dec::sub($s['interest'], $s['interest_paid'], 2);
            $base = $loan['penalty_base'] === 'principal_arrears' ? $unpaidP : Dec::add($unpaidP, $unpaidI, 2);
            if (!Dec::isPos($base)) {
                continue;
            }
            $start = Coop::addDays($s['due_date'], $grace);
            if ($since && $since > $start) {
                $start = $since;
            }
            $days = Coop::daysBetween($start, $asOf);
            if ($days <= 0) {
                continue;
            }
            $pen = $calc($base, $days);
            $new = Dec::add($new, $pen, 2);
            $detail[] = ['inst_no' => (int)$s['inst_no'], 'due_date' => $s['due_date'], 'from' => $start, 'base' => $base, 'days' => $days, 'penalty' => $pen];
        }
        return [$new, $detail];
    }

    /**
     * What is due on a loan as of a date: overdue amortizations (principal, interest, how many), penalties (charged
     * and still to charge), the daily interest since the last amortization date (for a payoff), and the payoff amount.
     */
    public static function dues(array $loan, string $asOf): array
    {
        $rows = self::scheduleRows((int)$loan['id']);
        $pDue = '0';
        $iDue = '0';
        $nDue = 0;
        $nextDate = null;
        $lastDueDate = $loan['release_date'] ?: $loan['start_date'];
        $future = [];
        foreach ($rows as $s) {
            $uP = Dec::sub($s['principal'], $s['principal_paid'], 2);
            $uI = Dec::sub($s['interest'], $s['interest_paid'], 2);
            if ($s['due_date'] <= $asOf) {
                if (Dec::isPos($uP) || Dec::isPos($uI)) {
                    $pDue = Dec::add($pDue, $uP, 2);
                    $iDue = Dec::add($iDue, $uI, 2);
                    $nDue++;
                }
                $lastDueDate = $s['due_date'];
            } else {
                $nextDate ??= $s['due_date'];
                if (Dec::isPos($uP) || Dec::isPos($uI)) {
                    $future[] = ['inst_no' => (int)$s['inst_no'], 'due_date' => $s['due_date'], 'interest' => $uI, 'principal' => $uP];
                }
            }
        }
        $pen = self::penaltyThrough($loan, $asOf);
        // with "compute penalties on collection" off, only the penalties already posted by the batch run are due
        $penaltyDue = Coop::setting('penalty_on_collection', '1') === '1' ? Dec::add($loan['penalty_balance'], $pen['new'], 2) : Dec::of($loan['penalty_balance']);
        // daily interest on the principal balance since the last amortization date that has passed (real-time interest)
        $daysSince = max(0, Coop::daysBetween($lastDueDate, $asOf));
        $cal = (string)((int)$loan['calendar_days'] ?: 365);
        $interestToDate = '0';
        if ($loan['interest_method'] === 'diminishing' && $nextDate !== null && $daysSince > 0) {
            $interestToDate = Dec::round(Dec::div(Dec::mul(Dec::mul($loan['principal_balance'], $loan['rate'], 10), (string)$daysSince, 10), Dec::mul('100', $cal, 4), 10), 2);
            $interestToDate = Dec::min($interestToDate, Dec::sub($loan['interest_balance'], $iDue, 2));
            if (Dec::isNeg($interestToDate)) {
                $interestToDate = '0';
            }
        }
        // payoff: diminishing loans pay the principal balance + interest due + interest to date + penalties;
        // straight (add-on) loans pay every scheduled interest unless a rebate is given on the adjustment page
        $payoff = Dec::add(Dec::add($loan['principal_balance'], $iDue, 2), $penaltyDue, 2);
        $payoff = Dec::add($payoff, $loan['interest_method'] === 'diminishing' ? $interestToDate : Dec::sub($loan['interest_balance'], $iDue, 2), 2);
        return ['principal_due' => $pDue, 'interest_due' => $iDue, 'count_due' => $nDue, 'penalty_due' => $penaltyDue, 'penalty_new' => $pen['new'], 'penalty_detail' => $pen['detail'],
            'interest_to_date' => $interestToDate, 'days_since' => $daysSince, 'next_due' => $nextDate, 'last_due' => $lastDueDate, 'future' => $future, 'interest_method' => (string)$loan['interest_method'],
            'principal_balance' => Dec::of($loan['principal_balance']), 'interest_balance' => Dec::of($loan['interest_balance']), 'penalty_balance' => Dec::of($loan['penalty_balance']),
            'total_due' => Dec::add(Dec::add($pDue, $iDue, 2), $penaltyDue, 2), 'payoff' => $payoff];
    }

    /**
     * The cut-off date of a loan brought over from a former system (release mode "opening": Import Loans, or the
     * opening balances of the migration): the date of its opening voucher, else its release date. Its past due interest
     * and penalties up to that date came with it. null for a loan released here.
     */
    public static function importedThrough(array $loan): ?string
    {
        if (($loan['release_mode'] ?? '') !== 'opening') {
            return null;
        }
        $d = !empty($loan['release_txn_id']) ? DB::value('SELECT txn_date FROM transactions WHERE id = :t', ['t' => $loan['release_txn_id']]) : null;
        return (string)($d ?: $loan['release_date']);
    }

    /** True once a loan product carries its additional interest (upgrade 2027_01_10) */
    public static function addlInterestReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(addl_interest) FROM loan_products WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * The additional interest of a past-due loan through $asOf, by its product (ADDL_INTEREST), per day at the loan's
     * rate (interest = base x rate / 100 x days / days in a year):
     *   arrears  : the principal of each amortization not paid by its due date, from that date; each payment on it
     *              lowers the base from the day it is made;
     *   maturity : the principal balance after the maturity date, lowered by each payment from its date.
     * "auto" is arrears for a diminishing-balance loan and maturity for an add-on loan. It is computed, not posted: the
     * Internal Ledger of the loan shows it with the penalty still to charge (penaltyThrough()).
     * Returns ['total', 'mode', 'detail' => [[inst_no, due_date, from, base, days, interest]]].
     */
    public static function additionalInterest(array $loan, string $asOf): array
    {
        // a loan brought over from a former system: its past due interest up to the cut-off date came with it
        $since = self::importedThrough($loan);
        $mode = self::addlInterestReady() ? (string)DB::value('SELECT addl_interest FROM loan_products WHERE id = :p', ['p' => $loan['product_id']]) : 'none';
        if ($mode === 'auto' || $mode === '') {
            $mode = $loan['interest_method'] === 'diminishing' ? 'arrears' : 'maturity';
        }
        $out = ['total' => '0', 'mode' => $mode, 'detail' => []];
        $rate = Dec::of($loan['rate']);
        if ($mode === 'none' || !Dec::isPos($rate) || !in_array($loan['status'], self::OPEN_STATUSES, true)) {
            return $out;
        }
        $cal = (string)((int)$loan['calendar_days'] ?: 365);
        $calc = fn(string $base, int $days) => Dec::round(Dec::div(Dec::mul(Dec::mul($base, $rate, 10), (string)$days, 10), Dec::mul('100', $cal, 4), 10), 2);
        // the interest on a base that changes on given dates, from $from to $asOf
        $run = function (string $base, string $from, array $changes) use ($asOf, $calc): array {
            $sum = '0';
            $days = 0;
            $first = null;
            foreach ($changes as [$date, $delta]) {
                if ($date >= $asOf) {
                    break;
                }
                if ($date > $from) {
                    $d = Coop::daysBetween($from, $date);
                    if (Dec::isPos($base) && $d > 0) {
                        $first ??= $base;
                        $sum = Dec::add($sum, $calc($base, $d), 2);
                        $days += $d;
                    }
                    $from = $date;
                }
                $base = Dec::add($base, $delta, 2);
            }
            $d = Coop::daysBetween($from, $asOf);
            if (Dec::isPos($base) && $d > 0) {
                $first ??= $base;
                $sum = Dec::add($sum, $calc($base, $d), 2);
                $days += $d;
            }
            return [$sum, $days, $first ?? '0'];
        };
        if ($mode === 'arrears') {
            foreach (DB::all('SELECT * FROM loan_schedule WHERE loan_id = :l AND due_date < :d ORDER BY inst_no', ['l' => $loan['id'], 'd' => $asOf]) as $sc) {
                // the principal of the amortization still unpaid on its due date, then each payment on it after
                $paid = DB::all('SELECT t.txn_date, SUM(a.principal) AS p FROM loan_payment_alloc a JOIN loan_txns t ON t.id = a.loan_txn_id
                                  WHERE a.schedule_id = :s AND t.is_voided = 0 GROUP BY t.txn_date ORDER BY t.txn_date', ['s' => $sc['id']]);
                $base = Dec::of($sc['principal']);
                $changes = [];
                foreach ($paid as $pd) {
                    if ($pd['txn_date'] <= $sc['due_date']) {
                        $base = Dec::sub($base, $pd['p'], 2);
                    } else {
                        $changes[] = [$pd['txn_date'], Dec::neg($pd['p'])];
                    }
                }
                $from = $sc['due_date'];
                if ($since !== null && $since > $from) {
                    foreach ($changes as $i => [$date, $delta]) {
                        if ($date <= $since) {
                            $base = Dec::add($base, $delta, 2);
                            unset($changes[$i]);
                        }
                    }
                    $changes = array_values($changes);
                    $from = $since;
                }
                [$amt, $days, $first] = $run($base, $from, $changes);
                if (Dec::isPos($amt)) {
                    $out['detail'][] = ['inst_no' => (int)$sc['inst_no'], 'due_date' => $sc['due_date'], 'from' => $from, 'base' => $first, 'days' => $days, 'interest' => $amt];
                    $out['total'] = Dec::add($out['total'], $amt, 2);
                }
            }
        } elseif (max((string)$loan['due_date'], (string)$since) < $asOf) {
            $from = max((string)$loan['due_date'], (string)$since);
            $base = Dec::of(DB::value('SELECT COALESCE(SUM(principal_dr - principal_cr),0) FROM loan_txns WHERE loan_id = :l AND is_voided = 0 AND txn_date <= :d', ['l' => $loan['id'], 'd' => $from]));
            $changes = array_map(fn($r) => [$r['txn_date'], Dec::of($r['c'])], DB::all('SELECT txn_date, SUM(principal_dr - principal_cr) AS c FROM loan_txns WHERE loan_id = :l AND is_voided = 0 AND txn_date > :d
                                                                                         GROUP BY txn_date ORDER BY txn_date', ['l' => $loan['id'], 'd' => $from]));
            [$amt, $days, $first] = $run($base, $from, $changes);
            if (Dec::isPos($amt)) {
                $out['detail'][] = ['inst_no' => 0, 'due_date' => $loan['due_date'], 'from' => $from, 'base' => $first, 'days' => $days, 'interest' => $amt];
                $out['total'] = $amt;
            }
        }
        return $out;
    }

    /** The words of a loan ledger row */
    public static function ledgerKind(array $r): string
    {
        return match ((string)$r['kind']) {
            'release' => 'Release',
            'payment' => 'Payment',
            'correction' => 'Error correction',
            'penalty' => 'Penalty',
            'waiver' => 'Waiver',
            'adjustment' => 'Adjustment',
            'rebate' => 'Interest rebate',
            'restructure' => 'Paid off by a new loan',
            'writeoff' => 'Write-off',
            'recovery' => 'Recovery',
            'accrual' => 'Interest accrual',
            'pastdue' => 'Past due interest',
            default => ucfirst((string)$r['kind']),
        };
    }

    /**
     * The Loan Ledger Form (passbook format): Date, Reference No, Release, Prin. Payments, Prin. Balance, Int. Payments,
     * Fines/Surc. The passbook view has the lines the loan passbook prints (the release, the payments with their error
     * corrections, the adjustments: a decrease as a payment, an increase negative); View All has every entry of the
     * loan ledger, the charges (penalties, adjustments, waivers ...) told in the reference.
     */
    public static function ledgerForm(array $loan, bool $all): array
    {
        $kinds = $all ? '' : " AND x.kind IN ('" . implode("','", Passbook::LOAN_KINDS) . "')";
        $rows = [];
        $tot = ['release' => '0', 'prin' => '0', 'int' => '0', 'fines' => '0'];
        foreach (DB::all("SELECT x.*, t.txn_no FROM loan_txns x LEFT JOIN transactions t ON t.id = x.txn_id
                           WHERE x.loan_id = :l AND x.is_voided = 0 AND x.kind <> 'accrual'$kinds ORDER BY x.txn_date, x.id", ['l' => $loan['id']]) as $r) {
            $ref = match ((string)$r['kind']) {
                'release' => (string)($loan['pn_no'] ?: $r['txn_no']),
                'correction' => 'EC ' . (string)($r['or_no'] ?: $r['txn_no']),
                default => (string)($r['or_no'] ?: $r['txn_no']),
            };
            $note = '';
            if ($all && !in_array($r['kind'], ['release', 'payment', 'correction'], true)) {
                $bits = [self::ledgerKind($r)];
                if (Dec::isPos($r['interest_dr']) && $r['kind'] !== 'release') {
                    $bits[] = 'interest charged ' . Dec::money($r['interest_dr']);
                }
                if (Dec::isPos($r['penalty_dr'])) {
                    $bits[] = 'penalty charged ' . Dec::money($r['penalty_dr']);
                }
                $note = implode(', ', $bits);
            }
            $row = ['date' => $r['txn_date'], 'ref' => $ref, 'note' => $note, 'release' => Dec::of($r['principal_dr']), 'prin' => Dec::of($r['principal_cr']),
                'bal' => Dec::of($r['principal_bal']), 'int' => Dec::of($r['interest_cr']), 'fines' => Dec::of($r['penalty_cr'])];
            if (!$all && $r['kind'] === 'adjustment') {
                // as the passbook prints it (Passbook::loanLine)
                $pl = Passbook::loanLine($r, $loan);
                $row = ['ref' => $pl['ref'] . ' (adjustment)', 'release' => '0', 'prin' => $pl['prin'], 'int' => $pl['int'], 'fines' => $pl['fines']] + $row;
            }
            foreach (['release', 'prin', 'int', 'fines'] as $k) {
                $tot[$k] = Dec::add($tot[$k], $row[$k], 2);
            }
            $rows[] = $row;
        }
        return ['rows' => $rows, 'totals' => $tot];
    }

    /**
     * The Internal Ledger of a loan: every entry of the loan ledger (Release / Additional Charges, Payments and the
     * Balances of the principal, the interest and the penalty), then the additional interest (additionalInterest())
     * and the penalty (penaltyThrough()) the loan has earned through $asOf by its product set-up because it is past
     * due or an amortization was missed, computed and not yet on the ledger.
     */
    public static function internalLedger(array $loan, string $asOf): array
    {
        $rows = [];
        $bal = ['p' => '0', 'i' => '0', 'pen' => '0'];
        $tot = ['add_p' => '0', 'add_i' => '0', 'add_pen' => '0', 'pay_p' => '0', 'pay_i' => '0', 'pay_pen' => '0'];
        $add = function (array $row) use (&$rows, &$bal, &$tot) {
            $bal['p'] = Dec::sub(Dec::add($bal['p'], $row['add_p'], 2), $row['pay_p'], 2);
            $bal['i'] = Dec::sub(Dec::add($bal['i'], $row['add_i'], 2), $row['pay_i'], 2);
            $bal['pen'] = Dec::sub(Dec::add($bal['pen'], $row['add_pen'], 2), $row['pay_pen'], 2);
            foreach ($tot as $k => $v) {
                $tot[$k] = Dec::add($v, $row[$k], 2);
            }
            $rows[] = $row + ['bal_p' => $bal['p'], 'bal_i' => $bal['i'], 'bal_pen' => $bal['pen']];
        };
        foreach (DB::all("SELECT x.*, t.txn_no FROM loan_txns x LEFT JOIN transactions t ON t.id = x.txn_id
                           WHERE x.loan_id = :l AND x.is_voided = 0 AND x.kind <> 'accrual' AND x.txn_date <= :d ORDER BY x.txn_date, x.id", ['l' => $loan['id'], 'd' => $asOf]) as $r) {
            $memo = self::ledgerKind($r);
            $ref = (string)($r['or_no'] ?: $r['txn_no']);
            if (!in_array($r['kind'], ['release', 'payment', 'correction'], true) && trim((string)$r['memo']) !== '') {
                $memo .= ': ' . $r['memo'];
            }
            $add(['date' => $r['txn_date'], 'memo' => $memo, 'ref' => $ref, 'computed' => false,
                'add_p' => Dec::of($r['principal_dr']), 'add_i' => Dec::of($r['interest_dr']), 'add_pen' => Dec::of($r['penalty_dr']),
                'pay_p' => Dec::of($r['principal_cr']), 'pay_i' => Dec::of($r['interest_cr']), 'pay_pen' => Dec::of($r['penalty_cr'])]);
        }
        $posted = $bal;
        $extra = ['interest' => '0', 'penalty' => '0'];
        if (in_array($loan['status'], self::OPEN_STATUSES, true)) {
            $ai = self::additionalInterest($loan, $asOf);
            foreach ($ai['detail'] as $x) {
                $what = $x['inst_no'] ? 'amortization #' . $x['inst_no'] . ' due ' . fdate($x['due_date']) : 'the balance after maturity (' . fdate($x['due_date']) . ')';
                $add(['date' => $asOf, 'memo' => 'Additional interest: ' . $what . ', ' . $x['days'] . ' day(s)' . ($x['from'] !== $x['due_date'] ? ' from ' . fdate($x['from']) : '') . ' at ' . Dec::fix($loan['rate'], 2) . ' %', 'ref' => '', 'computed' => true,
                    'add_p' => '0', 'add_i' => $x['interest'], 'add_pen' => '0', 'pay_p' => '0', 'pay_i' => '0', 'pay_pen' => '0']);
                $extra['interest'] = Dec::add($extra['interest'], $x['interest'], 2);
            }
            if (!$loan['last_penalty_date'] || $loan['last_penalty_date'] < $asOf) {
                $pen = self::penaltyThrough($loan, $asOf);
                foreach ($pen['detail'] as $x) {
                    $what = $x['inst_no'] ? 'amortization #' . $x['inst_no'] . ' due ' . fdate($x['due_date']) : 'the balance';
                    $add(['date' => $asOf, 'memo' => 'Penalty: ' . $what . ', ' . $x['days'] . ' day(s) on ' . Dec::money($x['base']), 'ref' => '', 'computed' => true,
                        'add_p' => '0', 'add_i' => '0', 'add_pen' => $x['penalty'], 'pay_p' => '0', 'pay_i' => '0', 'pay_pen' => '0']);
                    $extra['penalty'] = Dec::add($extra['penalty'], $x['penalty'], 2);
                }
            }
        }
        return ['rows' => $rows, 'totals' => $tot, 'balance' => $bal, 'posted' => $posted, 'additional' => $extra, 'as_of' => $asOf];
    }

    /** Posts the penalties earned through a date on one loan into the subsidiary ledger (no GL until collected) */
    public static function chargePenalty(array $loan, string $asOf, ?int $txnId = null): string
    {
        // a computation for an earlier date than the last one adds nothing (that period is already counted)
        if ($loan['last_penalty_date'] && $loan['last_penalty_date'] >= $asOf) {
            return '0';
        }
        $pen = self::penaltyThrough($loan, $asOf);
        if (!Dec::isPos($pen['new'])) {
            DB::update('loan_accounts', ['last_penalty_date' => $asOf], 'id = :id', ['id' => $loan['id']]);
            return '0';
        }
        $newBal = Dec::add($loan['penalty_balance'], $pen['new'], 2);
        DB::insert('loan_txns', ['loan_id' => $loan['id'], 'txn_date' => $asOf, 'kind' => 'penalty', 'memo' => 'Penalty through ' . fdate($asOf), 'penalty_dr' => Dec::fix($pen['new']),
            'principal_bal' => $loan['principal_balance'], 'interest_bal' => $loan['interest_balance'], 'penalty_bal' => Dec::fix($newBal), 'txn_id' => $txnId, 'created_by' => Auth::id()]);
        DB::update('loan_accounts', ['penalty_balance' => Dec::fix($newBal), 'last_penalty_date' => $asOf], 'id = :id', ['id' => $loan['id']]);
        return $pen['new'];
    }

    // ------------------------------------------------------------------ collection

    /**
     * Splits an amount into penalty / interest / principal, or takes the override the user typed. Returns [penalty,
     * interest, principal, excess].
     *
     * What is due is paid first, in the allocation order (policy coop_allocation_order; by default 1. the penalties
     * due, 2. the interest due, 3. the principal due). Whatever is left, and the whole payment when no penalty and no
     * interest is due, goes to the principal (up to its balance): an advance is not taken as interest of the
     * amortizations not yet due. Only a payment beyond the whole principal pays the interest not yet due (as a
     * payoff does: the interest to date of a diminishing loan, the remaining scheduled interest of a straight one).
     */
    public static function allocate(array $dues, string $amount, ?array $override = null): array
    {
        $amount = Dec::round($amount, 2);
        if ($override !== null) {
            $pen = Dec::round($override['penalty'] ?? '0', 2);
            $int = Dec::round($override['interest'] ?? '0', 2);
            $prn = Dec::round($override['principal'] ?? '0', 2);
            $sum = Dec::add(Dec::add($pen, $int, 2), $prn, 2);
            if (Dec::cmp($sum, $amount) !== 0) {
                fail('The override (' . Dec::money($sum) . ') must add up to the amount paid (' . Dec::money($amount) . ').');
            }
            if (Dec::cmp($pen, $dues['penalty_due']) > 0) {
                fail('Penalty paid cannot exceed the penalty due (' . Dec::money($dues['penalty_due']) . ').');
            }
            if (Dec::cmp($int, $dues['interest_balance']) > 0) {
                fail('Interest paid cannot exceed the interest balance (' . Dec::money($dues['interest_balance']) . ').');
            }
            if (Dec::cmp($prn, $dues['principal_balance']) > 0) {
                fail('Principal paid cannot exceed the principal balance (' . Dec::money($dues['principal_balance']) . ').');
            }
            return [$pen, $int, $prn, '0'];
        }
        $order = array_map('trim', explode(',', Coop::setting('allocation_order', 'penalty,interest,principal')));
        $left = $amount;
        $alloc = ['penalty' => '0', 'interest' => '0', 'principal' => '0'];
        $cap = ['penalty' => $dues['penalty_due'], 'interest' => $dues['interest_due'], 'principal' => $dues['principal_due']];
        foreach ($order as $k) {
            if (!isset($alloc[$k])) {
                continue;
            }
            $take = Dec::min($left, $cap[$k]);
            $alloc[$k] = $take;
            $left = Dec::sub($left, $take, 2);
        }
        // more than what is due (or nothing due at all): the rest goes to the principal, never beyond its balance
        if (Dec::isPos($left)) {
            $moreP = Dec::min($left, Dec::sub($dues['principal_balance'], $alloc['principal'], 2));
            if (Dec::isPos($moreP)) {
                $alloc['principal'] = Dec::add($alloc['principal'], $moreP, 2);
                $left = Dec::sub($left, $moreP, 2);
            }
        }
        // the whole principal is paid and money is left: the interest not yet due, as far as a payoff takes it
        if (Dec::isPos($left)) {
            $notDue = ($dues['interest_method'] ?? '') === 'diminishing'
                ? Dec::of($dues['interest_to_date'] ?? '0')
                : Dec::sub($dues['interest_balance'], $dues['interest_due'], 2);
            $moreI = Dec::min($left, Dec::max('0', $notDue));
            if (Dec::isPos($moreI)) {
                $alloc['interest'] = Dec::add($alloc['interest'], $moreI, 2);
                $left = Dec::sub($left, $moreI, 2);
            }
        }
        return [$alloc['penalty'], $alloc['interest'], $alloc['principal'], $left];
    }

    /**
     * Applies a payment to the loan ledger and the schedule (oldest instalment first), and updates the balances.
     * The GL side is posted by the caller (Teller::receipt or release()). Returns the loan_txns id.
     */
    public static function applyPayment(array $loan, array $dues, string $date, string $amount, ?array $override, int $txnId, array $opt = []): int
    {
        $loan = self::loan((int)$loan['id'], true);
        if (!in_array($loan['status'], ['current', 'past_due', 'restructured'], true)) {
            fail('Loan ' . $loan['loan_no'] . ' is ' . self::LOAN_STATUSES[$loan['status']] . '.');
        }
        // the penalties earned through the payment date go on the ledger first, so the allocation sees them
        if (Coop::setting('penalty_on_collection', '1') === '1') {
            self::chargePenalty($loan, $date);
            $loan = self::loan((int)$loan['id'], true);
            $dues = self::dues($loan, $date);
        }
        if ($override === ['payoff']) {
            // full settlement: every penalty, the interest due plus the interest to date (diminishing) or every scheduled
            // interest (straight), and the whole principal
            $override = ['penalty' => $dues['penalty_due'], 'principal' => $dues['principal_balance'],
                'interest' => $loan['interest_method'] === 'diminishing' ? Dec::add($dues['interest_due'], $dues['interest_to_date'], 2) : $dues['interest_balance']];
            $amount = $dues['payoff'];
        }
        [$pen, $int, $prn, $excess] = self::allocate($dues, $amount, $override);
        if (Dec::isPos($excess)) {
            fail('The amount exceeds what the loan can take (' . Dec::money(Dec::sub($amount, $excess)) . '). Excess: ' . Dec::money($excess) . '. Deposit the excess to savings instead.');
        }
        $id = DB::insert('loan_txns', ['loan_id' => $loan['id'], 'txn_date' => $date, 'kind' => $opt['kind'] ?? 'payment', 'memo' => $opt['memo'] ?? 'Payment',
            'principal_cr' => Dec::fix($prn), 'interest_cr' => Dec::fix($int), 'penalty_cr' => Dec::fix($pen),
            'principal_bal' => Dec::fix(Dec::sub($loan['principal_balance'], $prn)), 'interest_bal' => Dec::fix(Dec::sub($loan['interest_balance'], $int)), 'penalty_bal' => Dec::fix(Dec::sub($loan['penalty_balance'], $pen)),
            'txn_id' => $txnId, 'or_no' => $opt['or_no'] ?? null, 'teller_id' => Auth::id(), 'shift_id' => $opt['shift_id'] ?? null, 'created_by' => Auth::id()]);
        // settle the schedule: interest then principal of each instalment, oldest first
        $leftI = $int;
        $leftP = $prn;
        foreach (self::scheduleRows((int)$loan['id']) as $s) {
            if (!Dec::isPos($leftI) && !Dec::isPos($leftP)) {
                break;
            }
            $uI = Dec::sub($s['interest'], $s['interest_paid'], 2);
            $uP = Dec::sub($s['principal'], $s['principal_paid'], 2);
            $takeI = Dec::min($leftI, $uI);
            $takeP = Dec::min($leftP, $uP);
            if (!Dec::isPos($takeI) && !Dec::isPos($takeP)) {
                continue;
            }
            $newI = Dec::add($s['interest_paid'], $takeI, 2);
            $newP = Dec::add($s['principal_paid'], $takeP, 2);
            $paid = Dec::cmp($newI, $s['interest']) >= 0 && Dec::cmp($newP, $s['principal']) >= 0;
            DB::update('loan_schedule', ['interest_paid' => Dec::fix($newI), 'principal_paid' => Dec::fix($newP), 'is_paid' => $paid ? 1 : 0, 'paid_on' => $paid ? $date : $s['paid_on']], 'id = :id', ['id' => $s['id']]);
            DB::insert('loan_payment_alloc', ['loan_txn_id' => $id, 'schedule_id' => $s['id'], 'principal' => Dec::fix($takeP), 'interest' => Dec::fix($takeI)]);
            $leftI = Dec::sub($leftI, $takeI, 2);
            $leftP = Dec::sub($leftP, $takeP, 2);
        }
        // a diminishing-balance loan paid in full: the scheduled interest of the periods not yet run is not earned
        // and is cancelled (a rebate on the ledger only: it was never in the general ledger)
        if ($loan['interest_method'] === 'diminishing' && Dec::cmp($prn, $loan['principal_balance']) === 0) {
            $unearned = Dec::sub($loan['interest_balance'], $int, 2);
            if (Dec::isPos($unearned)) {
                $rb = DB::insert('loan_txns', ['loan_id' => $loan['id'], 'txn_date' => $date, 'kind' => 'rebate', 'memo' => 'Unearned interest cancelled on full payment',
                    'interest_cr' => Dec::fix($unearned), 'principal_bal' => '0.00', 'interest_bal' => '0.00', 'penalty_bal' => Dec::fix(Dec::sub($loan['penalty_balance'], $pen)),
                    'txn_id' => $txnId, 'created_by' => Auth::id()]);
                self::settleFromAdjustment((int)$loan['id'], $rb, ['interest_cr' => $unearned]);
                foreach (DB::all('SELECT s.*, a.interest AS alloc_interest FROM loan_payment_alloc a JOIN loan_schedule s ON s.id = a.schedule_id WHERE a.loan_txn_id = :t', ['t' => $rb]) as $al) {
                    $newI = Dec::add($al['interest_paid'], $al['alloc_interest'], 2);
                    $paid = Dec::cmp($newI, $al['interest']) >= 0 && Dec::cmp($al['principal_paid'], $al['principal']) >= 0;
                    DB::update('loan_schedule', ['interest_paid' => Dec::fix($newI), 'is_paid' => $paid ? 1 : 0, 'paid_on' => $paid ? $date : $al['paid_on']], 'id = :id', ['id' => $al['id']]);
                }
            }
        }
        self::refreshBalances((int)$loan['id'], $date);
        return $id;
    }

    /** The GL lines of a loan payment: Cr receivable / interest (receivable first where accrued) / penalty income */
    public static function paymentLines(array $loan, string $pen, string $int, string $prn, string $memo): array
    {
        $gl = [];
        if (Dec::isPos($prn)) {
            $gl[] = ['account_id' => (int)$loan['receivable_account_id'], 'credit' => $prn, 'memo' => $memo . ' principal', 'member_id' => $loan['member_id']];
        }
        $accrued = Dec::min($int, Dec::of($loan['interest_accrued']));
        if (Dec::isPos($int)) {
            if (Dec::isPos($accrued)) {
                $gl[] = ['account_id' => Accts::get('loan_interest_receivable'), 'credit' => $accrued, 'memo' => $memo . ' accrued interest', 'member_id' => $loan['member_id']];
            }
            $rest = Dec::sub($int, $accrued, 2);
            if (Dec::isPos($rest)) {
                $gl[] = ['account_id' => (int)$loan['interest_account_id'], 'credit' => $rest, 'memo' => $memo . ' interest', 'member_id' => $loan['member_id']];
            }
        }
        // a diminishing loan paid in full: interest accrued for the days after the payment will never be earned;
        // it comes back out of income (the same amount the rebate row clears from the loan's receivable)
        if ($loan['interest_method'] === 'diminishing' && Dec::isPos($prn) && Dec::cmp($prn, $loan['principal_balance']) === 0) {
            $left = Dec::sub($loan['interest_accrued'], $accrued, 2);
            $unearned = Dec::sub($loan['interest_balance'], $int, 2);
            $rev = Dec::min($left, $unearned);
            if (Dec::isPos($rev)) {
                $gl[] = ['account_id' => (int)$loan['interest_account_id'], 'debit' => $rev, 'memo' => $memo . ' accrued interest not earned (paid in full)', 'member_id' => $loan['member_id']];
                $gl[] = ['account_id' => Accts::get('loan_interest_receivable'), 'credit' => $rev, 'memo' => $memo . ' accrued interest not earned (paid in full)', 'member_id' => $loan['member_id']];
            }
        }
        if (Dec::isPos($pen)) {
            $gl[] = ['account_id' => (int)($loan['penalty_account_id'] ?: Accts::get('loan_penalty_income')), 'credit' => $pen, 'memo' => $memo . ' penalty', 'member_id' => $loan['member_id']];
        }
        return $gl;
    }

    /** Balances and status from the subsidiary ledger and the schedule */
    public static function refreshBalances(int $loanId, ?string $asOf = null): void
    {
        $loan = self::loan($loanId, true);
        // a payment negated by an error correction is not the last payment
        $notCorrected = LoanTxn::ready() ? ' AND corrected_by_id IS NULL' : '';
        $t = DB::row("SELECT COALESCE(SUM(principal_dr - principal_cr),0) AS p, COALESCE(SUM(interest_dr - interest_cr),0) AS i, COALESCE(SUM(penalty_dr - penalty_cr),0) AS pn,
                             MAX(CASE WHEN kind IN ('payment','restructure')$notCorrected THEN txn_date END) AS lastpay
                        FROM loan_txns WHERE loan_id = :l AND is_voided = 0", ['l' => $loanId]);
        $upd =['principal_balance' => Dec::fix($t['p']), 'interest_balance' => Dec::fix($t['i']), 'penalty_balance' => Dec::fix($t['pn']), 'last_payment_date' => $t['lastpay']];
        if (in_array($loan['status'], self::OPEN_STATUSES, true)) {
            if (!Dec::isPos($t['p']) && !Dec::isPos($t['i']) && !Dec::isPos($t['pn'])) {
                $upd['status'] = 'paid';
                $upd['closed_on'] = $asOf ?? today();
                $upd['interest_balance'] = '0.00';
                Savings::refreshHolds($loanId);
                DB::run('UPDATE sav_holds SET released_at = NOW(), released_by = :u WHERE loan_account_id = :l AND released_at IS NULL', ['u' => Auth::id(), 'l' => $loanId]);
                Savings::refreshHolds($loanId);
            } elseif ($loan['status'] !== 'restructured') {
                // a restructured loan keeps that classification; the others are current or past due by the schedule
                $overdue = (int)DB::value('SELECT COUNT(*) FROM loan_schedule WHERE loan_id = :l AND is_paid = 0 AND due_date < :d', ['l' => $loanId, 'd' => $asOf ?? today()]);
                $upd['status'] = $overdue > 0 ? 'past_due' : 'current';
            }
        }
        $upd['interest_accrued'] = Dec::fix(self::accruedOutstanding($loanId));
        DB::update('loan_accounts', $upd, 'id = :id', ['id' => $loanId]);
    }

    /**
     * Interest accrued into income and not yet collected (the loan's share of Interest Receivable), replayed from the
     * ledger in order: an accrual adds to it; interest collected, waived or written off clears it first (as
     * paymentLines() credits the receivable before the income account).
     */
    public static function accruedOutstanding(int $loanId): string
    {
        $acc = '0';
        $cleared = [];
        $ec = LoanTxn::ready();
        // in the order the rows were posted: each voucher cleared what stood accrued at the time it was posted; an
        // error correction puts back what the payment it negates had cleared (its journal entry debits it back)
        foreach (DB::all("SELECT id, kind, interest_dr, interest_cr" . ($ec ? ', corrects_id' : ', NULL AS corrects_id') . " FROM loan_txns WHERE loan_id = :l AND is_voided = 0
                            AND kind IN ('accrual','payment','restructure','writeoff','waiver','rebate'" . ($ec ? ",'correction'" : '') . ") ORDER BY id", ['l' => $loanId]) as $t) {
            if ($t['kind'] === 'accrual') {
                $acc = Dec::add($acc, $t['interest_dr'], 2);
            } elseif ($t['kind'] === 'correction') {
                $acc = Dec::add($acc, $cleared[(int)$t['corrects_id']] ?? '0', 2);
            } else {
                $take = Dec::min($acc, $t['interest_cr']);
                $cleared[(int)$t['id']] = $take;
                $acc = Dec::sub($acc, $take, 2);
            }
        }
        return $acc;
    }

    /** Rebuilds the schedule's paid columns and the running balances from the ledger rows (after a void) */
    public static function rebuild(int $loanId): void
    {
        DB::run('UPDATE loan_schedule SET principal_paid = 0, interest_paid = 0, is_paid = 0, paid_on = NULL WHERE loan_id = :l', ['l' => $loanId]);
        $p = '0';
        $i = '0';
        $pn = '0';
        foreach (DB::all('SELECT * FROM loan_txns WHERE loan_id = :l ORDER BY txn_date, id', ['l' => $loanId]) as $t) {
            if ((int)$t['is_voided'] === 1) {
                continue;
            }
            $p = Dec::add(Dec::sub($p, $t['principal_cr']), $t['principal_dr'], 2);
            $i = Dec::add(Dec::sub($i, $t['interest_cr']), $t['interest_dr'], 2);
            $pn = Dec::add(Dec::sub($pn, $t['penalty_cr']), $t['penalty_dr'], 2);
            DB::run('UPDATE loan_txns SET principal_bal = :p, interest_bal = :i, penalty_bal = :pn WHERE id = :id', ['p' => Dec::fix($p), 'i' => Dec::fix($i), 'pn' => Dec::fix($pn), 'id' => $t['id']]);
            foreach (DB::all('SELECT * FROM loan_payment_alloc WHERE loan_txn_id = :t', ['t' => $t['id']]) as $al) {
                DB::run('UPDATE loan_schedule SET principal_paid = principal_paid + :p, interest_paid = interest_paid + :i WHERE id = :id', ['p' => $al['principal'], 'i' => $al['interest'], 'id' => $al['schedule_id']]);
            }
        }
        DB::run('UPDATE loan_schedule SET is_paid = IF(principal_paid >= principal AND interest_paid >= interest, 1, 0), paid_on = IF(principal_paid >= principal AND interest_paid >= interest, paid_on, NULL) WHERE loan_id = :l', ['l' => $loanId]);
        $loan = self::loan($loanId);
        if (in_array($loan['status'], ['paid', 'past_due', 'current'], true)) {
            DB::update('loan_accounts', ['status' => 'current', 'closed_on' => null], 'id = :id', ['id' => $loanId]);
        }
        self::refreshBalances($loanId);
    }

    // ------------------------------------------------------------------ adjustments, waivers, write-off, restructure

    /** A journal adjustment of the loan balances (wrong entry): Dr/Cr the receivable / income accounts against the adjustment account */
    public static function adjust(int $loanId, string $date, int $adjAccountId, array $amounts, string $memo, string $pageKey): int
    {
        return DB::tx(function () use ($loanId, $date, $adjAccountId, $amounts, $memo, $pageKey) {
            if (!Access::special('coop_waive')) {
                fail('Loan adjustments need the "can waive loan interest and penalties, and adjust loan balances" permission.');
            }
            $loan = self::loan($loanId, true);
            if (!$loan || !in_array($loan['status'], ['current', 'past_due', 'restructured'], true)) {
                fail('The loan is not open.');
            }
            Coop::checkDate($date);
            if (trim($memo) === '') {
                fail('Enter the reason for the adjustment.');
            }
            $gl = [];
            $row = ['loan_id' => $loanId, 'txn_date' => $date, 'kind' => 'adjustment', 'memo' => mb_substr($memo, 0, 160)];
            $any = false;
            foreach (['principal' => (int)$loan['receivable_account_id'], 'interest' => (int)$loan['interest_account_id'], 'penalty' => (int)($loan['penalty_account_id'] ?: Accts::get('loan_penalty_income'))] as $k => $acct) {
                $amt = Dec::round($amounts[$k]['amount'] ?? '0', 2);
                if (!Dec::isPos($amt)) {
                    continue;
                }
                $any = true;
                $inc = ($amounts[$k]['type'] ?? 'decrease') === 'increase';
                $row[$k . ($inc ? '_dr' : '_cr')] = Dec::fix($amt);
                if ($k === 'principal') {
                    $gl[] = ['account_id' => $acct, $inc ? 'debit' : 'credit' => $amt, 'memo' => $memo, 'member_id' => $loan['member_id']];
                    $gl[] = ['account_id' => $adjAccountId, $inc ? 'credit' : 'debit' => $amt, 'memo' => $memo];
                } elseif ($k === 'interest' && !$inc) {
                    // scheduled (unearned) interest reduced: no GL until it is earned; recorded in the ledger only
                } elseif ($k === 'interest' && $inc) {
                    // extra interest charged: ledger only as well (it is income when collected)
                } else {
                    // penalties are income when collected: ledger only
                }
                if (!$inc && Dec::cmp($amt, $loan[$k . '_balance']) > 0) {
                    fail('The ' . $k . ' decrease exceeds the ' . $k . ' balance (' . Dec::money($loan[$k . '_balance']) . ').');
                }
            }
            if (!$any) {
                fail('Enter at least one amount.');
            }
            $txnId = $gl ? CoopLedger::post('coop_jv', ['txn_date' => $date, 'memo' => 'Loan adjustment ' . $loan['loan_no'] . ': ' . $memo, 'branch_id' => $loan['branch_id'], 'member_id' => $loan['member_id'], 'reason' => $memo], $gl) : null;
            $row['txn_id'] = $txnId;
            $row['created_by'] = Auth::id();
            $row['principal_bal'] = $loan['principal_balance'];
            $row['interest_bal'] = $loan['interest_balance'];
            $row['penalty_bal'] = $loan['penalty_balance'];
            $ltId = DB::insert('loan_txns', $row);
            $adjId = DB::insert('loan_adjustments', ['loan_id' => $loanId, 'adj_date' => $date, 'adj_account_id' => $adjAccountId,
                'principal_amt' => Dec::fix($amounts['principal']['amount'] ?? '0'), 'principal_type' => $amounts['principal']['type'] ?? null,
                'interest_amt' => Dec::fix($amounts['interest']['amount'] ?? '0'), 'interest_type' => $amounts['interest']['type'] ?? null,
                'penalty_amt' => Dec::fix($amounts['penalty']['amount'] ?? '0'), 'penalty_type' => $amounts['penalty']['type'] ?? null,
                'memo' => mb_substr($memo, 0, 255), 'txn_id' => $txnId, 'created_by' => Auth::id()]);
            // a principal decrease settles the last instalments; an interest / penalty decrease the oldest unpaid
            self::settleFromAdjustment($loanId, $ltId, $row);
            self::rebuild($loanId);
            Audit::log('adjust', 'loan', 'loan_account', $loanId, $loan['loan_no'], ['page_key' => $pageKey, 'reason' => $memo, 'snapshot' => $amounts, 'amount' => null]);
            return $adjId;
        });
    }

    private static function settleFromAdjustment(int $loanId, int $ltId, array $row): void
    {
        $leftI = Dec::of($row['interest_cr'] ?? '0');
        $leftP = Dec::of($row['principal_cr'] ?? '0');
        $rows = self::scheduleRows($loanId);
        foreach ($rows as $s) {
            if (!Dec::isPos($leftI)) {
                break;
            }
            $uI = Dec::sub($s['interest'], $s['interest_paid'], 2);
            $take = Dec::min($leftI, $uI);
            if (Dec::isPos($take)) {
                DB::insert('loan_payment_alloc', ['loan_txn_id' => $ltId, 'schedule_id' => $s['id'], 'principal' => '0.00', 'interest' => Dec::fix($take)]);
                $leftI = Dec::sub($leftI, $take, 2);
            }
        }
        foreach (array_reverse($rows) as $s) {
            if (!Dec::isPos($leftP)) {
                break;
            }
            $uP = Dec::sub($s['principal'], $s['principal_paid'], 2);
            $take = Dec::min($leftP, $uP);
            if (Dec::isPos($take)) {
                DB::insert('loan_payment_alloc', ['loan_txn_id' => $ltId, 'schedule_id' => $s['id'], 'principal' => Dec::fix($take), 'interest' => '0.00']);
                $leftP = Dec::sub($leftP, $take, 2);
            }
        }
    }

    /** True once waivers and loan applications keep the class chosen for them (upgrade 2026_12_20) */
    public static function classColumns(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(w.class_id) + COUNT(a.class_id) FROM loan_waivers w, loan_applications a WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    public static function requestWaiver(int $loanId, string $date, string $interest, string $penalty, string $memo, string $pageKey, ?int $classId = null): int
    {
        $loan = self::loan($loanId);
        if (!$loan || !in_array($loan['status'], ['current', 'past_due', 'restructured'], true)) {
            fail('The loan is not open.');
        }
        $interest = Dec::round($interest, 2);
        $penalty = Dec::round($penalty, 2);
        if (!Dec::isPos($interest) && !Dec::isPos($penalty)) {
            fail('Enter the interest or penalty to waive.');
        }
        if (Dec::cmp($interest, $loan['interest_balance']) > 0 || Dec::cmp($penalty, $loan['penalty_balance']) > 0) {
            fail('The waiver exceeds the balance (interest ' . Dec::money($loan['interest_balance']) . ', penalty ' . Dec::money($loan['penalty_balance']) . ').');
        }
        if (trim($memo) === '') {
            fail('Enter the reason for the waiver.');
        }
        $id = DB::insert('loan_waivers', ['loan_id' => $loanId, 'waive_date' => $date, 'interest_amount' => Dec::fix($interest), 'penalty_amount' => Dec::fix($penalty), 'memo' => mb_substr($memo, 0, 255), 'status' => 'pending', 'requested_by' => Auth::id()]
            + ($classId && self::classColumns() ? ['class_id' => $classId] : []));
        Audit::log('request', 'loan', 'loan_waiver', $id, $loan['loan_no'], ['page_key' => $pageKey, 'reason' => $memo, 'amount' => (float)Dec::add($interest, $penalty)]);
        return $id;
    }

    /** Approves and posts a waiver: the interest / penalty balances fall (ledger only: nothing was in the GL yet) */
    public static function approveWaiver(int $waiverId, bool $approve, string $remarks, string $pageKey): void
    {
        DB::tx(function () use ($waiverId, $approve, $remarks, $pageKey) {
            $w = DB::row('SELECT * FROM loan_waivers WHERE id = :id FOR UPDATE', ['id' => $waiverId]);
            if (!$w || $w['status'] !== 'pending') {
                fail('The waiver is not pending.');
            }
            if (!Access::can($pageKey, 'approve') || !Access::special('coop_waive')) {
                fail('Approving waivers needs the Approve right on this page and the waiver permission.');
            }
            if ((int)$w['requested_by'] === Auth::id() && !Access::isAdmin()) {
                fail('A waiver is approved by someone other than the user who requested it.');
            }
            if (!$approve) {
                DB::update('loan_waivers', ['status' => 'rejected', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s'), 'memo' => mb_substr($w['memo'] . ' | Rejected: ' . $remarks, 0, 255)], 'id = :id', ['id' => $waiverId]);
                Audit::log('reject', 'loan', 'loan_waiver', $waiverId, 'Waiver ' . $waiverId, ['page_key' => $pageKey, 'reason' => $remarks]);
                return;
            }
            $loan = self::loan((int)$w['loan_id'], true);
            if (Dec::cmp($w['interest_amount'], $loan['interest_balance']) > 0 || Dec::cmp($w['penalty_amount'], $loan['penalty_balance']) > 0) {
                fail('The balances have changed since the request; the waiver now exceeds them.');
            }
            // accrued interest already in income has to come out of income when waived
            $gl = [];
            $accruedWaived = Dec::min($w['interest_amount'], $loan['interest_accrued']);
            if (Dec::isPos($accruedWaived)) {
                $gl[] = ['account_id' => (int)$loan['interest_account_id'], 'debit' => $accruedWaived, 'memo' => 'Waived accrued interest ' . $loan['loan_no'], 'member_id' => $loan['member_id']];
                $gl[] = ['account_id' => Accts::get('loan_interest_receivable'), 'credit' => $accruedWaived, 'memo' => 'Waived accrued interest ' . $loan['loan_no'], 'member_id' => $loan['member_id']];
            }
            // the class chosen with the request (or by the approver) goes on the voucher and every line
            $classId = CoopLedger::currentClass() ?? (!empty($w['class_id']) ? (int)$w['class_id'] : null);
            if ($classId && empty($w['class_id']) && self::classColumns()) {
                DB::update('loan_waivers', ['class_id' => $classId], 'id = :id', ['id' => $waiverId]);
            }
            $txnId = $gl ? CoopLedger::withClass($classId, fn() => CoopLedger::post('coop_jv', ['txn_date' => $w['waive_date'], 'memo' => 'Interest waiver ' . $loan['loan_no'], 'branch_id' => $loan['branch_id'], 'member_id' => $loan['member_id']], $gl)) : null;
            $ltId = DB::insert('loan_txns', ['loan_id' => $loan['id'], 'txn_date' => $w['waive_date'], 'kind' => 'waiver', 'memo' => 'Waiver: ' . $w['memo'], 'interest_cr' => $w['interest_amount'], 'penalty_cr' => $w['penalty_amount'],
                'principal_bal' => $loan['principal_balance'], 'interest_bal' => Dec::fix(Dec::sub($loan['interest_balance'], $w['interest_amount'])), 'penalty_bal' => Dec::fix(Dec::sub($loan['penalty_balance'], $w['penalty_amount'])),
                'txn_id' => $txnId, 'created_by' => Auth::id()]);
            self::settleFromAdjustment((int)$loan['id'], $ltId, ['interest_cr' => $w['interest_amount']]);
            DB::update('loan_accounts', ['interest_waived' => Dec::fix(Dec::add($loan['interest_waived'], $w['interest_amount'])), 'penalty_waived' => Dec::fix(Dec::add($loan['penalty_waived'], $w['penalty_amount']))], 'id = :id', ['id' => $loan['id']]);
            DB::update('loan_waivers', ['status' => 'posted', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s'), 'txn_id' => $txnId], 'id = :id', ['id' => $waiverId]);
            self::rebuild((int)$loan['id']);
            Audit::log('approve', 'loan', 'loan_waiver', $waiverId, $loan['loan_no'], ['page_key' => $pageKey, 'reason' => $remarks, 'amount' => (float)Dec::add($w['interest_amount'], $w['penalty_amount'])]);
        });
    }

    /** Writes a loan off: Dr allowance (or provision) / Cr loans receivable for the principal; interest and penalties are dropped from the ledger */
    public static function writeOff(int $loanId, string $date, string $reason, bool $useAllowance, string $pageKey): int
    {
        return DB::tx(function () use ($loanId, $date, $reason, $useAllowance, $pageKey) {
            if (!Access::can($pageKey, 'approve')) {
                fail('Writing off a loan needs the Approve right on this page.');
            }
            $loan = self::loan($loanId, true);
            if (!$loan || !in_array($loan['status'], ['current', 'past_due', 'restructured'], true)) {
                fail('The loan is not open.');
            }
            Coop::checkDate($date);
            if (trim($reason) === '') {
                fail('Enter the board resolution / reason.');
            }
            $gl = [];
            if (Dec::isPos($loan['principal_balance'])) {
                $gl[] = ['account_id' => Accts::get($useAllowance ? 'loan_loss_allowance' : 'loan_loss_provision'), 'debit' => $loan['principal_balance'], 'memo' => 'Write-off ' . $loan['loan_no']];
                $gl[] = ['account_id' => (int)$loan['receivable_account_id'], 'credit' => $loan['principal_balance'], 'memo' => 'Write-off ' . $loan['loan_no'], 'member_id' => $loan['member_id']];
            }
            if (Dec::isPos($loan['interest_accrued'])) {
                $gl[] = ['account_id' => (int)$loan['interest_account_id'], 'debit' => $loan['interest_accrued'], 'memo' => 'Write-off accrued interest ' . $loan['loan_no']];
                $gl[] = ['account_id' => Accts::get('loan_interest_receivable'), 'credit' => $loan['interest_accrued'], 'memo' => 'Write-off accrued interest ' . $loan['loan_no'], 'member_id' => $loan['member_id']];
            }
            $txnId = $gl ? CoopLedger::post('coop_jv', ['txn_date' => $date, 'memo' => 'Loan write-off ' . $loan['loan_no'] . ': ' . $reason, 'branch_id' => $loan['branch_id'], 'member_id' => $loan['member_id'], 'reason' => $reason], $gl) : null;
            DB::insert('loan_txns', ['loan_id' => $loanId, 'txn_date' => $date, 'kind' => 'writeoff', 'memo' => 'Written off: ' . mb_substr($reason, 0, 140),
                'principal_cr' => $loan['principal_balance'], 'interest_cr' => $loan['interest_balance'], 'penalty_cr' => $loan['penalty_balance'],
                'principal_bal' => '0.00', 'interest_bal' => '0.00', 'penalty_bal' => '0.00', 'txn_id' => $txnId, 'created_by' => Auth::id()]);
            DB::update('loan_accounts', ['status' => 'written_off', 'written_off_on' => $date, 'written_off_txn_id' => $txnId, 'closed_on' => $date,
                'principal_balance' => '0.00', 'interest_balance' => '0.00', 'penalty_balance' => '0.00', 'interest_accrued' => '0.00', 'notes' => mb_substr($reason, 0, 255)], 'id = :id', ['id' => $loanId]);
            Audit::log('writeoff', 'loan', 'loan_account', $loanId, $loan['loan_no'], ['page_key' => $pageKey, 'reason' => $reason, 'amount' => (float)$loan['principal_balance']]);
            return (int)$txnId;
        });
    }

    /** Money collected on a written-off loan: Dr cash, Cr recovery income (or the allowance) */
    public static function recover(int $loanId, string $date, string $amount, int $cashAccountId, array $opt, string $pageKey): int
    {
        return DB::tx(function () use ($loanId, $date, $amount, $cashAccountId, $opt, $pageKey) {
            $loan = self::loan($loanId, true);
            if (!$loan || $loan['status'] !== 'written_off') {
                fail('Recoveries are recorded on written-off loans only.');
            }
            $amount = Dec::round($amount, 2);
            if (!Dec::isPos($amount)) {
                fail('Enter the amount recovered.');
            }
            $memo = 'Recovery on written-off loan ' . $loan['loan_no'];
            $txnId = CoopLedger::post('coop_or', ['txn_date' => $date, 'memo' => $memo, 'branch_id' => $loan['branch_id'], 'member_id' => $loan['member_id'], 'account_id' => $cashAccountId, 'txn_no' => $opt['or_no'] ?? null], [
                ['account_id' => $cashAccountId, 'debit' => $amount, 'memo' => $memo],
                ['account_id' => Accts::get('loan_recovery_income'), 'credit' => $amount, 'memo' => $memo, 'member_id' => $loan['member_id']],
            ]);
            DB::insert('loan_txns', ['loan_id' => $loanId, 'txn_date' => $date, 'kind' => 'recovery', 'memo' => $memo, 'principal_bal' => '0.00', 'interest_bal' => '0.00', 'penalty_bal' => '0.00',
                'txn_id' => $txnId, 'or_no' => $opt['or_no'] ?? null, 'teller_id' => Auth::id(), 'shift_id' => $opt['shift_id'] ?? null, 'created_by' => Auth::id()]);
            DB::update('loan_accounts', ['recovered_amount' => Dec::fix(Dec::add($loan['recovered_amount'], $amount))], 'id = :id', ['id' => $loanId]);
            if (!empty($opt['shift_id'])) {
                Teller::recordReceipt($txnId, 'or', $opt['or_no'] ?? Txn::load($txnId)['txn_no'], (int)$loan['member_id'], (int)$loan['branch_id'], (int)$opt['shift_id'], $opt['payment_mode'] ?? 'cash', $cashAccountId, $amount,
                    [['kind' => 'other', 'ref_id' => $loanId, 'account_id' => Accts::get('loan_recovery_income'), 'description' => $memo, 'amount' => $amount]]);
            }
            Audit::log('recovery', 'loan', 'loan_account', $loanId, $loan['loan_no'], ['page_key' => $pageKey, 'amount' => (float)$amount]);
            return $txnId;
        });
    }

    /** Monthly accrual of interest income on diminishing loans: Dr interest receivable, Cr interest income for the interest earned to date and not yet collected */
    public static function accrueInterest(string $through, string $postingDate, string $pageKey, bool $post = true): array
    {
        if (!valid_date($through) || !valid_date($postingDate)) {
            fail('Enter the accrual date and the posting date.');
        }
        $out = ['count' => 0, 'amount' => '0', 'txn_id' => null, 'lines' => []];
        $gl = [];
        $rows = [];
        // products set to "income when collected" (Accrue interest monthly: No) are left out
        foreach (DB::all("SELECT l.* FROM loan_accounts l JOIN loan_products p ON p.id = l.product_id WHERE l.status IN ('current','past_due','restructured') AND l.interest_method = 'diminishing'
                           AND p.interest_posting <> 'none' AND l.release_date < :d", ['d' => $through]) as $loan) {
            $d = self::dues($loan, $through);
            // earned to date = scheduled interest of the instalments due + interest to date on the current period, less interest already collected or accrued
            // interest that left the scheduled balance other than by an accrual: collected, waived, adjusted down
            $collected = Dec::of(DB::value("SELECT COALESCE(SUM(interest_cr),0) - COALESCE(SUM(CASE WHEN kind = 'adjustment' THEN interest_dr ELSE 0 END),0)
                                              FROM loan_txns WHERE loan_id = :l AND is_voided = 0 AND kind IN ('payment','restructure','waiver','adjustment')", ['l' => $loan['id']]));
            $earnedSched = Dec::of(DB::value('SELECT COALESCE(SUM(interest),0) FROM loan_schedule WHERE loan_id = :l AND due_date <= :d', ['l' => $loan['id'], 'd' => $through]));
            $earned = Dec::add($earnedSched, $d['interest_to_date'], 2);
            // what should stand in the receivable (earned, not collected) less what already stands there
            $outstanding = self::accruedOutstanding((int)$loan['id']);
            $delta = Dec::sub(Dec::sub($earned, $collected, 2), $outstanding, 2);
            if (Dec::cmp($delta, '0.01') < 0) {
                continue;
            }
            $gl[] = ['account_id' => Accts::get('loan_interest_receivable'), 'debit' => $delta, 'memo' => 'Accrued interest ' . $loan['loan_no'], 'member_id' => $loan['member_id'], 'class_id' => Coop::branchClassId($loan['branch_id'] ? (int)$loan['branch_id'] : null)];
            $gl[] = ['account_id' => (int)$loan['interest_account_id'], 'credit' => $delta, 'memo' => 'Accrued interest ' . $loan['loan_no'], 'member_id' => $loan['member_id'], 'class_id' => Coop::branchClassId($loan['branch_id'] ? (int)$loan['branch_id'] : null)];
            $rows[] = [$loan, $delta];
            $out['lines'][] = ['loan_no' => $loan['loan_no'], 'member_id' => $loan['member_id'], 'amount' => $delta];
            $out['amount'] = Dec::add($out['amount'], $delta, 2);
        }
        if (!$rows || !$post) {
            $out['count'] = count($rows);
            return $out;
        }
        return DB::tx(function () use ($gl, $rows, $through, $postingDate, $out, $pageKey) {
            $txnId = CoopLedger::post('coop_jv', ['txn_date' => $postingDate, 'memo' => 'Loan interest accrual through ' . fdate($through)], $gl);
            foreach ($rows as [$loan, $delta]) {
                // an accrual does not change the member's scheduled interest balance: it is recorded as earned (dr) and its own offset is the receivable
                DB::insert('loan_txns', ['loan_id' => $loan['id'], 'txn_date' => $postingDate, 'kind' => 'accrual', 'memo' => 'Interest accrued through ' . fdate($through), 'interest_dr' => Dec::fix($delta), 'interest_cr' => Dec::fix($delta),
                    'principal_bal' => $loan['principal_balance'], 'interest_bal' => $loan['interest_balance'], 'penalty_bal' => $loan['penalty_balance'], 'txn_id' => $txnId, 'created_by' => Auth::id()]);
                self::refreshBalances((int)$loan['id'], $postingDate);
            }
            $out['count'] = count($rows);
            $out['txn_id'] = $txnId;
            Audit::log('post', 'loan', 'interest_accrual', null, 'Loan interest accrual through ' . fdate($through), ['page_key' => $pageKey, 'amount' => (float)$out['amount']]);
            return $out;
        });
    }

    /** Posts the penalties earned through a date on every open loan (batch) */
    public static function postPenalties(string $through, string $pageKey, ?int $classId = null): array
    {
        $n = 0;
        $total = '0';
        foreach (DB::all("SELECT id FROM loan_accounts WHERE status IN ('current','past_due','restructured') AND penalty_rate > 0") as $r) {
            DB::tx(function () use ($r, $through, &$n, &$total) {
                $loan = self::loan((int)$r['id'], true);
                $new = self::chargePenalty($loan, $through);
                if (Dec::isPos($new)) {
                    $n++;
                    $total = Dec::add($total, $new, 2);
                }
                self::refreshBalances((int)$loan['id'], $through);
            });
        }
        // penalties reach the general ledger when collected (under the class of that receipt); the run keeps its class here
        Audit::log('post', 'loan', 'penalties', null, 'Penalties through ' . fdate($through), ['page_key' => $pageKey, 'amount' => (float)$total,
            'reason' => $n . ' loan(s)' . ($classId ? ', class ' . Classes::name($classId) : '')]);
        return ['count' => $n, 'amount' => $total];
    }

    /** Refreshes current / past_due on every open loan (run daily by the dashboard, cheap) */
    public static function refreshStatuses(): void
    {
        DB::run("UPDATE loan_accounts l SET l.status = 'past_due' WHERE l.status = 'current' AND EXISTS (SELECT 1 FROM loan_schedule s WHERE s.loan_id = l.id AND s.is_paid = 0 AND s.due_date < CURDATE())");
        DB::run("UPDATE loan_accounts l SET l.status = 'current' WHERE l.status = 'past_due' AND NOT EXISTS (SELECT 1 FROM loan_schedule s WHERE s.loan_id = l.id AND s.is_paid = 0 AND s.due_date < CURDATE())");
    }

    // ------------------------------------------------------------------ truth in lending

    /** Amortization periods in a year for a mode of payment (lump sum: one period over the term) */
    public static function periodsPerYear(string $mode, string $term = '12', string $termUnit = 'months'): float
    {
        return match ($mode) {
            'daily' => 365.0, 'weekly' => 52.0, 'semi_monthly' => 24.0, 'monthly' => 12.0, 'quarterly' => 4.0, 'semi_annual' => 2.0, 'annual' => 1.0,
            default => $termUnit === 'days' ? 365.0 / max(1.0, (float)$term) : 12.0 / max(1.0, (float)$term),
        };
    }

    /**
     * Effective interest rate for the disclosure statement (R.A. 3765, BSP Circular 730): the rate per period that
     * discounts the amortizations back to the net proceeds actually received, and that rate annualized
     * (per period x periods a year). A rate is a disclosure figure, so it is solved numerically; money stays decimal.
     * Returns ['period' => '1.6543', 'annual' => '19.85'] in per cent, or null when it cannot be solved.
     */
    public static function eir(string $netProceeds, array $payments, float $perYear): ?array
    {
        $net = (float)$netProceeds;
        $pay = array_map('floatval', array_values($payments));
        if ($net <= 0 || !$pay || array_sum($pay) <= $net) {
            return null;
        }
        $pv = function (float $r) use ($pay): float {
            $v = 0.0;
            foreach ($pay as $k => $amt) {
                $v += $amt / (1 + $r) ** ($k + 1);
            }
            return $v;
        };
        $lo = 0.0;
        $hi = 1.0;
        while ($pv($hi) > $net && $hi < 1000) {
            $hi *= 2;
        }
        for ($i = 0; $i < 200; $i++) {
            $mid = ($lo + $hi) / 2;
            if ($pv($mid) > $net) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        $r = ($lo + $hi) / 2;
        return ['period' => number_format($r * 100, 4, '.', ''), 'annual' => number_format($r * 100 * $perYear, 2, '.', '')];
    }

    // ------------------------------------------------------------------ aging (the three kinds of the reference)

    /** Age bucket label of a number of days past due */
    public static function bucket(int $days): string
    {
        if ($days <= 0) {
            return 'current';
        }
        if ($days <= 30) {
            return '1-30';
        }
        if ($days <= 90) {
            return '31-90';
        }
        if ($days <= 180) {
            return '91-180';
        }
        if ($days <= 365) {
            return '181-365';
        }
        return '>365';
    }

    public const BUCKETS = ['current' => 'Non-delinquent', '1-30' => '1 to 30 days', '31-90' => '1 to 3 months', '91-180' => '3 to 6 months', '181-365' => '6 to 12 months', '>365' => 'Over 12 months'];

    /**
     * Aging of one loan as of a date, three ways:
     *  maturity     : the whole balance ages only once the maturity date has passed
     *  par          : one missed (or short) amortization makes the whole balance delinquent, aged by the oldest unpaid due date
     *  amortization : only the unpaid amortizations are delinquent (each aged from its own due date); the rest is current
     * Returns ['bucket' => amounts by bucket, 'days' => days past due, 'delinquent' => amount, 'current' => amount]
     */
    public static function aging(array $loan, string $asOf, string $kind): array
    {
        $balance = Dec::of($loan['principal_balance']);
        $buckets = array_fill_keys(array_keys(self::BUCKETS), '0');
        // overdue = an unpaid amortization whose due date has passed (an amortization due today is not yet late)
        $rows = DB::all('SELECT * FROM loan_schedule WHERE loan_id = :l AND due_date < :d AND is_paid = 0 ORDER BY due_date', ['l' => $loan['id'], 'd' => $asOf]);
        $days = 0;
        if ($kind === 'maturity') {
            $days = max(0, Coop::daysBetween($loan['due_date'], $asOf));
            $buckets[self::bucket($days)] = $balance;
            return ['bucket' => $buckets, 'days' => $days, 'delinquent' => $days > 0 ? $balance : '0', 'current' => $days > 0 ? '0' : $balance];
        }
        if ($kind === 'par') {
            if ($rows) {
                $days = max(0, Coop::daysBetween($rows[0]['due_date'], $asOf));
                $buckets[self::bucket($days)] = $balance;
                return ['bucket' => $buckets, 'days' => $days, 'delinquent' => $balance, 'current' => '0'];
            }
            $buckets['current'] = $balance;
            return ['bucket' => $buckets, 'days' => 0, 'delinquent' => '0', 'current' => $balance];
        }
        // by amortization: only the unpaid principal of each overdue instalment is delinquent
        $del = '0';
        foreach ($rows as $s) {
            $u = Dec::sub($s['principal'], $s['principal_paid'], 2);
            if (!Dec::isPos($u)) {
                continue;
            }
            $d = max(0, Coop::daysBetween($s['due_date'], $asOf));
            $days = max($days, $d);
            $buckets[self::bucket($d)] = Dec::add($buckets[self::bucket($d)], $u, 2);
            $del = Dec::add($del, $u, 2);
        }
        $cur = Dec::sub($balance, $del, 2);
        $buckets['current'] = Dec::add($buckets['current'], $cur, 2);
        return ['bucket' => $buckets, 'days' => $days, 'delinquent' => $del, 'current' => $cur];
    }
}
