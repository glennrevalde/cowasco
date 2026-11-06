<?php
/**
 * getMembersByStreet.php
 * Fetches members dynamically based on city/street selected.
 */
require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/businessRequestGuard.php';
$__processGuardPdo = getDB();
// Read-only dependency shared by several business pages (audit F07): the caller
// names its page through ?context=..., limited to this allowlist.
business_require_read_access($__processGuardPdo, ['reading_entry', 'one_time_billing'], 'reading_entry');
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */

require_once __DIR__ . '/../database/config.php';
header('Content-Type: application/json');

$pdo    = getDB();
$city   = trim($_GET['city']   ?? '');
$street = trim($_GET['street'] ?? '');

try {
    // ------------------------------------------------------------------
    // 1. Build the members query — no discount_rate (column doesn't exist)
    // ------------------------------------------------------------------
    $sql = "
        SELECT DISTINCT
            m.pkey,
            CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS full_name
        FROM water_accounts m
    ";

    $filters = ["m.status = 'A'"];
    $params  = [];

    $isCitySpecific   = ($city   !== '' && strtolower($city)   !== 'all');
    $isStreetSpecific = ($street !== '' && strtolower($street) !== 'all');

    // Only JOIN address tables when filtering by a specific city or street
    if ($isCitySpecific || $isStreetSpecific) {
        $sql .= "
            JOIN memberaddress ma ON ma.member_key = m.pkey  AND ma.status = 'A'
            JOIN addresses     a  ON a.pkey = ma.address_key AND a.status  = 'A'
        ";

        if ($isCitySpecific) {
            $sql .= " JOIN cities c ON c.pkey = a.city_id AND c.status = 'A' ";
            $filters[] = "LOWER(TRIM(c.city)) = LOWER(TRIM(:city))";
            $params[':city'] = $city;
        }

        if ($isStreetSpecific) {
            $filters[] = "LOWER(TRIM(a.street)) = LOWER(TRIM(:street))";
            $params[':street'] = $street;
        }
    }

    $sql .= " WHERE " . implode(" AND ", $filters);
    $sql .= " ORDER BY m.lastname ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($members)) {
        echo json_encode([]);
        exit;
    }

    // ------------------------------------------------------------------
    // 2. Fetch discounts for all returned members in one query
    //    (uses discount_id + discount_type only — no discount_rate)
    // ------------------------------------------------------------------
    $pkeys       = array_column($members, 'pkey');
    $placeholders = implode(',', array_fill(0, count($pkeys), '?'));

    $discStmt = $pdo->prepare("
        SELECT
            dm.member_id,
            d.discount_id,
            d.discount_type
        FROM discounted_members dm
        JOIN discounts d ON dm.discount_id = d.discount_id
        WHERE dm.member_id IN ($placeholders)
    ");
    $discStmt->execute($pkeys);

    // Group discounts by member_id
    $discountMap = [];
    foreach ($discStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $discountMap[$row['member_id']][] = [
            'id'   => (int)$row['discount_id'],
            'type' => $row['discount_type'],
            'rate' => 0, // No discount_rate column — JS handles rate=0 gracefully
        ];
    }

    // ------------------------------------------------------------------
    // 3. Attach discounts array to each member
    // ------------------------------------------------------------------
    foreach ($members as &$mem) {
        $mem['discounts'] = $discountMap[$mem['pkey']] ?? [];
    }
    unset($mem);

    echo json_encode(array_values($members));

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}