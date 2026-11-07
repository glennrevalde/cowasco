<?php
/**
 * common/individualSoaHelpers.php
 *
 * The Individual Statement of Account (Generate SOA / Post Billing, and Reports > Generate Statement of Account):
 * one member or customer, every invoice of theirs that is still unpaid whatever its billing period, of which the user
 * chooses the ones the statement lists. A member or customer is a party: all of its water-service accounts (two
 * houses, two meters), or one of them.
 *
 * Open invoices of a water-service account:
 *   water bills          posted (Post Billing), not deleted, amount due above what is paid
 *   one-time bills       not cancelled or deleted, total above what is paid
 *   installment bills    each unpaid amortization of an active contract
 *   recurring bills      not cancelled or deleted, total above what is paid
 *   share capital bills  the posted, unpaid Fixed Share Capital bills of the account's member (the same party), on
 *                        the party's first account only, so a member with two accounts does not see them twice
 * dated on or before the statement date. Amounts are worked in centavos. An installment amortization not yet due is
 * listed but not ticked at first (it is an upcoming due, as on the statement of one billing period).
 */

require_once __DIR__ . '/accountProfileHelpers.php';
require_once __DIR__ . '/institutionConfigurationHelpers.php';

if (!function_exists('isoa_valid_date')) {
    function isoa_valid_date(string $value): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d instanceof DateTimeImmutable && $d->format('Y-m-d') === $value;
    }
}

if (!function_exists('isoa_cents')) {
    function isoa_cents($value): int
    {
        return (int)round(((float)($value ?? 0)) * 100);
    }
}

if (!function_exists('isoa_money')) {
    /** centavos -> "1234.50" */
    function isoa_money(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('isoa_reference')) {
    /** The invoice number as the Statement of Account prints it (WTR-1024, OTB-15, INS-9-3 ...) */
    function isoa_reference(string $prefix, $globalRef, $id, $suffix = null): string
    {
        $ref = trim((string)($globalRef ?? ''));
        if ($ref === '' || $ref === '0') {
            $ref = (string)$id;
        }
        return $prefix . '-' . $ref . ($suffix !== null && (string)$suffix !== '' ? '-' . $suffix : '');
    }
}

if (!function_exists('isoa_date_label')) {
    function isoa_date_label($date): string
    {
        $t = $date ? strtotime((string)$date) : false;
        return $t ? date('M j, Y', $t) : '';
    }
}

if (!function_exists('isoa_charge_name')) {
    /** A bill code as a statement line names it: its description, or its code when it has none (not both alike) */
    function isoa_charge_name($code, $description): string
    {
        $code = trim((string)($code ?? ''));
        $description = trim((string)($description ?? ''));
        if ($description === '') {
            return $code !== '' ? $code : 'Charge';
        }
        if (strcasecmp($description, $code) === 0) {
            return $description;
        }
        return $code !== '' ? $description . ' (' . $code . ')' : $description;
    }
}

if (!function_exists('isoa_person_accounts')) {
    /**
     * The water-service accounts of the member or customer an account belongs to (the same party), first account
     * first: [['id', 'account_number', 'status'], ...]. An account with no party is on its own.
     */
    function isoa_person_accounts(PDO $pdo, int $accountId): array
    {
        $party = $pdo->prepare('SELECT party_id FROM water_accounts WHERE pkey = ?');
        $party->execute([$accountId]);
        $partyId = $party->fetchColumn();
        if ($partyId === false) {
            return [];
        }
        if ($partyId === null || (int)$partyId <= 0) {
            $stmt = $pdo->prepare('SELECT pkey, account_number, status FROM water_accounts WHERE pkey = ?');
            $stmt->execute([$accountId]);
        } else {
            $stmt = $pdo->prepare("SELECT pkey, account_number, status FROM water_accounts WHERE party_id = ?
                                   ORDER BY CASE WHEN COALESCE(status, 'A') = 'A' THEN 0 ELSE 1 END, pkey");
            $stmt->execute([(int)$partyId]);
        }
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['id' => (int)$r['pkey'], 'account_number' => trim((string)($r['account_number'] ?? '')) ?: (string)(int)$r['pkey'],
                'status' => strtoupper((string)($r['status'] ?? 'A')) === 'A' ? 'Active' : 'Inactive'];
        }
        return $out;
    }
}

if (!function_exists('isoa_open_invoices')) {
    /**
     * The open invoices of a water-service account as of $asOf (YYYY-MM-DD), oldest first. Each:
     * key ("water:12"), type, type_label, reference, date, due_date, description, detail, amount, paid, balance
     * (strings, 2 decimals), status (overdue | due | current | open), status_label, days_overdue.
     */
    function isoa_open_invoices(PDO $pdo, $accountIds, string $asOf): array
    {
        $accountIds = array_values(array_unique(array_filter(array_map('intval', (array)$accountIds))));
        $out = [];
        foreach ($accountIds as $accountId) {
            foreach (isoa_open_invoices_of_account($pdo, $accountId, $asOf) as $i) {
                $out[] = $i;
            }
        }
        usort($out, static function (array $a, array $b): int {
            $c = strcmp((string)($a['date'] ?? $a['due_date'] ?? ''), (string)($b['date'] ?? $b['due_date'] ?? ''));
            return $c !== 0 ? $c : strcmp($a['reference'], $b['reference']);
        });
        return $out;
    }
}

if (!function_exists('isoa_open_invoices_of_account')) {
    /** isoa_open_invoices() of one water-service account */
    function isoa_open_invoices_of_account(PDO $pdo, int $accountId, string $asOf): array
    {
        $out = [];
        $no = $pdo->prepare('SELECT account_number FROM water_accounts WHERE pkey = ?');
        $no->execute([$accountId]);
        $acctNo = (string)$no->fetchColumn();
        $add = static function (array $r) use (&$out, $asOf, $accountId, $acctNo): void {
            $amount = max(0, (int)$r['amount']);
            $paid = min($amount, max(0, (int)$r['paid']));
            $balance = $amount - $paid;
            if ($balance <= 0) {
                return;
            }
            $due = $r['due_date'] ? substr((string)$r['due_date'], 0, 10) : null;
            $days = 0;
            if ($due === null) {
                $status = 'open';
                $label = 'No due date';
            } elseif ($due < $asOf) {
                $status = 'overdue';
                $days = (int)(new DateTimeImmutable($due))->diff(new DateTimeImmutable($asOf))->days;
                $label = 'Overdue ' . $days . ' day' . ($days === 1 ? '' : 's');
            } elseif ($due === $asOf) {
                $status = 'due';
                $label = 'Due today';
            } else {
                $status = 'current';
                $label = 'Not yet due';
            }
            $out[] = [
                'key' => $r['type'] . ':' . (int)$r['id'],
                'account_id' => $accountId,
                'account_number' => trim($acctNo) !== '' ? trim($acctNo) : (string)$accountId,
                'type' => $r['type'],
                'type_label' => $r['type_label'],
                'reference' => $r['reference'],
                'date' => $r['date'] ? substr((string)$r['date'], 0, 10) : null,
                'due_date' => $due,
                'description' => $r['description'],
                'detail' => $r['detail'] ?? '',
                'amount' => isoa_money($amount),
                'paid' => isoa_money($paid),
                'balance' => isoa_money($balance),
                'balance_cents' => $balance,
                'status' => $status,
                'status_label' => $label,
                'days_overdue' => $days,
                // ticked at first: every invoice but an installment amortization not yet due (an upcoming due)
                'preselect' => !($r['type'] === 'installment' && $status === 'current'),
            ];
        };

        // water bills (posted)
        $stmt = $pdo->prepare("
            SELECT br.reading_id, br.global_ref_number, br.billing_date, br.due_date,
                   br.amount_due, COALESCE(br.amount_paid, 0) AS amount_paid, br.consumption, bp.bp_code, bp.start_date, bp.end_date
            FROM bill_readings br
            LEFT JOIN bill_periods bp ON bp.period_id = br.period_id
            WHERE br.member_id = ?
              AND br.is_billed = 1
              AND COALESCE(br.bill_lifecycle_status, 'active') <> 'deleted'
              AND br.amount_due > COALESCE(br.amount_paid, 0)
              AND (br.billing_date IS NULL OR br.billing_date <= ?)
        ");
        $stmt->execute([$accountId, $asOf]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $period = trim((string)($r['bp_code'] ?? ''));
            $span = ($r['start_date'] && $r['end_date']) ? isoa_date_label($r['start_date']) . ' to ' . isoa_date_label($r['end_date']) : '';
            $add([
                'type' => 'water', 'type_label' => 'Water bill', 'id' => $r['reading_id'],
                'reference' => isoa_reference('WTR', $r['global_ref_number'], $r['reading_id']),
                'date' => $r['billing_date'], 'due_date' => $r['due_date'],
                'description' => 'Water consumption, ' . number_format((int)$r['consumption']) . ' m³',
                'detail' => trim('Billing period ' . $period . ($span !== '' ? ', ' . $span : '')),
                'amount' => isoa_cents($r['amount_due']), 'paid' => isoa_cents($r['amount_paid']),
            ]);
        }

        // one-time bills
        $stmt = $pdo->prepare("
            SELECT b.bill_id, b.global_ref_number, b.bill_date, b.due_date, b.total_amount, COALESCE(b.amount_paid, 0) AS amount_paid,
                   b.quantity, c.code, c.description, c.type
            FROM one_time_bills b
            LEFT JOIN bill_codes c ON c.code_id = b.bill_code_id
            WHERE b.member_id = ?
              AND b.status NOT IN ('cancelled', 'deleted')
              AND COALESCE(b.bill_lifecycle_status, 'active') <> 'deleted'
              AND b.total_amount > COALESCE(b.amount_paid, 0)
              AND b.bill_date <= ?
        ");
        $stmt->execute([$accountId, $asOf]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $isMaterial = strcasecmp(trim((string)($r['type'] ?? '')), 'Inventory') === 0;
            $add([
                'type' => 'one_time', 'type_label' => 'One-time bill', 'id' => $r['bill_id'],
                'reference' => isoa_reference('OTB', $r['global_ref_number'], $r['bill_id']),
                'date' => $r['bill_date'], 'due_date' => $r['due_date'],
                'description' => isoa_charge_name($r['code'], $r['description'])
                    . ($isMaterial ? ', quantity ' . rtrim(rtrim(number_format((float)$r['quantity'], 2), '0'), '.') : ''),
                'detail' => '',
                'amount' => isoa_cents($r['total_amount']), 'paid' => isoa_cents($r['amount_paid']),
            ]);
        }

        // installment bills: each unpaid amortization
        $stmt = $pdo->prepare("
            SELECT s.schedule_id, s.due_date, s.amount, COALESCE(s.amount_paid, 0) AS amount_paid,
                   b.installment_id, b.global_ref_number, b.start_date, b.term, c.code, c.description,
                   (SELECT COUNT(*) FROM installment_schedules x WHERE x.installment_id = b.installment_id) AS schedule_count,
                   (SELECT COUNT(*) FROM installment_schedules x WHERE x.installment_id = b.installment_id
                      AND (x.due_date < s.due_date OR (x.due_date = s.due_date AND x.schedule_id <= s.schedule_id))) AS schedule_no
            FROM installment_schedules s
            INNER JOIN installment_bills b ON b.installment_id = s.installment_id
            LEFT JOIN bill_codes c ON c.code_id = b.bill_code_id
            WHERE b.member_id = ?
              AND b.status NOT IN ('cancelled', 'deleted')
              AND COALESCE(b.bill_lifecycle_status, 'active') <> 'deleted'
              AND s.amount > COALESCE(s.amount_paid, 0)
              AND b.start_date <= ?
        ");
        $stmt->execute([$accountId, $asOf]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add([
                'type' => 'installment', 'type_label' => 'Installment', 'id' => $r['schedule_id'],
                'reference' => isoa_reference('INS', $r['global_ref_number'], $r['installment_id'], $r['schedule_id']),
                'date' => $r['due_date'], 'due_date' => $r['due_date'],
                'description' => isoa_charge_name($r['code'], $r['description'])
                    . ', amortization ' . (int)$r['schedule_no'] . ' of ' . (int)$r['schedule_count'],
                'detail' => 'Installment started ' . isoa_date_label($r['start_date']),
                'amount' => isoa_cents($r['amount']), 'paid' => isoa_cents($r['amount_paid']),
            ]);
        }

        // recurring bills
        $stmt = $pdo->prepare("
            SELECT rb.bill_id, rb.global_ref_number, rb.bill_date, rb.due_date, rb.total_amount, COALESCE(rb.amount_paid, 0) AS amount_paid,
                   rb.billing_period_start, rb.billing_period_end, c.code, c.description
            FROM recurring_bills rb
            LEFT JOIN bill_codes c ON c.code_id = rb.bill_code_id
            WHERE rb.member_id = ?
              AND rb.status NOT IN ('cancelled', 'deleted')
              AND COALESCE(rb.bill_lifecycle_status, 'active') <> 'deleted'
              AND rb.total_amount > COALESCE(rb.amount_paid, 0)
              AND rb.bill_date <= ?
        ");
        $stmt->execute([$accountId, $asOf]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $span = ($r['billing_period_start'] && $r['billing_period_end'])
                ? isoa_date_label($r['billing_period_start']) . ' to ' . isoa_date_label($r['billing_period_end']) : '';
            $add([
                'type' => 'recurring', 'type_label' => 'Recurring bill', 'id' => $r['bill_id'],
                'reference' => isoa_reference('REC', $r['global_ref_number'], $r['bill_id']),
                'date' => $r['bill_date'], 'due_date' => $r['due_date'],
                'description' => isoa_charge_name($r['code'], $r['description']),
                'detail' => $span !== '' ? 'Service period ' . $span : 'Recurring charge',
                'amount' => isoa_cents($r['total_amount']), 'paid' => isoa_cents($r['amount_paid']),
            ]);
        }

        // Fixed Share Capital bills of the account's member (posted bills only: nothing is posted here), on the
        // member's first account only
        $first = isoa_person_accounts($pdo, $accountId)[0]['id'] ?? $accountId;
        if ($first === $accountId && class_exists('ShareCapitalBilling') && ShareCapitalBilling::ready()) {
            foreach (ShareCapitalBilling::openBillsForWaterAccount($accountId) as $b) {
                if (substr((string)$b['bill_date'], 0, 10) > $asOf) {
                    continue;
                }
                $add([
                    'type' => 'sharecap', 'type_label' => 'Share capital', 'id' => $b['bill_id'],
                    'reference' => 'SCB-' . (int)$b['bill_id'],
                    'date' => $b['bill_date'], 'due_date' => $b['due_date'] ?? null,
                    'description' => ShareCapitalBilling::billDescription($b),
                    'detail' => 'Fixed share capital billing' . (trim((string)($b['member_no'] ?? '')) !== '' ? ', member no. ' . $b['member_no'] : ''),
                    'amount' => isoa_cents($b['amount']), 'paid' => isoa_cents($b['amount_paid']),
                ]);
            }
        }

        usort($out, static function (array $a, array $b): int {
            $c = strcmp((string)($a['date'] ?? $a['due_date'] ?? ''), (string)($b['date'] ?? $b['due_date'] ?? ''));
            return $c !== 0 ? $c : strcmp($a['reference'], $b['reference']);
        });
        return $out;
    }
}

if (!function_exists('isoa_totals')) {
    /** The statement's figures for the invoices it lists: total, overdue, due today, not yet due, no due date */
    function isoa_totals(array $invoices): array
    {
        $t = ['count' => count($invoices), 'total' => 0, 'overdue' => 0, 'due' => 0, 'current' => 0, 'open' => 0];
        foreach ($invoices as $i) {
            $t['total'] += (int)$i['balance_cents'];
            $t[$i['status']] += (int)$i['balance_cents'];
        }
        foreach (['total', 'overdue', 'due', 'current', 'open'] as $k) {
            $t[$k] = isoa_money($t[$k]);
        }
        return $t;
    }
}

if (!function_exists('isoa_search_accounts')) {
    /**
     * Members and customers for the statement's name box: the water-service accounts whose name, account number or
     * member number has $q, the names that start with it first, one row per member or customer (the accounts of the
     * same party together). $kind: all | member | customer. The unpaid invoices are counted as of $asOf.
     * Each: id (the first account), name, kind, member_no, status, accounts [{id, account_number, status}],
     * account_number (the accounts, joined), open_count, open_total.
     */
    function isoa_search_accounts(PDO $pdo, string $q, string $kind = 'all', int $limit = 15, ?string $asOf = null): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $asOf = $asOf !== null && isoa_valid_date($asOf) ? $asOf : date('Y-m-d');
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $start = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $memberExists = "EXISTS (SELECT 1 FROM members mm WHERE mm.party_id = w.party_id)";
        $kindSql = $kind === 'member' ? " AND $memberExists" : ($kind === 'customer' ? " AND NOT $memberExists" : '');
        $stmt = $pdo->prepare("
            SELECT w.pkey, w.party_id, w.lastname, w.firstname, w.middlename, p.display_name,
                   (SELECT mm.member_no FROM members mm WHERE mm.party_id = w.party_id ORDER BY mm.id LIMIT 1) AS member_no,
                   $memberExists AS is_member
            FROM water_accounts w
            LEFT JOIN parties p ON p.id = w.party_id
            WHERE (w.account_number LIKE :l1 OR w.lastname LIKE :l2 OR w.firstname LIKE :l3
                   OR CONCAT_WS(', ', w.lastname, w.firstname) LIKE :l4 OR CONCAT_WS(' ', w.firstname, w.lastname) LIKE :l5
                   OR p.display_name LIKE :l6
                   OR EXISTS (SELECT 1 FROM members mx WHERE mx.party_id = w.party_id AND mx.member_no LIKE :l7))
                  $kindSql
            ORDER BY CASE WHEN w.lastname LIKE :s1 OR w.account_number LIKE :s2 OR p.display_name LIKE :s3 THEN 0 ELSE 1 END,
                     w.lastname, w.firstname, w.pkey
            LIMIT " . max(1, min(50, $limit * 2)));
        $stmt->execute([':l1' => $like, ':l2' => $like, ':l3' => $like, ':l4' => $like, ':l5' => $like, ':l6' => $like, ':l7' => $like,
            ':s1' => $start, ':s2' => $start, ':s3' => $start]);
        $out = [];
        $seen = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            // one row per member or customer: the accounts of the same party together
            $group = (int)($r['party_id'] ?? 0) > 0 ? 'p' . (int)$r['party_id'] : 'a' . (int)$r['pkey'];
            if (isset($seen[$group])) {
                continue;
            }
            $seen[$group] = true;
            $accounts = isoa_person_accounts($pdo, (int)$r['pkey']);
            if (!$accounts) {
                continue;
            }
            $open = isoa_open_invoices($pdo, array_column($accounts, 'id'), $asOf);
            $total = 0;
            foreach ($open as $i) {
                $total += (int)$i['balance_cents'];
            }
            $active = array_filter($accounts, static fn($a) => $a['status'] === 'Active');
            $out[] = [
                'id' => $accounts[0]['id'],
                'name' => wa_account_name($r),
                'kind' => (int)$r['is_member'] > 0 ? 'Member' : 'Customer',
                'member_no' => trim((string)($r['member_no'] ?? '')),
                'status' => $active ? 'Active' : 'Inactive',
                'accounts' => $accounts,
                'account_number' => implode(', ', array_column($accounts, 'account_number')),
                'open_count' => count($open),
                'open_total' => isoa_money($total),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }
}

if (!function_exists('isoa_company')) {
    /** The cooperative as the statement prints it */
    function isoa_company(PDO $pdo): array
    {
        $issuer = institution_issuer($pdo);
        $company = ['name' => $issuer['name'], 'address' => $issuer['address'], 'telephone' => $issuer['phone'],
            'mobile' => '', 'email' => '', 'website' => '', 'tin' => $issuer['tin'], 'logo' => wa_issuer_logo($pdo)];
        try {
            $row = $pdo->query("SELECT cellularNo, email, website FROM bussinessprofile WHERE COALESCE(isdeleted, 0) = 0 ORDER BY IsBranch ASC, pkey ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $company['mobile'] = trim((string)($row['cellularNo'] ?? ''));
                $company['email'] = trim((string)($row['email'] ?? ''));
                $company['website'] = trim((string)($row['website'] ?? ''));
            }
        } catch (Throwable $e) {
            // no business profile on this install
        }
        return $company;
    }
}
