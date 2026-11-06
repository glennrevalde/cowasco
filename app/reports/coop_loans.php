<?php
declare(strict_types=1);

/** Loan reports (helpers in coop.php). Balances, arrears and aging are worked out as of the report date from the ledgers. */

/** Loans with a principal balance at a date, with that balance */
function crpt_loans_at(array $f, string $asof): array
{
    $p = ['d' => $asof, 'd2' => $asof];
    $sql = "SELECT l.*, m.full_name, m.member_no, m.mobile, p.name AS product_name, p.code AS product_code, u.full_name AS officer, b.name AS branch_name,
                   (SELECT COALESCE(SUM(x.principal_dr - x.principal_cr),0) FROM loan_txns x WHERE x.loan_id = l.id AND x.is_voided = 0 AND x.txn_date <= :d) AS bal_at
              FROM loan_accounts l JOIN members m ON m.id = l.member_id JOIN loan_products p ON p.id = l.product_id LEFT JOIN users u ON u.id = l.officer_id LEFT JOIN coop_branches b ON b.id = l.branch_id
             WHERE l.release_date <= :d2 AND l.status <> 'cancelled'" . crpt_branch_sql($f, 'l.branch_id', $p);
    if (($f['product'] ?? '') !== '') {
        $sql .= ' AND l.product_id = :pr';
        $p['pr'] = (int)$f['product'];
    }
    if (($f['officer'] ?? '') !== '') {
        $sql .= ' AND l.officer_id = :of';
        $p['of'] = (int)$f['officer'];
    }
    return array_values(array_filter(DB::all($sql . ' ORDER BY p.name, l.release_date, l.id', $p), fn($l) => Dec::isPos($l['bal_at'])));
}

/** The schedule of a loan with what was paid on each amortization up to a date */
function crpt_schedule_at(int $loanId, string $asof): array
{
    return DB::all('SELECT s.id, s.inst_no, s.due_date, s.principal, s.interest,
                           COALESCE(SUM(CASE WHEN x.is_voided = 0 AND x.txn_date <= :d THEN a.principal ELSE 0 END),0) AS pp,
                           COALESCE(SUM(CASE WHEN x.is_voided = 0 AND x.txn_date <= :d2 THEN a.interest ELSE 0 END),0) AS ip
                      FROM loan_schedule s LEFT JOIN loan_payment_alloc a ON a.schedule_id = s.id LEFT JOIN loan_txns x ON x.id = a.loan_txn_id
                     WHERE s.loan_id = :l GROUP BY s.id, s.inst_no, s.due_date, s.principal, s.interest ORDER BY s.inst_no', ['d' => $asof, 'd2' => $asof, 'l' => $loanId]);
}

/** Aging of one loan as of a date (maturity / par / amortization, as Loans::aging, but from the payments up to that date) */
function crpt_aging(array $l, string $asof, string $kind): array
{
    $bal = Dec::of($l['bal_at']);
    $b = array_fill_keys(array_keys(Loans::BUCKETS), '0');
    if ($kind === 'maturity') {
        $days = max(0, Coop::daysBetween($l['due_date'], $asof));
        $b[Loans::bucket($days)] = $bal;
        return ['bucket' => $b, 'days' => $days, 'delinquent' => $days > 0 ? $bal : '0', 'arrears' => '0'];
    }
    $over = [];
    $arrears = '0';
    foreach (crpt_schedule_at((int)$l['id'], $asof) as $s) {
        if ($s['due_date'] >= $asof) {
            continue;
        }
        $up = Dec::max('0', Dec::sub($s['principal'], $s['pp'], 2));
        $ui = Dec::max('0', Dec::sub($s['interest'], $s['ip'], 2));
        if (Dec::isPos($up) || Dec::isPos($ui)) {
            $over[] = $s + ['up' => $up];
            $arrears = Dec::add($arrears, Dec::add($up, $ui, 2), 2);
        }
    }
    if (!$over) {
        $b['current'] = $bal;
        return ['bucket' => $b, 'days' => 0, 'delinquent' => '0', 'arrears' => '0'];
    }
    $days = max(0, Coop::daysBetween($over[0]['due_date'], $asof));
    if ($kind === 'par') {
        $b[Loans::bucket($days)] = $bal;
        return ['bucket' => $b, 'days' => $days, 'delinquent' => $bal, 'arrears' => $arrears];
    }
    $del = '0';
    foreach ($over as $s) {
        $k = Loans::bucket(max(0, Coop::daysBetween($s['due_date'], $asof)));
        $b[$k] = Dec::add($b[$k], $s['up'], 2);
        $del = Dec::add($del, $s['up'], 2);
    }
    $b['current'] = Dec::add($b['current'], Dec::max('0', Dec::sub($bal, $del, 2)), 2);
    return ['bucket' => $b, 'days' => $days, 'delinquent' => $del, 'arrears' => $arrears];
}

function crpt_officers(): array
{
    return Coop::ready() ? DB::pairs('SELECT DISTINCT u.id, u.full_name FROM loan_accounts l JOIN users u ON u.id = l.officer_id ORDER BY u.full_name') : [];
}

/** The three aging reports share one layout */
function crpt_aging_report(string $kind, string $notes): array
{
    return [
        'filters' => ['asof'],
        'extra_filters' => ['product' => ['Product', fn() => crpt_loan_products()], 'branch' => ['Branch', fn() => crpt_branches()], 'officer' => ['Officer', fn() => crpt_officers()],
            'only' => ['Show', fn() => ['late' => 'Delinquent loans only'], 'Every loan']],
        'sortable' => false,
        'run' => function (array $f) use ($kind, $notes): array {
            crpt_ready();
            $rows = [];
            $grand = array_fill_keys(array_keys(Loans::BUCKETS), '0') + ['bal' => '0'];
            $product = null;
            $sub = $grand;
            $flush = function () use (&$rows, &$sub, &$product, $grand) {
                if ($product !== null) {
                    $rows[] = ['loan' => 'Total ' . $product, 'bal' => Dec::fix($sub['bal'])] + array_map(fn($v) => Dec::fix($v), array_diff_key($sub, ['bal' => 1])) + ['_class' => 'subtotal-row'];
                }
                $sub = $grand;
            };
            foreach (crpt_loans_at($f, $f['asof']) as $l) {
                $a = crpt_aging($l, $f['asof'], $kind);
                if ($f['only'] === 'late' && !Dec::isPos($a['delinquent'])) {
                    continue;
                }
                if ($l['product_name'] !== $product) {
                    $flush();
                    $product = $l['product_name'];
                    $rows[] = ['loan' => $product, '_class' => 'group-row'];
                }
                $r = ['loan' => $l['loan_no'], 'member' => $l['full_name'], 'released' => $l['release_date'], 'maturity' => $l['due_date'], 'bal' => Dec::fix($l['bal_at']), 'days' => $a['days'] ?: '', '_class' => 'indent-1',
                    '_links' => ['loan' => Report::link('loan.accounts', ['id' => $l['id']])]];
                foreach ($a['bucket'] as $k => $v) {
                    $r[$k] = Dec::isZero($v) ? '' : Dec::fix($v);
                    $sub[$k] = Dec::add($sub[$k], $v, 2);
                    $grand[$k] = Dec::add($grand[$k], $v, 2);
                }
                $sub['bal'] = Dec::add($sub['bal'], $l['bal_at'], 2);
                $grand['bal'] = Dec::add($grand['bal'], $l['bal_at'], 2);
                $rows[] = $r;
            }
            $flush();
            $tot = ['loan' => 'TOTAL', 'bal' => Dec::fix($grand['bal']), '_class' => 'total-row'];
            $chart = [];
            foreach (Loans::BUCKETS as $k => $lbl) {
                $tot[$k] = Dec::fix($grand[$k]);
                $chart[$lbl] = (float)$grand[$k];
            }
            $rows[] = $tot;
            $risk = Dec::sub($grand['bal'], $grand['current'], 2);
            $rows[] = ['loan' => 'Delinquent, % of the portfolio', 'bal' => Dec::isPos($grand['bal']) ? Dec::fix(Dec::div(Dec::mul($risk, '100', 6), $grand['bal'], 6), 2) . ' %' : '', '_class' => 'total-row'];
            $cols = [['key' => 'loan', 'label' => 'Loan'], ['key' => 'member', 'label' => 'Member'], ['key' => 'released', 'label' => 'Released', 'type' => 'date', 'optional' => true], ['key' => 'maturity', 'label' => 'Maturity', 'type' => 'date'],
                ['key' => 'bal', 'label' => 'Balance', 'type' => 'money'], ['key' => 'days', 'label' => 'Days past due', 'type' => 'int']];
            foreach (Loans::BUCKETS as $k => $lbl) {
                $cols[] = ['key' => $k, 'label' => $lbl, 'type' => 'money'];
            }
            return ['grouped' => true, 'columns' => $cols, 'rows' => $rows, 'chart' => ['bars' => $chart, 'bars_title' => 'By age'], 'notes' => $notes];
        },
    ];
}

Report::define('loan_aging_maturity', crpt_aging_report('maturity', 'Aging by maturity: a loan ages only once its maturity date has passed; the whole balance moves to the bucket of the days since maturity.'));
Report::define('loan_aging_par', crpt_aging_report('par', 'Portfolio at risk: one missed or short amortization puts the whole outstanding balance at risk, aged from the oldest unpaid due date.'));
Report::define('loan_aging_amort', crpt_aging_report('amort', 'Aging by amortization: only the unpaid principal of each overdue amortization is delinquent, aged from its own due date; the rest of the balance is current.'));

Report::define('loan_products', [
    'filters' => [],
    'run' => function (array $f): array {
        crpt_ready();
        $rows = [];
        foreach (DB::all("SELECT p.*, (SELECT COUNT(*) FROM loan_accounts l WHERE l.product_id = p.id AND l.status IN ('current','past_due','restructured')) AS n,
                                 (SELECT COALESCE(SUM(l.principal_balance),0) FROM loan_accounts l WHERE l.product_id = p.id AND l.status IN ('current','past_due','restructured')) AS bal
                            FROM loan_products p ORDER BY p.code") as $p) {
            $ch = DB::column('SELECT c.name FROM loan_product_charges pc JOIN loan_charges c ON c.id = pc.charge_id WHERE pc.product_id = :p', ['p' => $p['id']]);
            $rows[] = ['code' => $p['code'], 'name' => $p['name'], 'method' => Loans::INTEREST_METHODS[$p['interest_method']] ?? $p['interest_method'], 'rate' => Dec::fix($p['rate_default'] ?? '0', 2),
                'term' => Dec::fix($p['term_min'], 0) . ' to ' . Dec::fix($p['term_max'], 0) . ' ' . $p['term_unit'], 'mode' => Coop::modeLabel($p['mode_of_payment']),
                'amount' => Dec::money($p['amount_min']) . ' to ' . (Dec::isPos($p['amount_max']) ? Dec::money($p['amount_max']) : 'any'), 'charges' => implode(', ', $ch),
                'penalty' => Dec::isPos($p['penalty_rate']) ? Dec::fix($p['penalty_rate'], 2) . ' %, ' . (int)$p['penalty_grace_days'] . ' days grace' : 'none', 'n' => (int)$p['n'], 'bal' => Dec::fix($p['bal']),
                'active' => (int)$p['is_active'] ? 'Active' : 'Inactive', '_links' => ['name' => Report::link('loan.products', ['id' => $p['id']])]];
        }
        return ['columns' => [['key' => 'code', 'label' => 'Code'], ['key' => 'name', 'label' => 'Product'], ['key' => 'method', 'label' => 'Interest'], ['key' => 'rate', 'label' => 'Rate %', 'type' => 'qty'],
            ['key' => 'term', 'label' => 'Term'], ['key' => 'mode', 'label' => 'Payments'], ['key' => 'amount', 'label' => 'Amount'], ['key' => 'charges', 'label' => 'Charges'], ['key' => 'penalty', 'label' => 'Penalty'],
            ['key' => 'n', 'label' => 'Open loans', 'type' => 'int'], ['key' => 'bal', 'label' => 'Outstanding', 'type' => 'money'], ['key' => 'active', 'label' => 'Status']], 'rows' => $rows,
            'totals' => ['code' => 'TOTAL', 'n' => array_sum(array_column($rows, 'n')), 'bal' => crpt_sum($rows, 'bal')]];
    },
]);

Report::define('loan_schedule_recv', [
    'filters' => ['asof'],
    'extra_filters' => ['product' => ['Product', fn() => crpt_loan_products()], 'branch' => ['Branch', fn() => crpt_branches()], 'officer' => ['Officer', fn() => crpt_officers()]],
    'sortable' => false,
    'run' => function (array $f): array {
        crpt_ready();
        $rows = [];
        $product = null;
        $sub = '0';
        $grand = '0';
        $count = 0;
        foreach (crpt_loans_at($f, $f['asof']) as $l) {
            if ($l['product_name'] !== $product) {
                if ($product !== null) {
                    $rows[] = ['loan' => 'Total ' . $product, 'bal' => Dec::fix($sub), '_class' => 'subtotal-row'];
                }
                $product = $l['product_name'];
                $sub = '0';
                $rows[] = ['loan' => $product, '_class' => 'group-row'];
            }
            $count++;
            $sub = Dec::add($sub, $l['bal_at'], 2);
            $grand = Dec::add($grand, $l['bal_at'], 2);
            $rows[] = ['loan' => $l['loan_no'], 'member' => $l['full_name'], 'no' => $l['member_no'], 'released' => $l['release_date'], 'maturity' => $l['due_date'], 'rate' => Dec::fix($l['rate'], 2),
                'principal' => Dec::fix($l['principal']), 'bal' => Dec::fix($l['bal_at']), 'status' => Loans::LOAN_STATUSES[$l['status']] ?? $l['status'], '_class' => 'indent-1',
                '_links' => ['loan' => Report::link('loan.accounts', ['id' => $l['id']])]];
        }
        if ($product !== null) {
            $rows[] = ['loan' => 'Total ' . $product, 'bal' => Dec::fix($sub), '_class' => 'subtotal-row'];
        }
        $rows[] = ['loan' => 'TOTAL (' . $count . ' loans)', 'bal' => Dec::fix($grand), '_class' => 'total-row'];
        $gl = '0';
        foreach (array_unique(DB::column('SELECT DISTINCT receivable_account_id FROM loan_accounts')) as $a) {
            $gl = Dec::add($gl, DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND txn_date <= :d', ['a' => $a, 'd' => $f['asof']]), 2);
        }
        $filtered = ($f['product'] ?? '') !== '' || ($f['branch'] ?? '') !== '' || ($f['officer'] ?? '') !== '' || Coop::userBranchId() !== null;
        return ['grouped' => true, 'columns' => [['key' => 'loan', 'label' => 'Loan'], ['key' => 'member', 'label' => 'Member'], ['key' => 'no', 'label' => 'Member no.', 'optional' => true], ['key' => 'released', 'label' => 'Released', 'type' => 'date'],
            ['key' => 'maturity', 'label' => 'Maturity', 'type' => 'date'], ['key' => 'rate', 'label' => 'Rate %', 'type' => 'qty'], ['key' => 'principal', 'label' => 'Principal', 'type' => 'money'], ['key' => 'bal', 'label' => 'Balance', 'type' => 'money'],
            ['key' => 'status', 'label' => 'Status (today)']], 'rows' => $rows,
            'notes' => $filtered ? 'Filtered: compare with the general ledger on the unfiltered report.' : 'Loans receivable in the general ledger as of the date: ' . money($gl) . (Dec::cmp($gl, $grand) === 0 ? ', the same as the schedule.' : ' <strong class="text-danger">(differs from the schedule)</strong>.')];
    },
]);

Report::define('loan_releases', [
    'filters' => ['range'],
    'extra_filters' => ['product' => ['Product', fn() => crpt_loan_products()], 'branch' => ['Branch', fn() => crpt_branches()], 'officer' => ['Officer', fn() => crpt_officers()]],
    'run' => function (array $f): array {
        crpt_ready();
        $p = ['f' => $f['from'], 't' => $f['to']];
        $sql = "SELECT l.*, m.full_name, p.name AS product_name, u.full_name AS officer, x.txn_no, x.txn_type FROM loan_accounts l JOIN members m ON m.id = l.member_id JOIN loan_products p ON p.id = l.product_id
                  LEFT JOIN users u ON u.id = l.officer_id JOIN transactions x ON x.id = l.release_txn_id WHERE x.is_voided = 0 AND l.release_date BETWEEN :f AND :t" . crpt_branch_sql($f, 'l.branch_id', $p);
        if ($f['product'] !== '') {
            $sql .= ' AND l.product_id = :pr';
            $p['pr'] = (int)$f['product'];
        }
        if ($f['officer'] !== '') {
            $sql .= ' AND l.officer_id = :of';
            $p['of'] = (int)$f['officer'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY l.release_date, l.id', $p) as $l) {
            $rows[] = ['date' => $l['release_date'], 'loan' => $l['loan_no'], 'member' => $l['full_name'], 'product' => $l['product_name'], 'term' => Dec::fix($l['term'], 0) . ' ' . $l['term_unit'], 'rate' => Dec::fix($l['rate'], 2),
                'principal' => Dec::fix($l['principal']), 'charges' => Dec::fix($l['total_charges']), 'payoff' => Dec::isPos($l['previous_loan_payoff']) ? Dec::fix($l['previous_loan_payoff']) : '', 'net' => Dec::fix($l['net_proceeds']),
                'mode' => ['cash' => 'Cash', 'cheque' => 'Cheque', 'savings' => 'Credit to savings'][$l['release_mode']] ?? $l['release_mode'], 'officer' => $l['officer'],
                // the Cash Voucher or the Check Voucher of the release (Banking), or the loan release voucher of the older releases
                'voucher' => LoanVouchers::label((string)$l['txn_type']) . ' ' . $l['txn_no'],
                '_links' => ['loan' => Report::link('loan.accounts', ['id' => $l['id']]), 'voucher' => Report::link('loan.release', ['loan' => $l['id']])]];
        }
        return ['columns' => [['key' => 'date', 'label' => 'Released', 'type' => 'date'], ['key' => 'loan', 'label' => 'Loan'], ['key' => 'member', 'label' => 'Member'], ['key' => 'product', 'label' => 'Product'], ['key' => 'term', 'label' => 'Term'],
            ['key' => 'rate', 'label' => 'Rate %', 'type' => 'qty'], ['key' => 'principal', 'label' => 'Principal', 'type' => 'money'], ['key' => 'charges', 'label' => 'Charges', 'type' => 'money'], ['key' => 'payoff', 'label' => 'Previous loan paid off', 'type' => 'money'],
            ['key' => 'net', 'label' => 'Net proceeds', 'type' => 'money'], ['key' => 'mode', 'label' => 'Released by'], ['key' => 'officer', 'label' => 'Officer'], ['key' => 'voucher', 'label' => 'Voucher']],
            'rows' => $rows, 'totals' => crpt_totals($rows, ['principal', 'charges', 'payoff', 'net'], 'date')];
    },
]);

Report::define('loan_due_vs_coll', [
    'filters' => ['range'],
    'extra_filters' => ['product' => ['Product', fn() => crpt_loan_products()], 'branch' => ['Branch', fn() => crpt_branches()], 'officer' => ['Officer', fn() => crpt_officers()]],
    'run' => function (array $f): array {
        crpt_ready();
        $p = ['f' => $f['from'], 't' => $f['to'], 'f2' => $f['from'], 't2' => $f['to']];
        $sql = "SELECT l.id, l.loan_no, m.full_name, p.name AS product_name, u.full_name AS officer,
                       (SELECT COALESCE(SUM(s.principal + s.interest),0) FROM loan_schedule s WHERE s.loan_id = l.id AND s.due_date BETWEEN :f AND :t) AS due,
                       (SELECT COALESCE(SUM(x.principal_cr),0) FROM loan_txns x WHERE x.loan_id = l.id AND x.is_voided = 0 AND x.kind IN ('payment','restructure') AND x.txn_date BETWEEN :f2 AND :t2) AS pc,
                       (SELECT COALESCE(SUM(x.interest_cr),0) FROM loan_txns x WHERE x.loan_id = l.id AND x.is_voided = 0 AND x.kind IN ('payment','restructure') AND x.txn_date BETWEEN :f3 AND :t3) AS ic,
                       (SELECT COALESCE(SUM(x.penalty_cr),0) FROM loan_txns x WHERE x.loan_id = l.id AND x.is_voided = 0 AND x.kind IN ('payment','restructure') AND x.txn_date BETWEEN :f4 AND :t4) AS nc
                  FROM loan_accounts l JOIN members m ON m.id = l.member_id JOIN loan_products p ON p.id = l.product_id LEFT JOIN users u ON u.id = l.officer_id WHERE l.status <> 'cancelled'" . crpt_branch_sql($f, 'l.branch_id', $p);
        $p += ['f3' => $f['from'], 't3' => $f['to'], 'f4' => $f['from'], 't4' => $f['to']];
        if ($f['product'] !== '') {
            $sql .= ' AND l.product_id = :pr';
            $p['pr'] = (int)$f['product'];
        }
        if ($f['officer'] !== '') {
            $sql .= ' AND l.officer_id = :of';
            $p['of'] = (int)$f['officer'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY m.full_name', $p) as $r) {
            $coll = Dec::add($r['pc'], $r['ic'], 2);
            if (!Dec::isPos($r['due']) && !Dec::isPos($coll) && !Dec::isPos($r['nc'])) {
                continue;
            }
            $rows[] = ['loan' => $r['loan_no'], 'member' => $r['full_name'], 'product' => $r['product_name'], 'officer' => $r['officer'], 'due' => Dec::fix($r['due']), 'principal' => Dec::fix($r['pc']),
                'interest' => Dec::fix($r['ic']), 'collected' => Dec::fix($coll), 'penalty' => Dec::fix($r['nc']),
                'eff' => Dec::isPos($r['due']) ? Dec::fix(Dec::div(Dec::mul(Dec::min($coll, $r['due']), '100', 6), $r['due'], 6), 2) : '', '_links' => ['loan' => Report::link('loan.accounts', ['id' => $r['id']])]];
        }
        $t = crpt_totals($rows, ['due', 'principal', 'interest', 'collected', 'penalty'], 'loan');
        $t['eff'] = Dec::isPos($t['due']) ? Dec::fix(Dec::div(Dec::mul($t['collected'], '100', 6), $t['due'], 6), 2) : '';
        return ['columns' => [['key' => 'loan', 'label' => 'Loan'], ['key' => 'member', 'label' => 'Member'], ['key' => 'product', 'label' => 'Product', 'optional' => true], ['key' => 'officer', 'label' => 'Officer', 'optional' => true],
            ['key' => 'due', 'label' => 'Due in the period', 'type' => 'money'], ['key' => 'principal', 'label' => 'Principal collected', 'type' => 'money'], ['key' => 'interest', 'label' => 'Interest collected', 'type' => 'money'],
            ['key' => 'collected', 'label' => 'Total collected', 'type' => 'money'], ['key' => 'penalty', 'label' => 'Penalties collected', 'type' => 'money'], ['key' => 'eff', 'label' => 'Collection efficiency', 'type' => 'pct']],
            'rows' => $rows, 'totals' => $t, 'notes' => 'Collection efficiency = principal and interest collected in the period (up to what fell due, per loan) / principal and interest that fell due in the period. Advance and past-due collections count in the period they were received.'];
    },
]);

Report::define('loan_due', [
    'filters' => ['range'],
    'default_dates' => 'this_week',
    'extra_filters' => ['product' => ['Product', fn() => crpt_loan_products()], 'branch' => ['Branch', fn() => crpt_branches()], 'officer' => ['Officer', fn() => crpt_officers()],
        'arrears' => ['Include', fn() => ['yes' => 'Arrears from before the period'], 'Due in the period only']],
    'run' => function (array $f): array {
        crpt_ready();
        $p = ['t' => $f['to']];
        $sql = "SELECT s.*, l.loan_no, l.id AS loan_id, l.member_id, m.full_name, m.mobile, p.name AS product_name FROM loan_schedule s JOIN loan_accounts l ON l.id = s.loan_id JOIN members m ON m.id = l.member_id JOIN loan_products p ON p.id = l.product_id
                 WHERE l.status IN ('current','past_due','restructured') AND s.is_paid = 0 AND s.due_date <= :t" . crpt_branch_sql($f, 'l.branch_id', $p);
        if ($f['arrears'] !== 'yes') {
            $sql .= ' AND s.due_date >= :f';
            $p['f'] = $f['from'];
        }
        if ($f['product'] !== '') {
            $sql .= ' AND l.product_id = :pr';
            $p['pr'] = (int)$f['product'];
        }
        if ($f['officer'] !== '') {
            $sql .= ' AND l.officer_id = :of';
            $p['of'] = (int)$f['officer'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY s.due_date, m.full_name', $p) as $s) {
            $up = Dec::sub($s['principal'], $s['principal_paid'], 2);
            $ui = Dec::sub($s['interest'], $s['interest_paid'], 2);
            $late = $s['due_date'] < today() ? Coop::daysBetween($s['due_date'], today()) : '';
            $rows[] = ['due' => $s['due_date'], 'loan' => $s['loan_no'], 'member' => $s['full_name'], 'mobile' => $s['mobile'], 'inst' => (int)$s['inst_no'], 'principal' => Dec::fix($up), 'interest' => Dec::fix($ui),
                'total' => Dec::fix(Dec::add($up, $ui, 2)), 'late' => $late, '_links' => ['loan' => Report::link('loan.collection', ['loan' => $s['loan_id']])]];
        }
        return ['columns' => [['key' => 'due', 'label' => 'Due', 'type' => 'date'], ['key' => 'loan', 'label' => 'Loan'], ['key' => 'member', 'label' => 'Member'], ['key' => 'mobile', 'label' => 'Mobile'], ['key' => 'inst', 'label' => '#', 'type' => 'int'],
            ['key' => 'principal', 'label' => 'Principal', 'type' => 'money'], ['key' => 'interest', 'label' => 'Interest', 'type' => 'money'], ['key' => 'total', 'label' => 'Amount due', 'type' => 'money'], ['key' => 'late', 'label' => 'Days late', 'type' => 'int']],
            'rows' => $rows, 'totals' => crpt_totals($rows, ['principal', 'interest', 'total'], 'due'), 'notes' => 'Unpaid amortizations (or the unpaid part) as of today; penalties are added at the window. Click a loan to collect.'];
    },
]);

Report::define('loan_collections', [
    'filters' => ['range', 'user'],
    'filter_labels' => ['user' => 'Teller'],
    'extra_filters' => ['product' => ['Product', fn() => crpt_loan_products()], 'branch' => ['Branch', fn() => crpt_branches()]],
    'run' => function (array $f): array {
        crpt_ready();
        $p = ['f' => $f['from'], 't' => $f['to']];
        $sql = "SELECT x.*, l.loan_no, l.member_id, m.full_name, p.name AS product_name, u.username, t.txn_no, t.txn_type FROM loan_txns x JOIN loan_accounts l ON l.id = x.loan_id JOIN members m ON m.id = l.member_id
                  JOIN loan_products p ON p.id = l.product_id LEFT JOIN users u ON u.id = x.teller_id LEFT JOIN transactions t ON t.id = x.txn_id
                 WHERE x.kind IN ('payment','restructure') AND x.is_voided = 0 AND x.txn_id IS NOT NULL AND x.txn_date BETWEEN :f AND :t" . crpt_branch_sql($f, 'l.branch_id', $p);
        if ($f['product'] !== '') {
            $sql .= ' AND l.product_id = :pr';
            $p['pr'] = (int)$f['product'];
        }
        if ($f['user_id']) {
            $sql .= ' AND x.teller_id = :u';
            $p['u'] = $f['user_id'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY x.txn_date, x.id', $p) as $x) {
            $rows[] = ['date' => $x['txn_date'], 'ref' => $x['or_no'] ?: $x['txn_no'], 'loan' => $x['loan_no'], 'member' => $x['full_name'], 'product' => $x['product_name'], 'penalty' => Dec::fix($x['penalty_cr']),
                'interest' => Dec::fix($x['interest_cr']), 'principal' => Dec::fix($x['principal_cr']), 'total' => Dec::fix(Dec::sum([$x['penalty_cr'], $x['interest_cr'], $x['principal_cr']])),
                'how' => $x['kind'] === 'restructure' ? 'Renewal payoff' : '', 'teller' => $x['username'],
                '_links' => ['ref' => $x['txn_id'] ? Report::txnLink(['txn_type' => $x['txn_type'], 'txn_id' => $x['txn_id']]) : null, 'loan' => Report::link('loan.accounts', ['id' => $x['loan_id'], 'tab' => 'ledger'])]];
        }
        return ['columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'ref', 'label' => 'OR / voucher'], ['key' => 'loan', 'label' => 'Loan'], ['key' => 'member', 'label' => 'Member'], ['key' => 'product', 'label' => 'Product', 'optional' => true],
            ['key' => 'penalty', 'label' => 'Penalty', 'type' => 'money'], ['key' => 'interest', 'label' => 'Interest', 'type' => 'money'], ['key' => 'principal', 'label' => 'Principal', 'type' => 'money'], ['key' => 'total', 'label' => 'Total', 'type' => 'money'],
            ['key' => 'how', 'label' => ''], ['key' => 'teller', 'label' => 'Teller']], 'rows' => $rows, 'totals' => crpt_totals($rows, ['penalty', 'interest', 'principal', 'total'], 'date')];
    },
]);

Report::define('loan_no_payments', [
    'filters' => ['asof'],
    'extra_filters' => ['product' => ['Product', fn() => crpt_loan_products()], 'branch' => ['Branch', fn() => crpt_branches()], 'officer' => ['Officer', fn() => crpt_officers()]],
    'run' => function (array $f): array {
        crpt_ready();
        $rows = [];
        foreach (crpt_loans_at($f, $f['asof']) as $l) {
            if ($l['start_date'] >= $f['asof'] || DB::value("SELECT id FROM loan_txns WHERE loan_id = :l AND kind = 'payment' AND is_voided = 0 AND txn_date <= :d LIMIT 1", ['l' => $l['id'], 'd' => $f['asof']])) {
                continue;
            }
            $rows[] = ['loan' => $l['loan_no'], 'member' => $l['full_name'], 'mobile' => $l['mobile'], 'product' => $l['product_name'], 'released' => $l['release_date'], 'first' => $l['start_date'],
                'days' => Coop::daysBetween($l['start_date'], $f['asof']), 'principal' => Dec::fix($l['principal']), 'bal' => Dec::fix($l['bal_at']), 'officer' => $l['officer'],
                '_links' => ['loan' => Report::link('loan.accounts', ['id' => $l['id']])]];
        }
        return ['columns' => [['key' => 'loan', 'label' => 'Loan'], ['key' => 'member', 'label' => 'Member'], ['key' => 'mobile', 'label' => 'Mobile'], ['key' => 'product', 'label' => 'Product'], ['key' => 'released', 'label' => 'Released', 'type' => 'date'],
            ['key' => 'first', 'label' => 'First due', 'type' => 'date'], ['key' => 'days', 'label' => 'Days since first due', 'type' => 'int'], ['key' => 'principal', 'label' => 'Principal', 'type' => 'money'], ['key' => 'bal', 'label' => 'Balance', 'type' => 'money'],
            ['key' => 'officer', 'label' => 'Officer']], 'rows' => $rows, 'totals' => crpt_totals($rows, ['principal', 'bal'], 'loan'),
            'notes' => 'Loans whose first amortization has fallen due without any payment (an early sign of a bad loan).'];
    },
]);

Report::define('loan_soa', [
    'filters' => ['asof', 'search'],
    'filter_labels' => ['search' => 'Borrower or loan no.'],
    // the search box lists the borrowers with outstanding loans (name, loan product, loan no.) as they are typed
    'search_picker' => fn(array $f): string => Loans::pickerBox('q', (string)$f['q'], ['label' => 'Borrower or loan no.', 'submit' => true, 'id' => 'soaLoan']),
    'js' => ['js/loan_pick.js'],
    'sortable' => false,
    'run' => function (array $f): array {
        crpt_ready();
        $q = trim((string)$f['q']);
        $loan = $q !== '' ? Loans::byNo($q) : (gint('loan_id') ? Loans::loan((int)gint('loan_id')) : null);
        $cols = [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'what', 'label' => 'Particulars'], ['key' => 'ref', 'label' => 'Reference'], ['key' => 'principal', 'label' => 'Principal', 'type' => 'money'],
            ['key' => 'interest', 'label' => 'Interest', 'type' => 'money'], ['key' => 'penalty', 'label' => 'Penalty', 'type' => 'money'], ['key' => 'bal', 'label' => 'Principal balance', 'type' => 'money']];
        if (!$loan && $q !== '') {
            // a name or a loan product typed: the one outstanding loan it finds, or the loans to choose from
            $found = Loans::searchOutstanding($q, 100);
            if ($found['total'] === 1) {
                $loan = Loans::loan((int)$found['rows'][0]['id']);
            } else {
                $rows = array_map(fn($l) => ['loan' => $l['loan_no'], 'member' => $l['name'], 'member_no' => $l['member_no'], 'product' => $l['product'], 'status' => $l['status'], 'balance' => $l['principal_balance'],
                    '_links' => ['loan' => Report::link('rpt.loan_soa', ['q' => $l['loan_no'], 'asof' => $f['asof']])]], $found['rows']);
                return ['columns' => [['key' => 'loan', 'label' => 'Loan no.'], ['key' => 'member', 'label' => 'Borrower'], ['key' => 'member_no', 'label' => 'Member no.'], ['key' => 'product', 'label' => 'Loan product'],
                    ['key' => 'status', 'label' => 'Status'], ['key' => 'balance', 'label' => 'Principal balance', 'type' => 'money']], 'rows' => $rows,
                    'notes' => $found['total'] ? h($found['total'] . ' outstanding loans match "' . $q . '"' . ($found['total'] > count($rows) ? ' (the first ' . count($rows) . ' are listed)' : '') . '. Click a loan no., or choose it in the box, to see its statement.')
                        : h('No loan no. is "' . $q . '" and no borrower with an outstanding loan matches it. Type part of the name, the loan no. or the loan product in the box and choose the loan.')];
            }
        }
        if (!$loan) {
            return ['columns' => $cols, 'rows' => [], 'notes' => 'Choose the loan: click the box (or type part of the borrower\'s name, the loan no. or the loan product) and pick it from the list of borrowers with outstanding loans.'];
        }
        if (!Coop::branchAllowed($loan['branch_id'] ? (int)$loan['branch_id'] : null)) {
            fail('That loan belongs to another branch.');
        }
        $rows = [];
        foreach (DB::all("SELECT x.*, t.txn_no, t.txn_type FROM loan_txns x LEFT JOIN transactions t ON t.id = x.txn_id WHERE x.loan_id = :l AND x.is_voided = 0 AND x.kind <> 'accrual' AND x.txn_date <= :d ORDER BY x.txn_date, x.id",
            ['l' => $loan['id'], 'd' => $f['asof']]) as $x) {
            $rows[] = ['date' => $x['txn_date'], 'what' => ucfirst($x['kind']) . ($x['memo'] ? ': ' . $x['memo'] : ''), 'ref' => $x['or_no'] ?: $x['txn_no'], 'principal' => Dec::fix(Dec::sub($x['principal_dr'], $x['principal_cr'], 2)),
                'interest' => Dec::fix(Dec::sub($x['interest_dr'], $x['interest_cr'], 2)), 'penalty' => Dec::fix(Dec::sub($x['penalty_dr'], $x['penalty_cr'], 2)), 'bal' => Dec::fix($x['principal_bal']),
                '_links' => ['ref' => $x['txn_id'] ? Report::txnLink(['txn_type' => $x['txn_type'], 'txn_id' => $x['txn_id']]) : null]];
        }
        if (in_array($loan['status'], Loans::OPEN_STATUSES, true)) {
            $d = Loans::dues($loan, $f['asof']);
            $rows[] = ['what' => 'Due as of ' . fdate($f['asof']) . ' (' . (int)$d['count_due'] . ' amortizations)', 'principal' => Dec::fix($d['principal_due']), 'interest' => Dec::fix($d['interest_due']), 'penalty' => Dec::fix($d['penalty_due']), '_class' => 'total-row'];
            $rows[] = ['what' => 'Amount to pay off the loan: ' . Dec::money($d['payoff']), '_class' => 'total-row'];
        }
        return ['grouped' => true, 'columns' => $cols, 'rows' => $rows,
            'notes' => h($loan['loan_no'] . ' ' . $loan['product_name'] . ', ' . $loan['member_name'] . ' (' . $loan['member_no'] . '); released ' . fdate($loan['release_date']) . ', ' . Dec::money($loan['principal']) . ' at ' . Dec::fix($loan['rate'], 2) . ' %. Charges are positive, payments negative.')];
    },
]);

Report::define('loan_portfolio', [
    'filters' => ['asof'],
    'extra_filters' => ['branch' => ['Branch', fn() => crpt_branches()]],
    'sortable' => false,
    'run' => function (array $f): array {
        crpt_ready();
        $groups = ['product' => [], 'branch' => [], 'officer' => []];
        $all = ['n' => 0, 'bal' => '0', 'par_n' => 0, 'par' => '0', 'arrears' => '0', 'rs' => '0'];
        foreach (crpt_loans_at($f, $f['asof']) as $l) {
            $a = crpt_aging($l, $f['asof'], 'par');
            foreach (['product' => $l['product_name'], 'branch' => $l['branch_name'] ?: 'No branch', 'officer' => $l['officer'] ?: 'No officer'] as $g => $k) {
                $groups[$g][$k] ??= ['n' => 0, 'bal' => '0', 'par_n' => 0, 'par' => '0', 'arrears' => '0', 'rs' => '0'];
                $x = &$groups[$g][$k];
                $x['n']++;
                $x['bal'] = Dec::add($x['bal'], $l['bal_at'], 2);
                if (Dec::isPos($a['delinquent'])) {
                    $x['par_n']++;
                    $x['par'] = Dec::add($x['par'], $a['delinquent'], 2);
                    $x['arrears'] = Dec::add($x['arrears'], $a['arrears'], 2);
                }
                if ($l['status'] === 'restructured' || $l['restructured_from_id']) {
                    $x['rs'] = Dec::add($x['rs'], $l['bal_at'], 2);
                }
                unset($x);
            }
            $all['n']++;
            $all['bal'] = Dec::add($all['bal'], $l['bal_at'], 2);
            if (Dec::isPos($a['delinquent'])) {
                $all['par_n']++;
                $all['par'] = Dec::add($all['par'], $a['delinquent'], 2);
                $all['arrears'] = Dec::add($all['arrears'], $a['arrears'], 2);
            }
            if ($l['status'] === 'restructured' || $l['restructured_from_id']) {
                $all['rs'] = Dec::add($all['rs'], $l['bal_at'], 2);
            }
        }
        $line = fn(string $name, array $x, string $cls) => ['name' => $name, 'n' => $x['n'], 'bal' => Dec::fix($x['bal']), 'avg' => $x['n'] ? Dec::fix(Dec::div($x['bal'], (string)$x['n'], 4)) : '',
            'par_n' => $x['par_n'], 'par' => Dec::fix($x['par']), 'par_pct' => Dec::isPos($x['bal']) ? Dec::fix(Dec::div(Dec::mul($x['par'], '100', 6), $x['bal'], 6), 2) : '', 'arrears' => Dec::fix($x['arrears']),
            'rs' => Dec::fix($x['rs']), 'share' => Dec::isPos($all['bal']) ? Dec::fix(Dec::div(Dec::mul($x['bal'], '100', 6), $all['bal'], 6), 2) : '', '_class' => $cls];
        $rows = [];
        foreach (['product' => 'By product', 'branch' => 'By branch', 'officer' => 'By loan officer'] as $g => $title) {
            $rows[] = ['name' => $title, '_class' => 'group-row'];
            ksort($groups[$g]);
            foreach ($groups[$g] as $k => $x) {
                $rows[] = $line($k, $x, 'indent-1');
            }
        }
        $rows[] = $line('TOTAL PORTFOLIO', $all, 'total-row');
        return ['grouped' => true, 'columns' => [['key' => 'name', 'label' => ''], ['key' => 'n', 'label' => 'Loans', 'type' => 'int'], ['key' => 'bal', 'label' => 'Outstanding', 'type' => 'money'], ['key' => 'share', 'label' => 'Share', 'type' => 'pct'],
            ['key' => 'avg', 'label' => 'Average loan', 'type' => 'money'], ['key' => 'par_n', 'label' => 'Loans at risk', 'type' => 'int'], ['key' => 'par', 'label' => 'Portfolio at risk', 'type' => 'money'], ['key' => 'par_pct', 'label' => 'PAR', 'type' => 'pct'],
            ['key' => 'arrears', 'label' => 'Amount in arrears', 'type' => 'money'], ['key' => 'rs', 'label' => 'Restructured', 'type' => 'money']], 'rows' => $rows,
            'notes' => 'PAR: the outstanding balance of loans with at least one amortization unpaid at the date, as a percentage of the portfolio.'];
    },
]);

Report::define('loan_comaker', [
    'filters' => ['search'],
    'extra_filters' => ['branch' => ['Branch', fn() => crpt_branches()]],
    'sortable' => false,
    'run' => function (array $f): array {
        crpt_ready();
        $p = [];
        $sql = "SELECT c.*, cm.full_name AS cm_name, cm.member_no AS cm_no, l.id AS loan_id, l.loan_no, l.principal_balance, l.status, b.full_name AS borrower
                  FROM loan_comakers c JOIN loan_applications a ON a.id = c.application_id JOIN loan_accounts l ON l.id = a.loan_id JOIN members b ON b.id = l.member_id LEFT JOIN members cm ON cm.id = c.comaker_member_id
                 WHERE l.status IN ('current','past_due','restructured')" . crpt_branch_sql($f, 'l.branch_id', $p);
        if (trim((string)$f['q']) !== '') {
            $sql .= ' AND (cm.full_name LIKE :q OR c.name LIKE :q2 OR cm.member_no = :q3)';
            $p += ['q' => '%' . trim($f['q']) . '%', 'q2' => '%' . trim($f['q']) . '%', 'q3' => trim($f['q'])];
        }
        $by = [];
        foreach (DB::all($sql . ' ORDER BY COALESCE(cm.full_name, c.name), l.loan_no', $p) as $r) {
            $by[($r['cm_name'] ?: $r['name']) . ($r['cm_no'] ? ' (' . $r['cm_no'] . ')' : ' (non-member)')][] = $r;
        }
        $rows = [];
        foreach ($by as $who => $list) {
            $rows[] = ['name' => $who, '_class' => 'group-row'];
            $tb = '0';
            $tg = '0';
            foreach ($list as $r) {
                $rows[] = ['name' => $r['loan_no'], 'borrower' => $r['borrower'], 'status' => Loans::LOAN_STATUSES[$r['status']] ?? $r['status'], 'guaranteed' => Dec::fix($r['amount_guaranteed']), 'bal' => Dec::fix($r['principal_balance']),
                    '_class' => 'indent-1', '_links' => ['name' => Report::link('loan.accounts', ['id' => $r['loan_id']])]];
                $tb = Dec::add($tb, $r['principal_balance'], 2);
                $tg = Dec::add($tg, $r['amount_guaranteed'], 2);
            }
            $rows[] = ['name' => 'Exposure of ' . $who, 'guaranteed' => Dec::fix($tg), 'bal' => Dec::fix($tb), '_class' => 'subtotal-row'];
        }
        return ['grouped' => true, 'columns' => [['key' => 'name', 'label' => 'Co-maker / loan'], ['key' => 'borrower', 'label' => 'Borrower'], ['key' => 'status', 'label' => 'Status'], ['key' => 'guaranteed', 'label' => 'Guaranteed', 'type' => 'money'],
            ['key' => 'bal', 'label' => 'Loan balance', 'type' => 'money']], 'rows' => $rows, 'notes' => 'Open loans each co-maker guarantees. A co-maker who guarantees many loans, or loans in arrears, is a concentrated risk.'];
    },
]);

Report::define('loan_collateral', [
    'filters' => ['search'],
    'extra_filters' => ['status' => ['Collateral', fn() => ['held' => 'Held', 'released' => 'Released'], 'All'], 'expiring' => ['Show', fn() => ['60' => 'Expiring within 60 days or expired'], 'Everything']],
    'run' => function (array $f): array {
        crpt_ready();
        $p = [];
        $sql = 'SELECT c.*, l.id AS loan_id, l.loan_no, l.status AS loan_status, l.principal_balance, m.full_name FROM loan_collaterals c JOIN loan_applications a ON a.id = c.application_id
                  JOIN loan_accounts l ON l.id = a.loan_id JOIN members m ON m.id = l.member_id WHERE 1 = 1' . crpt_branch_sql($f, 'l.branch_id', $p);
        if ($f['status'] !== '') {
            $sql .= ' AND c.status = :st';
            $p['st'] = $f['status'];
        }
        if ($f['expiring'] !== '') {
            $sql .= ' AND ((c.expiry_date IS NOT NULL AND c.expiry_date <= :e1) OR (c.insurance_expiry IS NOT NULL AND c.insurance_expiry <= :e2))';
            $p += ['e1' => Coop::addDays(today(), 60), 'e2' => Coop::addDays(today(), 60)];
        }
        if (trim((string)$f['q']) !== '') {
            $sql .= ' AND (m.full_name LIKE :q OR l.loan_no = :q2 OR c.ref_no LIKE :q3)';
            $p += ['q' => '%' . trim($f['q']) . '%', 'q2' => trim($f['q']), 'q3' => '%' . trim($f['q']) . '%'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY m.full_name, l.loan_no', $p) as $c) {
            $rows[] = ['loan' => $c['loan_no'], 'member' => $c['full_name'], 'type' => $c['collateral_type'], 'desc' => $c['description'], 'ref' => $c['ref_no'], 'fmv' => Dec::fix($c['fmv']), 'lv' => Dec::fix($c['loan_value']),
                'bal' => Dec::fix($c['principal_balance']), 'expiry' => $c['expiry_date'], 'ins' => $c['insurance_expiry'], 'status' => $c['status'] === 'released' ? 'Released ' . fdate((string)$c['released_on']) : 'Held',
                '_links' => ['loan' => Report::link('loan.accounts', ['id' => $c['loan_id'], 'tab' => 'security'])]];
        }
        return ['columns' => [['key' => 'loan', 'label' => 'Loan'], ['key' => 'member', 'label' => 'Member'], ['key' => 'type', 'label' => 'Type'], ['key' => 'desc', 'label' => 'Description'], ['key' => 'ref', 'label' => 'TCT / OR-CR / ref.'],
            ['key' => 'fmv', 'label' => 'Market value', 'type' => 'money'], ['key' => 'lv', 'label' => 'Loan value', 'type' => 'money'], ['key' => 'bal', 'label' => 'Loan balance', 'type' => 'money'], ['key' => 'expiry', 'label' => 'Document expiry', 'type' => 'date'],
            ['key' => 'ins', 'label' => 'Insurance expiry', 'type' => 'date'], ['key' => 'status', 'label' => 'Status']], 'rows' => $rows, 'totals' => crpt_totals($rows, ['fmv', 'lv', 'bal'], 'loan')];
    },
]);

Report::define('loan_gl_compare', [
    'filters' => ['asof'],
    'sortable' => false,
    'run' => function (array $f): array {
        crpt_ready();
        $rows = [];
        foreach (DB::column('SELECT DISTINCT receivable_account_id FROM loan_accounts') as $acct) {
            $acct = (int)$acct;
            $gl = Dec::of(DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND txn_date <= :d', ['a' => $acct, 'd' => $f['asof']]));
            $rows[] = ['name' => Coop::accountLabel($acct), '_class' => 'group-row'];
            $sl = '0';
            foreach (DB::all('SELECT p.name, COALESCE(SUM(x.principal_dr - x.principal_cr),0) AS bal FROM loan_txns x JOIN loan_accounts l ON l.id = x.loan_id JOIN loan_products p ON p.id = l.product_id
                               WHERE l.receivable_account_id = :a AND x.is_voided = 0 AND x.txn_date <= :d GROUP BY p.name ORDER BY p.name', ['a' => $acct, 'd' => $f['asof']]) as $r) {
                $rows[] = ['name' => $r['name'], 'sl' => Dec::fix($r['bal']), '_class' => 'indent-1'];
                $sl = Dec::add($sl, $r['bal'], 2);
            }
            $rows[] = ['name' => 'Schedule of loans / general ledger', 'sl' => Dec::fix($sl), 'gl' => Dec::fix($gl), 'diff' => Dec::fix(Dec::sub($sl, $gl, 2)), '_class' => 'subtotal-row',
                '_links' => ['name' => Report::link('rpt.general_ledger', ['account_id' => $acct, 'from' => '2000-01-01', 'to' => $f['asof'], 'dates' => 'custom'])]];
        }
        try {
            $irec = Accts::get('loan_interest_receivable');
            if ($f['asof'] >= today()) {
                $sl = Dec::of(DB::value("SELECT COALESCE(SUM(interest_accrued),0) FROM loan_accounts WHERE status IN ('current','past_due','restructured')"));
                $gl = Dec::of(DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND txn_date <= :d', ['a' => $irec, 'd' => $f['asof']]));
                $rows[] = ['name' => Coop::accountLabel($irec), 'sl' => Dec::fix($sl), 'gl' => Dec::fix($gl), 'diff' => Dec::fix(Dec::sub($sl, $gl, 2)), '_class' => 'subtotal-row'];
            }
        } catch (UserError $e) {
        }
        return ['grouped' => true, 'columns' => [['key' => 'name', 'label' => 'Account / product'], ['key' => 'sl', 'label' => 'Loan ledgers', 'type' => 'money'], ['key' => 'gl', 'label' => 'General ledger', 'type' => 'money'],
            ['key' => 'diff', 'label' => 'Difference', 'type' => 'money']], 'rows' => $rows,
            'notes' => 'The outstanding principal of the loan ledgers against the loans receivable accounts in the general ledger. Accrued interest is compared on today\'s date.'];
    },
]);

Report::define('loan_waivers', [
    'filters' => ['range'],
    'extra_filters' => ['branch' => ['Branch', fn() => crpt_branches()], 'kind' => ['Show', fn() => ['waiver' => 'Waivers', 'adjustment' => 'Adjustments'], 'Both']],
    'run' => function (array $f): array {
        crpt_ready();
        $rows = [];
        if ($f['kind'] !== 'adjustment') {
            $p = ['f' => $f['from'], 't' => $f['to']];
            foreach (DB::all('SELECT w.*, l.loan_no, l.id AS lid, m.full_name, ur.username AS req, ua.username AS appr FROM loan_waivers w JOIN loan_accounts l ON l.id = w.loan_id JOIN members m ON m.id = l.member_id
                               LEFT JOIN users ur ON ur.id = w.requested_by LEFT JOIN users ua ON ua.id = w.approved_by WHERE w.waive_date BETWEEN :f AND :t' . crpt_branch_sql($f, 'l.branch_id', $p, 'wb') . ' ORDER BY w.waive_date, w.id', $p) as $w) {
                $rows[] = ['date' => $w['waive_date'], 'kind' => 'Waiver (' . $w['status'] . ')', 'loan' => $w['loan_no'], 'member' => $w['full_name'], 'principal' => '', 'interest' => Dec::fix(Dec::neg($w['interest_amount'])),
                    'penalty' => Dec::fix(Dec::neg($w['penalty_amount'])), 'reason' => $w['memo'], 'by' => $w['req'], 'approved' => $w['appr'], '_links' => ['loan' => Report::link('loan.waivers', ['loan' => $w['lid'], 'status' => 'all'])]];
            }
        }
        if ($f['kind'] !== 'waiver') {
            $p = ['f' => $f['from'], 't' => $f['to']];
            foreach (DB::all('SELECT a.*, l.loan_no, l.id AS lid, m.full_name, u.username, t.is_voided FROM loan_adjustments a JOIN loan_accounts l ON l.id = a.loan_id JOIN members m ON m.id = l.member_id LEFT JOIN users u ON u.id = a.created_by
                               LEFT JOIN transactions t ON t.id = a.txn_id WHERE a.adj_date BETWEEN :f AND :t' . crpt_branch_sql($f, 'l.branch_id', $p, 'ab') . ' ORDER BY a.adj_date, a.id', $p) as $a) {
                $sg = fn($amt, $type) => Dec::isPos($amt) ? Dec::fix($type === 'increase' ? $amt : Dec::neg($amt)) : '';
                $rows[] = ['date' => $a['adj_date'], 'kind' => 'Adjustment' . ((int)$a['is_voided'] ? ' (voided)' : ''), 'loan' => $a['loan_no'], 'member' => $a['full_name'], 'principal' => $sg($a['principal_amt'], $a['principal_type']),
                    'interest' => $sg($a['interest_amt'], $a['interest_type']), 'penalty' => $sg($a['penalty_amt'], $a['penalty_type']), 'reason' => $a['memo'], 'by' => $a['username'], 'approved' => '',
                    '_links' => ['loan' => Report::link('loan.adjustments', ['loan' => $a['lid']])]];
            }
        }
        usort($rows, fn($x, $y) => strcmp($x['date'], $y['date']));
        return ['columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'kind', 'label' => 'Kind'], ['key' => 'loan', 'label' => 'Loan'], ['key' => 'member', 'label' => 'Member'],
            ['key' => 'principal', 'label' => 'Principal', 'type' => 'money'], ['key' => 'interest', 'label' => 'Interest', 'type' => 'money'], ['key' => 'penalty', 'label' => 'Penalty', 'type' => 'money'],
            ['key' => 'reason', 'label' => 'Reason'], ['key' => 'by', 'label' => 'Requested / made by'], ['key' => 'approved', 'label' => 'Approved by']], 'rows' => $rows,
            'notes' => 'Every change to a member\'s loan balance outside a payment: decreases are negative. Review them with the approvals.'];
    },
]);

Report::define('loan_writeoffs', [
    'filters' => ['range'],
    'default_dates' => 'all',
    'extra_filters' => ['branch' => ['Branch', fn() => crpt_branches()]],
    'run' => function (array $f): array {
        crpt_ready();
        $p = ['f' => $f['from'], 't' => $f['to']];
        $rows = [];
        foreach (DB::all("SELECT l.*, m.full_name, p.name AS product_name, x.principal_cr AS wo_p, x.interest_cr AS wo_i, x.penalty_cr AS wo_n, t.txn_no, t.txn_type FROM loan_accounts l JOIN members m ON m.id = l.member_id
                           JOIN loan_products p ON p.id = l.product_id LEFT JOIN loan_txns x ON x.loan_id = l.id AND x.kind = 'writeoff' AND x.is_voided = 0 LEFT JOIN transactions t ON t.id = l.written_off_txn_id
                          WHERE l.written_off_on BETWEEN :f AND :t" . crpt_branch_sql($f, 'l.branch_id', $p) . ' ORDER BY l.written_off_on', $p) as $l) {
            $rows[] = ['date' => $l['written_off_on'], 'loan' => $l['loan_no'], 'member' => $l['full_name'], 'product' => $l['product_name'], 'principal' => Dec::fix($l['wo_p'] ?? '0'), 'interest' => Dec::fix($l['wo_i'] ?? '0'),
                'penalty' => Dec::fix($l['wo_n'] ?? '0'), 'recovered' => Dec::fix($l['recovered_amount']), 'net' => Dec::fix(Dec::sub($l['wo_p'] ?? '0', $l['recovered_amount'], 2)), 'reason' => $l['notes'], 'voucher' => $l['txn_no'],
                '_links' => ['loan' => Report::link('loan.restructure', ['loan' => $l['id']]), 'voucher' => $l['written_off_txn_id'] ? Report::txnLink(['txn_type' => $l['txn_type'], 'txn_id' => $l['written_off_txn_id']]) : null]];
        }
        return ['columns' => [['key' => 'date', 'label' => 'Written off', 'type' => 'date'], ['key' => 'loan', 'label' => 'Loan'], ['key' => 'member', 'label' => 'Member'], ['key' => 'product', 'label' => 'Product'],
            ['key' => 'principal', 'label' => 'Principal written off', 'type' => 'money'], ['key' => 'interest', 'label' => 'Interest dropped', 'type' => 'money'], ['key' => 'penalty', 'label' => 'Penalty dropped', 'type' => 'money'],
            ['key' => 'recovered', 'label' => 'Recovered', 'type' => 'money'], ['key' => 'net', 'label' => 'Net loss', 'type' => 'money'], ['key' => 'reason', 'label' => 'Resolution / reason'], ['key' => 'voucher', 'label' => 'Voucher']],
            'rows' => $rows, 'totals' => crpt_totals($rows, ['principal', 'interest', 'penalty', 'recovered', 'net'], 'date')];
    },
]);
