<?php
/**
 * Import of One-Time Billing charges (water/views/one_time_billing_import.php): many members' charges, each with its
 * own charge category (any bill code but the water bill's) and amount, read from a file, reviewed and then issued as
 * one-time bills, each with its invoice, exactly as Issue Individual Charge issues them: every line or none.
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/billingHelpers.php';
require_once __DIR__ . '/billCreationHelpers.php';
require_once __DIR__ . '/discountPrivilegeBillingHelpers.php';
require_once __DIR__ . '/waterBillCodeExclusionHelpers.php';
require_once __DIR__ . '/../process/oneTimeBillingInventoryHelpers.php';
require_once __DIR__ . '/../process/organizationUnitHelpers.php';

/** The columns of the import file; a blank cell takes the value of the form */
const OTBI_COLUMNS = [
    'account_no' => ['label' => 'Water account no.', 'req' => true, 'size' => 'sm', 'hint' => 'The account number of the water-service account (an account with no number: its account ID)'],
    'member_name' => ['label' => 'Member name', 'size' => 'lg', 'hint' => 'Optional: checked against the name on the account'],
    'charge_code' => ['label' => 'Charge category', 'size' => 'md', 'hint' => 'The CODE NAME of a bill code (CONNECTION FEE, PVC PIPE ...); blank: the form\'s'],
    'amount' => ['label' => 'Amount', 'type' => 'money', 'size' => 'sm', 'hint' => 'The amount of a service or fee; blank: the form\'s, else the bill code\'s price. A material is priced from its bill code'],
    'quantity' => ['label' => 'Quantity', 'type' => 'num', 'size' => 'xs', 'hint' => 'For a material (inventory bill code); blank: 1'],
    'bill_date' => ['label' => 'Date issued', 'type' => 'date', 'size' => 'md', 'hint' => 'YYYY-MM-DD or MM/DD/YYYY; blank: the form\'s'],
    'term_days' => ['label' => 'Term (days)', 'type' => 'num', 'size' => 'xs', 'hint' => 'Days to the due date; OPEN for no due date; blank: the form\'s'],
];

const OTBI_ALIASES = [
    'account' => 'account_no', 'account_number' => 'account_no', 'water_account' => 'account_no', 'water_account_no' => 'account_no', 'acct' => 'account_no', 'acct_no' => 'account_no',
    'name' => 'member_name', 'member' => 'member_name', 'concessionaire' => 'member_name',
    'charge' => 'charge_code', 'charge_category' => 'charge_code', 'bill_code' => 'charge_code', 'code_name' => 'charge_code', 'code' => 'charge_code', 'category' => 'charge_code',
    'qty' => 'quantity', 'date' => 'bill_date', 'date_issued' => 'bill_date', 'bill_date_' => 'bill_date', 'term' => 'term_days', 'terms' => 'term_days', 'total_amount' => 'amount',
];

/** The largest amount of one charge (one_time_bills.total_amount is DECIMAL(14,2)) */
const OTBI_MAX_AMOUNT = 999999999.99;

/** The most charges one file issues */
const OTBI_MAX_LINES = 2000;

/** What is wrong with the class chosen for the bills (null when it can be used); nothing is remembered in the session */
function otbi_class_problem($value): ?string
{
    $had = $_SESSION['last_class_id'] ?? null;
    try {
        water_required_class($value, 'the one-time bills');
        return null;
    } catch (RuntimeException $e) {
        return $e->getMessage() . ' (Values for the blank cells)';
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if ($had === null) {
                unset($_SESSION['last_class_id']);
            } else {
                $_SESSION['last_class_id'] = $had;
            }
        }
    }
}

/** The bill codes a one-time bill may use (every one but the water bill's), by their code name in capitals */
function otbi_codes(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT bc.*, it.id AS it_id FROM bill_codes bc LEFT JOIN items it ON it.id = bc.item_id ORDER BY bc.code')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (is_water_billing_reserved_bill_code($pdo, (int)$c['code_id'])) {
            continue;
        }
        $out[strtoupper(trim((string)$c['code']))] = $c;
    }
    return $out;
}

/**
 * Checks the lines: the account, the name, the charge category, the amount (a material: its quantity and stock),
 * the dates and the member discount. Each line comes back with _errors, _notes, _info, _cells, _figure (the net
 * amount) and _ok (what to issue).
 */
function otbi_check(PDO $pdo, array $rows, array $common): array
{
    $codes = otbi_codes($pdo);
    $byId = [];
    foreach ($codes as $c) {
        $byId[(int)$c['code_id']] = $c;
    }
    $default = fn(string $k) => trim((string)($common[$k] ?? ''));
    $pick = fn(array $r, string $k) => trim((string)($r[$k] ?? '')) !== '' ? trim((string)$r[$k]) : $default($k);
    // the water accounts named in the file, by account number and by account ID
    $nos = array_values(array_unique(array_filter(array_map(fn($r) => trim((string)($r['account_no'] ?? '')), $rows), fn($v) => $v !== '')));
    $accounts = [];
    foreach (array_chunk($nos, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("SELECT pkey, account_number, lastname, firstname, middlename, status FROM water_accounts WHERE account_number IN ($in)");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $accounts['no:' . $a['account_number']] = $a;
        }
        // an account ID is taken only for an account that has no account number (one with a number is named by it,
        // so a number mistyped never lands on another member's account by its ID)
        $ids = array_values(array_filter($chunk, fn($v) => ctype_digit($v) && !isset($accounts['no:' . $v])));
        if ($ids) {
            $st = $pdo->query("SELECT pkey, account_number, lastname, firstname, middlename, status FROM water_accounts
                                WHERE (account_number IS NULL OR account_number = '') AND pkey IN (" . implode(',', array_map('intval', $ids)) . ')');
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $accounts['id:' . $a['pkey']] = $a;
            }
        }
    }
    $out = [];
    $need = [];       // the quantity of each material the lines take
    foreach (array_values($rows) as $i => $r) {
        $line = (int)($r['_line'] ?? ($i + 1));
        $errs = [];
        $notes = [];
        $cells = [];
        $info = '';
        $err = function (string $m, array $c) use (&$errs, &$cells) {
            $errs[] = $m;
            $cells[count($errs) - 1] = $c;
        };
        $no = trim((string)($r['account_no'] ?? ''));
        $a = $no === '' ? null : ($accounts['no:' . $no] ?? $accounts['id:' . $no] ?? null);
        if ($a && !isset($accounts['no:' . $no])) {
            $notes[] = 'Account ID ' . $no . ': the account has no account number.';
        }
        if ($no === '') {
            $err('Enter the water account no.', ['account_no']);
        } elseif (!$a) {
            $err('No water-service account ' . $no . '.', ['account_no']);
        } elseif (strtoupper((string)$a['status']) !== 'A') {
            $err('Water-service account ' . ($a['account_number'] ?: $a['pkey']) . ' is not active.', ['account_no']);
        }
        if ($a) {
            $full = trim($a['lastname'] . ', ' . $a['firstname'] . ($a['middlename'] ? ' ' . $a['middlename'] : ''), ', ');
            $given = trim((string)($r['member_name'] ?? ''));
            if ($given !== '' && !CsvReview::nameMatches($given, (string)$a['lastname'], (string)$a['firstname'], $full)) {
                $err('Account ' . ($a['account_number'] ?: $a['pkey']) . ' is ' . $full . ', not ' . $given . '.', ['member_name', 'account_no']);
            }
            $info = h($full) . ' <span class="imp-dim">account ' . h($a['account_number'] ?: $a['pkey']) . '</span>';
        }
        $codeRaw = $pick($r, 'charge_code');
        $bc = null;
        if ($codeRaw === '') {
            $err('Enter the charge category (the code name of a bill code).', ['charge_code']);
        } else {
            $bc = $codes[strtoupper($codeRaw)] ?? (ctype_digit($codeRaw) ? ($byId[(int)$codeRaw] ?? null) : null);
            if (!$bc) {
                $reserved = water_billing_reserved_bill_code($pdo);
                $err($reserved && strcasecmp(trim((string)$reserved['code']), $codeRaw) === 0
                    ? 'The bill code ' . $codeRaw . ' is the water bill\'s: it is billed by Post Billing, not as a one-time charge.'
                    : 'No charge category ' . $codeRaw . ' in Bill Codes.', ['charge_code']);
            } elseif (empty($bc['ar_account']) || empty($bc['income_account'])) {
                $err('Bill code ' . $bc['code'] . ' has no A/R or income account (Water Billing > Bill Codes).', ['charge_code']);
                $bc = null;
            } else {
                // the accounts its invoice posts to, checked as the posting checks them
                try {
                    WaterPosting::arAccount((int)$bc['ar_account']);
                    WaterPosting::creditAccount((int)$bc['income_account'], 'The income account of bill code ' . $bc['code']);
                } catch (UserError $e) {
                    $err($e->getMessage(), ['charge_code']);
                    $bc = null;
                }
            }
        }
        $gross = null;
        $qty = 1.0;
        if ($bc) {
            $isInv = bc_is_inventory($bc);
            if ($isInv) {
                if (empty($bc['it_id'])) {
                    $err('Bill code ' . $bc['code'] . ' is a material but is not linked to an item of the Item List (Water Billing > Bill Codes).', ['charge_code']);
                } else {
                    $qRaw = trim((string)($r['quantity'] ?? ''));
                    try {
                        $qty = bc_positive_quantity($qRaw === '' ? 1 : str_replace(',', '', $qRaw));
                        $gross = bc_authoritative_gross($bc, $qty, null);
                        if ($gross > OTBI_MAX_AMOUNT) {
                            throw new UserError('the quantity makes ' . number_format($gross, 2) . ', more than ' . number_format(OTBI_MAX_AMOUNT, 2) . ' on one bill.');
                        }
                        $need[(int)$bc['code_id']][] = [$i, $qty];
                        $given = trim((string)($r['amount'] ?? ''));
                        if ($given !== '' && ($g = CsvReview::amount($given)) !== null && abs((float)$g - $gross) > 0.004) {
                            $notes[] = 'A material is priced from its bill code: ' . number_format($qty, 2) . ' x ' . number_format((float)$bc['sales_price'], 2) . ' = ' . number_format($gross, 2) . ' (not ' . $given . ').';
                        }
                    } catch (Throwable $e) {
                        $err($bc['code'] . ': ' . $e->getMessage(), ['quantity']);
                    }
                }
            } else {
                $amountRaw = $pick($r, 'amount');
                $amount = $amountRaw === '' ? ((float)$bc['sales_price'] > 0 ? (string)$bc['sales_price'] : null) : CsvReview::amount($amountRaw);
                if ($amount === null || (float)$amount <= 0) {
                    $err($amountRaw === '' ? 'Enter the amount of ' . $bc['code'] . ' (its bill code has no price).' : 'The amount "' . $amountRaw . '" is not more than zero.', ['amount']);
                } elseif ((float)$amount > OTBI_MAX_AMOUNT) {
                    $err('The amount ' . $amountRaw . ' is more than ' . number_format(OTBI_MAX_AMOUNT, 2) . '.', ['amount']);
                } else {
                    $gross = round((float)$amount, 2);
                    if ($amountRaw === '') {
                        $notes[] = 'Amount: the price of ' . $bc['code'] . ', ' . number_format($gross, 2) . '.';
                    }
                }
                if (trim((string)($r['quantity'] ?? '')) !== '' && (float)str_replace(',', '', (string)$r['quantity']) != 1.0) {
                    $notes[] = 'The quantity is for a material: ' . $bc['code'] . ' is billed by its amount.';
                }
            }
        }
        $dateRaw = $pick($r, 'bill_date');
        $billDate = $dateRaw === '' ? date('Y-m-d') : CsvReview::dateStrict($dateRaw);
        if ($billDate === null) {
            $err('The date issued "' . $dateRaw . '" is not a date (YYYY-MM-DD or MM/DD/YYYY).', ['bill_date']);
        } else {
            try {
                Txn::assertPeriod($billDate, null);
            } catch (UserError $e) {
                $err($e->getMessage(), ['bill_date']);
            }
        }
        $termRaw = trim((string)($r['term_days'] ?? '')) !== '' ? trim((string)$r['term_days']) : $default('term_days');
        $term = null;
        if ($termRaw !== '' && !in_array(strtolower($termRaw), ['open', 'none', '-'], true)) {
            if (!preg_match('/^\d+$/', $termRaw) || (int)$termRaw > 3650) {
                $err('Term (days) must be a whole number of days from 0 to 3650, or OPEN for no due date.', ['term_days']);
            } else {
                $term = (int)$termRaw;
            }
        }
        $net = null;
        $discount = null;
        if (!$errs && $a && $bc && $gross !== null) {
            try {
                $discount = dp_compute_member_bill_discount($pdo, (int)$a['pkey'], (int)$bc['code_id'], 'one_time', $gross);
                $net = round((float)$discount['net_amount'], 2);
            } catch (Throwable $e) {
                $err($e->getMessage(), ['charge_code']);
            }
        }
        if ($net !== null) {
            $info .= ' <span class="imp-dim">' . h($bc['code']) . ', ' . h((string)$bc['type']) . '</span>';
            $notes[] = ((float)$discount['discount_amount'] > 0.004
                    ? 'Bill ' . number_format($net, 2) . ' after the member discount of ' . number_format((float)$discount['discount_amount'], 2) . ' on ' . number_format($gross, 2)
                    : 'Bill ' . number_format($net, 2))
                . ', issued ' . date('m/d/Y', strtotime($billDate)) . ($term === null ? ', no due date.' : ', due ' . date('m/d/Y', strtotime($billDate . ' +' . $term . ' days')) . '.');
        }
        $out[$i] = array_filter($r, fn($k) => !is_string($k) || $k === '' || $k[0] !== '_', ARRAY_FILTER_USE_KEY) + [
            '_line' => $line, '_errors' => $errs, '_notes' => $notes, '_cells' => $cells, '_info' => $info, '_figure' => $net ?? 0.0,
            '_ok' => $errs || $net === null ? null : ['member_id' => (int)$a['pkey'], 'account' => $a['account_number'] ?: (string)$a['pkey'], 'bill_code_id' => (int)$bc['code_id'],
                'code' => (string)$bc['code'], 'gross' => $gross, 'quantity' => $qty, 'bill_date' => $billDate, 'term_days' => $term],
        ];
    }
    // a material: the lines together may not take more than the stock on hand. Taken in the order of the file, the
    // lines that still fit are good; a line that goes past the stock is the one in red
    $qtyText = fn(float $v) => rtrim(rtrim(number_format($v, 2), '0'), '.');
    foreach ($need as $codeId => $list) {
        $available = (float)otb_available_stock($pdo, $codeId);
        $running = 0.0;
        foreach ($list as [$i, $q]) {
            $running = round($running + $q, 4);
            if ($running > $available + 0.00001) {
                $out[$i]['_errors'][] = 'Not enough stock of ' . $byId[$codeId]['code'] . ': with this line the lines take ' . $qtyText($running) . ', on hand ' . $qtyText($available) . '.';
                $out[$i]['_cells'][count($out[$i]['_errors']) - 1] = ['quantity'];
                $out[$i]['_ok'] = null;
                $running = round($running - $q, 4);
            }
        }
    }
    return array_values($out);
}

/**
 * Issues the lines as one-time bills (one bill and one invoice each, as Issue Individual Charge does), every line or
 * none. Returns ['count', 'total', 'discount', 'refs'].
 */
function otbi_post(PDO $pdo, array $rows, array $common, $classValue, int $userId, string $fileName): array
{
    if (!$rows) {
        fail('There is no charge to issue: every line was removed.');
    }
    if (count($rows) > OTBI_MAX_LINES) {
        fail('Issue at most ' . number_format(OTBI_MAX_LINES) . ' charges at a time: split the file.');
    }
    if ($userId <= 0) {
        fail('Your session is missing a valid operator identifier. Sign in again.');
    }
    $classId = water_required_class($classValue, 'the one-time bills');
    $checked = otbi_check($pdo, $rows, $common);
    $bad = array_values(array_filter($checked, fn($r) => $r['_errors'] || !$r['_ok']));
    if ($bad) {
        fail(count($bad) === 1 ? 'Line ' . $bad[0]['_line'] . ': ' . ($bad[0]['_errors'][0] ?? 'it has a problem.') : count($bad) . ' lines have a problem (line ' . $bad[0]['_line'] . ': ' . ($bad[0]['_errors'][0] ?? '') . ')');
    }
    $insert = $pdo->prepare("INSERT INTO one_time_bills
        (global_ref_number, member_id, bill_code_id, quantity, inventory_unit_cost, inventory_total_cost,
         bill_date, due_date, term_days, total_amount, gross_amount, discount_amount, discount_id,
         discount_rule_id, amount_paid, status, branch_id, segment_id, division_id, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 'unpaid', 0, 0, 0, ?, NOW(), NOW())");
    $pdo->beginTransaction();
    try {
        // the accounts, then the bill codes, each in one order, then the stock of each material, all locked before
        // anything else is read: an import or a bill issued at the same time waits, and the stock it took is seen
        $members = array_values(array_unique(array_map(fn($r) => (int)$r['_ok']['member_id'], $checked)));
        sort($members);
        foreach ($members as $m) {
            bc_lock_active_member($pdo, $m);
        }
        $need = [];
        foreach ($checked as $r) {
            $need[(int)$r['_ok']['bill_code_id']] = ($need[(int)$r['_ok']['bill_code_id']] ?? 0) + (float)$r['_ok']['quantity'];
        }
        ksort($need);
        $stockQ = $pdo->prepare('SELECT COALESCE(SUM(qty_on_hand), 0) FROM item_site_stock WHERE item_id = ? FOR UPDATE');
        foreach ($need as $codeId => $qty) {
            $bc = bc_lock_bill_code($pdo, $codeId);
            if (bc_is_inventory($bc) && !empty($bc['item_id'])) {
                $stockQ->execute([(int)$bc['item_id']]);
                $onHand = round((float)$stockQ->fetchColumn(), 4);
                if (round($qty, 4) > $onHand + 0.00001) {
                    fail('Not enough stock of ' . $bc['code'] . ': the lines take ' . rtrim(rtrim(number_format($qty, 2), '0'), '.') . ', on hand '
                        . rtrim(rtrim(number_format($onHand, 2), '0'), '.') . ' (stock was taken since the check). Nothing was issued.');
                }
            }
        }
        $count = 0;
        $total = 0.0;
        $discountTotal = 0.0;
        $refs = [];
        $locked = array_fill_keys($members, true);
        foreach ($checked as $r) {
            $ok = $r['_ok'];
            $label = 'Line ' . $r['_line'] . ' (' . $ok['account'] . ', ' . $ok['code'] . '): ';
            try {
                if (!isset($locked[$ok['member_id']])) {
                    bc_lock_active_member($pdo, $ok['member_id']);
                    $locked[$ok['member_id']] = true;
                }
                assert_not_water_billing_reserved_bill_code($pdo, $ok['bill_code_id'], 'One-Time Billing');
                $bc = bc_lock_bill_code($pdo, $ok['bill_code_id']);
                $isInv = bc_is_inventory($bc);
                $qty = $isInv ? $ok['quantity'] : 1.00;
                $gross = bc_authoritative_gross($bc, $qty, $ok['gross']);
                $unitCost = $isInv ? bc_cents_amount(bc_money_cents(otb_item_unit_cost($pdo, $ok['bill_code_id']), 'Inventory unit cost', true)) : 0.00;
                $totalCost = $isInv ? round($qty * $unitCost, 2, PHP_ROUND_HALF_UP) : 0.00;
                if ($isInv) {
                    otb_assert_inventory_item($bc);
                }
                $discount = dp_compute_member_bill_discount($pdo, $ok['member_id'], $ok['bill_code_id'], 'one_time', $gross);
                $net = bc_cents_amount(bc_money_cents($discount['net_amount'], 'Net bill amount', true));
                $dueDate = $ok['term_days'] === null ? null : date('Y-m-d', strtotime($ok['bill_date'] . ' +' . $ok['term_days'] . ' days'));
                $ref = generateGlobalRef($pdo);
                $insert->execute([$ref, $ok['member_id'], $ok['bill_code_id'], $qty, $unitCost, $totalCost, $ok['bill_date'], $dueDate, (int)($ok['term_days'] ?? 0),
                    $net, $discount['gross_amount'], $discount['discount_amount'], $discount['discount_id'], $discount['discount_rule_id'], $userId]);
                $billId = (int)$pdo->lastInsertId();
                WaterPosting::withClass($classId, fn() => otb_create_sales_journal($pdo, $ref, $ok['bill_date'], $bc, $ok['member_id'], $billId, $net, $qty, 0, 0, 0));
                logAudit($pdo, $userId, 'CREATE_ONE_TIME_BILL_IMPORT', 'one_time_bills', (string)$billId, json_encode([
                    'global_ref_number' => $ref, 'member_id' => $ok['member_id'], 'bill_code_id' => $ok['bill_code_id'], 'bill_date' => $ok['bill_date'],
                    'due_date' => $dueDate, 'term_days' => $ok['term_days'], 'quantity' => $qty, 'gross_amount' => $gross, 'net_amount' => $net,
                    'file' => mb_substr($fileName, 0, 120), 'line' => $r['_line'], 'discount' => dp_discount_audit_payload($discount),
                ], JSON_THROW_ON_ERROR));
            } catch (UserError $e) {
                throw new UserError($label . $e->getMessage() . ' Nothing was issued.');
            } catch (Throwable $e) {
                if ($e instanceof PDOException) {
                    throw $e;
                }
                throw new UserError($label . $e->getMessage() . ' Nothing was issued.');
            }
            $count++;
            $total += $net;
            $discountTotal += (float)$discount['discount_amount'];
            if (count($refs) < 5) {
                $refs[] = '#' . $ref;
            }
        }
        $pdo->commit();
        return ['count' => $count, 'total' => round($total, 2), 'discount' => round($discountTotal, 2), 'refs' => $refs];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Example lines of the template: real accounts and charge categories of this database when it has them */
function otbi_samples(PDO $pdo): array
{
    $accts = $pdo->query("SELECT account_number, pkey, lastname, firstname FROM water_accounts WHERE status = 'A' ORDER BY pkey LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    $codes = array_values(array_filter(otbi_codes($pdo), fn($c) => !bc_is_inventory($c)));
    $inv = array_values(array_filter(otbi_codes($pdo), fn($c) => bc_is_inventory($c) && !empty($c['it_id'])));
    $out = [];
    foreach ($accts ?: [['account_number' => '50001', 'pkey' => 1, 'lastname' => 'Bond', 'firstname' => 'James']] as $i => $a) {
        $c = $codes[$i % max(1, count($codes))] ?? ['code' => 'CONNECTION FEE', 'sales_price' => 1500];
        $out[] = ['account_no' => $a['account_number'] ?: (string)$a['pkey'], 'member_name' => trim($a['lastname'] . ', ' . $a['firstname'], ', '), 'charge_code' => $c['code'],
            'amount' => number_format((float)$c['sales_price'] > 0 ? (float)$c['sales_price'] : 100, 2, '.', ''), 'quantity' => '', 'bill_date' => date('Y-m-d'), 'term_days' => ['7', '15', 'OPEN'][$i % 3]];
    }
    if ($inv && $accts) {
        $out[] = ['account_no' => $accts[0]['account_number'] ?: (string)$accts[0]['pkey'], 'member_name' => trim($accts[0]['lastname'] . ', ' . $accts[0]['firstname'], ', '),
            'charge_code' => $inv[0]['code'], 'amount' => '', 'quantity' => '2', 'bill_date' => date('Y-m-d'), 'term_days' => '30'];
    }
    return $out;
}
