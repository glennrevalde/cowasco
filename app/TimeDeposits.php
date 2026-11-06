<?php
declare(strict_types=1);

/**
 * Time deposits: placement with a certificate, maturity value, monthly accrual of the interest expense,
 * settlement at maturity (pay out, credit to savings, renew), and pre-termination at the reduced rate.
 *
 * A placement lives on a savings account of a time-deposit product (one account per placement, so the
 * subsidiary ledger shows the principal), with the certificate details in td_placements.
 */
final class TimeDeposits
{
    public const ACTIONS = ['payout' => 'Pay out principal and interest', 'renew_principal' => 'Renew the principal, pay out the interest',
        'renew_all' => 'Renew principal plus interest', 'credit_savings' => 'Credit principal and interest to a savings account'];

    public static function placement(int $id, bool $lock = false): ?array
    {
        return DB::row('SELECT t.*, a.account_no, a.member_id, a.branch_id, a.product_id, a.status AS account_status, m.full_name AS member_name, m.member_no, p.name AS product_name
                          FROM td_placements t JOIN sav_accounts a ON a.id = t.account_id JOIN members m ON m.id = a.member_id JOIN sav_products p ON p.id = a.product_id
                         WHERE t.id = :id' . ($lock ? ' FOR UPDATE' : ''), ['id' => $id]);
    }

    /** Term of a product in days */
    public static function termDays(array $product, ?string $from = null): int
    {
        $v = (int)$product['maturity_value'];
        return match ($product['maturity_unit']) {
            'months' => Coop::daysBetween($from ?? today(), Coop::addMonths($from ?? today(), $v)),
            'years' => Coop::daysBetween($from ?? today(), Coop::addMonths($from ?? today(), 12 * $v)),
            default => $v,
        };
    }

    /**
     * Interest and maturity value for a principal over $days at $rate % p.a.
     *  simple:   P x r x d / calendar
     *  compound: P x (1 + r/(calendar/period))^(d/period) - P, with the compounding period in days (30 monthly, 90 quarterly)
     */
    public static function compute(string $principal, string $rate, int $days, string $method = 'simple', string $compounding = 'maturity', int $calendar = 365): array
    {
        $principal = Dec::round($principal, 2);
        if ($method === 'compound' && $compounding !== 'maturity' && $days > 0) {
            $period = $compounding === 'monthly' ? 30 : 90;
            $n = intdiv($days, $period);
            $rem = $days % $period;
            $periodRate = Dec::div(Dec::mul($rate, (string)$period, 10), Dec::mul('100', (string)$calendar, 4), 12);
            $value = $principal;
            for ($i = 0; $i < $n; $i++) {
                $value = Dec::round(Dec::add($value, Dec::mul($value, $periodRate, 10)), 2);
            }
            if ($rem > 0) {
                $value = Dec::round(Dec::add($value, Dec::div(Dec::mul(Dec::mul($value, $rate, 10), (string)$rem, 10), Dec::mul('100', (string)$calendar, 4), 10)), 2);
            }
            $interest = Dec::sub($value, $principal, 2);
        } else {
            $interest = Dec::round(Dec::div(Dec::mul(Dec::mul($principal, $rate, 10), (string)$days, 10), Dec::mul('100', (string)$calendar, 4), 10), 2);
            $value = Dec::add($principal, $interest, 2);
        }
        return ['interest' => $interest, 'maturity_value' => $value];
    }

    /** Interest earned from placement to $asOf (never beyond maturity), on the placement's own terms */
    public static function earnedThrough(array $td, string $asOf): string
    {
        $to = min($asOf, $td['maturity_date']);
        $days = max(0, Coop::daysBetween($td['placement_date'], $to));
        $days = min($days, (int)$td['term_days']);
        if ($days >= (int)$td['term_days']) {
            return Dec::sub($td['maturity_value'], $td['principal'], 2);
        }
        return self::compute($td['principal'], $td['rate'], $days, $td['interest_method'], $td['compounding'], (int)$td['calendar_days'])['interest'];
    }

    /**
     * Places a time deposit: opens the account, receives the principal (cash / check / transfer from savings) and
     * issues the certificate. $source: ['kind' => 'cash'|'savings', 'cash_account_id' => .., 'from_account_id' => ..]
     * Returns [placement_id, txn_id].
     */
    public static function place(int $memberId, int $productId, string $principal, int $termDays, string $date, array $source, array $opt, string $pageKey): array
    {
        // a placement at the counter (cash, check, from savings) is a transaction of the Savings Transaction Center with
        // its own transaction number; an opening balance or a renewal belongs to the voucher that brought it
        $kind = $source['kind'] ?? 'cash';
        if (!in_array($kind, ['opening', 'renewal'], true) && Savings::centerReady() && Savings::currentTransNo() === null) {
            return DB::tx(fn() => Savings::withTransNo(Seq::next('sav_trans'), fn() => self::place($memberId, $productId, $principal, $termDays, $date, $source, $opt, $pageKey)));
        }
        return DB::tx(function () use ($memberId, $productId, $principal, $termDays, $date, $source, $opt, $pageKey) {
            $transNo = Savings::currentTransNo();
            $type = $transNo !== null ? 'coop_sv' : null;
            $p = Savings::product($productId);
            if (!$p || $p['product_type'] !== 'time_deposit') {
                fail('Choose a time deposit product.');
            }
            $principal = Dec::round($principal, 2);
            if (Dec::isPos($p['opening_amount']) && Dec::cmp($principal, $p['opening_amount']) < 0) {
                fail('The minimum placement for ' . $p['name'] . ' is ' . Dec::money($p['opening_amount']) . '.');
            }
            if (Dec::isPos($p['max_balance']) && Dec::cmp($principal, $p['max_balance']) > 0) {
                fail('The maximum placement for ' . $p['name'] . ' is ' . Dec::money($p['max_balance']) . '.');
            }
            if ($termDays <= 0) {
                fail('Enter the term.');
            }
            // an opening balance brought over keeps its placement date; it is posted on the import date
            $postDate = $opt['post_date'] ?? $date;
            Coop::checkDate($postDate);
            $tier = Savings::rateFor($productId, $principal, $date, $termDays);
            $rate = $opt['rate'] ?? ($tier ? $tier['rate'] : null);
            if ($rate === null) {
                fail('No interest rate is set up for ' . $p['name'] . ' at ' . Dec::money($principal) . ' for ' . $termDays . ' days (Savings & Deposit Products > rates).');
            }
            $method = $opt['interest_method'] ?? $p['interest_method'];
            $compounding = $opt['compounding'] ?? 'maturity';
            $calendar = (int)$p['calendar_days'] ?: 365;
            $maturity = Coop::addDays($date, $termDays);
            $calc = self::compute($principal, $rate, $termDays, $method, $compounding, $calendar);
            $m = Coop::member($memberId);
            if (!$m) {
                fail('Member not found.');
            }
            Coop::assertTransactable($m);
            $accountId = Savings::open($memberId, $productId, $opt['branch_id'] ?? null, $date, $opt['passbook_no'] ?? null, 'sav.accounts');
            DB::update('sav_accounts', ['status' => 'active', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $accountId]);
            $a = Savings::validate($accountId, 'credit', $principal, $postDate, ['allow_pending' => true, 'is_system' => true]);
            $certNo = !empty($opt['certificate_no']) ? mb_substr(trim((string)$opt['certificate_no']), 0, 30) : Seq::next('td_certificate');
            if (DB::value('SELECT id FROM td_placements WHERE certificate_no = :c', ['c' => $certNo])) {
                fail('Certificate number ' . $certNo . ' is already used.');
            }
            $memo = 'Time deposit placement ' . $certNo . ' ' . $a['account_no'];
            $liab = Savings::liabilityAccount($p);
            if (($source['kind'] ?? 'cash') === 'opening') {
                // the import voucher already credited the liability
                $txnId = (int)$source['txn_id'];
                // an import entered with a savings transaction code of the user's choice (Import Time Deposit) prints that code
                $code = !empty($opt['opening_code']) ? (string)$opt['opening_code'] : 'OB';
            } elseif (($source['kind'] ?? 'cash') === 'renewal') {
                // the settlement voucher already credited the liability for the renewed principal
                $txnId = (int)$source['txn_id'];
                $code = 'TDM';
            } elseif (($source['kind'] ?? 'cash') === 'savings') {
                $from = Savings::validate((int)$source['from_account_id'], 'debit', $principal, $date);
                $txnId = CoopLedger::post($type ?? 'coop_jv', ['txn_date' => $date, 'memo' => ($transNo !== null ? $transNo . ' CTD ' : '') . $memo, 'branch_id' => $a['branch_id'], 'member_id' => $memberId, 'txn_no' => $transNo], [
                    ['account_id' => Savings::ledgerAccount($from), 'debit' => $principal, 'memo' => 'Transfer to time deposit ' . $certNo],
                    ['account_id' => $liab, 'credit' => $principal, 'memo' => $memo],
                ]);
                Savings::move($from, 'TRO', 'debit', $principal, $date, ['txn_id' => $txnId, 'description' => 'Placed in time deposit ' . $certNo, 'ref_type' => 'td']);
                $code = 'CTD';
            } else {
                $cash = (int)($source['cash_account_id'] ?? 0);
                if (!$cash) {
                    fail('Choose the cash account the principal is received in.');
                }
                $isCheck = ($source['kind'] ?? 'cash') === 'check';
                $c = Savings::code($isCheck ? 'CKTD' : 'CTD');
                $dr = $isCheck ? (int)($c['opposite_account_id'] ?: Accts::get('coop_coci')) : $cash;
                $txnId = CoopLedger::post($type ?? 'coop_or', ['txn_date' => $date, 'memo' => ($transNo !== null ? $transNo . ' ' . $c['code'] . ' ' : '') . $memo, 'branch_id' => $a['branch_id'], 'member_id' => $memberId, 'account_id' => $dr,
                    'txn_no' => $transNo ?? ($opt['or_no'] ?? null)], [
                    ['account_id' => $dr, 'debit' => $principal, 'memo' => $memo],
                    ['account_id' => $liab, 'credit' => $principal, 'memo' => $memo],
                ]);
                $code = $c['code'];
                if ($isCheck) {
                    $opt['clearing_days'] = (int)$c['clearing_days'];
                }
                if (!empty($opt['shift_id']) || $transNo !== null) {
                    Teller::recordReceipt($txnId, $transNo !== null ? 'sd' : 'or', $transNo ?? ($opt['or_no'] ?? Txn::load($txnId)['txn_no']), $memberId, (int)$a['branch_id'], !empty($opt['shift_id']) ? (int)$opt['shift_id'] : null,
                        $isCheck ? 'check' : 'cash', $isCheck ? null : $cash, $principal, [['kind' => 'td', 'ref_id' => $accountId, 'account_id' => $liab, 'description' => $memo, 'amount' => $principal]], $opt['cheque'] ?? []);
                }
            }
            Savings::move($a, $code, 'credit', $principal, $postDate, ['txn_id' => $txnId, 'description' => ($code === 'OB' || !empty($opt['opening_code']) ? 'Opening balance ' : ($code === 'TDM' ? 'Rollover ' : 'Time Deposit ')) . $certNo, 'ref_type' => 'td',
                'clearing_days' => $opt['clearing_days'] ?? 0, 'shift_id' => $opt['shift_id'] ?? null, 'or_no' => $transNo === null ? ($opt['or_no'] ?? null) : null,
                'cheques' => $opt['cheque'] ?? [], 'opposite_account_id' => $dr ?? null]);
            $id = DB::insert('td_placements', [
                'account_id' => $accountId, 'certificate_no' => $certNo, 'principal' => Dec::fix($principal), 'rate' => $rate, 'term_days' => $termDays,
                'term_label' => $opt['term_label'] ?? ($termDays . ' days'), 'placement_date' => $date, 'maturity_date' => $maturity, 'maturity_value' => Dec::fix($calc['maturity_value']),
                'interest_method' => $method, 'compounding' => $compounding, 'payout_mode' => $opt['payout_mode'] ?? 'maturity',
                'maturity_action' => $opt['maturity_action'] ?? 'payout', 'credit_account_id' => $opt['credit_account_id'] ?? null, 'calendar_days' => $calendar,
                'status' => 'active', 'placement_txn_id' => $txnId, 'notes' => $opt['notes'] ?? null, 'created_by' => Auth::id(),
                'renewed_from_id' => $opt['renewed_from_id'] ?? null,
            ]);
            DB::update('td_placements', ['renewed_to_id' => $id], 'id = :id', ['id' => $opt['renewed_from_id'] ?? 0]);
            Audit::log('create', 'sav', 'td_placement', $id, $certNo . ' ' . $m['full_name'], ['page_key' => $pageKey, 'amount' => (float)$principal,
                'snapshot' => ['principal' => $principal, 'rate' => $rate, 'term_days' => $termDays, 'maturity_date' => $maturity, 'maturity_value' => $calc['maturity_value'], 'txn_id' => $txnId]]);
            return [$id, $txnId];
        });
    }

    /** Monthly accrual: Dr interest expense, Cr accrued interest payable for the interest earned since the last accrual */
    public static function accrue(string $through, string $postingDate, string $pageKey, bool $post = true): array
    {
        if (!valid_date($through) || !valid_date($postingDate)) {
            fail('Enter the accrual date and the posting date.');
        }
        $rows = DB::all("SELECT t.* FROM td_placements t WHERE t.status = 'active' AND t.placement_date < :d", ['d' => $through]);
        $out = ['count' => 0, 'amount' => '0', 'txn_id' => null, 'lines' => []];
        $gl = [];
        $acc = [];
        foreach ($rows as $td) {
            $earned = self::earnedThrough($td, $through);
            $delta = Dec::sub($earned, $td['accrued_interest'], 2);
            if (Dec::cmp($delta, '0.01') < 0) {
                continue;
            }
            if (DB::value('SELECT id FROM td_accruals WHERE placement_id = :p AND period_to = :t', ['p' => $td['id'], 't' => $through])) {
                continue;
            }
            $a = DB::row('SELECT member_id, branch_id, product_id FROM sav_accounts WHERE id = :a', ['a' => $td['account_id']]);
            $p = Savings::product((int)$a['product_id']);
            $gl[] = ['account_id' => Savings::expenseAccount($p), 'debit' => $delta, 'memo' => 'Accrued interest TD ' . $td['certificate_no'], 'member_id' => $a['member_id'], 'class_id' => Coop::branchClassId($a['branch_id'] ? (int)$a['branch_id'] : null)];
            $gl[] = ['account_id' => (int)($p['accrued_account_id'] ?: Accts::get('deposit_interest_payable')), 'credit' => $delta, 'memo' => 'Accrued interest TD ' . $td['certificate_no'], 'member_id' => $a['member_id'], 'class_id' => Coop::branchClassId($a['branch_id'] ? (int)$a['branch_id'] : null)];
            $acc[] = [$td, $delta, $earned];
            $out['lines'][] = ['certificate_no' => $td['certificate_no'], 'member_id' => $a['member_id'], 'earned' => $earned, 'amount' => $delta];
            $out['amount'] = Dec::add($out['amount'], $delta, 2);
        }
        if (!$acc || !$post) {
            $out['count'] = count($acc);
            return $out;
        }
        return DB::tx(function () use ($gl, $acc, $through, $postingDate, $out, $pageKey) {
            $txnId = CoopLedger::post('coop_jv', ['txn_date' => $postingDate, 'memo' => 'Time deposit interest accrual through ' . fdate($through)], $gl);
            foreach ($acc as [$td, $delta, $earned]) {
                $lastTo = DB::value('SELECT MAX(period_to) FROM td_accruals WHERE placement_id = :p', ['p' => $td['id']]);
                DB::insert('td_accruals', ['placement_id' => $td['id'], 'accrual_date' => $postingDate, 'period_from' => $lastTo ? Coop::addDays($lastTo, 1) : $td['placement_date'], 'period_to' => $through, 'amount' => Dec::fix($delta), 'txn_id' => $txnId]);
                DB::update('td_placements', ['accrued_interest' => Dec::fix($earned)], 'id = :id', ['id' => $td['id']]);
            }
            $out['count'] = count($acc);
            $out['txn_id'] = $txnId;
            Audit::log('post', 'sav', 'td_accrual', null, 'TD accrual through ' . fdate($through), ['page_key' => $pageKey, 'amount' => (float)$out['amount']]);
            return $out;
        });
    }

    /**
     * Settles a placement at (or after) maturity, or pre-terminates it before. $how overrides the maturity action.
     * Interest: accrued so far comes out of the accrual payable, the rest is expensed now.
     * Returns [txn_id, new_placement_id|null].
     */
    public static function settle(int $placementId, string $date, string $how, array $opt, string $pageKey): array
    {
        // the withdrawal, rollover or closing is a transaction of the Savings Transaction Center (one number on its rows)
        if (Savings::centerReady() && Savings::currentTransNo() === null) {
            return DB::tx(fn() => Savings::withTransNo(Seq::next('sav_trans'), fn() => self::settle($placementId, $date, $how, $opt, $pageKey)));
        }
        return DB::tx(function () use ($placementId, $date, $how, $opt, $pageKey) {
            $transNo = Savings::currentTransNo();
            $td = self::placement($placementId, true);
            if (!$td || $td['status'] !== 'active') {
                fail('The time deposit is not active.');
            }
            Coop::checkDate($date);
            Coop::requireBranch($td['branch_id'] ? (int)$td['branch_id'] : null);
            $p = Savings::product((int)$td['product_id']);
            $preterm = $date < $td['maturity_date'];
            $fee = '0';
            if ($preterm) {
                if ($p['preterm_rate'] === null && !Access::can($pageKey, 'approve')) {
                    fail('Pre-termination of ' . $p['name'] . ' needs supervisor approval (no pre-termination rate is set on the product).');
                }
                $days = max(0, Coop::daysBetween($td['placement_date'], $date));
                $rate = $p['preterm_rate'] !== null ? $p['preterm_rate'] : '0';
                $interest = self::compute($td['principal'], $rate, $days, 'simple', 'maturity', (int)$td['calendar_days'])['interest'];
                $fee = Dec::round($p['preterm_fee'], 2);
                $approver = Approval::check($pageKey, true, 'pre-termination of time deposit ' . $td['certificate_no']);
            } else {
                $rate = $td['rate'];
                $interest = Dec::sub($td['maturity_value'], $td['principal'], 2);
                // after maturity: the product's rate after maturity on the extra days
                $extra = Coop::daysBetween($td['maturity_date'], $date);
                if ($extra > 0 && $p['rate_after_maturity'] !== 'zero') {
                    $r2 = $p['rate_after_maturity'] === 'fixed' ? $p['rate_after_value'] : $td['rate'];
                    $interest = Dec::add($interest, self::compute($td['maturity_value'], $r2, $extra, 'simple', 'maturity', (int)$td['calendar_days'])['interest'], 2);
                }
                $approver = null;
            }
            $interest = Dec::sub($interest, $td['interest_paid'], 2);   // interest already paid out at intervals
            if (Dec::isNeg($interest)) {
                $interest = '0';
            }
            // the accrual payable left: what was accrued less what the accrued interest withdrawals (CAIW) took from it
            $accrued = Dec::max('0', Dec::sub($td['accrued_interest'], $td['accrued_paid'] ?? '0', 2));
            $accruedUsed = Dec::min($accrued, $interest);
            $expenseNow = Dec::sub($interest, $accruedUsed, 2);
            $excessAccrual = Dec::sub($accrued, $accruedUsed, 2);     // pre-termination: more was accrued than is paid
            $liab = Savings::liabilityAccount($p);
            $expAcct = Savings::expenseAccount($p);
            $accAcct = (int)($p['accrued_account_id'] ?: Accts::get('deposit_interest_payable'));
            $memo = ($preterm ? 'Pre-termination' : 'Maturity') . ' of time deposit ' . $td['certificate_no'];
            $gl = [['account_id' => $liab, 'debit' => $td['principal'], 'memo' => $memo . ' principal']];
            if (Dec::isPos($accruedUsed)) {
                $gl[] = ['account_id' => $accAcct, 'debit' => $accruedUsed, 'memo' => $memo . ' accrued interest'];
            }
            if (Dec::isPos($expenseNow)) {
                $gl[] = ['account_id' => $expAcct, 'debit' => $expenseNow, 'memo' => $memo . ' interest'];
            }
            if (Dec::isPos($excessAccrual)) {
                $gl[] = ['account_id' => $accAcct, 'debit' => $excessAccrual, 'memo' => $memo . ' accrual reversed'];
                $gl[] = ['account_id' => $expAcct, 'credit' => $excessAccrual, 'memo' => $memo . ' accrual reversed'];
            }
            $gross = Dec::add($td['principal'], $interest, 2);
            $net = Dec::sub($gross, $fee, 2);
            if (Dec::isPos($fee)) {
                $gl[] = ['account_id' => (int)($p['preterm_fee_account_id'] ?: Accts::get('preterm_fee_income')), 'credit' => $fee, 'memo' => 'Pre-termination fee ' . $td['certificate_no']];
            }
            $newId = null;
            $type = 'coop_cv';
            $cashAcct = null;
            $renewPrincipal = '0';
            switch ($how) {
                case 'payout':
                    $cashAcct = (int)($opt['cash_account_id'] ?? 0);
                    if (!$cashAcct) {
                        fail('Choose the cash account the deposit is paid from.');
                    }
                    $gl[] = ['account_id' => $cashAcct, 'credit' => $net, 'memo' => $memo . ' paid'];
                    break;
                case 'credit_savings':
                    $to = Savings::validate((int)($opt['credit_account_id'] ?: $td['credit_account_id']), 'credit', $net, $date, ['is_system' => true]);
                    $gl[] = ['account_id' => Savings::ledgerAccount($to), 'credit' => $net, 'memo' => $memo . ' credited to ' . $to['account_no']];
                    $type = 'coop_jv';
                    break;
                case 'renew_principal':
                case 'renew_all':
                    $renewPrincipal = $how === 'renew_all' ? $net : Dec::of($td['principal']);
                    $payoutPart = Dec::sub($net, $renewPrincipal, 2);
                    $gl[] = ['account_id' => $liab, 'credit' => $renewPrincipal, 'memo' => 'Renewal of ' . $td['certificate_no']];
                    if (Dec::isPos($payoutPart)) {
                        $cashAcct = (int)($opt['cash_account_id'] ?? 0);
                        if (!$cashAcct) {
                            fail('Choose the cash account the interest is paid from.');
                        }
                        $gl[] = ['account_id' => $cashAcct, 'credit' => $payoutPart, 'memo' => 'Interest paid on ' . $td['certificate_no']];
                    } else {
                        $type = 'coop_jv';
                    }
                    break;
                default:
                    fail('Choose what to do with the deposit.');
            }
            if ($transNo !== null) {
                $type = 'coop_sv';
            }
            $txnId = CoopLedger::post($type, ['txn_date' => $date, 'memo' => ($transNo !== null ? $transNo . ' CTDW ' . $td['member_name'] . ' ' : '') . $memo, 'branch_id' => $td['branch_id'], 'member_id' => $td['member_id'], 'account_id' => $cashAcct,
                'txn_no' => $transNo ?? ($opt['cv_no'] ?? null),
                'approved_by' => $approver['id'] ?? null], $gl, ['approved_by' => $approver['username'] ?? null]);
            // subsidiary: the placement account is emptied
            $a = Savings::account((int)$td['account_id'], true);
            Savings::move($a, 'CTDW', 'debit', $td['principal'], $date, ['txn_id' => $txnId, 'description' => $how === 'renew_principal' || $how === 'renew_all' ? 'Time Deposit Rollover' : 'Time Deposit Withdrawal',
                'ref_type' => 'td', 'ref_id' => $placementId, 'is_system' => true, 'skip_available' => true, 'shift_id' => $opt['shift_id'] ?? null, 'opposite_account_id' => $cashAcct]);
            if ($how === 'credit_savings') {
                Savings::move($to, 'TDM', 'credit', $net, $date, ['txn_id' => $txnId, 'description' => $memo, 'ref_type' => 'td', 'ref_id' => $placementId, 'is_system' => true]);
            }
            DB::update('td_placements', ['status' => $preterm ? 'preterminated' : ($how === 'payout' || $how === 'credit_savings' ? 'paid' : 'renewed'),
                'settled_txn_id' => $txnId, 'settled_on' => $date, 'interest_paid' => Dec::fix(Dec::add($td['interest_paid'], $interest)),
                'preterm_rate' => $preterm ? $rate : null, 'preterm_fee' => $preterm ? Dec::fix($fee) : null, 'accrued_interest' => '0.00'] + (array_key_exists('accrued_paid', $td) ? ['accrued_paid' => '0.00'] : []), 'id = :id', ['id' => $placementId]);
            DB::update('sav_accounts', ['status' => 'closed', 'closed_on' => $date, 'close_reason' => $memo], 'id = :id', ['id' => $td['account_id']]);
            if ($cashAcct && (!empty($opt['shift_id']) || $transNo !== null)) {
                Teller::recordReceipt($txnId, $transNo !== null ? 'sw' : 'cv', $transNo ?? Txn::load($txnId)['txn_no'], (int)$td['member_id'], (int)$td['branch_id'], !empty($opt['shift_id']) ? (int)$opt['shift_id'] : null, 'cash', $cashAcct,
                    $how === 'payout' ? $net : Dec::sub($net, $renewPrincipal, 2), [['kind' => 'td', 'ref_id' => $td['account_id'], 'account_id' => $liab, 'description' => $memo, 'amount' => $how === 'payout' ? $net : Dec::sub($net, $renewPrincipal, 2)]]);
            }
            if (Dec::isPos($renewPrincipal)) {
                [$newId] = self::place((int)$td['member_id'], (int)$td['product_id'], $renewPrincipal, (int)($opt['term_days'] ?? $td['term_days']), $date, ['kind' => 'renewal', 'txn_id' => $txnId],
                    ['renewed_from_id' => $placementId, 'maturity_action' => $td['maturity_action'], 'credit_account_id' => $td['credit_account_id'], 'branch_id' => $td['branch_id'], 'interest_method' => $td['interest_method'], 'compounding' => $td['compounding'], 'passbook_no' => $a['passbook_no']], $pageKey);
            }
            Audit::log($preterm ? 'preterminate' : 'mature', 'sav', 'td_placement', $placementId, $td['certificate_no'], ['page_key' => $pageKey, 'amount' => (float)$net,
                'reason' => $opt['reason'] ?? null, 'approved_by' => $approver['username'] ?? null,
                'snapshot' => ['how' => $how, 'interest' => $interest, 'fee' => $fee, 'net' => $net, 'txn_id' => $txnId, 'renewed_to' => $newId]]);
            return [$txnId, $newId];
        });
    }
}
