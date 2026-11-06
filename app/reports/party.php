<?php
declare(strict_types=1);

/** Parties (the Party Master), their roles and the documents reissued, and the operations by line of business. */

function prpt_ready(): void
{
    if (!Party::ready()) {
        fail('The Party Master is not installed (run database/migrations/2026_11_15_multipurpose_party.sql).');
    }
}

Report::define('party_list', [
    'filters' => ['search'],
    'extra_filters' => [
        'role' => ['Role', fn() => Party::ROLES + ['multi' => 'More than one role', 'none' => 'No role']],
        'pstatus' => ['Status', fn() => Party::STATUSES],
        'ptype' => ['Kind', fn() => Party::TYPES],
        'branch' => ['Home branch', fn() => Coop::ready() ? Coop::branchOptions(false) : []],
    ],
    'run' => function (array $f): array {
        prpt_ready();
        $p = [];
        $where = [];
        $roleSql = [
            'member' => 'EXISTS (SELECT 1 FROM members m WHERE m.party_id = p.id)',
            'customer' => 'EXISTS (SELECT 1 FROM customers c WHERE c.party_id = p.id AND c.parent_id IS NULL)',
            'supplier' => 'EXISTS (SELECT 1 FROM vendors v WHERE v.party_id = p.id)',
            'employee' => "EXISTS (SELECT 1 FROM other_names o WHERE o.party_id = p.id AND o.member_id IS NULL AND o.name_type = 'employee')",
            'other' => "EXISTS (SELECT 1 FROM other_names o WHERE o.party_id = p.id AND o.member_id IS NULL AND o.name_type <> 'employee')",
        ];
        if (isset($roleSql[$f['role']])) {
            $where[] = $roleSql[$f['role']];
        } elseif ($f['role'] === 'none') {
            $where[] = 'NOT (' . implode(' OR ', $roleSql) . ')';
        } elseif ($f['role'] === 'multi') {
            $where[] = '((' . implode(') + (', $roleSql) . ')) >= 2';
        }
        if ($f['pstatus'] !== '') {
            $where[] = 'p.status = :st';
            $p['st'] = $f['pstatus'];
        }
        if ($f['ptype'] !== '') {
            $where[] = 'p.party_type = :ty';
            $p['ty'] = $f['ptype'];
        }
        if ($f['branch'] !== '') {
            $where[] = '(p.home_branch_id = :br OR p.id IN (SELECT party_id FROM members WHERE branch_id = :br2))';
            $p += ['br' => (int)$f['branch'], 'br2' => (int)$f['branch']];
        }
        $q = trim((string)$f['q']);
        if ($q !== '') {
            $where[] = '(p.party_no LIKE :q1 OR p.legal_name LIKE :q2 OR p.display_name LIKE :q3 OR p.tin LIKE :q4)';
            $p += ['q1' => $q . '%', 'q2' => '%' . $q . '%', 'q3' => '%' . $q . '%', 'q4' => $q . '%'];
        }
        $rows = DB::all('SELECT p.* FROM parties p' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY p.display_name, p.id LIMIT 20000', $p);
        $roles = Party::roleNamesAll(array_column($rows, 'id'));
        $memberNos = $rows ? DB::pairs('SELECT party_id, GROUP_CONCAT(member_no) FROM members WHERE party_id IS NOT NULL GROUP BY party_id') : [];
        $branches = Coop::ready() ? Coop::branchOptions(false) : [];
        $out = [];
        $conf = Party::canSeeConfidential();
        foreach ($rows as $r) {
            $hide = !$conf && isset($memberNos[(int)$r['id']]);
            $out[] = ['no' => $r['party_no'], 'name' => $r['display_name'], 'legal' => $r['legal_name'], 'kind' => Party::TYPES[$r['party_type']] ?? $r['party_type'],
                'roles' => implode(', ', $roles[(int)$r['id']] ?? []), 'member_no' => (string)($memberNos[(int)$r['id']] ?? ''),
                'tin' => $hide && $r['tin'] ? Party::mask((string)$r['tin']) : (string)$r['tin'], 'mobile' => $hide && $r['mobile'] ? Party::mask((string)$r['mobile']) : (string)$r['mobile'],
                'email' => $hide && $r['email'] ? Party::mask((string)$r['email']) : (string)$r['email'],
                'branch' => $branches[(int)$r['home_branch_id']] ?? '', 'status' => Party::STATUSES[$r['status']] ?? $r['status'], 'since' => substr((string)$r['created_at'], 0, 10),
                '_links' => ['no' => Report::link('party.center', ['id' => $r['id']]), 'name' => Report::link('party.center', ['id' => $r['id']])]];
        }
        return ['columns' => [['key' => 'no', 'label' => 'Party ID'], ['key' => 'name', 'label' => 'Name'], ['key' => 'legal', 'label' => 'Legal name (printed)'], ['key' => 'kind', 'label' => 'Kind', 'optional' => true],
            ['key' => 'roles', 'label' => 'Roles'], ['key' => 'member_no', 'label' => 'Member no.'], ['key' => 'tin', 'label' => 'TIN'], ['key' => 'mobile', 'label' => 'Mobile', 'optional' => true],
            ['key' => 'email', 'label' => 'E-mail', 'optional' => true], ['key' => 'branch', 'label' => 'Home branch', 'optional' => true], ['key' => 'status', 'label' => 'Status'], ['key' => 'since', 'label' => 'On file since', 'type' => 'date', 'optional' => true]],
            'rows' => $out, 'notes' => count($out) . ' part' . (count($out) === 1 ? 'y' : 'ies') . '. ' . ($conf ? '' : 'Members\' TIN and contact details are partly hidden. ')];
    },
]);

Report::define('party_role_history', [
    'filters' => ['range', 'search'],
    'default_dates' => 'this_year',
    'extra_filters' => [
        'role' => ['Role', fn() => Party::ROLES + ['party' => 'The party itself']],
        'action' => ['What happened', fn() => Party::ACTIONS],
    ],
    'run' => function (array $f): array {
        prpt_ready();
        $sql = 'SELECT l.*, p.party_no, p.display_name, op.party_no AS other_no, u.username, a.username AS approver FROM party_role_log l JOIN parties p ON p.id = l.party_id
                  LEFT JOIN parties op ON op.id = l.other_party_id LEFT JOIN users u ON u.id = l.created_by LEFT JOIN users a ON a.id = l.approved_by
                 WHERE l.created_at BETWEEN :f AND :t';
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        if ($f['role'] !== '') {
            $sql .= ' AND l.role = :r';
            $p['r'] = $f['role'];
        }
        if ($f['action'] !== '') {
            $sql .= ' AND l.action = :a';
            $p['a'] = $f['action'];
        }
        if (trim((string)$f['q']) !== '') {
            $sql .= ' AND (p.party_no LIKE :q1 OR p.display_name LIKE :q2 OR l.reason LIKE :q3)';
            $p += ['q1' => trim($f['q']) . '%', 'q2' => '%' . trim($f['q']) . '%', 'q3' => '%' . trim($f['q']) . '%'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY l.created_at, l.id LIMIT 20000', $p) as $l) {
            $rows[] = ['when' => fdatetime($l['created_at']), 'no' => $l['party_no'], 'name' => $l['display_name'],
                'role' => $l['role'] === 'party' ? 'Party' : (Party::ROLES[$l['role']] ?? $l['role']), 'record' => $l['ref_id'] ? (string)$l['ref_id'] : '',
                'action' => Party::ACTIONS[$l['action']] ?? $l['action'], 'other' => (string)$l['other_no'], 'reason' => (string)$l['reason'], 'by' => (string)$l['username'], 'approver' => (string)$l['approver'],
                '_links' => ['no' => Report::link('party.center', ['id' => $l['party_id']])]];
        }
        return ['columns' => [['key' => 'when', 'label' => 'When'], ['key' => 'no', 'label' => 'Party ID'], ['key' => 'name', 'label' => 'Party'], ['key' => 'role', 'label' => 'Role'],
            ['key' => 'record', 'label' => 'Record', 'optional' => true], ['key' => 'action', 'label' => 'What happened'], ['key' => 'other', 'label' => 'Other party'], ['key' => 'reason', 'label' => 'Reason'],
            ['key' => 'by', 'label' => 'By'], ['key' => 'approver', 'label' => 'Approved by', 'optional' => true]], 'rows' => $rows,
            'notes' => 'Roles given, taken away, restored, linked, moved and merged. Membership status changes are also on the member\'s status history.'];
    },
]);

Report::define('doc_reissues', [
    'filters' => ['range', 'search'],
    'default_dates' => 'this_year',
    'run' => function (array $f): array {
        prpt_ready();
        $sql = 'SELECT r.*, t.txn_type, t.txn_no, t.txn_date, u.username, a.username AS approver FROM document_reissues r JOIN transactions t ON t.id = r.txn_id
                  LEFT JOIN users u ON u.id = r.created_by LEFT JOIN users a ON a.id = r.approved_by WHERE r.created_at BETWEEN :f AND :t';
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        if (trim((string)$f['q']) !== '') {
            $sql .= ' AND (t.txn_no LIKE :q1 OR r.old_name LIKE :q2 OR r.new_name LIKE :q3)';
            $p += ['q1' => '%' . trim($f['q']) . '%', 'q2' => '%' . trim($f['q']) . '%', 'q3' => '%' . trim($f['q']) . '%'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY r.created_at, r.id', $p) as $r) {
            $rows[] = ['when' => fdatetime($r['created_at']), 'doc' => Txn::label($r['txn_type']) . ' ' . $r['txn_no'], 'date' => $r['txn_date'], 'no' => (int)$r['reissue_no'],
                'old' => (string)$r['old_name'], 'new' => (string)$r['new_name'], 'old_address' => (string)$r['old_address'], 'new_address' => (string)$r['new_address'],
                'old_tin' => (string)$r['old_tin'], 'new_tin' => (string)$r['new_tin'], 'reason' => (string)$r['reason'], 'by' => (string)$r['username'], 'approver' => (string)$r['approver'],
                '_links' => ['doc' => Report::txnLink(['txn_type' => $r['txn_type'], 'txn_id' => $r['txn_id']])]];
        }
        return ['columns' => [['key' => 'when', 'label' => 'When'], ['key' => 'doc', 'label' => 'Document'], ['key' => 'date', 'label' => 'Dated', 'type' => 'date'], ['key' => 'no', 'label' => 'Reissue no.', 'type' => 'int'],
            ['key' => 'old', 'label' => 'Name before'], ['key' => 'new', 'label' => 'Name now'], ['key' => 'old_address', 'label' => 'Address before', 'optional' => true], ['key' => 'new_address', 'label' => 'Address now', 'optional' => true],
            ['key' => 'old_tin', 'label' => 'TIN before', 'optional' => true], ['key' => 'new_tin', 'label' => 'TIN now', 'optional' => true], ['key' => 'reason', 'label' => 'Reason'], ['key' => 'by', 'label' => 'By'],
            ['key' => 'approver', 'label' => 'Approved by', 'optional' => true]], 'rows' => $rows,
            'notes' => 'Corrections of the name, address or TIN printed on issued documents. Amounts and the ledger did not change.'];
    },
]);

/**
 * Statement of Operations by Line of Business: the income and expense of each line of business side by side, the
 * documents without a line of business, the inter-unit eliminations, and the whole cooperative.
 */
Report::define('sop_business_unit', [
    'filters' => ['range', 'class'],
    'no_unit' => true,
    'default_dates' => 'year_to_date',
    'sortable' => false,
    'run' => function (array $f): array {
        if (!BusinessUnits::ready()) {
            fail('Lines of business are not installed (run database/migrations/2026_11_15_multipurpose_party.sql).');
        }
        BusinessUnits::$reportFilter = null;   // every unit is a column
        [$csql, $cp] = Classes::sqlFilter($f['class_id'] ?: null, 'j', 'sbcls');
        $data = DB::all("SELECT j.account_id, COALESCE(j.business_unit_id, 0) AS bu, SUM(j.credit - j.debit) AS net FROM journal_lines j JOIN accounts a ON a.id = j.account_id
                          WHERE a.category IN ('Income','Expense') AND j.txn_date BETWEEN :f AND :t $csql GROUP BY j.account_id, COALESCE(j.business_unit_id, 0)", ['f' => $f['from'], 't' => $f['to']] + $cp);
        $units = BusinessUnits::all();
        $used = [];
        $byAcct = [];
        foreach ($data as $d) {
            $used[(int)$d['bu']] = true;
            $byAcct[(int)$d['account_id']][(int)$d['bu']] = (string)$d['net'];
        }
        $cols = [];
        foreach ($units as $id => $u) {
            if ((int)$u['is_active'] === 1 || isset($used[$id])) {
                $cols['u' . $id] = $u['code'] . ' ' . $u['name'];
            }
        }
        if (isset($used[0])) {
            $cols['u0'] = 'No line of business';
        }
        $elim = BusinessUnits::interunitGroups()['pl'] !== [];
        $accounts = DB::all("SELECT id, code, name, account_type, category FROM accounts WHERE category IN ('Income','Expense') ORDER BY code");
        $totals = [];
        foreach ($byAcct as $aid => $vals) {
            $totals[$aid] = array_sum(array_map('floatval', $vals));
        }
        $consolidated = $elim ? BusinessUnits::eliminate($totals) : $totals;
        $sections = ['Income' => 'REVENUES', 'Expense' => 'EXPENSES'];
        $rows = [];
        $grand = ['Income' => [], 'Expense' => []];
        foreach ($sections as $cat => $title) {
            $rows[] = ['name' => $title, '_class' => 'group-row'];
            foreach ($accounts as $a) {
                if ($a['category'] !== $cat || !isset($byAcct[(int)$a['id']])) {
                    continue;
                }
                $sign = $cat === 'Income' ? '1' : '-1';
                $r = ['name' => $a['code'] . ' ' . $a['name'], '_class' => 'indent-1'];
                $sum = '0';
                foreach ($cols as $k => $_) {
                    $v = Dec::mul($byAcct[(int)$a['id']][(int)substr($k, 1)] ?? '0', $sign, 2);
                    $r[$k] = Dec::fix($v);
                    $sum = Dec::add($sum, $v, 2);
                    $grand[$cat][$k] = Dec::add($grand[$cat][$k] ?? '0', $v, 2);
                }
                $total = Dec::mul((string)($consolidated[(int)$a['id']] ?? 0), $sign, 2);
                if ($elim) {
                    $r['elim'] = Dec::fix(Dec::sub($total, $sum, 2));
                    $grand[$cat]['elim'] = Dec::add($grand[$cat]['elim'] ?? '0', $r['elim'], 2);
                }
                $r['total'] = Dec::fix($total);
                $grand[$cat]['total'] = Dec::add($grand[$cat]['total'] ?? '0', $total, 2);
                $r['_links'] = ['name' => Report::link('rpt.general_ledger', ['account_id' => $a['id'], 'from' => $f['from'], 'to' => $f['to'], 'dates' => 'custom'])];
                $rows[] = $r;
            }
            $sub = ['name' => 'Total ' . strtolower($title), '_class' => 'subtotal-row'];
            foreach (array_merge(array_keys($cols), $elim ? ['elim'] : [], ['total']) as $k) {
                $sub[$k] = Dec::fix($grand[$cat][$k] ?? '0');
            }
            $rows[] = $sub;
        }
        $ns = ['name' => 'NET SURPLUS (LOSS)', '_class' => 'total-row'];
        foreach (array_merge(array_keys($cols), $elim ? ['elim'] : [], ['total']) as $k) {
            $ns[$k] = Dec::fix(Dec::sub($grand['Income'][$k] ?? '0', $grand['Expense'][$k] ?? '0', 2));
        }
        $rows[] = $ns;
        $columns = [['key' => 'name', 'label' => '']];
        foreach ($cols as $k => $label) {
            $columns[] = ['key' => $k, 'label' => $label, 'type' => 'money'];
        }
        if ($elim) {
            $columns[] = ['key' => 'elim', 'label' => 'Eliminations', 'type' => 'money'];
        }
        $columns[] = ['key' => 'total', 'label' => 'Whole cooperative', 'type' => 'money'];
        return ['grouped' => true, 'columns' => $columns, 'rows' => $rows,
            'notes' => 'From the one general ledger: each line of business is the ledger lines of its documents. '
                . ($elim ? 'Sales and charges between lines of business (the inter-unit income and expense accounts) net out in the Eliminations column; a difference that does not net shows on the whole-cooperative column. '
                    : 'No inter-unit income or expense accounts are set, so nothing is eliminated (set them on Members & Others > Lines of Business). ')
                . (isset($used[0]) ? 'Documents without a line of business are shown apart; give them one on Members & Others > Lines of Business.' : '')];
    },
]);
