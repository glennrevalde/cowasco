<?php
require_once __DIR__ . '/../database/config.php';

// Rate-code-level free cubic-meter allowances were retired.
// Configure every free-water privilege in General Discounts instead.
$_SESSION['flash'] = [
    'msg' => 'Rate-code free allowances are no longer supported. Configure Free Cubic Meter privileges in General Discounts.',
    'type' => 'error',
];
header('Location: ../views/discounts.php');
exit;
