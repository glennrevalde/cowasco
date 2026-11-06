<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$__authGuardPdo = getDB();
requirePageAccess($__authGuardPdo, basename(__FILE__, '.php'));
unset($__authGuardPdo);
/* AUTH_GUARD_END */

require_once '../database/config.php';
require_once __DIR__ . '/../common/operationTokenHelpers.php';
require_once __DIR__ . '/../common/waterPipeSizeHelpers.php';
require_once __DIR__ . '/../common/waterRateBillingHelpers.php';

require_once __DIR__ . '/../database/config.php';

$pdo = getDB();
$canOpenPartyCenter = class_exists('Access') && Access::page('party.center');
// the Open Account form as it was sent, when it came back with a question (a possible party on file) or an error
$addAccountForm = isset($_SESSION['wb_add_account_form']) && is_array($_SESSION['wb_add_account_form']) ? $_SESSION['wb_add_account_form'] : null;
unset($_SESSION['wb_add_account_form']);

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function buildQueryString(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);

    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }

    return http_build_query($query);
}

function statusLabel(?string $status): string
{
    return $status === 'A' ? 'Active' : ($status === 'I' ? 'Inactive' : ($status ?: 'Unknown'));
}

function fullName(array $member): string
{
    $parts = array_filter([
        $member['lastname'] ?? '',
        $member['firstname'] ?? '',
        $member['middlename'] ?? ''
    ]);

    return implode(', ', $parts);
}

function bindParams(PDOStatement $stmt, array $params): void
{
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
}

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$gender = trim($_GET['gender'] ?? '');
$civilstatus = trim($_GET['civilstatus'] ?? '');
$zone = trim($_GET['zone'] ?? '');
// address sub-group: the same list as the member form (Cooperative > Member Types, Clusters & Other Lists)
$subgroupReady = Members::waterSubgroupReady();
$subgroup = $subgroupReady ? trim((string)($_GET['subgroup'] ?? '')) : '';
$subgroupOptions = Members::listNames('address_subgroup');
// the Manage button beside the Address sub-group boxes: the list is kept on Address Sub-groups (or on the member lists)
$subgroupManage = $subgroupReady && (Access::page('water.address_subgroups') || Access::page('coop.lists'));
$subgroupManageBtn = $subgroupManage
    ? '<button type="button" class="sgm-manage" data-subgroup-manage data-endpoint="../process/addressSubgroups.php" data-page-key="water.member_list" data-csrf="' . e(csrfToken()) . '" aria-label="Add, edit or delete address sub-groups"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>Add, edit or delete sub-groups</button>'
    : '';
$cityId = trim($_GET['city_id'] ?? '');
$addressTypeId = trim($_GET['address_type_key'] ?? '');
$rateCodeId = trim($_GET['rc_id'] ?? '');
$pipeSizeId = trim($_GET['pipe_size_id'] ?? '');
$sort = trim($_GET['sort'] ?? 'account_number');
$direction = strtolower(trim($_GET['direction'] ?? 'asc'));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(10, min(200, (int)($_GET['per_page'] ?? 50)));
$offset = ($page - 1) * $perPage;

$allowedSorts = [
    'account_number' => 'CAST(m.account_number AS UNSIGNED)',
    'meter_sequence' => 'm.meter_sequence',
    'lastname' => 'm.lastname',
    'firstname' => 'm.firstname',
    'gender' => 'm.gender',
    'civilstatus' => 'm.civilstatus',
    'zone' => 'm.zone',
    'rate_code' => 'r.rc_code',
    'pipe_size' => 'ps.diameter_inches',
    'status' => 'm.status',
];

if (!array_key_exists($sort, $allowedSorts)) {
    $sort = 'account_number';
}

if (!in_array($direction, ['asc', 'desc'], true)) {
    $direction = 'asc';
}

if ($sort === 'meter_sequence') {
    $sequenceDirection = strtoupper($direction);
    $orderBy = "
        CASE WHEN NULLIF(TRIM(m.meter_sequence), '') IS NULL THEN 1 ELSE 0 END ASC,
        CASE WHEN m.meter_sequence REGEXP '^[0-9]+$' THEN 0 ELSE 1 END ASC,
        CASE WHEN m.meter_sequence REGEXP '^[0-9]+$' THEN CAST(m.meter_sequence AS UNSIGNED) END {$sequenceDirection},
        m.meter_sequence {$sequenceDirection},
        m.lastname ASC,
        m.firstname ASC
    ";
} else {
    $orderBy = $allowedSorts[$sort] . ' ' . strtoupper($direction) . ', m.lastname ASC, m.firstname ASC';
}

$where = [];
$params = [];

$activeAddressCondition = "
    ma2.status = 'A'
    AND (
        ma2.dtaddressto IS NULL
        OR ma2.dtaddressto = '0001-01-01'
        OR ma2.dtaddressto >= CURDATE()
    )
";

if ($search !== '') {
    /*
     * PDO native prepares require every named placeholder to be unique within
     * one statement. Global Search opens this page with ?search=..., so each
     * searchable field receives its own bound placeholder.
     */
    $searchValue = '%' . $search . '%';
    $where[] = "(
        m.account_number LIKE :search_account
        OR CAST(m.pkey AS CHAR) LIKE :search_member_id
        OR m.meter_sequence LIKE :search_meter_sequence
        OR m.lastname LIKE :search_lastname
        OR m.firstname LIKE :search_firstname
        OR m.middlename LIKE :search_middlename
        OR CONCAT_WS(' ', m.firstname, m.middlename, m.lastname) LIKE :search_fullname_forward
        OR CONCAT_WS(' ', m.lastname, m.firstname, m.middlename) LIKE :search_fullname_reverse
        OR r.rc_code LIKE :search_rate_code
        OR r.rc_name LIKE :search_rate_name
        OR ps.size_code LIKE :search_pipe_code
        OR ps.size_label LIKE :search_pipe_label
        OR EXISTS (
            SELECT 1
            FROM membercontactchannels mc2
            WHERE mc2.memberkey = m.pkey
              AND mc2.status = 'A'
              AND (mc2.activetodate IS NULL OR mc2.activetodate >= CURDATE())
              AND mc2.contactdata LIKE :search_contact
        )
        OR EXISTS (
            SELECT 1
            FROM memberaddress ma2
            INNER JOIN addresses a2 ON a2.pkey = ma2.address_key AND a2.status = 'A'
            LEFT JOIN cities c2 ON c2.pkey = a2.city_id AND c2.status = 'A'
            WHERE ma2.member_key = m.pkey
              AND $activeAddressCondition
              AND (
                    a2.street LIKE :search_street
                    OR a2.housebldg LIKE :search_building
                    OR a2.other_address_detail LIKE :search_address_detail
                    OR c2.city LIKE :search_city
                    OR c2.province LIKE :search_province
              )
        )
    )";

    foreach ([
        ':search_account',
        ':search_member_id',
        ':search_meter_sequence',
        ':search_lastname',
        ':search_firstname',
        ':search_middlename',
        ':search_fullname_forward',
        ':search_fullname_reverse',
        ':search_rate_code',
        ':search_rate_name',
        ':search_pipe_code',
        ':search_pipe_label',
        ':search_contact',
        ':search_street',
        ':search_building',
        ':search_address_detail',
        ':search_city',
        ':search_province',
    ] as $searchParameter) {
        $params[$searchParameter] = $searchValue;
    }
}

if ($status !== '') {
    $where[] = "m.status = :status";
    $params[':status'] = $status;
}

if ($gender !== '') {
    $where[] = "m.gender = :gender";
    $params[':gender'] = $gender;
}

if ($civilstatus !== '') {
    $where[] = "m.civilstatus = :civilstatus";
    $params[':civilstatus'] = $civilstatus;
}

if ($zone !== '') {
    $where[] = "m.zone = :zone";
    $params[':zone'] = $zone;
}

if ($subgroup !== '') {
    if ($subgroup === 'none') {
        $where[] = "(m.address_subgroup IS NULL OR m.address_subgroup = '')";
    } else {
        $where[] = "m.address_subgroup = :subgroup";
        $params[':subgroup'] = $subgroup;
    }
}

if ($rateCodeId !== '') {
    if ($rateCodeId === 'none') {
        $where[] = "m.rc_id IS NULL";
    } else {
        $where[] = "m.rc_id = :rc_id";
        $params[':rc_id'] = (int)$rateCodeId;
    }
}

if ($pipeSizeId !== '') {
    if ($pipeSizeId === 'none') {
        $where[] = "m.pipe_size_id IS NULL";
    } else {
        $where[] = "m.pipe_size_id = :pipe_size_id";
        $params[':pipe_size_id'] = (int)$pipeSizeId;
    }
}

if ($cityId !== '') {
    $where[] = "EXISTS (
        SELECT 1
        FROM memberaddress ma2
        INNER JOIN addresses a2 ON a2.pkey = ma2.address_key AND a2.status = 'A'
        WHERE ma2.member_key = m.pkey
          AND $activeAddressCondition
          AND a2.city_id = :city_id
    )";
    $params[':city_id'] = (int)$cityId;
}

if ($addressTypeId !== '') {
    $where[] = "EXISTS (
        SELECT 1
        FROM memberaddress ma2
        WHERE ma2.member_key = m.pkey
          AND $activeAddressCondition
          AND ma2.address_type_key = :address_type_key
    )";
    $params[':address_type_key'] = (int)$addressTypeId;
}

$whereSql = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$baseFromSql = "
    FROM water_accounts m
    LEFT JOIN rate_code r ON r.rc_id = m.rc_id
    LEFT JOIN water_pipe_sizes ps ON ps.pipe_size_id = m.pipe_size_id
";

$countSql = "
    SELECT COUNT(*)
    $baseFromSql
    $whereSql
";

$countStmt = $pdo->prepare($countSql);
bindParams($countStmt, $params);
$countStmt->execute();
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$listSql = "
    SELECT
        m.pkey,
        m.account_number,
        m.meter_sequence,
        m.salutation,
        m.lastname,
        m.firstname,
        m.middlename,
        m.gender,
        m.civilstatus,
        m.occupation,
        m.zone,
        m.status,
        m.rc_id,
        m.pipe_size_id,
        r.rc_code,
        r.rc_name,
        ps.size_code AS pipe_size_code,
        ps.size_label AS pipe_size_label,
        ps.diameter_inches,
        ps.is_active AS pipe_size_is_active,
        m.party_id,
        pty.party_no,
        pty.display_name AS party_name,
        (SELECT COUNT(*) FROM members cm WHERE cm.party_id = m.party_id) AS party_is_member,
        " . ($subgroupReady ? "m.address_subgroup," : "NULL AS address_subgroup,") . "
        (SELECT cm.address_subgroup FROM members cm WHERE cm.party_id = m.party_id ORDER BY cm.id LIMIT 1) AS member_subgroup
    $baseFromSql
    LEFT JOIN parties pty ON pty.id = m.party_id
    $whereSql
    ORDER BY $orderBy
    LIMIT :limit OFFSET :offset
";

$listStmt = $pdo->prepare($listSql);
bindParams($listStmt, $params);
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$members = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$memberIds = array_map(static fn($row) => (int)$row['pkey'], $members);
$addressesByMember = [];
$contactsByMember = [];

if (!empty($memberIds)) {
    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));

    $addressSql = "
        SELECT
            ma.member_key,
            GROUP_CONCAT(
                DISTINCT CONCAT_WS(
                    '',
                    COALESCE(atype.name, 'Address'),
                    ': ',
                    TRIM(CONCAT_WS(' ', NULLIF(a.housebldg, ''), NULLIF(a.street, ''), NULLIF(a.other_address_detail, ''))),
                    CASE WHEN c.city IS NOT NULL THEN CONCAT(', ', c.city) ELSE '' END,
                    CASE WHEN c.province IS NOT NULL AND c.province <> '' THEN CONCAT(', ', c.province) ELSE '' END,
                    CASE WHEN a.zippostal_code IS NOT NULL THEN CONCAT(' ', a.zippostal_code) ELSE '' END
                )
                SEPARATOR ' | '
            ) AS full_address
        FROM memberaddress ma
        LEFT JOIN addresses a
            ON a.pkey = ma.address_key
            AND a.status = 'A'
        LEFT JOIN cities c
            ON c.pkey = a.city_id
            AND c.status = 'A'
        LEFT JOIN addresstype atype
            ON atype.pkey = ma.address_type_key
            AND atype.status = 'A'
        WHERE ma.member_key IN ($placeholders)
          AND ma.status = 'A'
          AND (
              ma.dtaddressto IS NULL
              OR ma.dtaddressto = '0001-01-01'
              OR ma.dtaddressto >= CURDATE()
          )
        GROUP BY ma.member_key
    ";

    $addressStmt = $pdo->prepare($addressSql);
    foreach ($memberIds as $idx => $id) {
        $addressStmt->bindValue($idx + 1, $id, PDO::PARAM_INT);
    }
    $addressStmt->execute();

    foreach ($addressStmt->fetchAll(PDO::FETCH_ASSOC) as $addressRow) {
        $addressesByMember[(int)$addressRow['member_key']] = $addressRow['full_address'];
    }


    $contactSql = "
        SELECT
            mc.memberkey,
            GROUP_CONCAT(DISTINCT mc.contactdata ORDER BY mc.activefromdate DESC SEPARATOR ' / ') AS contact_numbers
        FROM membercontactchannels mc
        WHERE mc.memberkey IN ($placeholders)
          AND mc.status = 'A'
          AND (
              mc.activetodate IS NULL
              OR mc.activetodate >= CURDATE()
          )
        GROUP BY mc.memberkey
    ";

    $contactStmt = $pdo->prepare($contactSql);
    foreach ($memberIds as $idx => $id) {
        $contactStmt->bindValue($idx + 1, $id, PDO::PARAM_INT);
    }
    $contactStmt->execute();

    foreach ($contactStmt->fetchAll(PDO::FETCH_ASSOC) as $contactRow) {
        $contactsByMember[(int)$contactRow['memberkey']] = $contactRow['contact_numbers'];
    }

}

$rateCodes = $pdo->query("
    SELECT rc_id, rc_code, rc_name
    FROM rate_code
    ORDER BY rc_code ASC
")->fetchAll(PDO::FETCH_ASSOC);

$pipeSizes = $pdo->query("
    SELECT pipe_size_id, size_code, size_label, diameter_inches, is_active
    FROM water_pipe_sizes
    ORDER BY CASE WHEN diameter_inches IS NULL THEN 1 ELSE 0 END,
             diameter_inches, size_label, pipe_size_id
")->fetchAll(PDO::FETCH_ASSOC);
$activePipeSizes = array_values(array_filter($pipeSizes, static fn(array $row): bool => (int)$row['is_active'] === 1));

$defaultHalfInchPipeSizeId = null;
foreach ($activePipeSizes as $pipeSize) {
    $sizeCode = trim((string)($pipeSize['size_code'] ?? ''));
    $diameter = $pipeSize['diameter_inches'] !== null ? (float)$pipeSize['diameter_inches'] : null;
    if ($sizeCode === '1/2' || ($diameter !== null && abs($diameter - 0.5) < 0.00001)) {
        $defaultHalfInchPipeSizeId = (int)$pipeSize['pipe_size_id'];
        break;
    }
}

/*
 * Quick Assign uses one Rate Code + Pipe Size selector. Only combinations
 * with a complete tariff group are offered, preventing members from being
 * assigned to a configuration that would immediately cause a tariff error.
 */
$ratePipeCombinations = [];
$ratePipeCombinationMap = [];
$combinationCandidates = $pdo->query("
    SELECT DISTINCT
        r.rc_id,
        r.rc_code,
        r.rc_name,
        ps.pipe_size_id,
        ps.size_code,
        ps.size_label,
        ps.diameter_inches,
        ps.is_active
    FROM water_meter_members wr
    INNER JOIN rate_code r ON r.rc_id = wr.rc_id
    INNER JOIN water_pipe_sizes ps ON ps.pipe_size_id = wr.pipe_size_id
    WHERE ps.is_active = 1
    ORDER BY
        r.rc_code ASC,
        r.rc_name ASC,
        CASE WHEN ps.diameter_inches IS NULL THEN 1 ELSE 0 END,
        ps.diameter_inches ASC,
        ps.size_label ASC,
        ps.pipe_size_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($combinationCandidates as $candidate) {
    $candidateRcId = (int)$candidate['rc_id'];
    $candidatePipeSizeId = (int)$candidate['pipe_size_id'];

    try {
        $candidateTiers = water_rate_get_rates_for_configuration($pdo, $candidateRcId, $candidatePipeSizeId, false);
        water_rate_validate_tiers($candidateTiers, true, false);
    } catch (Throwable $e) {
        continue;
    }

    $assignmentValue = $candidateRcId . ':' . $candidatePipeSizeId;
    $pipeLabel = trim((string)($candidate['size_label'] ?? ''));
    if ($pipeLabel === '') {
        $pipeLabel = trim((string)($candidate['size_code'] ?? ''));
    }
    $displayLabel = trim((string)$candidate['rc_code']) . ' - ' . trim((string)$candidate['rc_name']) . ' + ' . $pipeLabel;

    $candidate['assignment_value'] = $assignmentValue;
    $candidate['display_label'] = $displayLabel;
    $ratePipeCombinations[] = $candidate;
    $ratePipeCombinationMap[$assignmentValue] = true;
}

$csrf = csrfToken();

$cities = $pdo->query("
    SELECT pkey, city, province
    FROM cities
    WHERE status = 'A'
    ORDER BY city ASC
")->fetchAll(PDO::FETCH_ASSOC);

$addressTypes = $pdo->query("
    SELECT pkey, name
    FROM addresstype
    WHERE status = 'A'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$zones = $pdo->query("
    SELECT DISTINCT zone
    FROM water_accounts
    WHERE zone IS NOT NULL
    ORDER BY zone ASC
")->fetchAll(PDO::FETCH_COLUMN);

$startRow = $totalRows > 0 ? $offset + 1 : 0;
$endRow = min($offset + $perPage, $totalRows);
$oppositeDirection = $direction === 'asc' ? 'desc' : 'asc';

$activeMembers = (int)$pdo->query("SELECT COUNT(*) FROM water_accounts WHERE status = 'A'")->fetchColumn();
$unassignedRateMembers = (int)$pdo->query("SELECT COUNT(*) FROM water_accounts WHERE rc_id IS NULL")->fetchColumn();
$unassignedPipeMembers = (int)$pdo->query("SELECT COUNT(*) FROM water_accounts WHERE pipe_size_id IS NULL")->fetchColumn();

$rateSummary = [];
foreach ($rateCodes as $rateCode) {
    $rateSummary[(int)$rateCode['rc_id']] = 0;
}

if (!empty($rateSummary)) {
    $summaryRows = $pdo->query("
        SELECT rc_id, COUNT(*) AS total
        FROM water_accounts
        WHERE rc_id IS NOT NULL
        GROUP BY rc_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($summaryRows as $row) {
        $rateSummary[(int)$row['rc_id']] = (int)$row['total'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Water Service Accounts - <?= e(app_name()) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <style>
        :root {
            --page-bg: #f4f6f8;
            --card: #ffffff;
            --page-text: #1f2937;
            --page-muted: #6b7280;
            --line: #e5e7eb;
            --dark: #111827;
            --blue: #2563eb;
            --green: #059669;
            --orange: #d97706;
            --red: #dc2626;
            --purple: #7c3aed;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--page-bg);
            color: var(--page-text);
            font-family: 'Sora', sans-serif;
        }

        .page { padding: 28px 34px; }

        .top {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 16px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .eyebrow {
            color: #9ca3af;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        h1 {
            margin: 4px 0;
            font-size: 24px;
        }

        .subtitle {
            color: var(--page-muted);
            font-size: 13px;
        }

        .btn {
            border: 0;
            border-radius: 8px;
            padding: 10px 14px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--dark);
            color: white;
            min-height: 38px;
            gap: 6px;
        }

        .btn.light {
            background: white;
            color: var(--dark);
            border: 1px solid var(--line);
        }

        .btn.blue { background: var(--blue); }
        .btn.green { background: var(--green); }
        .btn.red { background: var(--red); }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
            overflow: hidden;
            margin-bottom: 18px;
        }

        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-start;
        }

        .card-header > div { min-width: 0; }
        .card-header > div:last-child {
            flex: 1 1 420px;
            max-width: 680px;
        }

        .card-header h2 {
            margin: 0;
            font-size: 17px;
        }

        .card-header p {
            margin: 4px 0 0;
            color: var(--page-muted);
            font-size: 13px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        .stat-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }

        .stat-label {
            color: var(--page-muted);
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .stat-value {
            font-size: 22px;
            font-weight: 800;
            margin-top: 5px;
            color: var(--dark);
        }

        .stat-note {
            color: var(--page-muted);
            font-size: 11px;
            margin-top: 3px;
        }

        .filters { padding: 16px 20px; }

        .filter-grid {
            display: grid;
            grid-template-columns: 2fr repeat(6, minmax(130px, 1fr));
            gap: 12px;
            align-items: end;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        label {
            font-size: 12px;
            font-weight: 700;
            color: #4b5563;
        }

        input, select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 10px;
            font-size: 13px;
            background: white;
            font-family: inherit;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--dark);
            box-shadow: 0 0 0 3px rgba(17,24,39,.08);
        }

        .filter-actions, .bulk-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
            align-items: center;
        }

        .summary {
            padding: 12px 20px;
            border-top: 1px solid var(--line);
            background: #fafafa;
            color: var(--page-muted);
            font-size: 13px;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .member-table-shell { position: relative; }
        .table-wrap { overflow-x: auto; scroll-behavior: smooth; }
        .member-scroll-controls {
            position: sticky;
            top: calc(50vh - 24px);
            z-index: 25;
            height: 0;
            display: flex;
            justify-content: space-between;
            padding: 0 10px;
            pointer-events: none;
        }
        .member-scroll-btn {
            pointer-events: auto;
            width: 44px;
            height: 44px;
            border: 1px solid #d1d5db;
            border-radius: 50%;
            background: rgba(255,255,255,.96);
            color: var(--dark);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 8px 22px rgba(17,24,39,.18);
            transition: opacity .15s ease, transform .15s ease, background .15s ease;
        }
        .member-scroll-btn:hover { background: #f3f4f6; transform: scale(1.05); }
        .member-scroll-btn:focus-visible { outline: 3px solid rgba(37,99,235,.25); outline-offset: 2px; }
        .member-scroll-btn[hidden] { display: none; }
        .member-scroll-btn svg { width: 20px; height: 20px; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 1480px;
        }

        th, td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #fafafa;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .06em;
            font-size: 11px;
            white-space: nowrap;
        }

        th a {
            color: inherit;
            text-decoration: none;
        }

        tr:hover td { background: #fcfcfd; }

        .member-name {
            font-weight: 800;
            color: var(--page-text);
        }

        .muted {
            color: var(--page-muted);
            font-size: 12px;
            margin-top: 3px;
        }

        .address {
            color: #374151;
            line-height: 1.45;
            max-width: 420px;
            white-space: normal;
        }

        .contact-text {
            color: #374151;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            white-space: normal;
        }

        .mono {
            font-family: 'JetBrains Mono', monospace;
        }

        .badge {
            display: inline-flex;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .badge.active {
            background: #dcfce7;
            color: #166534;
        }

        .badge.inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge.rate {
            background: #ede9fe;
            color: #5b21b6;
        }

        .badge.none {
            background: #fef3c7;
            color: #92400e;
        }

        .sequence-form {
            display: grid;
            grid-template-columns: minmax(82px, 120px) auto;
            gap: 6px;
            align-items: center;
            min-width: 155px;
        }

        .sequence-form input {
            height: 36px;
            padding: 8px 9px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
        }

        .sequence-form .btn {
            padding: 8px 10px;
            min-height: 36px;
            font-size: 12px;
        }

        .quick-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 6px;
            align-items: center;
            width: 100%;
            min-width: 340px;
            max-width: 100%;
        }

        .quick-form select,
        .row-rate-pipe-select {
            width: 100%;
            min-width: 0;
            max-width: 100%;
            height: 36px;
            padding: 8px 32px 8px 10px;
            font-size: 12px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .quick-form .btn {
            padding: 8px 11px;
            min-height: 36px;
            font-size: 12px;
            white-space: nowrap;
        }

        .bulk-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: flex-end;
            flex-wrap: wrap;
            min-width: 0;
            max-width: 100%;
        }

        .bulk-rate-pipe-select {
            width: 100%;
            min-width: 0;
            max-width: 520px;
        }
        .bulk-actions .ds-select-enhanced {
            flex: 1 1 320px;
            width: min(520px, 100%);
            min-width: 0;
            max-width: 520px;
        }
        .bulk-actions .ds-select-control,
        .quick-form .ds-select-control {
            min-width: 0;
            max-width: 100%;
        }
        th:last-child,
        td:last-child {
            min-width: 360px;
            max-width: 430px;
        }

        .table-actions-note {
            color: var(--page-muted);
            font-size: 12px;
            margin-top: 6px;
        }

        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            flex-wrap: wrap;
        }

        .pages {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .page-link {
            min-width: 34px;
            height: 34px;
            border: 1px solid var(--line);
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: var(--page-text);
            background: white;
            font-size: 13px;
            font-weight: 700;
            padding: 0 10px;
        }

        .page-link.active {
            background: var(--dark);
            color: white;
            border-color: var(--dark);
        }

        .empty {
            text-align: center;
            padding: 34px;
            color: var(--page-muted);
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 20px;
        }

        .modal-box {
            background: #fff;
            border-radius: 16px;
            width: min(980px,100%);
            max-height: 90vh;
            overflow: auto;
            padding: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
        }

        .modal-head {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:16px;
            gap: 12px;
        }

        .modal-head h2 { margin: 0; }

        .modal-close {
            border:none;
            background:none;
            font-size:24px;
            cursor:pointer;
        }

        .modal-grid {
            display:grid;
            grid-template-columns:repeat(3,1fr);
            gap:14px;
        }

        .full { grid-column: 1 / -1; }

        .party-pick { border: 1px solid var(--line); border-radius: 10px; background: #f8fafc; padding: 12px 14px; margin-bottom: 16px; }
        .party-pick > label { display: block; margin-bottom: 6px; }
        .party-pick [hidden] { display: none !important; }
        .party-search-row { display: flex; gap: 8px; }
        .party-search-row input { flex: 1; min-width: 0; }
        .party-results { margin-top: 6px; border: 1px solid var(--line); border-radius: 8px; background: #fff; max-height: 240px; overflow-y: auto; }
        .party-results button { display: block; width: 100%; text-align: left; border: 0; border-bottom: 1px solid #eef1f5; background: #fff; padding: 8px 10px; cursor: pointer; font: inherit; font-size: 13px; color: inherit; }
        .party-results button:last-child { border-bottom: 0; }
        .party-results button:hover, .party-results button:focus { background: #e7f0ff; outline: 0; }
        .party-results .pr-meta, .party-chosen .pr-meta { display: block; color: var(--page-muted); font-size: 12px; margin-top: 2px; }
        .party-results .pr-none { padding: 8px 10px; color: var(--page-muted); font-size: 13px; }
        .party-chosen { margin-top: 8px; padding: 8px 10px; border-radius: 8px; border: 1px solid #b6d4fe; background: #e7f0ff; font-size: 13px; }
        .party-hint { margin: 8px 0 0; color: var(--page-muted); font-size: 12px; line-height: 1.45; }
        .party-matches { margin-top: 10px; padding: 10px 12px; border-radius: 8px; border: 1px solid #ffe69c; background: #fff8e1; font-size: 13px; color: #664d03; }
        .party-matches ul { margin: 6px 0 0; padding-left: 18px; }
        .party-matches li { margin: 4px 0; }
        .party-matches button { margin-left: 6px; border: 1px solid #c9d0d9; background: #fff; border-radius: 6px; padding: 2px 8px; font: inherit; font-size: 12px; cursor: pointer; }
        .party-distinct { display: flex; align-items: center; gap: 8px; margin-top: 10px; font-weight: 600; }
        .party-distinct input { width: auto; }
        .party-line a { color: #0b5ed7; text-decoration: none; }
        .party-line a:hover { text-decoration: underline; }

        .check-cell {
            width: 36px;
            text-align: center;
        }

        .check-cell input {
            width: auto;
            transform: scale(1.05);
        }

        @media (max-width: 1350px) {
            .filter-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 760px) {
            .page { padding: 20px; }

            .filter-grid, .modal-grid {
                grid-template-columns: 1fr;
            }

            .pagination {
                align-items: flex-start;
                flex-direction: column;
            }

            .top > div:last-child,
            .btn {
                width: 100%;
            }

            .quick-form {
                min-width: 100%;
                grid-template-columns: 1fr;
            }

            .quick-form select,
            .quick-form .btn,
            .bulk-rate-pipe-select,
            .bulk-actions .ds-select-enhanced {
                width: 100%;
                max-width: none;
            }
        }
    </style>
</head>
<body>
<?php require_once '../common/navbar.php'; ?>

<main class="main-content">
    <div class="page">

        <div class="top">
            <div>
                <h1>Water Service Accounts</h1>
                <div class="subtitle">
                    Water-service accounts of the cooperative's consumers. Each account belongs to a party; opening an account does not make the consumer a cooperative member.
                </div>
            </div>

            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button class="btn primary" type="button" onclick="openAddMemberModal()">Open Account</button>
                <a class="btn light" href="member_list.php">Reset List</a>
            </div>
        </div>

        <?php if (isset($_SESSION['success_msg'])): ?>
            <div style="background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;border-radius:10px;padding:12px 14px;margin-bottom:16px;font-weight:700;">
                <?= e($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_msg'])): ?>
            <div style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;border-radius:10px;padding:12px 14px;margin-bottom:16px;font-weight:700;">
                <?= e($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid" id="memberStats" data-live-results>
            <div class="stat-card">
                <div class="stat-label">Total accounts</div>
                <div class="stat-value"><?= e(number_format($totalRows)) ?></div>
                <div class="stat-note">Current filtered result</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Active accounts</div>
                <div class="stat-value"><?= e(number_format($activeMembers)) ?></div>
                <div class="stat-note">All active members</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">No Rate Code</div>
                <div class="stat-value"><?= e(number_format($unassignedRateMembers)) ?></div>
                <div class="stat-note">Must assign A/B/C/D before billing</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">No Pipe Size</div>
                <div class="stat-value"><?= e(number_format($unassignedPipeMembers)) ?></div>
                <div class="stat-note">Pipe size is required before billing</div>
            </div>

            <?php foreach ($rateCodes as $rateCode): ?>
                <div class="stat-card">
                    <div class="stat-label">Rate <?= e($rateCode['rc_code']) ?></div>
                    <div class="stat-value"><?= e(number_format($rateSummary[(int)$rateCode['rc_id']] ?? 0)) ?></div>
                    <div class="stat-note"><?= e($rateCode['rc_name']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Filters</h2>
                    <p>Search by account number, sequence, name, phone number, address, city, province, rate code, or pipe size.</p>
                </div>
            </div>

            <form method="GET" class="filters">
                <div class="filter-grid">
                    <div class="field">
                        <label>Search</label>
                        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Account no., sequence, name, phone, city, street, rate code, pipe size..." data-live-search aria-describedby="memberSummary">
                    </div>

                    <div class="field">
                        <label>Rate Code</label>
                        <select name="rc_id" data-searchable="true">
                            <option value="">All Rate Codes</option>
                            <option value="none" <?= $rateCodeId === 'none' ? 'selected' : '' ?>>No Rate Code Assigned</option>
                            <?php foreach ($rateCodes as $rateCode): ?>
                                <option value="<?= e($rateCode['rc_id']) ?>" <?= (string)$rateCodeId === (string)$rateCode['rc_id'] ? 'selected' : '' ?>>
                                    <?= e($rateCode['rc_code']) ?> - <?= e($rateCode['rc_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Pipe Size</label>
                        <select name="pipe_size_id" data-searchable="true">
                            <option value="">All Pipe Sizes</option>
                            <option value="none" <?= $pipeSizeId === 'none' ? 'selected' : '' ?>>No Pipe Size Assigned</option>
                            <?php foreach ($pipeSizes as $pipeSize): ?>
                                <option value="<?= e($pipeSize['pipe_size_id']) ?>" <?= (string)$pipeSizeId === (string)$pipeSize['pipe_size_id'] ? 'selected' : '' ?>>
                                    <?= e(water_pipe_size_format($pipeSize)) ?><?= (int)$pipeSize['is_active'] !== 1 ? ' [INACTIVE]' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Status</label>
                        <select name="status">
                            <option value="">All</option>
                            <option value="A" <?= $status === 'A' ? 'selected' : '' ?>>Active</option>
                            <option value="I" <?= $status === 'I' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="field">
                        <label>Gender</label>
                        <select name="gender">
                            <option value="">All</option>
                            <option value="Male" <?= $gender === 'Male' ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= $gender === 'Female' ? 'selected' : '' ?>>Female</option>
                        </select>
                    </div>

                    <div class="field">
                        <label>Civil Status</label>
                        <select name="civilstatus">
                            <option value="">All</option>
                            <?php foreach (['Single', 'Married', 'Widow', 'Widower', 'Separated'] as $option): ?>
                                <option value="<?= e($option) ?>" <?= $civilstatus === $option ? 'selected' : '' ?>>
                                    <?= e($option) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Zone</label>
                        <select name="zone">
                            <option value="">All</option>
                            <?php foreach ($zones as $zoneValue): ?>
                                <option value="<?= e($zoneValue) ?>" <?= (string)$zone === (string)$zoneValue ? 'selected' : '' ?>>
                                    Zone <?= e($zoneValue) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($subgroupReady): ?>
                    <div class="field">
                        <label>Address Sub-group</label>
                        <select name="subgroup" data-subgroup-select>
                            <option value="">All</option>
                            <option value="none" <?= $subgroup === 'none' ? 'selected' : '' ?>>No sub-group</option>
                            <?php foreach ($subgroupOptions as $sg): ?>
                                <option value="<?= e($sg) ?>" <?= $subgroup === $sg ? 'selected' : '' ?>><?= e($sg) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="field">
                        <label>Per Page</label>
                        <select name="per_page">
                            <?php foreach ([25, 50, 100, 200] as $option): ?>
                                <option value="<?= e($option) ?>" <?= $perPage === $option ? 'selected' : '' ?>>
                                    <?= e($option) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>City</label>
                        <select name="city_id" data-searchable="true">
                            <option value="">All Cities</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?= e($city['pkey']) ?>" <?= (string)$cityId === (string)$city['pkey'] ? 'selected' : '' ?>>
                                    <?= e($city['city']) ?><?= $city['province'] ? ', ' . e($city['province']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Address Type</label>
                        <select name="address_type_key">
                            <option value="">All Address Types</option>
                            <?php foreach ($addressTypes as $type): ?>
                                <option value="<?= e($type['pkey']) ?>" <?= (string)$addressTypeId === (string)$type['pkey'] ? 'selected' : '' ?>>
                                    <?= e($type['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <input type="hidden" name="sort" value="<?= e($sort) ?>">
                    <input type="hidden" name="direction" value="<?= e($direction) ?>">
                </div>

                <div class="filter-actions">
                    <button class="btn blue" type="submit">Apply Filters</button>
                    <a class="btn light" href="member_list.php">Clear</a>
                </div>
            </form>

            <div class="summary" id="memberSummary" data-live-results aria-live="polite">
                <div>
                    Showing <?= e($startRow) ?> to <?= e($endRow) ?> of <?= e(number_format($totalRows)) ?> account(s)
                </div>
                <div>
                    Page <?= e($page) ?> of <?= e($totalPages) ?>
                </div>
            </div>
        </div>

        <form action="../process/bulkUpdateMemberRateCode.php" method="POST" id="bulkRateForm" onsubmit="return confirmBulkUpdate(event);" style="display:none;">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="return_url" value="<?= e($_SERVER['REQUEST_URI'] ?? '../views/member_list.php') ?>">
        </form>

        <div class="card">
                <div class="card-header">
                    <div>
                        <h2>Accounts</h2>
                        <p>Assign a Rate Code + Pipe Size combination to each account.</p>
                    </div>

                    <div>
                        <div class="bulk-actions" style="margin-top:0;">
                        <select name="bulk_rate_pipe_assignment" form="bulkRateForm" class="bulk-rate-pipe-select" data-searchable="true" required>
                            <option value="">Rate Code + Pipe Size</option>
                            <?php foreach ($ratePipeCombinations as $combination): ?>
                                <option value="<?= e($combination['assignment_value']) ?>">
                                    <?= e($combination['display_label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn green" type="submit" form="bulkRateForm">Apply to Selected</button>
                        </div>
                        <?php if ($subgroupReady): ?>
                        <div class="bulk-actions" style="margin-top:8px;">
                        <select name="bulk_address_subgroup" form="bulkRateForm" class="bulk-rate-pipe-select" aria-label="Address sub-group for the checked accounts">
                            <option value="">Address sub-group</option>
                            <option value="__none__">No sub-group (clear it)</option>
                            <?php foreach ($subgroupOptions as $sg): ?>
                                <option value="<?= e($sg) ?>"><?= e($sg) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn light" type="submit" form="bulkRateForm" formaction="../process/updateMemberSubgroup.php" formnovalidate data-bulk="subgroup">Set for Selected</button>
                        <?= $subgroupManageBtn ?>
                        </div>
                        <?php endif; ?>
                        <div class="table-actions-note">Tip: row Save updates only that account; bulk apply updates the checked accounts.</div>
                    </div>
                </div>

                <div class="member-table-shell" id="memberTableShell">
                    <div class="member-scroll-controls" aria-label="Member table horizontal scrolling">
                        <button type="button" class="member-scroll-btn" id="memberScrollLeft" title="Scroll table left" aria-label="Scroll member table left" hidden>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                        </button>
                        <button type="button" class="member-scroll-btn" id="memberScrollRight" title="Scroll table right" aria-label="Scroll member table right" hidden>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                    </div>
                    <div class="table-wrap" id="memberTableScroll" data-live-results>
                    <table>
                        <thead>
                        <tr>
                            <th class="check-cell">
                                <input type="checkbox" id="selectAllMembers" onclick="toggleAllMembers(this)">
                            </th>
                            <th>
                                <a href="?<?= e(buildQueryString(['sort' => 'account_number', 'direction' => $sort === 'account_number' ? $oppositeDirection : 'asc', 'page' => 1])) ?>">
                                    Account No.
                                </a>
                            </th>
                            <th>
                                <a href="?<?= e(buildQueryString(['sort' => 'meter_sequence', 'direction' => $sort === 'meter_sequence' ? $oppositeDirection : 'asc', 'page' => 1])) ?>">
                                    Sequence
                                </a>
                            </th>
                            <th>
                                <a href="?<?= e(buildQueryString(['sort' => 'lastname', 'direction' => $sort === 'lastname' ? $oppositeDirection : 'asc', 'page' => 1])) ?>">
                                    Member Name
                                </a>
                            </th>
                            <th>
                                <a href="?<?= e(buildQueryString(['sort' => 'rate_code', 'direction' => $sort === 'rate_code' ? $oppositeDirection : 'asc', 'page' => 1])) ?>">
                                    Rate Code + Pipe Size
                                </a>
                            </th>
                            <th>
                                <a href="?<?= e(buildQueryString(['sort' => 'gender', 'direction' => $sort === 'gender' ? $oppositeDirection : 'asc', 'page' => 1])) ?>">
                                    Gender
                                </a>
                            </th>
                            <th>
                                <a href="?<?= e(buildQueryString(['sort' => 'civilstatus', 'direction' => $sort === 'civilstatus' ? $oppositeDirection : 'asc', 'page' => 1])) ?>">
                                    Civil Status
                                </a>
                            </th>
                            <th>Occupation</th>
                            <th>
                                <a href="?<?= e(buildQueryString(['sort' => 'zone', 'direction' => $sort === 'zone' ? $oppositeDirection : 'asc', 'page' => 1])) ?>">
                                    Zone
                                </a>
                            </th>
                            <?php if ($subgroupReady): ?><th>Address Sub-group</th><?php endif; ?>
                            <th>Phone</th>
                            <th>Address</th>
                            <th>
                                <a href="?<?= e(buildQueryString(['sort' => 'status', 'direction' => $sort === 'status' ? $oppositeDirection : 'asc', 'page' => 1])) ?>">
                                    Status
                                </a>
                            </th>
                            <th>Quick Assign</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php if (count($members) > 0): ?>
                            <?php foreach ($members as $member): ?>
                                <?php
                                    $memberAddress = $addressesByMember[(int)$member['pkey']] ?? '';
                                    $memberContact = $contactsByMember[(int)$member['pkey']] ?? '';
                                ?>
                                <tr>
                                    <td class="check-cell">
                                        <input type="checkbox" class="member-checkbox" name="member_ids[]" value="<?= e($member['pkey']) ?>" form="bulkRateForm">
                                    </td>

                                    <td>
                                        <div class="mono"><?= e($member['account_number']) ?></div>
                                    </td>

                                    <td>
                                        <form action="../process/updateMemberSequence.php" method="POST" class="sequence-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="member_id" value="<?= e($member['pkey']) ?>">
                                            <input type="hidden" name="return_url" value="<?= e($_SERVER['REQUEST_URI'] ?? '../views/member_list.php') ?>">
                                            <input type="text" name="meter_sequence" maxlength="50" value="<?= e($member['meter_sequence'] ?? '') ?>" placeholder="Optional" aria-label="Sequence for <?= e(fullName($member)) ?>">
                                            <button class="btn light" type="submit">Save</button>
                                        </form>
                                    </td>

                                    <td>
                                        <div class="member-name"><?= e(fullName($member)) ?></div>
                                        <?php if (!empty($member['party_id'])): ?>
                                            <div class="muted party-line">
                                                <?php if ($canOpenPartyCenter): ?><a href="<?= e(url('party.center', ['id' => (int)$member['party_id']])) ?>" title="Open the party in the Party / Member Center"><?= e($member['party_no']) ?></a><?php else: ?><?= e($member['party_no']) ?><?php endif; ?><?= (int)$member['party_is_member'] > 0 ? ', cooperative member' : '' ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="muted party-line">No party yet (made at the first posting)</div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if (!empty($member['rc_id'])): ?>
                                            <span class="badge rate">
                                                <?= e($member['rc_code']) ?> - <?= e($member['rc_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge none">No Rate Code</span>
                                        <?php endif; ?>
                                        <div style="margin-top:6px;">
                                            <?php if (!empty($member['pipe_size_id'])): ?>
                                                <span class="badge <?= (int)($member['pipe_size_is_active'] ?? 0) === 1 ? 'active' : 'inactive' ?>">
                                                    <?= e($member['pipe_size_label'] ?: $member['pipe_size_code']) ?>
                                                </span>
                                                <div class="muted"><?= e($member['pipe_size_code']) ?><?= $member['diameter_inches'] !== null ? ', ' . e(rtrim(rtrim(number_format((float)$member['diameter_inches'], 4, '.', ''), '0'), '.')) . ' in' : '' ?></div>
                                            <?php else: ?>
                                                <span class="badge none">No Pipe Size</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td><?= e($member['gender'] ?: '—') ?></td>
                                    <td><?= e($member['civilstatus'] ?: '—') ?></td>
                                    <td><?= e($member['occupation'] ?: '—') ?></td>
                                    <td><?= $member['zone'] !== null && $member['zone'] !== '' ? 'Zone ' . e($member['zone']) : '—' ?></td>
                                    <?php if ($subgroupReady): ?>
                                    <td>
                                        <?php if (($member['address_subgroup'] ?? '') !== ''): ?>
                                            <?= e($member['address_subgroup']) ?>
                                        <?php elseif (($member['member_subgroup'] ?? '') !== ''): ?>
                                            <span class="muted" title="Not set on this account; this is the address sub-group on the member's record">Member: <?= e($member['member_subgroup']) ?></span>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <?php endif; ?>

                                    <td>
                                        <div class="contact-text">
                                            <?= e($memberContact ?: '—') ?>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="address">
                                            <?= e($memberAddress ?: 'No active address found') ?>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="badge <?= $member['status'] === 'A' ? 'active' : 'inactive' ?>">
                                            <?= e(statusLabel($member['status'])) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <form action="../process/updateMemberRateCode.php" method="POST" class="quick-form">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="member_id" value="<?= e($member['pkey']) ?>">
                                            <input type="hidden" name="return_url" value="<?= e($_SERVER['REQUEST_URI'] ?? '../views/member_list.php') ?>">
                                            <?php
                                                $currentAssignmentValue = ((int)($member['rc_id'] ?? 0) > 0 && (int)($member['pipe_size_id'] ?? 0) > 0)
                                                    ? ((int)$member['rc_id'] . ':' . (int)$member['pipe_size_id'])
                                                    : '';
                                                $currentAssignmentIsAvailable = $currentAssignmentValue !== '' && isset($ratePipeCombinationMap[$currentAssignmentValue]);
                                            ?>
                                            <select name="rate_pipe_assignment" class="row-rate-pipe-select" required aria-label="Rate Code + Pipe Size for <?= e(fullName($member)) ?>">
                                                <option value="">Rate Code + Pipe Size</option>
                                                <?php if ($currentAssignmentValue !== '' && !$currentAssignmentIsAvailable): ?>
                                                    <option value="" selected disabled>Current assignment has no complete tariff</option>
                                                <?php endif; ?>
                                                <?php foreach ($ratePipeCombinations as $combination): ?>
                                                    <option value="<?= e($combination['assignment_value']) ?>" <?= $currentAssignmentValue === $combination['assignment_value'] ? 'selected' : '' ?>>
                                                        <?= e($combination['display_label']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn blue" type="submit">Save</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?= $subgroupReady ? 14 : 13 ?>" class="empty">
                                    No members found using the selected filters.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>

                <div class="pagination" id="memberPagination" data-live-results>
                    <div class="muted">
                        Showing <?= e($startRow) ?> to <?= e($endRow) ?> of <?= e(number_format($totalRows)) ?> account(s)
                    </div>

                    <div class="pages">
                        <?php if ($page > 1): ?>
                            <a class="page-link" href="?<?= e(buildQueryString(['page' => $page - 1])) ?>">Previous</a>
                        <?php endif; ?>

                        <?php
                        $window = 2;
                        $startPage = max(1, $page - $window);
                        $endPage = min($totalPages, $page + $window);
                        ?>

                        <?php if ($startPage > 1): ?>
                            <a class="page-link" href="?<?= e(buildQueryString(['page' => 1])) ?>">1</a>
                            <?php if ($startPage > 2): ?>
                                <span class="page-link">...</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <a class="page-link <?= $i === $page ? 'active' : '' ?>" href="?<?= e(buildQueryString(['page' => $i])) ?>">
                                <?= e($i) ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <span class="page-link">...</span>
                            <?php endif; ?>
                            <a class="page-link" href="?<?= e(buildQueryString(['page' => $totalPages])) ?>"><?= e($totalPages) ?></a>
                        <?php endif; ?>

                        <?php if ($page < $totalPages): ?>
                            <a class="page-link" href="?<?= e(buildQueryString(['page' => $page + 1])) ?>">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
        </div>

    </div>
</main>

<div class="modal-overlay" id="addMemberModal">
  <div class="modal-box">
    <div class="modal-head">
        <h2>Open Water-Service Account</h2>
        <button type="button" onclick="closeAddMemberModal()" class="modal-close">×</button>
    </div>

    <form action="../process/addMember.php" method="POST" id="addAccountForm">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <div class="party-pick">
        <label for="partySearch">Person or organization already on file</label>
        <div class="party-search-row">
          <input type="search" id="partySearch" placeholder="Search by name, party number, TIN, mobile or account number" autocomplete="off" aria-controls="partyResults">
          <button type="button" class="btn light" id="partyClear" hidden>Clear</button>
        </div>
        <input type="hidden" name="link_party_id" id="linkPartyId" value="">
        <div class="party-results" id="partyResults" role="listbox" hidden></div>
        <div class="party-chosen" id="partyChosen" hidden></div>
        <p class="party-hint">Choose the party when the consumer is already on file (a member, customer or supplier, or a consumer with another connection). Leave it empty to make a new party from the details below. Opening a water account does not make anyone a cooperative member.</p>
        <div class="party-matches" id="partyMatches" hidden></div>
        <label class="party-distinct"><input type="checkbox" name="distinct_party" value="1" id="distinctParty"> This is a different person (the details only look like a party on file)</label>
      </div>
      <div class="modal-grid">
        <div><label>First Name</label><input name="firstname" required></div>
        <div><label>Middle Name</label><input name="middlename"></div>
        <div><label>Last Name</label><input name="lastname" required></div>

        <div>
            <label>Gender</label>
            <select name="gender">
                <option value="">—</option>
                <option>Male</option>
                <option>Female</option>
            </select>
        </div>

        <div>
            <label>Civil Status</label>
            <select name="civilstatus">
                <option value="">—</option>
                <?php foreach (['Single', 'Married', 'Widow', 'Widower', 'Separated'] as $option): ?>
                    <option value="<?= e($option) ?>"><?= e($option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div><label>Date of Birth</label><input type="date" name="dateofbirth"></div>
        <div><label>Phone Number</label><input name="contact_number" placeholder="09XXXXXXXXX"></div>
        <div><label>Occupation</label><input name="occupation"></div>
        <div><label>Place of Birth</label><input name="placeofbirth"></div>
        <div><label>TIN No.</label><input name="tinno"></div>
        <div><label>Education</label><input name="education"></div>
        <div><label>Affiliated Org</label><input name="affiliatedOrg"></div>
        <div><label>Zone</label><input type="number" name="zone"></div>
        <?php if ($subgroupReady): ?>
        <div>
            <label>Address Sub-group</label>
            <select name="address_subgroup">
                <option value="">— (the member's, if a member)</option>
                <?php foreach ($subgroupOptions as $sg): ?>
                    <option value="<?= e($sg) ?>"><?= e($sg) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $subgroupManageBtn ?>
        </div>
        <?php endif; ?>
        <div>
            <label>Sequence (Optional)</label>
            <input type="text" name="meter_sequence" maxlength="50" placeholder="Example: 1, 1A, GROUP-2">
        </div>

        <div>
            <label>Rate Code / Classification</label>
            <select name="rc_id" data-searchable="true" required>
                <option value="">Choose Rate Code</option>
                <?php foreach ($rateCodes as $rateCode): ?>
                    <option value="<?= e($rateCode['rc_id']) ?>">
                        <?= e($rateCode['rc_code']) ?> - <?= e($rateCode['rc_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label>Pipe Size</label>
            <select name="pipe_size_id" data-searchable="true" required>
                <option value="">Choose Pipe Size</option>
                <?php foreach ($activePipeSizes as $pipeSize): ?>
                    <option value="<?= e($pipeSize['pipe_size_id']) ?>" <?= $defaultHalfInchPipeSizeId !== null && (int)$pipeSize['pipe_size_id'] === $defaultHalfInchPipeSizeId ? 'selected' : '' ?>><?= e(water_pipe_size_format($pipeSize)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div><label>Connection Date</label><input type="date" name="connection_date" value="<?= date('Y-m-d') ?>" required></div>

        <div><label>House/Bldg</label><input name="housebldg"></div>
        <div><label>Street</label><input name="street"></div>
        <div><label>Other Address Detail</label><input name="other_address_detail"></div>

        <div>
            <label>City</label>
            <select name="city_id" data-searchable="true" required>
                <?php foreach ($cities as $city): ?>
                    <option value="<?= e($city['pkey']) ?>">
                        <?= e($city['city']) ?><?= $city['province'] ? ', ' . e($city['province']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div><label>ZIP / Postal Code</label><input type="number" name="zippostal_code" value="0" required></div>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:18px; flex-wrap:wrap;">
        <button type="button" class="btn light" onclick="closeAddMemberModal()">Cancel</button>
        <button class="btn primary" type="submit" id="saveAccountBtn">Open Account</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAddMemberModal() {
    document.getElementById('addMemberModal').style.display = 'flex';
}

function closeAddMemberModal() {
    document.getElementById('addMemberModal').style.display = 'none';
}

/* ---- party on file: search, choose, and the duplicate check before an account opens a new party ---- */
(function () {
    const form = document.getElementById('addAccountForm');
    if (!form) return;
    const search = document.getElementById('partySearch');
    const results = document.getElementById('partyResults');
    const chosen = document.getElementById('partyChosen');
    const hidden = document.getElementById('linkPartyId');
    const clearBtn = document.getElementById('partyClear');
    const matches = document.getElementById('partyMatches');
    const distinct = document.getElementById('distinctParty');
    const endpoint = '../process/searchParties.php';
    let timer = null, seq = 0, checked = false;

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function describe(p) {
        const bits = [p.party_no];
        if (p.roles && p.roles.length) bits.push(p.roles.join(', '));
        if (p.water_accounts) bits.push(p.water_accounts + ' water account' + (p.water_accounts === 1 ? '' : 's'));
        if (p.status === 'inactive') bits.push('inactive');
        return bits.join('; ');
    }
    function choose(p) {
        hidden.value = p.id;
        chosen.innerHTML = '<strong>' + esc(p.display_name) + '</strong><span class="pr-meta">' + esc(describe(p)) + '. The new account is added to this party.</span>';
        chosen.hidden = false; results.hidden = true; clearBtn.hidden = false; matches.hidden = true;
        search.value = p.display_name;
        distinct.checked = false; distinct.parentElement.hidden = true;
    }
    function unchoose() {
        hidden.value = ''; chosen.hidden = true; clearBtn.hidden = true; search.value = '';
        distinct.parentElement.hidden = false; checked = false;
    }
    window.wbChooseParty = choose;
    clearBtn.addEventListener('click', unchoose);
    search.addEventListener('input', function () {
        if (hidden.value) { hidden.value = ''; chosen.hidden = true; clearBtn.hidden = true; distinct.parentElement.hidden = false; }
        clearTimeout(timer);
        const q = search.value.trim();
        if (q.length < 2) { results.hidden = true; return; }
        timer = setTimeout(function () {
            const mine = ++seq;
            fetch(endpoint + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(r => r.json()).then(function (data) {
                    if (mine !== seq) return;
                    const list = (data && data.parties) || [];
                    results.innerHTML = list.length
                        ? list.map((p, i) => '<button type="button" role="option" data-i="' + i + '">' + esc(p.display_name) + '<span class="pr-meta">' + esc(describe(p)) + '</span></button>').join('')
                        : '<div class="pr-none">No party on file matches. Leave the field empty to make a new party.</div>';
                    results.hidden = false;
                    results.querySelectorAll('button[data-i]').forEach(b => b.addEventListener('click', () => choose(list[+b.dataset.i])));
                }).catch(function () { results.hidden = true; });
        }, 250);
    });

    // before a new party is made: is the consumer already on file?
    form.addEventListener('submit', function (ev) {
        if (hidden.value || distinct.checked || checked) return;
        ev.preventDefault();
        const fd = new FormData();
        ['firstname', 'middlename', 'lastname', 'tinno', 'contact_number', 'dateofbirth'].forEach(n => fd.append(n, (form.elements[n] && form.elements[n].value) || ''));
        fd.append('csrf_token', form.elements['csrf_token'].value);
        fd.append('check', '1');
        fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(r => r.json()).then(function (data) {
                const list = (data && data.parties) || [];
                if (!data || data.ok === false) { checked = true; form.requestSubmit ? form.requestSubmit() : form.submit(); return; }
                if (!list.length) { checked = true; form.requestSubmit ? form.requestSubmit() : form.submit(); return; }
                matches.innerHTML = '<strong>This consumer may already be on file.</strong> Choose the party, or tick "This is a different person" and open the account again.<ul>'
                    + list.map((p, i) => '<li>' + esc(p.display_name) + ' (' + esc(describe(p)) + (p.basis && p.basis.length ? '; ' + esc(p.basis.join(', ').toLowerCase()) : '') + ')<button type="button" data-i="' + i + '">Use this party</button></li>').join('') + '</ul>';
                matches.hidden = false;
                matches.querySelectorAll('button[data-i]').forEach(b => b.addEventListener('click', () => choose(list[+b.dataset.i])));
                matches.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }).catch(function () { checked = true; form.submit(); });
    });

    // the form as it was sent, when the server sent it back
    const restore = <?= json_encode($addAccountForm, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    if (restore) {
        Object.keys(restore.values || {}).forEach(function (k) {
            const el = form.elements[k];
            if (el && el.type !== 'hidden' && el.type !== 'checkbox') el.value = restore.values[k];
        });
        if (restore.party) choose(restore.party);
        if (restore.matches && restore.matches.length) {
            matches.innerHTML = '<strong>This consumer may already be on file.</strong> Choose the party, or tick "This is a different person" and open the account again.<ul>'
                + restore.matches.map((p, i) => '<li>' + esc(p.display_name) + ' (' + esc(describe(p)) + ')<button type="button" data-i="' + i + '">Use this party</button></li>').join('') + '</ul>';
            matches.hidden = false;
            matches.querySelectorAll('button[data-i]').forEach(b => b.addEventListener('click', () => choose(restore.matches[+b.dataset.i])));
        }
        openAddMemberModal();
    }
})();

function toggleAllMembers(master) {
    document.querySelectorAll('.member-checkbox').forEach(cb => {
        cb.checked = master.checked;
    });
}

document.addEventListener('submit', function (event) {
    const form = event.target;

    if (!form.classList || !form.classList.contains('quick-form')) {
        return;
    }

    const assignmentSelect = form.querySelector('select[name="rate_pipe_assignment"]');

    if (!assignmentSelect || !assignmentSelect.value) {
        event.preventDefault();
        alert('Please choose a Rate Code + Pipe Size for this member.');
    }
});

(function setupMemberTableHorizontalControls() {
    const scroller = document.getElementById('memberTableScroll');
    const leftButton = document.getElementById('memberScrollLeft');
    const rightButton = document.getElementById('memberScrollRight');
    if (!scroller || !leftButton || !rightButton) return;

    function updateButtons() {
        const maxScroll = Math.max(0, scroller.scrollWidth - scroller.clientWidth);
        const hasOverflow = maxScroll > 4;
        leftButton.hidden = !hasOverflow || scroller.scrollLeft <= 4;
        rightButton.hidden = !hasOverflow || scroller.scrollLeft >= maxScroll - 4;
    }

    function scrollTable(direction) {
        const distance = Math.max(360, Math.round(scroller.clientWidth * 0.72));
        scroller.scrollBy({ left: direction * distance, behavior: 'smooth' });
    }

    leftButton.addEventListener('click', () => scrollTable(-1));
    rightButton.addEventListener('click', () => scrollTable(1));
    scroller.addEventListener('scroll', updateButtons, { passive: true });
    window.addEventListener('resize', updateButtons);
    requestAnimationFrame(updateButtons);
})();

function confirmBulkUpdate(event) {
    const checkedBoxes = document.querySelectorAll('.member-checkbox:checked');
    const assignmentSelect = document.querySelector('select[name="bulk_rate_pipe_assignment"][form="bulkRateForm"]');
    const submitter = event && event.submitter;

    if (checkedBoxes.length <= 0) {
        alert('Please select at least one account.');
        return false;
    }

    if (submitter && submitter.dataset.bulk === 'subgroup') {
        const sg = document.querySelector('select[name="bulk_address_subgroup"][form="bulkRateForm"]');
        if (!sg || !sg.value) {
            alert('Choose the address sub-group to set.');
            return false;
        }
        const form = document.getElementById('bulkRateForm');
        const what = sg.value === '__none__' ? 'Clear the address sub-group of ' : 'Set the address sub-group "' + sg.options[sg.selectedIndex].text.trim() + '" on ';
        AppDialog.confirm(what + checkedBoxes.length + ' selected account(s)?', { title: 'Address sub-group', ok: 'Apply' }).then((yes) => {
            if (!yes || !form) return;
            const keep = form.action;
            form.action = '../process/updateMemberSubgroup.php';
            form.submit();
            form.action = keep;
        });
        return false;
    }

    if (!assignmentSelect || !assignmentSelect.value) {
        alert('Please choose a Rate Code + Pipe Size for the selected members.');
        return false;
    }

    const selectedText = assignmentSelect.options[assignmentSelect.selectedIndex]?.text || 'selected Rate Code + Pipe Size';
    const form = document.getElementById('bulkRateForm');
    AppDialog.confirm('Apply ' + selectedText.trim() + ' to ' + checkedBoxes.length + ' selected account(s)?', { title: 'Change rate code and pipe size', ok: 'Apply' })
        .then((yes) => { if (yes && form) form.submit(); });
    return false;
}
</script>
<?php if ($subgroupManage): ?><script src="<?= e(asset('js/subgroup_manager.js')) ?>"></script><?php endif; ?>

</body>
</html>
