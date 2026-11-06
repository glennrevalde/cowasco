<?php
/**
 * Institution configuration helpers.
 *
 * The supplied company database already contains companyofficers and
 * bussinessprofile. This helper makes those tables usable by the Water Billing
 * System without introducing a duplicate institution/signatory table.
 */

if (!function_exists('institution_configuration_positions')) {
    function institution_configuration_positions(): array
    {
        return [
            'manager' => 'Manager',
            'general_manager' => 'General Manager',
        ];
    }
}

if (!function_exists('institution_configuration_default_names')) {
    function institution_configuration_default_names(): array
    {
        return [
            'manager' => '',
            'general_manager' => '',
        ];
    }
}

if (!function_exists('institution_normalize_position_key')) {
    function institution_normalize_position_key(string $position): string
    {
        $position = strtolower(trim($position));
        $position = preg_replace('/[^a-z0-9]+/', '_', $position) ?? '';
        return trim($position, '_');
    }
}

if (!function_exists('institution_table_exists')) {
    function institution_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        $key = spl_object_id($pdo) . ':' . strtolower($table);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$table]);
            return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
        } catch (Throwable $error) {
            return $cache[$key] = false;
        }
    }
}

if (!function_exists('institution_business_profile')) {
    function institution_business_profile(PDO $pdo): array
    {
        $fallback = [
            'pkey' => 1,
            'name' => class_exists('Coop') ? Coop::coopName() : (class_exists('Settings') ? (string)Settings::get('company_name') : 'Cooperative'),
        ];

        if (!institution_table_exists($pdo, 'bussinessprofile')) {
            return $fallback;
        }

        try {
            $stmt = $pdo->query(
                "SELECT pkey, name
                 FROM bussinessprofile
                 WHERE COALESCE(isdeleted, 0) = 0
                 ORDER BY COALESCE(IsBranch, 0) ASC, pkey ASC
                 LIMIT 1"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return $fallback;
            }

            return [
                'pkey' => max(1, (int)($row['pkey'] ?? 1)),
                'name' => trim((string)($row['name'] ?? '')) ?: $fallback['name'],
            ];
        } catch (Throwable $error) {
            return $fallback;
        }
    }
}

if (!function_exists('institution_current_officers')) {
    /**
     * Returns the current active officer row for each supported position.
     * The newest active record wins if old company data contains duplicates.
     */
    function institution_current_officers(PDO $pdo, ?int $businessProfileId = null): array
    {
        $positions = institution_configuration_positions();
        $result = [];
        foreach ($positions as $key => $label) {
            $result[$key] = [
                'position_key' => $key,
                'position' => $label,
                'name' => '',
                'found' => false,
                'pkey' => 0,
                'created_at' => null,
                'updated_at' => null,
                'updated_by' => '',
            ];
        }

        if (!institution_table_exists($pdo, 'companyofficers')) {
            return $result;
        }

        $businessProfileId = $businessProfileId ?: (int)institution_business_profile($pdo)['pkey'];

        try {
            $stmt = $pdo->prepare(
                "SELECT pkey, name, position, dateCreated, dateLastModified, createdBy, modifiedBy
                 FROM companyofficers
                 WHERE busProfID = ?
                   AND COALESCE(isdeleted, 0) = 0
                 ORDER BY COALESCE(dateLastModified, dateCreated) DESC, pkey DESC"
            );
            $stmt->execute([$businessProfileId]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = institution_normalize_position_key((string)($row['position'] ?? ''));
                if (!isset($result[$key]) || $result[$key]['found']) {
                    continue;
                }

                $result[$key] = [
                    'position_key' => $key,
                    'position' => $positions[$key],
                    'name' => trim((string)($row['name'] ?? '')),
                    'found' => true,
                    'pkey' => (int)($row['pkey'] ?? 0),
                    'created_at' => $row['dateCreated'] ?? null,
                    'updated_at' => $row['dateLastModified'] ?? null,
                    'updated_by' => trim((string)($row['modifiedBy'] ?? $row['createdBy'] ?? '')),
                ];
            }
        } catch (Throwable $error) {
            error_log('Institution officer lookup failed: ' . $error->getMessage());
        }

        return $result;
    }
}

if (!function_exists('institution_officer_for_report')) {
    /**
     * Returns report-safe officer data. A default is used only when no saved
     * row exists; an explicitly saved blank value remains blank.
     */
    function institution_officer_for_report(PDO $pdo, string $positionKey): array
    {
        $positions = institution_configuration_positions();
        $defaults = institution_configuration_default_names();
        $officers = institution_current_officers($pdo);

        if (!isset($positions[$positionKey])) {
            return ['position' => '', 'name' => ''];
        }

        $officer = $officers[$positionKey] ?? null;
        $name = ($officer && !empty($officer['found']))
            ? (string)$officer['name']
            : (string)($defaults[$positionKey] ?? '');

        return [
            'position' => $positions[$positionKey],
            'name' => $name,
        ];
    }
}

if (!function_exists('institution_issuer')) {
    /**
     * Who issues the water documents (official receipt, statements): the cooperative itself. Its registered name,
     * address, telephone and TIN come from the company settings of the integrated system (Company > Company
     * Information); a value left empty there is taken from the institution profile of the water module.
     *
     * @return array{name:string,address:string,phone:string,tin:string}
     */
    function institution_issuer(PDO $pdo): array
    {
        $profile = [];
        if (institution_table_exists($pdo, 'bussinessprofile')) {
            try {
                $row = $pdo->query(
                    "SELECT name, address1, address2, telephoneNo, cellularNo, tinNo
                     FROM bussinessprofile
                     WHERE COALESCE(isdeleted, 0) = 0
                     ORDER BY COALESCE(IsBranch, 0) ASC, pkey ASC
                     LIMIT 1"
                )->fetch(PDO::FETCH_ASSOC);
                $profile = is_array($row) ? $row : [];
            } catch (Throwable $error) {
                $profile = [];
            }
        }
        $setting = static function (string $key): string {
            return class_exists('Settings') ? trim((string)Settings::get($key, '')) : '';
        };
        $address = trim(implode(', ', array_unique(array_filter([trim((string)($profile['address1'] ?? '')), trim((string)($profile['address2'] ?? ''))], 'strlen'))));
        $phone = trim((string)($profile['telephoneNo'] ?? ''));
        $tin = trim((string)($profile['tinNo'] ?? ''));
        $name = class_exists('Coop') ? trim(Coop::coopName()) : $setting('company_name');

        return [
            'name' => $name !== '' ? $name : trim((string)($profile['name'] ?? '')),
            'address' => $setting('company_address') !== '' ? str_replace(["\r\n", "\n"], ', ', $setting('company_address')) : $address,
            'phone' => $setting('company_phone') !== '' ? $setting('company_phone') : (strlen(preg_replace('/\D/', '', $phone)) >= 5 ? $phone : ''),
            'tin' => $setting('company_tin') !== '' ? $setting('company_tin') : (strlen(preg_replace('/\D/', '', $tin)) >= 9 ? $tin : ''),
        ];
    }
}
