<?php
/**
 * common/readingHelpers.php
 * Shared helper functions for the Bill Reading module.
 * Requires: database/config.php  (provides $pdo + requireLogin())
 */

/* ===================================================================
   WATER RATE CALCULATOR
   =================================================================== */
// Authoritative water billing calculations live in waterRateBillingHelpers.php.
// This file intentionally contains no separate water-charge formula.
require_once __DIR__ . '/waterRateBillingHelpers.php';
require_once __DIR__ . '/memberAddressHelpers.php';

/* ===================================================================
   BILLING PERIOD
   =================================================================== */

function getActivePeriod(PDO $pdo): ?array {
    $stmt = $pdo->query(
        "SELECT period_id, bp_code, start_date, end_date, status
         FROM bill_periods
         WHERE status = 'OPEN'
         ORDER BY period_id DESC
         LIMIT 1"
    );
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* ===================================================================
   MEMBER LOOKUP
   =================================================================== */

function searchMembers(PDO $pdo, string $query, int $limit = 10): array {
    // Each word typed must be found in the name, the account number, the ID or the address (in any order:
    // "james bond", "bond, james", "50031"). The LIMIT is written into the SQL as a whole number: bound as a
    // parameter it would be sent quoted ('12'), which MySQL refuses, and every search would fail.
    $words = preg_split('/[\s,]+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $words = array_slice($words, 0, 5);
    if (!$words) {
        return [];
    }
    $primaryAddressTypeId = member_primary_address_type_id($pdo);
    $where = [];
    $params = [$primaryAddressTypeId];
    foreach ($words as $w) {
        $like = '%' . addcslashes($w, '%_\\') . '%';
        $where[] = "(m.lastname LIKE ? OR m.firstname LIKE ? OR m.middlename LIKE ? OR m.account_number LIKE ?
                     OR CAST(m.pkey AS CHAR) LIKE ? OR a.street LIKE ? OR a.housebldg LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    $stmt = $pdo->prepare(
        "SELECT m.pkey,
                CONCAT(IFNULL(m.salutation,''), ' ', CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')),
                       IF(m.middlename IS NOT NULL AND m.middlename NOT IN ('', '.', 'N/A'),
                          CONCAT(' ', LEFT(m.middlename,1), '.'), '')) AS full_name,
                m.lastname, m.firstname, m.middlename, m.account_number,
                a.street, a.housebldg,
                (SELECT dm.dm_id FROM discounted_members dm WHERE dm.member_id = m.pkey LIMIT 1) AS dm_id
         FROM water_accounts m
         LEFT JOIN memberaddress ma ON ma.member_key = m.pkey
                                   AND ma.address_type_key = ?
                                   AND ma.status = 'A'
         LEFT JOIN addresses a ON a.pkey = ma.address_key
         WHERE m.status = 'A'
           AND " . implode(' AND ', $where) . "
         ORDER BY (CASE WHEN m.lastname LIKE ? OR m.account_number LIKE ? OR CAST(m.pkey AS CHAR) LIKE ? THEN 0
                        WHEN m.firstname LIKE ? THEN 1 ELSE 2 END), m.lastname, m.firstname
         LIMIT " . max(1, min(100, $limit))
    );
    // incremental search (version 46): the accounts whose last name, account number or ID start with the first word
    // typed come first, then a first name that starts with it, then the rest
    $starts = addcslashes($words[0], '%_\\') . '%';
    array_push($params, $starts, $starts, $starts, $starts);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMemberById(PDO $pdo, int $pkey): ?array {
    $primaryAddressTypeId = member_primary_address_type_id($pdo);
    $stmt = $pdo->prepare(
        "SELECT m.pkey, m.account_number,
                CONCAT(IFNULL(m.salutation,''), ' ', CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')),
                       IF(m.middlename IS NOT NULL AND m.middlename NOT IN ('', '.', 'N/A'),
                          CONCAT(' ', LEFT(m.middlename,1), '.'), '')) AS full_name,
                m.lastname, m.firstname, m.middlename,
                m.zone,
                rc.rc_code,
                rc.rc_name,
                a.street, a.housebldg, a.zippostal_code,
                (SELECT GROUP_CONCAT(DISTINCT d.discount_type ORDER BY d.discount_type SEPARATOR ', ')
                 FROM discounted_members dm
                 JOIN discounts d ON d.discount_id = dm.discount_id
                 WHERE dm.member_id = m.pkey) AS discount_type
         FROM water_accounts m
         LEFT JOIN memberaddress ma ON ma.member_key = m.pkey
                                   AND ma.address_type_key = ?
                                   AND ma.status = 'A'
         LEFT JOIN addresses a  ON a.pkey  = ma.address_key
         LEFT JOIN rate_code rc ON rc.rc_id = m.rc_id
         WHERE m.pkey = ? AND m.status = 'A'"
    );
    $stmt->execute([$primaryAddressTypeId, $pkey]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getMemberDiscounts(PDO $pdo, int $memberId): array {
    $bundle = water_rate_get_member_discount($pdo, $memberId);
    if (!$bundle || empty($bundle['rules']) || !is_array($bundle['rules'])) {
        return [];
    }

    $discounts = [];
    foreach ($bundle['rules'] as $rule) {
        $method = (string)($rule['discount_method'] ?? 'none');
        $discounts[] = [
            'id' => 'discount_' . (int)($rule['discount_id'] ?? 0),
            'source' => 'discounts',
            'label' => (string)($rule['discount_type'] ?? 'Member Privilege'),
            'type' => $method,
            'value' => (float)($rule['discount_value'] ?? 0),
            'max_discount_amount' => $rule['max_discount_amount'] ?? null,
            'max_discount_cubic' => $rule['max_discount_cubic'] ?? null,
            'eligibility_limit_m3' => (int)($bundle['eligibility_limit_m3'] ?? water_member_discount_max_consumption_m3()),
        ];
    }

    return $discounts;
}


function getPreviousReading(PDO $pdo, int $memberId, int $currentPeriodId): ?float {
    $stmt = $pdo->prepare(
        "SELECT br.pres_reading
         FROM bill_readings br
         JOIN bill_periods bp_prev ON bp_prev.period_id = br.period_id
         JOIN bill_periods bp_curr ON bp_curr.period_id = ?
         WHERE br.member_id = ?
           AND bp_prev.start_date < bp_curr.start_date
           AND COALESCE(br.bill_lifecycle_status, 'active') <> 'deleted'
         ORDER BY bp_prev.start_date DESC, bp_prev.end_date DESC, bp_prev.period_id DESC, br.reading_id DESC
         LIMIT 1"
    );
    $stmt->execute([$currentPeriodId, $memberId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)round((float)$row['pres_reading']) : null;
}

function getCurrentReading(PDO $pdo, int $memberId, int $periodId): ?array {
    $stmt = $pdo->prepare(
        "SELECT reading_id, global_ref_number, prev_reading, pres_reading, consumption,
                is_billed, is_paid, bill_lifecycle_status, is_edited_override, entry_mode, encoded_by,
                snapshot_version, rate_code_id_snapshot, rate_code_snapshot, tariff_snapshot,
                discount_snapshot, calculation_snapshot, base_charge_snapshot,
                discount_amount_snapshot, snapshot_hash, snapshot_created_at,
                created_at, updated_at
         FROM bill_readings
         WHERE member_id = ? AND period_id = ?
         LIMIT 1"
    );
    $stmt->execute([$memberId, $periodId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getMembersByStreet(PDO $pdo, string $street, int $periodId): array {
   $primaryAddressTypeId = member_primary_address_type_id($pdo);
   $stmt = $pdo->prepare("
    SELECT m.pkey,
           CONCAT(CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')),
                  IF(m.middlename IS NOT NULL AND m.middlename NOT IN ('', '.', 'N/A'),
                     CONCAT(' ', LEFT(m.middlename,1), '.'), '')) AS full_name,
           a.street, a.housebldg,
           br.reading_id, br.prev_reading, br.pres_reading,
           br.consumption, br.is_billed, br.is_edited_override,
           (SELECT d.discount_type 
            FROM discounted_members dm
            JOIN discounts d ON d.discount_id = dm.discount_id
            WHERE dm.member_id = m.pkey LIMIT 1) AS discount_type
    FROM water_accounts m
    JOIN memberaddress ma ON ma.member_key = m.pkey
                          AND ma.address_type_key = ?
                          AND ma.status = 'A'
    JOIN addresses a
        ON a.pkey = ma.address_key
    LEFT JOIN bill_readings br
        ON br.member_id = m.pkey AND br.period_id = ?
    WHERE m.status = 'A'
      AND LOWER(TRIM(a.street)) = LOWER(TRIM(?))
    ORDER BY m.lastname, m.firstname
");
    $stmt->execute([$primaryAddressTypeId, $periodId, $street]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getStreetList(PDO $pdo): array {
    $stmt = $pdo->query(
        "SELECT DISTINCT street
         FROM addresses
         WHERE status = 'A' AND street IS NOT NULL AND street != ''
         ORDER BY street ASC"
    );
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/* ===================================================================
   FLASH MESSAGES
   =================================================================== */
if (!function_exists('setFlash')) {
    function setFlash(string $type, string $msg): void {
        require_once __DIR__ . '/../database/config.php';
        $_SESSION['flash'] = ['type' => $type, 'message' => $msg];
    }
}

if (!function_exists('flash')) {
    function flash(): ?array {
        require_once __DIR__ . '/../database/config.php';
        if (!isset($_SESSION['flash'])) return null;
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
}

/* ===================================================================
   MISC UTILS
   =================================================================== */
function initials(string $name): string {
    $parts = preg_split('/[\s,]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    $init  = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $init .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $init ?: '??';
}

function fmtPeso(float $amount): string {
    return '₱ ' . number_format($amount, 2);
}

function fmtCubic(float $m3): string {
    return number_format((int)round($m3), 0) . ' m³';
}

function getZoneList(PDO $pdo): array {
    $stmt = $pdo->query(
        "SELECT DISTINCT zone FROM water_accounts WHERE status='A' AND zone IS NOT NULL ORDER BY zone"
    );
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
