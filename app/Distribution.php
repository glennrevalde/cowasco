<?php
declare(strict_types=1);

/**
 * Year-end distribution of the net surplus (RA 9520): the allocation to the statutory funds and to the pools of
 * interest on share capital (ISC) and patronage refund (PR), then the member-level ISC and PR batches.
 *
 *  1. Net Surplus Allocation (dist_periods): the net surplus of the fiscal year from the ledger (or typed from the
 *     audited statements), the percentages approved by the General Assembly, the resolution reference. Posting it:
 *       Dr Retained Earnings (the year's net surplus, shown there once the year has ended)
 *       Cr Reserve Fund, Education and Training Fund (and the part due to the union / federation),
 *          Community Development Fund, Optional Fund, ISC Payable, PR Payable
 *  2. A batch per kind (dist_batches): ISC on the average daily balance (or ending balance) of share capital,
 *     PR on each member's patronage (income accounts that carry the member's name in the period). Computed,
 *     reviewed (members can be removed with a reason), validated, approved with the resolution reference and
 *     then locked; posting it credits each member: savings, share capital, or the payable (paid out later).
 *  Nothing is posted twice: a batch is posted once (status), and the batches of a fiscal year cannot exceed the
 *  pool its allocation set aside.
 */
final class Distribution
{
    public const CREDIT_MODES = ['savings' => 'Credit to a savings account', 'share_capital' => 'Credit to share capital',
        'auto' => 'Share capital until the subscription is fully paid, the rest to savings', 'payable' => 'Keep as payable (paid out in cash / cheque later)'];

    // ------------------------------------------------------------------ net surplus allocation

    /** Net surplus of a period from the ledger: income less expenses (all branches) */
    public static function netSurplus(string $from, string $to): string
    {
        return Dec::round(DB::value("SELECT COALESCE(SUM(j.credit - j.debit),0) FROM journal_lines j JOIN accounts a ON a.id = j.account_id
                                       WHERE a.category IN ('Income','Expense') AND j.txn_date BETWEEN :f AND :t", ['f' => $from, 't' => $to]), 2);
    }

    /** The statutory amounts for a net surplus and the percentages; ISC and PR share what is left */
    public static function allocate(string $ns, array $pct, string $isc): array
    {
        $out = [];
        foreach (['reserve', 'educ', 'cdf', 'optional'] as $k) {
            $out[$k] = Dec::isPos($ns) ? Dec::pct($ns, $pct[$k] ?? '0') : '0';
        }
        $statutory = Dec::sum(array_values($out));
        $remaining = Dec::sub($ns, $statutory, 2);
        $isc = Dec::round($isc, 2);
        if (Dec::cmp($isc, $remaining) > 0) {
            fail('Interest on share capital (' . Dec::money($isc) . ') cannot exceed the net surplus left after the statutory funds (' . Dec::money($remaining) . ').');
        }
        $out['statutory'] = $statutory;
        $out['remaining'] = $remaining;
        $out['isc'] = $isc;
        $out['pr'] = Dec::sub($remaining, $isc, 2);
        return $out;
    }

    public static function savePeriod(array $d, ?int $id, string $pageKey): int
    {
        return DB::tx(function () use ($d, $id, $pageKey) {
            $old = $id ? DB::row('SELECT * FROM dist_periods WHERE id = :id FOR UPDATE', ['id' => $id]) : null;
            if ($old && $old['status'] !== 'draft') {
                fail('An allocation that is ' . $old['status'] . ' can no longer be edited.');
            }
            if (!valid_date($d['period_from']) || !valid_date($d['period_to']) || $d['period_from'] > $d['period_to']) {
                fail('Enter the fiscal period.');
            }
            $ns = Dec::round($d['net_surplus'], 2);
            if (!Dec::isPos($ns)) {
                fail('There is no net surplus to allocate (' . Dec::money($ns) . ').');
            }
            foreach (['reserve_pct', 'educ_pct', 'cdf_pct', 'optional_pct', 'educ_apex_pct'] as $k) {
                if (Dec::isNeg($d[$k]) || Dec::cmp($d[$k], '100') > 0) {
                    fail('Percentages are between 0 and 100.');
                }
            }
            $a = self::allocate($ns, ['reserve' => $d['reserve_pct'], 'educ' => $d['educ_pct'], 'cdf' => $d['cdf_pct'], 'optional' => $d['optional_pct']], $d['isc_amount']);
            $row = ['fiscal_year' => (int)$d['fiscal_year'], 'period_from' => $d['period_from'], 'period_to' => $d['period_to'], 'net_surplus' => Dec::fix($ns),
                'reserve_pct' => Dec::fix($d['reserve_pct']), 'reserve_amt' => Dec::fix($a['reserve']), 'educ_pct' => Dec::fix($d['educ_pct']), 'educ_amt' => Dec::fix($a['educ']),
                'educ_apex_pct' => Dec::fix($d['educ_apex_pct']), 'cdf_pct' => Dec::fix($d['cdf_pct']), 'cdf_amt' => Dec::fix($a['cdf']),
                'optional_pct' => Dec::fix($d['optional_pct']), 'optional_amt' => Dec::fix($a['optional']), 'isc_amount' => Dec::fix($a['isc']), 'pr_amount' => Dec::fix($a['pr']),
                'notes' => mb_substr((string)($d['notes'] ?? ''), 0, 2000) ?: null];
            if ($old) {
                DB::update('dist_periods', $row, 'id = :id', ['id' => $id]);
            } else {
                if (DB::value('SELECT id FROM dist_periods WHERE fiscal_year = :y', ['y' => $row['fiscal_year']])) {
                    fail('An allocation for fiscal year ' . $row['fiscal_year'] . ' already exists.');
                }
                $row['created_by'] = Auth::id();
                $id = DB::insert('dist_periods', $row);
            }
            Audit::log($old ? 'edit' : 'create', 'dist', 'dist_period', $id, 'Net surplus allocation ' . $row['fiscal_year'], ['page_key' => $pageKey, 'amount' => (float)$ns,
                'before' => $old ?: [], 'after' => $row, 'changes' => $old ? null : [], 'snapshot' => $old ? null : $row]);
            return $id;
        });
    }

    public static function approvePeriod(int $id, string $resolutionNo, string $resolutionDate, string $officers, string $pageKey): void
    {
        DB::tx(function () use ($id, $resolutionNo, $resolutionDate, $officers, $pageKey) {
            $p = DB::row('SELECT * FROM dist_periods WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (!$p || $p['status'] !== 'draft') {
                fail('Only a draft allocation can be approved.');
            }
            if (!Access::can($pageKey, 'approve')) {
                fail('Approving the allocation needs the Approve right on this page.');
            }
            if (trim($resolutionNo) === '' || !valid_date($resolutionDate)) {
                fail('Enter the General Assembly / Board resolution number and date.');
            }
            DB::update('dist_periods', ['status' => 'approved', 'resolution_no' => mb_substr($resolutionNo, 0, 60), 'resolution_date' => $resolutionDate,
                'approving_officers' => mb_substr($officers, 0, 255) ?: null, 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            Audit::log('approve', 'dist', 'dist_period', $id, 'Net surplus allocation ' . $p['fiscal_year'], ['page_key' => $pageKey, 'reason' => 'Resolution ' . $resolutionNo . ' of ' . fdate($resolutionDate)]);
        });
    }

    /** Posts the allocation entry (once) */
    public static function postPeriod(int $id, string $date, string $pageKey): int
    {
        return DB::tx(function () use ($id, $date, $pageKey) {
            $p = DB::row('SELECT * FROM dist_periods WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (!$p || $p['status'] !== 'approved') {
                fail('Approve the allocation first; an allocation is posted once.');
            }
            if ($p['allocation_txn_id']) {
                fail('The allocation has already been posted.');
            }
            Coop::checkDate($date);
            if ($date <= $p['period_to']) {
                fail('Post the allocation after the end of the fiscal year (' . fdate($p['period_to']) . '), when its net surplus is in Retained Earnings.');
            }
            $educApex = Dec::pct($p['educ_amt'], $p['educ_apex_pct']);
            $educLocal = Dec::sub($p['educ_amt'], $educApex, 2);
            $total = Dec::sum([$p['reserve_amt'], $p['educ_amt'], $p['cdf_amt'], $p['optional_amt'], $p['isc_amount'], $p['pr_amount']]);
            $memo = 'Allocation of net surplus ' . $p['fiscal_year'] . ' (resolution ' . $p['resolution_no'] . ')';
            $gl = [['account_id' => Accts::get('retained_earnings'), 'debit' => $total, 'memo' => $memo],
                ['account_id' => Accts::get('reserve_fund'), 'credit' => $p['reserve_amt'], 'memo' => 'Reserve fund ' . $p['fiscal_year']],
                ['account_id' => Accts::get('education_fund'), 'credit' => $educLocal, 'memo' => 'Education and training fund ' . $p['fiscal_year']],
                ['account_id' => Accts::get('education_fund_apex'), 'credit' => $educApex, 'memo' => 'Education fund due to the union / federation ' . $p['fiscal_year']],
                ['account_id' => Accts::get('cdf_fund'), 'credit' => $p['cdf_amt'], 'memo' => 'Community development fund ' . $p['fiscal_year']],
                ['account_id' => Accts::get('optional_fund'), 'credit' => $p['optional_amt'], 'memo' => 'Optional fund ' . $p['fiscal_year']],
                ['account_id' => Accts::get('isc_payable'), 'credit' => $p['isc_amount'], 'memo' => 'Interest on share capital ' . $p['fiscal_year']],
                ['account_id' => Accts::get('pr_payable'), 'credit' => $p['pr_amount'], 'memo' => 'Patronage refund ' . $p['fiscal_year']]];
            $txnId = CoopLedger::post('coop_jv', ['txn_date' => $date, 'memo' => $memo, 'ref_no' => $p['resolution_no']], $gl);
            DB::update('dist_periods', ['status' => 'posted', 'allocation_txn_id' => $txnId], 'id = :id', ['id' => $id]);
            Audit::log('post', 'dist', 'dist_period', $id, $memo, ['page_key' => $pageKey, 'amount' => (float)$total]);
            return $txnId;
        });
    }

    /** Reverses a posted allocation with an adjusting entry (never by voiding); it goes back to approved */
    public static function reversePeriod(int $id, string $date, string $reason, string $pageKey): int
    {
        return DB::tx(function () use ($id, $date, $reason, $pageKey) {
            $p = DB::row('SELECT * FROM dist_periods WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (!$p || $p['status'] !== 'posted') {
                fail('The allocation is not posted.');
            }
            if (!Access::can($pageKey, 'approve')) {
                fail('Reversing the allocation needs the Approve right.');
            }
            if (trim($reason) === '') {
                fail('Enter the reason for the reversal.');
            }
            if (DB::value("SELECT id FROM dist_batches WHERE period_id = :p AND status = 'posted'", ['p' => $id])) {
                fail('ISC or PR batches of this year are posted. Reverse them first.');
            }
            Coop::checkDate($date);
            $txnId = CoopLedger::reverse((int)$p['allocation_txn_id'], $date, $reason);
            DB::update('dist_periods', ['status' => 'approved', 'allocation_txn_id' => null, 'notes' => mb_substr(($p['notes'] ? $p['notes'] . "\n" : '') . 'Allocation reversed ' . fdate($date) . ': ' . $reason, 0, 2000)], 'id = :id', ['id' => $id]);
            Audit::log('reverse', 'dist', 'dist_period', $id, 'Net surplus allocation ' . $p['fiscal_year'], ['page_key' => $pageKey, 'reason' => $reason]);
            return $txnId;
        });
    }

    // ------------------------------------------------------------------ batches

    public static function batch(int $id, bool $lock = false): ?array
    {
        return DB::row('SELECT * FROM dist_batches WHERE id = :id' . ($lock ? ' FOR UPDATE' : ''), ['id' => $id]);
    }

    /**
     * The members a batch covers, with the basis of each: ISC on share capital ADB / ending balance, PR on income earned.
     * $f: cluster_id, segment_id, member_types[], product_id (ISC: the share capital product measured), income_accounts[] (PR),
     *     amount_min, amount_max, date_from, date_to, branch_id, exclude_delinquent
     */
    public static function candidates(string $kind, array $f, string $basis): array
    {
        $where = ["m.status IN ('active','suspended')"];
        $p = [];
        if (!empty($f['cluster_id'])) {
            $where[] = 'm.cluster_id = :cl';
            $p['cl'] = (int)$f['cluster_id'];
        }
        if (!empty($f['segment_id'])) {
            $where[] = 'm.segment_id = :sg';
            $p['sg'] = (int)$f['segment_id'];
        }
        if (!empty($f['member_types'])) {
            [$in, $ip] = DB::in('mt', array_map('intval', (array)$f['member_types']));
            $where[] = "m.member_type_id IN $in";
            $p += $ip;
        }
        if (!empty($f['branch_id'])) {
            $where[] = 'm.branch_id = :br';
            $p['br'] = (int)$f['branch_id'];
        }
        [$bf, $bp] = Coop::branchFilter('m.branch_id', 'cbr');
        $members = DB::all('SELECT m.* FROM members m WHERE ' . implode(' AND ', $where) . $bf . ' ORDER BY m.full_name', $p + $bp);
        $from = $f['date_from'];
        $to = $f['date_to'];
        $rows = [];
        if ($kind === 'isc') {
            $productIds = !empty($f['product_id']) ? [(int)$f['product_id']] : array_map('intval', DB::column("SELECT id FROM sav_products WHERE product_type = 'share_capital' AND isc_eligible = 1"));
            if (!$productIds) {
                fail('No share capital product is set up.');
            }
            [$pin, $pp] = DB::in('sp', $productIds);
            foreach ($members as $m) {
                $accts = DB::all("SELECT a.* FROM sav_accounts a WHERE a.member_id = :m AND a.product_id IN $pin AND a.status <> 'pending' AND (a.opened_on IS NULL OR a.opened_on <= :t)", ['m' => $m['id'], 't' => $to] + $pp);
                foreach ($accts as $a) {
                    $b = Savings::balances((int)$a['id'], $from, $to);
                    $amt = $basis === 'ending' ? $b['ending'] : $b['adb'];
                    if (!Dec::isPos($amt)) {
                        continue;
                    }
                    $rows[] = ['member' => $m, 'sav_account_id' => (int)$a['id'], 'account_no' => $a['account_no'], 'basis' => $amt, 'current_balance' => Dec::of($a['balance'])];
                }
            }
        } else {
            $earned = self::patronage(in_array($basis, ['purchases', 'deliveries'], true) ? $basis : 'income', $f, $from, $to);
            foreach ($members as $m) {
                $amt = Dec::round($earned[(int)$m['id']] ?? '0', 2);
                if (!Dec::isPos($amt)) {
                    continue;
                }
                $rows[] = ['member' => $m, 'sav_account_id' => null, 'account_no' => '', 'basis' => $amt, 'current_balance' => '0'];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            if (isset($f['amount_min']) && $f['amount_min'] !== '' && Dec::cmp($r['basis'], $f['amount_min']) < 0) {
                continue;
            }
            if (isset($f['amount_max']) && $f['amount_max'] !== '' && Dec::isPos($f['amount_max']) && Dec::cmp($r['basis'], $f['amount_max']) > 0) {
                continue;
            }
            $r['delinquent'] = (int)DB::value("SELECT COUNT(*) FROM loan_accounts WHERE member_id = :m AND status = 'past_due'", ['m' => $r['member']['id']]) > 0;
            $out[] = $r;
        }
        return $out;
    }

    /** What a patronage refund is based on */
    public const PR_BASES = [
        'income' => 'Income earned from the member (the income accounts chosen)',
        'purchases' => 'Member\'s purchases of goods and services that count',
        'deliveries' => 'Member\'s deliveries (produce sold to the cooperative) that count',
    ];

    public static function basisLabel(array $batch): string
    {
        if ($batch['kind'] === 'isc') {
            return $batch['basis'] === 'ending' ? 'Ending balance' : 'Average daily balance';
        }
        return ['purchases' => 'Purchases', 'deliveries' => 'Deliveries'][$batch['basis']] ?? 'Income earned';
    }

    /**
     * Each member's patronage in the period: member_id => amount. Only what counts is taken: goods and services marked
     * "counts for patronage refund", in lines of business whose member transactions count; a member is recognized on a
     * document through the party, whatever role it acted in (member, customer or supplier).
     *   income:     the income accounts chosen, on ledger lines with the member's name or made out to the member's
     *               customer record (lines of an item count only when the item counts)
     *   purchases:  sales documents made out to the member's customer records, less returns (items that count)
     *   deliveries: purchase documents from the member's supplier records, less debit memos (items that count)
     */
    public static function patronage(string $basis, array $f, string $from, string $to): array
    {
        $out = [];
        $add = function (array $pairs) use (&$out) {
            foreach ($pairs as $m => $amt) {
                $out[(int)$m] = Dec::add($out[(int)$m] ?? '0', Dec::of($amt), 2);
            }
        };
        $partyReady = Party::ready() && BusinessUnits::ready();
        $buOk = $partyReady ? ' AND (bu.id IS NULL OR bu.patronage_eligible = 1)' : '';
        if ($basis === 'income') {
            $accounts = array_map('intval', (array)($f['income_accounts'] ?? []));
            if (!$accounts) {
                fail('Choose the income accounts (income codes) the patronage refund is based on.');
            }
            [$ain, $ap] = DB::in('ia', $accounts);
            $itemOk = $partyReady ? ' AND (jl.item_id IS NULL OR i.patronage_eligible = 1)' : '';
            $join = $partyReady ? ' LEFT JOIN items i ON i.id = jl.item_id LEFT JOIN business_units bu ON bu.id = jl.business_unit_id' : '';
            // lines with the member's ledger name (loan interest, fees ...)
            $add(DB::pairs("SELECT m.id, SUM(jl.credit - jl.debit) FROM journal_lines jl JOIN transactions t ON t.id = jl.txn_id JOIN members m ON m.other_name_id = jl.other_name_id$join
                             WHERE jl.account_id IN $ain AND jl.txn_date BETWEEN :f AND :t AND t.is_voided = 0$itemOk$buOk GROUP BY m.id", ['f' => $from, 't' => $to] + $ap));
            if ($partyReady) {
                // lines made out to the member's customer records (store and service sales)
                $add(DB::pairs("SELECT m.id, SUM(jl.credit - jl.debit) FROM journal_lines jl JOIN transactions t ON t.id = jl.txn_id JOIN customers c ON c.id = jl.customer_id
                                  JOIN members m ON m.party_id = c.party_id$join
                                 WHERE jl.account_id IN $ain AND jl.txn_date BETWEEN :f AND :t AND t.is_voided = 0 AND jl.other_name_id IS NULL$itemOk$buOk GROUP BY m.id", ['f' => $from, 't' => $to] + $ap));
            }
            return $out;
        }
        if (!$partyReady) {
            fail('Purchases and deliveries are recognized through the Party Master: run the 2026_11_15 database update first.');
        }
        if ($basis === 'purchases') {
            $add(DB::pairs("SELECT m.id, SUM(CASE WHEN t.txn_type IN ('credit_memo','pos_return') THEN -l.amount ELSE l.amount END)
                              FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id JOIN items i ON i.id = l.item_id
                              JOIN customers c ON c.id = t.customer_id JOIN members m ON m.party_id = c.party_id LEFT JOIN business_units bu ON bu.id = t.business_unit_id
                             WHERE t.txn_type IN ('invoice','sales_receipt','pos_sale','statement_charge','credit_memo','pos_return') AND t.is_voided = 0 AND t.is_pending = 0
                               AND t.status <> 'suspended' AND t.txn_date BETWEEN :f AND :t AND l.line_kind = 'item' AND i.patronage_eligible = 1$buOk
                             GROUP BY m.id", ['f' => $from, 't' => $to]));
            return $out;
        }
        $add(DB::pairs("SELECT m.id, SUM(CASE WHEN t.txn_type = 'vendor_credit' THEN -l.amount ELSE l.amount END)
                          FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id JOIN items i ON i.id = l.item_id
                          JOIN vendors v ON v.id = t.vendor_id JOIN members m ON m.party_id = v.party_id LEFT JOIN business_units bu ON bu.id = t.business_unit_id
                         WHERE t.txn_type IN ('bill','item_receipt','check','vendor_credit') AND t.is_voided = 0 AND t.is_pending = 0
                           AND t.txn_date BETWEEN :f AND :t AND l.line_kind = 'item' AND i.patronage_eligible = 1$buOk
                         GROUP BY m.id", ['f' => $from, 't' => $to]));
        return $out;
    }

    /**
     * Where a member's basis comes from (shown when the basis amount on a batch is pointed at or tapped):
     *  ISC: the share capital account, its balance at the start of the period and each stretch of days the balance stood
     *       the same (Savings::balanceSegments()), the balance-days added up and divided by the days of the period (the
     *       average daily balance), or the ending balance;
     *  PR:  the income earned from the member by income account, document by document (or the purchases / deliveries
     *       document by document) in the period, with the same rules as patronage().
     * 'current' is the basis worked out again now; 'changed' says it no longer equals the basis stored on the line (an
     * entry dated in the period was made or voided after the batch was computed: Recompute takes it).
     */
    public static function basisDetail(array $batch, array $line): array
    {
        $from = (string)$batch['date_from'];
        $to = (string)$batch['date_to'];
        $out = ['kind' => $batch['kind'], 'basis' => $batch['basis'], 'from' => $from, 'to' => $to, 'stored' => Dec::of($line['basis_amount'])];
        if ($batch['kind'] === 'isc') {
            $acct = $line['sav_account_id'] ? DB::row('SELECT a.account_no, p.name AS product FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.id = :a', ['a' => $line['sav_account_id']]) : null;
            $seg = $line['sav_account_id'] ? Savings::balanceSegments((int)$line['sav_account_id'], $from, $to) : ['opening' => '0', 'segments' => [], 'changes' => [], 'sum' => '0', 'days' => 0, 'adb' => '0', 'ending' => '0'];
            $out += ['account' => $acct['account_no'] ?? '', 'product' => $acct['product'] ?? ''] + $seg;
            $out['current'] = $batch['basis'] === 'ending' ? $seg['ending'] : $seg['adb'];
        } else {
            $f = (array)json_decode((string)$batch['filters_json'], true);
            $out['groups'] = self::patronageDetail($batch['basis'], $f, $from, $to, (int)$line['member_id']);
            $out['current'] = Dec::round(Dec::sum(array_column($out['groups'], 'total')), 2);
        }
        $out['changed'] = Dec::cmp($out['current'], $out['stored']) !== 0;
        return $out;
    }

    /**
     * The patronage of one member in a period, as patronage() counts it, by group: [label, total, docs => [id, type,
     * no, date, amount]]. Income: a group per income account; purchases and deliveries: one group of documents.
     */
    public static function patronageDetail(string $basis, array $f, string $from, string $to, int $memberId): array
    {
        $partyReady = Party::ready() && BusinessUnits::ready();
        $buOk = $partyReady ? ' AND (bu.id IS NULL OR bu.patronage_eligible = 1)' : '';
        $docs = [];
        $put = function (string $group, array $r, string $amount) use (&$docs) {
            $docs[$group]['label'] ??= $group;
            $key = (int)$r['txn_id'];
            $docs[$group]['docs'][$key] ??= ['id' => (int)$r['txn_id'], 'type' => (string)$r['txn_type'], 'no' => (string)$r['txn_no'], 'date' => (string)$r['d'], 'amount' => '0'];
            $docs[$group]['docs'][$key]['amount'] = Dec::add($docs[$group]['docs'][$key]['amount'], Dec::of($amount), 2);
        };
        if (!in_array($basis, ['purchases', 'deliveries'], true)) {
            $accounts = array_map('intval', (array)($f['income_accounts'] ?? []));
            if (!$accounts) {
                return [];
            }
            [$ain, $ap] = DB::in('ia', $accounts);
            $itemOk = $partyReady ? ' AND (jl.item_id IS NULL OR i.patronage_eligible = 1)' : '';
            $join = $partyReady ? ' LEFT JOIN items i ON i.id = jl.item_id LEFT JOIN business_units bu ON bu.id = jl.business_unit_id' : '';
            $names = DB::pairs('SELECT id, CONCAT(code, \' - \', name) FROM accounts WHERE id IN ' . $ain, $ap);
            $sets = [DB::all("SELECT jl.account_id, t.id AS txn_id, t.txn_type, t.txn_no, jl.txn_date AS d, SUM(jl.credit - jl.debit) AS amt
                                FROM journal_lines jl JOIN transactions t ON t.id = jl.txn_id JOIN members m ON m.other_name_id = jl.other_name_id$join
                               WHERE jl.account_id IN $ain AND jl.txn_date BETWEEN :f AND :t AND t.is_voided = 0$itemOk$buOk AND m.id = :m
                               GROUP BY jl.account_id, t.id, t.txn_type, t.txn_no, jl.txn_date", ['f' => $from, 't' => $to, 'm' => $memberId] + $ap)];
            if ($partyReady) {
                $sets[] = DB::all("SELECT jl.account_id, t.id AS txn_id, t.txn_type, t.txn_no, jl.txn_date AS d, SUM(jl.credit - jl.debit) AS amt
                                     FROM journal_lines jl JOIN transactions t ON t.id = jl.txn_id JOIN customers c ON c.id = jl.customer_id
                                     JOIN members m ON m.party_id = c.party_id$join
                                    WHERE jl.account_id IN $ain AND jl.txn_date BETWEEN :f AND :t AND t.is_voided = 0 AND jl.other_name_id IS NULL$itemOk$buOk AND m.id = :m
                                    GROUP BY jl.account_id, t.id, t.txn_type, t.txn_no, jl.txn_date", ['f' => $from, 't' => $to, 'm' => $memberId] + $ap);
            }
            foreach ($sets as $rows) {
                foreach ($rows as $r) {
                    $put((string)($names[(int)$r['account_id']] ?? 'Account ' . $r['account_id']), $r, (string)$r['amt']);
                }
            }
        } elseif ($partyReady && $basis === 'purchases') {
            foreach (DB::all("SELECT t.id AS txn_id, t.txn_type, t.txn_no, t.txn_date AS d, SUM(CASE WHEN t.txn_type IN ('credit_memo','pos_return') THEN -l.amount ELSE l.amount END) AS amt
                                FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id JOIN items i ON i.id = l.item_id
                                JOIN customers c ON c.id = t.customer_id JOIN members m ON m.party_id = c.party_id LEFT JOIN business_units bu ON bu.id = t.business_unit_id
                               WHERE t.txn_type IN ('invoice','sales_receipt','pos_sale','statement_charge','credit_memo','pos_return') AND t.is_voided = 0 AND t.is_pending = 0
                                 AND t.status <> 'suspended' AND t.txn_date BETWEEN :f AND :t AND l.line_kind = 'item' AND i.patronage_eligible = 1$buOk AND m.id = :m
                               GROUP BY t.id, t.txn_type, t.txn_no, t.txn_date", ['f' => $from, 't' => $to, 'm' => $memberId]) as $r) {
                $put('Purchases of goods and services that count', $r, (string)$r['amt']);
            }
        } elseif ($partyReady) {
            foreach (DB::all("SELECT t.id AS txn_id, t.txn_type, t.txn_no, t.txn_date AS d, SUM(CASE WHEN t.txn_type = 'vendor_credit' THEN -l.amount ELSE l.amount END) AS amt
                                FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id JOIN items i ON i.id = l.item_id
                                JOIN vendors v ON v.id = t.vendor_id JOIN members m ON m.party_id = v.party_id LEFT JOIN business_units bu ON bu.id = t.business_unit_id
                               WHERE t.txn_type IN ('bill','item_receipt','check','vendor_credit') AND t.is_voided = 0 AND t.is_pending = 0
                                 AND t.txn_date BETWEEN :f AND :t AND l.line_kind = 'item' AND i.patronage_eligible = 1$buOk AND m.id = :m
                               GROUP BY t.id, t.txn_type, t.txn_no, t.txn_date", ['f' => $from, 't' => $to, 'm' => $memberId]) as $r) {
                $put('Deliveries that count', $r, (string)$r['amt']);
            }
        }
        $out = [];
        foreach ($docs as $g) {
            $list = array_values($g['docs']);
            usort($list, fn($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
            $out[] = ['label' => $g['label'], 'total' => Dec::sum(array_column($list, 'amount')), 'docs' => $list];
        }
        usort($out, fn($a, $b) => strcmp($a['label'], $b['label']));
        return $out;
    }

    /** The account a member's amount is credited to, by the batch's credit mode */
    private static function creditAccountFor(array $batch, int $memberId): ?int
    {
        if ($batch['credit_mode'] === 'payable') {
            return null;
        }
        $productId = $batch['credit_mode'] === 'share_capital' ? null : (int)$batch['credit_product_id'];
        if ($batch['credit_mode'] === 'share_capital') {
            $id = DB::value("SELECT a.id FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m AND p.product_type = 'share_capital' AND a.status IN ('active','dormant') ORDER BY a.id LIMIT 1", ['m' => $memberId]);
        } else {
            $id = DB::value("SELECT id FROM sav_accounts WHERE member_id = :m AND product_id = :p AND status IN ('active','dormant') ORDER BY id LIMIT 1", ['m' => $memberId, 'p' => $productId]);
        }
        return $id ? (int)$id : null;
    }

    /** Creates or recomputes a draft batch: the lines and the totals */
    public static function compute(string $kind, array $h, array $filters, ?int $id, string $pageKey): int
    {
        return DB::tx(function () use ($kind, $h, $filters, $id, $pageKey) {
            $old = $id ? self::batch($id, true) : null;
            if ($old && !in_array($old['status'], ['draft', 'validated'], true)) {
                fail('The batch is ' . $old['status'] . ' and locked.');
            }
            if (!valid_date($h['date_from']) || !valid_date($h['date_to']) || $h['date_from'] > $h['date_to']) {
                fail('Enter the date range.');
            }
            $method = $h['method'] === 'pool' ? 'pool' : 'rate';
            $rate = Dec::round($h['rate'] ?? '0', 4);
            $pool = Dec::round($h['pool'] ?? '0', 2);
            if ($method === 'rate' && !Dec::isPos($rate)) {
                fail('Enter the ' . ($kind === 'isc' ? 'dividend (ISC) rate' : 'refund rate') . ' in percent.');
            }
            if ($method === 'pool' && !Dec::isPos($pool)) {
                fail('Enter the amount to distribute.');
            }
            if (!isset(self::CREDIT_MODES[$h['credit_mode']])) {
                fail('Choose how the amounts are credited.');
            }
            if (in_array($h['credit_mode'], ['savings', 'auto'], true) && empty($h['credit_product_id'])) {
                fail('Choose the savings product the amounts are credited to.');
            }
            $hb = (string)($h['basis'] ?? '');
            $basis = $kind === 'isc' ? ($hb === 'ending' ? 'ending' : 'adb') : (in_array($hb, ['purchases', 'deliveries'], true) ? $hb : 'income');
            $filters['date_from'] = $h['date_from'];
            $filters['date_to'] = $h['date_to'];
            $cands = self::candidates($kind, $filters, $basis);
            $row = ['period_id' => $h['period_id'] ?: null, 'kind' => $kind, 'description' => mb_substr((string)($h['description'] ?? ''), 0, 160) ?: null,
                'filters_json' => json_encode($filters, JSON_UNESCAPED_UNICODE), 'date_from' => $h['date_from'], 'date_to' => $h['date_to'], 'method' => $method,
                'rate' => Dec::fix($rate, 4), 'pool' => Dec::fix($pool), 'basis' => $basis, 'credit_mode' => $h['credit_mode'], 'credit_product_id' => $h['credit_product_id'] ?: null,
                'memo' => mb_substr((string)($h['memo'] ?? ''), 0, 255) ?: null, 'status' => 'draft'];
            if ($old) {
                // keep the members removed by hand: they stay removed after a recomputation
                $excluded = DB::pairs('SELECT member_id, exclude_reason FROM dist_batch_lines WHERE batch_id = :b AND is_excluded = 1', ['b' => $id]);
                DB::update('dist_batches', $row, 'id = :id', ['id' => $id]);
                DB::run('DELETE FROM dist_batch_lines WHERE batch_id = :b', ['b' => $id]);
            } else {
                $excluded = [];
                $row['batch_no'] = Seq::next('dist_batch');
                $row['created_by'] = Auth::id();
                $id = DB::insert('dist_batches', $row);
            }
            foreach ($cands as $c) {
                $memberId = (int)$c['member']['id'];
                $ex = isset($excluded[$memberId]);
                $reason = $ex ? $excluded[$memberId] : null;
                if (!$ex && !empty($filters['exclude_delinquent']) && $c['delinquent']) {
                    $ex = true;
                    $reason = 'Past due loan';
                }
                DB::insert('dist_batch_lines', ['batch_id' => $id, 'member_id' => $memberId, 'sav_account_id' => $c['sav_account_id'], 'basis_amount' => Dec::fix($c['basis']),
                    'current_balance' => Dec::fix($c['current_balance']), 'computed' => '0.00', 'net_amount' => '0.00', 'is_excluded' => $ex ? 1 : 0, 'exclude_reason' => $reason]);
            }
            self::recalc($id);
            $b = self::batch($id);
            Audit::log($old ? 'recompute' : 'create', 'dist', 'dist_batch', $id, $b['batch_no'] . ' ' . strtoupper($kind), ['page_key' => $pageKey, 'amount' => (float)$b['total_amount'],
                'snapshot' => ['members' => $b['members_count'], 'basis' => $b['total_basis'], 'total' => $b['total_amount'], 'method' => $method, 'rate' => $rate, 'pool' => $pool]]);
            return $id;
        });
    }

    /** Works the amounts out again from the basis of the lines that are not excluded */
    public static function recalc(int $id): void
    {
        $b = self::batch($id);
        $lines = DB::all('SELECT * FROM dist_batch_lines WHERE batch_id = :b ORDER BY id', ['b' => $id]);
        $inc = array_filter($lines, fn($l) => (int)$l['is_excluded'] === 0);
        $totBasis = Dec::sum(array_column($inc, 'basis_amount'));
        $sum = '0';
        $largest = null;
        foreach ($lines as $l) {
            if ((int)$l['is_excluded'] === 1) {
                DB::update('dist_batch_lines', ['computed' => '0.00', 'net_amount' => '0.00'], 'id = :id', ['id' => $l['id']]);
                continue;
            }
            if ($b['method'] === 'rate') {
                $amt = Dec::pct($l['basis_amount'], $b['rate']);
            } else {
                $amt = Dec::isPos($totBasis) ? Dec::round(Dec::div(Dec::mul($b['pool'], $l['basis_amount'], 10), $totBasis, 10), 2) : '0';
            }
            $net = Dec::add($amt, $l['adjustment'], 2);
            DB::update('dist_batch_lines', ['computed' => Dec::fix($amt), 'net_amount' => Dec::fix($net)], 'id = :id', ['id' => $l['id']]);
            $sum = Dec::add($sum, $amt, 2);
            if ($largest === null || Dec::cmp($l['basis_amount'], $largest['basis_amount']) > 0) {
                $largest = $l;
            }
        }
        $diff = '0';
        if ($b['method'] === 'pool' && $largest) {
            // the rounding difference goes to the member with the largest basis, so the pool is distributed exactly
            $diff = Dec::sub($b['pool'], $sum, 2);
            if (!Dec::isZero($diff)) {
                DB::run('UPDATE dist_batch_lines SET computed = computed + :d, net_amount = net_amount + :d2 WHERE id = :id', ['d' => Dec::fix($diff), 'd2' => Dec::fix($diff), 'id' => $largest['id']]);
            }
        }
        $t = DB::row('SELECT COUNT(*) AS n, COALESCE(SUM(basis_amount),0) AS b, COALESCE(SUM(net_amount),0) AS a FROM dist_batch_lines WHERE batch_id = :b AND is_excluded = 0', ['b' => $id]);
        DB::update('dist_batches', ['members_count' => (int)$t['n'], 'total_basis' => Dec::fix($t['b']), 'total_amount' => Dec::fix($t['a']), 'rounding_diff' => Dec::fix($diff)], 'id = :id', ['id' => $id]);
    }

    public static function exclude(int $batchId, int $lineId, bool $exclude, string $reason, ?string $adjustment, string $pageKey): void
    {
        DB::tx(function () use ($batchId, $lineId, $exclude, $reason, $adjustment, $pageKey) {
            $b = self::batch($batchId, true);
            if (!$b || !in_array($b['status'], ['draft', 'validated'], true)) {
                fail('The batch is locked.');
            }
            $l = DB::row('SELECT bl.*, m.full_name FROM dist_batch_lines bl JOIN members m ON m.id = bl.member_id WHERE bl.id = :id AND bl.batch_id = :b', ['id' => $lineId, 'b' => $batchId]);
            if (!$l) {
                fail('Line not found.');
            }
            if ($exclude && trim($reason) === '') {
                fail('Enter the reason the member is removed from the list.');
            }
            $upd = ['is_excluded' => $exclude ? 1 : 0, 'exclude_reason' => $exclude ? mb_substr($reason, 0, 160) : null];
            if ($adjustment !== null && $adjustment !== '') {
                if (trim($reason) === '') {
                    fail('Enter the reason for the adjustment.');
                }
                $upd['adjustment'] = Dec::fix($adjustment);
                $upd['exclude_reason'] = mb_substr($reason, 0, 160);
            }
            DB::update('dist_batch_lines', $upd, 'id = :id', ['id' => $lineId]);
            DB::update('dist_batches', ['status' => 'draft'], 'id = :id', ['id' => $batchId]);
            self::recalc($batchId);
            Audit::log($exclude ? 'exclude' : 'include', 'dist', 'dist_batch', $batchId, $b['batch_no'] . ': ' . $l['full_name'], ['page_key' => $pageKey, 'reason' => $reason]);
        });
    }

    /** The reasons offered when a member is removed from the list of a batch (the reviewer may add details) */
    public const REMOVE_REASONS = [
        'delinquent' => 'Delinquent: past-due loan',
        'obligation' => 'Has another obligation to the cooperative',
        'standing' => 'Not a member in good standing',
        'other' => 'Other reason',
    ];

    /** The reason as recorded: the reason chosen, with the details typed (an "other" reason needs them) */
    public static function removeReason(string $pick, string $details): string
    {
        $details = trim(preg_replace('/\s+/', ' ', $details));
        if (!isset(self::REMOVE_REASONS[$pick]) || $pick === 'other') {
            if ($details === '') {
                fail('Enter the reason the member is removed from the list.');
            }
            return mb_substr($details, 0, 160);
        }
        return mb_substr(self::REMOVE_REASONS[$pick] . ($details !== '' ? ': ' . $details : ''), 0, 160);
    }

    /**
     * What members owe the cooperative, for the review of a batch: member_id => [past_due (the amortizations of open
     * loans due before $asOf and unpaid, with the penalties), past_due_loans (their loan nos.), loan_balance (the
     * principal of the open loans), hold (the hold reason, when the member is on hold), unpaid_capital (the share
     * capital subscribed and not yet paid)]. A member with a past-due amount or on hold "has an obligation".
     */
    public static function obligations(array $memberIds, string $asOf): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds))));
        $out = [];
        if (!$ids) {
            return $out;
        }
        foreach ($ids as $id) {
            $out[$id] = ['past_due' => '0', 'past_due_loans' => [], 'loan_balance' => '0', 'hold' => '', 'unpaid_capital' => '0'];
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            [$in, $ip] = DB::in('om', $chunk);
            foreach (DB::all("SELECT l.member_id, l.loan_no, l.principal_balance, l.penalty_balance,
                                     (SELECT COALESCE(SUM(GREATEST(s.principal - s.principal_paid, 0) + GREATEST(s.interest - s.interest_paid, 0)), 0)
                                        FROM loan_schedule s WHERE s.loan_id = l.id AND s.is_paid = 0 AND s.due_date < :d) AS arrears
                                FROM loan_accounts l WHERE l.member_id IN $in AND l.status IN ('current','past_due','restructured')", $ip + ['d' => $asOf]) as $l) {
                $o = &$out[(int)$l['member_id']];
                $o['loan_balance'] = Dec::add($o['loan_balance'], $l['principal_balance'], 2);
                $due = Dec::add($l['arrears'], $l['penalty_balance'], 2);
                if (Dec::isPos($due)) {
                    $o['past_due'] = Dec::add($o['past_due'], $due, 2);
                    $o['past_due_loans'][] = $l['loan_no'];
                }
                unset($o);
            }
            foreach (DB::all("SELECT m.id, m.hold_flag, m.hold_reason, m.subscribed_capital,
                                     (SELECT COALESCE(SUM(a.balance), 0) FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id
                                       WHERE a.member_id = m.id AND p.product_type = 'share_capital' AND a.status <> 'closed') AS paid_capital
                                FROM members m WHERE m.id IN $in", $ip) as $m) {
                $o = &$out[(int)$m['id']];
                if ((int)$m['hold_flag'] === 1) {
                    $o['hold'] = trim((string)$m['hold_reason']) ?: 'on hold';
                }
                $o['unpaid_capital'] = Dec::max('0', Dec::sub(Dec::of($m['subscribed_capital'] ?? '0'), Dec::of($m['paid_capital']), 2));
                unset($o);
            }
        }
        return $out;
    }

    /** True when the member owes something past due or is on hold (obligations()) */
    public static function hasObligation(array $o): bool
    {
        return Dec::isPos($o['past_due'] ?? '0') || ($o['hold'] ?? '') !== '';
    }

    /**
     * Removes members from the list of a batch under review (the lines stay, marked removed with the reason), then
     * works the amounts of the others out again. Returns the number of members removed.
     */
    public static function removeLines(int $batchId, array $lineIds, string $reason, string $pageKey): int
    {
        return DB::tx(function () use ($batchId, $lineIds, $reason, $pageKey) {
            $b = self::batch($batchId, true);
            if (!$b || !in_array($b['status'], ['draft', 'validated'], true)) {
                fail('The batch is locked: it is ' . ($b['status'] ?? 'not found') . '.');
            }
            if (trim($reason) === '') {
                fail('Enter the reason the member is removed from the list.');
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $lineIds))));
            if (!$ids) {
                fail('Tick the members to remove from the list first.');
            }
            [$in, $ip] = DB::in('rl', $ids);
            $lines = DB::all("SELECT bl.*, m.full_name FROM dist_batch_lines bl JOIN members m ON m.id = bl.member_id WHERE bl.batch_id = :b AND bl.id IN $in AND bl.is_excluded = 0", ['b' => $batchId] + $ip);
            foreach ($lines as $l) {
                DB::update('dist_batch_lines', ['is_excluded' => 1, 'exclude_reason' => mb_substr($reason, 0, 160)], 'id = :id', ['id' => $l['id']]);
                Audit::log('exclude', 'dist', 'dist_batch', $batchId, $b['batch_no'] . ': ' . $l['full_name'], ['page_key' => $pageKey, 'reason' => $reason, 'amount' => (float)$l['net_amount']]);
            }
            if ($lines) {
                DB::update('dist_batches', ['status' => 'draft'], 'id = :id', ['id' => $batchId]);
                self::recalc($batchId);
            }
            return count($lines);
        });
    }

    /** True when a batch was never posted and can be deleted: in review, reviewed or approved, with no voucher */
    public static function deletable(array $b): bool
    {
        return in_array($b['status'], ['draft', 'validated', 'approved'], true) && empty($b['txn_id']);
    }

    /**
     * Deletes a computation that was never posted (in review, reviewed or approved and not yet posted): the batch and its
     * member lines go; the audit trail keeps them as they were, with the reason. The batch number is not used again.
     * A posted batch is reversed instead; a reversed one stays on file with its vouchers. An approved batch needs the
     * Approve right as well as the Delete right.
     */
    public static function deleteBatch(int $id, string $reason, string $pageKey): void
    {
        DB::tx(function () use ($id, $reason, $pageKey) {
            $b = self::batch($id, true);
            if (!$b) {
                fail('Batch not found.');
            }
            if ($b['status'] === 'posted') {
                fail('Batch ' . $b['batch_no'] . ' is posted: it is not deleted. Reverse it instead.');
            }
            if (!self::deletable($b)) {
                fail('Batch ' . $b['batch_no'] . ' was posted and reversed: it stays on file with its vouchers.');
            }
            if (!Access::can($pageKey, 'delete')) {
                fail('Deleting a distribution batch needs the Delete right on this page.');
            }
            if ($b['status'] === 'approved' && !Access::can($pageKey, 'approve')) {
                fail('Batch ' . $b['batch_no'] . ' is approved: deleting it needs the Approve right as well.');
            }
            $reason = trim(preg_replace('/\s+/', ' ', $reason));
            if ($reason === '') {
                fail('Enter the reason for deleting the batch.');
            }
            $lines = DB::all('SELECT bl.*, m.full_name, m.member_no FROM dist_batch_lines bl JOIN members m ON m.id = bl.member_id WHERE bl.batch_id = :b ORDER BY m.full_name', ['b' => $id]);
            if (array_filter($lines, fn($l) => !empty($l['sav_txn_id']) || !empty($l['paid_txn_id']))) {
                fail('Batch ' . $b['batch_no'] . ' has members already credited or paid: it is not deleted.');
            }
            DB::run('DELETE FROM dist_batch_lines WHERE batch_id = :b', ['b' => $id]);
            DB::run('DELETE FROM dist_batches WHERE id = :b', ['b' => $id]);
            // the batch and its member lines as they were; a large batch in parts of 1,000 lines, so no audit row
            // grows past what the server takes in one statement (max_allowed_packet is 1 MB on many installations)
            $rows = array_map(fn($l) => ['member' => $l['member_no'] . ' ' . $l['full_name'], 'basis' => $l['basis_amount'], 'computed' => $l['computed'],
                'adjustment' => $l['adjustment'], 'net' => $l['net_amount'], 'removed' => (int)$l['is_excluded'], 'reason' => $l['exclude_reason']], $lines);
            $parts = array_chunk($rows, 1000) ?: [[]];
            foreach ($parts as $i => $part) {
                $snap = ['lines' => $part];
                if ($i === 0) {
                    $snap = ['batch' => $b] + $snap;
                }
                if (count($parts) > 1) {
                    $snap['part'] = 'lines ' . ($i * 1000 + 1) . ' to ' . ($i * 1000 + count($part)) . ' of ' . count($rows);
                }
                Audit::log('delete', 'dist', 'dist_batch', $id, $b['batch_no'] . ' ' . strtoupper($b['kind']), ['page_key' => $pageKey, 'reason' => mb_substr($reason, 0, 255),
                    'amount' => $i === 0 ? (float)$b['total_amount'] : null, 'snapshot' => $snap]);
            }
        });
    }

    /** Validate Entries: every member has the account the credit mode needs; the pool of the year is not exceeded */
    public static function validate(int $id, string $pageKey): array
    {
        return DB::tx(function () use ($id, $pageKey) {
            $b = self::batch($id, true);
            if (!$b || !in_array($b['status'], ['draft', 'validated'], true)) {
                fail('Only a draft batch can be validated.');
            }
            $problems = [];
            foreach (DB::all('SELECT bl.*, m.full_name, m.member_no FROM dist_batch_lines bl JOIN members m ON m.id = bl.member_id WHERE bl.batch_id = :b AND bl.is_excluded = 0', ['b' => $id]) as $l) {
                if (Dec::isNeg($l['net_amount'])) {
                    $problems[] = $l['full_name'] . ': the net amount is negative.';
                }
                if ($b['credit_mode'] !== 'payable' && !self::creditAccountFor($b, (int)$l['member_id']) && $b['credit_mode'] !== 'auto') {
                    $problems[] = $l['full_name'] . ' (' . $l['member_no'] . ') has no ' . ($b['credit_mode'] === 'share_capital' ? 'share capital' : 'savings') . ' account to credit.';
                }
                if ($b['credit_mode'] === 'auto' && !self::creditAccountFor(['credit_mode' => 'savings'] + $b, (int)$l['member_id']) && !self::creditAccountFor(['credit_mode' => 'share_capital'] + $b, (int)$l['member_id'])) {
                    $problems[] = $l['full_name'] . ' (' . $l['member_no'] . ') has neither a share capital nor a savings account to credit.';
                }
            }
            if ($b['period_id']) {
                $p = DB::row('SELECT * FROM dist_periods WHERE id = :id', ['id' => $b['period_id']]);
                $pool = $b['kind'] === 'isc' ? $p['isc_amount'] : $p['pr_amount'];
                $used = Dec::of(DB::value("SELECT COALESCE(SUM(total_amount),0) FROM dist_batches WHERE period_id = :p AND kind = :k AND status IN ('approved','posted') AND id <> :id", ['p' => $b['period_id'], 'k' => $b['kind'], 'id' => $id]));
                if (Dec::cmp(Dec::add($used, $b['total_amount']), $pool) > 0) {
                    $problems[] = 'The batches of fiscal year ' . $p['fiscal_year'] . ' (' . Dec::money(Dec::add($used, $b['total_amount'])) . ') would exceed the ' . strtoupper($b['kind']) . ' set aside by its allocation (' . Dec::money($pool) . ').';
                }
            }
            if (!Dec::isPos($b['total_amount'])) {
                $problems[] = 'There is nothing to distribute.';
            }
            if (!$problems) {
                DB::update('dist_batches', ['status' => 'validated'], 'id = :id', ['id' => $id]);
                Audit::log('validate', 'dist', 'dist_batch', $id, $b['batch_no'], ['page_key' => $pageKey]);
            }
            return $problems;
        });
    }

    public static function approve(int $id, string $resolution, string $pageKey): void
    {
        DB::tx(function () use ($id, $resolution, $pageKey) {
            $b = self::batch($id, true);
            if (!$b || $b['status'] !== 'validated') {
                fail('Validate the batch first.');
            }
            if (!Access::can($pageKey, 'approve')) {
                fail('Approving needs the Approve right on this page.');
            }
            if ((int)$b['created_by'] === Auth::id() && !Access::isAdmin()) {
                fail('The batch is approved by someone other than the user who prepared it.');
            }
            if (trim($resolution) === '') {
                fail('Enter the Board / General Assembly resolution reference.');
            }
            DB::update('dist_batches', ['status' => 'approved', 'resolution_ref' => mb_substr($resolution, 0, 120), 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            Audit::log('approve', 'dist', 'dist_batch', $id, $b['batch_no'], ['page_key' => $pageKey, 'reason' => $resolution, 'amount' => (float)$b['total_amount']]);
        });
    }

    /** Posts an approved batch: one voucher, a credit for each member */
    public static function post(int $id, string $date, int $debitAccountId, string $pageKey): int
    {
        return DB::tx(function () use ($id, $date, $debitAccountId, $pageKey) {
            $b = self::batch($id, true);
            if (!$b || $b['status'] !== 'approved') {
                fail('Only an approved batch is posted, and only once.');
            }
            if ($b['txn_id']) {
                fail('The batch has already been posted.');
            }
            Coop::checkDate($date);
            $kindLabel = $b['kind'] === 'isc' ? 'Interest on share capital' : 'Patronage refund';
            $payable = Accts::get($b['kind'] === 'isc' ? 'isc_payable' : 'pr_payable');
            $debitAccountId = $debitAccountId ?: $payable;
            $lines = DB::all('SELECT bl.*, m.full_name, m.branch_id, m.subscribed_capital FROM dist_batch_lines bl JOIN members m ON m.id = bl.member_id WHERE bl.batch_id = :b AND bl.is_excluded = 0 AND bl.net_amount > 0 ORDER BY m.full_name', ['b' => $id]);
            $gl = [['account_id' => $debitAccountId, 'debit' => $b['total_amount'], 'memo' => $kindLabel . ' ' . $b['batch_no'] . ' (' . fdate($b['date_from']) . ' - ' . fdate($b['date_to']) . ')']];
            $moves = [];
            foreach ($lines as $l) {
                $amt = Dec::of($l['net_amount']);
                $parts = [];
                if ($b['credit_mode'] === 'payable') {
                    $gl[] = ['account_id' => $payable, 'credit' => $amt, 'memo' => $kindLabel . ' ' . $b['batch_no'], 'member_id' => $l['member_id']];
                    continue;
                }
                if ($b['credit_mode'] === 'auto') {
                    $sc = self::creditAccountFor(['credit_mode' => 'share_capital'] + $b, (int)$l['member_id']);
                    $paid = $sc ? Dec::of(DB::value('SELECT balance FROM sav_accounts WHERE id = :a', ['a' => $sc])) : '0';
                    $unpaid = Dec::max('0', Dec::sub($l['subscribed_capital'], $paid));
                    $toSc = $sc ? Dec::min($amt, $unpaid) : '0';
                    if (Dec::isPos($toSc)) {
                        $parts[] = [$sc, $toSc];
                    }
                    $rest = Dec::sub($amt, $toSc, 2);
                    if (Dec::isPos($rest)) {
                        $sv = self::creditAccountFor(['credit_mode' => 'savings'] + $b, (int)$l['member_id']) ?: $sc;
                        if (!$sv) {
                            fail($l['full_name'] . ' has no account to credit.');
                        }
                        $parts[] = [$sv, $rest];
                    }
                } else {
                    $acct = self::creditAccountFor($b, (int)$l['member_id']);
                    if (!$acct) {
                        fail($l['full_name'] . ' has no account to credit. Validate the batch again.');
                    }
                    $parts[] = [$acct, $amt];
                }
                foreach ($parts as [$aid, $v]) {
                    $a = Savings::validate($aid, 'credit', $v, $date, ['is_system' => true]);
                    $gl[] = ['account_id' => Savings::ledgerAccount($a), 'credit' => $v, 'memo' => $kindLabel . ' ' . $b['batch_no'] . ' ' . $a['account_no'], 'member_id' => $l['member_id'],
                        'class_id' => Coop::branchClassId($l['branch_id'] ? (int)$l['branch_id'] : null)];
                    $moves[] = [$l['id'], $aid, $v];
                }
            }
            $txnId = CoopLedger::post('coop_jv', ['txn_date' => $date, 'memo' => $kindLabel . ' ' . $b['batch_no'] . ($b['resolution_ref'] ? ' (' . $b['resolution_ref'] . ')' : ''), 'ref_no' => $b['batch_no']], $gl);
            foreach ($moves as [$lineId, $aid, $v]) {
                $a = Savings::account($aid, true);
                $sid = Savings::move($a, $b['kind'] === 'isc' ? 'ISC' : 'PR', 'credit', $v, $date, ['txn_id' => $txnId, 'description' => $kindLabel . ' ' . substr($b['date_to'], 0, 4), 'ref_type' => 'dist', 'ref_id' => $id, 'is_system' => true]);
                DB::update('dist_batch_lines', ['sav_txn_id' => $sid, 'sav_account_id' => $aid, 'paid_txn_id' => $txnId, 'paid_on' => $date], 'id = :id', ['id' => $lineId]);
            }
            DB::update('dist_batches', ['status' => 'posted', 'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => Auth::id(), 'txn_id' => $txnId], 'id = :id', ['id' => $id]);
            Audit::log('post', 'dist', 'dist_batch', $id, $b['batch_no'], ['page_key' => $pageKey, 'amount' => (float)$b['total_amount'], 'reason' => $b['resolution_ref']]);
            return $txnId;
        });
    }

    /** A posted batch is corrected only by an adjusting entry that reverses it line for line, member ledgers included */
    public static function reverse(int $id, string $date, string $reason, string $pageKey): int
    {
        return DB::tx(function () use ($id, $date, $reason, $pageKey) {
            $b = self::batch($id, true);
            if (!$b || $b['status'] !== 'posted') {
                fail('The batch is not posted.');
            }
            if (!Access::can($pageKey, 'approve')) {
                fail('Reversing a distribution needs the Approve right.');
            }
            if (trim($reason) === '') {
                fail('Enter the reason for the reversal.');
            }
            Coop::checkDate($date);
            if ($b['credit_mode'] === 'payable' && DB::value('SELECT id FROM dist_batch_lines WHERE batch_id = :b AND paid_txn_id IS NOT NULL AND paid_txn_id <> :t', ['b' => $id, 't' => $b['txn_id']])) {
                fail('Some members have already been paid. Reverse those payments first.');
            }
            // the member accounts first: each credit comes back out (the account must still hold it)
            foreach (DB::all('SELECT * FROM dist_batch_lines WHERE batch_id = :b AND sav_txn_id IS NOT NULL', ['b' => $id]) as $l) {
                $st = DB::row('SELECT * FROM sav_txns WHERE id = :id', ['id' => $l['sav_txn_id']]);
                $a = Savings::validate((int)$st['account_id'], 'debit', $st['credit'], $date, ['is_system' => true, 'closing' => true]);
                $after[] = [$a, $st];
            }
            $txnId = CoopLedger::reverse((int)$b['txn_id'], $date, $reason);
            foreach ($after ?? [] as [$a, $st]) {
                $a = Savings::account((int)$a['id'], true);
                Savings::move($a, 'DM', 'debit', $st['credit'], $date, ['txn_id' => $txnId, 'description' => 'Reversal of ' . $b['batch_no'], 'ref_type' => 'dist', 'ref_id' => $id, 'is_system' => true, 'skip_available' => true]);
            }
            DB::update('dist_batches', ['status' => 'cancelled', 'memo' => mb_substr(($b['memo'] ? $b['memo'] . ' | ' : '') . 'Reversed ' . fdate($date) . ': ' . $reason, 0, 255)], 'id = :id', ['id' => $id]);
            Audit::log('reverse', 'dist', 'dist_batch', $id, $b['batch_no'], ['page_key' => $pageKey, 'reason' => $reason, 'amount' => (float)$b['total_amount']]);
            return $txnId;
        });
    }
}
