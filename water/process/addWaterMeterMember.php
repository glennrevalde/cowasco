<?php
require_once __DIR__ . '/../database/config.php';
$_SESSION['flash']['error'] = 'Individual tariff-tier changes are disabled. Create, edit, or delete the complete Rate Code and Pipe Size tariff group.';
header('Location: ../views/water_meter.php?tab=rates');
exit;
