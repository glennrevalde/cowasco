<?php
require_once __DIR__ . '/../database/config.php';
$_SESSION['error_msg'] = 'Separate privilege rules are retired. Edit all module values directly in General Discounts.';
header('Location: ../views/discounts.php');
exit;
