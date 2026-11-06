<?php
require_once __DIR__ . '/../database/config.php';

require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/organization_units.php');
/* BUSINESS_MUTATION_GUARD_END */


$pdo = getDB();

function redirect_back(): void
{
    header('Location: ../views/organization_units.php');
    exit;
}

try {
    $unit = $_POST['unit'] ?? '';
    $action = $_POST['action'] ?? '';

    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $status = ($_POST['status'] ?? 'A') === 'I' ? 'I' : 'A';
    $username = $_SESSION['username'] ?? $_SESSION['full_name'] ?? 'system';

    if (!in_array($unit, ['branch', 'division', 'segment'], true)) {
        throw new Exception('Invalid setup type.');
    }

    if ($action === 'save') {
        if ($name === '') {
            throw new Exception('Name is required.');
        }

        if ($unit === 'branch') {
            if ($id > 0) {
                $deleted = $status === 'A' ? 0 : 1;

                $stmt = $pdo->prepare("
                    UPDATE branches
                    SET name = ?,
                        description = ?,
                        Deleted = ?,
                        modifiedby = ?,
                        date_modified = NOW()
                    WHERE pkey = ?
                ");
                $stmt->execute([$name, $description, $deleted, $username, $id]);

                $_SESSION['success_msg'] = 'Branch updated.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO branches
                    (name, description, Deleted, createdby, date_created)
                    VALUES (?, ?, 0, ?, NOW())
                ");
                $stmt->execute([$name, $description, $username]);

                $_SESSION['success_msg'] = 'Branch created.';
            }
        }

        if ($unit === 'division') {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE ic_divisions
                    SET name = ?,
                        status = ?,
                        datelastmodified = NOW()
                    WHERE pkey = ?
                ");
                $stmt->execute([$name, $status, $id]);

                $_SESSION['success_msg'] = 'Division updated.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO ic_divisions
                    (name, datecreated, status)
                    VALUES (?, NOW(), 'A')
                ");
                $stmt->execute([$name]);

                $_SESSION['success_msg'] = 'Division created.';
            }
        }

        if ($unit === 'segment') {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE ic_segments
                    SET name = ?,
                        status = ?,
                        datelastmodified = NOW()
                    WHERE pkey = ?
                ");
                $stmt->execute([$name, $status, $id]);

                $_SESSION['success_msg'] = 'Segment updated.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO ic_segments
                    (name, datecreated, status)
                    VALUES (?, NOW(), 'A')
                ");
                $stmt->execute([$name]);

                $_SESSION['success_msg'] = 'Segment created.';
            }
        }

        redirect_back();
    }

    if ($action === 'delete') {
        if ($id <= 0) {
            throw new Exception('Invalid record.');
        }

        if ($unit === 'branch') {
            $stmt = $pdo->prepare("
                UPDATE branches
                SET Deleted = 1,
                    modifiedby = ?,
                    date_modified = NOW()
                WHERE pkey = ?
            ");
            $stmt->execute([$username, $id]);
            $_SESSION['success_msg'] = 'Branch deleted.';
        }

        if ($unit === 'division') {
            $stmt = $pdo->prepare("
                UPDATE ic_divisions
                SET status = 'I',
                    datelastmodified = NOW()
                WHERE pkey = ?
            ");
            $stmt->execute([$id]);
            $_SESSION['success_msg'] = 'Division deactivated.';
        }

        if ($unit === 'segment') {
            $stmt = $pdo->prepare("
                UPDATE ic_segments
                SET status = 'I',
                datelastmodified = NOW()
                WHERE pkey = ?
            ");
            $stmt->execute([$id]);
            $_SESSION['success_msg'] = 'Segment deactivated.';
        }

        redirect_back();
    }

    throw new Exception('Invalid action.');
} catch (Throwable $e) {
    $_SESSION['error_msg'] = $e->getMessage();
    redirect_back();
}
