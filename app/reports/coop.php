<?php
declare(strict_types=1);

/**
 * Cooperative reports: members and capital, financial statements in the cooperative format, statutory funds,
 * subsidiary vs general ledger, document prints, teller reports, year-end distribution and CDA compliance.
 * Savings / time deposit reports are in coop_savings.php, loan reports in coop_loans.php.
 * Every report follows the user's branch scope (a user tied to a branch sees that branch only).
 */

function crpt_ready(): void
{
    if (!Coop::ready()) {
        fail('The cooperative module is not installed (run database/migrations/2026_11_01_cooperative.sql).');
    }
}

/** The branch scope of the user plus the Branch filter of the report, on a column */
function crpt_branch_sql(array $f, string $col, array &$p, string $tag = 'rb'): string
{
    [$bf, $bp] = Coop::branchFilter($col, $tag . 'u');
    $p += $bp;
    if (($f['branch'] ?? '') !== '') {
        $bf .= " AND $col = :{$tag}f";
        $p[$tag . 'f'] = (int)$f['branch'];
    }
    return $bf;
}

function crpt_branches(): array
{
    return Coop::ready() ? Coop::branchOptions(false) : [];
}

function crpt_sav_products(?array $types = null): array
{
    return Coop::ready() ? Savings::productOptions($types, false) : [];
}

function crpt_loan_products(): array
{
    return Coop::ready() ? Loans::productOptions(false) : [];
}

function crpt_sum(array $rows, string $key): string
{
    $s = '0';
    foreach ($rows as $r) {
        if (isset($r[$key]) && is_numeric($r[$key]) && empty($r['_class'])) {
            $s = Dec::add($s, $r[$key], 2);
        }
    }
    return Dec::fix($s);
}

/** Totals row for some money columns */
function crpt_totals(array $rows, array $keys, string $labelKey, string $label = 'TOTAL'): array
{
    $t = [$labelKey => $label . ' (' . count(array_filter($rows, fn($r) => empty($r['_class']))) . ')', '_class' => 'total-row'];
    foreach ($keys as $k) {
        $t[$k] = crpt_sum($rows, $k);
    }
    return $t;
}

/** GL balance (debit - credit) of accounts through a date, or over a range when $from is given */
function crpt_gl(array $accountIds, string $to, ?string $from = null): array
{
    if (!$accountIds) {
        return [];
    }
    [$in, $p] = DB::in('ga', array_map('intval', $accountIds));
    $sql = "SELECT account_id, COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id IN $in AND txn_date <= :t";
    $p['t'] = $to;
    if ($from) {
        $sql .= ' AND txn_date >= :f';
        $p['f'] = $from;
    }
    return DB::pairs($sql . ' GROUP BY account_id', $p);
}

// ============================================================================ members and capital

Report::define('coop_members', [
    'filters' => ['search'],
    'extra_filters' => ['status' => ['Status', fn() => Coop::MEMBER_STATUSES], 'mtype' => ['Member type', fn() => Coop::ready() ? Members::typeOptions(false) : []],
        'branch' => ['Branch', fn() => crpt_branches()], 'cluster' => ['Cluster', fn() => Coop::ready() ? Members::clusters() : []]],
    'run' => function (array $f): array {
        crpt_ready();
        $p = [];
        $sql = "SELECT m.*, t.name AS type_name, b.name AS branch_name,
                       (SELECT COALESCE(SUM(a.balance),0) FROM sav_accounts a JOIN sav_products sp ON sp.id = a.product_id WHERE a.member_id = m.id AND sp.product_type = 'share_capital' AND a.status <> 'closed') AS paid_up,
                       (SELECT COALESCE(SUM(a.balance),0) FROM sav_accounts a JOIN sav_products sp ON sp.id = a.product_id WHERE a.member_id = m.id AND sp.product_type IN ('ordinary','special','time_deposit') AND a.status <> 'closed') AS deposits,
                       (SELECT COALESCE(SUM(l.principal_balance),0) FROM loan_accounts l WHERE l.member_id = m.id AND l.status IN ('current','past_due','restructured')) AS loans
                  FROM members m LEFT JOIN member_types t ON t.id = m.member_type_id LEFT JOIN coop_branches b ON b.id = m.branch_id WHERE 1 = 1" . crpt_branch_sql($f, 'm.branch_id', $p);
        if ($f['status'] !== '') {
            $sql .= ' AND m.status = :st';
            $p['st'] = $f['status'];
        }
        if ($f['mtype'] !== '') {
            $sql .= ' AND m.member_type_id = :mt';
            $p['mt'] = (int)$f['mtype'];
        }
        if ($f['cluster'] !== '') {
            $sql .= ' AND m.cluster_id = :cl';
            $p['cl'] = (int)$f['cluster'];
        }
        if (trim((string)$f['q']) !== '') {
            $sql .= ' AND (m.full_name LIKE :q1 OR m.member_no = :q2 OR m.mobile LIKE :q3)';
            $p += ['q1' => '%' . trim($f['q']) . '%', 'q2' => trim($f['q']), 'q3' => '%' . trim($f['q']) . '%'];
        }
        $rows = [];
        // contact details and age are confidential: shown to users with the coop_view_confidential permission
        $conf = !Party::ready() || Party::canSeeConfidential();
        foreach (DB::all($sql . ' ORDER BY m.full_name LIMIT 20000', $p) as $m) {
            $rows[] = ['no' => $m['member_no'], 'name' => $m['full_name'], 'type' => $m['type_name'], 'status' => Coop::MEMBER_STATUSES[$m['status']] ?? $m['status'], 'since' => $m['membership_date'],
                'gender' => $m['gender'], 'age' => $conf ? Members::age($m['birth_date']) : null, 'mobile' => $conf || !$m['mobile'] ? $m['mobile'] : Party::mask((string)$m['mobile']),
                'address' => $conf ? Members::address($m) : '', 'branch' => $m['branch_name'],
                'subscribed' => Dec::fix($m['subscribed_capital']), 'paid_up' => Dec::fix($m['paid_up']), 'deposits' => Dec::fix($m['deposits']), 'loans' => Dec::fix($m['loans']),
                '_links' => ['name' => Report::link('coop.member', ['id' => $m['id']])]];
        }
        return ['columns' => [['key' => 'no', 'label' => 'Member no.'], ['key' => 'name', 'label' => 'Name'], ['key' => 'type', 'label' => 'Type'], ['key' => 'status', 'label' => 'Status'],
            ['key' => 'since', 'label' => 'Member since', 'type' => 'date'], ['key' => 'gender', 'label' => 'Sex', 'optional' => true], ['key' => 'age', 'label' => 'Age', 'type' => 'int', 'optional' => true],
            ['key' => 'mobile', 'label' => 'Mobile'], ['key' => 'address', 'label' => 'Address', 'optional' => true], ['key' => 'branch', 'label' => 'Branch'],
            ['key' => 'subscribed', 'label' => 'Subscribed capital', 'type' => 'money'], ['key' => 'paid_up', 'label' => 'Paid-up capital', 'type' => 'money'],
            ['key' => 'deposits', 'label' => 'Savings and deposits', 'type' => 'money'], ['key' => 'loans', 'label' => 'Loans outstanding', 'type' => 'money']],
            'rows' => $rows, 'totals' => crpt_totals($rows, ['subscribed', 'paid_up', 'deposits', 'loans'], 'no')];
    },
]);

Report::define('coop_member_movement', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'extra_filters' => ['branch' => ['Branch', fn() => crpt_branches()]],
    'sortable' => false,
    'run' => function (array $f): array {
        crpt_ready();
        $from = $f['from'];
        $to = $f['to'];
        $p = [];
        $bf = crpt_branch_sql($f, 'm.branch_id', $p);
        // a member counts on a date when admitted on or before it and not separated by then
        $count = function (string $d) use ($bf, $p): array {
            return DB::pairs("SELECT COALESCE(t.name, 'No type'), COUNT(*) FROM members m LEFT JOIN member_types t ON t.id = m.member_type_id
                               WHERE m.membership_date IS NOT NULL AND m.membership_date <= :d AND NOT EXISTS (
                                   SELECT 1 FROM member_status_history h WHERE h.member_id = m.id AND h.new_status IN ('resigned','terminated','deceased') AND h.changed_on <= :d2
                                     AND NOT EXISTS (SELECT 1 FROM member_status_history h2 WHERE h2.member_id = m.id AND h2.new_status = 'active' AND h2.changed_on > h.changed_on AND h2.changed_on <= :d3))" . $bf
                . ' GROUP BY COALESCE(t.name, \'No type\')', ['d' => $d, 'd2' => $d, 'd3' => $d] + $p);
        };
        $open = $count(Coop::addDays($from, -1));
        $close = $count($to);
        $admitted = DB::pairs("SELECT COALESCE(t.name, 'No type'), COUNT(*) FROM members m LEFT JOIN member_types t ON t.id = m.member_type_id WHERE m.membership_date BETWEEN :f AND :t" . $bf . " GROUP BY COALESCE(t.name, 'No type')", ['f' => $from, 't' => $to] + $p);
        $sep = DB::all("SELECT h.new_status, COALESCE(t.name, 'No type') AS tn, COUNT(DISTINCT h.member_id) AS n FROM member_status_history h JOIN members m ON m.id = h.member_id LEFT JOIN member_types t ON t.id = m.member_type_id
                         WHERE h.new_status IN ('resigned','terminated','deceased') AND h.changed_on BETWEEN :f AND :t" . $bf . " GROUP BY h.new_status, COALESCE(t.name, 'No type')", ['f' => $from, 't' => $to] + $p);
        $types = array_unique(array_merge(array_keys($open), array_keys($close), array_keys($admitted), array_column($sep, 'tn')));
        sort($types);
        $rows = [];
        $tot = ['open' => 0, 'admitted' => 0, 'resigned' => 0, 'terminated' => 0, 'deceased' => 0, 'close' => 0];
        foreach ($types as $tn) {
            $r = ['type' => $tn, 'open' => (int)($open[$tn] ?? 0), 'admitted' => (int)($admitted[$tn] ?? 0), 'resigned' => 0, 'terminated' => 0, 'deceased' => 0, 'close' => (int)($close[$tn] ?? 0)];
            foreach ($sep as $s) {
                if ($s['tn'] === $tn) {
                    $r[$s['new_status']] = (int)$s['n'];
                }
            }
            foreach ($tot as $k => $_) {
                $tot[$k] += $r[$k];
            }
            $rows[] = $r;
        }
        $pa = [];
        $pb = [];
        $bfa = crpt_branch_sql($f, 'm.branch_id', $pa, 'ma');
        $bfb = crpt_branch_sql($f, 'm.branch_id', $pb, 'mb');
        $detail = DB::all("SELECT x.* FROM (
                              SELECT m.id, m.member_no, m.full_name, m.membership_date AS d, 'Admitted' AS what, '' AS reason FROM members m WHERE m.membership_date BETWEEN :f AND :t" . $bfa . "
                              UNION ALL
                              SELECT m.id, m.member_no, m.full_name, h.changed_on, CONCAT(UPPER(SUBSTRING(h.new_status,1,1)), SUBSTRING(h.new_status,2)), COALESCE(h.reason, '') FROM member_status_history h JOIN members m ON m.id = h.member_id
                               WHERE h.new_status IN ('resigned','terminated','deceased','suspended') AND h.changed_on BETWEEN :f2 AND :t2" . $bfb . "
                           ) x ORDER BY x.d, x.full_name", ['f' => $from, 't' => $to, 'f2' => $from, 't2' => $to] + $pa + $pb);
        $rows[] = ['type' => 'TOTAL', '_class' => 'total-row'] + $tot;
        if ($detail) {
            $rows[] = ['type' => 'Movements in the period', '_class' => 'group-row'];
            foreach ($detail as $d) {
                $rows[] = ['type' => fdate($d['d']) . '  ' . $d['what'] . ': ' . $d['full_name'] . ' (' . $d['member_no'] . ')' . ($d['reason'] !== '' ? ', ' . $d['reason'] : ''), '_class' => 'indent-1',
                    '_links' => ['type' => Report::link('coop.member', ['id' => $d['id']])]];
            }
        }
        return ['grouped' => true, 'columns' => [['key' => 'type', 'label' => 'Member type'], ['key' => 'open', 'label' => 'Beginning', 'type' => 'int'], ['key' => 'admitted', 'label' => 'Admitted', 'type' => 'int'],
            ['key' => 'resigned', 'label' => 'Resigned', 'type' => 'int'], ['key' => 'terminated', 'label' => 'Terminated', 'type' => 'int'], ['key' => 'deceased', 'label' => 'Deceased', 'type' => 'int'],
            ['key' => 'close', 'label' => 'Ending', 'type' => 'int']], 'rows' => $rows,
            'notes' => 'Members are counted from their membership date until they resign, are terminated or die (reinstatement counts them again).'];
    },
]);

Report::define('coop_share_capital', [
    'filters' => ['asof'],
    'extra_filters' => ['product' => ['Product', fn() => crpt_sav_products(['share_capital'])], 'branch' => ['Branch', fn() => crpt_branches()], 'mtype' => ['Member type', fn() => Coop::ready() ? Members::typeOptions(false) : []]],
    'run' => function (array $f): array {
        crpt_ready();
        $p = ['d' => $f['asof']];
        $sql = "SELECT a.id, a.account_no, a.member_id, m.member_no, m.full_name, m.subscribed_shares, m.par_value, m.subscribed_capital, p.name AS product_name,
                       (SELECT COALESCE(SUM(t.credit - t.debit),0) FROM sav_txns t WHERE t.account_id = a.id AND t.is_voided = 0 AND t.txn_date <= :d) AS paid
                  FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id JOIN members m ON m.id = a.member_id
                 WHERE p.product_type = 'share_capital' AND (a.opened_on IS NULL OR a.opened_on <= :d2)" . crpt_branch_sql($f, 'a.branch_id', $p);
        $p['d2'] = $f['asof'];
        if ($f['product'] !== '') {
            $sql .= ' AND a.product_id = :pr';
            $p['pr'] = (int)$f['product'];
        }
        if ($f['mtype'] !== '') {
            $sql .= ' AND m.member_type_id = :mt';
            $p['mt'] = (int)$f['mtype'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY m.full_name', $p) as $r) {
            if (Dec::isZero($r['paid']) && !Dec::isPos($r['subscribed_capital'])) {
                continue;
            }
            $unpaid = Dec::max('0', Dec::sub($r['subscribed_capital'], $r['paid'], 2));
            $rows[] = ['member' => $r['full_name'], 'no' => $r['member_no'], 'account' => $r['account_no'], 'product' => $r['product_name'],
                'shares' => Dec::isPos($r['par_value']) ? Dec::fix(Dec::div($r['paid'], $r['par_value'], 4), 2) : '', 'subscribed' => Dec::fix($r['subscribed_capital']), 'paid' => Dec::fix($r['paid']),
                'unpaid' => Dec::fix($unpaid), 'pct' => Dec::isPos($r['subscribed_capital']) ? Dec::fix(Dec::div(Dec::mul($r['paid'], '100', 6), $r['subscribed_capital'], 6), 2) : '',
                '_links' => ['member' => Report::link('coop.member', ['id' => $r['member_id']]), 'account' => Report::link('sav.accounts', ['id' => $r['id']])]];
        }
        return ['columns' => [['key' => 'member', 'label' => 'Member'], ['key' => 'no', 'label' => 'Member no.'], ['key' => 'account', 'label' => 'Account'], ['key' => 'product', 'label' => 'Product', 'optional' => true],
            ['key' => 'shares', 'label' => 'Paid-up shares', 'type' => 'qty'], ['key' => 'subscribed', 'label' => 'Subscribed', 'type' => 'money'], ['key' => 'paid', 'label' => 'Paid-up', 'type' => 'money'],
            ['key' => 'unpaid', 'label' => 'Unpaid subscription', 'type' => 'money'], ['key' => 'pct', 'label' => 'Paid', 'type' => 'pct']],
            'rows' => $rows, 'totals' => crpt_totals($rows, ['subscribed', 'paid', 'unpaid'], 'member')];
    },
]);

Report::define('coop_cbu', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'extra_filters' => ['product' => ['Product', fn() => crpt_sav_products(['share_capital', 'special'])], 'branch' => ['Branch', fn() => crpt_branches()]],
    'run' => function (array $f): array {
        crpt_ready();
        $p = ['f' => $f['from'], 'f2' => $f['from'], 't' => $f['to'], 'f3' => $f['from'], 't3' => $f['to']];
        $sql = "SELECT a.id, a.account_no, a.member_id, m.full_name, m.member_no, p.name AS product_name,
                       (SELECT COALESCE(SUM(t.credit - t.debit),0) FROM sav_txns t WHERE t.account_id = a.id AND t.is_voided = 0 AND t.txn_date < :f) AS beg,
                       (SELECT COALESCE(SUM(t.credit),0) FROM sav_txns t WHERE t.account_id = a.id AND t.is_voided = 0 AND t.txn_date BETWEEN :f2 AND :t) AS added,
                       (SELECT COALESCE(SUM(t.debit),0) FROM sav_txns t WHERE t.account_id = a.id AND t.is_voided = 0 AND t.txn_date BETWEEN :f3 AND :t3) AS withdrawn
                  FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id JOIN members m ON m.id = a.member_id WHERE 1 = 1" . crpt_branch_sql($f, 'a.branch_id', $p);
        if ($f['product'] !== '') {
            $sql .= ' AND a.product_id = :pr';
            $p['pr'] = (int)$f['product'];
        } else {
            $sql .= " AND p.product_type = 'share_capital'";
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY m.full_name', $p) as $r) {
            if (Dec::isZero($r['beg']) && Dec::isZero($r['added']) && Dec::isZero($r['withdrawn'])) {
                continue;
            }
            $rows[] = ['member' => $r['full_name'], 'no' => $r['member_no'], 'account' => $r['account_no'], 'beg' => Dec::fix($r['beg']), 'added' => Dec::fix($r['added']), 'withdrawn' => Dec::fix($r['withdrawn']),
                'end' => Dec::fix(Dec::sub(Dec::add($r['beg'], $r['added'], 2), $r['withdrawn'], 2)), '_links' => ['account' => Report::link('sav.accounts', ['id' => $r['id']]), 'member' => Report::link('coop.member', ['id' => $r['member_id']])]];
        }
        return ['columns' => [['key' => 'member', 'label' => 'Member'], ['key' => 'no', 'label' => 'Member no.'], ['key' => 'account', 'label' => 'Account'], ['key' => 'beg', 'label' => 'Beginning', 'type' => 'money'],
            ['key' => 'added', 'label' => 'Build-up', 'type' => 'money'], ['key' => 'withdrawn', 'label' => 'Withdrawn / applied', 'type' => 'money'], ['key' => 'end', 'label' => 'Ending', 'type' => 'money']],
            'rows' => $rows, 'totals' => crpt_totals($rows, ['beg', 'added', 'withdrawn', 'end'], 'member'),
            'notes' => 'Capital build-up: paid-up share capital (or the product chosen) received during the period, from deposits, loan deductions, payroll batches and distributions credited to capital.'];
    },
]);

/**
 * The cooperative statement of financial condition: GL accounts in the cooperative headings. The headings come from
 * the accounts the cooperative products and defaults post to; anything else falls under its account type.
 */
function crpt_sfc_rows(string $asof): array
{
    $fy = Report::fiscalYearStart($asof);
    $accounts = DB::all('SELECT id, code, name, account_type, category, normal_balance FROM accounts ORDER BY code');
    // one line of business, or the whole cooperative with the balances between its lines eliminated
    [$bsql, $bp] = BusinessUnits::sqlFilter('', 'sfcbu');
    $bal = BusinessUnits::eliminate(DB::pairs('SELECT account_id, COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE txn_date <= :t' . $bsql . ' GROUP BY account_id', ['t' => $asof] + $bp));
    $acct = fn(string $k) => (function () use ($k) {
        try {
            return Accts::get($k);
        } catch (Throwable $e) {
            return 0;
        }
    })();
    $loanAccts = array_map('intval', DB::column('SELECT DISTINCT receivable_account_id FROM loan_products WHERE receivable_account_id IS NOT NULL'));
    foreach (['loans_receivable', 'loans_past_due', 'loans_restructured', 'loan_loss_allowance', 'loan_interest_receivable'] as $k) {
        $loanAccts[] = $acct($k);
    }
    $depAccts = [];
    $capAccts = [];
    foreach (DB::all('SELECT * FROM sav_products') as $p) {
        if ($p['product_type'] === 'share_capital') {
            $capAccts[] = Savings::liabilityAccount($p);
        } else {
            $depAccts[] = Savings::liabilityAccount($p);
        }
    }
    $depAccts[] = $acct('deposit_interest_payable');
    $fundAccts = array_filter([$acct('reserve_fund'), $acct('education_fund'), $acct('cdf_fund'), $acct('optional_fund')]);
    $distAccts = array_filter([$acct('isc_payable'), $acct('pr_payable'), $acct('education_fund_apex'), $acct('unclaimed_dist')]);
    $heading = function (array $a) use ($loanAccts, $depAccts, $capAccts, $fundAccts, $distAccts): array {
        $id = (int)$a['id'];
        if ($a['category'] === 'Asset') {
            if ($a['account_type'] === 'Bank') {
                return ['ASSETS', '1 Cash and cash equivalents'];
            }
            if (in_array($id, $loanAccts, true)) {
                return ['ASSETS', '2 Loans and interest receivable, net'];
            }
            if (in_array($a['account_type'], ['Accounts Receivable', 'Other Current Asset'], true)) {
                return ['ASSETS', '3 Other receivables and current assets'];
            }
            if ($a['account_type'] === 'Fixed Asset') {
                return ['ASSETS', '4 Property and equipment, net'];
            }
            return ['ASSETS', '5 Other non-current assets'];
        }
        if ($a['category'] === 'Liability') {
            if (in_array($id, $depAccts, true)) {
                return ['LIABILITIES', '1 Deposit liabilities'];
            }
            if (in_array($id, $distAccts, true)) {
                return ['LIABILITIES', '2 Interest on share capital, patronage refund and other dues to members'];
            }
            if ($a['account_type'] === 'Long Term Liability') {
                return ['LIABILITIES', '4 Non-current liabilities'];
            }
            return ['LIABILITIES', '3 Accounts and other payables'];
        }
        if (in_array($id, $capAccts, true)) {
            return ['MEMBERS\' EQUITY', '1 Paid-up share capital'];
        }
        if (in_array($id, $fundAccts, true)) {
            return ['MEMBERS\' EQUITY', '3 Statutory funds'];
        }
        return ['MEMBERS\' EQUITY', '2 Undivided net surplus and other equity'];
    };
    $groups = [];
    foreach ($accounts as $a) {
        if (!in_array($a['category'], ['Asset', 'Liability', 'Equity'], true)) {
            continue;
        }
        $v = Dec::of($bal[$a['id']] ?? '0');
        if (Dec::isZero($v)) {
            continue;
        }
        [$sec, $head] = $heading($a);
        $amt = $a['category'] === 'Asset' ? $v : Dec::neg($v);
        $groups[$sec][$head][] = ['name' => $a['code'] . ' ' . $a['name'], 'amount' => Dec::fix($amt), 'id' => (int)$a['id']];
    }
    // the surplus not yet closed to equity: prior years, and this fiscal year to date
    $ni = fn(?string $from, string $to) => Dec::of(DB::value("SELECT COALESCE(SUM(j.credit - j.debit),0) FROM journal_lines j JOIN accounts a ON a.id = j.account_id WHERE a.category IN ('Income','Expense') AND j.txn_date <= :t" . ($from ? ' AND j.txn_date >= :f' : ''),
        ['t' => $to] + ($from ? ['f' => $from] : [])));
    $prior = $ni(null, Coop::addDays($fy, -1));
    $ytd = $ni($fy, $asof);
    if (!Dec::isZero($prior)) {
        $groups['MEMBERS\' EQUITY']['2 Undivided net surplus and other equity'][] = ['name' => 'Net surplus of prior years not yet allocated', 'amount' => Dec::fix($prior), 'id' => 0];
    }
    $groups['MEMBERS\' EQUITY']['4 Net surplus for the year to date'][] = ['name' => 'Net surplus, ' . fdate($fy) . ' to ' . fdate($asof), 'amount' => Dec::fix($ytd), 'id' => 0];
    $rows = [];
    $totals = [];
    foreach (['ASSETS', 'LIABILITIES', 'MEMBERS\' EQUITY'] as $sec) {
        $rows[] = ['name' => $sec, '_class' => 'group-row'];
        $secTotal = '0';
        $heads = $groups[$sec] ?? [];
        ksort($heads);
        foreach ($heads as $head => $lines) {
            $label = substr($head, 2);
            $rows[] = ['name' => $label, '_class' => 'group-row indent-1'];
            $sub = '0';
            foreach ($lines as $l) {
                $rows[] = ['name' => $l['name'], 'amount' => $l['amount'], '_class' => 'indent-2', '_links' => $l['id'] ? ['name' => Report::link('rpt.general_ledger', ['account_id' => $l['id'], 'from' => '2000-01-01', 'to' => $asof, 'dates' => 'custom'])] : []];
                $sub = Dec::add($sub, $l['amount'], 2);
            }
            $rows[] = ['name' => 'Total ' . mb_strtolower($label), 'amount' => Dec::fix($sub), '_class' => 'subtotal-row indent-1'];
            $secTotal = Dec::add($secTotal, $sub, 2);
        }
        $rows[] = ['name' => 'TOTAL ' . $sec, 'amount' => Dec::fix($secTotal), '_class' => 'total-row'];
        $totals[$sec] = $secTotal;
    }
    $le = Dec::add($totals['LIABILITIES'], $totals['MEMBERS\' EQUITY'], 2);
    $rows[] = ['name' => 'TOTAL LIABILITIES AND MEMBERS\' EQUITY', 'amount' => Dec::fix($le), '_class' => 'total-row'];
    return [$rows, Dec::cmp($totals['ASSETS'], $le) === 0];
}

Report::define('coop_sfc', [
    'filters' => ['asof', 'unit'],
    'sortable' => false,
    'run' => function (array $f): array {
        crpt_ready();
        [$rows, $balanced] = crpt_sfc_rows($f['asof']);
        return ['grouped' => true, 'columns' => [['key' => 'name', 'label' => ''], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']], 'rows' => $rows,
            'notes' => BusinessUnits::reportNote() . ($balanced ? 'Total assets equal total liabilities and members\' equity. ' : '<strong class="text-danger">Assets do not equal liabilities and equity.</strong> ')
                . 'Statement of Financial Condition in the cooperative format. Loans are shown with their allowance and the interest receivable; the surplus of the year stays in equity until the General Assembly allocates it.'];
    },
]);

Report::define('coop_sop', [
    'filters' => ['range', 'unit'],
    'default_dates' => 'year_to_date',
    'sortable' => false,
    'run' => function (array $f): array {
        crpt_ready();
        $acct = function (string $k): int {
            try {
                return Accts::get($k);
            } catch (Throwable $e) {
                return 0;
            }
        };
        $credit = array_map('intval', array_merge(DB::column('SELECT DISTINCT interest_account_id FROM loan_products WHERE interest_account_id IS NOT NULL'),
            DB::column('SELECT DISTINCT penalty_account_id FROM loan_products WHERE penalty_account_id IS NOT NULL'), DB::column("SELECT DISTINCT account_id FROM loan_charges WHERE charge_type = 'financial' AND account_id IS NOT NULL"),
            [$acct('loan_interest_income'), $acct('loan_penalty_income'), $acct('loan_service_fee')]));
        $finCost = [$acct('savings_interest_expense'), $acct('td_interest_expense')];
        [$bsql, $bp] = BusinessUnits::sqlFilter('j', 'sopbu');
        $act = DB::all("SELECT a.id, a.code, a.name, a.account_type, a.category, COALESCE(SUM(j.credit - j.debit),0) AS net FROM journal_lines j JOIN accounts a ON a.id = j.account_id
                         WHERE a.category IN ('Income','Expense') AND j.txn_date BETWEEN :f AND :t$bsql GROUP BY a.id, a.code, a.name, a.account_type, a.category ORDER BY a.code", ['f' => $f['from'], 't' => $f['to']] + $bp);
        if (BusinessUnits::eliminating()) {
            // charges between lines of business net out for the whole cooperative
            $nets = BusinessUnits::eliminate(array_column($act, 'net', 'id'));
            foreach ($act as $i => $a) {
                $act[$i]['net'] = (string)($nets[(int)$a['id']] ?? '0');
            }
        }
        $sections = ['1 Revenues from credit operations' => [], '2 Other revenues' => [], '3 Financing cost' => [], '4 Cost of sales and services' => [], '5 Operating expenses' => [], '6 Other expenses' => []];
        foreach ($act as $a) {
            if (Dec::isZero($a['net'])) {
                continue;
            }
            $id = (int)$a['id'];
            if ($a['category'] === 'Income') {
                $k = in_array($id, $credit, true) ? '1 Revenues from credit operations' : '2 Other revenues';
                $amt = Dec::of($a['net']);
            } else {
                $k = in_array($id, $finCost, true) ? '3 Financing cost' : ($a['account_type'] === 'Cost of Goods Sold' ? '4 Cost of sales and services' : ($a['account_type'] === 'Other Expense' ? '6 Other expenses' : '5 Operating expenses'));
                $amt = Dec::neg($a['net']);
            }
            $sections[$k][] = ['name' => $a['code'] . ' ' . $a['name'], 'amount' => Dec::fix($amt), 'id' => $id];
        }
        $rows = [];
        $t = [];
        foreach ($sections as $k => $lines) {
            if (!$lines) {
                $t[$k] = '0';
                continue;
            }
            $rows[] = ['name' => substr($k, 2), '_class' => 'group-row'];
            $sub = '0';
            foreach ($lines as $l) {
                $rows[] = ['name' => $l['name'], 'amount' => $l['amount'], '_class' => 'indent-1', '_links' => ['name' => Report::link('rpt.general_ledger', ['account_id' => $l['id'], 'from' => $f['from'], 'to' => $f['to'], 'dates' => 'custom'])]];
                $sub = Dec::add($sub, $l['amount'], 2);
            }
            $rows[] = ['name' => 'Total ' . mb_strtolower(substr($k, 2)), 'amount' => Dec::fix($sub), '_class' => 'subtotal-row'];
            $t[$k] = $sub;
        }
        $revenue = Dec::add($t['1 Revenues from credit operations'], $t['2 Other revenues'], 2);
        $expenses = Dec::sum([$t['3 Financing cost'], $t['4 Cost of sales and services'], $t['5 Operating expenses'], $t['6 Other expenses']]);
        $ns = Dec::sub($revenue, $expenses, 2);
        $rows[] = ['name' => 'TOTAL REVENUES', 'amount' => Dec::fix($revenue), '_class' => 'total-row'];
        $rows[] = ['name' => 'TOTAL EXPENSES', 'amount' => Dec::fix($expenses), '_class' => 'total-row'];
        $rows[] = ['name' => Dec::isNeg($ns) ? 'NET LOSS' : 'NET SURPLUS', 'amount' => Dec::fix($ns), '_class' => 'total-row'];
        if (Dec::isPos($ns)) {
            $rows[] = ['name' => 'Allocation by the policies (for information; the General Assembly decides)', '_class' => 'group-row'];
            foreach (['reserve_pct' => 'Reserve fund', 'educ_pct' => 'Education and training fund', 'cdf_pct' => 'Community development fund', 'optional_pct' => 'Optional fund'] as $k => $lbl) {
                $rows[] = ['name' => $lbl . ' (' . Dec::fix(Coop::setting($k, '0')) . ' %)', 'amount' => Dec::pct($ns, Coop::setting($k, '0')), '_class' => 'indent-1'];
            }
        }
        return ['grouped' => true, 'columns' => [['key' => 'name', 'label' => ''], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']], 'rows' => $rows,
            'notes' => BusinessUnits::reportNote() . 'Statement of Operations in the cooperative format: revenues from credit operations are the income accounts the loan products and charges post to.'];
    },
]);

/**
 * Statement of Changes in Members' Equity, from the general ledger: paid-up share capital, statutory funds, and
 * undivided net surplus and other equity (the surplus of the years not yet allocated is in that column, as the
 * Statement of Financial Condition shows it). Each voucher's equity lines are put on one row by what they did.
 */
function crpt_sce_columns(): array
{
    $acct = function (string $k): int {
        try {
            return Accts::get($k);
        } catch (Throwable $e) {
            return 0;
        }
    };
    $cap = [$acct('share_capital')];
    foreach (DB::all("SELECT * FROM sav_products WHERE product_type = 'share_capital'") as $p) {
        $cap[] = (int)Savings::liabilityAccount($p);
    }
    foreach (DB::all("SELECT id, name FROM accounts WHERE category = 'Equity'") as $a) {
        if (stripos((string)$a['name'], 'share capital') !== false) {
            $cap[] = (int)$a['id'];
        }
    }
    $funds = array_values(array_filter([$acct('reserve_fund'), $acct('education_fund'), $acct('cdf_fund'), $acct('optional_fund')]));
    return [array_values(array_unique(array_filter($cap))), $funds];
}

Report::define('coop_sce', [
    'filters' => ['range'],
    'default_dates' => 'year_to_date',
    'sortable' => false,
    'run' => function (array $f): array {
        crpt_ready();
        [$cap, $funds] = crpt_sce_columns();
        $col = fn(int $id): string => in_array($id, $cap, true) ? 'cap' : (in_array($id, $funds, true) ? 'fund' : 'other');
        $zero = ['cap' => '0', 'fund' => '0', 'other' => '0'];
        $pl = fn(?string $from, string $to): string => Dec::of(DB::value("SELECT COALESCE(SUM(j.credit - j.debit),0) FROM journal_lines j JOIN accounts a ON a.id = j.account_id
            WHERE a.category IN ('Income','Expense') AND j.txn_date <= :t" . ($from ? ' AND j.txn_date >= :f' : ''), ['t' => $to] + ($from ? ['f' => $from] : [])));
        $balance = function (string $before) use ($col, $zero, $pl): array {
            $b = $zero;
            foreach (DB::all("SELECT j.account_id, COALESCE(SUM(j.credit - j.debit),0) AS v FROM journal_lines j JOIN accounts a ON a.id = j.account_id
                               WHERE a.category = 'Equity' AND j.txn_date < :d GROUP BY j.account_id", ['d' => $before]) as $r) {
                $k = $col((int)$r['account_id']);
                $b[$k] = Dec::add($b[$k], $r['v'], 2);
            }
            $b['other'] = Dec::add($b['other'], $pl(null, Coop::addDays($before, -1)), 2);
            return $b;
        };
        $rowsBy = ['cap_in' => $zero, 'cap_out' => $zero, 'surplus' => $zero, 'alloc' => $zero, 'fund_use' => $zero, 'other' => $zero];
        $rowsBy['surplus']['other'] = $pl($f['from'], $f['to']);
        $byTxn = [];
        foreach (DB::all("SELECT j.txn_id, j.account_id, COALESCE(SUM(j.credit - j.debit),0) AS v FROM journal_lines j JOIN accounts a ON a.id = j.account_id
                           WHERE a.category = 'Equity' AND j.txn_date BETWEEN :f AND :t GROUP BY j.txn_id, j.account_id", ['f' => $f['from'], 't' => $f['to']]) as $r) {
            $k = $col((int)$r['account_id']);
            $byTxn[$r['txn_id']] ??= $zero;
            $byTxn[$r['txn_id']][$k] = Dec::add($byTxn[$r['txn_id']][$k], $r['v'], 2);
        }
        foreach ($byTxn as $m) {
            if (Dec::isPos($m['fund']) && Dec::isNeg($m['other'])) {
                // the net surplus allocation: the funds are credited, the undivided surplus is debited (ISC and PR go to payables)
                $rowsBy['alloc']['fund'] = Dec::add($rowsBy['alloc']['fund'], $m['fund'], 2);
                $rowsBy['alloc']['other'] = Dec::add($rowsBy['alloc']['other'], $m['other'], 2);
                $m['fund'] = '0';
                $m['other'] = '0';
            }
            if (!Dec::isZero($m['cap'])) {
                $r = Dec::isPos($m['cap']) ? 'cap_in' : 'cap_out';
                $rowsBy[$r]['cap'] = Dec::add($rowsBy[$r]['cap'], $m['cap'], 2);
            }
            if (!Dec::isZero($m['fund'])) {
                $r = Dec::isNeg($m['fund']) ? 'fund_use' : 'other';
                $rowsBy[$r]['fund'] = Dec::add($rowsBy[$r]['fund'], $m['fund'], 2);
            }
            $rowsBy['other']['other'] = Dec::add($rowsBy['other']['other'], $m['other'], 2);
        }
        $line = fn(string $name, array $v, string $class = ''): array => ['name' => $name, 'cap' => Dec::fix($v['cap']), 'fund' => Dec::fix($v['fund']), 'other' => Dec::fix($v['other']),
            'total' => Dec::fix(Dec::sum([$v['cap'], $v['fund'], $v['other']])), '_class' => $class];
        $begin = $balance($f['from']);
        $end = $begin;
        $rows = [$line('Balance, ' . fdate($f['from']), $begin, 'subtotal-row')];
        $labels = ['cap_in' => 'Share capital paid in', 'cap_out' => 'Share capital withdrawn or transferred out', 'surplus' => 'Net surplus for the period',
            'alloc' => 'Allocation of net surplus (to statutory funds; ISC and patronage refund declared)', 'fund_use' => 'Statutory funds used', 'other' => 'Other changes in equity'];
        foreach ($labels as $k => $label) {
            $v = $rowsBy[$k];
            if (Dec::isZero($v['cap']) && Dec::isZero($v['fund']) && Dec::isZero($v['other']) && $k !== 'surplus') {
                continue;
            }
            $rows[] = $line($label, $v, 'indent-1');
            foreach (['cap', 'fund', 'other'] as $c) {
                $end[$c] = Dec::add($end[$c], $v[$c], 2);
            }
        }
        $rows[] = $line('Balance, ' . fdate($f['to']), $end, 'total-row');
        $check = $balance(Coop::addDays($f['to'], 1));
        $ok = Dec::cmp(Dec::sum($check), Dec::sum($end)) === 0;
        return ['grouped' => true, 'columns' => [['key' => 'name', 'label' => ''], ['key' => 'cap', 'label' => 'Paid-up share capital', 'type' => 'money'],
            ['key' => 'fund', 'label' => 'Statutory funds', 'type' => 'money'], ['key' => 'other', 'label' => 'Undivided net surplus and other equity', 'type' => 'money'],
            ['key' => 'total', 'label' => 'Total members\' equity', 'type' => 'money']], 'rows' => $rows,
            'notes' => ($ok ? '' : '<strong class="text-danger">The ending balance does not agree with the ledger.</strong> ')
                . 'From the general ledger. The surplus of the years not yet allocated stays in the undivided column until the General Assembly allocates it; the part declared as interest on share capital and patronage refund leaves equity for the payables. The total agrees with members\' equity on the Statement of Financial Condition.'];
    },
]);

Report::define('coop_statutory_funds', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'sortable' => false,
    'run' => function (array $f): array {
        crpt_ready();
        $rows = [];
        foreach (['reserve_fund' => 'Reserve fund', 'education_fund' => 'Education and training fund', 'education_fund_apex' => 'Education fund due to the union / federation (CETF)',
                  'cdf_fund' => 'Community development fund', 'optional_fund' => 'Optional fund', 'isc_payable' => 'Interest on share capital payable', 'pr_payable' => 'Patronage refund payable'] as $k => $lbl) {
            try {
                $id = Accts::get($k);
            } catch (Throwable $e) {
                continue;
            }
            $beg = Dec::neg(DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND txn_date < :f', ['a' => $id, 'f' => $f['from']]));
            $mv = DB::row('SELECT COALESCE(SUM(credit),0) AS cr, COALESCE(SUM(debit),0) AS dr FROM journal_lines WHERE account_id = :a AND txn_date BETWEEN :f AND :t', ['a' => $id, 'f' => $f['from'], 't' => $f['to']]);
            $rows[] = ['fund' => $lbl, 'account' => Coop::accountLabel($id), 'beg' => Dec::fix($beg), 'added' => Dec::fix($mv['cr']), 'used' => Dec::fix($mv['dr']),
                'end' => Dec::fix(Dec::sub(Dec::add($beg, $mv['cr'], 2), $mv['dr'], 2)), '_links' => ['account' => Report::link('rpt.general_ledger', ['account_id' => $id, 'from' => $f['from'], 'to' => $f['to'], 'dates' => 'custom'])]];
        }
        return ['columns' => [['key' => 'fund', 'label' => 'Fund'], ['key' => 'account', 'label' => 'Account'], ['key' => 'beg', 'label' => 'Beginning', 'type' => 'money'], ['key' => 'added', 'label' => 'Allocated / added', 'type' => 'money'],
            ['key' => 'used', 'label' => 'Used / paid', 'type' => 'money'], ['key' => 'end', 'label' => 'Ending', 'type' => 'money']], 'rows' => $rows,
            'notes' => 'The funds and the member payables set up by the net surplus allocation, and what was spent or paid from them in the period (from the general ledger).'];
    },
]);

Report::define('coop_sl_gl', [
    'filters' => ['asof'],
    'sortable' => false,
    'run' => function (array $f): array {
        crpt_ready();
        $rows = [];
        $off = 0;
        foreach (CoopRecon::slVsGl($f['asof']) as $r) {
            $rows[] = ['ledger' => $r['ledger'], 'account' => $r['code'] . ' ' . $r['name'], 'count' => $r['count'], 'sl' => $r['sl'], 'gl' => $r['gl'], 'diff' => $r['difference'],
                '_links' => ['account' => Report::link('rpt.general_ledger', ['account_id' => $r['account_id'], 'from' => '2000-01-01', 'to' => $f['asof'], 'dates' => 'custom'])]];
            if (!Dec::isZero($r['difference'])) {
                $off++;
                foreach (CoopRecon::memberDiffs($r, $f['asof']) as $d) {
                    $link = !empty($d['record']) ? Report::link($d['record'] === 'customers' ? 'cust.center' : 'vend.center', ['id' => $d['record_id']]) : Report::link('coop.member', ['id' => $d['member_id']]);
                    $rows[] = ['ledger' => $d['member'] . ' (' . $d['member_no'] . ')', 'sl' => $d['sl'], 'gl' => $d['gl'], 'diff' => $d['difference'], '_class' => 'indent-1',
                        '_links' => ['ledger' => $link]];
                }
            }
        }
        return ['grouped' => true, 'columns' => [['key' => 'ledger', 'label' => 'Subsidiary ledger'], ['key' => 'account', 'label' => 'GL control account'], ['key' => 'count', 'label' => 'Accounts / loans / names', 'type' => 'int'],
            ['key' => 'sl', 'label' => 'Subsidiary ledger', 'type' => 'money'], ['key' => 'gl', 'label' => 'General ledger', 'type' => 'money'], ['key' => 'diff', 'label' => 'Difference', 'type' => 'money']], 'rows' => $rows,
            'notes' => $off ? '<strong class="text-danger">' . $off . ' ledger(s) do not agree.</strong> The members whose balances differ are listed under them; look for journal entries posted to a control account without going through the member pages.'
                : 'Every subsidiary ledger agrees with its general ledger control account: members\' savings, share capital, time deposits and loans, and the customers\' and suppliers\' open documents. Accrued interest is compared on today\'s date only.'];
    },
]);

Report::define('coop_document_prints', [
    'filters' => ['range', 'user', 'search'],
    'run' => function (array $f): array {
        crpt_ready();
        $sql = 'SELECT d.*, u.username FROM document_prints d LEFT JOIN users u ON u.id = d.printed_by WHERE d.printed_at BETWEEN :f AND :t';
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        if ($f['user_id']) {
            $sql .= ' AND d.printed_by = :u';
            $p['u'] = $f['user_id'];
        }
        if (($f['copies'] ?? '') === 'reprints') {
            $sql .= ' AND d.copy_no > 1';
        }
        if (trim((string)$f['q']) !== '') {
            $sql .= ' AND (d.doc_no LIKE :q OR d.doc_key LIKE :q2)';
            $p += ['q' => '%' . trim($f['q']) . '%', 'q2' => '%' . trim($f['q']) . '%'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY d.id DESC LIMIT 5000', $p) as $d) {
            $rows[] = ['when' => $d['printed_at'], 'doc' => CoopDocs::KEYS[preg_replace('/^[a-z]+\./', '', $d['doc_key'])] ?? ucwords(str_replace(['.', '_'], ' ', $d['doc_key'])), 'no' => $d['doc_no'],
                'copy' => (int)$d['copy_no'] === 1 ? 'Original' : 'Copy ' . ((int)$d['copy_no'] - 1), 'reason' => $d['reason'], 'user' => $d['username']];
        }
        return ['columns' => [['key' => 'when', 'label' => 'Printed', 'type' => 'datetime'], ['key' => 'doc', 'label' => 'Document'], ['key' => 'no', 'label' => 'Number'], ['key' => 'copy', 'label' => 'Copy'],
            ['key' => 'reason', 'label' => 'Reason or purpose'], ['key' => 'user', 'label' => 'User']], 'rows' => $rows,
            'notes' => 'Every print of a controlled document: the first is the original, each later print is a numbered copy with its reason. Certificates of balances are numbered on each issue and show the as-of date and purpose.'];
    },
    'extra_filters' => ['copies' => ['Show', fn() => ['reprints' => 'Reprints only'], 'All prints']],
]);

// ============================================================================ teller

Report::define('teller_journal', [
    'filters' => ['range', 'user'],
    'default_dates' => 'today',
    'extra_filters' => ['kind' => ['Kind', fn() => ['or' => 'Official receipts', 'cv' => 'Disbursements', 'sd' => 'Savings deposits (Savings Transaction Center)', 'sw' => 'Savings withdrawals (Savings Transaction Center)']], 'branch' => ['Branch', fn() => crpt_branches()]],
    'filter_labels' => ['user' => 'Teller'],
    'run' => function (array $f): array {
        crpt_ready();
        $p = ['f' => $f['from'], 't' => $f['to']];
        $sql = 'SELECT r.*, t.txn_date, t.txn_type, t.is_voided, t.memo, m.full_name, m.member_no, u.username, a.code AS cash_code
                  FROM coop_receipts r JOIN transactions t ON t.id = r.txn_id LEFT JOIN members m ON m.id = r.member_id LEFT JOIN users u ON u.id = r.created_by LEFT JOIN accounts a ON a.id = r.cash_account_id
                 WHERE t.txn_date BETWEEN :f AND :t' . crpt_branch_sql($f, 'r.branch_id', $p);
        if ($f['user_id']) {
            $sql .= ' AND r.created_by = :u';
            $p['u'] = $f['user_id'];
        }
        if ($f['kind'] !== '') {
            $sql .= ' AND r.receipt_kind = :k';
            $p['k'] = $f['kind'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY t.txn_date, r.id', $p) as $r) {
            $void = (int)$r['is_voided'] === 1;
            $rows[] = ['date' => $r['txn_date'], 'time' => substr((string)$r['created_at'], 11, 5), 'teller' => $r['username'], 'no' => $r['receipt_no'], 'kind' => ['or' => 'OR', 'cv' => 'CV', 'sd' => 'SD', 'sw' => 'SW'][$r['receipt_kind']] ?? strtoupper((string)$r['receipt_kind']),
                'member' => $r['full_name'] ?: $r['payor_name'], 'mode' => ucfirst($r['payment_mode']), 'drawer' => $r['cash_code'], 'shift' => $r['shift_id'],
                'in' => !$void && in_array($r['receipt_kind'], Teller::KINDS_IN, true) ? Dec::fix($r['total']) : '', 'out' => !$void && in_array($r['receipt_kind'], Teller::KINDS_OUT, true) ? Dec::fix($r['total']) : '', 'status' => $void ? 'VOID' : ($r['is_back_entry'] ? 'Back entry' : ''),
                '_links' => ['no' => Report::txnLink(['txn_type' => $r['txn_type'], 'txn_id' => $r['txn_id']])]];
        }
        return ['columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'time', 'label' => 'Time'], ['key' => 'teller', 'label' => 'Teller'], ['key' => 'no', 'label' => 'Receipt / voucher'],
            ['key' => 'kind', 'label' => 'Kind'], ['key' => 'member', 'label' => 'Member'], ['key' => 'mode', 'label' => 'Mode'], ['key' => 'drawer', 'label' => 'Drawer', 'optional' => true], ['key' => 'shift', 'label' => 'Shift', 'optional' => true],
            ['key' => 'in', 'label' => 'Received', 'type' => 'money'], ['key' => 'out', 'label' => 'Paid out', 'type' => 'money'], ['key' => 'status', 'label' => 'Status']],
            'rows' => $rows, 'totals' => crpt_totals($rows, ['in', 'out'], 'date'), 'notes' => 'Voided receipts stay on the list marked VOID and are left out of the totals. SD and SW are the savings deposits and withdrawals of the Savings Transaction Center (numbered by transaction no.).'];
    },
]);

/**
 * The teller's day by where the money came from: the receipts and vouchers of the drawer (official receipts, loan
 * collections and their error corrections, member disbursements, loan releases from the drawer) and every transaction
 * of the Savings Transaction Center, cash or not (check deposits, memo codes, transfers and GL-opposite codes too).
 * A savings deposit / withdrawal slip is counted once, from the savings ledger.
 */
function crpt_teller_daily(array $f): array
{
    $sources = [
        'loan'     => 'Loan Collection',
        'loan_ec'  => 'Loan Collection: error corrections',
        'sav_dep'  => 'Savings Transaction Center: deposits',
        'sav_wd'   => 'Savings Transaction Center: withdrawals',
        'sav_ec'   => 'Savings Transaction Center: error corrections',
        'or_sav'   => 'Official receipts: savings and deposits',
        'or_other' => 'Official receipts: fees and other items',
        'release'  => 'Loan releases from the drawer',
        'disb'     => 'Member disbursements',
    ];
    $cols = ['cash', 'check', 'other', 'out_cash', 'out_other'];
    $by = [];
    $add = function (string $date, string $teller, string $src, string $col, string $amount, string $doc) use (&$by, $cols) {
        $k = $date . '|' . $teller . '|' . $src;
        if (!isset($by[$k])) {
            $by[$k] = ['date' => $date, 'teller' => $teller, 'src' => $src, 'docs' => []] + array_fill_keys($cols, '0');
        }
        $by[$k][$col] = Dec::add($by[$k][$col], $amount, 2);
        $by[$k]['docs'][$doc] = true;
    };
    $inCol = fn(?string $mode) => $mode === 'cash' ? 'cash' : ($mode === 'check' ? 'check' : 'other');
    $outCol = fn(?string $mode) => $mode === 'cash' ? 'out_cash' : 'out_other';
    $ec = LoanTxn::ready();
    $center = Savings::centerReady();

    // A. official receipts and vouchers of the drawer (savings slips of the Savings Transaction Center are taken from the savings ledger, C.)
    $p = ['f' => $f['from'], 't' => $f['to']];
    $sql = "SELECT r.id, r.txn_id, r.receipt_kind, r.payment_mode, r.total, t.txn_date, u.username,
                   (SELECT COALESCE(SUM(l.amount),0) FROM coop_receipt_lines l WHERE l.receipt_id = r.id AND l.kind IN ('loan_principal','loan_interest','loan_penalty')) AS loan_amt,
                   (SELECT COALESCE(SUM(l.amount),0) FROM coop_receipt_lines l WHERE l.receipt_id = r.id AND l.kind IN ('savings','td')) AS sav_amt,
                   (SELECT x.kind FROM loan_txns x WHERE x.txn_id = r.txn_id AND x.kind IN ('release'" . ($ec ? ",'correction'" : '') . ") LIMIT 1) AS loan_kind
              FROM coop_receipts r JOIN transactions t ON t.id = r.txn_id LEFT JOIN users u ON u.id = r.created_by
             WHERE t.is_voided = 0 AND t.txn_date BETWEEN :f AND :t" . ($center ? " AND r.receipt_kind NOT IN ('sd','sw')" : '') . crpt_branch_sql($f, 'r.branch_id', $p);
    if ($f['user_id']) {
        $sql .= ' AND r.created_by = :u';
        $p['u'] = $f['user_id'];
    }
    foreach (DB::all($sql, $p) as $r) {
        $doc = 'r' . $r['id'];
        $teller = (string)$r['username'];
        if (in_array($r['receipt_kind'], Teller::KINDS_IN, true)) {
            $loan = Dec::of($r['loan_amt']);
            $sav = Dec::of($r['sav_amt']);
            $rest = Dec::sub(Dec::sub($r['total'], $loan, 2), $sav, 2);
            foreach (['loan' => $loan, 'or_sav' => $sav, 'or_other' => $rest] as $src => $amt) {
                if (!Dec::isZero($amt)) {
                    $add($r['txn_date'], $teller, $src, $inCol($r['payment_mode']), $amt, $doc);
                }
            }
        } elseif ($r['loan_kind'] === 'correction') {
            // the error correction of a loan payslip: the collection is taken back, out of the column it came in
            $add($r['txn_date'], $teller, 'loan_ec', $inCol($r['payment_mode']), Dec::neg($r['total']), $doc);
        } else {
            $add($r['txn_date'], $teller, $r['loan_kind'] === 'release' ? 'release' : 'disb', $outCol($r['payment_mode']), Dec::of($r['total']), $doc);
        }
    }

    // B. error corrections of loan payments made by check or bank: a journal voucher, no drawer voucher
    if ($ec) {
        $p = ['f' => $f['from'], 't' => $f['to']];
        $sql = "SELECT c.id, c.txn_date, (c.principal_cr + c.interest_cr + c.penalty_cr) AS amt, u.username, r.payment_mode
                  FROM loan_txns c JOIN loan_accounts l ON l.id = c.loan_id JOIN transactions t ON t.id = c.txn_id
                  JOIN loan_txns o ON o.id = c.corrects_id AND o.kind = 'payment'
                  LEFT JOIN coop_receipts r ON r.txn_id = o.txn_id AND r.receipt_kind = 'or'
                  LEFT JOIN users u ON u.id = c.created_by
                 WHERE c.kind = 'correction' AND c.is_voided = 0 AND t.is_voided = 0 AND c.txn_date BETWEEN :f AND :t
                   AND NOT EXISTS (SELECT 1 FROM coop_receipts x WHERE x.txn_id = c.txn_id)" . crpt_branch_sql($f, 'l.branch_id', $p);
        if ($f['user_id']) {
            $sql .= ' AND c.created_by = :u';
            $p['u'] = $f['user_id'];
        }
        foreach (DB::all($sql, $p) as $r) {
            $add($r['txn_date'], (string)$r['username'], 'loan_ec', $inCol($r['payment_mode']), Dec::of($r['amt']), 'c' . $r['id']);
        }
    }

    // C. every transaction of the Savings Transaction Center (one transaction no. each), whatever its code
    if ($center) {
        $p = ['f' => $f['from'], 't' => $f['to']];
        $sql = "SELECT s.id, s.txn_id, s.txn_date, s.debit, s.credit, s.corrects_id, o.credit AS corrected_credit, u.username,
                       (SELECT r.payment_mode FROM coop_receipts r WHERE r.txn_id = s.txn_id AND r.receipt_kind IN ('sd','sw') LIMIT 1) AS payment_mode
                  FROM sav_txns s JOIN sav_accounts a ON a.id = s.account_id JOIN transactions t ON t.id = s.txn_id
                  LEFT JOIN sav_txns o ON o.id = s.corrects_id
                  LEFT JOIN users u ON u.id = COALESCE(s.teller_id, s.created_by)
                 WHERE s.trans_no IS NOT NULL AND s.is_voided = 0 AND t.is_voided = 0 AND s.txn_date BETWEEN :f AND :t" . crpt_branch_sql($f, 'a.branch_id', $p);
        if ($f['user_id']) {
            $sql .= ' AND COALESCE(s.teller_id, s.created_by) = :u';
            $p['u'] = $f['user_id'];
        }
        foreach (DB::all($sql, $p) as $s) {
            $doc = 's' . $s['txn_id'];
            $credit = Dec::of($s['credit']);
            $debit = Dec::of($s['debit']);
            if ($s['corrects_id']) {
                // the correction of a deposit takes the deposit back (its row carries the amount negative); that of a
                // withdrawal takes the payment back
                if (Dec::isPos((string)$s['corrected_credit'])) {
                    $add($s['txn_date'], (string)$s['username'], 'sav_ec', $inCol($s['payment_mode']), Dec::sub($credit, $debit, 2), $doc);
                } else {
                    $add($s['txn_date'], (string)$s['username'], 'sav_ec', $outCol($s['payment_mode']), Dec::sub($debit, $credit, 2), $doc);
                }
            } elseif (Dec::isPos($credit)) {
                $add($s['txn_date'], (string)$s['username'], 'sav_dep', $inCol($s['payment_mode']), $credit, $doc);
            } elseif (Dec::isPos($debit)) {
                $add($s['txn_date'], (string)$s['username'], 'sav_wd', $outCol($s['payment_mode']), $debit, $doc);
            }
        }
    }

    // one row per day, teller and source, with a subtotal for the teller's day; or one line per day and teller
    $order = array_flip(array_keys($sources));
    uasort($by, fn($a, $b) => [$a['date'], $a['teller'], $order[$a['src']]] <=> [$b['date'], $b['teller'], $order[$b['src']]]);
    $summary = ($f['view'] ?? '') === 'summary';
    $rows = [];
    $day = null;
    $flush = function () use (&$day, &$rows, $cols, $summary) {
        if ($day === null) {
            return;
        }
        $r = ['date' => $day['date'], 'teller' => $day['teller'], 'src' => $summary ? count($day['srcs']) . ' source(s)' : 'Total ' . $day['teller'] . ', ' . fdate($day['date']),
            'n' => count($day['docs'])];
        foreach ($cols as $c) {
            $r[$c] = Dec::fix($day[$c]);
        }
        $r['net'] = Dec::fix(Dec::sub($day['cash'], $day['out_cash'], 2));
        if (!$summary) {
            // the words run on over the date, teller and transactions columns
            $r['date'] = $r['src'];
            $r['teller'] = '';
            $r['src'] = '';
            $r['_class'] = 'subtotal-row';
        }
        $rows[] = $r;
        $day = null;
    };
    foreach ($by as $b) {
        if ($day !== null && ($day['date'] !== $b['date'] || $day['teller'] !== $b['teller'])) {
            $flush();
        }
        $day ??= ['date' => $b['date'], 'teller' => $b['teller'], 'docs' => [], 'srcs' => []] + array_fill_keys($cols, '0');
        $day['docs'] += $b['docs'];
        $day['srcs'][$b['src']] = true;
        foreach ($cols as $c) {
            $day[$c] = Dec::add($day[$c], $b[$c], 2);
        }
        if (!$summary) {
            $r = ['date' => $b['date'], 'teller' => $b['teller'], 'src' => $sources[$b['src']], 'n' => count($b['docs'])];
            foreach ($cols as $c) {
                $r[$c] = Dec::isZero($b[$c]) ? '' : Dec::fix($b[$c]);
            }
            $r['net'] = Dec::fix(Dec::sub($b['cash'], $b['out_cash'], 2));
            $rows[] = $r;
        }
    }
    $flush();
    return $rows;
}

Report::define('teller_daily', [
    'filters' => ['range', 'user'],
    'default_dates' => 'this_week',
    'extra_filters' => ['view' => ['Show', fn() => ['summary' => 'One line per day and teller'], 'By transaction source'], 'branch' => ['Branch', fn() => crpt_branches()]],
    'filter_labels' => ['user' => 'Teller'],
    'sortable' => false,
    'run' => function (array $f): array {
        crpt_ready();
        $rows = crpt_teller_daily($f);
        // the grand total adds up the source rows (or the day lines), never the subtotals
        $leaf = array_values(array_filter($rows, fn($r) => empty($r['_class'])));
        // documents: those of each teller's day (a slip that pays two sources is one document)
        $days = ($f['view'] ?? '') === 'summary' ? $leaf : array_filter($rows, fn($r) => ($r['_class'] ?? '') === 'subtotal-row');
        $total = ['date' => 'TOTAL', '_class' => 'total-row', 'n' => array_sum(array_map(fn($r) => (int)$r['n'], $days))];
        foreach (['cash', 'check', 'other', 'out_cash', 'out_other', 'net'] as $k) {
            $total[$k] = crpt_sum($leaf, $k);
        }
        return ['columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'teller', 'label' => 'Teller'], ['key' => 'src', 'label' => 'Transactions'], ['key' => 'n', 'label' => 'Documents', 'type' => 'int'],
            ['key' => 'cash', 'label' => 'Collected, cash', 'type' => 'money'], ['key' => 'check', 'label' => 'Collected, checks', 'type' => 'money'], ['key' => 'other', 'label' => 'Collected, bank / savings / memo', 'type' => 'money'],
            ['key' => 'out_cash', 'label' => 'Paid out, cash', 'type' => 'money'], ['key' => 'out_other', 'label' => 'Paid out, cheque / other', 'type' => 'money'], ['key' => 'net', 'label' => 'Net cash', 'type' => 'money']],
            'rows' => $rows, 'totals' => $total,
            'notes' => 'Every transaction of the teller: Loan Collection (official receipts of loan payments) and its error corrections, every transaction of the Savings Transaction Center '
                . '(cash and check slips, memo codes, transfers and GL-opposite codes, with their error corrections), the official receipts of other items, member disbursements and loan releases paid from the drawer. '
                . 'An error correction takes the amount back out of the column the corrected transaction is in (a negative amount). Net cash = cash collected less cash paid out. Voided transactions are left out.'];
    },
]);

Report::define('teller_over_short', [
    'filters' => ['range', 'user'],
    'filter_labels' => ['user' => 'Teller'],
    'extra_filters' => ['only' => ['Show', fn() => ['diff' => 'Only shifts with a difference'], 'All closed shifts']],
    'run' => function (array $f): array {
        crpt_ready();
        $p = ['f' => $f['from'], 't' => $f['to']];
        $sql = "SELECT s.*, u.username, a.code AS cash_code, c.username AS closer, x.txn_no, x.txn_type FROM teller_shifts s JOIN users u ON u.id = s.user_id JOIN accounts a ON a.id = s.cash_account_id
                  LEFT JOIN users c ON c.id = s.closed_by LEFT JOIN transactions x ON x.id = s.over_short_txn_id WHERE s.status = 'closed' AND s.business_date BETWEEN :f AND :t" . crpt_branch_sql($f, 's.branch_id', $p);
        if ($f['user_id']) {
            $sql .= ' AND s.user_id = :u';
            $p['u'] = $f['user_id'];
        }
        if ($f['only'] === 'diff') {
            $sql .= ' AND s.over_short <> 0';
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY s.business_date, s.id', $p) as $s) {
            $os = Dec::of($s['over_short'] ?? '0');
            $rows[] = ['date' => $s['business_date'], 'teller' => $s['username'], 'shift' => (int)$s['id'], 'drawer' => $s['cash_code'], 'opening' => Dec::fix($s['opening_cash']), 'in' => Dec::fix($s['receipts_total']),
                'out' => Dec::fix($s['disbursements_total']), 'expected' => Dec::fix($s['expected_cash'] ?? '0'), 'counted' => Dec::fix($s['counted_cash'] ?? '0'),
                'over' => Dec::isPos($os) ? Dec::fix($os) : '', 'short' => Dec::isNeg($os) ? Dec::fix(Dec::abs($os)) : '', 'closed' => $s['closer'], 'voucher' => $s['txn_no'],
                '_links' => ['voucher' => $s['over_short_txn_id'] ? Report::txnLink(['txn_type' => $s['txn_type'], 'txn_id' => $s['over_short_txn_id']]) : null]];
        }
        return ['columns' => [['key' => 'date', 'label' => 'Business date', 'type' => 'date'], ['key' => 'teller', 'label' => 'Teller'], ['key' => 'shift', 'label' => 'Shift', 'type' => 'int'], ['key' => 'drawer', 'label' => 'Drawer'],
            ['key' => 'opening', 'label' => 'Beginning cash', 'type' => 'money'], ['key' => 'in', 'label' => 'Cash received', 'type' => 'money'], ['key' => 'out', 'label' => 'Cash paid out', 'type' => 'money'],
            ['key' => 'expected', 'label' => 'Expected', 'type' => 'money'], ['key' => 'counted', 'label' => 'Counted', 'type' => 'money'], ['key' => 'over', 'label' => 'Over', 'type' => 'money'], ['key' => 'short', 'label' => 'Short', 'type' => 'money'],
            ['key' => 'closed', 'label' => 'Closed by'], ['key' => 'voucher', 'label' => 'Voucher']], 'rows' => $rows, 'totals' => crpt_totals($rows, ['in', 'out', 'over', 'short'], 'date')];
    },
]);

Report::define('teller_or_list', [
    'filters' => ['range'],
    'extra_filters' => ['branch' => ['Branch', fn() => crpt_branches()], 'kind' => ['Series', fn() => ['or' => 'Official receipts', 'cv' => 'Disbursement vouchers'], null]],
    'sortable' => false,
    'run' => function (array $f): array {
        crpt_ready();
        $kind = $f['kind'] === 'cv' ? 'cv' : 'or';
        $p = ['f' => $f['from'], 't' => $f['to'], 'k' => $kind];
        $list = DB::all('SELECT r.*, t.txn_date, t.txn_type, t.is_voided, t.void_reason, m.full_name, u.username FROM coop_receipts r JOIN transactions t ON t.id = r.txn_id LEFT JOIN members m ON m.id = r.member_id
                           LEFT JOIN users u ON u.id = r.created_by WHERE r.receipt_kind = :k AND t.txn_date BETWEEN :f AND :t' . crpt_branch_sql($f, 'r.branch_id', $p) . ' ORDER BY r.receipt_no', $p);
        // numbers in sequence within each prefix: a jump shows the numbers never used (spoiled, lost or skipped)
        $bySeries = [];
        foreach ($list as $r) {
            preg_match('/^(.*?)(\d+)$/', (string)$r['receipt_no'], $mm);
            $bySeries[$mm[1] ?? ''][] = $r + ['_n' => isset($mm[2]) ? (int)$mm[2] : null, '_w' => isset($mm[2]) ? strlen($mm[2]) : 0];
        }
        $rows = [];
        $gaps = 0;
        foreach ($bySeries as $prefix => $items) {
            usort($items, fn($a, $b) => ($a['_n'] ?? 0) <=> ($b['_n'] ?? 0));
            $prev = null;
            foreach ($items as $r) {
                if ($prev !== null && $r['_n'] !== null && $r['_n'] > $prev + 1) {
                    $missing = $r['_n'] - $prev - 1;
                    $gaps += $missing;
                    $first = $prefix . str_pad((string)($prev + 1), $r['_w'], '0', STR_PAD_LEFT);
                    $last = $prefix . str_pad((string)($r['_n'] - 1), $r['_w'], '0', STR_PAD_LEFT);
                    $rows[] = ['no' => $missing === 1 ? $first : $first . ' to ' . $last, 'member' => $missing . ' number(s) not used in this period', '_class' => 'indent-1 text-warning'];
                }
                $prev = $r['_n'] ?? $prev;
                $void = (int)$r['is_voided'] === 1;
                $rows[] = ['no' => $r['receipt_no'], 'date' => $r['txn_date'], 'member' => $r['full_name'] ?: $r['payor_name'], 'mode' => ucfirst($r['payment_mode']), 'amount' => $void ? '' : Dec::fix($r['total']),
                    'status' => $void ? 'VOID: ' . $r['void_reason'] : '', 'teller' => $r['username'], 'prints' => (int)$r['print_count'],
                    '_links' => ['no' => Report::txnLink(['txn_type' => $r['txn_type'], 'txn_id' => $r['txn_id']])]];
            }
        }
        return ['grouped' => true, 'columns' => [['key' => 'no', 'label' => 'Number'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'member', 'label' => 'Member'], ['key' => 'mode', 'label' => 'Mode'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'], ['key' => 'status', 'label' => 'Status'], ['key' => 'teller', 'label' => 'Teller'], ['key' => 'prints', 'label' => 'Prints', 'type' => 'int']],
            'rows' => $rows, 'totals' => ['no' => 'TOTAL', 'amount' => crpt_sum($rows, 'amount')],
            'notes' => $gaps ? '<strong>' . $gaps . ' number(s) in the series were not used in this period.</strong> Account for each one (spoiled receipts are kept, or the numbers were used outside the period).' : 'The series has no gap in this period.'];
    },
]);

Report::define('teller_cash_position', [
    'filters' => ['asof'],
    'sortable' => false,
    'run' => function (array $f): array {
        crpt_ready();
        $cashIds = array_unique(array_filter(array_merge(DB::column('SELECT DISTINCT cash_account_id FROM teller_shifts'), DB::column('SELECT DISTINCT coop_cash_account_id FROM users WHERE coop_cash_account_id IS NOT NULL'),
            [(function () {
                try {
                    return Accts::get('coop_cash');
                } catch (Throwable $e) {
                    return null;
                }
            })(), (function () {
                try {
                    return Accts::get('coop_coci');
                } catch (Throwable $e) {
                    return null;
                }
            })()])));
        $bal = crpt_gl($cashIds, $f['asof']);
        $rows = [['name' => 'Teller cash and cash items', '_class' => 'group-row']];
        foreach ($cashIds as $id) {
            $open = DB::row("SELECT s.id, u.username, s.opening_cash FROM teller_shifts s JOIN users u ON u.id = s.user_id WHERE s.cash_account_id = :a AND s.status = 'open' ORDER BY s.id DESC LIMIT 1", ['a' => $id]);
            $rows[] = ['name' => Coop::accountLabel((int)$id), 'balance' => Dec::fix($bal[$id] ?? '0'), 'note' => $open ? 'open shift ' . $open['id'] . ' (' . $open['username'] . ')' : '', '_class' => 'indent-1',
                '_links' => ['name' => Report::link('rpt.general_ledger', ['account_id' => $id, 'from' => $f['asof'], 'to' => $f['asof'], 'dates' => 'custom'])]];
        }
        $banks = array_keys(Accts::banks());
        $bb = crpt_gl($banks, $f['asof']);
        $rows[] = ['name' => 'Banks', '_class' => 'group-row'];
        foreach ($banks as $id) {
            if (in_array($id, $cashIds)) {
                continue;
            }
            $rows[] = ['name' => Coop::accountLabel((int)$id), 'balance' => Dec::fix($bb[$id] ?? '0'), '_class' => 'indent-1'];
        }
        $rows[] = ['name' => 'TOTAL CASH POSITION', 'balance' => crpt_sum(array_map(fn($r) => ['balance' => $r['balance'] ?? null], $rows), 'balance'), '_class' => 'total-row'];
        $rows[] = ['name' => 'Deposits that can be withdrawn on demand (savings, net of holds)', 'balance' => Dec::fix(DB::value("SELECT COALESCE(SUM(a.balance - a.hold_amount - a.uncleared),0) FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE p.product_type IN ('ordinary','special') AND a.status IN ('active','dormant')")), '_class' => 'indent-1'];
        return ['grouped' => true, 'columns' => [['key' => 'name', 'label' => 'Account'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money', 'sensitive' => 'bank'], ['key' => 'note', 'label' => '']], 'rows' => $rows,
            'notes' => 'Cash on hand in the teller drawers and the bank balances from the general ledger, against the savings members can withdraw (a liquidity check).'];
    },
]);

// ============================================================================ year-end distribution

Report::define('dist_register', [
    'filters' => ['search'],
    'extra_filters' => ['batch' => ['Batch', fn() => Coop::ready() ? DB::pairs("SELECT id, CONCAT(batch_no, ' ', UPPER(kind), ' ', status) FROM dist_batches ORDER BY id DESC") : [], null]],
    'run' => function (array $f): array {
        crpt_ready();
        $batchId = (int)($f['batch'] ?: DB::value("SELECT id FROM dist_batches ORDER BY id DESC LIMIT 1"));
        $b = $batchId ? Distribution::batch($batchId) : null;
        $rows = [];
        if ($b) {
            $p = ['b' => $batchId];
            $sql = 'SELECT bl.*, m.full_name, m.member_no, a.account_no FROM dist_batch_lines bl JOIN members m ON m.id = bl.member_id LEFT JOIN sav_accounts a ON a.id = bl.sav_account_id WHERE bl.batch_id = :b';
            if (trim((string)$f['q']) !== '') {
                $sql .= ' AND (m.full_name LIKE :q OR m.member_no = :q2)';
                $p += ['q' => '%' . trim($f['q']) . '%', 'q2' => trim($f['q'])];
            }
            foreach (DB::all($sql . ' ORDER BY bl.is_excluded, m.full_name', $p) as $l) {
                $rows[] = ['member' => $l['full_name'], 'no' => $l['member_no'], 'account' => $l['account_no'], 'basis' => Dec::fix($l['basis_amount']), 'computed' => (int)$l['is_excluded'] ? '' : Dec::fix($l['computed']),
                    'adj' => Dec::isZero($l['adjustment']) ? '' : Dec::fix($l['adjustment']), 'net' => (int)$l['is_excluded'] ? '' : Dec::fix($l['net_amount']), 'note' => (int)$l['is_excluded'] ? 'Removed: ' . $l['exclude_reason'] : (string)$l['exclude_reason'],
                    'paid' => $l['paid_on'], '_links' => ['member' => Report::link('coop.member', ['id' => $l['member_id']])]];
            }
        }
        return ['columns' => [['key' => 'member', 'label' => 'Member'], ['key' => 'no', 'label' => 'Member no.'], ['key' => 'account', 'label' => 'Account'], ['key' => 'basis', 'label' => 'Basis', 'type' => 'money'],
            ['key' => 'computed', 'label' => 'Computed', 'type' => 'money'], ['key' => 'adj', 'label' => 'Adjustment', 'type' => 'money'], ['key' => 'net', 'label' => 'Net', 'type' => 'money'], ['key' => 'note', 'label' => 'Note'], ['key' => 'paid', 'label' => 'Credited / paid', 'type' => 'date']],
            'rows' => $rows, 'totals' => crpt_totals($rows, ['basis', 'computed', 'adj', 'net'], 'member'),
            'notes' => $b ? h($b['batch_no'] . ': ' . ($b['kind'] === 'isc' ? 'interest on share capital' : 'patronage refund') . ', ' . fdate($b['date_from']) . ' to ' . fdate($b['date_to']) . ', '
                    . (['draft' => 'in review, not posted', 'validated' => 'reviewed, not posted', 'cancelled' => 'reversed'][$b['status']] ?? $b['status'])) . '. '
                . h(Distribution::CREDIT_MODES[$b['credit_mode']] ?? '') . ($b['resolution_ref'] ? '; resolution ' . h($b['resolution_ref']) : '') . '.'
                : 'No distribution batch yet.'];
    },
]);

Report::define('dist_unclaimed', [
    'filters' => ['asof'],
    'run' => function (array $f): array {
        crpt_ready();
        $rows = [];
        foreach (DB::all("SELECT bl.*, b.batch_no, b.kind, b.posted_at, b.date_to, m.full_name, m.member_no FROM dist_batch_lines bl JOIN dist_batches b ON b.id = bl.batch_id JOIN members m ON m.id = bl.member_id
                           WHERE b.status = 'posted' AND b.credit_mode = 'payable' AND bl.is_excluded = 0 AND bl.net_amount > 0 AND (bl.paid_txn_id IS NULL OR bl.paid_txn_id = b.txn_id)
                           ORDER BY m.full_name, b.id") as $l) {
            $posted = substr((string)$l['posted_at'], 0, 10);
            $rows[] = ['member' => $l['full_name'], 'no' => $l['member_no'], 'batch' => $l['batch_no'], 'kind' => $l['kind'] === 'isc' ? 'Interest on share capital' : 'Patronage refund', 'year' => substr($l['date_to'], 0, 4),
                'posted' => $posted, 'days' => $posted ? Coop::daysBetween($posted, $f['asof']) : '', 'amount' => Dec::fix($l['net_amount']), '_links' => ['member' => Report::link('teller.disburse', ['member' => $l['member_id']])]];
        }
        return ['columns' => [['key' => 'member', 'label' => 'Member'], ['key' => 'no', 'label' => 'Member no.'], ['key' => 'batch', 'label' => 'Batch'], ['key' => 'kind', 'label' => 'Kind'], ['key' => 'year', 'label' => 'Year'],
            ['key' => 'posted', 'label' => 'Posted', 'type' => 'date'], ['key' => 'days', 'label' => 'Days unclaimed', 'type' => 'int'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
            'rows' => $rows, 'totals' => crpt_totals($rows, ['amount'], 'member'), 'notes' => 'Amounts kept as payable and not yet paid out. Click a member to pay it out (Member Disbursement).'];
    },
]);

Report::define('dist_summary', [
    'filters' => [],
    'sortable' => false,
    'run' => function (array $f): array {
        crpt_ready();
        $rows = [];
        foreach (DB::all('SELECT * FROM dist_periods ORDER BY fiscal_year DESC') as $p) {
            $used = DB::pairs("SELECT kind, COALESCE(SUM(total_amount),0) FROM dist_batches WHERE period_id = :p AND status = 'posted' GROUP BY kind", ['p' => $p['id']]);
            $rows[] = ['year' => (string)$p['fiscal_year'], 'ns' => Dec::fix($p['net_surplus']), 'reserve' => Dec::fix($p['reserve_amt']), 'educ' => Dec::fix($p['educ_amt']), 'cdf' => Dec::fix($p['cdf_amt']),
                'optional' => Dec::fix($p['optional_amt']), 'isc' => Dec::fix($p['isc_amount']), 'isc_paid' => Dec::fix($used['isc'] ?? '0'), 'pr' => Dec::fix($p['pr_amount']), 'pr_paid' => Dec::fix($used['pr'] ?? '0'),
                'status' => ucfirst($p['status']) . ($p['resolution_no'] ? ', ' . $p['resolution_no'] : ''), '_links' => ['year' => Report::link('dist.surplus', ['id' => $p['id']])]];
        }
        return ['columns' => [['key' => 'year', 'label' => 'Fiscal year'], ['key' => 'ns', 'label' => 'Net surplus', 'type' => 'money'], ['key' => 'reserve', 'label' => 'Reserve', 'type' => 'money'],
            ['key' => 'educ', 'label' => 'Education', 'type' => 'money'], ['key' => 'cdf', 'label' => 'Community development', 'type' => 'money'], ['key' => 'optional', 'label' => 'Optional', 'type' => 'money'],
            ['key' => 'isc', 'label' => 'ISC set aside', 'type' => 'money'], ['key' => 'isc_paid', 'label' => 'ISC distributed', 'type' => 'money'], ['key' => 'pr', 'label' => 'PR set aside', 'type' => 'money'],
            ['key' => 'pr_paid', 'label' => 'PR distributed', 'type' => 'money'], ['key' => 'status', 'label' => 'Status']], 'rows' => $rows];
    },
]);

// ============================================================================ CDA

Report::define('cda_officers', [
    'filters' => [],
    'sortable' => false,
    'extra_filters' => ['active' => ['Show', fn() => ['all' => 'Including past officers'], 'Active officers']],
    'run' => function (array $f): array {
        crpt_ready();
        $bodies = ['board' => 'Board of Directors', 'audit' => 'Audit Committee', 'election' => 'Election Committee', 'credit' => 'Credit Committee', 'education' => 'Education and Training Committee',
            'ethics' => 'Ethics Committee', 'mediation' => 'Mediation and Conciliation Committee', 'gad' => 'Gender and Development Committee', 'management' => 'Management', 'other' => 'Other committees'];
        $rows = [];
        $list = DB::all('SELECT o.*, m.member_no FROM coop_officers o LEFT JOIN members m ON m.id = o.member_id' . ($f['active'] === 'all' ? '' : ' WHERE o.is_active = 1') . ' ORDER BY o.body, o.position, o.name');
        foreach ($bodies as $k => $lbl) {
            $mine = array_filter($list, fn($o) => $o['body'] === $k);
            if (!$mine) {
                continue;
            }
            $rows[] = ['name' => $lbl, '_class' => 'group-row'];
            foreach ($mine as $o) {
                $tr = array_filter(array_map('trim', explode("\n", (string)$o['trainings'])));
                $rows[] = ['name' => $o['name'], 'no' => $o['member_no'], 'position' => $o['position'], 'from' => $o['term_start'], 'to' => $o['term_end'], 'trainings' => count($tr) ? implode('; ', $tr) : 'none recorded',
                    'active' => (int)$o['is_active'] ? '' : 'past', '_class' => 'indent-1'];
            }
        }
        return ['grouped' => true, 'columns' => [['key' => 'name', 'label' => 'Name'], ['key' => 'no', 'label' => 'Member no.'], ['key' => 'position', 'label' => 'Position'], ['key' => 'from', 'label' => 'Term from', 'type' => 'date'],
            ['key' => 'to', 'label' => 'to', 'type' => 'date'], ['key' => 'trainings', 'label' => 'Trainings attended'], ['key' => 'active', 'label' => '']], 'rows' => $rows];
    },
]);

Report::define('cda_compliance', [
    'filters' => [],
    'extra_filters' => ['year' => ['Year', fn() => Coop::ready() ? array_combine($y = array_map('strval', DB::column('SELECT DISTINCT report_year FROM cda_compliance ORDER BY report_year DESC')), $y) : [], 'Every year']],
    'run' => function (array $f): array {
        crpt_ready();
        $p = [];
        $sql = 'SELECT * FROM cda_compliance WHERE 1 = 1';
        if ($f['year'] !== '') {
            $sql .= ' AND report_year = :y';
            $p['y'] = (int)$f['year'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY report_year DESC, due_date, item', $p) as $c) {
            $late = $c['status'] === 'pending' && $c['due_date'] && $c['due_date'] < today();
            $rows[] = ['year' => (string)$c['report_year'], 'item' => $c['item'], 'due' => $c['due_date'], 'submitted' => $c['submitted_on'], 'ref' => $c['reference_no'],
                'status' => $late ? 'OVERDUE' : ucwords(str_replace('_', ' ', $c['status'] === 'pending' ? 'to submit' : $c['status'])), 'notes' => $c['notes'],
                '_links' => ['item' => Report::link('cda.compliance', ['year' => $c['report_year'], 'id' => $c['id']])]];
        }
        return ['columns' => [['key' => 'year', 'label' => 'Year'], ['key' => 'item', 'label' => 'Report / requirement'], ['key' => 'due', 'label' => 'Due', 'type' => 'date'], ['key' => 'submitted', 'label' => 'Submitted', 'type' => 'date'],
            ['key' => 'ref', 'label' => 'Reference'], ['key' => 'status', 'label' => 'Status'], ['key' => 'notes', 'label' => 'Notes']], 'rows' => $rows];
    },
]);
