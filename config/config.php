<?php
/**
 * Application configuration.
 * Copy this file's values to match your MySQL / MariaDB server.
 * Environment variables (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS) override these.
 */
return [
    'db' => [
        'host'    => getenv('DB_HOST') ?: 'localhost',
        'port'    => getenv('DB_PORT') ?: '3306',
        'name'    => getenv('DB_NAME') ?: 'watercoopsystem',
        'user'    => getenv('DB_USER') ?: 'root',
        // XAMPP's MySQL/MariaDB root has no password out of the box; put yours here (or set DB_PASS)
        'pass'    => getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : 'Jeglen',
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
