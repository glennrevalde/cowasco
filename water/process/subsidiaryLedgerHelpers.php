<?php
if (!function_exists('sl_next_code')) {
    function sl_next_code(PDO $pdo, int $sltype): string
    {
        $start = ($sltype >= 1 && $sltype <= 3) ? 1 : 100000;
        $stmt = $pdo->prepare("SELECT MAX(CAST(slcode AS UNSIGNED)) FROM subsidiaryledger WHERE sltype = ?");
        $stmt->execute([$sltype]);
        $max = (int)$stmt->fetchColumn();
        return (string)max($start, $max + 1);
    }
}

if (!function_exists('sl_create')) {
    function sl_create(PDO $pdo, int $sltype, string $slname): string
    {
        $slname = trim($slname);
        if ($slname === '') throw new Exception('Subsidiary ledger name is required.');
        $slcode = sl_next_code($pdo, $sltype);
        $stmt = $pdo->prepare("INSERT INTO subsidiaryledger (slcode, sltype, slname, isdeleted) VALUES (?, ?, ?, 0)");
        $stmt->execute([$slcode, $sltype, $slname]);
        return $slcode;
    }
}
