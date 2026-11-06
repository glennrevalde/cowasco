<?php
// The one logout of the integrated system.
require_once __DIR__ . '/../database/config.php';
header('Location: ' . base_url() . '/index.php?p=auth.logout');
exit;
