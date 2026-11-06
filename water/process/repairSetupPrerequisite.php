<?php
/**
 * process/repairSetupPrerequisite.php
 *
 * Permission-controlled setup path for prerequisites that have no editing UI.
 * The only supported repair creates the missing journal voucher sequence row
 * (syssequence.SysJVIDSeq) seeded from the highest existing voucher number.
 * It never creates GL accounts, mappings, fees, discounts, profiles or logins.
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/businessRequestGuard.php';
require_once __DIR__ . '/journalNumberingHelpers.php';

$pdo = getDB();
// Any operator/profile allowed to manage settings may run the repair; the
// shared access helper decides using the unchanged permission semantics.
requireProcessAccess($pdo, 'settings');
business_require_mutation($pdo, '../views/settings.php');

$item = preg_replace('/[^a-z_]/', '', strtolower((string)($_POST['item'] ?? '')));
$return = '../views/settings.php';
try {
    if ($item !== 'jv_sequence') throw new RuntimeException('Unknown setup repair item.');
    $pdo->beginTransaction();
    jv_ensure_sequence_row($pdo);
    $pdo->prepare("UPDATE syssequence SET SeqStatus = 'A' WHERE SeqName = 'SysJVIDSeq' AND SeqStatus <> 'A'")->execute();
    $pdo->commit();
    $_SESSION['success_msg'] = 'The journal voucher numbering sequence (SysJVIDSeq) is now present and active.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[setup repair] ' . $e->getMessage());
    $_SESSION['error_msg'] = 'The setup repair could not be completed. Nothing was changed.';
}
header('Location: ' . $return);
exit;
