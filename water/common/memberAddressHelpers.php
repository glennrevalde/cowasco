<?php
/**
 * Resolve the application's primary member-address type without assuming that
 * an AUTO_INCREMENT key is always 1.
 */

if (!function_exists('member_primary_address_type_id')) {
    function member_primary_address_type_id(PDO $pdo, bool $forUpdate = false): int
    {
        $sql = "
            SELECT pkey
            FROM addresstype
            WHERE status = 'A'
            ORDER BY
                CASE LOWER(TRIM(name))
                    WHEN 'home' THEN 0
                    WHEN 'residence' THEN 1
                    WHEN 'residential' THEN 2
                    WHEN 'present' THEN 3
                    ELSE 10
                END,
                pkey
            LIMIT 1
        ";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $id = (int)$pdo->query($sql)->fetchColumn();
        if ($id <= 0) {
            throw new RuntimeException('No active member address type is configured.');
        }
        return $id;
    }
}
