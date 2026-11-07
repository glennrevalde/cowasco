<?php
declare(strict_types=1);

/** Financial statements, general ledger and journal. */

/** account_id => net (debit - credit) for a date window, optionally for one class */
function fin_balances(?string $from, string $to, ?int $classId = null): array
{
    $sql = 'SELECT account_id, SUM(debit - credit) AS net FROM journal_lines WHERE txn_date <= :t';
    $p = ['t' => $to];
    if ($from) {
        $sql .= ' AND txn_date >= :f';
        $p['f'] = $from;
    }
    [$csql, $cp] = Classes::sqlFilter($classId, '');
    // the whole cooperative: balances between its lines of business are eliminated (Cooperative > Lines of Business)
    return BusinessUnits::eliminate(array_map('floatval', DB::pairs($sql . $csql . ' GROUP BY account_id', $p + $cp)));
}

/** Note shown on a report that is filtered by class */
function fin_class_note(?int $classId, bool $eliminates = true): string
{
    return ($classId ? 'Class: <strong>' . h(Classes::name($classId)) . '</strong> only. ' : '') . BusinessUnits::reportNote($eliminates);
}

/**
 * Column buckets of the "by Class" statements: every class with activity, then Unclassified.
 * Returns [bucket key => label]; bucket key is "c<id>" or "c0" for entries without a class.
 */
function fin_class_buckets(array $usedKeys): array
{
    $out = [];
    foreach (Classes::all() as $id => $c) {
        if (isset($usedKeys['c' . $id]) || (int)$c['is_active'] === 1) {
            $out['c' . $id] = $c['name'];
        }
    }
    if (isset($usedKeys['c0']) || !$out) {
        $out['c0'] = 'Unclassified';
    }
    return $out;
}

/** Every account in code order (read once a request; $fresh reads them again) */
function fin_accounts(bool $fresh = false): array
{
    static $a = null;
    if ($fresh) {
        $a = null;
    }
    return $a ??= DB::all('SELECT * FROM accounts ORDER BY code');
}

/** +1 for an account whose normal balance is a debit, -1 for a credit-normal account (by its Normal balance,
    else by its category). */
function fin_normal_sign(array $a): int
{
    $nb = (string)($a['normal_balance'] ?? '');
    if ($nb === '') {
        $nb = in_array($a['category'] ?? '', ['Liability', 'Equity', 'Income'], true) ? 'Credit' : 'Debit';
    }
    return $nb === 'Credit' ? -1 : 1;
}

/** Balance-sheet style net income before a date (all prior P&L activity) */
function fin_net_income(?string $from, string $to, ?int $classId = null): float
{
    $sql = "SELECT COALESCE(SUM(j.credit - j.debit),0) FROM journal_lines j JOIN accounts a ON a.id = j.account_id
             WHERE a.category IN ('Income','Expense') AND j.txn_date <= :t";
    $p = ['t' => $to];
    if ($from) {
        $sql .= ' AND j.txn_date >= :f';
        $p['f'] = $from;
    }
    [$csql, $cp] = Classes::sqlFilter($classId, 'j');
    return r2(DB::value($sql . $csql, $p + $cp));
}

/** Net income (credit - debit) per class bucket for a date window: ['c3' => 1200.00, 'c0' => 50.00] */
function fin_net_income_by_class(?string $from, string $to): array
{
    $sql = "SELECT COALESCE(j.class_id, 0) AS cls, SUM(j.credit - j.debit) AS net FROM journal_lines j JOIN accounts a ON a.id = j.account_id
             WHERE a.category IN ('Income','Expense') AND j.txn_date <= :t";
    $p = ['t' => $to];
    if ($from) {
        $sql .= ' AND j.txn_date >= :f';
        $p['f'] = $from;
    }
    $out = [];
    foreach (DB::all($sql . ' GROUP BY COALESCE(j.class_id, 0)', $p) as $r) {
        $out['c' . (int)$r['cls']] = (float)$r['net'];
    }
    return $out;
}

Report::define('trial_balance', [
    'filters' => ['asof', 'class'],
    'sortable' => false,
    'run' => function (array $f): array {
        $asof = $f['asof'];
        $cls = $f['class_id'] ?: null;
        $fy = Report::fiscalYearStart($asof);
        $bsBal = fin_balances(null, $asof, $cls);
        $plBal = fin_balances($fy, $asof, $cls);
        $priorNi = fin_net_income(null, date('Y-m-d', strtotime("$fy -1 day")), $cls);
        $rows = [];
        $td = 0.0;
        $tc = 0.0;
        $re = Accts::get('retained_earnings');
        foreach (fin_accounts() as $a) {
            $isPl = in_array($a['category'], ['Income', 'Expense'], true);
            $net = $isPl ? ($plBal[$a['id']] ?? 0.0) : ($bsBal[$a['id']] ?? 0.0);
            if ((int)$a['id'] === $re) {
                $net -= $priorNi;
            }
            if (abs($net) < 0.005) {
                continue;
            }
            $rows[] = [
                'account' => $a['code'] . ' - ' . $a['name'], 'type' => AccountTypes::nameOf($a),
                'debit' => $net > 0 ? r2($net) : '', 'credit' => $net < 0 ? r2(-$net) : '',
                '_links' => ['account' => Report::link('rpt.general_ledger', ['account_id' => $a['id'], 'from' => $isPl ? $fy : '2000-01-01', 'to' => $asof, 'dates' => 'custom', 'class_id' => $cls])],
            ];
            $td += max(0, $net);
            $tc += max(0, -$net);
        }
        return [
            'columns' => [['key' => 'account', 'label' => 'Account'], ['key' => 'type', 'label' => 'Type'], ['key' => 'debit', 'label' => 'Debit', 'type' => 'money'], ['key' => 'credit', 'label' => 'Credit', 'type' => 'money']],
            'rows' => $rows,
            'totals' => ['account' => 'TOTAL', 'debit' => r2($td), 'credit' => r2($tc)],
            'notes' => fin_class_note($cls) . 'Income and expense accounts show fiscal year-to-date activity from ' . fdate($fy) . '; earlier years\' net income is included in Retained Earnings.'
                . (abs(r2($td) - r2($tc)) >= 0.01 ? ' <strong class="text-danger">Out of balance!</strong>' : ''),
        ];
    },
]);

/**
 * The accounts of a set of base types, classified as the statements show them (app/AccountTypes.php): by account type
 * (every type a classification of its own), and in each type the main accounts in code order, each with its
 * sub-accounts (a sub-account goes with its main account, whose type decides where both are shown).
 *  $bases : the built-in types of this part of the statement
 *  $vals  : fn(array $account): array column key => amount, signed for the statement
 *  $cols  : the column keys
 * Returns [['type' => type row, 'mains' => [['acct' => row, 'own' => vals, 'subs' => [[row, vals], ...], 'total' => vals]], 'total' => vals], ...];
 * an account with nothing in any column is left out, a main account is kept when it or a sub-account has an amount.
 */
function fin_classify(array $bases, callable $vals, array $cols): array
{
    $byId = array_column(fin_accounts(), null, 'id');
    $zero = array_fill_keys($cols, 0.0);
    $isZero = function (array $v) use ($cols): bool {
        foreach ($cols as $k) {
            if (abs($v[$k] ?? 0.0) >= 0.005) {
                return false;
            }
        }
        return true;
    };
    $add = function (array $a, array $b) use ($cols): array {
        foreach ($cols as $k) {
            $a[$k] = ($a[$k] ?? 0.0) + ($b[$k] ?? 0.0);
        }
        return $a;
    };
    $mains = [];
    foreach (fin_accounts() as $a) {
        $main = AccountTypes::mainOf($a, $byId);
        $type = AccountTypes::forAccount($main);
        if (!in_array($type['base_type'], $bases, true)) {
            continue;
        }
        $v = $vals($a) + $zero;
        $mid = (int)$main['id'];
        $mains[$mid] ??= ['acct' => $main, 'type' => $type, 'own' => $zero, 'subs' => [], 'total' => $zero];
        if ((int)$a['id'] === $mid) {
            $mains[$mid]['own'] = $v;
        } elseif (!$isZero($v)) {
            $mains[$mid]['subs'][] = [$a, $v];
        }
        $mains[$mid]['total'] = $add($mains[$mid]['total'], $v);
    }
    $blocks = [];
    foreach (AccountTypes::all() as $tid => $t) {
        if (!in_array($t['base_type'], $bases, true)) {
            continue;
        }
        $list = array_values(array_filter($mains, fn($m) => (int)$m['type']['id'] === (int)$tid && (!$isZero($m['own']) || $m['subs'])));
        if (!$list) {
            continue;
        }
        usort($list, fn($x, $y) => strcmp((string)$x['acct']['code'], (string)$y['acct']['code']));
        $total = $zero;
        foreach ($list as $m) {
            $total = $add($total, $m['total']);
        }
        $blocks[] = ['type' => $t, 'mains' => $list, 'total' => $total];
    }
    return $blocks;
}

/**
 * The rows of classified accounts (fin_classify()): a heading and a total for each account type (left out when the
 * part shows one built-in type only, its own heading being enough), each main account, and in the detailed statement
 * a main account with sub-accounts as a heading, its sub-accounts, its own entries and its total. The summary shows
 * the main accounts only, each with the total of its sub-accounts.
 *  $row    : fn(string $name, ?array $vals, string $class, ?string $url): array (the report's own row)
 *  $link   : fn(array $account): ?string (the drill-down of an account)
 *  $mainLink : fn(array $account): ?string (the drill-down of a main account with sub-accounts, in the summary)
 * Returns [rows, total].
 */
function fin_block_rows(array $blocks, array $cols, callable $row, int $indent, bool $summary, callable $link, ?callable $mainLink = null, ?callable $totalName = null): array
{
    $rows = [];
    $total = array_fill_keys($cols, 0.0);
    $heads = count($blocks) > 1 || ($blocks && (int)$blocks[0]['type']['is_system'] !== 1);
    $totalName ??= fn(string $n) => 'Total ' . $n;
    foreach ($blocks as $b) {
        $i = $indent;
        if ($heads) {
            $rows[] = $row($b['type']['name'], null, 'indent-' . $i . ' fw-semibold type-head', null);
            $i++;
        }
        foreach ($b['mains'] as $m) {
            $a = $m['acct'];
            $label = $a['code'] . ' - ' . $a['name'];
            if ($summary || !$m['subs']) {
                $rows[] = $row($label, $m['total'], 'indent-' . $i, $m['subs'] ? ($mainLink ? $mainLink($a) : null) : $link($a));
                continue;
            }
            $rows[] = $row($label, null, 'indent-' . $i . ' fw-semibold', $link($a));
            foreach ($m['subs'] as [$s, $v]) {
                $rows[] = $row($s['code'] . ' - ' . $s['name'], $v, 'indent-' . ($i + 1), $link($s));
            }
            $own = false;
            foreach ($cols as $k) {
                $own = $own || abs($m['own'][$k] ?? 0.0) >= 0.005;
            }
            if ($own) {
                $rows[] = $row($label . ' (own entries)', $m['own'], 'indent-' . ($i + 1), $link($a));
            }
            $rows[] = $row('Total ' . $label, $m['total'], 'indent-' . $i . ' acct-total', null);
        }
        if ($heads) {
            $rows[] = $row($totalName($b['type']['name']), $b['total'], 'indent-' . $indent . ' subtotal-row type-total', null);
        }
        foreach ($cols as $k) {
            $total[$k] += $b['total'][$k] ?? 0.0;
        }
    }
    return [$rows, $total];
}

/**
 * Trial Balance by Class (v33): the trial balance as of a date, with each class's debit and credit side by side (the
 * classes with activity, then Unclassified), and the Total debit and credit, which is the plain Trial Balance. As there:
 * income and expense accounts show the fiscal year to date, and the net income of earlier years is in Retained Earnings.
 * "One column per class" shows each class's net balance (debits less credits) in a single column instead.
 */
Report::define('trial_balance_class', [
    'filters' => ['asof'],
    'checks' => ['net_only' => 'One column per class (debit less credit)'],
    'sortable' => false,
    'run' => function (array $f): array {
        $asof = $f['asof'];
        $netOnly = ($f['net_only'] ?? '') === '1';
        $fy = Report::fiscalYearStart($asof);
        $re = Accts::get('retained_earnings');
        $plIds = array_flip(array_map('intval', DB::column("SELECT id FROM accounts WHERE category IN ('Income','Expense')")));
        $bal = [];
        $used = [];
        $add = function (int $acct, string $k, float $v) use (&$bal, &$used) {
            $bal[$acct][$k] = ($bal[$acct][$k] ?? 0.0) + $v;
            if (abs($v) >= 0.005) {
                $used[$k] = true;
            }
        };
        // balance sheet accounts: everything up to the date; income and expense: the fiscal year to date
        foreach (DB::all('SELECT account_id, COALESCE(class_id, 0) AS cls,
                                 SUM(debit - credit) AS bs, SUM(CASE WHEN txn_date >= :fy THEN debit - credit ELSE 0 END) AS pl
                            FROM journal_lines WHERE txn_date <= :t GROUP BY account_id, COALESCE(class_id, 0)', ['t' => $asof, 'fy' => $fy]) as $r) {
            $acct = (int)$r['account_id'];
            $add($acct, 'c' . (int)$r['cls'], (float)(isset($plIds[$acct]) ? $r['pl'] : $r['bs']));
        }
        // the net income of earlier fiscal years belongs to Retained Earnings, class by class
        foreach (fin_net_income_by_class(null, date('Y-m-d', strtotime("$fy -1 day"))) as $k => $v) {
            $add($re, $k, -$v);
        }
        $buckets = fin_class_buckets($used);
        // the Total columns are the Trial Balance: the whole cooperative, the balances between its lines of business
        // eliminated as they are there (fin_balances); the class columns show the entries as booked
        $netTotal = BusinessUnits::eliminate(array_map(fn($v) => array_sum($v), $bal));
        // no Type column here: with a debit and a credit per class the table is wide enough already
        $columns = [['key' => 'account', 'label' => 'Account']];
        foreach ($buckets as $k => $label) {
            if ($netOnly) {
                $columns[] = ['key' => $k, 'label' => $label, 'type' => 'money'];
            } else {
                $columns[] = ['key' => $k . '_dr', 'label' => $label . ' Debit', 'type' => 'money'];
                $columns[] = ['key' => $k . '_cr', 'label' => $label . ' Credit', 'type' => 'money'];
            }
        }
        $columns[] = ['key' => 'debit', 'label' => 'Total Debit', 'type' => 'money'];
        $columns[] = ['key' => 'credit', 'label' => 'Total Credit', 'type' => 'money'];
        $rows = [];
        $tot = ['account' => 'TOTAL', 'debit' => 0.0, 'credit' => 0.0];
        $side = [];
        foreach (fin_accounts() as $a) {
            $vals = $bal[(int)$a['id']] ?? [];
            $net = (float)($netTotal[(int)$a['id']] ?? 0.0);
            if (abs($net) < 0.005 && !array_filter($vals, fn($v) => abs($v) >= 0.005)) {
                continue;
            }
            $isPl = isset($plIds[(int)$a['id']]);
            $link = Report::link('rpt.general_ledger', ['account_id' => $a['id'], 'from' => $isPl ? $fy : '2000-01-01', 'to' => $asof, 'dates' => 'custom']);
            $row = ['account' => $a['code'] . ' - ' . $a['name'], '_links' => ['account' => $link]];
            foreach ($buckets as $k => $_) {
                $v = r2($vals[$k] ?? 0.0);
                $cid = (int)substr($k, 1) ?: Classes::UNCLASSIFIED;
                if ($netOnly) {
                    $row[$k] = abs($v) >= 0.005 ? $v : '';
                    $tot[$k] = ($tot[$k] ?? 0.0) + $v;
                    if ($link && abs($v) >= 0.005) {
                        $row['_links'][$k] = $link . '&class_id=' . $cid;
                    }
                } else {
                    $row[$k . '_dr'] = $v > 0 ? $v : '';
                    $row[$k . '_cr'] = $v < 0 ? -$v : '';
                    $tot[$k . '_dr'] = ($tot[$k . '_dr'] ?? 0.0) + max(0, $v);
                    $tot[$k . '_cr'] = ($tot[$k . '_cr'] ?? 0.0) + max(0, -$v);
                    if ($link && abs($v) >= 0.005) {
                        $row['_links'][$k . ($v > 0 ? '_dr' : '_cr')] = $link . '&class_id=' . $cid;
                    }
                }
                $side[$k] = ($side[$k] ?? 0.0) + $v;
            }
            $net = r2($net);
            $row['debit'] = $net > 0 ? $net : '';
            $row['credit'] = $net < 0 ? -$net : '';
            $tot['debit'] += max(0, $net);
            $tot['credit'] += max(0, -$net);
            $rows[] = $row;
        }
        foreach ($tot as $k => $v) {
            if ($k !== 'account') {
                $tot[$k] = r2($v);
            }
        }
        $off = [];
        foreach ($buckets as $k => $label) {
            if (abs(r2($side[$k] ?? 0.0)) >= 0.01) {
                $off[] = $label;
            }
        }
        $note = fin_class_note(null) . 'Income and expense accounts show fiscal year-to-date activity from ' . fdate($fy) . '; earlier years\' net income is included in Retained Earnings. '
            . 'Entries without a class are under Unclassified; the Total columns are the Trial Balance of the whole company.';
        if (abs($tot['debit'] - $tot['credit']) >= 0.01) {
            $note .= ' <strong class="text-danger">Out of balance!</strong>';
        } elseif ($off) {
            $note .= ' The debits and credits of ' . h(implode(', ', $off)) . ' differ on their own: some entries carry a different class on each side (journal entry or cash voucher lines with their own class, or payments applied across classes).';
        }
        if (!Classes::inUse()) {
            $note .= ' No classes are set up yet, so everything is unclassified. Add classes under Financial, Class List.';
        }
        return ['columns' => $columns, 'rows' => $rows, 'totals' => $tot, 'notes' => $note];
    },
]);

/**
 * Balance sheet rows for one or more amount columns.
 *  $cols  : column keys (['amount'] for the plain statement; class buckets plus 'total' for the by-class one)
 *  $bal   : column key => [account id => net debit balance]
 *  $prior : column key => net income of earlier fiscal years (shown inside Retained Earnings)
 *  $ytd   : column key => net income of the fiscal year to date
 *  $summary : the main accounts only, each with the total of its sub-accounts (Balance Sheet Summary)
 * Every account type is a classification of its own (a heading, its accounts, its total) under the group of its built-in
 * type (Current Assets, Fixed Assets, ...). Returns [rows, section totals per column].
 */
function fin_balance_sheet_rows(array $cols, array $bal, array $prior, array $ytd, string $asof, string $fy, array $linkExtra = [], bool $summary = false, ?string $niLabel = null): array
{
    $re = Accts::get('retained_earnings');
    $sections = [
        'ASSETS' => ['Current Assets' => ['Bank', 'Accounts Receivable', 'Other Current Asset'], 'Fixed Assets' => ['Fixed Asset'], 'Other Assets' => ['Other Asset']],
        'LIABILITIES' => ['Current Liabilities' => ['Accounts Payable', 'Credit Card', 'Other Current Liability'], 'Long-term Liabilities' => ['Long Term Liability']],
        'EQUITY' => ['Equity' => ['Equity']],
    ];
    $zero = array_fill_keys($cols, 0.0);
    $withVals = function (array $row, array $vals) use ($cols): array {
        foreach ($cols as $k) {
            $row[$k] = r2($vals[$k] ?? 0);
        }
        return $row;
    };
    $row = function (string $name, ?array $vals, string $class, ?string $url) use ($withVals): array {
        $r = ['name' => $name, '_class' => $class];
        if ($url) {
            $r['_links'] = ['name' => $url];
        }
        return $vals === null ? $r : $withVals($r, $vals);
    };
    $link = fn(array $a) => Report::link('rpt.general_ledger', ['account_id' => $a['id'], 'from' => '2000-01-01', 'to' => $asof, 'dates' => 'custom'] + $linkExtra);
    $mainLink = fn(array $a) => Report::link('rpt.balance_sheet', ['asof' => $asof] + $linkExtra);
    $rows = [];
    $totals = [];
    foreach ($sections as $sec => $groups) {
        $rows[] = ['name' => $sec, '_class' => 'group-row'];
        $secTotal = $zero;
        foreach ($groups as $gname => $types) {
            $vals = function (array $a) use ($cols, $bal, $prior, $sec, $re): array {
                $v = [];
                foreach ($cols as $k) {
                    $net = $bal[$k][$a['id']] ?? 0.0;
                    if ($sec !== 'ASSETS') {
                        $net = -$net;
                    }
                    if ((int)$a['id'] === $re) {
                        $net += $prior[$k] ?? 0.0;
                    }
                    $v[$k] = $net;
                }
                return $v;
            };
            [$gRows, $gTotal] = fin_block_rows(fin_classify($types, $vals, $cols), $cols, $row, 2, $summary, $link, $mainLink);
            if ($sec === 'EQUITY') {
                $gRows[] = $withVals(['name' => $niLabel ?? 'Net Income (fiscal year to date)', '_class' => 'indent-2',
                    '_links' => ['name' => Report::link('rpt.income_statement' . ($summary ? '_summary' : ''), ['from' => $fy, 'to' => $asof, 'dates' => 'custom'] + $linkExtra)]], $ytd);
                foreach ($cols as $k) {
                    $gTotal[$k] += $ytd[$k] ?? 0.0;
                }
            }
            if (!$gRows) {
                continue;
            }
            $rows[] = ['name' => $gname, '_class' => 'indent-1 fw-semibold'];
            $rows = array_merge($rows, $gRows);
            $rows[] = $withVals(['name' => 'Total ' . $gname, '_class' => 'subtotal-row indent-1'], $gTotal);
            foreach ($cols as $k) {
                $secTotal[$k] += $gTotal[$k];
            }
        }
        $rows[] = $withVals(['name' => 'TOTAL ' . $sec, '_class' => 'total-row'], $secTotal);
        $totals[$sec] = $secTotal;
    }
    $le = [];
    foreach ($cols as $k) {
        $le[$k] = ($totals['LIABILITIES'][$k] ?? 0) + ($totals['EQUITY'][$k] ?? 0);
    }
    $rows[] = $withVals(['name' => 'TOTAL LIABILITIES AND EQUITY', '_class' => 'total-row'], $le);
    $totals['LE'] = $le;
    return [$rows, $totals];
}

/** The Balance Sheet, or with $summary the Balance Sheet Summary (the main accounts only) */
function fin_balance_sheet_report(array $f, bool $summary): array
{
    $asof = $f['asof'];
    $cls = $f['class_id'] ?: null;
    $fy = Report::fiscalYearStart($asof);
    [$rows, $totals] = fin_balance_sheet_rows(
        ['amount'],
        ['amount' => fin_balances(null, $asof, $cls)],
        ['amount' => fin_net_income(null, date('Y-m-d', strtotime("$fy -1 day")), $cls)],
        ['amount' => fin_net_income($fy, $asof, $cls)],
        $asof, $fy, $cls ? ['class_id' => $cls] : [], $summary
    );
    $balanced = abs(($totals['ASSETS']['amount'] ?? 0) - ($totals['LE']['amount'] ?? 0)) < 0.01;
    return [
        'grouped' => true,
        'columns' => [['key' => 'name', 'label' => ''], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
        'rows' => $rows,
        'notes' => fin_class_note($cls) . ($balanced ? 'Assets = Liabilities + Equity.'
            : ($cls ? 'Assets and liabilities plus equity differ for this class: some entries carry a different class on each side (journal entry or cash voucher lines with their own class).'
                : '<strong class="text-danger">Assets do not equal liabilities and equity.</strong>'))
            . ' ' . ($summary ? 'The main accounts only: each shows the total of its sub-accounts (the Balance Sheet lists them).'
                : 'Each account type is a classification of its own; a main account with sub-accounts shows them and their total.'),
    ];
}

Report::define('balance_sheet', [
    'filters' => ['asof', 'class'],
    'sortable' => false,
    'run' => fn(array $f): array => fin_balance_sheet_report($f, false),
]);

Report::define('balance_sheet_summary', [
    'filters' => ['asof', 'class'],
    'sortable' => false,
    'run' => fn(array $f): array => fin_balance_sheet_report($f, true),
]);

Report::define('balance_sheet_class', [
    'filters' => ['asof'],
    'sortable' => false,
    'run' => function (array $f): array {
        $asof = $f['asof'];
        $fy = Report::fiscalYearStart($asof);
        $bal = [];
        $used = [];
        foreach (DB::all('SELECT account_id, COALESCE(class_id, 0) AS cls, SUM(debit - credit) AS net FROM journal_lines WHERE txn_date <= :t GROUP BY account_id, COALESCE(class_id, 0)', ['t' => $asof]) as $r) {
            $k = 'c' . (int)$r['cls'];
            $bal[$k][(int)$r['account_id']] = (float)$r['net'];
            $bal['total'][(int)$r['account_id']] = ($bal['total'][(int)$r['account_id']] ?? 0) + (float)$r['net'];
            if (abs((float)$r['net']) >= 0.005) {
                $used[$k] = true;
            }
        }
        $prior = fin_net_income_by_class(null, date('Y-m-d', strtotime("$fy -1 day")));
        $ytd = fin_net_income_by_class($fy, $asof);
        $prior['total'] = array_sum($prior);
        $ytd['total'] = array_sum($ytd);
        $buckets = fin_class_buckets($used + array_fill_keys(array_keys(array_filter($ytd + $prior, fn($v) => abs($v) >= 0.005)), true));
        unset($buckets['total']);
        $cols = array_merge(array_keys($buckets), ['total']);
        [$rows, $totals] = fin_balance_sheet_rows($cols, $bal, $prior, $ytd, $asof, $fy);
        $columns = [['key' => 'name', 'label' => '']];
        foreach ($buckets as $k => $label) {
            $columns[] = ['key' => $k, 'label' => $label, 'type' => 'money'];
        }
        $columns[] = ['key' => 'total', 'label' => 'Total', 'type' => 'money'];
        // per-class drill-down on the account rows
        foreach ($rows as &$r) {
            if (!empty($r['_links']['name'])) {
                foreach ($buckets as $k => $_) {
                    $cid = (int)substr($k, 1) ?: Classes::UNCLASSIFIED;
                    $r['_links'][$k] = $r['_links']['name'] . '&class_id=' . $cid;
                }
            }
        }
        unset($r);
        $off = [];
        foreach ($buckets as $k => $label) {
            if (abs(($totals['ASSETS'][$k] ?? 0) - ($totals['LE'][$k] ?? 0)) >= 0.01) {
                $off[] = $label;
            }
        }
        $note = abs(($totals['ASSETS']['total'] ?? 0) - ($totals['LE']['total'] ?? 0)) >= 0.01
            ? '<strong class="text-danger">Assets do not equal liabilities and equity.</strong>'
            : 'Assets = Liabilities + Equity in total.';
        if ($off) {
            $note .= ' The columns ' . h(implode(', ', $off)) . ' do not balance on their own: some entries carry a different class on each side (journal entry or cash voucher lines with their own class, or payments applied across classes).';
        }
        if (!Classes::inUse()) {
            $note .= ' No classes are set up yet, so everything is unclassified. Add classes under Financial, Class List.';
        }
        return ['grouped' => true, 'columns' => $columns, 'rows' => $rows, 'notes' => $note];
    },
]);

/**
 * Income statement rows for any set of amount columns.
 *  $by   : account id => [column key => net (credit - debit)], with '__total' for the total column
 *  $keys : column keys between the name and the total
 *  $link : fn(account row) => url of the drill-down, or null
 *  $summary : the main accounts only, each with the total of its sub-accounts (Income Statement Summary);
 *             $mainLink: the drill-down of such a main account
 * Every account type is a classification of its own (a heading, its accounts, its total) in the section of its
 * built-in type (Income, Cost of Goods Sold, Expenses, Other Income, Other Expenses).
 */
function fin_income_rows(array $by, array $keys, callable $link, bool $summary = false, ?callable $mainLink = null): array
{
    $sections = [
        'Income' => [['Income'], 1],
        'Cost of Goods Sold' => [['Cost of Goods Sold'], -1],
        'Expenses' => [['Expense'], -1],
        'Other Income' => [['Other Income'], 1],
        'Other Expenses' => [['Other Expense'], -1],
    ];
    $cols = array_merge($keys, ['__total']);
    $rows = [];
    $sum = [];
    $makeRow = function (string $name, ?array $vals, string $class, ?string $url = null) use ($keys) {
        $r = ['name' => $name, '_class' => $class];
        if ($vals !== null) {
            $r['total'] = r2($vals['__total'] ?? 0);
            foreach ($keys as $k) {
                $r['p_' . $k] = r2($vals[$k] ?? 0);
            }
        }
        if ($url) {
            $r['_links'] = ['name' => $url];
        }
        return $r;
    };
    foreach ($sections as $sec => [$types, $sign]) {
        $vals = fn(array $a): array => array_map(fn($v) => $sign * $v, $by[$a['id']] ?? []);
        [$secRows, $secVals] = fin_block_rows(fin_classify($types, $vals, $cols), $cols, $makeRow, 1, $summary, fn($a) => $link($a), $mainLink);
        $sum[$sec] = $secVals;
        if ($secRows) {
            $rows[] = ['name' => $sec, '_class' => 'group-row'];
            $rows = array_merge($rows, $secRows);
            $rows[] = $makeRow('Total ' . $sec, $secVals, 'subtotal-row');
        }
        if ($sec === 'Cost of Goods Sold') {
            $gp = [];
            foreach ($cols as $k) {
                $gp[$k] = ($sum['Income'][$k] ?? 0) - ($secVals[$k] ?? 0);
            }
            $rows[] = $makeRow('GROSS PROFIT', $gp, 'total-row');
            $sum['Gross'] = $gp;
        }
        if ($sec === 'Expenses') {
            $op = [];
            foreach ($cols as $k) {
                $op[$k] = ($sum['Gross'][$k] ?? 0) - ($secVals[$k] ?? 0);
            }
            $rows[] = $makeRow('NET OPERATING INCOME', $op, 'total-row');
            $sum['Operating'] = $op;
        }
    }
    $ni = [];
    foreach ($cols as $k) {
        $ni[$k] = ($sum['Operating'][$k] ?? 0) + ($sum['Other Income'][$k] ?? 0) - ($sum['Other Expenses'][$k] ?? 0);
    }
    $rows[] = $makeRow('NET INCOME', $ni, 'total-row');
    return $rows;
}

/** The Income Statement, or with $summary the Income Statement Summary (the main accounts only) */
function fin_income_statement_report(array $f, bool $summary): array
{
    $mode = $f['columns'] ?: 'total';
    $cls = $f['class_id'] ?: null;
    $periods = Report::periods($f['from'], $f['to'], $mode);
    [$csql, $cp] = Classes::sqlFilter($cls, 'j');
    $data = DB::all(
        "SELECT j.account_id, j.txn_date, SUM(j.credit - j.debit) AS net FROM journal_lines j JOIN accounts a ON a.id = j.account_id
          WHERE a.category IN ('Income','Expense') AND j.txn_date BETWEEN :f AND :t $csql GROUP BY j.account_id, j.txn_date",
        ['f' => $f['from'], 't' => $f['to']] + $cp
    );
    $by = [];
    foreach ($data as $d) {
        $pk = $periods ? Report::periodKey($d['txn_date'], $mode) : 'total';
        $by[$d['account_id']][$pk] = ($by[$d['account_id']][$pk] ?? 0) + (float)$d['net'];
        $by[$d['account_id']]['__total'] = ($by[$d['account_id']]['__total'] ?? 0) + (float)$d['net'];
    }
    // the whole cooperative: inter-unit sales and charges net out against their cost
    $by = BusinessUnits::eliminateColumns($by);
    $cols = [['key' => 'name', 'label' => '']];
    foreach ($periods as $k => $label) {
        $cols[] = ['key' => 'p_' . $k, 'label' => $label, 'type' => 'money'];
    }
    $cols[] = ['key' => 'total', 'label' => 'Total', 'type' => 'money'];
    $rows = fin_income_rows($by, array_keys($periods), fn($a) => Report::link('rpt.general_ledger',
        ['account_id' => $a['id'], 'from' => $f['from'], 'to' => $f['to'], 'dates' => 'custom', 'class_id' => $cls]), $summary,
        fn($a) => Report::link('rpt.income_statement', ['from' => $f['from'], 'to' => $f['to'], 'dates' => 'custom', 'columns' => $f['columns'] ?: null, 'class_id' => $cls]));
    return ['grouped' => true, 'columns' => $cols, 'rows' => $rows, 'notes' => fin_class_note($cls)
        . ($summary ? 'The main accounts only: each shows the total of its sub-accounts (the Income Statement lists them).'
            : 'Each account type is a classification of its own; a main account with sub-accounts shows them and their total.')];
}

Report::define('income_statement', [
    'filters' => ['range', 'columns', 'class'],
    'default_dates' => 'year_to_date',
    'sortable' => false,
    'run' => fn(array $f): array => fin_income_statement_report($f, false),
]);

Report::define('income_statement_summary', [
    'filters' => ['range', 'columns', 'class'],
    'default_dates' => 'year_to_date',
    'sortable' => false,
    'run' => fn(array $f): array => fin_income_statement_report($f, true),
]);

Report::define('income_statement_class', [
    'filters' => ['range'],
    'default_dates' => 'year_to_date',
    'sortable' => false,
    'run' => function (array $f): array {
        $data = DB::all(
            "SELECT j.account_id, COALESCE(j.class_id, 0) AS cls, SUM(j.credit - j.debit) AS net FROM journal_lines j JOIN accounts a ON a.id = j.account_id
              WHERE a.category IN ('Income','Expense') AND j.txn_date BETWEEN :f AND :t GROUP BY j.account_id, COALESCE(j.class_id, 0)",
            ['f' => $f['from'], 't' => $f['to']]
        );
        $by = [];
        $used = [];
        foreach ($data as $d) {
            $k = 'c' . (int)$d['cls'];
            $by[$d['account_id']][$k] = ($by[$d['account_id']][$k] ?? 0) + (float)$d['net'];
            $by[$d['account_id']]['__total'] = ($by[$d['account_id']]['__total'] ?? 0) + (float)$d['net'];
            if (abs((float)$d['net']) >= 0.005) {
                $used[$k] = true;
            }
        }
        $buckets = fin_class_buckets($used);
        $cols = [['key' => 'name', 'label' => '']];
        foreach ($buckets as $k => $label) {
            $cols[] = ['key' => 'p_' . $k, 'label' => $label, 'type' => 'money'];
        }
        $cols[] = ['key' => 'total', 'label' => 'Total', 'type' => 'money'];
        $rows = fin_income_rows($by, array_keys($buckets), fn($a) => Report::link('rpt.general_ledger',
            ['account_id' => $a['id'], 'from' => $f['from'], 'to' => $f['to'], 'dates' => 'custom']));
        // each class column drills down to the ledger of that class
        foreach ($rows as &$r) {
            if (!empty($r['_links']['name'])) {
                foreach ($buckets as $k => $_) {
                    $cid = (int)substr($k, 1) ?: Classes::UNCLASSIFIED;
                    $r['_links']['p_' . $k] = $r['_links']['name'] . '&class_id=' . $cid;
                }
            }
        }
        unset($r);
        $note = Classes::inUse() ? 'One column per class; entries without a class are under Unclassified.'
            : 'No classes are set up yet, so everything is unclassified. Add classes under Financial, Class List.';
        return ['grouped' => true, 'columns' => $cols, 'rows' => $rows, 'notes' => $note];
    },
]);

/**
 * Statement of cash flows rows (indirect method) for one or more amount columns: the Statement of Cash Flows (one
 * column) and the Statement of Cash Flows by Class (v33: one column per class and a Total). Every balance sheet account
 * is counted once, in the section its account type is linked to (Financial > Account Types); the accounts counted as
 * cash and cash equivalents (fin_cash_accounts) give the cash at the beginning and at the end. Accumulated
 * depreciation (a credit Fixed Asset account in investing) goes back to operating activities, a non-cash charge.
 *  $cols   : column keys (['amount'], or class buckets plus 'total')
 *  $change : column key => [account id => net debit change in the period]
 *  $ni     : column key => net income of the period
 *  $begin  : column key => cash balance at the start of the period
 *  $link   : fn(array $account): ?string, the drill-down of an account
 * Returns [rows, column key => net cash increase].
 */
function fin_cash_flow_rows(array $cols, array $change, array $ni, array $begin, ?callable $link = null): array
{
    $byId = array_column(fin_accounts(), null, 'id');
    $flowOf = fn(array $a): string => fin_flow_of($a, $byId);
    $isDepr = fn(array $a): bool => $flowOf($a) === 'investing' && $a['account_type'] === 'Fixed Asset' && $a['normal_balance'] === 'Credit';
    $vals = fn(array $row, array $v) => $row + array_map(fn($x) => r2((float)$x), array_intersect_key($v + array_fill_keys($cols, 0.0), array_flip($cols)));
    $link ??= fn(array $a) => null;
    $tot = [];
    foreach ($cols as $k) {
        $tot['op'][$k] = (float)($ni[$k] ?? 0.0);
        $tot['inv'][$k] = 0.0;
        $tot['fin'][$k] = 0.0;
    }
    // depreciation set apart: a non-cash adjustment to operating activities
    $depr = [];
    foreach ($byId as $a) {
        if (!in_array($a['category'], ['Asset', 'Liability', 'Equity'], true) || !$isDepr($a)) {
            continue;
        }
        $v = [];
        $any = false;
        foreach ($cols as $k) {
            $v[$k] = -($change[$k][$a['id']] ?? 0.0);
            $any = $any || abs($v[$k]) >= 0.005;
        }
        if (!$any) {
            continue;
        }
        $r = $vals(['name' => $a['code'] . ' - ' . $a['name'] . ' (depreciation)', '_class' => 'indent-1'], $v);
        if ($u = $link($a)) {
            $r['_links'] = ['name' => $u];
        }
        $depr[] = $r;
        foreach ($cols as $k) {
            $tot['op'][$k] += $v[$k];
        }
    }
    $row = function (string $name, ?array $v, string $class, ?string $url) use ($vals) {
        $r = ['name' => $name, '_class' => $class];
        if ($v !== null) {
            $r = $vals($r, $v);
        }
        if ($url) {
            $r['_links'] = ['name' => $url];
        }
        return $r;
    };
    $bases = array_keys(array_filter(AccountTypes::BASE, fn($b) => $b[0] !== 'Income' && $b[0] !== 'Expense'));
    $lines = [];
    foreach (['operating' => 'op', 'investing' => 'inv', 'financing' => 'fin'] as $flow => $g) {
        // the accounts whose type is linked to this section (the bases of every balance sheet type)
        $valsOf = function (array $a) use ($flow, $flowOf, $isDepr, $change, $cols): array {
            $in = $flowOf($a) === $flow && !$isDepr($a);
            $out = [];
            foreach ($cols as $k) {
                $out[$k] = $in ? -($change[$k][$a['id']] ?? 0.0) : 0.0;
            }
            return $out;
        };
        $blocks = array_values(array_filter(fin_classify($bases, $valsOf, $cols),
            fn($b) => ($b['type']['cash_flow'] ?: AccountTypes::defaultCashFlow($b['type']['base_type'])) === $flow));
        [$lines[$g], $t] = fin_block_rows($blocks, $cols, $row, 1, true, $link, null, fn($n) => 'Net change in ' . $n);
        foreach ($cols as $k) {
            $tot[$g][$k] += $t[$k] ?? 0.0;
        }
    }
    $rows = [['name' => 'OPERATING ACTIVITIES', '_class' => 'group-row'], $vals(['name' => 'Net Income', '_class' => 'indent-1'], $ni)];
    $rows = array_merge($rows, $depr, $lines['op']);
    $rows[] = $vals(['name' => 'Net cash provided by operating activities', '_class' => 'subtotal-row'], $tot['op']);
    $rows[] = ['name' => 'INVESTING ACTIVITIES', '_class' => 'group-row'];
    $rows = array_merge($rows, $lines['inv']);
    $rows[] = $vals(['name' => 'Net cash provided by investing activities', '_class' => 'subtotal-row'], $tot['inv']);
    $rows[] = ['name' => 'FINANCING ACTIVITIES', '_class' => 'group-row'];
    $rows = array_merge($rows, $lines['fin']);
    $rows[] = $vals(['name' => 'Net cash provided by financing activities', '_class' => 'subtotal-row'], $tot['fin']);
    $net = [];
    $endv = [];
    foreach ($cols as $k) {
        $net[$k] = r2($tot['op'][$k] + $tot['inv'][$k] + $tot['fin'][$k]);
        $endv[$k] = r2(($begin[$k] ?? 0.0) + $net[$k]);
    }
    $rows[] = $vals(['name' => 'NET CASH INCREASE (DECREASE) FOR THE PERIOD', '_class' => 'total-row'], $net);
    $rows[] = $vals(['name' => 'Cash at beginning of period'], $begin);
    $rows[] = $vals(['name' => 'CASH AT END OF PERIOD', '_class' => 'total-row'], $endv);
    return [$rows, $net];
}

/** The cash flow section of a balance sheet account: cash, operating, investing or financing (its account type's) */
function fin_flow_of(array $a, ?array $byId = null): string
{
    $byId ??= array_column(fin_accounts(), null, 'id');
    $t = AccountTypes::forAccount(AccountTypes::mainOf($a, $byId));
    return $t['cash_flow'] ?: (AccountTypes::defaultCashFlow($t['base_type']) ?: 'operating');
}

/** The accounts counted as cash and cash equivalents (their account type's cash flow section is cash) */
function fin_cash_accounts(): array
{
    $byId = array_column(fin_accounts(), null, 'id');
    $ids = [];
    foreach ($byId as $a) {
        if (in_array($a['category'], ['Asset', 'Liability', 'Equity'], true) && fin_flow_of($a, $byId) === 'cash') {
            $ids[] = (int)$a['id'];
        }
    }
    return $ids ?: [0];
}

/** The names of the account types counted as cash and cash equivalents (the note of the cash flow statements) */
function fin_cash_type_names(): string
{
    return implode(', ', array_values(array_map(fn($t) => $t['name'], array_filter(AccountTypes::all(), fn($t) => $t['cash_flow'] === 'cash')))) ?: 'none';
}

/**
 * The Statement of Cash Flows (indirect method). Every balance sheet account is counted once, in the section its account
 * type is linked to (Chart of Accounts > Account Types): the cash and cash equivalents give the cash at the beginning
 * and at the end; the changes of the others are operating, investing or financing activities, listed by account type
 * (each main account with its sub-accounts). Accumulated depreciation (a credit Fixed Asset account in investing)
 * goes back to operating activities, a non-cash charge. The statement ties to the cash accounts.
 */
Report::define('cash_flow', [
    'filters' => ['range', 'class'],
    'default_dates' => 'year_to_date',
    'sortable' => false,
    'run' => function (array $f): array {
        $from = $f['from'];
        $to = $f['to'];
        $cls = $f['class_id'] ?: null;
        [$csql, $cp] = Classes::sqlFilter($cls, '');
        [$in, $p] = DB::in('b', fin_cash_accounts());
        $begin = (float)DB::value("SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id IN $in AND txn_date < :f $csql", $p + ['f' => $from] + $cp);
        $end = (float)DB::value("SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id IN $in AND txn_date <= :t $csql", $p + ['t' => $to] + $cp);
        $link = fn(array $a) => Report::link('rpt.general_ledger', ['account_id' => $a['id'], 'from' => $from, 'to' => $to, 'dates' => 'custom', 'class_id' => $cls]);
        [$rows, $netCols] = fin_cash_flow_rows(['amount'], ['amount' => fin_balances($from, $to, $cls)], ['amount' => fin_net_income($from, $to, $cls)], ['amount' => r2($begin)], $link);
        $net = $netCols['amount'];
        return [
            'grouped' => true,
            'columns' => [['key' => 'name', 'label' => ''], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
            'rows' => $rows,
            'notes' => fin_class_note($cls) . 'Indirect method. Cash = the accounts of the types counted as cash and cash equivalents (' . h(fin_cash_type_names()) . '); '
                . 'every other balance sheet account is an operating, investing or financing activity by its account type (Financial &gt; Account Types).'
                . (abs(r2($begin + $net) - r2($end)) >= 0.01 ? ' <strong class="text-danger">Does not tie to the cash accounts (' . money($end) . ').</strong>' : ''),
        ];
    },
]);

/**
 * Statement of Cash Flows by Class (v33): the statement of cash flows with one column per class (the classes with
 * activity, then Unclassified) and a Total column, which is the plain statement. Each class column works out its own
 * net income, changes in the balance sheet accounts and cash balances from the entries carrying that class; the
 * sections follow the account types (Financial > Account Types), as on the plain statement.
 */
Report::define('cash_flow_class', [
    'filters' => ['range'],
    'default_dates' => 'year_to_date',
    'sortable' => false,
    'run' => function (array $f): array {
        $from = $f['from'];
        $to = $f['to'];
        $change = [];
        $used = [];
        foreach (DB::all('SELECT account_id, COALESCE(class_id, 0) AS cls, SUM(debit - credit) AS net FROM journal_lines
                           WHERE txn_date BETWEEN :f AND :t GROUP BY account_id, COALESCE(class_id, 0)', ['f' => $from, 't' => $to]) as $r) {
            $k = 'c' . (int)$r['cls'];
            $change[$k][(int)$r['account_id']] = (float)$r['net'];
            $change['total'][(int)$r['account_id']] = ($change['total'][(int)$r['account_id']] ?? 0.0) + (float)$r['net'];
            if (abs((float)$r['net']) >= 0.005) {
                $used[$k] = true;
            }
        }
        // the Total column is the plain statement: the whole cooperative, its lines of business netted (as fin_balances)
        $change['total'] = BusinessUnits::eliminate($change['total'] ?? []);
        $ni = fin_net_income_by_class($from, $to);
        $ni['total'] = array_sum($ni);
        [$in, $p] = DB::in('b', fin_cash_accounts());
        $begin = ['total' => 0.0];
        $end = ['total' => 0.0];
        foreach (DB::all("SELECT COALESCE(class_id, 0) AS cls, SUM(CASE WHEN txn_date < :f THEN debit - credit ELSE 0 END) AS b, SUM(debit - credit) AS e
                            FROM journal_lines WHERE account_id IN $in AND txn_date <= :t GROUP BY COALESCE(class_id, 0)", $p + ['f' => $from, 't' => $to]) as $r) {
            $k = 'c' . (int)$r['cls'];
            $begin[$k] = r2((float)$r['b']);
            $end[$k] = r2((float)$r['e']);
            $begin['total'] += (float)$r['b'];
            $end['total'] += (float)$r['e'];
            if (abs((float)$r['b']) >= 0.005 || abs((float)$r['e']) >= 0.005) {
                $used[$k] = true;
            }
        }
        $begin['total'] = r2($begin['total']);
        $buckets = fin_class_buckets($used);
        $cols = array_merge(array_keys($buckets), ['total']);
        $link = fn(array $a) => Report::link('rpt.general_ledger', ['account_id' => $a['id'], 'from' => $from, 'to' => $to, 'dates' => 'custom']);
        [$rows, $net] = fin_cash_flow_rows($cols, $change, $ni, $begin, $link);
        $columns = [['key' => 'name', 'label' => '']];
        foreach ($buckets as $k => $label) {
            $columns[] = ['key' => $k, 'label' => $label, 'type' => 'money'];
        }
        $columns[] = ['key' => 'total', 'label' => 'Total', 'type' => 'money'];
        $off = [];
        foreach ($cols as $k) {
            if (abs(r2(($begin[$k] ?? 0.0) + $net[$k]) - r2($end[$k] ?? 0.0)) >= 0.01) {
                $off[] = $k === 'total' ? 'Total' : $buckets[$k];
            }
        }
        $note = 'One column per class; entries without a class are under Unclassified, and Total is the Statement of Cash Flows of the whole company. Indirect method. Cash = the accounts of the types counted as cash and cash equivalents ('
            . h(fin_cash_type_names()) . '); every other balance sheet account is an operating, investing or financing activity by its account type (Financial &gt; Account Types).';
        if (in_array('Total', $off, true)) {
            $note .= ' <strong class="text-danger">The total does not tie to the cash accounts (' . money($end['total']) . ').</strong>';
        } elseif ($off) {
            $note .= ' The columns ' . h(implode(', ', $off)) . ' do not tie to their own cash balances: some entries carry a different class on each side (journal entry or cash voucher lines with their own class, or payments applied across classes).';
        }
        if (!Classes::inUse()) {
            $note .= ' No classes are set up yet, so everything is unclassified. Add classes under Financial, Class List.';
        }
        return ['grouped' => true, 'columns' => $columns, 'rows' => $rows, 'notes' => $note];
    },
]);

Report::define('general_ledger', [
    'filters' => ['range', 'account', 'class', 'search'],
    'sortable' => false,
    'run' => function (array $f): array {
        $accts = $f['account_id'] ? array_filter(fin_accounts(), fn($a) => (int)$a['id'] === $f['account_id']) : fin_accounts();
        $cls = $f['class_id'] ?: null;
        [$csql, $cp] = Classes::sqlFilter($cls, '');
        [$cjsql, $cjp] = Classes::sqlFilter($cls, 'j', 'fclassj');
        $rows = [];
        foreach ($accts as $a) {
            $opening = (float)DB::value("SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND txn_date < :f $csql", ['a' => $a['id'], 'f' => $f['from']] + $cp);
            if (in_array($a['category'], ['Income', 'Expense'], true)) {
                $fy = Report::fiscalYearStart($f['from']);
                $opening = (float)DB::value("SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND txn_date < :f AND txn_date >= :fy $csql", ['a' => $a['id'], 'f' => $f['from'], 'fy' => $fy] + $cp);
            }
            $sql = "SELECT j.*, t.txn_type, t.txn_no, COALESCE(c.name, v.name, o.name, t.payee_name) AS party, cl.name AS class_name FROM journal_lines j JOIN transactions t ON t.id = j.txn_id
                     LEFT JOIN customers c ON c.id = j.customer_id LEFT JOIN vendors v ON v.id = j.vendor_id
                     LEFT JOIN other_names o ON o.id = j.other_name_id LEFT JOIN classes cl ON cl.id = j.class_id
                    WHERE j.account_id = :a AND j.txn_date BETWEEN :f AND :t $cjsql";
            $p = ['a' => $a['id'], 'f' => $f['from'], 't' => $f['to']] + $cjp;
            if ($f['q'] !== '') {
                $sql .= ' AND (j.memo LIKE :q OR t.txn_no LIKE :q OR c.name LIKE :q OR v.name LIKE :q OR o.name LIKE :q)';
                $p['q'] = '%' . $f['q'] . '%';
            }
            $lines = DB::all($sql . ' ORDER BY j.txn_date, j.id', $p);
            if (!$lines && abs($opening) < 0.005) {
                continue;
            }
            // the balance is shown on the account's normal side: a credit-normal account (income, liability,
            // equity, contra-asset) grows with credits and shows a positive balance when it is in credit
            $sign = fin_normal_sign($a);
            $opening *= $sign;
            $rows[] = ['date' => $a['code'] . ' - ' . $a['name'], '_class' => 'group-row'];
            $rows[] = ['date' => '', 'memo' => 'Beginning balance', 'balance' => r2($opening)];
            $run = $opening;
            $sd = 0.0;
            $sc = 0.0;
            foreach ($lines as $l) {
                $run += $sign * ($l['debit'] - $l['credit']);
                $sd += $l['debit'];
                $sc += $l['credit'];
                $rows[] = [
                    'date' => $l['txn_date'], 'type' => Txn::label($l['txn_type']), 'num' => $l['txn_no'], 'name' => $l['party'], 'memo' => $l['memo'],
                    'class' => $l['class_name'],
                    'debit' => (float)$l['debit'] ?: '', 'credit' => (float)$l['credit'] ?: '', 'balance' => r2($run),
                    '_links' => ['num' => Report::txnLink($l)],
                ];
            }
            $rows[] = ['date' => 'Total ' . $a['code'], 'debit' => r2($sd), 'credit' => r2($sc), 'balance' => r2($run), '_class' => 'subtotal-row'];
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'type', 'label' => 'Type'], ['key' => 'num', 'label' => 'Num'],
                ['key' => 'name', 'label' => 'Name'], ['key' => 'memo', 'label' => 'Memo'], ['key' => 'class', 'label' => 'Class'],
                ['key' => 'debit', 'label' => 'Debit', 'type' => 'money'],
                ['key' => 'credit', 'label' => 'Credit', 'type' => 'money'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money']],
            'notes' => fin_class_note($cls, false) . 'The balance is shown on each account\'s normal side: debits increase a debit-balance account (assets, expenses), credits increase a credit-balance account (liabilities, equity, income). A negative balance is on the other side.',
            'rows' => array_map(function ($r) {
                if (($r['_class'] ?? '') !== '' && !valid_date((string)($r['date'] ?? ''))) {
                    $r['_html']['date'] = '<strong>' . h($r['date']) . '</strong>';
                }
                return $r;
            }, $rows),
        ];
    },
]);

Report::define('journal', [
    'filters' => ['range', 'txn_type', 'class'],
    'sortable' => false,
    'run' => function (array $f): array {
        $cls = $f['class_id'] ?: null;
        [$csql, $cp] = Classes::sqlFilter($cls, 'j');
        $sql = "SELECT t.* FROM transactions t WHERE t.txn_date BETWEEN :f AND :t AND EXISTS (SELECT 1 FROM journal_lines j WHERE j.txn_id = t.id $csql)";
        $p = ['f' => $f['from'], 't' => $f['to']] + $cp;
        if ($f['txn_type'] !== '') {
            $sql .= ' AND t.txn_type = :ty';
            $p['ty'] = $f['txn_type'];
        }
        $txns = DB::all($sql . ' ORDER BY t.txn_date, t.id LIMIT 2000', $p);
        $rows = [];
        $td = 0.0;
        $tc = 0.0;
        foreach ($txns as $t) {
            $first = true;
            foreach (DB::all("SELECT j.*, a.code, a.name, cl.name AS class_name FROM journal_lines j JOIN accounts a ON a.id = j.account_id LEFT JOIN classes cl ON cl.id = j.class_id WHERE j.txn_id = :t $csql ORDER BY j.id", ['t' => $t['id']] + $cp) as $j) {
                $rows[] = [
                    'date' => $first ? $t['txn_date'] : '', 'type' => $first ? Txn::label($t['txn_type']) : '', 'num' => $first ? $t['txn_no'] : '',
                    'account' => $j['code'] . ' - ' . $j['name'], 'memo' => $j['memo'], 'class' => $j['class_name'],
                    'debit' => (float)$j['debit'] ?: '', 'credit' => (float)$j['credit'] ?: '',
                    '_links' => ['num' => $first ? Report::txnLink(['txn_type' => $t['txn_type'], 'txn_id' => $t['id']]) : null],
                    '_class' => $first ? 'border-top' : '',
                ];
                $td += $j['debit'];
                $tc += $j['credit'];
                $first = false;
            }
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'type', 'label' => 'Type'], ['key' => 'num', 'label' => 'Num'],
                ['key' => 'account', 'label' => 'Account'], ['key' => 'memo', 'label' => 'Memo'], ['key' => 'class', 'label' => 'Class'],
                ['key' => 'debit', 'label' => 'Debit', 'type' => 'money'], ['key' => 'credit', 'label' => 'Credit', 'type' => 'money']],
            'rows' => $rows,
            'notes' => fin_class_note($cls, false),
            'totals' => ['date' => 'TOTAL', 'debit' => r2($td), 'credit' => r2($tc)],
        ];
    },
]);
