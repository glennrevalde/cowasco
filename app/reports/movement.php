<?php
declare(strict_types=1);

/**
 * Inventory movement reports (v25):
 *   Inventory Movement Summary Report   (Inventory Reports)  beginning, in, out, ending per item
 *   Inventory Movement Detailed Report  (Inventory Reports)  the in and out split by kind of transaction
 *   Sales Report Through Inventory Movement (Sales Reports)  quantity sold = beginning + in - out - ending
 *   Inventory Movement Transactions     (hidden)             the source transactions behind every figure
 *
 * Every figure comes from the stock movements (inventory_movements) of transactions that are not voided, so the
 * beginning balance (everything before the From date) plus the movements of the period always gives the ending
 * balance (everything up to the To date). The site, class and item filters narrow the beginning and the period alike.
 */

/** The kinds of movement: key => [label, 'in' | 'out', what it covers] */
const INV_MOVE_KINDS = [
    'purchases'  => ['Purchases', 'in', 'Write Checks and Cash Vouchers with inventory (positive or negative), Receive Items, Receive Items and Enter Bills'],
    'trf_in'     => ['Transfer inventory', 'in', 'Inventory transfers into the site'],
    'adj_in'     => ['Adjust quantity', 'in', 'Inventory adjustments that add quantity (physical counts and opening quantities included)'],
    'build_in'   => ['Build assemblies', 'in', 'Build Assemblies: the assembled items'],
    'proc_in'    => ['Disassemble / process items', 'in', 'Disassemble / Process Items: the outputs'],
    'sales'      => ['Sales', 'out', 'Create Invoices, Create Batch Invoices, Enter Sales Receipts, POS sales and statement charges, less credit memos and POS returns that restock'],
    'trf_out'    => ['Transfer inventory', 'out', 'Inventory transfers out of the site'],
    'adj_out'    => ['Adjust quantity', 'out', 'Inventory adjustments that take quantity off'],
    'build_out'  => ['Build assemblies', 'out', 'Build Assemblies: the component items used'],
    'proc_out'   => ['Disassemble / process items', 'out', 'Disassemble / Process Items: the inputs consumed'],
    'debit_memo' => ['Supplier debit memo', 'out', 'Supplier debit memos (vendor credits) that return items'],
];

/** The movement groups a drill-down can ask for, besides one kind */
const INV_MOVE_GROUPS = [
    'beg'     => 'Beginning balance (before the From date)',
    'end'     => 'Ending balance (up to the To date)',
    'in'      => 'All IN',
    'out'     => 'All OUT',
    'in_ns'   => 'Delivery (IN, without sales documents)',
    'out_ns'  => 'Withdrawals (OUT, without sales documents)',
];

/** The SQL that sorts a movement (m) of a transaction (t) into its kind */
function inv_move_kind_sql(): string
{
    $proc = "'" . preg_replace('/\W/', '', Processing::TXN_TYPE) . "'";
    return "CASE WHEN t.txn_type IN ('bill','item_receipt','check','cash_voucher') THEN 'purchases'
                 WHEN t.txn_type IN ('invoice','statement_charge','sales_receipt','pos_sale','credit_memo','pos_return') THEN 'sales'
                 WHEN t.txn_type = 'vendor_credit' THEN 'debit_memo'
                 WHEN t.txn_type = 'inventory_transfer' THEN IF(m.qty < 0, 'trf_out', 'trf_in')
                 WHEN t.txn_type = 'build_assembly' THEN IF(m.qty < 0, 'build_out', 'build_in')
                 WHEN t.txn_type = $proc THEN IF(m.qty < 0, 'proc_out', 'proc_in')
                 ELSE IF(m.qty < 0, 'adj_out', 'adj_in') END";
}

/** The filters every movement query shares: site, class, item, category, the sites the user may see, the cashier */
function inv_move_where(array $f, array &$p): string
{
    $w = ' AND t.is_voided = 0 AND m.qty <> 0';
    if (!empty($f['site_id'])) {
        $w .= ' AND m.site_id = :mvs';
        $p['mvs'] = (int)$f['site_id'];
    }
    if (!empty($f['class_id']) && Classes::ready()) {
        if ((int)$f['class_id'] === Classes::UNCLASSIFIED) {
            $w .= ' AND COALESCE(l.class_id, t.class_id) IS NULL';
        } else {
            $w .= ' AND COALESCE(l.class_id, t.class_id) = :mvc';
            $p['mvc'] = (int)$f['class_id'];
        }
    }
    if (!empty($f['item_id'])) {
        $w .= ' AND m.item_id = :mvi';
        $p['mvi'] = (int)$f['item_id'];
    }
    $w .= Report::itemCond($f, 'm.item_id', $p, 'mvf');
    [$sf, $sp] = Access::siteFilter('m.site_id', 'mvst');
    $p += $sp;
    return $w . $sf;
}

const INV_MOVE_FROM = ' FROM inventory_movements m JOIN transactions t ON t.id = m.txn_id LEFT JOIN transaction_lines l ON l.id = m.txn_line_id ';

/**
 * item_id => ['beg' => qty, 'end' => qty, kind => qty (signed as in stock), ...] for the period, plus the
 * quantity and amount sold per cashier when one is asked for.
 */
function inv_move_data(array $f, ?int $cashier = null): array
{
    $out = [];
    $p = [];
    $where = inv_move_where($f, $p);
    foreach (DB::all('SELECT m.item_id, SUM(m.qty) AS q' . INV_MOVE_FROM . 'WHERE m.txn_date < :mvfrom' . $where . ' GROUP BY m.item_id', $p + ['mvfrom' => $f['from']]) as $r) {
        $out[(int)$r['item_id']]['beg'] = (float)$r['q'];
    }
    $kind = inv_move_kind_sql();
    // the period, by kind; the sales documents also give the amount sold (credit memos and returns take it back)
    $sql = "SELECT m.item_id, $kind AS kind, SUM(m.qty) AS q,
                   SUM(CASE WHEN t.txn_type IN ('credit_memo','pos_return') THEN -1 ELSE 1 END * COALESCE(l.amount, 0)) AS amt,
                   SUM(CASE WHEN t.created_by = :mvu THEN m.qty ELSE 0 END) AS q_user,
                   SUM(CASE WHEN t.created_by = :mvu2 THEN CASE WHEN t.txn_type IN ('credit_memo','pos_return') THEN -1 ELSE 1 END * COALESCE(l.amount, 0) ELSE 0 END) AS amt_user"
        . INV_MOVE_FROM . 'WHERE m.txn_date BETWEEN :mvfrom AND :mvto' . $where . ' GROUP BY m.item_id, kind';
    foreach (DB::all($sql, $p + ['mvfrom' => $f['from'], 'mvto' => $f['to'], 'mvu' => (int)$cashier, 'mvu2' => (int)$cashier]) as $r) {
        $id = (int)$r['item_id'];
        $out[$id][$r['kind']] = ($out[$id][$r['kind']] ?? 0.0) + (float)$r['q'];
        if ($r['kind'] === 'sales') {
            $out[$id]['sales_amt'] = (float)$r['amt'];
            $out[$id]['sales_q_user'] = (float)$r['q_user'];
            $out[$id]['sales_amt_user'] = (float)$r['amt_user'];
        }
    }
    foreach ($out as $id => &$d) {
        $d['beg'] ??= 0.0;
        $d['in'] = 0.0;
        $d['out'] = 0.0;
        foreach (INV_MOVE_KINDS as $k => [, $dir]) {
            $d[$k] ??= 0.0;
            $d[$dir] += $d[$k];
        }
        // OUT reads as a positive quantity taken off (sales net of returns, issues, transfers out)
        $d['out'] = -$d['out'];
        $d['end'] = $d['beg'] + $d['in'] - $d['out'];
    }
    unset($d);
    return $out;
}

/** The items of the movement data, in code order (inactive items that moved are shown too) */
function inv_move_items(array $data): array
{
    if (!$data) {
        return [];
    }
    [$in, $p] = DB::in('mvit', array_keys($data));
    return DB::all("SELECT id, code, name, unit, is_active FROM items WHERE id IN $in ORDER BY code", $p);
}

/** The drill-down to the source transactions of one figure (none for a zero: there is nothing behind it) */
function inv_move_link(array $f, ?int $itemId, string $move, ?int $cashier = null, ?float $value = null): ?string
{
    if ($value !== null && abs($value) < 0.00001) {
        return null;
    }
    $from = $f['from'];
    $to = $f['to'];
    if ($move === 'beg' || $move === 'end') {
        $first = (string)(DB::value('SELECT MIN(txn_date) FROM inventory_movements') ?: $from);
        if ($move === 'beg') {
            $to = date('Y-m-d', strtotime($from . ' -1 day'));
        }
        $from = min($first, $from);
        if ($to < $from) {
            $from = $to;
        }
    }
    return Report::link('rpt.inv_movement_txns', array_filter([
        'dates' => 'custom', 'from' => $from, 'to' => $to, 'move' => $move, 'item_id' => $itemId,
        'site_id' => $f['site_id'] ?? null, 'class_id' => $f['class_id'] ?? null, 'category_id' => $f['category_id'] ?? null,
        'cashier_id' => $cashier,
    ], fn($v) => $v !== null && $v !== '' && $v !== 0));
}

function inv_move_notes(array $f): string
{
    $n = 'Beginning balance: the quantity on hand at the end of the day before the From date. Ending balance: the quantity on hand at the end of the To date. '
       . 'IN and OUT are the transactions dated within the range; voided transactions are left out. Click any figure to see its source transactions.';
    if (!empty($f['site_id'])) {
        $n .= ' One site: transfers into it count as IN and transfers out of it as OUT.';
    } else {
        $n .= ' All sites: a transfer is IN at the site it went to and OUT at the site it left, so it does not change the total.';
    }
    if (!empty($f['class_id'])) {
        $n .= ' Class: the movements of transactions (or lines) of that class, the beginning balance included.';
    }
    return $n;
}

/** The users who entered sales documents (the Cashier filter) */
function inv_move_cashiers(): array
{
    return DB::pairs("SELECT u.id, COALESCE(NULLIF(u.full_name, ''), u.username) FROM users u
                       WHERE EXISTS (SELECT 1 FROM transactions t WHERE t.created_by = u.id
                                        AND t.txn_type IN ('invoice','sales_receipt','pos_sale','statement_charge','credit_memo','pos_return'))
                       ORDER BY 2");
}

Report::define('inv_movement_summary', [
    'filters' => ['range', 'site', 'class', 'item', 'category'],
    'default_dates' => 'this_month',
    'run' => function (array $f): array {
        $data = inv_move_data($f);
        $rows = [];
        $tot = ['item' => 'TOTAL', 'beg' => 0.0, 'in' => 0.0, 'out' => 0.0, 'end' => 0.0];
        foreach (inv_move_items($data) as $i) {
            $d = $data[(int)$i['id']];
            if (abs($d['beg']) < 0.00001 && abs($d['in']) < 0.00001 && abs($d['out']) < 0.00001 && abs($d['end']) < 0.00001) {
                continue;
            }
            $id = (int)$i['id'];
            $rows[] = [
                'item' => $i['code'], 'desc' => $i['name'], 'unit' => $i['unit'],
                'beg' => $d['beg'], 'in' => $d['in'], 'out' => $d['out'], 'end' => $d['end'],
                '_links' => ['item' => Report::link('inv.center', ['id' => $id]), 'beg' => inv_move_link($f, $id, 'beg', null, $d['beg']), 'in' => inv_move_link($f, $id, 'in', null, $d['in']),
                    'out' => inv_move_link($f, $id, 'out', null, $d['out']), 'end' => inv_move_link($f, $id, 'end', null, $d['end'])],
                '_ref' => ['item' => $id],
            ];
            foreach (['beg', 'in', 'out', 'end'] as $k) {
                $tot[$k] += $d[$k];
            }
        }
        $tot['_links'] = ['beg' => inv_move_link($f, null, 'beg', null, $tot['beg']), 'in' => inv_move_link($f, null, 'in', null, $tot['in']),
            'out' => inv_move_link($f, null, 'out', null, $tot['out']), 'end' => inv_move_link($f, null, 'end', null, $tot['end'])];
        return [
            'columns' => [['key' => 'item', 'label' => 'Item code'], ['key' => 'desc', 'label' => 'Item description'], ['key' => 'unit', 'label' => 'Unit', 'optional' => true],
                ['key' => 'beg', 'label' => 'Beg. balance', 'type' => 'qty'], ['key' => 'in', 'label' => 'In', 'type' => 'qty'],
                ['key' => 'out', 'label' => 'Out', 'type' => 'qty'], ['key' => 'end', 'label' => 'Ending balance', 'type' => 'qty']],
            'rows' => $rows,
            'totals' => $tot,
            'notes' => inv_move_notes($f) . ' Ending balance = Beg. balance + In - Out.',
            'footer' => '<div class="small text-muted"><strong>In:</strong> ' . h(implode('; ', array_map(fn($k) => INV_MOVE_KINDS[$k][2], ['purchases', 'trf_in', 'adj_in', 'build_in', 'proc_in'])))
                . '.<br><strong>Out:</strong> ' . h(implode('; ', array_map(fn($k) => INV_MOVE_KINDS[$k][2], ['sales', 'trf_out', 'adj_out', 'build_out', 'proc_out', 'debit_memo']))) . '.</div>',
        ];
    },
]);

Report::define('inv_movement_detail', [
    'filters' => ['range', 'site', 'class', 'item', 'category'],
    'default_dates' => 'this_month',
    'run' => function (array $f): array {
        $data = inv_move_data($f);
        $cols = [['key' => 'item', 'label' => 'Item code'], ['key' => 'desc', 'label' => 'Item description'], ['key' => 'unit', 'label' => 'Unit', 'optional' => true],
            ['key' => 'beg', 'label' => 'Beg. balance', 'type' => 'qty']];
        foreach (INV_MOVE_KINDS as $k => [$label, $dir]) {
            $cols[] = ['key' => $k, 'label' => $label, 'type' => 'qty', 'band' => strtoupper($dir), 'group' => $dir === 'in' ? 'In' : 'Out'];
        }
        $cols[] = ['key' => 'end', 'label' => 'Ending balance', 'type' => 'qty'];
        $rows = [];
        $tot = ['item' => 'TOTAL', 'beg' => 0.0, 'end' => 0.0] + array_fill_keys(array_keys(INV_MOVE_KINDS), 0.0);
        $tot['_links'] = ['beg' => inv_move_link($f, null, 'beg'), 'end' => inv_move_link($f, null, 'end')];
        foreach (array_keys(INV_MOVE_KINDS) as $k) {
            $tot['_links'][$k] = inv_move_link($f, null, $k);
        }
        foreach (inv_move_items($data) as $i) {
            $id = (int)$i['id'];
            $d = $data[$id];
            if (abs($d['beg']) < 0.00001 && abs($d['in']) < 0.00001 && abs($d['out']) < 0.00001 && abs($d['end']) < 0.00001) {
                continue;
            }
            $r = ['item' => $i['code'], 'desc' => $i['name'], 'unit' => $i['unit'], 'beg' => $d['beg'], 'end' => $d['end'],
                '_links' => ['item' => Report::link('inv.center', ['id' => $id]), 'beg' => inv_move_link($f, $id, 'beg', null, $d['beg']), 'end' => inv_move_link($f, $id, 'end', null, $d['end'])],
                '_ref' => ['item' => $id]];
            foreach (INV_MOVE_KINDS as $k => $_) {
                // as in stock: IN positive, OUT negative (a purchase returned on a check reads negative under Purchases)
                $r[$k] = abs($d[$k]) < 0.00001 ? '' : $d[$k];
                if (abs($d[$k]) >= 0.00001) {
                    $r['_links'][$k] = inv_move_link($f, $id, $k);
                }
                $tot[$k] += $d[$k];
            }
            $tot['beg'] += $d['beg'];
            $tot['end'] += $d['end'];
            $rows[] = $r;
        }
        $legend = [];
        foreach (INV_MOVE_KINDS as [$label, $dir, $what]) {
            $legend[] = '<strong>' . strtoupper($dir) . ' ' . h($label) . ':</strong> ' . h($what);
        }
        return [
            'columns' => $cols,
            'rows' => $rows,
            'totals' => $tot,
            'notes' => inv_move_notes($f) . ' The OUT columns are negative; Ending balance = the sum of Beg. balance and every IN and OUT column.',
            'footer' => '<div class="small text-muted">' . implode('<br>', $legend) . '</div>',
        ];
    },
]);

Report::define('sales_inv_movement', [
    'filters' => ['range', 'site', 'class', 'item', 'category'],
    'default_dates' => 'this_month',
    'extra_filters' => [
        'cashier_id' => ['Cashier', fn() => inv_move_cashiers()],
    ],
    'run' => function (array $f): array {
        $cashier = (int)($f['cashier_id'] ?? 0) ?: null;
        if ($cashier && !isset(inv_move_cashiers()[$cashier])) {
            $cashier = null;
        }
        $data = inv_move_data($f, $cashier);
        $inNs = ['purchases', 'trf_in', 'adj_in', 'build_in', 'proc_in'];
        $outNs = ['trf_out', 'adj_out', 'build_out', 'proc_out', 'debit_memo'];
        $rows = [];
        $tot = ['desc' => 'TOTAL', 'beg' => 0.0, 'in' => 0.0, 'out' => 0.0, 'end' => 0.0, 'sold' => 0.0, 'others' => 0.0, 'sales' => 0.0];
        foreach (inv_move_items($data) as $i) {
            $id = (int)$i['id'];
            $d = $data[$id];
            $in = array_sum(array_map(fn($k) => $d[$k], $inNs));
            $out = -array_sum(array_map(fn($k) => $d[$k], $outNs));
            // what the stock says was sold: beginning + delivery - withdrawals - ending
            $sold = $d['beg'] + $in - $out - $d['end'];
            $amt = (float)($d['sales_amt'] ?? 0.0);
            $others = 0.0;
            if ($cashier) {
                $mine = -(float)($d['sales_q_user'] ?? 0.0);
                $others = $sold - $mine;
                $sold = $mine;
                $amt = (float)($d['sales_amt_user'] ?? 0.0);
            }
            if (abs($d['beg']) < 0.00001 && abs($in) < 0.00001 && abs($out) < 0.00001 && abs($d['end']) < 0.00001 && abs($sold) < 0.00001 && abs($others) < 0.00001) {
                continue;
            }
            $avg = abs($sold) > 0.00001 ? $amt / $sold : 0.0;
            $total = r2($sold * $avg);
            $rows[] = [
                'item' => $i['code'], 'desc' => $i['name'], 'unit' => $i['unit'],
                'beg' => $d['beg'], 'in' => $in, 'out' => $out, 'end' => $d['end'], 'sold' => $sold, 'others' => $others,
                'avg' => abs($sold) > 0.00001 ? round($avg, 4) : '', 'sales' => $total,
                '_links' => ['beg' => inv_move_link($f, $id, 'beg', null, $d['beg']), 'in' => inv_move_link($f, $id, 'in_ns', null, $in), 'out' => inv_move_link($f, $id, 'out_ns', null, $out),
                    'end' => inv_move_link($f, $id, 'end', null, $d['end']), 'sold' => inv_move_link($f, $id, 'sales', $cashier, $sold),
                    'sales' => abs($total) < 0.005 ? null : Report::link('rpt.sales_item_detail', array_filter(['item_id' => $id, 'dates' => 'custom', 'from' => $f['from'], 'to' => $f['to'], 'site_id' => $f['site_id'] ?: null]))],
                '_ref' => ['item' => $id],
            ];
            foreach (['beg' => $d['beg'], 'in' => $in, 'out' => $out, 'end' => $d['end'], 'sold' => $sold, 'others' => $others, 'sales' => $total] as $k => $v) {
                $tot[$k] += $v;
            }
        }
        // the total line's average price is the total sales over the quantity sold
        $tot['avg'] = abs($tot['sold']) > 0.00001 ? round($tot['sales'] / $tot['sold'], 4) : '';
        $tot['sales'] = r2($tot['sales']);
        $tot['_links'] = ['beg' => inv_move_link($f, null, 'beg', null, $tot['beg']), 'in' => inv_move_link($f, null, 'in_ns', null, $tot['in']),
            'out' => inv_move_link($f, null, 'out_ns', null, $tot['out']), 'end' => inv_move_link($f, null, 'end', null, $tot['end']),
            'sold' => inv_move_link($f, null, 'sales', $cashier, $tot['sold'])];
        $cols = [['key' => 'item', 'label' => 'Item code', 'optional' => true], ['key' => 'desc', 'label' => 'Item description'], ['key' => 'unit', 'label' => 'Unit', 'optional' => true],
            ['key' => 'beg', 'label' => 'Beg. inventory', 'type' => 'qty'], ['key' => 'in', 'label' => 'Delivery (in)', 'type' => 'qty'],
            ['key' => 'out', 'label' => 'Withdrawals (out)', 'type' => 'qty'], ['key' => 'end', 'label' => 'Ending inventory', 'type' => 'qty'],
            ['key' => 'sold', 'label' => 'Sales (qty)', 'type' => 'qty']];
        if ($cashier) {
            $cols[] = ['key' => 'others', 'label' => 'Sold by other cashiers', 'type' => 'qty'];
        }
        $cols[] = ['key' => 'avg', 'label' => 'Ave. price', 'type' => 'money'];
        $cols[] = ['key' => 'sales', 'label' => 'Total sales', 'type' => 'money'];
        $notes = 'Delivery (in) and Withdrawals (out) leave out invoices, batch invoices, sales receipts and POS sales; Sales (qty) = Beg. inventory + Delivery - Withdrawals - Ending inventory, '
            . 'which is the quantity the sales documents took out of stock (net of credit memos and POS returns that restock). Ave. price = the amount of those invoices, batch invoices, '
            . 'sales receipts and POS sales over the quantity sold, so Total sales (Sales qty x Ave. price) equals their amount. Ending inventory is the quantity on hand at the end of the To date. '
            . 'Transactions dated within the range; click a figure to see its source transactions.';
        if ($cashier) {
            $notes .= ' Cashier: Sales (qty), Ave. price and Total sales are those of the sales documents this cashier entered; "Sold by other cashiers" is the rest of the quantity sold.';
        }
        return [
            'columns' => $cols,
            'rows' => $rows,
            'totals' => $tot,
            'notes' => $notes,
            'footer' => '<div class="small text-muted"><strong>Delivery (in):</strong> ' . h(implode('; ', array_map(fn($k) => INV_MOVE_KINDS[$k][2], $inNs)))
                . '.<br><strong>Withdrawals (out):</strong> ' . h(implode('; ', array_map(fn($k) => INV_MOVE_KINDS[$k][2], $outNs))) . '.</div>',
        ];
    },
]);

Report::define('inv_movement_txns', [
    'filters' => ['range', 'site', 'class', 'item', 'category'],
    'default_dates' => 'this_month',
    'sortable' => false,
    'extra_filters' => [
        'move' => ['Movement', function () {
            $o = INV_MOVE_GROUPS;
            foreach (INV_MOVE_KINDS as $k => [$label, $dir]) {
                $o[$k] = strtoupper($dir) . ': ' . $label;
            }
            return $o;
        }],
        'cashier_id' => ['Cashier', fn() => inv_move_cashiers()],
    ],
    'run' => function (array $f): array {
        $move = (string)($f['move'] ?? '');
        $kinds = match ($move) {
            'in' => array_keys(array_filter(INV_MOVE_KINDS, fn($k) => $k[1] === 'in')),
            'out' => array_keys(array_filter(INV_MOVE_KINDS, fn($k) => $k[1] === 'out')),
            'in_ns' => ['purchases', 'trf_in', 'adj_in', 'build_in', 'proc_in'],
            'out_ns' => ['trf_out', 'adj_out', 'build_out', 'proc_out', 'debit_memo'],
            default => isset(INV_MOVE_KINDS[$move]) ? [$move] : [],
        };
        $p = ['mvfrom' => $f['from'], 'mvto' => $f['to']];
        $where = inv_move_where($f, $p);
        $kind = inv_move_kind_sql();
        $sql = "SELECT m.item_id, m.txn_date, m.qty, m.total_cost, t.id AS txn_id, t.txn_type, t.txn_no, t.receipt_no, $kind AS kind,
                       COALESCE(c.name, v.name, t.payee_name) AS party, s.code AS site, cl.name AS class, COALESCE(NULLIF(u.full_name, ''), u.username) AS user,
                       i.code, i.name AS item_name
                  FROM inventory_movements m JOIN transactions t ON t.id = m.txn_id LEFT JOIN transaction_lines l ON l.id = m.txn_line_id
                  JOIN items i ON i.id = m.item_id JOIN sites s ON s.id = m.site_id
                  LEFT JOIN customers c ON c.id = t.customer_id LEFT JOIN vendors v ON v.id = t.vendor_id
                  LEFT JOIN classes cl ON cl.id = COALESCE(l.class_id, t.class_id) LEFT JOIN users u ON u.id = t.created_by
                 WHERE m.txn_date BETWEEN :mvfrom AND :mvto" . $where;
        if ($kinds) {
            [$in, $kp] = DB::in('mvk', $kinds);
            $sql .= " AND $kind IN $in";
            $p += $kp;
        }
        $cashier = (int)($f['cashier_id'] ?? 0);
        if ($cashier) {
            $sql .= ' AND t.created_by = :mvcu';
            $p['mvcu'] = $cashier;
        }
        $moves = DB::all($sql . ' ORDER BY i.code, m.txn_date, m.id', $p);
        $rows = [];
        $cur = null;
        $sub = 0.0;
        $subCost = 0.0;
        $grand = 0.0;
        $grandCost = 0.0;
        $close = function () use (&$rows, &$cur, &$sub, &$subCost) {
            if ($cur !== null) {
                $rows[] = ['type' => 'Total ' . $cur, 'qty' => $sub, 'cost' => r2($subCost), '_class' => 'subtotal-row'];
            }
        };
        foreach ($moves as $m) {
            if ($cur !== $m['code']) {
                $close();
                $cur = $m['code'];
                $sub = 0.0;
                $subCost = 0.0;
                $rows[] = ['type' => $m['code'] . ' - ' . $m['item_name'], '_class' => 'group-row'];
            }
            [$label, $dir] = INV_MOVE_KINDS[$m['kind']];
            $rows[] = [
                'type' => Txn::label($m['txn_type']), 'date' => $m['txn_date'], 'num' => $m['txn_no'] ?: $m['receipt_no'], 'name' => $m['party'],
                'site' => $m['site'], 'class' => $m['class'], 'user' => $m['user'], 'move' => strtoupper($dir) . ': ' . $label,
                'qty' => (float)$m['qty'], 'cost' => r2((float)$m['total_cost']),
                '_links' => ['num' => Report::txnLink($m), 'type' => Report::txnLink($m)],
                '_class' => 'indent-1',
            ];
            $sub += (float)$m['qty'];
            $subCost += (float)$m['total_cost'];
            $grand += (float)$m['qty'];
            $grandCost += (float)$m['total_cost'];
        }
        $close();
        $what = INV_MOVE_GROUPS[$move] ?? (isset(INV_MOVE_KINDS[$move]) ? strtoupper(INV_MOVE_KINDS[$move][1]) . ': ' . INV_MOVE_KINDS[$move][0] . ' (' . INV_MOVE_KINDS[$move][2] . ')' : 'Every movement');
        return [
            'grouped' => true,
            'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'],
                ['key' => 'name', 'label' => 'Name'], ['key' => 'site', 'label' => 'Site'], ['key' => 'class', 'label' => 'Class', 'optional' => true],
                ['key' => 'user', 'label' => 'Entered by', 'optional' => true], ['key' => 'move', 'label' => 'Movement'],
                ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty'], ['key' => 'cost', 'label' => 'Cost', 'type' => 'money', 'sensitive' => 'cost', 'optional' => true]],
            'rows' => $rows,
            'totals' => ['type' => 'TOTAL', 'qty' => $grand, 'cost' => r2($grandCost)],
            'notes' => 'Showing: <strong>' . h($what) . '</strong>. Quantities as they moved the stock (in positive, out negative); voided transactions are left out. Click a number to open the transaction.',
        ];
    },
]);
