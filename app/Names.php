<?php
declare(strict_types=1);

/**
 * One name picker for payees and subsidiary ledgers:
 * customers, suppliers (vendors), employees and other names; cooperative members are described by member type.
 *
 * A choice travels as one short code: c12 = customer 12, v5 = vendor 5, o7 = employee / other name 7, m3 = member 3.
 * A cooperative member is listed once, by the member's name (one name in every line of business): the member's own
 * customer and supplier records and member name are not listed apart. Which of them an entry is recorded on follows
 * the account of the entry (parse()): a receivable is the member's customer record, a payable or a payment to the
 * member its supplier record, anything else its member name.
 */
final class Names
{
    public const TYPES = ['employee' => 'Employee', 'other' => 'Other name'];

    private static ?array $others = null;

    /** Employees and other names: id => row */
    public static function others(): array
    {
        if (self::$others === null) {
            self::$others = [];
            try {
                foreach (DB::all('SELECT * FROM other_names ORDER BY name') as $r) {
                    self::$others[(int)$r['id']] = $r;
                }
            } catch (Throwable $e) {
                // table not installed yet
            }
        }
        return self::$others;
    }

    public static function reset(): void
    {
        self::$others = null;
        if (class_exists('MemberDefaults', false)) {
            MemberDefaults::reset();
        }
    }

    /**
     * code => label, grouped by kind.
     * $keep is a code that stays in the list even when the record is inactive.
     */
    public static function options(?string $keep = null): array
    {
        $out = [];
        // cooperative members are listed once, described by their member type ("Regular Member", "Associate - Joint
        // Member" ...); their own customer, supplier and member-name records are not listed apart
        $idx = class_exists('MemberDefaults') ? MemberDefaults::index() : ['m' => [], 'c' => [], 'v' => [], 'o' => []];
        $kinds = class_exists('Members') ? Members::nameKinds() : ['o' => [], 'c' => [], 'v' => []];
        foreach (self::others() as $id => $r) {
            if ($r['name_type'] === 'employee' && ((int)$r['is_active'] === 1 || $keep === 'o' . $id)) {
                $out['o' . $id] = 'Employee' . (isset($kinds['o'][$id]) ? ', ' . $kinds['o'][$id] : '') . ': ' . $r['name'];
            }
        }
        foreach (Lookup::customers() as $id => $name) {
            if (!isset($idx['c'][$id])) {
                $out['c' . $id] = 'Customer' . (isset($kinds['c'][$id]) ? ', ' . $kinds['c'][$id] : '') . ': ' . $name;
            }
        }
        foreach (Lookup::vendorNames() as $id => $name) {
            if (!isset($idx['v'][$id])) {
                $out['v' . $id] = 'Supplier' . (isset($kinds['v'][$id]) ? ', ' . $kinds['v'][$id] : '') . ': ' . $name;
            }
        }
        foreach (self::others() as $id => $r) {
            if ($r['name_type'] !== 'employee' && !isset($idx['o'][$id]) && ((int)$r['is_active'] === 1 || $keep === 'o' . $id)) {
                $out['o' . $id] = ($kinds['o'][$id] ?? ($r['name_type'] === 'member' ? 'Member' : 'Other')) . ': ' . $r['name'];
            }
        }
        foreach ($idx['m'] as $mid => $m) {
            // a member whose name record was switched off (left the cooperative long ago) stays only on its old entries
            $on = $m['other_name_id'] ? (self::others()[(int)$m['other_name_id']] ?? null) : null;
            if ($on && (int)$on['is_active'] !== 1 && $keep !== 'm' . $mid) {
                continue;
            }
            $out['m' . $mid] = MemberDefaults::label((int)$mid);
        }
        if ($keep && !isset($out[$keep])) {
            $label = $keep[0] === 'm' ? MemberDefaults::label((int)substr($keep, 1)) : self::label(self::parse($keep));
            if ($label !== '') {
                $out[$keep] = $label . ' (inactive)';
            }
        }
        return $out;
    }

    /**
     * "Customer: ABC Trading" -> ['ABC Trading', 'Customer']; "Regular Member: Santos, Maria" -> ['Santos, Maria',
     * 'Regular Member']; "Other: Barangay Treasurer" -> ['Barangay Treasurer', 'Other name']. A label without a kind
     * comes back as it is, with no kind.
     */
    public static function splitLabel(string $label): array
    {
        if (preg_match('/^((?:Employee|Customer|Supplier|Vendor|Other)(?:, [^:]*?Member[^:]*)?|[^:]*?Member(?:, [a-z ]+)?): (.+)$/u', $label, $m)) {
            return [$m[2], $m[1] === 'Other' ? 'Other name' : $m[1]];
        }
        return [$label, ''];
    }

    /**
     * Code -> the three id columns. A member (m<id>) is recorded on the record of the role it plays in the entry:
     * on an Accounts Receivable account its customer record, on Accounts Payable or as the payee of a check or
     * cash voucher ($context 'payee') its supplier record, elsewhere its member name. The customer or supplier record
     * is made at once when the member has none yet.
     */
    public static function parse(?string $code, ?int $accountId = null, string $context = ''): array
    {
        $code = (string)$code;
        $id = (int)substr($code, 1);
        $out = ['customer_id' => null, 'vendor_id' => null, 'other_name_id' => null];
        if ($id <= 0) {
            return $out;
        }
        switch ($code[0] ?? '') {
            case 'c':
                $out['customer_id'] = $id;
                break;
            case 'v':
                $out['vendor_id'] = $id;
                break;
            case 'o':
                if (!isset(self::others()[$id])) {
                    fail('A name chosen on this form no longer exists.');
                }
                $out['other_name_id'] = $id;
                break;
            case 'm':
                $m = Coop::member($id);
                if (!$m) {
                    fail('A member chosen on this form no longer exists.');
                }
                if ($accountId && in_array($accountId, Accts::arIds(), true)) {
                    $out['customer_id'] = MemberDefaults::ensureCustomer($id);
                } elseif (($accountId && in_array($accountId, Accts::apIds(), true)) || $context === 'payee') {
                    $out['vendor_id'] = MemberDefaults::ensureVendor($id);
                } else {
                    $out['other_name_id'] = Coop::otherNameId($m);
                }
                break;
        }
        return $out;
    }

    /** The member a code stands for, when it is a member or one of a member's own records (else null) */
    public static function memberOf(?string $code): ?int
    {
        $code = (string)$code;
        $id = (int)substr($code, 1);
        if ($id <= 0 || !class_exists('MemberDefaults')) {
            return null;
        }
        return match ($code[0] ?? '') {
            'm' => $id,
            'c' => MemberDefaults::memberOf('c', $id),
            'v' => MemberDefaults::memberOf('v', $id),
            'o' => MemberDefaults::memberOf('o', $id),
            default => null,
        };
    }

    /** Row (transaction, line or ledger entry) -> code; a member's own record gives the member (m<id>) */
    public static function code(array $row): string
    {
        $idx = class_exists('MemberDefaults') ? MemberDefaults::index() : ['c' => [], 'v' => [], 'o' => []];
        if (!empty($row['customer_id'])) {
            $mid = $idx['c'][(int)$row['customer_id']] ?? null;
            return $mid ? 'm' . $mid : 'c' . (int)$row['customer_id'];
        }
        if (!empty($row['vendor_id'])) {
            $mid = $idx['v'][(int)$row['vendor_id']] ?? null;
            return $mid ? 'm' . $mid : 'v' . (int)$row['vendor_id'];
        }
        if (!empty($row['other_name_id'])) {
            $mid = $idx['o'][(int)$row['other_name_id']] ?? null;
            return $mid ? 'm' . $mid : 'o' . (int)$row['other_name_id'];
        }
        return '';
    }

    /** Display name for a row that has customer_id / vendor_id / other_name_id */
    public static function label(array $row): string
    {
        if (!empty($row['customer_id'])) {
            return (string)DB::value(
                "SELECT CASE WHEN p.id IS NULL THEN c.name ELSE CONCAT(p.name, ':', c.name) END
                   FROM customers c LEFT JOIN customers p ON p.id = c.parent_id WHERE c.id = :id",
                ['id' => $row['customer_id']]
            );
        }
        if (!empty($row['vendor_id'])) {
            return (string)DB::value('SELECT name FROM vendors WHERE id = :id', ['id' => $row['vendor_id']]);
        }
        if (!empty($row['other_name_id'])) {
            return (string)(self::others()[(int)$row['other_name_id']]['name'] ?? '');
        }
        return '';
    }
}
