<?php
require_once __DIR__ . '/../database/config.php';
$_SESSION['error_msg'] = 'The separate Water Meter Discount workflow is retired. Configure the classification and its Water Billing value in General Discounts.';
header('Location: ../views/discounts.php');
exit;
