<?php
require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */

require_once '../common/waterRateBillingHelpers.php';
require_once '../common/waterBillingSnapshotHelpers.php';
require_once '../common/institutionConfigurationHelpers.php';
header('Content-Type: application/json; charset=UTF-8');

$pdo = getDB();

if (!function_exists('soa_valid_iso_date')) {
    function soa_valid_iso_date(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        return $date instanceof DateTimeImmutable
            && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
            && $date->format('Y-m-d') === $value;
    }
}

if (!function_exists('soa_reference')) {
    function soa_reference(string $prefix, $globalReference, $fallbackId, $suffix = null): string
    {
        $reference = trim((string)($globalReference ?? ''));
        if ($reference === '' || $reference === '0') {
            $reference = trim((string)$fallbackId);
        }
        $formatted = $prefix . '-' . $reference;
        if ($suffix !== null && trim((string)$suffix) !== '') {
            $formatted .= '-' . trim((string)$suffix);
        }
        return $formatted;
    }
}

if (!function_exists('soa_image_data_uri')) {
    function soa_image_data_uri($blob): ?string
    {
        if (!is_string($blob) || $blob === '') {
            return null;
        }

        $mime = 'image/png';
        if (substr($blob, 0, 3) === "\xFF\xD8\xFF") {
            $mime = 'image/jpeg';
        } elseif (substr($blob, 0, 6) === 'GIF87a' || substr($blob, 0, 6) === 'GIF89a') {
            $mime = 'image/gif';
        } elseif (substr($blob, 0, 4) === "\x89PNG") {
            $mime = 'image/png';
        }

        return 'data:' . $mime . ';base64,' . base64_encode($blob);
    }
}

if (!function_exists('soa_join_nonempty')) {
    function soa_join_nonempty(array $parts, string $separator = ', '): string
    {
        $clean = [];
        foreach ($parts as $part) {
            $part = trim((string)($part ?? ''));
            if ($part !== '') {
                $clean[] = $part;
            }
        }
        return implode($separator, $clean);
    }
}

$memberId = (int)($_GET['member_id'] ?? 0);
$periodId = (int)($_GET['period_id'] ?? 0);
$statementDateInput = trim((string)($_GET['statement_date'] ?? ''));

if ($memberId <= 0 || $periodId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing or invalid member or billing period.']);
    exit;
}

try {
    $periodStmt = $pdo->prepare(
        "SELECT period_id, bp_code, start_date, end_date, status
         FROM bill_periods
         WHERE period_id = ?
         LIMIT 1"
    );
    $periodStmt->execute([$periodId]);
    $period = $periodStmt->fetch(PDO::FETCH_ASSOC);
    if (!$period) {
        throw new RuntimeException('The selected billing period no longer exists.');
    }

    $periodEndDate = substr((string)$period['end_date'], 0, 10);
    $statementDate = $statementDateInput !== '' ? $statementDateInput : $periodEndDate;
    if (!soa_valid_iso_date($statementDate)) {
        throw new RuntimeException('The statement date is invalid.');
    }

    $memberStmt = $pdo->prepare(
        "SELECT pkey, account_number, salutation, lastname, firstname, middlename, zone
         FROM water_accounts
         WHERE pkey = ?
           AND COALESCE(status, 'A') = 'A'
         LIMIT 1"
    );
    $memberStmt->execute([$memberId]);
    $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        throw new RuntimeException('The selected member is unavailable or inactive.');
    }

    $surname = trim((string)($member['lastname'] ?? ''));
    $givenNames = soa_join_nonempty([
        $member['firstname'] ?? '',
        $member['middlename'] ?? '',
    ], ' ');
    $fullName = $surname !== ''
        ? $surname . ($givenNames !== '' ? ', ' . $givenNames : '')
        : $givenNames;
    if ($fullName === '') {
        $fullName = 'Member ' . $memberId;
    }

    $memberAddress = '';
    try {
        $addressStmt = $pdo->prepare(
            "SELECT a.housebldg, a.street, a.other_address_detail, a.zippostal_code,
                    c.city, c.province, c.country, at.name AS address_type
             FROM memberaddress ma
             INNER JOIN addresses a ON a.pkey = ma.address_key
             LEFT JOIN cities c ON c.pkey = a.city_id
             LEFT JOIN addresstype at ON at.pkey = ma.address_type_key
             WHERE ma.member_key = ?
               AND COALESCE(ma.status, 'A') = 'A'
               AND COALESCE(a.status, 'A') = 'A'
               AND (ma.dtaddressto IS NULL OR ma.dtaddressto >= CURDATE())
             ORDER BY
               CASE WHEN LOWER(COALESCE(at.name, '')) IN ('home', 'residential', 'primary') THEN 0 ELSE 1 END,
               ma.dtaddressFrom DESC,
               a.pkey DESC
             LIMIT 1"
        );
        $addressStmt->execute([$memberId]);
        if ($address = $addressStmt->fetch(PDO::FETCH_ASSOC)) {
            $localAddress = soa_join_nonempty([
                $address['housebldg'] ?? '',
                $address['street'] ?? '',
                $address['other_address_detail'] ?? '',
            ]);
            $cityAddress = soa_join_nonempty([
                $address['city'] ?? '',
                $address['province'] ?? '',
                $address['zippostal_code'] ?? '',
            ]);
            $memberAddress = soa_join_nonempty([$localAddress, $cityAddress], ', ');
        }
    } catch (Throwable $addressError) {
        error_log('SOA member-address lookup error: ' . $addressError->getMessage());
    }

    $memberPhone = '';
    $memberEmail = '';
    try {
        $contactStmt = $pdo->prepare(
            "SELECT cc.name AS channel_name, mcc.contactdata
             FROM membercontactchannels mcc
             INNER JOIN contactchannels cc ON cc.pkey = mcc.conchannelkey
             WHERE mcc.memberkey = ?
               AND COALESCE(mcc.status, 'A') = 'A'
               AND COALESCE(cc.status, 'A') = 'A'
               AND (mcc.activetodate IS NULL OR mcc.activetodate >= CURDATE())
             ORDER BY mcc.activefromdate DESC"
        );
        $contactStmt->execute([$memberId]);
        foreach ($contactStmt->fetchAll(PDO::FETCH_ASSOC) as $contact) {
            $channel = strtolower(trim((string)($contact['channel_name'] ?? '')));
            $value = trim((string)($contact['contactdata'] ?? ''));
            if ($value === '') {
                continue;
            }
            if ($memberEmail === '' && strpos($channel, 'email') !== false) {
                $memberEmail = $value;
            }
            if ($memberPhone === '' && preg_match('/phone|mobile|cell|tel/', $channel)) {
                $memberPhone = $value;
            }
        }
    } catch (Throwable $contactError) {
        error_log('SOA member-contact lookup error: ' . $contactError->getMessage());
    }

    // the cooperative issues the statement: its name, address, telephone and TIN from the company settings (the
    // institution profile fills what they leave empty)
    $issuer = institution_issuer($pdo);
    $company = [
        'name' => $issuer['name'],
        'address' => $issuer['address'],
        'telephone' => $issuer['phone'],
        'mobile' => '',
        'email' => '',
        'website' => '',
        'tin' => $issuer['tin'],
        'logo_data_uri' => null,
    ];

    try {
        $companyStmt = $pdo->query(
            "SELECT name, address1, address2, telephoneNo, cellularNo, email, website, tinNo, logoImage
             FROM bussinessprofile
             WHERE COALESCE(isdeleted, 0) = 0
             ORDER BY IsBranch ASC, pkey ASC
             LIMIT 1"
        );
        if ($profile = $companyStmt->fetch(PDO::FETCH_ASSOC)) {
            $company['name'] = $company['name'] ?: trim((string)($profile['name'] ?? ''));
            $company['address'] = $company['address'] ?: soa_join_nonempty([
                $profile['address1'] ?? '',
                $profile['address2'] ?? '',
            ]);
            $company['telephone'] = $company['telephone'] ?: trim((string)($profile['telephoneNo'] ?? ''));
            $company['mobile'] = trim((string)($profile['cellularNo'] ?? ''));
            $company['email'] = trim((string)($profile['email'] ?? ''));
            $company['website'] = trim((string)($profile['website'] ?? ''));
            $company['tin'] = $company['tin'] ?: trim((string)($profile['tinNo'] ?? ''));
            $company['logo_data_uri'] = soa_image_data_uri($profile['logoImage'] ?? null);
        }
    } catch (Throwable $companyError) {
        error_log('SOA company-profile lookup error: ' . $companyError->getMessage());
    }

    $signatories = [
        'manager' => institution_officer_for_report($pdo, 'manager'),
        'general_manager' => institution_officer_for_report($pdo, 'general_manager'),
    ];

    $current = [
        'Water Bill' => [],
        'Installments' => [],
        'Recurring Fees' => [],
        'One-Time Fees' => [],
    ];
    $upcoming = [];

    $waterStmt = $pdo->prepare(
        "SELECT
            br.reading_id,
            br.global_ref_number,
            br.member_id,
            br.consumption,
            br.amount_due,
            br.amount_paid,
            br.billing_date,
            br.term_days,
            br.snapshot_version,
            br.rate_code_id_snapshot,
            br.rate_code_snapshot,
            br.tariff_snapshot,
            br.discount_snapshot,
            br.calculation_snapshot,
            br.base_charge_snapshot,
            br.discount_amount_snapshot,
            br.snapshot_hash,
            br.snapshot_created_at,
            COALESCE(br.due_date, bp.end_date) AS water_due_date
         FROM bill_readings br
         INNER JOIN bill_periods bp ON bp.period_id = br.period_id
         WHERE br.member_id = ?
           AND br.period_id = ?
           AND br.is_billed = 1
           AND br.is_paid = 0
           AND COALESCE(br.bill_lifecycle_status, 'active') = 'active'
         ORDER BY br.reading_id DESC
         LIMIT 1"
    );
    $waterStmt->execute([$memberId, $periodId]);

    $primaryBillDate = null;
    $primaryTermDays = null;
    $primaryDueDate = null;

    if ($water = $waterStmt->fetch(PDO::FETCH_ASSOC)) {
        $details = water_snapshot_details_for_reading($water);
        $totalConsumption = (int)($details['consumption'] ?? $water['consumption'] ?? 0);
        $amountCents = max(
            0,
            water_rate_decimal_to_scaled_int($water['amount_due'] ?? 0, 2)
                - water_rate_decimal_to_scaled_int($water['amount_paid'] ?? 0, 2)
        );

        if ($amountCents > 0) {
            $description = 'Water Consumption (' . number_format($totalConsumption, 0) . ' m³)';
            $current['Water Bill'][] = [
                'category' => 'Water Bill',
                'date' => $water['billing_date'] ?: $periodEndDate,
                'reference' => soa_reference('WTR', $water['global_ref_number'] ?? null, $water['reading_id']),
                'description' => $description,
                'amount' => water_rate_scaled_to_decimal($amountCents, 2),
                'debit' => water_rate_scaled_to_decimal($amountCents, 2),
                'credit' => '0.00',
                'due_date' => $water['water_due_date'] ?: $periodEndDate,
                'snapshot_version' => (int)($water['snapshot_version'] ?? 0),
                'snapshot_warning' => $details['snapshot_warning'] ?? null,
            ];
        }

        $primaryBillDate = $water['billing_date'] ?: null;
        $primaryTermDays = $water['term_days'] !== null ? (int)$water['term_days'] : null;
        $primaryDueDate = $water['water_due_date'] ?: null;
    }

    $installmentStmt = $pdo->prepare(
        "SELECT
            c.code AS code_name,
            b.installment_id,
            b.global_ref_number,
            b.start_date,
            s.schedule_id,
            GREATEST(0, s.amount - COALESCE(s.amount_paid, 0)) AS balance,
            s.due_date
         FROM installment_schedules s
         INNER JOIN installment_bills b ON b.installment_id = s.installment_id
         INNER JOIN bill_codes c ON c.code_id = b.bill_code_id
         WHERE b.member_id = ?
           AND s.status IN ('pending', 'partial')
           AND GREATEST(0, s.amount - COALESCE(s.amount_paid, 0)) > 0
           AND b.status = 'active'
           AND COALESCE(b.bill_lifecycle_status, 'active') = 'active'
         ORDER BY s.due_date ASC, s.schedule_id ASC"
    );
    $installmentStmt->execute([$memberId]);
    foreach ($installmentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $amount = water_rate_scaled_to_decimal(water_rate_decimal_to_scaled_int($row['balance'], 2), 2);
        $item = [
            'category' => 'Installments',
            'date' => $row['due_date'],
            'reference' => soa_reference('INS', $row['global_ref_number'] ?? null, $row['installment_id'], $row['schedule_id']),
            'description' => trim((string)$row['code_name']) . ' (Amortization)',
            'amount' => $amount,
            'debit' => $amount,
            'credit' => '0.00',
            'due_date' => $row['due_date'],
        ];
        if ($row['due_date'] <= $periodEndDate) {
            $current['Installments'][] = $item;
        } else {
            $upcoming[] = $item;
        }
    }

    $recurringStmt = $pdo->prepare(
        "SELECT
            c.code AS code_name,
            rb.bill_id,
            rb.global_ref_number,
            rb.bill_date,
            GREATEST(0, rb.total_amount - COALESCE(rb.amount_paid, 0)) AS amount,
            rb.due_date
         FROM recurring_bills rb
         INNER JOIN bill_codes c ON c.code_id = rb.bill_code_id
         WHERE rb.member_id = ?
           AND rb.status IN ('unpaid', 'overdue')
           AND GREATEST(0, rb.total_amount - COALESCE(rb.amount_paid, 0)) > 0
           AND COALESCE(rb.bill_lifecycle_status, 'active') = 'active'
         ORDER BY rb.due_date ASC, rb.bill_id ASC"
    );
    $recurringStmt->execute([$memberId]);
    foreach ($recurringStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $amount = water_rate_scaled_to_decimal(water_rate_decimal_to_scaled_int($row['amount'], 2), 2);
        $item = [
            'category' => 'Recurring Fees',
            'date' => $row['bill_date'],
            'reference' => soa_reference('REC', $row['global_ref_number'] ?? null, $row['bill_id']),
            'description' => trim((string)$row['code_name']),
            'amount' => $amount,
            'debit' => $amount,
            'credit' => '0.00',
            'due_date' => $row['due_date'],
        ];
        if ($row['due_date'] <= $periodEndDate) {
            $current['Recurring Fees'][] = $item;
        } else {
            $upcoming[] = $item;
        }
    }

    $oneTimeStmt = $pdo->prepare(
        "SELECT
            c.code AS code_name,
            b.bill_id,
            b.global_ref_number,
            b.bill_date,
            GREATEST(0, b.total_amount - COALESCE(b.amount_paid, 0)) AS balance,
            b.due_date
         FROM one_time_bills b
         INNER JOIN bill_codes c ON c.code_id = b.bill_code_id
         WHERE b.member_id = ?
           AND b.status = 'unpaid'
           AND GREATEST(0, b.total_amount - COALESCE(b.amount_paid, 0)) > 0
           AND COALESCE(b.bill_lifecycle_status, 'active') = 'active'
         ORDER BY b.due_date ASC, b.bill_id ASC"
    );
    $oneTimeStmt->execute([$memberId]);
    foreach ($oneTimeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $amount = water_rate_scaled_to_decimal(water_rate_decimal_to_scaled_int($row['balance'], 2), 2);
        $item = [
            'category' => 'One-Time Fees',
            'date' => $row['bill_date'],
            'reference' => soa_reference('OTB', $row['global_ref_number'] ?? null, $row['bill_id']),
            'description' => trim((string)$row['code_name']),
            'amount' => $amount,
            'debit' => $amount,
            'credit' => '0.00',
            'due_date' => $row['due_date'],
        ];
        if ($row['due_date'] <= $periodEndDate) {
            $current['One-Time Fees'][] = $item;
        } else {
            $upcoming[] = $item;
        }
    }

    usort($upcoming, static function (array $left, array $right): int {
        $dateCompare = strcmp((string)($left['due_date'] ?? ''), (string)($right['due_date'] ?? ''));
        if ($dateCompare !== 0) {
            return $dateCompare;
        }
        return strcmp((string)($left['reference'] ?? ''), (string)($right['reference'] ?? ''));
    });

    echo json_encode([
        'success' => true,
        'statement_date' => $statementDate,
        'period' => [
            'period_id' => (int)$period['period_id'],
            'code' => (string)$period['bp_code'],
            'start_date' => (string)$period['start_date'],
            'end_date' => (string)$period['end_date'],
            'status' => (string)$period['status'],
        ],
        'member' => [
            'id' => $memberId,
            'account_reference' => trim((string)($member['account_number'] ?? '')) ?: (string)$memberId,
            'name' => $fullName,
            'address' => $memberAddress,
            'phone' => $memberPhone,
            'email' => $memberEmail,
            'zone' => $member['zone'] !== null ? (string)$member['zone'] : '',
        ],
        'company' => $company,
        'signatories' => $signatories,
        'bill_date' => $primaryBillDate,
        'billing_date' => $primaryBillDate,
        'term_days' => $primaryTermDays,
        'due_date' => $primaryDueDate,
        'current' => $current,
        'upcoming' => $upcoming,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('SOA data error: ' . $error->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $error instanceof RuntimeException
            ? $error->getMessage()
            : 'The Statement of Account data could not be generated.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
