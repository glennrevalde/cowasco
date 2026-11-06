<?php
/**
 * Canonical pipe-size helpers for water tariff and member configuration.
 */

if (!function_exists('water_pipe_size_normalize_code')) {
    function water_pipe_size_normalize_code($value): string
    {
        $code = strtoupper(trim((string)$value));
        $code = preg_replace('/\s+/', ' ', $code) ?? '';
        if ($code === '') {
            throw new InvalidArgumentException('Pipe size code is required.');
        }
        if (strlen($code) > 30) {
            throw new InvalidArgumentException('Pipe size code must not exceed 30 characters.');
        }
        if (!preg_match('/^[A-Z0-9 ._\/-]+$/', $code)) {
            throw new InvalidArgumentException('Pipe size code may contain only letters, numbers, spaces, periods, underscores, slashes, and hyphens.');
        }
        return $code;
    }
}

if (!function_exists('water_pipe_size_normalize_label')) {
    function water_pipe_size_normalize_label($value): string
    {
        $label = trim((string)$value);
        $label = preg_replace('/\s+/', ' ', $label) ?? '';
        if ($label === '') {
            throw new InvalidArgumentException('Pipe size label is required.');
        }
        if (strlen($label) > 80) {
            throw new InvalidArgumentException('Pipe size label must not exceed 80 characters.');
        }
        return $label;
    }
}

if (!function_exists('water_pipe_size_normalize_diameter')) {
    function water_pipe_size_normalize_diameter($value): ?string
    {
        $raw = trim((string)($value ?? ''));
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^\d+(?:\.\d{1,4})?$/', $raw)) {
            throw new InvalidArgumentException('Diameter in inches must be a positive number with up to four decimal places.');
        }
        $diameter = (float)$raw;
        if ($diameter <= 0 || $diameter > 9999) {
            throw new InvalidArgumentException('Diameter in inches must be greater than zero.');
        }
        return number_format($diameter, 4, '.', '');
    }
}

if (!function_exists('water_pipe_size_get')) {
    function water_pipe_size_get(PDO $pdo, int $pipeSizeId, bool $forUpdate = false): array
    {
        if ($pipeSizeId <= 0) {
            throw new InvalidArgumentException('Please select a valid pipe size.');
        }
        $sql = "
            SELECT pipe_size_id, size_code, size_label, diameter_inches, is_active,
                   created_by, created_at, updated_at
            FROM water_pipe_sizes
            WHERE pipe_size_id = ?
            LIMIT 1
        ";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$pipeSizeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('The selected pipe size does not exist.');
        }
        $row['pipe_size_id'] = (int)$row['pipe_size_id'];
        $row['is_active'] = (int)$row['is_active'];
        $row['display_name'] = trim((string)$row['size_label']) !== ''
            ? (string)$row['size_label']
            : (string)$row['size_code'];
        return $row;
    }
}

if (!function_exists('water_pipe_size_require_active')) {
    function water_pipe_size_require_active(PDO $pdo, int $pipeSizeId, bool $forUpdate = false): array
    {
        $row = water_pipe_size_get($pdo, $pipeSizeId, $forUpdate);
        if ((int)$row['is_active'] !== 1) {
            throw new InvalidArgumentException('The selected pipe size is inactive. Activate it before assigning it to a member or tariff.');
        }
        return $row;
    }
}

if (!function_exists('water_pipe_size_options')) {
    function water_pipe_size_options(PDO $pdo, bool $activeOnly = true): array
    {
        $where = $activeOnly ? 'WHERE is_active = 1' : '';
        $stmt = $pdo->query("
            SELECT pipe_size_id, size_code, size_label, diameter_inches, is_active,
                   created_by, created_at, updated_at
            FROM water_pipe_sizes
            {$where}
            ORDER BY
                CASE WHEN diameter_inches IS NULL THEN 1 ELSE 0 END,
                diameter_inches ASC,
                size_label ASC,
                pipe_size_id ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('water_pipe_size_format')) {
    function water_pipe_size_format(?array $pipeSize): string
    {
        if (!$pipeSize) {
            return 'Unassigned';
        }
        $label = trim((string)($pipeSize['size_label'] ?? ''));
        $code = trim((string)($pipeSize['size_code'] ?? ''));
        if ($label !== '' && $code !== '' && strcasecmp($label, $code) !== 0) {
            return $label . ' (' . $code . ')';
        }
        return $label !== '' ? $label : ($code !== '' ? $code : 'Unassigned');
    }
}
