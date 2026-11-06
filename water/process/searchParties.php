<?php
/**
 * Parties on file, for the Open Account form of Water Service Accounts.
 *
 *   GET  ?q=text              parties whose name, party number, TIN, mobile or water account number match (typeahead)
 *   POST check=1 + details    parties the new consumer may already be (same TIN, mobile, name and date of birth, name)
 *
 * Answers JSON: {"ok":true,"parties":[{id, party_no, display_name, status, roles[], water_accounts, basis[]}]}.
 * Rights: those of Water Service Accounts (the page the form is on). Nothing is changed here.
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo, 'member_list');
unset($__processGuardPdo);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sp_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** The rows the form shows: number, name, roles and how many water accounts the party already has */
function sp_rows(array $rows, array $basis = []): array
{
    if (!$rows) {
        return [];
    }
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $roles = Party::roleNamesAll($ids);
    [$in, $ip] = DB::in('wp', $ids);
    $water = DB::pairs("SELECT party_id, COUNT(*) FROM water_accounts WHERE party_id IN $in GROUP BY party_id", $ip);
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $out[] = [
            'id' => $id,
            'party_no' => (string)$r['party_no'],
            'display_name' => (string)$r['display_name'],
            'status' => (string)$r['status'],
            'roles' => array_values(array_filter($roles[$id] ?? [], static fn($x) => stripos((string)$x, 'water') === false)),
            'water_accounts' => (int)($water[$id] ?? 0),
            'basis' => $basis[$id] ?? [],
        ];
    }
    return $out;
}

try {
    if (!Party::ready()) {
        sp_out(['ok' => true, 'parties' => []]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
            sp_out(['ok' => false, 'code' => 'csrf_invalid', 'message' => 'The form session expired. Reload the page and try again.'], 403);
        }
        $first = trim((string)($_POST['firstname'] ?? ''));
        $middle = trim((string)($_POST['middlename'] ?? ''));
        $last = trim((string)($_POST['lastname'] ?? ''));
        if ($first === '' || $last === '') {
            sp_out(['ok' => true, 'parties' => []]);
        }
        $mi = $middle !== '' ? ' ' . mb_substr($middle, 0, 1) . '.' : '';
        $birth = trim((string)($_POST['dateofbirth'] ?? ''));
        $fields = [
            'party_type' => 'person',
            'legal_name' => Party::personName($first, $middle, $last, null),
            'display_name' => $last . ', ' . $first . $mi,
            'tin' => (string)($_POST['tinno'] ?? ''),
            'mobile' => (string)($_POST['contact_number'] ?? ''),
            'birth_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth) ? $birth : null,
        ];
        $cands = array_slice(Party::candidatesFor($fields), 0, 6, true);
        $rows = array_map(static fn($c) => $c['party'], $cands);
        $basis = array_map(static fn($c) => $c['basis'], $cands);
        sp_out(['ok' => true, 'parties' => sp_rows(array_values($rows), $basis)]);
    }

    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        sp_out(['ok' => true, 'parties' => []]);
    }
    $like = '%' . $q . '%';
    $rows = DB::all(
        "SELECT p.id, p.party_no, p.display_name, p.status
           FROM parties p
          WHERE p.status IN ('active', 'inactive')
            AND (p.party_no LIKE :q1 OR p.legal_name LIKE :q2 OR p.display_name LIKE :q3 OR p.tin LIKE :q4 OR p.mobile LIKE :q5
                 OR p.id IN (SELECT party_id FROM members WHERE member_no LIKE :q6)
                 OR p.id IN (SELECT party_id FROM water_accounts WHERE account_number LIKE :q7))
          ORDER BY p.status = 'active' DESC, p.display_name, p.id
          LIMIT 20",
        ['q1' => $q . '%', 'q2' => $like, 'q3' => $like, 'q4' => $q . '%', 'q5' => $like, 'q6' => $q . '%', 'q7' => $q . '%']
    );
    sp_out(['ok' => true, 'parties' => sp_rows($rows)]);
} catch (Throwable $e) {
    error_log('[searchParties] ' . $e->getMessage());
    sp_out(['ok' => false, 'message' => 'Parties could not be searched.'], 500);
}
