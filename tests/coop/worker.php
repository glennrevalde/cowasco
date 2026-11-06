<?php
declare(strict_types=1);

/**
 * A separate process for the concurrency test (run.php starts two of them at once):
 *   php tests/coop/worker.php withdraw <savings account id> <amount> <date> <cash account id>
 * Prints "OK <voucher id>" or the error.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../../app/bootstrap.php';
$_SESSION = [];
Auth::actAs((int)DB::value("SELECT id FROM users WHERE username = 'admin'"));
[, $what, $account, $amount, $date, $cash] = array_pad($argv, 6, '');
// both processes start the withdrawal at the same instant (the next tenth of a second)
$at = ceil(microtime(true) * 10) / 10 + 0.2;
usleep((int)max(0, ($at - microtime(true)) * 1e6));
try {
    if ($what !== 'withdraw') {
        throw new RuntimeException('unknown action');
    }
    [$txnId] = Savings::withdraw((int)$account, 'CSW', $amount, $date, (int)$cash);
    echo 'OK ' . $txnId;
} catch (Throwable $e) {
    echo 'ERR ' . $e->getMessage();
}
