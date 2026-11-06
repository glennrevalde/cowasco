<?php
require_once __DIR__ . '/../database/config.php';
$_SESSION['error_msg'] = 'Separate privilege rules are retired. Deactivate or edit the module inside General Discounts.';
header('Location: ../views/discounts.php');
exit;
