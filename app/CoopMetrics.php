<?php
declare(strict_types=1);

/**
 * Figures of the cooperative for a period, by name, for the regulatory reports (CAPR and the other CDA forms) and the
 * dashboard. A report line is mapped (cda_report_mappings.source) to one of:
 *   metric:<name>          a figure below
 *   account:<codes>        GL balance at the end of the period: codes separated by commas, a trailing * for every
 *                          code that starts with it (e.g. account:1210,1211 or account:4*); balance-sheet accounts give the
 *                          balance at the end, income and expense accounts the activity of the period; shown in their
 *                          normal direction (credit accounts positive)
 *   text:<value>           a fixed text (e.g. the registration number)
 *   setting:<key>          a cooperative setting (Cooperative Profile & Policies)
 * The mapping is data, so when the CDA changes the form the lines are edited, not the code.
 */
final class CoopMetrics
{
    public const METRICS = [
        'members_total'        => 'Members (active and suspended) at the end of the period',
        'members_regular'      => 'Regular members',
        'members_associate'    => 'Associate members',
        'members_male'         => 'Members, male',
        'members_female'       => 'Members, female',
        'members_new'          => 'Members admitted in the period',
        'members_separated'    => 'Members who resigned, were terminated or died in the period',
        'officers_count'       => 'Officers and committee members (active)',
        'total_assets'         => 'Total assets',
        'total_liabilities'    => 'Total liabilities',
        'total_equity'         => 'Total equity (assets less liabilities)',
        'paid_up_capital'      => 'Paid-up share capital (member ledgers)',
        'subscribed_capital'   => 'Subscribed share capital',
        'savings_deposits'     => 'Savings deposits (member ledgers)',
        'time_deposits'        => 'Time deposits (member ledgers)',
        'loans_outstanding'    => 'Loans receivable, outstanding principal',
        'loans_past_due'       => 'Loans receivable, past due (balance of loans with a missed amortization)',
        'par_ratio'            => 'Portfolio at risk, % of the loans outstanding',
        'loans_released_count' => 'Loans released in the period, number',
        'loans_released_amount'=> 'Loans released in the period, amount',
        'loans_borrowers'      => 'Members with an open loan',
        'gross_revenue'        => 'Gross revenue (income accounts) of the period',
        'total_expenses'       => 'Total expenses of the period',
        'net_surplus'          => 'Net surplus of the period',
        'reserve_fund'         => 'Reserve fund balance',
        'education_fund'       => 'Education and training fund balance',
        'cdf_fund'             => 'Community development fund balance',
        'optional_fund'        => 'Optional fund balance',
        'isc_distributed'      => 'Interest on share capital posted in the period',
        'pr_distributed'       => 'Patronage refund posted in the period',
    ];

    /** The value of a source for the period (money as a decimal string, counts as integers, text as is) */
    public static function value(string $source, string $from, string $to): string
    {
        $source = trim($source);
        if (str_starts_with($source, 'text:')) {
            return substr($source, 5);
        }
        if (str_starts_with($source, 'setting:')) {
            return Coop::setting(substr($source, 8));
        }
        if (str_starts_with($source, 'account:')) {
            return self::accounts(substr($source, 8), $from, $to);
        }
        if (str_starts_with($source, 'metric:')) {
            return self::metric(substr($source, 7), $from, $to);
        }
        throw new UserError('Unknown source "' . $source . '": use metric:, account:, text: or setting:.');
    }

    /** Sum of the balances of a list of account codes, in their normal direction */
    public static function accounts(string $codes, string $from, string $to): string
    {
        $total = '0';
        foreach (array_filter(array_map('trim', explode(',', $codes))) as $c) {
            if (str_ends_with($c, '*')) {
                $rows = DB::all('SELECT id, category, normal_balance FROM accounts WHERE code LIKE :c', ['c' => rtrim($c, '*') . '%']);
            } else {
                $rows = DB::all('SELECT id, category, normal_balance FROM accounts WHERE code = :c', ['c' => $c]);
            }
            foreach ($rows as $a) {
                $flow = in_array($a['category'], ['Income', 'Expense'], true);
                $v = Dec::of(DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND txn_date <= :t' . ($flow ? ' AND txn_date >= :f' : ''),
                    ['a' => $a['id'], 't' => $to] + ($flow ? ['f' => $from] : [])));
                $total = Dec::add($total, $a['normal_balance'] === 'Credit' ? Dec::neg($v) : $v, 2);
            }
        }
        return Dec::fix($total);
    }

    private static function category(string $cat, string $to, ?string $from = null): string
    {
        $v = Dec::of(DB::value('SELECT COALESCE(SUM(j.debit - j.credit),0) FROM journal_lines j JOIN accounts a ON a.id = j.account_id WHERE a.category = :c AND j.txn_date <= :t' . ($from ? ' AND j.txn_date >= :f' : ''),
            ['c' => $cat, 't' => $to] + ($from ? ['f' => $from] : [])));
        return in_array($cat, ['Asset', 'Expense'], true) ? Dec::fix($v) : Dec::fix(Dec::neg($v));
    }

    private static function savTotal(array $types, string $to): string
    {
        [$in, $ip] = DB::in('pt', $types);
        return Dec::fix(DB::value("SELECT COALESCE(SUM(t.credit - t.debit),0) FROM sav_txns t JOIN sav_accounts a ON a.id = t.account_id JOIN sav_products p ON p.id = a.product_id
                                    WHERE p.product_type IN $in AND t.is_voided = 0 AND t.txn_date <= :d", $ip + ['d' => $to]));
    }

    public static function metric(string $name, string $from, string $to): string
    {
        $activeAt = "m.membership_date IS NOT NULL AND m.membership_date <= :t AND (m.status IN ('active','suspended') OR (m.status_date IS NOT NULL AND m.status_date > :t2))";
        $p = ['t' => $to, 't2' => $to];
        switch ($name) {
            case 'members_total':
                return (string)DB::value("SELECT COUNT(*) FROM members m WHERE $activeAt", $p);
            case 'members_regular':
            case 'members_associate':
                return (string)DB::value("SELECT COUNT(*) FROM members m LEFT JOIN member_types t ON t.id = m.member_type_id WHERE $activeAt AND COALESCE(t.is_regular, 1) = :r", $p + ['r' => $name === 'members_regular' ? 1 : 0]);
            case 'members_male':
            case 'members_female':
                return (string)DB::value("SELECT COUNT(*) FROM members m WHERE $activeAt AND LOWER(m.gender) LIKE :g", $p + ['g' => $name === 'members_male' ? 'm%' : 'f%']);
            case 'members_new':
                return (string)DB::value('SELECT COUNT(*) FROM members m WHERE m.membership_date BETWEEN :f AND :t', ['f' => $from, 't' => $to]);
            case 'members_separated':
                return (string)DB::value("SELECT COUNT(DISTINCT h.member_id) FROM member_status_history h WHERE h.new_status IN ('resigned','terminated','deceased') AND h.changed_on BETWEEN :f AND :t", ['f' => $from, 't' => $to]);
            case 'officers_count':
                return (string)DB::value('SELECT COUNT(*) FROM coop_officers WHERE is_active = 1 AND (term_end IS NULL OR term_end >= :t)', ['t' => $to]);
            case 'total_assets':
                return self::category('Asset', $to);
            case 'total_liabilities':
                return self::category('Liability', $to);
            case 'total_equity':
                return Dec::fix(Dec::sub(self::category('Asset', $to), self::category('Liability', $to), 2));
            case 'paid_up_capital':
                return self::savTotal(['share_capital'], $to);
            case 'subscribed_capital':
                return Dec::fix(DB::value("SELECT COALESCE(SUM(m.subscribed_capital),0) FROM members m WHERE $activeAt", $p));
            case 'savings_deposits':
                return self::savTotal(['ordinary', 'special'], $to);
            case 'time_deposits':
                return self::savTotal(['time_deposit'], $to);
            case 'loans_outstanding':
                return Dec::fix(DB::value('SELECT COALESCE(SUM(x.principal_dr - x.principal_cr),0) FROM loan_txns x WHERE x.is_voided = 0 AND x.txn_date <= :d', ['d' => $to]));
            case 'loans_past_due':
                return Dec::fix(self::pastDue($to));
            case 'par_ratio':
                $out = Dec::of(self::metric('loans_outstanding', $from, $to));
                return Dec::isPos($out) ? Dec::fix(Dec::div(Dec::mul(self::pastDue($to), '100', 6), $out, 6), 2) : '0.00';
            case 'loans_released_count':
                return (string)DB::value('SELECT COUNT(*) FROM loan_accounts l JOIN transactions t ON t.id = l.release_txn_id WHERE t.is_voided = 0 AND l.release_date BETWEEN :f AND :t', ['f' => $from, 't' => $to]);
            case 'loans_released_amount':
                return Dec::fix(DB::value('SELECT COALESCE(SUM(l.principal),0) FROM loan_accounts l JOIN transactions t ON t.id = l.release_txn_id WHERE t.is_voided = 0 AND l.release_date BETWEEN :f AND :t', ['f' => $from, 't' => $to]));
            case 'loans_borrowers':
                return (string)DB::value("SELECT COUNT(DISTINCT member_id) FROM loan_accounts WHERE status IN ('current','past_due','restructured')");
            case 'gross_revenue':
                return self::category('Income', $to, $from);
            case 'total_expenses':
                return self::category('Expense', $to, $from);
            case 'net_surplus':
                return Dec::fix(Dec::sub(self::category('Income', $to, $from), self::category('Expense', $to, $from), 2));
            case 'reserve_fund':
            case 'education_fund':
            case 'cdf_fund':
            case 'optional_fund':
                $code = (string)DB::value('SELECT code FROM accounts WHERE id = :a', ['a' => Accts::get($name)]);
                return self::accounts($code, $from, $to);
            case 'isc_distributed':
            case 'pr_distributed':
                return Dec::fix(DB::value("SELECT COALESCE(SUM(b.total_amount),0) FROM dist_batches b JOIN transactions t ON t.id = b.txn_id WHERE b.status = 'posted' AND b.kind = :k AND t.is_voided = 0 AND t.txn_date BETWEEN :f AND :t",
                    ['k' => $name === 'isc_distributed' ? 'isc' : 'pr', 'f' => $from, 't' => $to]));
            default:
                throw new UserError('Unknown figure "' . $name . '".');
        }
    }

    /** Principal outstanding at a date of the loans with an amortization unpaid at that date (balance at risk) */
    private static function pastDue(string $to): string
    {
        $sum = '0';
        foreach (DB::all("SELECT l.id FROM loan_accounts l WHERE l.release_date <= :d AND (l.closed_on IS NULL OR l.closed_on > :d2) AND l.status <> 'cancelled'", ['d' => $to, 'd2' => $to]) as $l) {
            $late = DB::value('SELECT 1 FROM loan_schedule s WHERE s.loan_id = :l AND s.due_date < :d AND (s.is_paid = 0 OR s.paid_on > :d2) LIMIT 1', ['l' => $l['id'], 'd' => $to, 'd2' => $to]);
            if ($late) {
                $sum = Dec::add($sum, DB::value('SELECT COALESCE(SUM(principal_dr - principal_cr),0) FROM loan_txns WHERE loan_id = :l AND is_voided = 0 AND txn_date <= :d', ['l' => $l['id'], 'd' => $to]), 2);
            }
        }
        return $sum;
    }

    /** The lines of a mapped report with their values: [section, field_code, label, source, value, error] */
    public static function report(string $report, string $from, string $to): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM cda_report_mappings WHERE report = :r ORDER BY sort_order, id', ['r' => $report]) as $m) {
            $err = null;
            try {
                $v = self::value($m['source'], $from, $to);
            } catch (Throwable $e) {
                $v = '';
                $err = $e instanceof UserError ? $e->getMessage() : 'Cannot compute this line.';
            }
            $out[] = $m + ['value' => $v, 'error' => $err];
        }
        return $out;
    }
}
