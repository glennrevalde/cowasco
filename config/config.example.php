<?php
/**
 * Configuration template (no secrets). Copy it to config/config.php and fill in your server's values.
 *
 * - db.name: the integrated database. For a new install it is created by tools/install.php or by importing
 *   database/accounting_system_clean.sql. For a cut-over it is the target made by tools/create_target.php.
 * - db.user / db.pass: a MySQL account with rights on that database (the cut-over tools also need SELECT on the
 *   former water database and CREATE on the server). Never commit a real password.
 * - app.timezone: the cooperative's time zone; every date and the business day follow it.
 * Environment variables (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, APP_DEBUG) override these values.
 */
return [
    'db' => [
        'host'    => getenv('DB_HOST') ?: 'localhost',
        'port'    => getenv('DB_PORT') ?: '3306',
        'name'    => getenv('DB_NAME') ?: 'cooperative_system',
        'user'    => getenv('DB_USER') ?: 'root',
        // XAMPP's MySQL/MariaDB root has no password out of the box; put yours here (or set DB_PASS)
        'pass'    => getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name'        => 'Accounting System',
        'timezone'    => 'Asia/Manila',
        'currency'    => '₱',
        'debug'       => (getenv('APP_DEBUG') ?: '0') === '1',
        // folder (inside the project) where uploaded receipts are stored
        'upload_dir'  => __DIR__ . '/../storage/receipts',
    ],
];
