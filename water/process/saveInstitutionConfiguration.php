<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/institutionConfigurationHelpers.php';

$pdo = getDB();
requireProcessAccess($pdo, 'institution_configuration');

$returnTo = '../views/institution_configuration.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $returnTo);
    exit;
}

if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['error_msg'] = 'Your form session expired. Refresh the page and try again.';
    header('Location: ' . $returnTo);
    exit;
}

try {
    if (!institution_table_exists($pdo, 'companyofficers')) {
        throw new RuntimeException('The companyofficers table is missing from the current database.');
    }

    $positions = institution_configuration_positions();
    $submitted = is_array($_POST['officers'] ?? null) ? $_POST['officers'] : [];
    $profile = institution_business_profile($pdo);
    $businessProfileId = max(1, (int)($profile['pkey'] ?? 1));

    $operatorName = trim((string)($_SESSION['operator_name'] ?? $_SESSION['username'] ?? 'system'));
    if ($operatorName === '') {
        $operatorName = 'system';
    }
    $operatorName = function_exists('mb_substr')
        ? mb_substr($operatorName, 0, 45, 'UTF-8')
        : substr($operatorName, 0, 45);

    $normalized = [];
    foreach ($positions as $positionKey => $positionLabel) {
        $name = trim((string)($submitted[$positionKey] ?? ''));
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($length > 100) {
            throw new RuntimeException($positionLabel . ' name must be 100 characters or fewer.');
        }
        $normalized[$positionKey] = $name;
    }

    $selectActive = $pdo->prepare(
        "SELECT pkey, name
         FROM companyofficers
         WHERE busProfID = ?
           AND LOWER(TRIM(position)) = LOWER(TRIM(?))
           AND COALESCE(isdeleted, 0) = 0
         ORDER BY COALESCE(dateLastModified, dateCreated) DESC, pkey DESC
         FOR UPDATE"
    );
    $deactivate = $pdo->prepare(
        "UPDATE companyofficers
         SET isdeleted = 1,
             dateLastModified = NOW(),
             modifiedBy = ?
         WHERE busProfID = ?
           AND LOWER(TRIM(position)) = LOWER(TRIM(?))
           AND COALESCE(isdeleted, 0) = 0"
    );
    $insert = $pdo->prepare(
        "INSERT INTO companyofficers
            (busProfID, name, position, contactNo, dateCreated, createdBy, dateLastModified, modifiedBy, isdeleted)
         VALUES (?, ?, ?, '', NOW(), ?, NOW(), ?, 0)"
    );

    $changed = 0;
    $pdo->beginTransaction();

    foreach ($positions as $positionKey => $positionLabel) {
        $newName = $normalized[$positionKey];
        $selectActive->execute([$businessProfileId, $positionLabel]);
        $activeRows = $selectActive->fetchAll(PDO::FETCH_ASSOC);
        $currentName = isset($activeRows[0]) ? trim((string)($activeRows[0]['name'] ?? '')) : null;

        if (count($activeRows) === 1 && $currentName === $newName) {
            continue;
        }

        if (!empty($activeRows)) {
            $deactivate->execute([$operatorName, $businessProfileId, $positionLabel]);
        }

        // Insert even when blank. A saved blank is an explicit instruction to
        // hide that signatory and must not fall back to an old/default name.
        $insert->execute([$businessProfileId, $newName, $positionLabel, $operatorName, $operatorName]);
        $changed++;
    }

    $pdo->commit();
    $_SESSION['success_msg'] = $changed > 0
        ? 'Institution configuration saved successfully. Generated reports will use the current officer names.'
        : 'No institution officer changes were detected.';
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error_msg'] = $error->getMessage();
}

header('Location: ' . $returnTo);
exit;
