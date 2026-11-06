<?php
/**
 * Authenticated user-manual download endpoint.
 *
 * The Help Center is available to every signed-in operator. Downloads are
 * served through PHP so the manuals are not exposed through arbitrary paths,
 * and so unsupported format values cannot be used for path traversal.
 */
require_once __DIR__ . '/../database/config.php';

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';

$pdo = getDB();
requirePageAccess($pdo, 'help_center');

$format = strtolower(trim((string)($_GET['format'] ?? 'pdf')));
$manuals = [
    'pdf' => [
        'filename' => 'Water_Billing_User_Manual.pdf',
        'mime' => 'application/pdf',
    ],
    'docx' => [
        'filename' => 'Water_Billing_User_Manual.docx',
        'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ],
];

if (!isset($manuals[$format])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unsupported manual format. Choose PDF or DOCX.';
    exit;
}

$manualDirectory = realpath(__DIR__ . '/../resources/manuals');
$manualPath = $manualDirectory !== false
    ? realpath($manualDirectory . DIRECTORY_SEPARATOR . $manuals[$format]['filename'])
    : false;

$expectedPrefix = $manualDirectory !== false
    ? rtrim($manualDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
    : '';

if (
    $manualDirectory === false ||
    $manualPath === false ||
    strpos($manualPath, $expectedPrefix) !== 0 ||
    !is_file($manualPath) ||
    !is_readable($manualPath)
) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'The requested user manual is unavailable. Please contact the system administrator.';
    exit;
}

$size = filesize($manualPath);
if ($size === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'The user manual could not be prepared for download.';
    exit;
}

// Release the session lock before streaming the file.
session_write_close();

while (ob_get_level() > 0) {
    ob_end_clean();
}

$downloadName = $manuals[$format]['filename'];
header('Content-Type: ' . $manuals[$format]['mime']);
header('Content-Length: ' . (string)$size);
header('Content-Disposition: attachment; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$handle = fopen($manualPath, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit;
}

while (!feof($handle)) {
    $chunk = fread($handle, 1024 * 1024);
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    flush();
}

fclose($handle);
exit;
