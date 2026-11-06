<?php
declare(strict_types=1);

/**
 * Water Billing reports run by the accounting report engine: collections, revenue by billing period, receivables by
 * source, the ledger side of the water postings, one account's statement, the posting log with its exceptions and
 * the account mapping check. The water records (bill_readings, one_time_bills, installment_schedules,
 * recurring_bills, payment_transactions) are the operational side; water_postings and the transactions they point
 * to are the ledger side. Where a report shows both, the difference column is the reconciliation.
 */

function wrpt_ready(): void
{
    if (!Party::waterReady() || !WaterPosting::ready()) {
        fail('The water-billing module is not installed (run database/migrations/2026_12_01_water_billing.sql).');
    }
}

/** The water branches (organization units) for a filter */
function wrpt_branches(): array
{
    try {
        return DB::pairs('SELECT pkey, name FROM branches WHERE Deleted = 0 ORDER BY name');
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * The classes for the Class filter of the water reports (the Class List of the accounting books). Branch, segment and
 * division are no longer asked on the water forms: the class is what the water transactions carry. Before the class
 * columns are installed the filter falls back to the former water branches.
 */
function wrpt_classes(): array
{
    return WaterPosting::classColumns() ? Classes::options() : wrpt_branches();
}

/** The column the Class filter reads: class_id once installed, else the former branch_id */
function wrpt_class_col(): string
{
    return WaterPosting::classColumns() ? 'class_id' : 'branch_id';
}

/** The zones of the water-service accounts for a filter */
function wrpt_zones(): array
{
    try {
        $out = [];
        foreach (DB::column('SELECT DISTINCT zone FROM water_accounts WHERE zone IS NOT NULL ORDER BY zone') as $z) {
            $out[(string)$z] = 'Zone ' . $z;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** The receivable sources of the water module, as the water records and the postings name them */
const WRPT_SOURCES = [
    'water'       => ['Water bills', 'water_bill'],
    'one_time'    => ['One-time bills', 'one_time_bill'],
    'installment' => ['Installment bills', 'installment_bill'],
    'recurring'   => ['Recurring bills', 'recurring_bill'],
];

/** What a water posting is about, from its source type */
function wrpt_source_label(string $sourceType): string
{
    return match ($sourceType) {
        'water_bill' => 'Water bill', 'one_time_bill' => 'One-time bill', 'installment_bill' => 'Installment bill', 'recurring_bill' => 'Recurring bill',
        'collection' => 'Collection (OR)', 'collection_penalty' => 'Penalty collected', 'collection_compromise' => 'Compromise collected',
        'collection_reversal' => 'Collection reversal', 'collection_refund' => 'Collection refund', 'issuance' => 'Material issuance',
        default => ucwords(str_replace('_', ' ', $sourceType)),
    };
}

function wrpt_sum(array $rows, string $key): string
{
    $s = '0';
    foreach ($rows as $r) {
        if (isset($r[$key]) && is_numeric($r[$key]) && empty($r['_class'])) {
            $s = Dec::add($s, $r[$key], 2);
        }
    }
    return Dec::fix($s);
}

function wrpt_totals(array $rows, array $keys, string $labelKey, string $label = 'TOTAL'): array
{
    $t = [$labelKey => $label . ' (' . count(array_filter($rows, fn($r) => empty($r['_class']))) . ')', '_class' => 'total-row'];
    foreach ($keys as $k) {
        $t[$k] = wrpt_sum($rows, $k);
    }
    return $t;
}

/** The water receipt print page, when the user may open it */
function wrpt_receipt_link(string $receiptNo): ?string
{
    return $receiptNo !== '' && Access::page('water.collection_receipt') ? url('water.collection_receipt', ['receipt_no' => $receiptNo]) : null;
}

/** The water account statement of this report engine, for one account number */
function wrpt_statement_link(string $accountNo): ?string
{
    return Report::link('rpt.water_account_statement', ['q' => $accountNo, 'dates' => 'all']);
}

/** "11001 DELA CRUZ, JUAN P." */
function wrpt_account_label(array $a): string
{
    return trim((string)($a['account_number'] ?: 'W-' . $a['pkey']) . ' ' . WaterAccounts::listName($a));
}

/** The ledger open balance of the posted water invoices, by source type (invoices less what was applied to them) */
function wrpt_ledger_open_by_source(string $extraWhere = '', array $p = []): array
{
    $rows = DB::all("SELECT w.source_type, COUNT(*) AS n, SUM(t.total) - COALESCE(SUM(ap.applied), 0) AS open_amt
                       FROM water_postings w
                       JOIN transactions t ON t.id = w.txn_id
                       LEFT JOIN (SELECT pa.target_txn_id, SUM(pa.amount + pa.discount + pa.withholding) AS applied
                                    FROM payment_applications pa JOIN transactions p ON p.id = pa.payment_txn_id AND p.is_voided = 0
                                   GROUP BY pa.target_txn_id) ap ON ap.target_txn_id = t.id
                       LEFT JOIN water_accounts a ON a.pkey = w.water_account_id
                      WHERE w.status = 'posted' AND t.is_voided = 0 AND t.txn_type = 'invoice'
                        AND w.source_type IN ('water_bill', 'one_time_bill', 'installment_bill', 'recurring_bill') $extraWhere
                      GROUP BY w.source_type", $p);
    $out = [];
    foreach ($rows as $r) {
        $out[$r['source_type']] = ['n' => (int)$r['n'], 'open' => Dec::fix($r['open_amt'])];
    }
    return $out;
}

// ============================================================================ 1. Collection Summary

/** The share capital collected on a receipt (Fixed Share Capital Billing), once the column is installed */
function wrpt_share_capital_ready(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            DB::value('SELECT COUNT(share_capital_amount) FROM payment_transactions WHERE 1 = 0');
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** The code name of the water bills: the bill code of the water billing (Bill Codes), WATER BILL by default */
function wrpt_water_code_name(): string
{
    try {
        $id = (int)DB::value("SELECT setting_value FROM water_billing_settings WHERE setting_key = 'water_billing_bill_code_id'");
        $name = $id > 0 ? (string)DB::value('SELECT code FROM bill_codes WHERE code_id = :c', ['c' => $id]) : '';
        if (trim($name) === '') {
            $name = (string)DB::value("SELECT code FROM bill_codes WHERE UPPER(TRIM(code)) IN ('WATER BILL', 'WATER FEE') ORDER BY UPPER(TRIM(code)) = 'WATER BILL' DESC, code_id LIMIT 1");
        }
        return trim($name) !== '' ? strtoupper(trim($name)) : 'WATER BILL';
    } catch (Throwable $e) {
        return 'WATER BILL';
    }
}

/**
 * What each receipt paid, by code name (the Detailed report): transaction_id => [code name => amount], and the code
 * names in their order (the water bill first, then the bill codes as listed in Bill Codes, the fixed share capital last).
 * Each bill paid is put under the code name of its bill code: one-time, recurring and installment bills by their own
 * bill code, water bills under the water bill code, share capital contributions under FIXED SHARE CAPITAL.
 */
function wrpt_receipt_code_amounts(array $transactionIds): array
{
    $byReceipt = [];
    $order = [];
    if (!$transactionIds) {
        return [$byReceipt, []];
    }
    $water = wrpt_water_code_name();
    $sort = DB::pairs('SELECT UPPER(TRIM(code)), code_id FROM bill_codes');
    foreach (array_chunk(array_values(array_unique(array_map('intval', $transactionIds))), 500) as $chunk) {
        $rows = DB::all("SELECT i.transaction_id, i.source_type, i.description, COALESCE(i.principal_amount, i.amount) AS amt,
                                CASE i.source_type
                                  WHEN 'onetime' THEN (SELECT bc.code FROM one_time_bills b JOIN bill_codes bc ON bc.code_id = b.bill_code_id WHERE b.bill_id = i.source_id)
                                  WHEN 'recurring' THEN (SELECT bc.code FROM recurring_bills b JOIN bill_codes bc ON bc.code_id = b.bill_code_id WHERE b.bill_id = i.source_id)
                                  WHEN 'installment' THEN (SELECT bc.code FROM installment_schedules sc JOIN installment_bills b ON b.installment_id = sc.installment_id
                                                            JOIN bill_codes bc ON bc.code_id = b.bill_code_id WHERE sc.schedule_id = i.source_id)
                                  ELSE NULL END AS code_name
                           FROM payment_transaction_items i
                          WHERE i.transaction_id IN (" . implode(',', $chunk) . ')
                          ORDER BY i.transaction_id, i.item_id');
        foreach ($rows as $r) {
            $type = (string)$r['source_type'];
            if ($type === 'water') {
                $name = $water;
            } elseif ($type === 'sharecap') {
                $name = class_exists('ShareCapitalBilling') ? ShareCapitalBilling::CODE_NAME : 'FIXED SHARE CAPITAL';
            } else {
                $name = strtoupper(trim((string)$r['code_name']));
                if ($name === '') {
                    // a bill whose bill code is gone: its description, as the receipt prints it
                    $name = strtoupper(trim((string)preg_replace('/\s*\((?:INSTALLMENT|ONE[- ]?TIME|RECURRING)\)\s*$/i', '', (string)$r['description']))) ?: 'OTHER';
                }
            }
            $t = (int)$r['transaction_id'];
            $byReceipt[$t][$name] = Dec::add($byReceipt[$t][$name] ?? '0', (string)$r['amt'], 2);
            if (!isset($order[$name])) {
                $order[$name] = $type === 'water' ? -1 : ($type === 'sharecap' ? PHP_INT_MAX : (int)($sort[$name] ?? 1000000));
            }
        }
    }
    uksort($order, fn($a, $b) => [$order[$a], $a] <=> [$order[$b], $b]);
    return [$byReceipt, array_keys($order)];
}

Report::define('water_collection_summary', [
    'filters' => ['range', 'user', 'search'],
    'range_time' => true,
    'filter_labels' => ['user' => 'Cashier'],
    'default_dates' => 'this_month',
    'extra_filters' => [
        // Summary: the bills paid in one amount (as before). Detailed: what was paid by code name, a column for each.
        'rtype' => ['Report type', fn() => ['summary' => 'Summary', 'detailed' => 'Detailed'], null],
        'view' => ['Show', fn() => ['receipts' => 'Every receipt', 'date' => 'By date', 'cashier' => 'By cashier', 'ptype' => 'By payment type', 'date_cashier' => 'By date and cashier'], null],
        'ptype' => ['Payment type', fn() => ['water' => 'Water', 'installment' => 'Installment', 'onetime' => 'One-time', 'recurring' => 'Recurring', 'sharecap' => 'Share capital', 'mixed' => 'Mixed']],
        'wclass' => ['Class', fn() => wrpt_classes()],
        'pstatus' => ['Receipt status', fn() => ['active' => 'Active', 'reversed' => 'Reversed', 'refunded' => 'Refunded', 'all' => 'All'], null],
    ],
    'run' => function (array $f): array {
        wrpt_ready();
        $view = $f['view'] !== '' ? $f['view'] : 'receipts';
        $detailed = $f['rtype'] === 'detailed';
        $status = $f['pstatus'] !== '' ? $f['pstatus'] : 'active';
        $hasShare = wrpt_share_capital_ready();
        // The period: whole days (12:00 AM to 11:59 PM) count the receipts by their business date, as always; a period
        // with times counts them by the moment they were recorded, so only collections within those times are shown.
        // (rec=1: a line of the times-period opened for one whole day keeps counting by the moment recorded.)
        $timed = substr($f['from_dt'], 11, 5) !== '00:00' || substr($f['to_dt'], 11, 5) !== '23:59' || (string)g('rec') === '1';
        $dateExpr = $timed ? 'DATE(pt.payment_date)' : 'COALESCE(pt.business_date, DATE(pt.payment_date))';
        $shareCol = $hasShare ? 'pt.share_capital_amount' : '0.00';
        $sql = "SELECT pt.transaction_id, pt.receipt_no, pt.member_id, pt.payment_type, pt.base_amount, pt.penalty_amount, pt.compromise_amount, pt.total_paid,
                       $shareCol AS share_amount,
                       pt.cashier_id, $dateExpr AS bdate, COALESCE(pt.business_date, DATE(pt.payment_date)) AS business_day, pt.payment_date, pt.transaction_status, pt.branch_id,
                       a.account_number, a.lastname, a.firstname, a.middlename, a.pkey, a.party_id,
                       COALESCE(NULLIF(u.full_name, ''), u.username) AS cashier,
                       w.txn_id AS ledger_txn_id, w.status AS posting_status
                  FROM payment_transactions pt
                  LEFT JOIN water_accounts a ON a.pkey = pt.member_id
                  LEFT JOIN users u ON u.id = pt.cashier_id
                  LEFT JOIN water_postings w ON w.source_type = 'collection' AND w.source_id = pt.receipt_no AND w.event_key NOT LIKE '%#%'
                 WHERE " . ($timed ? 'pt.payment_date BETWEEN :f AND :t' : 'COALESCE(pt.business_date, DATE(pt.payment_date)) BETWEEN :f AND :t');
        $p = $timed ? ['f' => $f['from_dt'], 't' => $f['to_dt']] : ['f' => $f['from'], 't' => $f['to']];
        if ($status !== 'all') {
            $sql .= ' AND pt.transaction_status = :st';
            $p['st'] = $status;
        }
        if (!empty($f['user_id'])) {
            $sql .= ' AND pt.cashier_id = :u';
            $p['u'] = (int)$f['user_id'];
        }
        if ($f['ptype'] !== '') {
            $sql .= ' AND pt.payment_type = :pt';
            $p['pt'] = $f['ptype'];
        }
        if ($f['wclass'] !== '') {
            $sql .= ' AND pt.' . wrpt_class_col() . ' = :br';
            $p['br'] = (int)$f['wclass'];
        }
        $q = trim((string)$f['q']);
        if ($q !== '') {
            $sql .= ' AND (pt.receipt_no LIKE :q1 OR a.account_number LIKE :q2 OR a.lastname LIKE :q3 OR a.firstname LIKE :q4)';
            $p += ['q1' => '%' . $q . '%', 'q2' => $q . '%', 'q3' => $q . '%', 'q4' => $q . '%'];
        }
        $data = DB::all($sql . ' ORDER BY bdate, pt.payment_date, pt.transaction_id LIMIT 20000', $p);
        $typeLabels = ['water' => 'Water', 'installment' => 'Installment', 'onetime' => 'One-time', 'recurring' => 'Recurring', 'sharecap' => 'Share capital', 'mixed' => 'Mixed'];

        // the share capital deposits of the receipts (their own journal entries, in the savings books)
        $shareTxn = [];
        if ($hasShare && $data && class_exists('ShareCapitalBilling') && ShareCapitalBilling::ready()) {
            $ids = array_map(fn($r) => (int)$r['transaction_id'], array_filter($data, fn($r) => Dec::isPos((string)$r['share_amount'])));
            if ($ids) {
                $shareTxn = DB::pairs('SELECT transaction_id, MIN(ledger_txn_id) FROM share_capital_collections WHERE transaction_id IN (' . implode(',', $ids) . ') GROUP BY transaction_id');
            }
        }

        // the money columns: Summary, the bills paid in one amount and the share capital; Detailed, a column for each
        // code name collected in the period (never put together)
        $codeCols = [];
        $byReceipt = [];
        if ($detailed) {
            [$byReceipt, $names] = wrpt_receipt_code_amounts(array_map(fn($r) => (int)$r['transaction_id'], $data));
            foreach ($names as $i => $name) {
                $codeCols['code' . ($i + 1)] = $name;
            }
            $money = array_merge(array_keys($codeCols), ['penalty', 'compromise', 'total']);
            $moneyCols = [];
            foreach ($codeCols as $k => $name) {
                $moneyCols[] = ['key' => $k, 'label' => $name, 'type' => 'money'];
            }
        } else {
            $money = $hasShare ? ['base', 'share', 'penalty', 'compromise', 'total'] : ['base', 'penalty', 'compromise', 'total'];
            $moneyCols = [['key' => 'base', 'label' => 'Bills paid', 'type' => 'money']];
            if ($hasShare) {
                $moneyCols[] = ['key' => 'share', 'label' => 'Share capital', 'type' => 'money'];
            }
        }
        $moneyCols[] = ['key' => 'penalty', 'label' => 'Penalty', 'type' => 'money'];
        $moneyCols[] = ['key' => 'compromise', 'label' => 'Compromise', 'type' => 'money'];
        $moneyCols[] = ['key' => 'total', 'label' => 'Total collected', 'type' => 'money'];

        // the amounts of one receipt in those columns
        $amountsOf = function (array $r) use ($detailed, $codeCols, $byReceipt, $hasShare): array {
            $out = ['penalty' => Dec::fix($r['penalty_amount']), 'compromise' => Dec::fix($r['compromise_amount']), 'total' => Dec::fix($r['total_paid'])];
            if ($detailed) {
                $paid = $byReceipt[(int)$r['transaction_id']] ?? [];
                foreach ($codeCols as $k => $name) {
                    $out[$k] = Dec::fix($paid[$name] ?? '0');
                }
            } else {
                $out['base'] = Dec::fix($r['base_amount']);
                if ($hasShare) {
                    $out['share'] = Dec::fix($r['share_amount']);
                }
            }
            return $out;
        };

        $rows = [];
        if ($view === 'receipts') {
            foreach ($data as $r) {
                $acct = $r['pkey'] ? wrpt_account_label($r) : 'Account #' . $r['member_id'];
                $shareLedger = $shareTxn[(int)$r['transaction_id']] ?? null;
                if ($r['ledger_txn_id']) {
                    $ledger = $r['posting_status'] === 'posted' ? 'Payment #' . $r['ledger_txn_id'] : ucfirst((string)$r['posting_status']);
                    $ledgerLink = Report::txnLink(['txn_type' => 'payment', 'txn_id' => $r['ledger_txn_id']]);
                    if ($shareLedger) {
                        $ledger .= ', share capital deposit #' . $shareLedger;
                    }
                } elseif ($shareLedger) {
                    $ledger = 'Share capital deposit #' . $shareLedger;
                    $ledgerLink = Report::txnLink(['txn_type' => 'coop_sv', 'txn_id' => $shareLedger]);
                } else {
                    $ledger = 'Not posted';
                    $ledgerLink = null;
                }
                $rows[] = ['date' => $r['business_day'], 'receipt' => $r['receipt_no'], 'account' => $acct, 'cashier' => (string)$r['cashier'], 'ptype' => $typeLabels[$r['payment_type']] ?? $r['payment_type'],
                    'status' => ucfirst((string)$r['transaction_status']), 'ledger' => $ledger, 'when' => (string)$r['payment_date'],
                    '_links' => ['receipt' => wrpt_receipt_link((string)$r['receipt_no']), 'account' => $r['account_number'] ? wrpt_statement_link((string)$r['account_number']) : null,
                        'ledger' => $ledgerLink]] + $amountsOf($r);
            }
            $columns = array_merge(
                [['key' => 'date', 'label' => 'Business date', 'type' => 'date'], ['key' => 'when', 'label' => 'Date and time recorded', 'type' => 'datetime'], ['key' => 'receipt', 'label' => 'Receipt no.'], ['key' => 'account', 'label' => 'Water-service account'],
                    ['key' => 'cashier', 'label' => 'Cashier'], ['key' => 'ptype', 'label' => 'Payment type']],
                $moneyCols,
                [['key' => 'status', 'label' => 'Status'], ['key' => 'ledger', 'label' => 'Ledger document']]
            );
            $labelKey = 'date';
        } else {
            $groups = [];
            foreach ($data as $r) {
                $key = match ($view) {
                    'date' => $r['bdate'],
                    'cashier' => (string)($r['cashier'] ?: 'User #' . $r['cashier_id']),
                    'ptype' => $typeLabels[$r['payment_type']] ?? $r['payment_type'],
                    default => $r['bdate'] . '|' . ($r['cashier'] ?: 'User #' . $r['cashier_id']),
                };
                if (!isset($groups[$key])) {
                    $groups[$key] = ['date' => $view === 'cashier' || $view === 'ptype' ? '' : $r['bdate'], 'who' => $view === 'ptype' ? ($typeLabels[$r['payment_type']] ?? $r['payment_type']) : ($view === 'date' ? '' : (string)($r['cashier'] ?: 'User #' . $r['cashier_id'])),
                        'uid' => (int)$r['cashier_id'], 'n' => 0] + array_fill_keys($money, '0');
                }
                $g = &$groups[$key];
                $g['n']++;
                foreach ($amountsOf($r) as $k => $v) {
                    $g[$k] = Dec::add($g[$k], $v, 2);
                }
                unset($g);
            }
            foreach ($groups as $g) {
                foreach ($money as $k) {
                    $g[$k] = Dec::fix($g[$k]);
                }
                // the receipts of the line, within the period's times (a day of the period starts and ends at its times)
                $linkFrom = $g['date'] !== '' ? max($g['date'] . ' 00:00', substr($f['from_dt'], 0, 16)) : substr($f['from_dt'], 0, 16);
                $linkTo = $g['date'] !== '' ? min($g['date'] . ' 23:59', substr($f['to_dt'], 0, 16)) : substr($f['to_dt'], 0, 16);
                $g['_links'] = ['n' => Report::link('rpt.water_collection_summary', ['from' => str_replace(' ', 'T', $linkFrom), 'to' => str_replace(' ', 'T', $linkTo), 'dates' => 'custom', 'view' => 'receipts', 'rec' => $timed ? 1 : null,
                    'rtype' => $detailed ? 'detailed' : null,
                    'ptype' => $view === 'ptype' ? ((string)array_search($g['who'], $typeLabels, true) ?: '') : $f['ptype'], 'user_id' => $view === 'cashier' || $view === 'date_cashier' ? $g['uid'] : $f['user_id'], 'pstatus' => $status, 'wclass' => $f['wclass']])];
                $rows[] = $g;
            }
            $columns = array_merge(array_values(array_filter([
                $view !== 'cashier' && $view !== 'ptype' ? ['key' => 'date', 'label' => 'Date', 'type' => 'date'] : null,
                $view !== 'date' ? ['key' => 'who', 'label' => $view === 'ptype' ? 'Payment type' : 'Cashier'] : null,
                ['key' => 'n', 'label' => 'Receipts', 'type' => 'int'],
            ])), $moneyCols);
            $labelKey = $columns[0]['key'];
        }
        $totals = wrpt_totals($rows, $money, $labelKey);
        if ($view !== 'receipts') {
            $totals['n'] = (string)array_sum(array_column($rows, 'n'));
        }
        $notes = ($timed ? 'Official receipts of the water cashiers recorded within the dates and times of the period. '
                : 'Official receipts of the water cashiers by business date (whole days; give times to count the receipts recorded within them). ');
        if ($detailed) {
            $notes .= 'Detailed: what was collected by code name, a column for each code name collected in the period (the bill code of each bill paid; '
                . wrpt_water_code_name() . ' for the water bills' . ($hasShare ? ', ' . (class_exists('ShareCapitalBilling') ? ShareCapitalBilling::CODE_NAME : 'FIXED SHARE CAPITAL') . ' for the share capital contributions of Fixed Share Capital Billing' : '')
                . '); penalty and compromise are collected on the spot. ';
        } else {
            $notes .= '"Bills paid" settles the bills; ' . ($hasShare ? '"Share capital" is the fixed share capital contributions collected (deposits to the members\' share capital accounts); ' : '')
                . 'penalty and compromise are collected on the spot. Report type Detailed shows the collections by code name. ';
        }
        $notes .= 'Each receipt is posted to the ledger as a payment (plus finance charges for the penalty and compromise)' . ($hasShare ? ', each share capital contribution as a savings deposit' : '') . '; "Ledger document" leads to it. '
            . ($status === 'active' ? 'Reversed and refunded receipts are left out; choose a receipt status to see them.' : '');
        return ['columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'notes' => $notes];
    },
]);

// ============================================================================ 2. Revenue by Billing Period

Report::define('water_revenue_period', [
    'filters' => [],
    'extra_filters' => [
        'period' => ['Billing period', fn() => DB::pairs("SELECT period_id, CONCAT(bp_code, ' (', DATE_FORMAT(start_date, '%m/%d/%Y'), ' - ', DATE_FORMAT(end_date, '%m/%d/%Y'), ')') FROM bill_periods ORDER BY start_date DESC")],
        'wclass' => ['Class', fn() => wrpt_classes()],
        'zone' => ['Zone', fn() => wrpt_zones()],
    ],
    'basis' => function (array $f): string {
        $parts = [];
        if ($f['period'] !== '') {
            $parts[] = 'Billing period ' . DB::value('SELECT bp_code FROM bill_periods WHERE period_id = :id', ['id' => (int)$f['period']]);
        }
        if ($f['wclass'] !== '') {
            $parts[] = 'Class ' . (wrpt_classes()[(int)$f['wclass']] ?? $f['wclass']);
        }
        if ($f['zone'] !== '') {
            $parts[] = 'Zone ' . $f['zone'];
        }
        return implode(', ', $parts);
    },
    'run' => function (array $f): array {
        wrpt_ready();
        $where = "b.is_billed = 1 AND COALESCE(b.bill_lifecycle_status, 'active') = 'active'";
        $p = [];
        if ($f['period'] !== '') {
            $where .= ' AND b.period_id = :pid';
            $p['pid'] = (int)$f['period'];
        }
        if ($f['wclass'] !== '') {
            $where .= ' AND b.' . wrpt_class_col() . ' = :br';
            $p['br'] = (int)$f['wclass'];
        }
        $join = '';
        if ($f['zone'] !== '') {
            $join = ' JOIN water_accounts a ON a.pkey = b.member_id';
            $where .= ' AND a.zone = :z';
            $p['z'] = (int)$f['zone'];
        }
        $water = DB::all("SELECT bp.period_id, bp.bp_code, bp.start_date, bp.end_date, bp.status, COUNT(b.reading_id) AS n, SUM(b.consumption) AS cons,
                                 SUM(b.amount_due) AS due, SUM(b.amount_paid) AS paid, SUM(GREATEST(0, b.amount_due - COALESCE(b.amount_paid, 0))) AS bal, SUM(b.penalty_amount) AS pen
                            FROM bill_periods bp JOIN bill_readings b ON b.period_id = bp.period_id$join
                           WHERE $where
                           GROUP BY bp.period_id, bp.bp_code, bp.start_date, bp.end_date, bp.status ORDER BY bp.start_date, bp.period_id", $p);
        // the ledger side: the posted invoices (and credit memos) of those readings
        $ledger = DB::pairs("SELECT b.period_id, SUM(CASE WHEN t.txn_type = 'credit_memo' THEN -t.total ELSE t.total END)
                               FROM water_postings w JOIN transactions t ON t.id = w.txn_id JOIN bill_readings b ON b.reading_id = w.source_id$join
                              WHERE w.source_type = 'water_bill' AND w.status = 'posted' AND t.is_voided = 0 AND t.txn_type IN ('invoice', 'credit_memo') AND $where
                              GROUP BY b.period_id", $p);
        $rows = [];
        foreach ($water as $r) {
            $inv = Dec::fix($ledger[(int)$r['period_id']] ?? '0');
            $rows[] = ['period' => $r['bp_code'], 'from' => substr((string)$r['start_date'], 0, 10), 'to' => substr((string)$r['end_date'], 0, 10), 'status' => ucfirst((string)$r['status']),
                'n' => (int)$r['n'], 'cons' => Dec::fix($r['cons'], 0), 'due' => Dec::fix($r['due']), 'paid' => Dec::fix($r['paid']), 'bal' => Dec::fix($r['bal']),
                'invoiced' => $inv, 'diff' => Dec::fix(Dec::sub($r['due'], $inv, 2)), 'penalty' => Dec::fix($r['pen']),
                '_links' => ['period' => Access::page('water.billing_history') ? url('water.billing_history', ['period_id' => $r['period_id']]) : null]];
        }
        $totals = wrpt_totals($rows, ['cons', 'due', 'paid', 'bal', 'invoiced', 'diff', 'penalty'], 'period');
        $totals['n'] = (string)array_sum(array_column($rows, 'n'));
        return ['columns' => [['key' => 'period', 'label' => 'Billing period'], ['key' => 'from', 'label' => 'From', 'type' => 'date'], ['key' => 'to', 'label' => 'To', 'type' => 'date'], ['key' => 'status', 'label' => 'Period status', 'optional' => true],
            ['key' => 'n', 'label' => 'Bills', 'type' => 'int'], ['key' => 'cons', 'label' => 'Consumption (m³)', 'type' => 'qty'],
            ['key' => 'due', 'label' => 'Amount billed', 'type' => 'money'], ['key' => 'paid', 'label' => 'Amount paid', 'type' => 'money'], ['key' => 'bal', 'label' => 'Balance', 'type' => 'money'],
            ['key' => 'invoiced', 'label' => 'Invoiced in the ledger', 'type' => 'money'], ['key' => 'diff', 'label' => 'Difference', 'type' => 'money'], ['key' => 'penalty', 'label' => 'Penalty on bills', 'type' => 'money', 'optional' => true]],
            'rows' => $rows, 'totals' => $totals,
            'chart' => $rows ? ['bars' => array_combine(array_column($rows, 'period'), array_map('floatval', array_column($rows, 'due'))), 'bars_title' => 'Amount billed by period'] : null,
            'notes' => 'Posted water bills by billing period, from the water records. "Invoiced in the ledger" is the total of the invoices those bills posted to the general ledger (less credit memos on them); '
                . 'a difference means a bill was posted without its ledger document, or adjusted on one side only. The Water Posting Log & Exceptions report names them.'];
    },
]);

// ============================================================================ 3. Receivables by Source

Report::define('water_receivables_source', [
    'filters' => ['search'],
    'extra_filters' => [
        'group' => ['Show', fn() => ['source' => 'By source', 'account' => 'By water-service account'], null],
        'wclass' => ['Class', fn() => wrpt_classes()],
        'zone' => ['Zone', fn() => wrpt_zones()],
    ],
    'basis' => fn(array $f): string => 'As of ' . fdate(today()) . ($f['wclass'] !== '' ? ', class ' . (wrpt_classes()[(int)$f['wclass']] ?? $f['wclass']) : '') . ($f['zone'] !== '' ? ', zone ' . $f['zone'] : ''),
    'run' => function (array $f): array {
        wrpt_ready();
        $today = today();
        // filters on the water-service account
        $aw = '';
        $p = [];
        if ($f['zone'] !== '') {
            $aw .= ' AND a.zone = :z';
            $p['z'] = (int)$f['zone'];
        }
        $q = trim((string)$f['q']);
        if ($q !== '') {
            $aw .= ' AND (a.account_number LIKE :q1 OR a.lastname LIKE :q2 OR a.firstname LIKE :q3)';
            $p += ['q1' => $q . '%', 'q2' => $q . '%', 'q3' => $q . '%'];
        }
        $bw = '';
        if ($f['wclass'] !== '') {
            $bw = ' AND COALESCE(x.org_unit, 0) = :br';
            $p['br'] = (int)$f['wclass'];
        }
        // the open bills of every source, with their due dates, from the water records
        $age = "CASE WHEN x.due_date IS NULL OR x.due_date >= :t0 THEN 'cur' WHEN x.due_date >= DATE_SUB(:t1, INTERVAL 30 DAY) THEN 'd30'
                     WHEN x.due_date >= DATE_SUB(:t2, INTERVAL 60 DAY) THEN 'd60' WHEN x.due_date >= DATE_SUB(:t3, INTERVAL 90 DAY) THEN 'd90' ELSE 'd91' END";
        $tp = ['t0' => $today, 't1' => $today, 't2' => $today, 't3' => $today];
        $cc = wrpt_class_col();
        $sources = [
            'water' => "SELECT b.member_id, b.due_date, b.$cc AS org_unit, GREATEST(0, b.amount_due - COALESCE(b.amount_paid, 0)) AS open_amt FROM bill_readings b
                         WHERE b.is_billed = 1 AND COALESCE(b.bill_lifecycle_status, 'active') = 'active' AND b.amount_due - COALESCE(b.amount_paid, 0) > 0.004",
            'one_time' => "SELECT b.member_id, b.due_date, b.$cc AS org_unit, GREATEST(0, b.total_amount - COALESCE(b.amount_paid, 0)) AS open_amt FROM one_time_bills b
                         WHERE b.status = 'unpaid' AND COALESCE(b.bill_lifecycle_status, 'active') = 'active' AND b.total_amount - COALESCE(b.amount_paid, 0) > 0.004",
            'installment' => "SELECT b.member_id, s.due_date, b.$cc AS org_unit, GREATEST(0, s.amount - COALESCE(s.amount_paid, 0)) AS open_amt FROM installment_schedules s
                         JOIN installment_bills b ON b.installment_id = s.installment_id
                         WHERE b.status = 'active' AND COALESCE(b.bill_lifecycle_status, 'active') = 'active' AND s.amount - COALESCE(s.amount_paid, 0) > 0.004",
            'recurring' => "SELECT b.member_id, b.due_date, b.$cc AS org_unit, GREATEST(0, b.total_amount - COALESCE(b.amount_paid, 0)) AS open_amt FROM recurring_bills b
                         WHERE b.status IN ('unpaid', 'overdue') AND COALESCE(b.bill_lifecycle_status, 'active') = 'active' AND b.total_amount - COALESCE(b.amount_paid, 0) > 0.004",
        ];
        $byAccount = $f['group'] === 'account';
        $groupCol = $byAccount ? 'x.member_id' : "'all'";
        $water = [];   // [source][account or 'all'] => [n, open, cur, d30, d60, d90, d91]
        foreach ($sources as $src => $inner) {
            foreach (DB::all("SELECT $groupCol AS grp, COUNT(*) AS n, SUM(x.open_amt) AS open_amt, $age AS bucket
                                FROM ($inner) x JOIN water_accounts a ON a.pkey = x.member_id WHERE 1 = 1 $aw $bw
                               GROUP BY $groupCol, $age", $p + $tp) as $r) {
                $g = (string)$r['grp'];
                $water[$src][$g] ??= ['n' => 0, 'open' => '0', 'cur' => '0', 'd30' => '0', 'd60' => '0', 'd90' => '0', 'd91' => '0'];
                $water[$src][$g]['n'] += (int)$r['n'];
                $water[$src][$g]['open'] = Dec::add($water[$src][$g]['open'], $r['open_amt'], 2);
                $water[$src][$g][$r['bucket']] = Dec::add($water[$src][$g][$r['bucket']], $r['open_amt'], 2);
            }
        }
        // the ledger side: open invoices of the postings, by source type (and account)
        $lw = str_replace('x.org_unit', 'w.' . $cc, $bw);
        $lgroup = $byAccount ? 'w.water_account_id' : "'all'";
        $ledger = [];
        foreach (DB::all("SELECT w.source_type, $lgroup AS grp, SUM(t.total) - COALESCE(SUM(ap.applied), 0) AS open_amt
                            FROM water_postings w JOIN transactions t ON t.id = w.txn_id
                            LEFT JOIN (SELECT pa.target_txn_id, SUM(pa.amount + pa.discount + pa.withholding) AS applied FROM payment_applications pa
                                         JOIN transactions pp ON pp.id = pa.payment_txn_id AND pp.is_voided = 0 GROUP BY pa.target_txn_id) ap ON ap.target_txn_id = t.id
                            JOIN water_accounts a ON a.pkey = w.water_account_id
                           WHERE w.status = 'posted' AND t.is_voided = 0 AND t.txn_type = 'invoice'
                             AND w.source_type IN ('water_bill', 'one_time_bill', 'installment_bill', 'recurring_bill') $aw $lw
                           GROUP BY w.source_type, $lgroup", $p) as $r) {
            $ledger[$r['source_type']][(string)$r['grp']] = Dec::fix($r['open_amt']);
        }
        $rows = [];
        $moneyKeys = ['open', 'ledger', 'diff', 'cur', 'd30', 'd60', 'd90', 'd91'];
        if (!$byAccount) {
            foreach (WRPT_SOURCES as $src => [$label, $stype]) {
                $w = $water[$src]['all'] ?? ['n' => 0, 'open' => '0', 'cur' => '0', 'd30' => '0', 'd60' => '0', 'd90' => '0', 'd91' => '0'];
                $l = $ledger[$stype]['all'] ?? '0';
                $rows[] = ['source' => $label, 'n' => $w['n'], 'open' => Dec::fix($w['open']), 'ledger' => Dec::fix($l), 'diff' => Dec::fix(Dec::sub($w['open'], $l, 2)),
                    'cur' => Dec::fix($w['cur']), 'd30' => Dec::fix($w['d30']), 'd60' => Dec::fix($w['d60']), 'd90' => Dec::fix($w['d90']), 'd91' => Dec::fix($w['d91']),
                    '_links' => ['n' => Report::link('rpt.water_receivables_source', ['group' => 'account', 'wclass' => $f['wclass'], 'zone' => $f['zone'], 'q' => $q])]];
            }
            $columns = [['key' => 'source', 'label' => 'Source'], ['key' => 'n', 'label' => 'Open bills', 'type' => 'int'],
                ['key' => 'open', 'label' => 'Open balance (water records)', 'type' => 'money'], ['key' => 'ledger', 'label' => 'Open in the ledger', 'type' => 'money'], ['key' => 'diff', 'label' => 'Difference', 'type' => 'money'],
                ['key' => 'cur', 'label' => 'Current', 'type' => 'money', 'band' => 'Aging by due date'], ['key' => 'd30', 'label' => '1-30 days', 'type' => 'money', 'band' => 'Aging by due date'],
                ['key' => 'd60', 'label' => '31-60 days', 'type' => 'money', 'band' => 'Aging by due date'], ['key' => 'd90', 'label' => '61-90 days', 'type' => 'money', 'band' => 'Aging by due date'],
                ['key' => 'd91', 'label' => 'Over 90 days', 'type' => 'money', 'band' => 'Aging by due date']];
            $totals = wrpt_totals($rows, $moneyKeys, 'source');
            $totals['n'] = (string)array_sum(array_column($rows, 'n'));
            $chart = ['bars' => ['Current' => (float)$totals['cur'], '1-30 days' => (float)$totals['d30'], '31-60 days' => (float)$totals['d60'], '61-90 days' => (float)$totals['d90'], 'Over 90 days' => (float)$totals['d91']],
                'bars_title' => 'Water receivables by age (water records)'];
        } else {
            $accts = [];
            foreach ($water as $src => $byAcct) {
                foreach ($byAcct as $id => $w) {
                    $accts[(int)$id][$src] = $w;
                }
            }
            foreach ($ledger as $stype => $byAcct) {
                foreach ($byAcct as $id => $v) {
                    $accts[(int)$id]['_ledger'] = Dec::add($accts[(int)$id]['_ledger'] ?? '0', $v, 2);
                }
            }
            if ($accts) {
                [$in, $ip] = DB::in('wa', array_keys($accts));
                $names = [];
                foreach (DB::all("SELECT a.pkey, a.account_number, a.lastname, a.firstname, a.middlename, a.zone, a.connection_status, a.party_id, p.party_no, p.display_name
                                    FROM water_accounts a LEFT JOIN parties p ON p.id = a.party_id WHERE a.pkey IN $in", $ip) as $a) {
                    $names[(int)$a['pkey']] = $a;
                }
                foreach ($accts as $id => $d) {
                    $a = $names[$id] ?? ['pkey' => $id, 'account_number' => '', 'lastname' => '', 'firstname' => '', 'middlename' => '', 'zone' => '', 'connection_status' => '', 'party_id' => null, 'party_no' => '', 'display_name' => ''];
                    $r = ['account' => (string)($a['account_number'] ?: 'W-' . $id), 'name' => WaterAccounts::listName($a), 'party' => (string)$a['party_no'], 'zone' => (string)$a['zone'], 'conn' => ucfirst((string)$a['connection_status']),
                        'water' => '0.00', 'one_time' => '0.00', 'installment' => '0.00', 'recurring' => '0.00', 'open' => '0', 'cur' => '0', 'd30' => '0', 'd60' => '0', 'd90' => '0', 'd91' => '0'];
                    foreach (WRPT_SOURCES as $src => $_) {
                        if (isset($d[$src])) {
                            $r[$src] = Dec::fix($d[$src]['open']);
                            $r['open'] = Dec::add($r['open'], $d[$src]['open'], 2);
                            foreach (['cur', 'd30', 'd60', 'd90', 'd91'] as $b) {
                                $r[$b] = Dec::add($r[$b], $d[$src][$b], 2);
                            }
                        }
                    }
                    $r['ledger'] = Dec::fix($d['_ledger'] ?? '0');
                    $r['diff'] = Dec::fix(Dec::sub($r['open'], $r['ledger'], 2));
                    foreach (['open', 'cur', 'd30', 'd60', 'd90', 'd91'] as $k) {
                        $r[$k] = Dec::fix($r[$k]);
                    }
                    $r['_links'] = ['account' => wrpt_statement_link($r['account']), 'party' => $a['party_id'] ? Report::link('party.center', ['id' => $a['party_id']]) : null];
                    $rows[] = $r;
                }
                usort($rows, fn($x, $y) => strcmp($x['account'], $y['account']));
            }
            $columns = [['key' => 'account', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Name'], ['key' => 'party', 'label' => 'Party ID', 'optional' => true], ['key' => 'zone', 'label' => 'Zone', 'optional' => true], ['key' => 'conn', 'label' => 'Connection', 'optional' => true],
                ['key' => 'water', 'label' => 'Water bills', 'type' => 'money'], ['key' => 'one_time', 'label' => 'One-time', 'type' => 'money'], ['key' => 'installment', 'label' => 'Installment', 'type' => 'money'], ['key' => 'recurring', 'label' => 'Recurring', 'type' => 'money'],
                ['key' => 'open', 'label' => 'Open balance (water records)', 'type' => 'money'], ['key' => 'ledger', 'label' => 'Open in the ledger', 'type' => 'money'], ['key' => 'diff', 'label' => 'Difference', 'type' => 'money'],
                ['key' => 'cur', 'label' => 'Current', 'type' => 'money', 'band' => 'Aging by due date'], ['key' => 'd30', 'label' => '1-30 days', 'type' => 'money', 'band' => 'Aging by due date'],
                ['key' => 'd60', 'label' => '31-60 days', 'type' => 'money', 'band' => 'Aging by due date'], ['key' => 'd90', 'label' => '61-90 days', 'type' => 'money', 'band' => 'Aging by due date'],
                ['key' => 'd91', 'label' => 'Over 90 days', 'type' => 'money', 'band' => 'Aging by due date']];
            $totals = wrpt_totals($rows, array_merge(['water', 'one_time', 'installment', 'recurring'], $moneyKeys), 'account');
            $chart = null;
        }
        return ['columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'chart' => $chart,
            'notes' => 'Open balances today, from the water records (bills less what was paid on them), aged by the bill\'s due date. "Open in the ledger" is the open balance of the invoices those bills posted; '
                . 'penalty and compromise are charged only when collected, so they never stay open. A difference means a bill or a collection reached one side only: see the Water Posting Log & Exceptions report.'];
    },
]);

// ============================================================================ 4. Payment History (Ledger)

Report::define('water_payment_history', [
    'filters' => ['range', 'search'],
    'default_dates' => 'this_month',
    'extra_filters' => [
        'kind' => ['Kind', fn() => ['collection' => 'Collections (payments)', 'collection_penalty' => 'Penalty charges', 'collection_compromise' => 'Compromise charges', 'collection_reversal' => 'Reversals', 'collection_refund' => 'Refunds']],
        'wstatus' => ['Posting status', fn() => ['posted' => 'Posted', 'voided' => 'Voided', 'replaced' => 'Replaced']],
        'wclass' => ['Class', fn() => wrpt_classes()],
    ],
    'run' => function (array $f): array {
        wrpt_ready();
        $sql = "SELECT w.*, t.txn_date, t.txn_no, t.total, t.is_voided, t.txn_type AS ttype, t.account_id, t.account2_id,
                       a.account_number, a.lastname, a.firstname, a.middlename, a.pkey, p.party_no, p.display_name, u.username,
                       pt.transaction_status, pt.cashier_id, COALESCE(NULLIF(cu.full_name, ''), cu.username) AS cashier
                  FROM water_postings w
                  LEFT JOIN transactions t ON t.id = w.txn_id
                  LEFT JOIN water_accounts a ON a.pkey = w.water_account_id
                  LEFT JOIN parties p ON p.id = w.party_id
                  LEFT JOIN users u ON u.id = w.created_by
                  LEFT JOIN payment_transactions pt ON pt.receipt_no = w.source_id
                  LEFT JOIN users cu ON cu.id = pt.cashier_id
                 WHERE w.source_type IN ('collection', 'collection_penalty', 'collection_compromise', 'collection_reversal', 'collection_refund')
                   AND COALESCE(t.txn_date, DATE(w.created_at)) BETWEEN :f AND :t";
        $p = ['f' => $f['from'], 't' => $f['to']];
        if ($f['kind'] !== '') {
            $sql .= ' AND w.source_type = :k';
            $p['k'] = $f['kind'];
        }
        if ($f['wstatus'] !== '') {
            $sql .= ' AND w.status = :s';
            $p['s'] = $f['wstatus'];
        }
        if ($f['wclass'] !== '') {
            $sql .= ' AND w.' . wrpt_class_col() . ' = :br';
            $p['br'] = (int)$f['wclass'];
        }
        $q = trim((string)$f['q']);
        if ($q !== '') {
            $sql .= ' AND (w.source_id LIKE :q1 OR w.doc_no LIKE :q2 OR a.account_number LIKE :q3 OR a.lastname LIKE :q4 OR a.firstname LIKE :q5 OR p.display_name LIKE :q6)';
            $p += ['q1' => '%' . $q . '%', 'q2' => '%' . $q . '%', 'q3' => $q . '%', 'q4' => $q . '%', 'q5' => $q . '%', 'q6' => '%' . $q . '%'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY COALESCE(t.txn_date, DATE(w.created_at)), w.id LIMIT 20000', $p) as $r) {
            $cash = $r['ttype'] === 'payment' && $r['account_id'] ? (string)DB::value("SELECT CONCAT(code, ' ', name) FROM accounts WHERE id = :a", ['a' => $r['account_id']]) : '';
            $rows[] = ['date' => $r['txn_date'] ?: substr((string)$r['created_at'], 0, 10), 'receipt' => (string)$r['source_id'], 'doc' => (string)($r['txn_no'] ?: $r['doc_no']), 'kind' => wrpt_source_label((string)$r['source_type']),
                'account' => $r['pkey'] ? wrpt_account_label($r) : '', 'party' => (string)$r['display_name'], 'party_no' => (string)$r['party_no'], 'cashier' => (string)$r['cashier'],
                'amount' => Dec::fix($r['amount']), 'status' => ucfirst((string)$r['status']) . ((int)$r['is_voided'] === 1 && $r['status'] === 'posted' ? ' (document voided)' : ''),
                'cash' => $cash, 'reason' => (string)$r['reason'], 'by' => (string)$r['username'], 'rstatus' => $r['transaction_status'] ? ucfirst((string)$r['transaction_status']) : '',
                '_links' => ['receipt' => wrpt_receipt_link((string)$r['source_id']), 'doc' => $r['txn_id'] && $r['ttype'] ? Report::txnLink(['txn_type' => $r['ttype'], 'txn_id' => $r['txn_id']]) : null,
                    'account' => $r['account_number'] ? wrpt_statement_link((string)$r['account_number']) : null, 'party' => $r['party_id'] ? Report::link('party.center', ['id' => $r['party_id']]) : null]];
        }
        return ['columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'receipt', 'label' => 'Receipt no.'], ['key' => 'doc', 'label' => 'Ledger document'], ['key' => 'kind', 'label' => 'Kind'],
            ['key' => 'account', 'label' => 'Water-service account'], ['key' => 'party', 'label' => 'Party'], ['key' => 'party_no', 'label' => 'Party ID', 'optional' => true], ['key' => 'cashier', 'label' => 'Cashier'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'], ['key' => 'status', 'label' => 'Posting'], ['key' => 'rstatus', 'label' => 'Receipt status', 'optional' => true],
            ['key' => 'cash', 'label' => 'Cash account', 'optional' => true], ['key' => 'reason', 'label' => 'Reason', 'optional' => true], ['key' => 'by', 'label' => 'Posted by', 'optional' => true]],
            'rows' => $rows, 'totals' => wrpt_totals(array_filter($rows, fn($r) => $r['status'] === 'Posted' && !str_contains($r['kind'], 'reversal') && !str_contains($r['kind'], 'refund')), ['amount'], 'date', 'TOTAL POSTED'),
            'notes' => 'The ledger documents made from the water official receipts: one payment per receipt (a second one when bills of different A/R accounts were paid together), a finance charge for a penalty or '
                . 'compromise collected with it, and the reversal or refund that voided them. The total counts the posted payments and charges only.'];
    },
]);

// ============================================================================ 5. Water Service Account Statement

Report::define('water_account_statement', [
    'filters' => ['range', 'search'],
    'filter_labels' => [],
    'default_dates' => 'this_year',
    'sortable' => false,
    'run' => function (array $f): array {
        wrpt_ready();
        $q = trim((string)$f['q']);
        $columns = [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'doc', 'label' => 'Document'], ['key' => 'desc', 'label' => 'Description'],
            ['key' => 'debit', 'label' => 'Billed', 'type' => 'money'], ['key' => 'credit', 'label' => 'Paid / credited', 'type' => 'money'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money'], ['key' => 'status', 'label' => 'Status']];
        if ($q === '') {
            return ['grouped' => true, 'columns' => $columns, 'rows' => [], 'notes' => 'Type the account number (or part of the name) in the Search box, then Refresh.'];
        }
        $p = ['q1' => $q, 'q2' => $q . '%', 'q3' => $q . '%', 'q4' => '%' . $q . '%', 'q5' => $q];
        $matches = DB::all("SELECT a.*, rc.rc_name, p.party_no, p.display_name FROM water_accounts a LEFT JOIN rate_code rc ON rc.rc_id = a.rc_id LEFT JOIN parties p ON p.id = a.party_id
                             WHERE a.account_number = :q1 OR a.account_number LIKE :q2 OR a.lastname LIKE :q3 OR CONCAT_WS(', ', NULLIF(a.lastname, ''), NULLIF(a.firstname, '')) LIKE :q4 OR CAST(a.pkey AS CHAR) = :q5
                             ORDER BY a.account_number = :q6 DESC, a.account_number LIMIT 200", $p + ['q6' => $q]);
        if (!$matches) {
            return ['grouped' => true, 'columns' => $columns, 'rows' => [], 'notes' => 'No water-service account matches "' . h($q) . '".'];
        }
        $exact = array_values(array_filter($matches, fn($m) => (string)$m['account_number'] === $q || (string)$m['pkey'] === $q));
        if (count($matches) > 1 && !$exact) {
            // several accounts: list them, each leading to its own statement
            $rows = [];
            foreach ($matches as $m) {
                $bal = WaterAccounts::openBalance((int)$m['pkey']);
                $rows[] = ['date' => '', 'doc' => (string)($m['account_number'] ?: 'W-' . $m['pkey']), 'desc' => WaterAccounts::listName($m) . ($m['party_no'] ? ' (party ' . $m['party_no'] . ')' : ''),
                    'debit' => '', 'credit' => '', 'balance' => Dec::fix($bal['total']), 'status' => strtoupper((string)$m['status']) === 'A' ? 'Active' : 'Inactive',
                    '_links' => ['doc' => Report::link('rpt.water_account_statement', ['q' => (string)($m['account_number'] ?: $m['pkey']), 'from' => $f['from'], 'to' => $f['to'], 'dates' => 'custom'])]];
            }
            return ['grouped' => true, 'columns' => $columns, 'rows' => $rows, 'notes' => count($matches) . ' accounts match "' . h($q) . '". Choose one; the Balance column is its open balance today.'];
        }
        $a = $exact[0] ?? $matches[0];
        $id = (int)$a['pkey'];
        $from = $f['from'];
        $to = $f['to'];
        // every bill of the account (debits) and every settlement (credits), from the water records
        $bills = [];
        foreach (DB::all("SELECT b.reading_id AS id, b.billing_date AS d, b.global_ref_number AS ref, b.amount_due AS amt, bp.bp_code, b.due_date, b.consumption, b.is_paid
                            FROM bill_readings b LEFT JOIN bill_periods bp ON bp.period_id = b.period_id
                           WHERE b.member_id = :m AND b.is_billed = 1 AND COALESCE(b.bill_lifecycle_status, 'active') = 'active'", ['m' => $id]) as $b) {
            $bills[] = ['date' => (string)$b['d'], 'doc' => WaterPosting::docNo('water', (int)$b['id'], $b['ref']), 'desc' => 'Water bill' . ($b['bp_code'] ? ' for period ' . $b['bp_code'] : '') . ((int)$b['consumption'] > 0 ? ', ' . (int)$b['consumption'] . ' m³' : '') . ($b['due_date'] ? ', due ' . fdate($b['due_date']) : ''),
                'amount' => (string)$b['amt'], 'src' => 'water', 'sid' => (int)$b['id']];
        }
        foreach (DB::all("SELECT b.bill_id AS id, b.bill_date AS d, b.global_ref_number AS ref, b.total_amount AS amt, b.due_date, bc.code FROM one_time_bills b LEFT JOIN bill_codes bc ON bc.code_id = b.bill_code_id
                           WHERE b.member_id = :m AND b.status IN ('unpaid', 'paid') AND COALESCE(b.bill_lifecycle_status, 'active') = 'active'", ['m' => $id]) as $b) {
            $bills[] = ['date' => (string)$b['d'], 'doc' => WaterPosting::docNo('one_time', (int)$b['id'], $b['ref']), 'desc' => 'One-time bill' . ($b['code'] ? ': ' . $b['code'] : '') . ($b['due_date'] ? ', due ' . fdate($b['due_date']) : ''),
                'amount' => (string)$b['amt'], 'src' => 'one_time', 'sid' => (int)$b['id']];
        }
        foreach (DB::all("SELECT s.schedule_id, s.due_date AS d, s.amount AS amt, b.installment_id, b.global_ref_number AS ref, bc.code FROM installment_schedules s JOIN installment_bills b ON b.installment_id = s.installment_id
                           LEFT JOIN bill_codes bc ON bc.code_id = b.bill_code_id
                           WHERE b.member_id = :m AND b.status IN ('active', 'completed') AND COALESCE(b.bill_lifecycle_status, 'active') = 'active'", ['m' => $id]) as $b) {
            $bills[] = ['date' => (string)$b['d'], 'doc' => WaterPosting::docNo('installment', (int)$b['installment_id'], $b['ref']), 'desc' => 'Installment due' . ($b['code'] ? ': ' . $b['code'] : ''),
                'amount' => (string)$b['amt'], 'src' => 'installment', 'sid' => (int)$b['installment_id']];
        }
        foreach (DB::all("SELECT b.bill_id AS id, b.bill_date AS d, b.global_ref_number AS ref, b.total_amount AS amt, b.due_date, bc.code FROM recurring_bills b LEFT JOIN bill_codes bc ON bc.code_id = b.bill_code_id
                           WHERE b.member_id = :m AND b.status IN ('unpaid', 'paid', 'overdue') AND COALESCE(b.bill_lifecycle_status, 'active') = 'active'", ['m' => $id]) as $b) {
            $bills[] = ['date' => (string)$b['d'], 'doc' => WaterPosting::docNo('recurring', (int)$b['id'], $b['ref']), 'desc' => 'Recurring bill' . ($b['code'] ? ': ' . $b['code'] : '') . ($b['due_date'] ? ', due ' . fdate($b['due_date']) : ''),
                'amount' => (string)$b['amt'], 'src' => 'recurring', 'sid' => (int)$b['id']];
        }
        $credits = [];
        foreach (DB::all("SELECT pt.receipt_no, COALESCE(pt.business_date, DATE(pt.payment_date)) AS d, i.source_type, i.source_id, i.principal_amount, i.penalty_amount, i.description, pt.transaction_status
                            FROM payment_transactions pt JOIN payment_transaction_items i ON i.transaction_id = pt.transaction_id
                           WHERE pt.member_id = :m AND pt.transaction_status = 'active' AND i.allocation_status = 'active'
                             AND i.source_type <> 'sharecap'", ['m' => $id]) as $c) {
            $credits[] = ['date' => (string)$c['d'], 'doc' => 'OR ' . $c['receipt_no'], 'desc' => 'Collection' . ($c['description'] ? ': ' . $c['description'] : '') . ((float)$c['penalty_amount'] > 0 ? ' (penalty / compromise ' . money($c['penalty_amount']) . ' collected with it)' : ''),
                'amount' => (string)$c['principal_amount'], 'receipt' => (string)$c['receipt_no']];
        }
        $opening = '0';
        foreach ($bills as $b) {
            if ($b['date'] < $from) {
                $opening = Dec::add($opening, $b['amount'], 2);
            }
        }
        foreach ($credits as $c) {
            if ($c['date'] < $from) {
                $opening = Dec::sub($opening, $c['amount'], 2);
            }
        }
        $lines = [];
        foreach ($bills as $b) {
            if ($b['date'] >= $from && $b['date'] <= $to) {
                $txnId = WaterPosting::billTxnId($b['src'], $b['sid']);
                $lines[] = ['date' => $b['date'], 'doc' => $b['doc'], 'desc' => $b['desc'], 'debit' => Dec::fix($b['amount']), 'credit' => '', 'status' => $txnId ? 'Posted' : 'Not in the ledger',
                    '_links' => ['doc' => $txnId ? Report::txnLink(['txn_type' => 'invoice', 'txn_id' => $txnId]) : null], '_k' => 0];
            }
        }
        foreach ($credits as $c) {
            if ($c['date'] >= $from && $c['date'] <= $to) {
                $lines[] = ['date' => $c['date'], 'doc' => $c['doc'], 'desc' => $c['desc'], 'debit' => '', 'credit' => Dec::fix($c['amount']), 'status' => '',
                    '_links' => ['doc' => wrpt_receipt_link($c['receipt'])], '_k' => 1];
            }
        }
        usort($lines, fn($x, $y) => [$x['date'], $x['_k'], $x['doc']] <=> [$y['date'], $y['_k'], $y['doc']]);
        $rows = [];
        $rows[] = ['date' => '', 'doc' => 'Water-service account ' . ($a['account_number'] ?: 'W-' . $id) . ', ' . WaterAccounts::listName($a), '_class' => 'group-row'];
        $rows[] = ['date' => $from, 'doc' => '', 'desc' => 'Opening balance', 'debit' => '', 'credit' => '', 'balance' => Dec::fix($opening), 'status' => '', '_class' => 'subtotal-row'];
        $bal = $opening;
        $deb = '0';
        $cre = '0';
        foreach ($lines as $l) {
            $bal = Dec::add(Dec::sub($bal, $l['credit'] === '' ? '0' : $l['credit'], 2), $l['debit'] === '' ? '0' : $l['debit'], 2);
            $deb = Dec::add($deb, $l['debit'] === '' ? '0' : $l['debit'], 2);
            $cre = Dec::add($cre, $l['credit'] === '' ? '0' : $l['credit'], 2);
            unset($l['_k']);
            $l['balance'] = Dec::fix($bal);
            $rows[] = $l;
        }
        if (!$lines) {
            $rows[] = ['date' => '', 'doc' => '', 'desc' => 'No bills or collections in the period.', 'debit' => '', 'credit' => '', 'balance' => '', 'status' => ''];
        }
        $rows[] = ['date' => $to, 'doc' => '', 'desc' => 'Closing balance', 'debit' => Dec::fix($deb), 'credit' => Dec::fix($cre), 'balance' => Dec::fix($bal), 'status' => '', '_class' => 'total-row'];
        $open = WaterAccounts::openBalance($id);
        $ledgerBal = WaterAccounts::ledgerBalance($id);
        $rows[] = ['date' => '', 'doc' => 'Open balance today: water bills ' . money($open['water']) . ', one-time ' . money($open['one_time']) . ', installment ' . money($open['installment']) . ', recurring ' . money($open['recurring'])
            . ', total ' . money($open['total']) . '. In the ledger: ' . money($ledgerBal) . '.', '_class' => 'group-row'];
        // the ledger documents of the account in the period
        $rows[] = ['date' => '', 'doc' => 'Ledger documents of the account (' . fdate($from) . ' to ' . fdate($to) . ')', '_class' => 'group-row'];
        $docs = DB::all("SELECT w.*, t.txn_date, t.txn_no, t.total, t.is_voided, t.txn_type AS ttype FROM water_postings w LEFT JOIN transactions t ON t.id = w.txn_id
                          WHERE w.water_account_id = :m AND COALESCE(t.txn_date, DATE(w.created_at)) BETWEEN :f AND :t ORDER BY COALESCE(t.txn_date, DATE(w.created_at)), w.id", ['m' => $id, 'f' => $from, 't' => $to]);
        foreach ($docs as $d) {
            $debitSide = in_array($d['ttype'], ['invoice', 'finance_charge'], true);
            $rows[] = ['date' => $d['txn_date'] ?: substr((string)$d['created_at'], 0, 10), 'doc' => ($d['ttype'] ? Txn::label((string)$d['ttype']) . ' ' : '') . ($d['txn_no'] ?: $d['doc_no']),
                'desc' => wrpt_source_label((string)$d['source_type']) . ' ' . $d['source_id'] . ($d['reason'] ? '. ' . $d['reason'] : ''),
                'debit' => $debitSide ? Dec::fix($d['amount']) : '', 'credit' => !$debitSide && $d['ttype'] !== 'void' ? Dec::fix($d['amount']) : '', 'balance' => '',
                'status' => ucfirst((string)$d['status']) . ((int)$d['is_voided'] === 1 ? ' (document voided)' : ''), '_class' => 'indent-1',
                '_links' => ['doc' => $d['txn_id'] && $d['ttype'] && $d['ttype'] !== 'void' ? Report::txnLink(['txn_type' => $d['ttype'], 'txn_id' => $d['txn_id']]) : null]];
        }
        if (!$docs) {
            $rows[] = ['date' => '', 'doc' => '', 'desc' => 'No ledger document in the period.', '_class' => 'indent-1'];
        }
        $party = $a['party_id'] ? 'Party ' . $a['party_no'] . ' ' . $a['display_name'] : 'No party linked';
        return ['grouped' => true, 'columns' => $columns, 'rows' => $rows,
            'notes' => '<strong>' . h(($a['account_number'] ?: 'W-' . $id) . ' ' . WaterAccounts::listName($a)) . '</strong>. ' . ($a['party_id'] && Access::page('party.center') ? '<a href="' . h(url('party.center', ['id' => $a['party_id']])) . '">' . h($party) . '</a>' : h($party))
                . ($a['rc_name'] ? '. Rate code ' . h($a['rc_name']) : '') . ($a['zone'] ? ', zone ' . h((string)$a['zone']) : '') . ', ' . h(ucfirst((string)$a['connection_status'])) . '. '
                . (Access::page('water.customer_ledger') ? '<a href="' . h(url('water.customer_ledger', ['member_id' => $id])) . '">Water customer ledger</a>. ' : '')
                . 'Bills and collections are from the water records (installments by schedule due date; a collection credits the bill part, the penalty or compromise collected with it is shown in the description). '
                . 'The ledger documents are the invoices, payments, finance charges and credit memos the postings made.'];
    },
]);

// ============================================================================ 6. Water Posting Log & Exceptions

Report::define('water_posting_log', [
    'filters' => ['range', 'search'],
    'default_dates' => 'this_month',
    'sortable' => false,
    'extra_filters' => [
        'stype' => ['Source', fn() => ['water_bill' => 'Water bills', 'one_time_bill' => 'One-time bills', 'installment_bill' => 'Installment bills', 'recurring_bill' => 'Recurring bills',
            'collection' => 'Collections', 'collection_penalty' => 'Penalty charges', 'collection_compromise' => 'Compromise charges', 'collection_reversal' => 'Reversals', 'collection_refund' => 'Refunds', 'issuance' => 'Material issuances']],
        'wstatus' => ['Posting status', fn() => ['posted' => 'Posted', 'voided' => 'Voided', 'replaced' => 'Replaced']],
        'exc' => ['Exceptions', fn() => ['1' => 'Show the exceptions', '0' => 'Log only'], null],
    ],
    'run' => function (array $f): array {
        wrpt_ready();
        $sql = "SELECT w.*, t.txn_date, t.txn_no, t.total, t.is_voided, t.txn_type AS ttype, a.account_number, a.lastname, a.firstname, a.middlename, a.pkey, p.party_no, p.display_name, u.username
                  FROM water_postings w LEFT JOIN transactions t ON t.id = w.txn_id LEFT JOIN water_accounts a ON a.pkey = w.water_account_id LEFT JOIN parties p ON p.id = w.party_id LEFT JOIN users u ON u.id = w.created_by
                 WHERE DATE(w.created_at) BETWEEN :f AND :t";
        $p = ['f' => $f['from'], 't' => $f['to']];
        if ($f['stype'] !== '') {
            $sql .= ' AND w.source_type = :s';
            $p['s'] = $f['stype'];
        }
        if ($f['wstatus'] !== '') {
            $sql .= ' AND w.status = :ws';
            $p['ws'] = $f['wstatus'];
        }
        $q = trim((string)$f['q']);
        if ($q !== '') {
            $sql .= ' AND (w.event_key LIKE :q1 OR w.doc_no LIKE :q2 OR w.source_id LIKE :q3 OR a.account_number LIKE :q4 OR a.lastname LIKE :q5 OR p.display_name LIKE :q6)';
            $p += ['q1' => '%' . $q . '%', 'q2' => '%' . $q . '%', 'q3' => '%' . $q . '%', 'q4' => $q . '%', 'q5' => $q . '%', 'q6' => '%' . $q . '%'];
        }
        $rows = [['when' => 'Postings made ' . fdate($f['from']) . ' to ' . fdate($f['to']), '_class' => 'group-row']];
        $n = 0;
        foreach (DB::all($sql . ' ORDER BY w.id LIMIT 20000', $p) as $r) {
            $n++;
            $rows[] = ['when' => fdatetime($r['created_at']), 'event' => (string)$r['event_key'], 'source' => wrpt_source_label((string)$r['source_type']), 'sid' => (string)$r['source_id'],
                'doc' => ($r['ttype'] && $r['ttype'] !== 'void' ? Txn::label((string)$r['ttype']) . ' ' : '') . ($r['txn_no'] ?: $r['doc_no']), 'date' => (string)$r['txn_date'],
                'account' => $r['pkey'] ? wrpt_account_label($r) : '', 'party' => (string)$r['display_name'], 'amount' => Dec::fix($r['amount']),
                'status' => ucfirst((string)$r['status']) . ((int)$r['is_voided'] === 1 && $r['status'] === 'posted' ? ' (document voided)' : ''), 'reason' => (string)$r['reason'], 'by' => (string)$r['username'],
                '_links' => ['doc' => $r['txn_id'] && $r['ttype'] && $r['ttype'] !== 'void' ? Report::txnLink(['txn_type' => $r['ttype'], 'txn_id' => $r['txn_id']]) : null,
                    'account' => $r['account_number'] ? wrpt_statement_link((string)$r['account_number']) : null, 'party' => $r['party_id'] ? Report::link('party.center', ['id' => $r['party_id']]) : null]];
        }
        if ($n === 0) {
            $rows[] = ['when' => '', 'event' => 'No posting in the period.'];
        }
        $exceptions = 0;
        if ($f['exc'] !== '0') {
            $rows[] = ['when' => 'Exceptions (as of today, every date)', '_class' => 'group-row'];
            // (a) bills that are posted on the water side but have no live ledger invoice
            $missing = [
                'water' => DB::all("SELECT b.reading_id AS id, b.member_id, b.billing_date AS d, b.amount_due AS amt, b.global_ref_number AS ref FROM bill_readings b
                                     WHERE b.is_billed = 1 AND COALESCE(b.bill_lifecycle_status, 'active') = 'active' AND b.amount_due > 0.004
                                       AND NOT EXISTS (SELECT 1 FROM water_postings w JOIN transactions t ON t.id = w.txn_id WHERE w.source_type = 'water_bill' AND w.source_id = b.reading_id AND w.status = 'posted' AND t.is_voided = 0 AND t.txn_type = 'invoice') LIMIT 500"),
                'one_time' => DB::all("SELECT b.bill_id AS id, b.member_id, b.bill_date AS d, b.total_amount AS amt, b.global_ref_number AS ref FROM one_time_bills b
                                     WHERE b.status IN ('unpaid', 'paid') AND COALESCE(b.bill_lifecycle_status, 'active') = 'active' AND b.total_amount > 0.004
                                       AND NOT EXISTS (SELECT 1 FROM water_postings w JOIN transactions t ON t.id = w.txn_id WHERE w.source_type = 'one_time_bill' AND w.source_id = b.bill_id AND w.status = 'posted' AND t.is_voided = 0 AND t.txn_type = 'invoice') LIMIT 500"),
                'installment' => DB::all("SELECT b.installment_id AS id, b.member_id, b.start_date AS d, b.total_amount AS amt, b.global_ref_number AS ref FROM installment_bills b
                                     WHERE b.status IN ('active', 'completed') AND COALESCE(b.bill_lifecycle_status, 'active') = 'active' AND b.total_amount > 0.004
                                       AND NOT EXISTS (SELECT 1 FROM water_postings w JOIN transactions t ON t.id = w.txn_id WHERE w.source_type = 'installment_bill' AND w.source_id = b.installment_id AND w.status = 'posted' AND t.is_voided = 0 AND t.txn_type = 'invoice') LIMIT 500"),
                'recurring' => DB::all("SELECT b.bill_id AS id, b.member_id, b.bill_date AS d, b.total_amount AS amt, b.global_ref_number AS ref FROM recurring_bills b
                                     WHERE b.status IN ('unpaid', 'paid', 'overdue') AND COALESCE(b.bill_lifecycle_status, 'active') = 'active' AND b.total_amount > 0.004
                                       AND NOT EXISTS (SELECT 1 FROM water_postings w JOIN transactions t ON t.id = w.txn_id WHERE w.source_type = 'recurring_bill' AND w.source_id = b.bill_id AND w.status = 'posted' AND t.is_voided = 0 AND t.txn_type = 'invoice') LIMIT 500"),
            ];
            $acctCache = [];
            $acctLabel = function (int $memberId) use (&$acctCache): string {
                if (!isset($acctCache[$memberId])) {
                    $a = WaterAccounts::get($memberId);
                    $acctCache[$memberId] = $a ? wrpt_account_label($a) : 'Account #' . $memberId;
                }
                return $acctCache[$memberId];
            };
            foreach ($missing as $src => $list) {
                foreach ($list as $b) {
                    $exceptions++;
                    $rows[] = ['when' => 'Bill without a ledger document', 'event' => WaterPosting::eventKey($src, (int)$b['id']), 'source' => WRPT_SOURCES[$src][0], 'sid' => (string)$b['id'],
                        'doc' => WaterPosting::docNo($src, (int)$b['id'], $b['ref']), 'date' => (string)$b['d'], 'account' => $acctLabel((int)$b['member_id']), 'party' => '', 'amount' => Dec::fix($b['amt']),
                        'status' => 'Not posted', 'reason' => 'The bill is in the water records but has no live invoice in the ledger (the posting failed or was voided without a replacement).', 'by' => '', '_class' => 'indent-1'];
                }
            }
            // (b) active receipts with no posting (a receipt of share capital only has none: its deposits are savings
            // documents, Fixed Share Capital Billing)
            $waterPart = wrpt_share_capital_ready() ? 'pt.total_paid - pt.share_capital_amount' : 'pt.total_paid';
            foreach (DB::all("SELECT pt.receipt_no, pt.member_id, COALESCE(pt.business_date, DATE(pt.payment_date)) AS d, pt.total_paid FROM payment_transactions pt
                               WHERE pt.transaction_status = 'active' AND $waterPart > 0.004
                                 AND NOT EXISTS (SELECT 1 FROM water_postings w WHERE w.source_type = 'collection' AND w.source_id = pt.receipt_no AND w.status = 'posted') LIMIT 500") as $r) {
                $exceptions++;
                $rows[] = ['when' => 'Receipt without a ledger document', 'event' => WaterPosting::collectionKey((string)$r['receipt_no']), 'source' => 'Collection (OR)', 'sid' => (string)$r['receipt_no'], 'doc' => (string)$r['receipt_no'],
                    'date' => (string)$r['d'], 'account' => $acctLabel((int)$r['member_id']), 'party' => '', 'amount' => Dec::fix($r['total_paid']), 'status' => 'Not posted',
                    'reason' => 'The official receipt is active in the water records but no payment was posted to the ledger.', 'by' => '', '_class' => 'indent-1', '_links' => ['doc' => wrpt_receipt_link((string)$r['receipt_no'])]];
            }
            // (c) postings still "posted" whose ledger document was voided outside the water module
            foreach (DB::all("SELECT w.*, t.txn_no, t.txn_date, t.txn_type AS ttype, t.void_reason FROM water_postings w JOIN transactions t ON t.id = w.txn_id WHERE w.status = 'posted' AND t.is_voided = 1 LIMIT 500") as $r) {
                $exceptions++;
                $rows[] = ['when' => 'Ledger document voided, posting still live', 'event' => (string)$r['event_key'], 'source' => wrpt_source_label((string)$r['source_type']), 'sid' => (string)$r['source_id'],
                    'doc' => Txn::label((string)$r['ttype']) . ' ' . ($r['txn_no'] ?: $r['doc_no']), 'date' => (string)$r['txn_date'], 'account' => $r['water_account_id'] ? $acctLabel((int)$r['water_account_id']) : '', 'party' => '',
                    'amount' => Dec::fix($r['amount']), 'status' => 'Posted (document voided)', 'reason' => 'The document was voided on the ledger side; the water record still counts it. ' . (string)($r['void_reason'] ?? ''), 'by' => '', '_class' => 'indent-1',
                    '_links' => ['doc' => Report::txnLink(['txn_type' => $r['ttype'], 'txn_id' => $r['txn_id']])]];
            }
            // (d) receipts reversed on the water side whose postings are still live
            foreach (DB::all("SELECT pt.receipt_no, pt.member_id, COALESCE(pt.business_date, DATE(pt.payment_date)) AS d, pt.total_paid, pt.transaction_status FROM payment_transactions pt
                               WHERE pt.transaction_status IN ('reversed', 'refunded')
                                 AND EXISTS (SELECT 1 FROM water_postings w JOIN transactions t ON t.id = w.txn_id WHERE w.source_type = 'collection' AND w.source_id = pt.receipt_no AND w.status = 'posted' AND t.is_voided = 0) LIMIT 500") as $r) {
                $exceptions++;
                $rows[] = ['when' => 'Receipt ' . $r['transaction_status'] . ', payment still posted', 'event' => WaterPosting::collectionKey((string)$r['receipt_no']), 'source' => 'Collection (OR)', 'sid' => (string)$r['receipt_no'], 'doc' => (string)$r['receipt_no'],
                    'date' => (string)$r['d'], 'account' => $acctLabel((int)$r['member_id']), 'party' => '', 'amount' => Dec::fix($r['total_paid']), 'status' => 'Posted',
                    'reason' => 'The receipt was ' . $r['transaction_status'] . ' in the water records but its ledger payment was not voided.', 'by' => '', '_class' => 'indent-1', '_links' => ['doc' => wrpt_receipt_link((string)$r['receipt_no'])]];
            }
            // (e) the account mapping
            foreach (WaterPosting::mappingProblems() as $m) {
                $exceptions++;
                $rows[] = ['when' => 'Account mapping', 'event' => '', 'source' => $m['what'], 'sid' => '', 'doc' => '', 'date' => '', 'account' => '', 'party' => '', 'amount' => '', 'status' => 'Problem', 'reason' => ucfirst($m['problem']), 'by' => '', '_class' => 'indent-1'];
            }
            if ($exceptions === 0) {
                $rows[] = ['when' => '', 'event' => 'No exception: every posted bill and active receipt has its ledger document, no live posting points to a voided document, and the accounts are mapped.', '_class' => 'indent-1'];
            }
        }
        return ['grouped' => true, 'columns' => [['key' => 'when', 'label' => 'Recorded / exception'], ['key' => 'event', 'label' => 'Event key'], ['key' => 'source', 'label' => 'Source'], ['key' => 'sid', 'label' => 'Source id', 'optional' => true],
            ['key' => 'doc', 'label' => 'Ledger document'], ['key' => 'date', 'label' => 'Document date', 'type' => 'date'], ['key' => 'account', 'label' => 'Water-service account'], ['key' => 'party', 'label' => 'Party', 'optional' => true],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'], ['key' => 'status', 'label' => 'Status'], ['key' => 'reason', 'label' => 'Reason / what to do'], ['key' => 'by', 'label' => 'By', 'optional' => true]],
            'rows' => $rows,
            'notes' => $n . ' posting' . ($n === 1 ? '' : 's') . ' in the period' . ($f['exc'] !== '0' ? ', ' . $exceptions . ' exception' . ($exceptions === 1 ? '' : 's') . ' as of today' : '') . '. '
                . 'Every water event with an accounting effect is one row of water_postings (its event key is the idempotency key: a retry finds it instead of posting twice). '
                . 'An exception is a water record and its ledger document that do not agree; the water pages post the missing document when the bill or receipt is opened again, or the posting is made from Billing Adjustments / Collection Corrections.'];
    },
]);

// ============================================================================ 7. Account Mapping Validation

Report::define('water_mapping_check', [
    'filters' => [],
    'sortable' => false,
    'basis' => fn(array $f): string => 'As of ' . fdate(today()),
    'run' => function (array $f): array {
        wrpt_ready();
        $problems = [];
        foreach (WaterPosting::mappingProblems() as $m) {
            $problems[$m['what']][] = ucfirst($m['problem']);
        }
        $acct = function (?int $id): array {
            if (!$id) {
                return ['', '', ''];
            }
            $a = DB::row('SELECT code, name, account_type, is_active FROM accounts WHERE id = :id', ['id' => $id]);
            if (!$a) {
                return ['#' . $id, '', 'missing'];
            }
            return [$a['code'] . ' ' . $a['name'], (string)$a['account_type'], (int)$a['is_active'] === 1 ? 'Active' : 'Inactive'];
        };
        $rows = [];
        $line = function (string $what, ?int $id, string $use = '', ?string $problemKey = null, string $extra = '') use (&$rows, $acct, $problems) {
            [$label, $type, $active] = $acct($id);
            $prob = $problems[$problemKey ?? $what] ?? [];
            if (!$prob && $id && $active === 'missing') {
                $prob = ['Account does not exist'];
            }
            if (!$prob && $id && $active === 'Inactive') {
                $prob = ['Account is inactive'];
            }
            $rows[] = ['what' => $what, 'use' => $use, 'account' => $label !== '' ? $label : ($extra !== '' ? $extra : 'not set'), 'type' => $type, 'active' => $active === 'missing' ? '' : $active,
                'ok' => $prob ? 'Problem' : ($id || $extra !== '' ? 'OK' : 'Not set'), 'problem' => implode('; ', $prob), '_class' => 'indent-1',
                '_links' => ['account' => $id && Access::page('coa.accounts') ? url('coa.accounts', ['a' => 'edit', 'id' => $id]) : null]];
        };
        // the bill codes
        $rows[] = ['what' => 'Bill codes (Water Billing > Bill Codes)', '_class' => 'group-row'];
        foreach (DB::all('SELECT * FROM bill_codes ORDER BY code') as $bc) {
            $prefix = 'Bill code ' . $bc['code'];
            $line($prefix . ': A/R account', $bc['ar_account'] ? (int)$bc['ar_account'] : null, 'Debit of the bill (receivable)');
            $line($prefix . ': income account', $bc['income_account'] ? (int)$bc['income_account'] : null, 'Credit of the bill (revenue)');
            if (strtolower((string)$bc['type']) === 'inventory') {
                $item = $bc['item_id'] ? DB::row('SELECT code, name, is_active FROM items WHERE id = :i', ['i' => $bc['item_id']]) : null;
                $prob = $problems[$prefix] ?? [];
                $rows[] = ['what' => $prefix . ': inventory item', 'use' => 'Stock out and cost of the material sold', 'account' => $item ? $item['code'] . ' ' . $item['name'] : ($bc['item_id'] ? 'item #' . $bc['item_id'] : 'not set'),
                    'type' => 'Item', 'active' => $item ? ((int)$item['is_active'] === 1 ? 'Active' : 'Inactive') : '', 'ok' => $prob ? 'Problem' : ($item ? 'OK' : 'Not set'), 'problem' => implode('; ', $prob), '_class' => 'indent-1',
                    '_links' => ['account' => $bc['item_id'] && Access::page('inv.items') ? url('inv.items', ['a' => 'edit', 'id' => $bc['item_id']]) : null]];
                if ($bc['cogs_account']) {
                    $line($prefix . ': cost of goods sold (legacy)', (int)$bc['cogs_account'], 'Kept for reference; the item carries its own COGS account');
                }
                if ($bc['asset_account']) {
                    $line($prefix . ': inventory asset (legacy)', (int)$bc['asset_account'], 'Kept for reference; the item carries its own asset account');
                }
            }
        }
        // the collection policy
        $rows[] = ['what' => 'Collection policy (Water Billing > Collection Policy)', '_class' => 'group-row'];
        foreach (['collection_penalty_account_id' => ['Collection policy: penalty income', 'water_penalty', 'Finance charge for a penalty collected on the spot'], 'collection_compromise_account_id' => ['Collection policy: compromise income', 'water_compromise', 'Finance charge for a compromise collected on the spot']] as $k => [$what, $dkey, $use]) {
            $v = (int)(DB::value('SELECT setting_value FROM water_billing_settings WHERE setting_key = :k', ['k' => $k]) ?: 0);
            $fallback = $v ? null : WaterPosting::defaultAccount($dkey);
            $line($what, $v ?: $fallback, $use . ($fallback ? ' (from the water default account)' : ''));
        }
        // the water default accounts
        $rows[] = ['what' => 'Water default accounts (Financial > Module Default Accounts > Water Billing)', '_class' => 'group-row'];
        $uses = ['water_ar' => 'A/R when a bill code names none', 'water_income' => 'Income of a bill without a breakdown', 'water_penalty' => 'Penalty income when the collection policy names none',
            'water_compromise' => 'Compromise income when the collection policy names none', 'water_discount' => 'Discount granted shown on its own line (optional)', 'water_cash' => 'Cash account when the cashier has none',
            'water_legacy_clearing' => 'Clearing account of the migrated legacy balances'];
        foreach (DB::all("SELECT * FROM default_accounts WHERE module = 'water' ORDER BY FIELD(setting_key, 'water_ar', 'water_income', 'water_penalty', 'water_compromise', 'water_discount', 'water_cash', 'water_legacy_clearing'), label") as $d) {
            $line($d['label'], $d['account_id'] ? (int)$d['account_id'] : null, $uses[$d['setting_key']] ?? '', $d['setting_key'] === 'water_ar' ? 'Default water A/R account' : $d['label']);
        }
        if (!WaterPosting::defaultAccount('water_ar')) {
            $line('Default water A/R account', (int)(DB::value("SELECT account_id FROM default_accounts WHERE setting_key = 'ar'") ?: 0) ?: null, 'Falls back to the Customers A/R default');
        }
        // the cashiers
        $rows[] = ['what' => 'Cashiers\' cash accounts (System Administration > User Accounts)', '_class' => 'group-row'];
        $cashiers = DB::all("SELECT id, username, full_name, coop_cash_account_id FROM users WHERE status = 'active' AND is_platform = 0 ORDER BY username");
        $any = false;
        foreach ($cashiers as $u) {
            if (!$u['coop_cash_account_id']) {
                continue;
            }
            $any = true;
            $line('Cash account of ' . $u['username'], (int)$u['coop_cash_account_id'], ($u['full_name'] ?: $u['username']) . ' collects into it (teller and water cashier)');
        }
        if (!$any) {
            $rows[] = ['what' => 'No user has a cash account', 'use' => 'Every collection falls back to the water default cash account', 'account' => '', 'type' => '', 'active' => '', 'ok' => WaterPosting::defaultAccount('water_cash') ? 'OK' : 'Problem',
                'problem' => WaterPosting::defaultAccount('water_cash') ? '' : 'Set the cash account on each cashier or the water default cash account', '_class' => 'indent-1'];
        }
        // the line of business
        $rows[] = ['what' => 'Dimensions', '_class' => 'group-row'];
        $unit = WaterPosting::unitId();
        $rows[] = ['what' => 'Water Utility line of business', 'use' => 'Every water document carries it', 'account' => $unit && BusinessUnits::ready() ? BusinessUnits::name($unit) : 'not set', 'type' => 'Line of business', 'active' => '',
            'ok' => $unit ? 'OK' : 'Problem', 'problem' => implode('; ', $problems['Water Utility line of business'] ?? []), '_class' => 'indent-1'];
        foreach (DB::all('SELECT b.pkey, b.name, b.class_id, c.name AS class_name, c.is_active FROM branches b LEFT JOIN classes c ON c.id = b.class_id WHERE b.Deleted = 0 ORDER BY b.name') as $b) {
            $rows[] = ['what' => 'Water branch ' . $b['name'], 'use' => 'Class (branch) of its documents', 'account' => $b['class_name'] ?: 'made on the first posting', 'type' => 'Class', 'active' => $b['class_id'] ? ((int)$b['is_active'] === 1 ? 'Active' : 'Inactive') : '',
                'ok' => $b['class_id'] && (int)$b['is_active'] !== 1 ? 'Problem' : 'OK', 'problem' => $b['class_id'] && (int)$b['is_active'] !== 1 ? 'Class is inactive' : '', '_class' => 'indent-1'];
        }
        $bad = count(array_filter($rows, fn($r) => ($r['ok'] ?? '') === 'Problem'));
        return ['grouped' => true, 'columns' => [['key' => 'what', 'label' => 'Mapping'], ['key' => 'use', 'label' => 'Used for'], ['key' => 'account', 'label' => 'Account / item'], ['key' => 'type', 'label' => 'Type', 'optional' => true],
            ['key' => 'active', 'label' => 'Active'], ['key' => 'ok', 'label' => 'Check'], ['key' => 'problem', 'label' => 'Problem']],
            'rows' => $rows,
            'notes' => ($bad ? $bad . ' problem' . ($bad === 1 ? '' : 's') . ' to fix before posting. ' : 'Every mapping is in order. ')
                . 'A bill posts Dr the A/R account of its bill code, Cr its income account; a collection posts Dr the cashier\'s cash account, Cr A/R; penalty and compromise post to the collection policy accounts. '
                . 'Posting stops with a clear message when a mapped account is missing or inactive.'];
    },
]);
