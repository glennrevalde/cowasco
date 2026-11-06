<?php
require_once __DIR__ . '/../database/config.php';

require_once '../database/config.php';
require_once __DIR__ . '/../common/operationTokenHelpers.php';
require_once __DIR__ . '/../common/waterPipeSizeHelpers.php';
require_once __DIR__ . '/../common/waterRateBillingHelpers.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */

require_once 'sysSequenceHelpers.php';

$pdo = getDB();

function back_member($msg, $type = 'error', ?array $form = null) {
    $_SESSION[$type === 'success' ? 'success_msg' : 'error_msg'] = $msg;
    if ($form !== null) {
        // the Open Account form comes back filled in, so nothing has to be typed again
        $_SESSION['wb_add_account_form'] = $form;
    }
    header('Location: ../views/member_list.php');
    exit;
}

/** The fields of the form as typed (no token), to fill the form again */
function member_form_values(): array
{
    $keep = ['firstname', 'middlename', 'lastname', 'gender', 'civilstatus', 'dateofbirth', 'contact_number', 'occupation', 'placeofbirth', 'tinno',
        'education', 'affiliatedOrg', 'zone', 'meter_sequence', 'rc_id', 'pipe_size_id', 'connection_date', 'housebldg', 'street', 'other_address_detail',
        'city_id', 'zippostal_code', 'address_subgroup'];
    $out = [];
    foreach ($keep as $k) {
        if (isset($_POST[$k]) && is_scalar($_POST[$k])) {
            $out[$k] = mb_substr((string)$_POST[$k], 0, 200);
        }
    }
    return $out;
}

/** A party as the form shows it */
function member_party_view(array $p, array $basis = []): array
{
    $roles = Party::roleNamesAll([(int)$p['id']])[(int)$p['id']] ?? [];
    return [
        'id' => (int)$p['id'], 'party_no' => (string)$p['party_no'], 'display_name' => (string)$p['display_name'], 'status' => (string)$p['status'],
        'roles' => array_values(array_filter($roles, static fn($x) => stripos((string)$x, 'water') === false)),
        'water_accounts' => (int)DB::value('SELECT COUNT(*) FROM water_accounts WHERE party_id = :p', ['p' => $p['id']]),
        'basis' => $basis,
    ];
}

function member_table_exists(PDO $pdo, string $table): bool
{
    return sysseq_table_exists($pdo, $table);
}

function member_column_exists(PDO $pdo, string $table, string $column): bool
{
    return sysseq_column_exists($pdo, $table, $column);
}

function member_valid_date(?string $date): ?string
{
    $date = trim((string)$date);

    if ($date === '') {
        return null;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception('Invalid date format. Use YYYY-MM-DD.');
    }

    [$y, $m, $d] = array_map('intval', explode('-', $date));

    if (!checkdate($m, $d, $y)) {
        throw new Exception('Invalid date value.');
    }

    return $date;
}

function member_next_pkey_from_syssequence(PDO $pdo): int
{
    $maxMemberId = 0;

    try {
        $stmt = $pdo->query("SELECT COALESCE(MAX(pkey), 0) FROM water_accounts");
        $maxMemberId = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $maxMemberId = 0;
    }

    return sysseq_next_numeric($pdo, 'SysMemberIDSeq', $maxMemberId);
}

function member_next_account_number(PDO $pdo, int $memberId): string
{
    /*
     * account_number is separate from members.pkey in your current system.
     * Keep the existing style: numeric account numbers start from 50001.
     * If a collision happens, advance until an unused number is found.
     */
    $stmt = $pdo->query("
        SELECT COALESCE(MAX(CAST(account_number AS UNSIGNED)), 50000)
        FROM water_accounts
        WHERE account_number REGEXP '^[0-9]+$'
    ");

    $candidate = (int)$stmt->fetchColumn() + 1;
    $candidate = max($candidate, 50001);

    $check = $pdo->prepare("SELECT COUNT(*) FROM water_accounts WHERE account_number = ?");

    for ($i = 0; $i < 100; $i++) {
        $check->execute([(string)$candidate]);
        if ((int)$check->fetchColumn() === 0) {
            return (string)$candidate;
        }

        $candidate++;
    }

    /*
     * Final fallback. This should almost never happen.
     */
    return (string)$memberId;
}

function member_contact_channel_key(PDO $pdo): int
{
    if (!member_table_exists($pdo, 'contactchannels')) {
        return 0;
    }

    $stmt = $pdo->query("
        SELECT pkey
        FROM contactchannels
        WHERE status = 'A'
          AND (
                LOWER(name) IN ('mobile', 'phone', 'contact', 'cellphone', 'telephone')
                OR LOWER(description) LIKE '%phone%'
                OR LOWER(description) LIKE '%mobile%'
                OR LOWER(description) LIKE '%contact%'
          )
        ORDER BY
            CASE
                WHEN LOWER(name) = 'mobile' THEN 1
                WHEN LOWER(name) = 'phone' THEN 2
                ELSE 3
            END,
            pkey ASC
        LIMIT 1
    ");

    $key = (int)$stmt->fetchColumn();

    if ($key > 0) {
        return $key;
    }

    $insert = $pdo->prepare("
        INSERT INTO contactchannels (name, description, datecreated, status)
        VALUES ('Mobile', 'Mobile Number', NOW(), 'A')
    ");
    $insert->execute();

    return (int)$pdo->lastInsertId();
}

function member_insert_contact_if_applicable(PDO $pdo, int $memberId, string $phoneNumber, string $activeFrom): bool
{
    $phoneNumber = trim($phoneNumber);

    if ($phoneNumber === '') {
        return false;
    }

    if (!member_table_exists($pdo, 'contactchannels') || !member_table_exists($pdo, 'membercontactchannels')) {
        return false;
    }

    if (!preg_match('/^[0-9+()\\-\\s]{5,50}$/', $phoneNumber)) {
        throw new Exception('Phone number contains invalid characters.');
    }

    $channelKey = member_contact_channel_key($pdo);

    if ($channelKey <= 0) {
        return false;
    }

    $stmt = $pdo->prepare("
        INSERT INTO membercontactchannels
            (conchannelkey, memberkey, activefromdate, activetodate, contactdata, status)
        VALUES
            (?, ?, ?, NULL, ?, 'A')
        ON DUPLICATE KEY UPDATE
            contactdata = VALUES(contactdata),
            activetodate = NULL,
            status = 'A'
    ");
    $stmt->execute([$channelKey, $memberId, $activeFrom, $phoneNumber]);

    return true;
}

function member_insert_address_if_applicable(PDO $pdo, int $memberId, string $membershipDate, int $cityId, ?string $housebldg, ?string $street, ?string $other, int $zip): bool
{
    if (!member_table_exists($pdo, 'addresses') || !member_table_exists($pdo, 'memberaddress')) {
        return false;
    }

    $addr = $pdo->prepare("
        INSERT INTO addresses
            (city_id, housebldg, street, other_address_detail, zippostal_code, status)
        VALUES
            (?, ?, ?, ?, ?, 'A')
    ");
    $addr->execute([$cityId, $housebldg ?: null, $street ?: null, $other ?: null, $zip]);

    $addressId = (int)$pdo->lastInsertId();

    $ma = $pdo->prepare("
        INSERT INTO memberaddress
            (member_key, address_key, dtaddressFrom, address_type_key, status)
        VALUES
            (?, ?, ?, ?, 'A')
    ");
    $ma->execute([$memberId, $addressId, $membershipDate, 1]);

    return true;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        back_member('Invalid request method.');
    }
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        back_member('The form session expired. Reload the page and try again.');
    }

    $firstname = trim($_POST['firstname'] ?? '');
    $middlename = trim($_POST['middlename'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $civilstatus = trim($_POST['civilstatus'] ?? '');
    $dateofbirth = member_valid_date($_POST['dateofbirth'] ?? ($_POST['birthdate'] ?? ''));
    $placeofbirth = trim($_POST['placeofbirth'] ?? '');
    $tinno = trim($_POST['tinno'] ?? '');
    $education = trim($_POST['education'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');
    $affiliatedOrg = trim($_POST['affiliatedOrg'] ?? '');
    $phoneNumber = trim($_POST['contact_number'] ?? $_POST['phone_number'] ?? '');
    $zone = ($_POST['zone'] ?? '') !== '' ? (int)$_POST['zone'] : null;
    $meterSequence = trim((string)($_POST['meter_sequence'] ?? ''));
    $meterSequence = $meterSequence !== '' ? $meterSequence : null;
    $rc_id = ($_POST['rc_id'] ?? '') !== '' ? (int)$_POST['rc_id'] : null;
    $pipe_size_id = ($_POST['pipe_size_id'] ?? '') !== '' ? (int)$_POST['pipe_size_id'] : null;
    if (!$pipe_size_id) {
        $halfInchStmt = $pdo->query("
            SELECT pipe_size_id
            FROM water_pipe_sizes
            WHERE is_active = 1
              AND (size_code = '1/2' OR diameter_inches = 0.5000)
            ORDER BY CASE WHEN size_code = '1/2' THEN 0 ELSE 1 END, pipe_size_id
            LIMIT 1
        ");
        $halfInchPipeSizeId = (int)$halfInchStmt->fetchColumn();
        $pipe_size_id = $halfInchPipeSizeId > 0 ? $halfInchPipeSizeId : null;
    }
    // the date the connection starts (the former form called it the membership date; a water account is not a membership)
    $membershipDate = member_valid_date($_POST['connection_date'] ?? ($_POST['membershipDate'] ?? date('Y-m-d'))) ?: date('Y-m-d');
    $linkPartyId = (int)($_POST['link_party_id'] ?? 0) ?: null;
    $distinctParty = (string)($_POST['distinct_party'] ?? '') === '1';
    if ($linkPartyId !== null) {
        $chosenParty = Party::live($linkPartyId);
        if (!$chosenParty || $chosenParty['status'] === 'merged') {
            throw new Exception('The party chosen is no longer on file. Search for it again.');
        }
        if ($chosenParty['status'] !== 'active') {
            throw new Exception('Party ' . $chosenParty['party_no'] . ' is inactive. Make it active again on the Party / Member Center first.');
        }
        $linkPartyId = (int)$chosenParty['id'];
    }
    $housebldg = trim($_POST['housebldg'] ?? '');
    $street = trim($_POST['street'] ?? '');
    $city_id = (int)($_POST['city_id'] ?? 1);
    $zip = (int)($_POST['zippostal_code'] ?? 0);
    $other = trim($_POST['other_address_detail'] ?? '');
    $createdBy = $_SESSION['username'] ?? 'admin';

    if ($firstname === '' || $lastname === '') {
        throw new Exception('First name and last name are required.');
    }

    if ($meterSequence !== null) {
        if (strlen($meterSequence) > 50) {
            throw new Exception('Sequence must not exceed 50 characters.');
        }

        if (!preg_match('~^[A-Za-z0-9 ._/-]+$~', $meterSequence)) {
            throw new Exception('Sequence may contain only letters, numbers, spaces, periods, underscores, slashes, and hyphens.');
        }
    }

    if ($gender !== '' && !in_array($gender, ['Male', 'Female'], true)) {
        throw new Exception('Invalid gender selected.');
    }

    if ($civilstatus !== '' && !in_array($civilstatus, ['Single', 'Married', 'Widow', 'Widower', 'Separated'], true)) {
        throw new Exception('Invalid civil status selected.');
    }

    if (!$rc_id) {
        throw new Exception('Rate Code is required. Please classify the member as Residential/Commercial and Regular/Associate.');
    }

    $rateCheck = $pdo->prepare("SELECT COUNT(*) FROM rate_code WHERE rc_id = ?");
    $rateCheck->execute([$rc_id]);

    if ((int)$rateCheck->fetchColumn() === 0) {
        throw new Exception('Invalid Rate Code selected.');
    }
    if (!$pipe_size_id) {
        throw new Exception('Pipe Size is required for water billing.');
    }
    water_pipe_size_require_active($pdo, $pipe_size_id);
    $tariff = water_rate_get_rates_for_configuration($pdo, $rc_id, $pipe_size_id);
    water_rate_validate_tiers($tariff, true, false);

    if ($city_id <= 0) {
        throw new Exception('City is required.');
    }

    $cityCheck = $pdo->prepare("SELECT COUNT(*) FROM cities WHERE pkey = ? AND status = 'A'");
    $cityCheck->execute([$city_id]);

    if ((int)$cityCheck->fetchColumn() === 0) {
        throw new Exception('Invalid city selected.');
    }

    $pdo->beginTransaction();

    /*
     * Official member ID source:
     * syssequence.SeqName = SysMemberIDSeq
     *
     * This replaces the unsafe MAX(pkey) + 1 approach.
     */
    $memberId = member_next_pkey_from_syssequence($pdo);
    $accountNumber = member_next_account_number($pdo, $memberId);

    /*
     * Correct members table columns:
     * - dateofbirth, not birthdate
     * - phone is not stored in members
     * - phone goes to membercontactchannels if applicable
     */
    $stmt = $pdo->prepare("
        INSERT INTO water_accounts
            (pkey, salutation, lastname, firstname, middlename, dateofbirth, placeofbirth, tinno,
             gender, civilstatus, education, occupation, affiliatedOrg,
             datecreated, createdby, status, account_number, zone, meter_sequence, rc_id, pipe_size_id)
        VALUES
            (?, NULL, ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?,
             NOW(), ?, 'A', ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $memberId,
        $lastname,
        $firstname,
        $middlename ?: null,
        $dateofbirth,
        $placeofbirth ?: null,
        $tinno ?: null,
        $gender ?: null,
        $civilstatus ?: null,
        $education ?: null,
        $occupation ?: null,
        $affiliatedOrg ?: null,
        $createdBy,
        $accountNumber,
        $zone,
        $meterSequence,
        $rc_id,
        $pipe_size_id
    ]);

    // the date the connection started (upgrade 2026_12_27), shown on the member's Water Defaults too
    if (member_column_exists($pdo, 'water_accounts', 'connection_date')) {
        $pdo->prepare('UPDATE water_accounts SET connection_date = ? WHERE pkey = ?')->execute([$membershipDate, $memberId]);
    }

    $fullName = trim(trim($lastname . ', ' . $firstname, ', ') . ' ' . $middlename);

    // A water-service account is not a cooperative membership: nothing is written to the membership register
    // (membershipinfo is the former membership register, kept read-only). Membership is applied for in the Cooperative menu.

    $insertedAddress = member_insert_address_if_applicable(
        $pdo,
        $memberId,
        $membershipDate,
        $city_id,
        $housebldg,
        $street,
        $other,
        $zip
    );

    $insertedContact = member_insert_contact_if_applicable($pdo, $memberId, $phoneNumber, $membershipDate);

    // The party of the account: the one chosen, or a new party made from these details once it is clear the
    // consumer is not already on file (the receivables post to the party's customer record at the first bill).
    if ($linkPartyId === null && !$distinctParty && Party::ready()) {
        $fields = WaterAccounts::partyFields(WaterAccounts::get($memberId) ?? []);
        $fields['mobile'] = $phoneNumber;
        $cands = array_slice(Party::candidatesFor($fields), 0, 6, true);
        if ($cands) {
            $pdo->rollBack();
            back_member(
                Party::candidatesMessage($cands, 'consumer', 'This is a different person') . ' Nothing was saved.',
                'error',
                ['values' => member_form_values(), 'matches' => array_values(array_map(static fn($c) => member_party_view($c['party'], $c['basis']), $cands))]
            );
        }
    }
    $partyId = Party::ready()
        ? WaterAccounts::ensureParty($memberId, $linkPartyId, $linkPartyId ? 'Water-service account opened for a party on file' : 'Water-service account opened')
        : null;
    $party = $partyId ? Party::get($partyId) : null;

    // the address sub-group (the list of the member form): the one chosen, else the member's when the party is one
    $subgroup = null;
    if (Members::waterSubgroupReady()) {
        $subgroup = trim((string)($_POST['address_subgroup'] ?? ''));
        if ($subgroup !== '' && !in_array($subgroup, Members::listNames('address_subgroup'), true)) {
            throw new RuntimeException('"' . $subgroup . '" is not an active address sub-group.');
        }
        if ($subgroup === '' && $partyId) {
            $subgroup = (string)DB::value("SELECT address_subgroup FROM members WHERE party_id = :p AND address_subgroup IS NOT NULL AND address_subgroup <> '' ORDER BY id LIMIT 1", ['p' => $partyId]);
        }
        $subgroup = $subgroup !== '' ? $subgroup : null;
        if ($subgroup !== null) {
            $pdo->prepare('UPDATE water_accounts SET address_subgroup = ? WHERE pkey = ?')->execute([$subgroup, $memberId]);
        }
    }

    // a cooperative member's first water-service account is the member's own (Member Information > Water Defaults)
    if ($partyId && class_exists('MemberDefaults') && MemberDefaults::ready()) {
        $pdo->prepare('UPDATE members SET water_account_id = ? WHERE party_id = ? AND water_account_id IS NULL')->execute([$memberId, $partyId]);
    }

    $pdo->commit();

    $details = [];
    $details[] = 'Account No: ' . $accountNumber;
    if ($meterSequence !== null) {
        $details[] = 'Sequence: ' . $meterSequence;
    }
    if ($subgroup !== null) {
        $details[] = 'Address sub-group: ' . $subgroup;
    }
    if ($party) {
        $details[] = ($linkPartyId ? 'Added to party ' : 'New party ') . $party['party_no'] . ' (' . $party['display_name'] . ')';
    }

    if ($insertedAddress) {
        $details[] = 'Address saved';
    }

    if ($insertedContact) {
        $details[] = 'Phone saved: ' . $phoneNumber;
    }

    back_member('Water-service account opened. ' . implode('. ', $details) . '.', 'success');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $restoreParty = null;
    if (!empty($linkPartyId) && ($p = Party::get((int)$linkPartyId))) {
        $restoreParty = member_party_view($p);
    }
    back_member('The account was not opened: ' . $e->getMessage(), 'error', ['values' => member_form_values(), 'party' => $restoreParty]);
}
