<?php
/**
 * common/accountProfileHelpers.php
 *
 * Who a water-service account is billed to, as a bill or a statement prints it: the name, the account number,
 * whether the party is a cooperative member or a customer, the address and the contact details.
 *
 * The address is the first one on file of: the account's own address (the former water program's address book),
 * the member's residence (Member Information), the party's address, the customer's billing address.
 */

if (!function_exists('wa_join')) {
    function wa_join(array $parts, string $separator = ', '): string
    {
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string)($part ?? ''));
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return implode($separator, $out);
    }
}

if (!function_exists('wa_account_name')) {
    /** "Last, First Middle", or the party's name for an institution or an account with no first name */
    function wa_account_name(array $row): string
    {
        $last = trim((string)($row['lastname'] ?? ''));
        $given = wa_join([$row['firstname'] ?? '', $row['middlename'] ?? ''], ' ');
        $name = $last !== '' ? $last . ($given !== '' ? ', ' . $given : '') : $given;
        if ($name === '') {
            $name = trim((string)($row['display_name'] ?? ''));
        }
        return $name !== '' ? $name : 'Account ' . (int)($row['pkey'] ?? 0);
    }
}

if (!function_exists('wa_account_profile')) {
    /**
     * One water-service account as a document prints it:
     * ['id', 'account_number', 'name', 'kind' => 'Member'|'Customer', 'member_no', 'address', 'phone', 'email', 'zone',
     *  'status']. Null when the account does not exist.
     */
    function wa_account_profile(PDO $pdo, int $accountId): ?array
    {
        if ($accountId <= 0) {
            return null;
        }
        $row = null;
        try {
            $stmt = $pdo->prepare("
                SELECT w.pkey, w.account_number, w.lastname, w.firstname, w.middlename, w.zone, w.status, w.party_id,
                       w.customer_id, p.display_name, p.address AS party_address, p.mobile AS party_mobile,
                       p.phone AS party_phone, p.email AS party_email,
                       m.id AS coop_member_id, m.member_no, m.res_house, m.res_street, m.res_barangay, m.res_city,
                       m.res_province, m.res_postal, m.mobile AS member_mobile, m.email AS member_email,
                       c.billing_address, c.phone AS customer_phone, c.mobile AS customer_mobile, c.email AS customer_email
                FROM water_accounts w
                LEFT JOIN parties p ON p.id = w.party_id
                LEFT JOIN members m ON m.id = (SELECT MIN(m2.id) FROM members m2 WHERE m2.party_id = w.party_id)
                LEFT JOIN customers c ON c.id = w.customer_id
                WHERE w.pkey = ?
                LIMIT 1
            ");
            $stmt->execute([$accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            // an install without the party tables: the account's own record only
            $stmt = $pdo->prepare("SELECT pkey, account_number, lastname, firstname, middlename, zone, status FROM water_accounts WHERE pkey = ? LIMIT 1");
            $stmt->execute([$accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$row) {
            return null;
        }

        $address = '';
        try {
            $stmt = $pdo->prepare("
                SELECT a.housebldg, a.street, a.other_address_detail, a.zippostal_code, c.city, c.province
                FROM memberaddress ma
                INNER JOIN addresses a ON a.pkey = ma.address_key
                LEFT JOIN cities c ON c.pkey = a.city_id
                LEFT JOIN addresstype at ON at.pkey = ma.address_type_key
                WHERE ma.member_key = ?
                  AND COALESCE(ma.status, 'A') = 'A'
                  AND COALESCE(a.status, 'A') = 'A'
                  AND (ma.dtaddressto IS NULL OR ma.dtaddressto >= CURDATE())
                ORDER BY CASE WHEN LOWER(COALESCE(at.name, '')) IN ('home', 'residential', 'primary') THEN 0 ELSE 1 END,
                         ma.dtaddressFrom DESC, a.pkey DESC
                LIMIT 1
            ");
            $stmt->execute([$accountId]);
            if ($a = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $address = wa_join([
                    wa_join([$a['housebldg'] ?? '', $a['street'] ?? '', $a['other_address_detail'] ?? '']),
                    wa_join([$a['city'] ?? '', $a['province'] ?? '', $a['zippostal_code'] ?? '']),
                ]);
            }
        } catch (Throwable $e) {
            // no former address book on this install
        }
        if ($address === '') {
            $address = wa_join([$row['res_house'] ?? '', $row['res_street'] ?? '', $row['res_barangay'] ?? '',
                $row['res_city'] ?? '', $row['res_province'] ?? '', $row['res_postal'] ?? '']);
        }
        if ($address === '') {
            $address = trim((string)($row['party_address'] ?? ''));
        }
        if ($address === '') {
            $address = trim((string)($row['billing_address'] ?? ''));
        }

        $phone = '';
        $email = '';
        foreach (['member_mobile', 'party_mobile', 'customer_mobile', 'party_phone', 'customer_phone'] as $k) {
            if ($phone === '' && trim((string)($row[$k] ?? '')) !== '') {
                $phone = trim((string)$row[$k]);
            }
        }
        foreach (['member_email', 'party_email', 'customer_email'] as $k) {
            if ($email === '' && trim((string)($row[$k] ?? '')) !== '') {
                $email = trim((string)$row[$k]);
            }
        }

        return [
            'id' => (int)$row['pkey'],
            'account_number' => trim((string)($row['account_number'] ?? '')) ?: (string)(int)$row['pkey'],
            'name' => wa_account_name($row),
            'kind' => !empty($row['coop_member_id']) ? 'Member' : 'Customer',
            'member_no' => trim((string)($row['member_no'] ?? '')),
            'address' => $address,
            'phone' => $phone,
            'email' => $email,
            'zone' => $row['zone'] !== null ? (string)$row['zone'] : '',
            'status' => strtoupper((string)($row['status'] ?? 'A')),
        ];
    }
}

if (!function_exists('wa_issuer_logo')) {
    /** The cooperative's logo for a printed bill or statement (data: URI), or '' when none is on file */
    function wa_issuer_logo(PDO $pdo): string
    {
        try {
            $blob = $pdo->query("SELECT logoImage FROM bussinessprofile WHERE COALESCE(isdeleted, 0) = 0 ORDER BY IsBranch ASC, pkey ASC LIMIT 1")->fetchColumn();
        } catch (Throwable $e) {
            return '';
        }
        if (!is_string($blob) || $blob === '') {
            return '';
        }
        $mime = 'image/png';
        if (substr($blob, 0, 3) === "\xFF\xD8\xFF") {
            $mime = 'image/jpeg';
        } elseif (substr($blob, 0, 6) === 'GIF87a' || substr($blob, 0, 6) === 'GIF89a') {
            $mime = 'image/gif';
        }
        return 'data:' . $mime . ';base64,' . base64_encode($blob);
    }
}
