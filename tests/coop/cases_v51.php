<?php
declare(strict_types=1);

/**
 * Version 51: MySQL 8 refuses to compare a DATE with an empty string (error 1525 "Incorrect DATE value: ''"), where
 * MySQL 5.5 and MariaDB only warn. Billing List did that with its date filters left empty ("(?='' OR bill_date>=?)").
 * No query may compare a date with a parameter that can be '': an empty filter is bound as NULL ("? IS NULL OR ...").
 * The page itself is checked over HTTP (tests/water/e2e_http.php, section v51), and the suites run on MySQL 8 too.
 */

test('Version 51: no query compares a date with a filter that can be empty (MySQL 8 error 1525)', function () {
    $found = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = str_replace('\\', '/', substr((string)$file, strlen(APP_ROOT) + 1));
        if (!str_ends_with($path, '.php') || preg_match('#^(tests|vendor|storage|\.git)/#', $path)) {
            continue;
        }
        foreach (file((string)$file) as $n => $line) {
            // "(?='' OR <a date> >= ?)" or "(:from = '' OR <a date> <= :from)"
            if (preg_match("/(\\?|:[a-z_0-9]+)\\s*=\\s*''\\s*OR\\s+[a-z_.`]*(date|_at|_on|day)[a-z_`]*\\s*(<=|>=|<|>|=|BETWEEN)/i", $line)) {
                $found[] = $path . ':' . ($n + 1);
            }
        }
    }
    eq($found, [], 'no date compared with a filter that can be empty');
    $page = (string)file_get_contents(APP_ROOT . '/water/views/billing_list.php');
    check(str_contains($page, '(? IS NULL OR bill_date>=?) AND (? IS NULL OR bill_date<=?)'), 'Billing List binds an empty date filter as NULL');
});
