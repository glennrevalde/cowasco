<?php
require_once __DIR__ . '/../database/config.php';
$_SESSION['success_msg'] = 'Use Discounted Members to assign a General Discount to a member.';
header('Location: ../views/discounted_members.php');
exit;
