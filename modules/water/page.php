<?php
declare(strict_types=1);

/**
 * Router target of the water-billing pages (index.php?p=water.<key>). The water pages are files of their own under
 * water/views (their scripts call water/process directly), so the router sends the browser there. Page access was
 * already checked by index.php; the page checks it again itself.
 */
$key = (string)($PARAMS['page'] ?? 'dashboard');
redirect(url('water.' . $key, array_diff_key($_GET, ['p' => 1])));
