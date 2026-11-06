<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';

$pdo = getDB();
requireProcessAccess($pdo, 'collection_policy_configuration');
$returnTo = '../views/collection_policy_configuration.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . $returnTo);
    exit;
}

if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['error_msg'] = 'Your form session expired. Refresh and try again.';
    header('Location: ' . $returnTo);
    exit;
}

try {
    $penaltyEnabled = isset($_POST['manual_penalty_enabled']) ? 1 : 0;
    $compromiseEnabled = isset($_POST['manual_compromise_enabled']) ? 1 : 0;
    $penaltyAccount = (int)($_POST['collection_penalty_account_id'] ?? 0);
    $compromiseAccount = (int)($_POST['collection_compromise_account_id'] ?? 0);
    $inactivityHours = (int)($_POST['session_inactivity_hours'] ?? 24);

    if ($inactivityHours < 1 || $inactivityHours > 720) {
        throw new RuntimeException('Session inactivity must be between 1 and 720 hours.');
    }

    $accountCheck = $pdo->prepare("SELECT AccntID FROM chartofaccntstbl WHERE AccntID = ? AND Status = 'A' LIMIT 1");

    if ($penaltyEnabled) {
        $accountCheck->execute([$penaltyAccount]);
        if (!(int)$accountCheck->fetchColumn()) {
            throw new RuntimeException('Select an active income account before activating Penalty.');
        }
    }

    if ($compromiseEnabled) {
        $accountCheck->execute([$compromiseAccount]);
        if (!(int)$accountCheck->fetchColumn()) {
            throw new RuntimeException('Select an active income account before activating Compromise.');
        }
    }

    // Validate a selected account even when its fee is currently disabled so a
    // stale or deleted account is never saved silently.
    foreach ([
        [$penaltyAccount, 'Penalty'],
        [$compromiseAccount, 'Compromise'],
    ] as [$accountId, $label]) {
        if ($accountId <= 0) continue;
        $accountCheck->execute([$accountId]);
        if (!(int)$accountCheck->fetchColumn()) {
            throw new RuntimeException($label . ' income account is missing or inactive.');
        }
    }

    $values = [
        'manual_penalty_enabled' => [(string)$penaltyEnabled, 'Whether the Penalty checkbox is available to cashiers.'],
        'collection_penalty_account_id' => [(string)$penaltyAccount, 'Income account used only when a cashier manually applies Penalty.'],
        'manual_compromise_enabled' => [(string)$compromiseEnabled, 'Whether the Compromise checkbox is available to cashiers.'],
        'collection_compromise_account_id' => [(string)$compromiseAccount, 'Income account used only when a cashier manually applies Compromise.'],
        'manual_penalty_amount' => ['0.00', 'Deprecated default amount. Cashiers must type every Penalty amount manually.'],
        'manual_compromise_amount' => ['0.00', 'Deprecated default amount. Cashiers must type every Compromise amount manually.'],
        'session_inactivity_hours' => [(string)$inactivityHours, 'Hours of inactivity before an operator session is automatically logged out.'],
        'collection_penalty_mode' => ['none', 'Legacy automatic penalty calculation is disabled.'],
        'collection_penalty_value' => ['0', 'Legacy automatic penalty value is disabled.'],
        'collection_penalty_grace_days' => ['0', 'Legacy automatic penalty grace period is disabled.'],
    ];

    $upsert = $pdo->prepare(
        "INSERT INTO water_billing_settings
            (setting_key, setting_value, description, created_at, updated_at)
         VALUES (?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            description = VALUES(description),
            updated_at = NOW()"
    );

    $pdo->beginTransaction();
    foreach ($values as $key => [$value, $description]) {
        $upsert->execute([$key, $value, $description]);
    }
    $pdo->commit();

    $_SESSION['success_msg'] = 'Other Fees and inactivity settings were saved. Penalty and Compromise remain optional and cashier-entered.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error_msg'] = $e->getMessage();
}

header('Location: ' . $returnTo);
exit;
