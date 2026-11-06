<?php
declare(strict_types=1);

/** Savings, share capital and time deposit reports (helpers in coop.php). */

function crpt_sav_statuses(): array
{
    return ['pending' => 'Waiting for approval', 'active' => 'Active', 'dormant' => 'Dormant', 'closed' => 'Closed'];
}

Report::define('sav_list', [
    'filters' => ['search'],
    'extra_filters' => ['product' => ['Product', fn() => crpt_sav_products()], 'status' => ['Status', fn() => crpt_sav_statuses()], 'branch' => ['Branch', fn() => crpt_branches()]],
    'run' => function (array $f): array {
        crpt_ready();
        $p = [];
        $sql = 'SELECT a.*, m.full_name, m.member_no, p.name AS product_name, p.code AS product_code FROM sav_accounts a JOIN members m ON m.id = a.member_id JOIN sav_products p ON p.id = a.product_id WHERE 1 = 1'
            . crpt_branch_sql($f, 'a.branch_id', $p);
        if ($f['product'] !== '') {
            $sql .= ' AND a.product_id = :pr';
            $p['pr'] = (int)$f['product'];
        }
        if ($f['status'] !== '') {
            $sql .= ' AND a.status = :st';
            $p['st'] = $f['status'];
        } else {
            $sql .= " AND a.status <> 'closed'";
        }
        if (trim((string)$f['q']) !== '') {
            $sql .= ' AND (a.account_no LIKE :q1 OR m.full_name LIKE :q2 OR m.member_no = :q3 OR a.passbook_no = :q4)';
            $p += ['q1' => '%' . trim($f['q']) . '%', 'q2' => '%' . trim($f['q']) . '%', 'q3' => trim($f['q']), 'q4' => trim($f['q'])];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY p.sort_order, m.full_name LIMIT 20000', $p) as $a) {
            $rows[] = ['account' => $a['account_no'], 'member' => $a['full_name'], 'no' => $a['member_no'], 'product' => $a['product_code'] . ' ' . $a['product_name'], 'opened' => $a['opened_on'],
                'status' => crpt_sav_statuses()[$a['status']] ?? $a['status'], 'passbook' => $a['passbook_no'], 'last' => $a['last_txn_date'], 'hold' => Dec::isPos($a['hold_amount']) ? Dec::fix($a['hold_amount']) : '',
                'uncleared' => Dec::isPos($a['uncleared']) ? Dec::fix($a['uncleared']) : '', 'balance' => Dec::fix($a['balance']), 'available' => Dec::fix(Savings::available($a)),
                '_links' => ['account' => Report::link('sav.accounts', ['id' => $a['id']]), 'member' => Report::link('coop.member', ['id' => $a['member_id']])]];
        }
        return ['columns' => [['key' => 'account', 'label' => 'Account'], ['key' => 'member', 'label' => 'Member'], ['key' => 'no', 'label' => 'Member no.', 'optional' => true], ['key' => 'product', 'label' => 'Product'],
            ['key' => 'opened', 'label' => 'Opened', 'type' => 'date'], ['key' => 'status', 'label' => 'Status'], ['key' => 'passbook', 'label' => 'Passbook', 'optional' => true], ['key' => 'last', 'label' => 'Last transaction', 'type' => 'date'],
            ['key' => 'hold', 'label' => 'On hold', 'type' => 'money'], ['key' => 'uncleared', 'label' => 'Uncleared', 'type' => 'money'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money'], ['key' => 'available', 'label' => 'Available', 'type' => 'money']],
            'rows' => $rows, 'totals' => crpt_totals($rows, ['hold', 'uncleared', 'balance', 'available'], 'account')];
    },
]);

Report::define('sav_journal', [
    'filters' => ['range', 'user'],
    'default_dates' => 'today',
    'filter_labels' => ['user' => 'Teller / user'],
    'extra_filters' => ['product' => ['Product', fn() => crpt_sav_products()], 'code' => ['Transaction code', fn() => Coop::ready() ? DB::pairs("SELECT code, CONCAT(code, ' ', description) FROM sav_txn_codes ORDER BY code") : []],
        'branch' => ['Branch', fn() => crpt_branches()], 'voids' => ['Voided entries', fn() => ['show' => 'Show them'], 'Leave them out']],
    'run' => function (array $f): array {
        crpt_ready();
        $p = ['f' => $f['from'], 't' => $f['to']];
        $sql = 'SELECT s.*, a.account_no, a.member_id, m.full_name, p.code AS product_code, u.username, x.txn_no, x.txn_type FROM sav_txns s JOIN sav_accounts a ON a.id = s.account_id JOIN members m ON m.id = a.member_id
                  JOIN sav_products p ON p.id = a.product_id LEFT JOIN users u ON u.id = s.created_by LEFT JOIN transactions x ON x.id = s.txn_id WHERE s.txn_date BETWEEN :f AND :t' . crpt_branch_sql($f, 'a.branch_id', $p);
        if ($f['product'] !== '') {
            $sql .= ' AND a.product_id = :pr';
            $p['pr'] = (int)$f['product'];
        }
        if ($f['code'] !== '') {
            $sql .= ' AND s.txn_code = :c';
            $p['c'] = $f['code'];
        }
        if ($f['user_id']) {
            $sql .= ' AND s.created_by = :u';
            $p['u'] = $f['user_id'];
        }
        if ($f['voids'] !== 'show') {
            $sql .= ' AND s.is_voided = 0';
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY s.txn_date, s.id LIMIT 50000', $p) as $s) {
            $v = (int)$s['is_voided'] === 1;
            $rows[] = ['date' => $s['txn_date'], 'account' => $s['account_no'], 'member' => $s['full_name'], 'product' => $s['product_code'], 'code' => $s['txn_code'], 'desc' => ($v ? 'VOID ' : '') . $s['description'],
                'ref' => $s['or_no'] ?: $s['txn_no'], 'debit' => $v ? '' : Dec::fix($s['debit']), 'credit' => $v ? '' : Dec::fix($s['credit']), 'balance' => Dec::fix($s['balance_after']), 'user' => $s['username'],
                '_links' => ['account' => Report::link('sav.accounts', ['id' => $s['account_id']]), 'ref' => $s['txn_id'] ? Report::txnLink(['txn_type' => $s['txn_type'], 'txn_id' => $s['txn_id']]) : null]];
        }
        return ['columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'account', 'label' => 'Account'], ['key' => 'member', 'label' => 'Member'], ['key' => 'product', 'label' => 'Product'],
            ['key' => 'code', 'label' => 'Code'], ['key' => 'desc', 'label' => 'Description'], ['key' => 'ref', 'label' => 'OR / voucher'], ['key' => 'debit', 'label' => 'Withdrawals', 'type' => 'money'],
            ['key' => 'credit', 'label' => 'Deposits', 'type' => 'money'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money'], ['key' => 'user', 'label' => 'User']],
            'rows' => $rows, 'totals' => crpt_totals($rows, ['debit', 'credit'], 'date')];
    },
]);

/** Balances of the savings accounts at a date from their ledgers: [account row + balance] */
function crpt_sav_balances(array $f, string $asof, ?array $types = null): array
{
    $p = ['d' => $asof, 'd2' => $asof];
    $sql = "SELECT a.id, a.account_no, a.member_id, a.product_id, a.status, m.full_name, m.member_no, p.code AS product_code, p.name AS product_name, p.product_type,
                   (SELECT COALESCE(SUM(t.credit - t.debit),0) FROM sav_txns t WHERE t.account_id = a.id AND t.is_voided = 0 AND t.txn_date <= :d) AS bal
              FROM sav_accounts a JOIN members m ON m.id = a.member_id JOIN sav_products p ON p.id = a.product_id WHERE (a.opened_on IS NULL OR a.opened_on <= :d2)" . crpt_branch_sql($f, 'a.branch_id', $p);
    if ($types) {
        [$in, $ip] = DB::in('pt', $types);
        $sql .= " AND p.product_type IN $in";
        $p += $ip;
    }
    if (($f['product'] ?? '') !== '') {
        $sql .= ' AND a.product_id = :pr';
        $p['pr'] = (int)$f['product'];
    }
    return DB::all($sql . ' ORDER BY p.sort_order, m.full_name', $p);
}

Report::define('sav_balances', [
    'filters' => ['asof'],
    'extra_filters' => ['product' => ['Product', fn() => crpt_sav_products()], 'branch' => ['Branch', fn() => crpt_branches()], 'zero' => ['Zero balances', fn() => ['show' => 'Show them'], 'Leave them out']],
    'run' => function (array $f): array {
        crpt_ready();
        $rows = [];
        $byProduct = [];
        foreach (crpt_sav_balances($f, $f['asof']) as $a) {
            if (Dec::isZero($a['bal']) && $f['zero'] !== 'show') {
                continue;
            }
            $rows[] = ['account' => $a['account_no'], 'member' => $a['full_name'], 'no' => $a['member_no'], 'product' => $a['product_code'] . ' ' . $a['product_name'], 'balance' => Dec::fix($a['bal']),
                '_links' => ['account' => Report::link('sav.accounts', ['id' => $a['id']])]];
            $byProduct[$a['product_code'] . ' ' . $a['product_name']] = Dec::add($byProduct[$a['product_code'] . ' ' . $a['product_name']] ?? '0', $a['bal'], 2);
        }
        return ['columns' => [['key' => 'account', 'label' => 'Account'], ['key' => 'member', 'label' => 'Member'], ['key' => 'no', 'label' => 'Member no.'], ['key' => 'product', 'label' => 'Product'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money']],
            'rows' => $rows, 'totals' => crpt_totals($rows, ['balance'], 'account'), 'chart' => $byProduct ? ['bars' => array_map('floatval', $byProduct), 'bars_title' => 'By product'] : null,
            'notes' => 'Balances recomputed from the account ledgers as of the date (voided entries left out).'];
    },
]);

Report::define('sav_schedule', [
    'filters' => ['asof'],
    'extra_filters' => ['branch' => ['Branch', fn() => crpt_branches()]],
    'run' => function (array $f): array {
        crpt_ready();
        $products = DB::all("SELECT id, code, name, product_type FROM sav_products ORDER BY sort_order, code");
        $by = [];
        foreach (crpt_sav_balances($f, $f['asof']) as $a) {
            if (Dec::isZero($a['bal'])) {
                continue;
            }
            $by[$a['member_id']] ??= ['member' => $a['full_name'], 'no' => $a['member_no'], 'total' => '0', '_links' => ['member' => Report::link('coop.member', ['id' => $a['member_id']])]];
            $k = 'p' . $a['product_id'];
            $by[$a['member_id']][$k] = Dec::fix(Dec::add($by[$a['member_id']][$k] ?? '0', $a['bal'], 2));
            $by[$a['member_id']]['total'] = Dec::fix(Dec::add($by[$a['member_id']]['total'], $a['bal'], 2));
        }
        uasort($by, fn($x, $y) => strcmp($x['member'], $y['member']));
        $cols = [['key' => 'member', 'label' => 'Member'], ['key' => 'no', 'label' => 'Member no.']];
        $used = [];
        foreach ($products as $pr) {
            foreach ($by as $r) {
                if (isset($r['p' . $pr['id']])) {
                    $used[] = $pr;
                    break;
                }
            }
        }
        foreach ($used as $pr) {
            $cols[] = ['key' => 'p' . $pr['id'], 'label' => $pr['name'], 'type' => 'money'];
        }
        $cols[] = ['key' => 'total', 'label' => 'Total', 'type' => 'money'];
        $rows = array_values($by);
        $tot = crpt_totals($rows, array_merge(array_map(fn($p) => 'p' . $p['id'], $used), ['total']), 'member');
        // the GL control accounts the products post to, for the tie-out
        $gl = [];
        foreach ($used as $pr) {
            $acct = Savings::liabilityAccount(Savings::product((int)$pr['id']));
            $gl[$acct] = true;
        }
        $glTotal = '0';
        foreach (array_keys($gl) as $acct) {
            $glTotal = Dec::add($glTotal, Dec::neg(DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND txn_date <= :d', ['a' => $acct, 'd' => $f['asof']])), 2);
        }
        return ['columns' => $cols, 'rows' => $rows, 'totals' => $tot,
            'notes' => 'Schedule of member deposits and capital by product. The control accounts of these products show ' . money($glTotal) . ' in the general ledger as of ' . fdate($f['asof'])
                . (Dec::cmp($glTotal, $tot['total']) === 0 ? ', the same as the schedule.' : '; <strong class="text-danger">the schedule shows ' . money($tot['total']) . '</strong> (see SL vs GL reconciliation).')];
    },
]);

Report::define('sav_dormant', [
    'filters' => ['asof'],
    'extra_filters' => ['product' => ['Product', fn() => crpt_sav_products(['ordinary', 'special'])], 'branch' => ['Branch', fn() => crpt_branches()],
        'which' => ['Show', fn() => ['soon' => 'Dormant and becoming dormant within 60 days'], 'Dormant accounts']],
    'run' => function (array $f): array {
        crpt_ready();
        $p = ['d' => $f['asof']];
        $sql = "SELECT a.*, m.full_name, m.member_no, p.name AS product_name, p.dormancy_months FROM sav_accounts a JOIN members m ON m.id = a.member_id JOIN sav_products p ON p.id = a.product_id
                 WHERE a.status IN ('active','dormant') AND p.dormancy_months > 0 AND (a.status = 'dormant' OR DATE_ADD(COALESCE(a.last_txn_date, a.opened_on), INTERVAL p.dormancy_months MONTH) <= "
            . ($f['which'] === 'soon' ? 'DATE_ADD(:d, INTERVAL 60 DAY)' : ':d') . ')' . crpt_branch_sql($f, 'a.branch_id', $p);
        if ($f['product'] !== '') {
            $sql .= ' AND a.product_id = :pr';
            $p['pr'] = (int)$f['product'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY a.last_txn_date, m.full_name', $p) as $a) {
            $last = $a['last_txn_date'] ?: $a['opened_on'];
            $since = $last ? Coop::addMonths($last, (int)$a['dormancy_months']) : null;
            $rows[] = ['account' => $a['account_no'], 'member' => $a['full_name'], 'product' => $a['product_name'], 'last' => $last, 'months' => $last ? (int)floor(Coop::daysBetween($last, $f['asof']) / 30.4375) : '',
                'since' => $a['status'] === 'dormant' ? ($a['dormant_since'] ?: $since) : $since, 'status' => $a['status'] === 'dormant' ? 'Dormant' : 'Becomes dormant', 'balance' => Dec::fix($a['balance']),
                '_links' => ['account' => Report::link('sav.accounts', ['id' => $a['id']])]];
        }
        return ['columns' => [['key' => 'account', 'label' => 'Account'], ['key' => 'member', 'label' => 'Member'], ['key' => 'product', 'label' => 'Product'], ['key' => 'last', 'label' => 'Last transaction', 'type' => 'date'],
            ['key' => 'months', 'label' => 'Months inactive', 'type' => 'int'], ['key' => 'since', 'label' => 'Dormant from', 'type' => 'date'], ['key' => 'status', 'label' => 'Status'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money']],
            'rows' => $rows, 'totals' => crpt_totals($rows, ['balance'], 'account'), 'notes' => 'Dormancy follows the inactivity period of each product (Savings & Deposit Products).'];
    },
]);

Report::define('sav_interest', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'extra_filters' => ['product' => ['Product', fn() => crpt_sav_products()], 'detail' => ['Show', fn() => ['accounts' => 'Each account'], 'Runs only']],
    'sortable' => false,
    'run' => function (array $f): array {
        crpt_ready();
        $p = ['f' => $f['from'], 't' => $f['to']];
        $sql = "SELECT r.*, p.name AS product_name, x.txn_no, x.txn_type, x.is_voided FROM sav_interest_runs r JOIN sav_products p ON p.id = r.product_id LEFT JOIN transactions x ON x.id = r.txn_id
                 WHERE r.status <> 'preview' AND r.posting_date BETWEEN :f AND :t";
        if ($f['product'] !== '') {
            $sql .= ' AND r.product_id = :pr';
            $p['pr'] = (int)$f['product'];
        }
        $rows = [];
        $ti = '0';
        $tt = '0';
        foreach (DB::all($sql . ' ORDER BY r.posting_date, r.id', $p) as $r) {
            $void = (int)$r['is_voided'] === 1 || $r['status'] === 'voided';
            $rows[] = ['what' => fdate($r['posting_date']) . '  ' . $r['product_name'] . ', ' . fdate($r['period_from']) . ' to ' . fdate($r['period_to']) . ($void ? ' (VOIDED)' : ''), 'count' => (int)$r['accounts_count'],
                'interest' => Dec::fix($r['total_interest']), 'tax' => Dec::fix($r['total_tax']), 'net' => Dec::fix(Dec::sub($r['total_interest'], $r['total_tax'], 2)), 'ref' => $r['txn_no'], '_class' => 'group-row',
                '_links' => ['ref' => $r['txn_id'] ? Report::txnLink(['txn_type' => $r['txn_type'], 'txn_id' => $r['txn_id']]) : null]];
            if (!$void) {
                $ti = Dec::add($ti, $r['total_interest'], 2);
                $tt = Dec::add($tt, $r['total_tax'], 2);
            }
            if ($f['detail'] === 'accounts') {
                foreach (DB::all('SELECT l.*, a.account_no, m.full_name FROM sav_interest_lines l JOIN sav_accounts a ON a.id = l.account_id JOIN members m ON m.id = a.member_id WHERE l.run_id = :r ORDER BY m.full_name', ['r' => $r['id']]) as $l) {
                    $rows[] = ['what' => $l['account_no'] . ' ' . $l['full_name'], 'basis' => Dec::fix($l['basis_amount']), 'days' => (int)$l['days'], 'rate' => Dec::fix($l['rate'], 2), 'interest' => Dec::fix($l['interest']),
                        'tax' => Dec::fix($l['tax']), 'net' => Dec::fix($l['net']), '_class' => 'indent-1'];
                }
            }
        }
        $rows[] = ['what' => 'TOTAL (voided runs left out)', 'interest' => Dec::fix($ti), 'tax' => Dec::fix($tt), 'net' => Dec::fix(Dec::sub($ti, $tt, 2)), '_class' => 'total-row'];
        return ['grouped' => true, 'columns' => [['key' => 'what', 'label' => 'Posting / account'], ['key' => 'count', 'label' => 'Accounts', 'type' => 'int'], ['key' => 'basis', 'label' => 'Basis', 'type' => 'money'],
            ['key' => 'days', 'label' => 'Days', 'type' => 'int'], ['key' => 'rate', 'label' => 'Rate %', 'type' => 'qty'], ['key' => 'interest', 'label' => 'Interest', 'type' => 'money'], ['key' => 'tax', 'label' => 'Withholding tax', 'type' => 'money'],
            ['key' => 'net', 'label' => 'Net credited', 'type' => 'money'], ['key' => 'ref', 'label' => 'Voucher']], 'rows' => $rows];
    },
]);

function crpt_td_statuses(): array
{
    return ['active' => 'Active', 'matured' => 'Matured', 'paid' => 'Paid out', 'renewed' => 'Renewed', 'preterminated' => 'Pre-terminated', 'cancelled' => 'Cancelled'];
}

Report::define('td_list', [
    'filters' => ['search'],
    'extra_filters' => ['product' => ['Product', fn() => crpt_sav_products(['time_deposit'])], 'status' => ['Status', fn() => crpt_td_statuses(), 'Active and matured'], 'branch' => ['Branch', fn() => crpt_branches()]],
    'run' => function (array $f): array {
        crpt_ready();
        $p = [];
        $sql = 'SELECT t.*, a.account_no, a.member_id, m.full_name, m.member_no, p.name AS product_name FROM td_placements t JOIN sav_accounts a ON a.id = t.account_id JOIN members m ON m.id = a.member_id JOIN sav_products p ON p.id = a.product_id WHERE 1 = 1'
            . crpt_branch_sql($f, 'a.branch_id', $p);
        if ($f['status'] !== '') {
            $sql .= ' AND t.status = :st';
            $p['st'] = $f['status'];
        } else {
            $sql .= " AND t.status IN ('active','matured')";
        }
        if ($f['product'] !== '') {
            $sql .= ' AND a.product_id = :pr';
            $p['pr'] = (int)$f['product'];
        }
        if (trim((string)$f['q']) !== '') {
            $sql .= ' AND (t.certificate_no LIKE :q1 OR m.full_name LIKE :q2 OR m.member_no = :q3)';
            $p += ['q1' => '%' . trim($f['q']) . '%', 'q2' => '%' . trim($f['q']) . '%', 'q3' => trim($f['q'])];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY p.name, t.maturity_date', $p) as $t) {
            $rows[] = ['cert' => $t['certificate_no'], 'member' => $t['full_name'], 'product' => $t['product_name'], 'principal' => Dec::fix($t['principal']), 'rate' => Dec::fix($t['rate'], 2), 'term' => $t['term_label'] ?: $t['term_days'] . ' days',
                'placed' => $t['placement_date'], 'maturity' => $t['maturity_date'], 'value' => Dec::fix($t['maturity_value']), 'accrued' => Dec::fix(Dec::sub(Dec::of($t['accrued_interest']), Dec::of($t['accrued_paid'] ?? '0'), 2)), 'action' => str_replace('_', ' ', $t['maturity_action']),
                'status' => crpt_td_statuses()[$t['status']] ?? $t['status'], '_links' => ['cert' => Report::link('sav.td', ['id' => $t['id']]), 'member' => Report::link('coop.member', ['id' => $t['member_id']])]];
        }
        return ['columns' => [['key' => 'cert', 'label' => 'Certificate'], ['key' => 'member', 'label' => 'Member'], ['key' => 'product', 'label' => 'Product'], ['key' => 'principal', 'label' => 'Principal', 'type' => 'money'],
            ['key' => 'rate', 'label' => 'Rate %', 'type' => 'qty'], ['key' => 'term', 'label' => 'Term'], ['key' => 'placed', 'label' => 'Placed', 'type' => 'date'], ['key' => 'maturity', 'label' => 'Maturity', 'type' => 'date'],
            ['key' => 'value', 'label' => 'Maturity value', 'type' => 'money'], ['key' => 'accrued', 'label' => 'Accrued interest payable', 'type' => 'money'], ['key' => 'action', 'label' => 'At maturity', 'optional' => true], ['key' => 'status', 'label' => 'Status']],
            'rows' => $rows, 'totals' => crpt_totals($rows, ['principal', 'value', 'accrued'], 'cert')];
    },
]);

Report::define('td_maturity', [
    'filters' => ['range'],
    'default_dates' => 'this_quarter',
    'extra_filters' => ['product' => ['Product', fn() => crpt_sav_products(['time_deposit'])], 'branch' => ['Branch', fn() => crpt_branches()]],
    'sortable' => false,
    'run' => function (array $f): array {
        crpt_ready();
        $p = ['f' => $f['from'], 't' => $f['to']];
        $sql = "SELECT t.*, a.member_id, m.full_name, m.mobile FROM td_placements t JOIN sav_accounts a ON a.id = t.account_id JOIN members m ON m.id = a.member_id
                 WHERE t.status IN ('active','matured') AND t.maturity_date BETWEEN :f AND :t" . crpt_branch_sql($f, 'a.branch_id', $p);
        if ($f['product'] !== '') {
            $sql .= ' AND a.product_id = :pr';
            $p['pr'] = (int)$f['product'];
        }
        $rows = [];
        $month = null;
        $sub = ['p' => '0', 'i' => '0', 'v' => '0'];
        $flush = function () use (&$rows, &$sub, &$month) {
            if ($month !== null) {
                $rows[] = ['cert' => 'Total ' . $month, 'principal' => Dec::fix($sub['p']), 'interest' => Dec::fix($sub['i']), 'value' => Dec::fix($sub['v']), '_class' => 'subtotal-row'];
            }
            $sub = ['p' => '0', 'i' => '0', 'v' => '0'];
        };
        $all = ['p' => '0', 'i' => '0', 'v' => '0'];
        foreach (DB::all($sql . ' ORDER BY t.maturity_date, t.id', $p) as $t) {
            $m = date('F Y', strtotime($t['maturity_date']));
            if ($m !== $month) {
                $flush();
                $month = $m;
                $rows[] = ['cert' => $m, '_class' => 'group-row'];
            }
            $int = Dec::sub($t['maturity_value'], $t['principal'], 2);
            $rows[] = ['cert' => $t['certificate_no'], 'member' => $t['full_name'], 'mobile' => $t['mobile'], 'maturity' => $t['maturity_date'], 'principal' => Dec::fix($t['principal']), 'interest' => Dec::fix($int),
                'value' => Dec::fix($t['maturity_value']), 'action' => str_replace('_', ' ', $t['maturity_action']), '_class' => 'indent-1', '_links' => ['cert' => Report::link('sav.td', ['id' => $t['id']])]];
            foreach (['p' => $t['principal'], 'i' => $int, 'v' => $t['maturity_value']] as $k => $v) {
                $sub[$k] = Dec::add($sub[$k], $v, 2);
                $all[$k] = Dec::add($all[$k], $v, 2);
            }
        }
        $flush();
        $rows[] = ['cert' => 'TOTAL', 'principal' => Dec::fix($all['p']), 'interest' => Dec::fix($all['i']), 'value' => Dec::fix($all['v']), '_class' => 'total-row'];
        return ['grouped' => true, 'columns' => [['key' => 'cert', 'label' => 'Certificate'], ['key' => 'member', 'label' => 'Member'], ['key' => 'mobile', 'label' => 'Mobile', 'optional' => true], ['key' => 'maturity', 'label' => 'Maturity', 'type' => 'date'],
            ['key' => 'principal', 'label' => 'Principal', 'type' => 'money'], ['key' => 'interest', 'label' => 'Interest at maturity', 'type' => 'money'], ['key' => 'value', 'label' => 'Maturity value', 'type' => 'money'],
            ['key' => 'action', 'label' => 'At maturity']], 'rows' => $rows, 'notes' => 'The cash the cooperative needs on each maturity, by month (liquidity planning).'];
    },
]);

Report::define('td_accrued', [
    'filters' => ['asof'],
    'extra_filters' => ['product' => ['Product', fn() => crpt_sav_products(['time_deposit'])]],
    'run' => function (array $f): array {
        crpt_ready();
        $p = ['d' => $f['asof']];
        $sql = "SELECT t.*, a.member_id, a.product_id, m.full_name FROM td_placements t JOIN sav_accounts a ON a.id = t.account_id JOIN members m ON m.id = a.member_id WHERE t.status IN ('active','matured') AND t.placement_date <= :d"
            . crpt_branch_sql($f, 'a.branch_id', $p);
        if ($f['product'] !== '') {
            $sql .= ' AND a.product_id = :pr';
            $p['pr'] = (int)$f['product'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY t.maturity_date', $p) as $t) {
            $earned = TimeDeposits::earnedThrough($t, $f['asof']);
            $rows[] = ['cert' => $t['certificate_no'], 'member' => $t['full_name'], 'principal' => Dec::fix($t['principal']), 'rate' => Dec::fix($t['rate'], 2), 'placed' => $t['placement_date'], 'maturity' => $t['maturity_date'],
                'earned' => Dec::fix($earned), 'booked' => Dec::fix($t['accrued_interest']), 'to_book' => Dec::fix(Dec::max('0', Dec::sub($earned, $t['accrued_interest'], 2))),
                // the accrued interest a member withdrew before maturity (CAIW) leaves the payable
                'withdrawn' => Dec::fix($t['accrued_paid'] ?? '0'), 'payable' => Dec::fix(Dec::sub(Dec::of($t['accrued_interest']), Dec::of($t['accrued_paid'] ?? '0'), 2)),
                '_links' => ['cert' => Report::link('sav.td', ['id' => $t['id']])]];
        }
        return ['columns' => [['key' => 'cert', 'label' => 'Certificate'], ['key' => 'member', 'label' => 'Member'], ['key' => 'principal', 'label' => 'Principal', 'type' => 'money'], ['key' => 'rate', 'label' => 'Rate %', 'type' => 'qty'],
            ['key' => 'placed', 'label' => 'Placed', 'type' => 'date'], ['key' => 'maturity', 'label' => 'Maturity', 'type' => 'date'], ['key' => 'earned', 'label' => 'Earned to date', 'type' => 'money'],
            ['key' => 'booked', 'label' => 'Accrued in the books', 'type' => 'money'], ['key' => 'to_book', 'label' => 'Not yet accrued', 'type' => 'money'],
            ['key' => 'withdrawn', 'label' => 'Accrued interest withdrawn', 'type' => 'money'], ['key' => 'payable', 'label' => 'Accrued interest payable', 'type' => 'money']],
            'rows' => $rows, 'totals' => crpt_totals($rows, ['principal', 'earned', 'booked', 'to_book', 'withdrawn', 'payable'], 'cert'),
            'notes' => 'Interest earned by the members on their time deposits up to the date, and how much of it is already accrued as a payable (Time Deposits > accrual). Accrued interest payable is what was accrued less what the members withdrew of it before maturity (Withdraw Accrued): it is the balance of the accrued interest payable account.'];
    },
]);

Report::define('sav_statement', [
    'filters' => ['range', 'search'],
    'default_dates' => 'this_year',
    'filter_labels' => ['search' => 'Account no.'],
    'sortable' => false,
    'run' => function (array $f): array {
        crpt_ready();
        $a = trim((string)$f['q']) !== '' ? Savings::byNo(trim((string)$f['q'])) : (gint('account_id') ? Savings::account((int)gint('account_id')) : null);
        if (!$a) {
            return ['columns' => [['key' => 'date', 'label' => 'Date']], 'rows' => [], 'notes' => 'Enter the account number in the search box.'];
        }
        if (!Coop::branchAllowed($a['branch_id'] ? (int)$a['branch_id'] : null)) {
            fail('That account belongs to another branch.');
        }
        $beg = Dec::of(DB::value('SELECT COALESCE(SUM(credit - debit),0) FROM sav_txns WHERE account_id = :a AND is_voided = 0 AND txn_date < :f', ['a' => $a['id'], 'f' => $f['from']]));
        $rows = [['date' => $f['from'], 'desc' => 'Beginning balance', 'balance' => Dec::fix($beg), '_class' => 'group-row']];
        $bal = $beg;
        $td = '0';
        $tc = '0';
        foreach (DB::all('SELECT s.*, x.txn_no, x.txn_type FROM sav_txns s LEFT JOIN transactions x ON x.id = s.txn_id WHERE s.account_id = :a AND s.is_voided = 0 AND s.txn_date BETWEEN :f AND :t ORDER BY s.txn_date, s.id',
            ['a' => $a['id'], 'f' => $f['from'], 't' => $f['to']]) as $s) {
            $bal = Dec::add(Dec::sub($bal, $s['debit'], 2), $s['credit'], 2);
            $td = Dec::add($td, $s['debit'], 2);
            $tc = Dec::add($tc, $s['credit'], 2);
            $rows[] = ['date' => $s['txn_date'], 'code' => $s['txn_code'], 'desc' => $s['description'], 'ref' => $s['or_no'] ?: $s['txn_no'], 'debit' => Dec::isPos($s['debit']) ? Dec::fix($s['debit']) : '',
                'credit' => Dec::isPos($s['credit']) ? Dec::fix($s['credit']) : '', 'balance' => Dec::fix($bal), '_links' => ['ref' => $s['txn_id'] ? Report::txnLink(['txn_type' => $s['txn_type'], 'txn_id' => $s['txn_id']]) : null]];
        }
        $rows[] = ['date' => $f['to'], 'desc' => 'Ending balance', 'debit' => Dec::fix($td), 'credit' => Dec::fix($tc), 'balance' => Dec::fix($bal), '_class' => 'total-row'];
        return ['grouped' => true, 'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'code', 'label' => 'Code'], ['key' => 'desc', 'label' => 'Description'], ['key' => 'ref', 'label' => 'Reference'],
            ['key' => 'debit', 'label' => 'Withdrawals', 'type' => 'money'], ['key' => 'credit', 'label' => 'Deposits', 'type' => 'money'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money']], 'rows' => $rows,
            'notes' => h($a['account_no'] . ' ' . $a['product_name'] . ', ' . $a['member_name'] . ' (' . $a['member_no'] . ')')];
    },
]);
