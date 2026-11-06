<?php
declare(strict_types=1);

/**
 * The Party Master: one record for each natural or juridical person the cooperative deals with, whatever roles it
 * holds. A role is a record of its own (members, customers, vendors, other_names) that keeps its number, terms,
 * limits, status and history, and points at its party through party_id.
 *
 * Rules kept here:
 *  - Nothing is linked or merged by name alone. A new role record gets a new party unless the user picks an existing
 *    party; possible duplicates are only proposed (identity review) and linking them needs the party_merge permission
 *    and a reason.
 *  - A member's registered name and details lead: saving the member writes them to the party. A party that is not a
 *    member and has a single role follows that role record; a party with several roles is edited on the Party Center
 *    (the role records then only fill details the party does not have yet).
 *  - Granting, revoking, restoring and moving a role never deletes the role record or its documents, and every change
 *    is written to party_role_log and the audit trail.
 */
final class Party
{
    public const ROLES = ['member' => 'Member', 'customer' => 'Customer', 'supplier' => 'Supplier', 'employee' => 'Employee', 'other' => 'Other name',
        'water' => 'Water-service account'];
    public const TYPES = ['person' => 'Person', 'organization' => 'Organization'];
    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive', 'merged' => 'Merged'];
    public const ACTIONS = ['granted' => 'Granted', 'revoked' => 'Revoked', 'restored' => 'Restored', 'moved_in' => 'Moved in',
        'moved_out' => 'Moved out', 'merged' => 'Merged', 'edited' => 'Edited', 'linked' => 'Linked'];

    /** source => table */
    private const TABLES = ['member' => 'members', 'customer' => 'customers', 'vendor' => 'vendors', 'other_name' => 'other_names'];

    /** party columns written from the role records and the Party Center form */
    private const IDENTITY = ['party_type', 'legal_name', 'display_name', 'last_name', 'first_name', 'middle_name', 'suffix', 'tin', 'birth_date',
        'registration_no', 'email', 'mobile', 'phone', 'address', 'home_branch_id'];
    /** the details a role record may fill in when the party does not have them */
    private const FILLABLE = ['tin', 'email', 'mobile', 'phone', 'address', 'birth_date', 'home_branch_id'];
    /** details of a member's party that come from the member record (edited on the Member Information page) */
    public const MEMBER_LED = ['party_type', 'legal_name', 'display_name', 'last_name', 'first_name', 'middle_name', 'suffix', 'birth_date', 'tin', 'email', 'mobile', 'address', 'home_branch_id'];
    /** details of a member hidden from users without the coop_view_confidential permission */
    public const CONFIDENTIAL = ['tin', 'birth_date', 'email', 'mobile', 'phone', 'address', 'registration_no'];

    private static ?bool $ready = null;

    /** The party tables are installed (the 2026_11_15 migration ran) */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                DB::value('SELECT COUNT(*) FROM parties WHERE 1 = 0');
                DB::value('SELECT COUNT(party_id) FROM customers WHERE 1 = 0');
                self::$ready = true;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function resetReady(): void
    {
        self::$ready = null;
        self::$waterReady = null;
    }

    private static ?bool $waterReady = null;

    /** The water-billing module is installed (the 2026_12_01 migration ran) */
    public static function waterReady(): bool
    {
        if (self::$waterReady === null) {
            try {
                DB::value('SELECT COUNT(party_id) FROM water_accounts WHERE 1 = 0');
                self::$waterReady = true;
            } catch (Throwable $e) {
                self::$waterReady = false;
            }
        }
        return self::$waterReady;
    }

    // ------------------------------------------------------------------ reading

    public static function get(int $id): ?array
    {
        return $id > 0 ? DB::row('SELECT * FROM parties WHERE id = :id', ['id' => $id]) : null;
    }

    /** The party a merged party went into (or the party itself) */
    public static function live(int $id): ?array
    {
        $p = self::get($id);
        $guard = 0;
        while ($p && $p['status'] === 'merged' && $p['merged_into_id'] && $guard++ < 10) {
            $p = self::get((int)$p['merged_into_id']);
        }
        return $p;
    }

    public static function label(array $p): string
    {
        return $p['display_name'] . ' (' . $p['party_no'] . ')';
    }

    /** "Maria R. Santos Jr." */
    public static function personName(?string $first, ?string $middle, ?string $last, ?string $suffix): string
    {
        $mi = trim((string)$middle);
        $mi = $mi !== '' ? mb_substr($mi, 0, 1) . '.' : '';
        return trim(preg_replace('/\s+/', ' ', trim((string)$first) . ' ' . $mi . ' ' . trim((string)$last) . ' ' . trim((string)$suffix)));
    }

    /**
     * id => "Display name (P-000001), roles" of the parties for a drop-down (searchable as you type).
     * $nonMembers: only parties that are not members (to enroll one as a member).
     */
    public static function options(?int $keepId = null, bool $withRoles = true, bool $nonMembers = false): array
    {
        $rows = DB::all("SELECT p.id, p.party_no, p.display_name, p.status FROM parties p WHERE (p.status = 'active'"
            . ($nonMembers ? ' AND NOT EXISTS (SELECT 1 FROM members m WHERE m.party_id = p.id)' : '') . ') OR p.id = :k ORDER BY p.display_name, p.id', ['k' => $keepId ?? 0]);
        $roles = $withRoles ? self::roleNamesAll() : [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = $r['display_name'] . ' (' . $r['party_no'] . ($r['status'] === 'inactive' ? ', inactive' : '') . ')' . (!empty($roles[(int)$r['id']]) ? ', ' . implode(', ', $roles[(int)$r['id']]) : '');
        }
        return $out;
    }

    /** party id => role labels, for lists */
    public static function roleNamesAll(?array $ids = null): array
    {
        $filter = '';
        $params = [];
        if ($ids !== null) {
            if (!$ids) {
                return [];
            }
            [$in, $params] = DB::in('pr', array_values(array_unique(array_map('intval', $ids))));
            $filter = " AND party_id IN $in";
        }
        $rows = DB::all("SELECT party_id, 'member' AS role, MIN(CASE WHEN status IN ('active','applicant','suspended') THEN 1 ELSE 0 END) AS a, MAX(CASE WHEN status IN ('active','applicant','suspended') THEN 1 ELSE 0 END) AS on_ FROM members WHERE party_id IS NOT NULL$filter GROUP BY party_id
                UNION ALL SELECT party_id, 'customer', 0, MAX(is_active) FROM customers WHERE party_id IS NOT NULL AND parent_id IS NULL$filter GROUP BY party_id
                UNION ALL SELECT party_id, 'supplier', 0, MAX(is_active) FROM vendors WHERE party_id IS NOT NULL$filter GROUP BY party_id
                UNION ALL SELECT party_id, IF(name_type = 'employee', 'employee', 'other'), 0, MAX(is_active) FROM other_names WHERE party_id IS NOT NULL AND member_id IS NULL$filter GROUP BY party_id, IF(name_type = 'employee', 'employee', 'other')",
            $params);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['party_id']][] = self::ROLES[$r['role']] . ((int)$r['on_'] === 1 ? '' : ' (inactive)');
        }
        return $out;
    }

    /**
     * The roles of a party: every member, customer, supplier, employee and other-name record linked to it.
     * @return array<int,array{role:string,label:string,ref_id:int,ref_no:string,name:string,status:string,active:bool,branch:string,detail:string,page:string,params:array}>
     */
    public static function roles(int $partyId): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM members WHERE party_id = :p ORDER BY id', ['p' => $partyId]) as $m) {
            $out[] = ['role' => 'member', 'label' => 'Member', 'ref_id' => (int)$m['id'], 'ref_no' => (string)$m['member_no'], 'name' => (string)$m['full_name'],
                'status' => Coop::MEMBER_STATUSES[$m['status']] ?? $m['status'], 'active' => in_array($m['status'], ['active', 'applicant', 'suspended'], true),
                'branch' => $m['branch_id'] ? Coop::branchName((int)$m['branch_id']) : '',
                'detail' => trim(($m['membership_date'] ? 'Member since ' . fdate($m['membership_date']) : '') . ((int)$m['hold_flag'] === 1 ? ', on hold' : ''), ', '),
                'page' => 'coop.member', 'params' => ['id' => $m['id']]];
        }
        $terms = Lookup::terms();
        $levels = Lookup::priceLevels();
        foreach (DB::all('SELECT * FROM customers WHERE party_id = :p AND parent_id IS NULL ORDER BY id', ['p' => $partyId]) as $c) {
            $jobs = (int)DB::value('SELECT COUNT(*) FROM customers WHERE parent_id = :c', ['c' => $c['id']]);
            $detail = [];
            if ($c['terms_id']) {
                $detail[] = 'Terms ' . ($terms[(int)$c['terms_id']] ?? '');
            }
            if ((float)$c['credit_limit'] > 0) {
                $detail[] = 'Credit limit ' . money($c['credit_limit']);
            }
            if ($c['price_level_id']) {
                $detail[] = 'Price level ' . ($levels[(int)$c['price_level_id']] ?? '');
            }
            if ($jobs) {
                $detail[] = $jobs . ' job' . ($jobs > 1 ? 's' : '');
            }
            $out[] = ['role' => 'customer', 'label' => 'Customer', 'ref_id' => (int)$c['id'], 'ref_no' => 'C-' . $c['id'], 'name' => (string)$c['name'],
                'status' => (int)$c['is_active'] === 1 ? 'Active' : 'Inactive', 'active' => (int)$c['is_active'] === 1, 'branch' => '',
                'detail' => implode(', ', $detail), 'page' => 'cust.center', 'params' => ['id' => $c['id']]];
        }
        foreach (DB::all('SELECT * FROM vendors WHERE party_id = :p ORDER BY id', ['p' => $partyId]) as $v) {
            $detail = [];
            if ($v['terms_id']) {
                $detail[] = 'Terms ' . ($terms[(int)$v['terms_id']] ?? '');
            }
            if ($v['account_no']) {
                $detail[] = 'Our account no. ' . $v['account_no'];
            }
            $out[] = ['role' => 'supplier', 'label' => 'Supplier', 'ref_id' => (int)$v['id'], 'ref_no' => 'V-' . $v['id'], 'name' => (string)$v['name'],
                'status' => (int)$v['is_active'] === 1 ? 'Active' : 'Inactive', 'active' => (int)$v['is_active'] === 1, 'branch' => '',
                'detail' => implode(', ', $detail), 'page' => 'vend.center', 'params' => ['id' => $v['id']]];
        }
        // water-service accounts (the water-billing module), when it is installed
        if (self::waterReady()) {
            foreach (WaterAccounts::ofParty($partyId) as $w) {
                $active = strtoupper((string)$w['status']) === 'A';
                $detail = array_filter([$w['rc_name'] ? 'Rate code ' . $w['rc_name'] : '', $w['connection_status'] ? ucfirst((string)$w['connection_status']) : '', $w['zone'] ? 'Zone ' . $w['zone'] : '']);
                $out[] = ['role' => 'water', 'label' => self::ROLES['water'], 'ref_id' => (int)$w['pkey'], 'ref_no' => (string)($w['account_number'] ?: 'W-' . $w['pkey']),
                    'name' => WaterAccounts::listName($w), 'status' => $active ? 'Active' : 'Inactive', 'active' => $active, 'branch' => '',
                    'detail' => implode(', ', $detail), 'page' => 'water.member_list', 'params' => ['search' => (string)($w['account_number'] ?: $w['pkey'])]];
            }
        }
        foreach (DB::all('SELECT * FROM other_names WHERE party_id = :p AND member_id IS NULL ORDER BY id', ['p' => $partyId]) as $o) {
            $role = $o['name_type'] === 'employee' ? 'employee' : 'other';
            $out[] = ['role' => $role, 'label' => self::ROLES[$role], 'ref_id' => (int)$o['id'], 'ref_no' => (string)($o['employee_no'] ?: 'N-' . $o['id']), 'name' => (string)$o['name'],
                'status' => (int)$o['is_active'] === 1 ? 'Active' : 'Inactive', 'active' => (int)$o['is_active'] === 1, 'branch' => '',
                'detail' => (string)($o['position'] ?? ''), 'page' => 'coa.names', 'params' => ['a' => 'edit', 'id' => $o['id']]];
        }
        return $out;
    }

    /** The party's member record (the first one; a party holds one membership) */
    public static function member(int $partyId): ?array
    {
        return DB::row('SELECT * FROM members WHERE party_id = :p ORDER BY id LIMIT 1', ['p' => $partyId]);
    }

    public static function isMember(int $partyId): bool
    {
        return (bool)DB::value('SELECT 1 FROM members WHERE party_id = :p LIMIT 1', ['p' => $partyId]);
    }

    /** An active (or applicant) member: member pricing, patronage and nonmember policies look at this */
    public static function isActiveMember(?int $partyId): bool
    {
        return $partyId && (bool)DB::value("SELECT 1 FROM members WHERE party_id = :p AND status IN ('active','applicant','suspended') LIMIT 1", ['p' => $partyId]);
    }

    /** Role records other than the membership (top-level customers, suppliers, non-member other names) */
    public static function roleRecordCount(int $partyId): int
    {
        return (int)DB::value('SELECT (SELECT COUNT(*) FROM customers WHERE party_id = :a AND parent_id IS NULL)
                                    + (SELECT COUNT(*) FROM vendors WHERE party_id = :b)
                                    + (SELECT COUNT(*) FROM other_names WHERE party_id = :c AND member_id IS NULL)', ['a' => $partyId, 'b' => $partyId, 'c' => $partyId]);
    }

    // ------------------------------------------------------------------ confidential details

    public static function canSeeConfidential(): bool
    {
        return (bool)Access::special('coop_view_confidential');
    }

    /** The party's details with a member's confidential ones hidden from users without the permission */
    public static function visible(array $p): array
    {
        if (self::canSeeConfidential() || !self::isMember((int)$p['id'])) {
            $p['_masked'] = false;
            return $p;
        }
        foreach (self::CONFIDENTIAL as $f) {
            if (($p[$f] ?? null) !== null && $p[$f] !== '') {
                $p[$f] = self::mask((string)$p[$f]);
            }
        }
        $p['_masked'] = true;
        return $p;
    }

    public static function mask(string $v): string
    {
        $len = mb_strlen($v);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return str_repeat('*', $len - 3) . mb_substr($v, -3);
    }

    // ------------------------------------------------------------------ identity from the role records

    /** Party fields from a member record (the member leads its party) */
    public static function fromMember(array $m): array
    {
        $kind = $m['member_kind'] ?? 'individual';
        $individual = $kind === 'individual';
        return [
            'party_type' => $kind === 'institutional' ? 'organization' : 'person',
            'legal_name' => $individual ? self::personName($m['first_name'] ?? '', $m['middle_name'] ?? '', $m['last_name'] ?? '', $m['suffix'] ?? '') : (string)$m['full_name'],
            'display_name' => (string)$m['full_name'],
            'last_name' => $individual ? self::nn($m['last_name'] ?? null) : null,
            'first_name' => $individual ? self::nn($m['first_name'] ?? null) : null,
            'middle_name' => $individual ? self::nn($m['middle_name'] ?? null) : null,
            'suffix' => $individual ? self::nn($m['suffix'] ?? null) : null,
            'tin' => self::nn($m['tin'] ?? null),
            'birth_date' => $individual ? self::nn($m['birth_date'] ?? null) : null,
            'email' => self::nn($m['email'] ?? null),
            'mobile' => self::nn($m['mobile'] ?? null),
            'address' => self::nn(Members::address($m)),
            'home_branch_id' => !empty($m['branch_id']) ? (int)$m['branch_id'] : null,
        ];
    }

    public static function fromCustomer(array $c): array
    {
        $company = trim((string)($c['company_name'] ?? ''));
        return [
            'party_type' => $company !== '' ? 'organization' : 'person',
            'legal_name' => $company !== '' ? $company : (string)$c['name'],
            'display_name' => (string)$c['name'],
            'tin' => self::nn($c['tin'] ?? null), 'email' => self::nn($c['email'] ?? null), 'mobile' => self::nn($c['mobile'] ?? null),
            'phone' => self::nn($c['phone'] ?? null), 'address' => self::nn($c['billing_address'] ?? null),
        ];
    }

    public static function fromVendor(array $v): array
    {
        $company = trim((string)($v['company_name'] ?? ''));
        return [
            'party_type' => $company !== '' ? 'organization' : 'person',
            'legal_name' => $company !== '' ? $company : (string)$v['name'],
            'display_name' => (string)$v['name'],
            'tin' => self::nn($v['tin'] ?? null), 'email' => self::nn($v['email'] ?? null),
            'phone' => self::nn($v['phone'] ?? null), 'address' => self::nn($v['address'] ?? null),
        ];
    }

    public static function fromOtherName(array $o): array
    {
        return [
            'party_type' => 'person', 'legal_name' => (string)$o['name'], 'display_name' => (string)$o['name'],
            'tin' => self::nn($o['tin'] ?? null), 'phone' => self::nn($o['phone'] ?? null), 'address' => self::nn($o['address'] ?? null),
        ];
    }

    private static function fields(string $source, array $rec): array
    {
        return match ($source) {
            'member' => self::fromMember($rec),
            'customer' => self::fromCustomer($rec),
            'vendor' => self::fromVendor($rec),
            'other_name' => self::fromOtherName($rec),
            default => fail('Unknown role record.'),
        };
    }

    private static function nn($v): ?string
    {
        $v = trim((string)($v ?? ''));
        return $v === '' ? null : $v;
    }

    private static function record(string $source, int $id): ?array
    {
        $t = self::TABLES[$source] ?? fail('Unknown role record.');
        return DB::row("SELECT * FROM $t WHERE id = :id", ['id' => $id]);
    }

    private static function roleOf(string $source, array $rec): string
    {
        return match ($source) {
            'member' => 'member',
            'customer' => 'customer',
            'vendor' => 'supplier',
            default => ($rec['name_type'] ?? '') === 'employee' ? 'employee' : 'other',
        };
    }

    private static function sourceOf(string $role): string
    {
        return match ($role) {
            'member' => 'member',
            'customer' => 'customer',
            'supplier' => 'vendor',
            'employee', 'other' => 'other_name',
            default => fail('Unknown role.'),
        };
    }

    private static function refText(string $source, array $rec): string
    {
        return mb_substr(match ($source) {
            'member' => $rec['member_no'] . ' ' . $rec['full_name'],
            default => (string)$rec['name'],
        }, 0, 160);
    }

    // ------------------------------------------------------------------ creating and linking

    /** A new party. $f holds party columns (legal_name at least). */
    public static function create(array $f, ?string $srcTable = null, ?int $srcId = null, string $pageKey = 'party.center'): int
    {
        $row = [];
        foreach (self::IDENTITY as $k) {
            if (array_key_exists($k, $f)) {
                $row[$k] = is_string($f[$k]) ? self::nn($f[$k]) : $f[$k];
            }
        }
        $row['legal_name'] = mb_substr(trim((string)($row['legal_name'] ?? '')), 0, 160);
        if ($row['legal_name'] === '') {
            fail('Enter the legal name of the party.');
        }
        $row['display_name'] = mb_substr(trim((string)($row['display_name'] ?? '')) ?: $row['legal_name'], 0, 160);
        $row['party_type'] = isset(self::TYPES[$row['party_type'] ?? '']) ? $row['party_type'] : 'person';
        if (!empty($row['birth_date']) && !valid_date($row['birth_date'])) {
            $row['birth_date'] = null;
        }
        foreach (['tin' => 30, 'email' => 120, 'mobile' => 40, 'phone' => 40, 'address' => 255, 'registration_no' => 60,
                  'last_name' => 80, 'first_name' => 80, 'middle_name' => 80, 'suffix' => 20] as $k => $len) {
            if (isset($row[$k]) && is_string($row[$k])) {
                $row[$k] = mb_substr($row[$k], 0, $len);
            }
        }
        if (isset($f['notes'])) {
            $row['notes'] = self::nn(mb_substr((string)$f['notes'], 0, 255));
        }
        $row['party_no'] = Seq::next('party');
        $row['status'] = 'active';
        // the record the party was first made from (one party per record)
        if ($srcTable && $srcId && !DB::value('SELECT id FROM parties WHERE src_table = :t AND src_id = :i', ['t' => $srcTable, 'i' => $srcId])) {
            $row['src_table'] = $srcTable;
            $row['src_id'] = $srcId;
        }
        $row['created_by'] = Auth::id();
        $id = DB::insert('parties', $row);
        Audit::log('create', 'party', 'party', $id, $row['party_no'] . ' ' . $row['legal_name'], ['snapshot' => $row, 'page_key' => $pageKey]);
        return $id;
    }

    /**
     * Links a role record to a party: the existing party $partyId the user picked, or a new party made from the record.
     * A job takes its customer's party and a member's ledger name its member's party. Returns the party id.
     */
    public static function attach(string $source, int $id, ?int $partyId = null, string $reason = '', string $pageKey = 'party.center'): int
    {
        return DB::tx(function () use ($source, $id, $partyId, $reason, $pageKey) {
            $rec = self::record($source, $id) ?? fail('The record to link was not found.');
            $table = self::TABLES[$source];
            if ($source === 'customer' && !empty($rec['parent_id'])) {
                $pid = self::ensureFor('customer', (int)$rec['parent_id']);
                DB::run('UPDATE customers SET party_id = :p WHERE id = :id', ['p' => $pid, 'id' => $id]);
                return $pid;
            }
            if ($source === 'other_name' && !empty($rec['member_id'])) {
                $pid = self::ensureFor('member', (int)$rec['member_id']);
                DB::run('UPDATE other_names SET party_id = :p WHERE id = :id', ['p' => $pid, 'id' => $id]);
                return $pid;
            }
            if ($partyId === null && !empty($rec['party_id'])) {
                return (int)$rec['party_id'];
            }
            $how = 'created';
            if ($partyId !== null) {
                $p = self::live($partyId) ?? fail('Choose an existing party.');
                if ($p['status'] === 'merged') {
                    fail('Party ' . $p['party_no'] . ' is not active.');
                }
                if ($source === 'member' && ($other = DB::row('SELECT member_no FROM members WHERE party_id = :p AND id <> :id LIMIT 1', ['p' => $p['id'], 'id' => $id]))) {
                    fail($p['display_name'] . ' is already member no. ' . $other['member_no'] . '. A person or organization holds one membership.');
                }
                if ($source === 'member') {
                    // an individual member joins a person's party, an institution an organization's
                    $wantOrg = ($rec['member_kind'] ?? 'individual') === 'institutional';
                    if ($wantOrg !== ($p['party_type'] === 'organization')) {
                        fail($p['display_name'] . ' is ' . ($p['party_type'] === 'organization' ? 'an organization' : 'a person') . ', and this member is '
                            . ($wantOrg ? 'an institution' : ($rec['member_kind'] === 'joint' ? 'a joint account' : 'an individual')) . '. Choose the party of the same kind, or none.');
                    }
                }
                $partyId = (int)$p['id'];
                $how = 'linked';
                self::reactivate($partyId, self::ROLES[self::roleOf($source, $rec)] . ' record linked');
                self::fillBlanks($partyId, self::fields($source, $rec), $pageKey);
            } else {
                $partyId = self::create(self::fields($source, $rec), $source, $id, $pageKey);
            }
            DB::run("UPDATE $table SET party_id = :p WHERE id = :id", ['p' => $partyId, 'id' => $id]);
            if ($source === 'customer') {
                DB::run('UPDATE customers SET party_id = :p WHERE parent_id = :id', ['p' => $partyId, 'id' => $id]);
            }
            if ($source === 'member') {
                DB::run('UPDATE other_names SET party_id = :p WHERE member_id = :id', ['p' => $partyId, 'id' => $id]);
            }
            self::crosswalk($source, $id, self::refText($source, $rec), $partyId, $how);
            self::log($partyId, self::roleOf($source, $rec), $id, $how === 'linked' ? 'linked' : 'granted', $reason !== '' ? $reason : ($how === 'linked' ? 'Linked to an existing party' : 'New record'));
            return $partyId;
        });
    }

    /** The record's party, made now when the record has none (records saved by older code, imports, sample data) */
    public static function ensureFor(string $source, int $id): ?int
    {
        if (!self::ready() || $id <= 0) {
            return null;
        }
        $rec = self::record($source, $id);
        if (!$rec) {
            return null;
        }
        if (!empty($rec['party_id'])) {
            $p = self::get((int)$rec['party_id']);
            if ($p && $p['status'] === 'merged' && $p['merged_into_id']) {
                // a record left on a merged party follows it
                $live = self::live((int)$p['id']);
                DB::run('UPDATE ' . self::TABLES[$source] . ' SET party_id = :p WHERE id = :id', ['p' => $live['id'], 'id' => $id]);
                return (int)$live['id'];
            }
            return (int)$rec['party_id'];
        }
        return self::attach($source, $id);
    }

    /**
     * A role record was saved: the party takes the change. The member leads its party. Any other record leads when
     * it is the party's only role and the party is not a member; otherwise it only fills what the party lacks.
     * $before is the record before the save (null for a new record).
     */
    public static function syncFrom(string $source, int $id, ?array $before = null, string $pageKey = 'party.center'): ?int
    {
        if (!self::ready()) {
            return null;
        }
        $rec = self::record($source, $id);
        if (!$rec) {
            return null;
        }
        if ($source === 'customer' && !empty($rec['parent_id'])) {
            // a job belongs to its customer's party; a customer made a job of another moves there with its documents
            $pid = self::ensureFor('customer', (int)$rec['parent_id']);
            $from = (int)($rec['party_id'] ?? 0);
            if ($pid && $from !== $pid) {
                DB::run('UPDATE customers SET party_id = :p WHERE id = :id', ['p' => $pid, 'id' => $id]);
                if ($from) {
                    DB::run('UPDATE transactions SET party_id = :p WHERE customer_id = :c AND party_id = :f', ['p' => $pid, 'c' => $id, 'f' => $from]);
                    self::crosswalk('customer', $id, (string)$rec['name'], $pid, 'moved');
                    self::log($from, 'customer', $id, 'moved_out', 'Made a job of another customer', $pid);
                    self::log($pid, 'customer', $id, 'moved_in', 'Made a job of this customer', $from);
                }
            }
            return $pid;
        }
        $partyId = self::ensureFor($source, $id);
        if (!$partyId) {
            return null;
        }
        $recActive = $source === 'member' ? in_array($rec['status'] ?? '', ['active', 'applicant', 'suspended'], true) : (int)($rec['is_active'] ?? 0) === 1;
        if ($recActive) {
            self::reactivate($partyId, ucfirst(self::ROLES[self::roleOf($source, $rec)]) . ' record active');
        }
        $p = self::get($partyId);
        $new = self::fields($source, $rec);
        if ($source === 'member') {
            $set = [];
            foreach ($new as $k => $v) {
                if ((string)($p[$k] ?? '') !== (string)($v ?? '')) {
                    // the membership details lead, but a blank on the member does not erase what the party has
                    if (($v === null || $v === '') && in_array($k, self::FILLABLE, true)) {
                        continue;
                    }
                    $set[$k] = $v;
                }
            }
            self::writeParty($p, $set, 'Member record saved', $pageKey);
            return $partyId;
        }
        $leads = !self::isMember($partyId) && self::roleRecordCount($partyId) <= 1;
        $old = $before ? self::fields($source, $before) : null;
        $set = [];
        foreach ($new as $k => $v) {
            $changed = $old === null || (string)($old[$k] ?? '') !== (string)($v ?? '');
            $blank = ($p[$k] ?? null) === null || $p[$k] === '';
            if ($leads && $changed && $old !== null && (string)($p[$k] ?? '') !== (string)($v ?? '')) {
                $set[$k] = $v;
            } elseif ($blank && $v !== null && $v !== '' && in_array($k, self::FILLABLE, true)) {
                $set[$k] = $v;
            }
        }
        self::writeParty($p, $set, ucfirst(self::ROLES[self::roleOf($source, $rec)]) . ' record saved', $pageKey);
        return $partyId;
    }

    /** A member was saved: links it to the chosen existing party (a new member only) or to its own, then the member leads */
    public static function syncMember(int $memberId, ?int $choosePartyId = null, string $pageKey = 'coop.member'): ?int
    {
        if (!self::ready()) {
            return null;
        }
        $m = DB::row('SELECT id, party_id FROM members WHERE id = :id', ['id' => $memberId]);
        if (!$m) {
            return null;
        }
        if (empty($m['party_id'])) {
            self::attach('member', $memberId, $choosePartyId ?: null, $choosePartyId ? 'Enrolled as a member' : '', $pageKey);
        }
        return self::syncFrom('member', $memberId, null, $pageKey);
    }

    private static function fillBlanks(int $partyId, array $f, string $pageKey): void
    {
        $p = self::get($partyId);
        $set = [];
        foreach (self::FILLABLE as $k) {
            if ((($p[$k] ?? null) === null || $p[$k] === '') && isset($f[$k]) && $f[$k] !== null && $f[$k] !== '') {
                $set[$k] = $f[$k];
            }
        }
        self::writeParty($p, $set, 'Details filled from a linked record', $pageKey);
    }

    private static function writeParty(array $p, array $set, string $why, string $pageKey): void
    {
        if (!$set) {
            return;
        }
        if (array_key_exists('legal_name', $set) && trim((string)$set['legal_name']) === '') {
            unset($set['legal_name']);
        }
        if (array_key_exists('display_name', $set) && trim((string)$set['display_name']) === '') {
            unset($set['display_name']);
        }
        if (!$set) {
            return;
        }
        $set['updated_by'] = Auth::id();
        $set['updated_at'] = date('Y-m-d H:i:s');
        DB::update('parties', $set, 'id = :id', ['id' => $p['id']]);
        $after = array_merge($p, $set);
        Audit::log('edit', 'party', 'party', (int)$p['id'], $p['party_no'] . ' ' . $after['legal_name'], ['before' => $p, 'after' => $after, 'reason' => $why, 'page_key' => $pageKey]);
    }

    /** Edits the party itself (the Party Center form). A member's identity and contact details are kept from the member record. */
    public static function update(int $partyId, array $f, string $reason, string $pageKey = 'party.center'): void
    {
        DB::tx(function () use ($partyId, $f, $reason, $pageKey) {
            $p = DB::row('SELECT * FROM parties WHERE id = :id FOR UPDATE', ['id' => $partyId]) ?? fail('Party not found.');
            if ($p['status'] === 'merged') {
                fail('Party ' . $p['party_no'] . ' was merged into another party and cannot be changed.');
            }
            $member = self::member($partyId);
            $set = [];
            foreach (self::IDENTITY as $k) {
                if (!array_key_exists($k, $f)) {
                    continue;
                }
                if ($member && in_array($k, self::MEMBER_LED, true)) {
                    continue;
                }
                $v = is_string($f[$k]) ? self::nn($f[$k]) : $f[$k];
                if ($k === 'birth_date' && $v !== null && !valid_date($v)) {
                    fail('Enter a valid date of birth.');
                }
                if ((string)($p[$k] ?? '') !== (string)($v ?? '')) {
                    $set[$k] = $v;
                }
            }
            if (array_key_exists('notes', $f) && (string)($p['notes'] ?? '') !== (string)self::nn($f['notes'])) {
                $set['notes'] = self::nn(mb_substr((string)$f['notes'], 0, 255));
            }
            if (array_key_exists('legal_name', $set) && trim((string)$set['legal_name']) === '') {
                fail('Enter the legal name.');
            }
            if (array_key_exists('legal_name', $set) && trim($reason) === '') {
                fail('Enter the reason for changing the legal name (new documents print it; documents already issued keep the name they were issued with).');
            }
            if (isset($set['party_type']) && !isset(self::TYPES[$set['party_type']])) {
                unset($set['party_type']);
            }
            // a party nobody deals with any more is made inactive (it leaves the pick lists); only when no role is active
            if (array_key_exists('status', $f) && in_array($f['status'], ['active', 'inactive'], true) && $f['status'] !== $p['status']) {
                if ($f['status'] === 'inactive' && array_filter(self::roles($partyId), fn($r) => $r['active'])) {
                    fail('Party ' . $p['party_no'] . ' still has an active role. Take its roles away first (a membership ends through its status on the Member Center).');
                }
                if (trim($reason) === '') {
                    fail('Enter the reason for making the party ' . strtolower(self::STATUSES[$f['status']]) . '.');
                }
                $set['status'] = $f['status'];
            }
            if (!$set) {
                return;
            }
            $set['updated_by'] = Auth::id();
            $set['updated_at'] = date('Y-m-d H:i:s');
            DB::update('parties', $set, 'id = :id', ['id' => $partyId]);
            $after = array_merge($p, $set);
            Audit::log('edit', 'party', 'party', $partyId, $p['party_no'] . ' ' . $after['legal_name'], ['before' => $p, 'after' => $after, 'reason' => $reason ?: null, 'page_key' => $pageKey]);
            if (isset($set['legal_name']) || isset($set['display_name'])) {
                self::log($partyId, 'party', null, 'edited', 'Name changed from "' . $p['legal_name'] . '"' . ($reason ? ': ' . $reason : ''));
            }
            if (isset($set['status'])) {
                self::log($partyId, 'party', null, 'edited', 'Made ' . strtolower(self::STATUSES[$set['status']]) . ': ' . $reason);
            }
        });
    }

    /** An inactive party that is given a role again, or whose role record becomes active, is active again */
    private static function reactivate(int $partyId, string $why): void
    {
        $p = self::get($partyId);
        if (!$p || $p['status'] !== 'inactive') {
            return;
        }
        DB::update('parties', ['status' => 'active', 'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $partyId]);
        self::log($partyId, 'party', null, 'edited', 'Made active: ' . $why);
        Audit::log('edit', 'party', 'party', $partyId, $p['party_no'] . ' ' . $p['legal_name'], ['changes' => [['field' => 'status', 'old' => 'inactive', 'new' => 'active']], 'reason' => $why]);
    }

    // ------------------------------------------------------------------ roles: grant, revoke, restore, move

    /**
     * Gives the party a role by making the role record from the party's details (a customer, supplier, employee or
     * other name). A role the party had before and lost is restored instead, so its number and history continue.
     * Returns the role record id.
     */
    public static function grant(int $partyId, string $role, string $reason = '', array $opt = [], string $pageKey = 'party.center'): int
    {
        return DB::tx(function () use ($partyId, $role, $reason, $opt, $pageKey) {
            $p = self::live($partyId) ?? fail('Party not found.');
            if ($p['status'] === 'merged') {
                fail('Party ' . $p['party_no'] . ' is not active.');
            }
            $partyId = (int)$p['id'];
            if ($role === 'member') {
                fail('Enroll a member on the Member Information page (it asks for the membership details); choose this party there.');
            }
            self::reactivate($partyId, self::ROLES[$role] . ' role given');
            $source = self::sourceOf($role);
            $org = $p['party_type'] === 'organization';
            if ($source === 'customer') {
                $old = DB::row('SELECT * FROM customers WHERE party_id = :p AND parent_id IS NULL ORDER BY is_active DESC, id LIMIT 1', ['p' => $partyId]);
                if ($old && (int)$old['is_active'] === 1) {
                    fail($p['display_name'] . ' is already a customer (' . $old['name'] . ').');
                }
                if ($old) {
                    self::restore('customer', (int)$old['id'], $reason ?: 'Customer role given again', $pageKey);
                    return (int)$old['id'];
                }
                $data = [
                    'party_id' => $partyId, 'name' => self::uniqueName('customers', $p['display_name'], 'parent_id IS NULL'),
                    'company_name' => $org ? mb_substr($p['legal_name'], 0, 120) : null,
                    'phone' => $p['phone'], 'mobile' => $p['mobile'], 'email' => $p['email'], 'billing_address' => $p['address'], 'tin' => $p['tin'],
                    'terms_id' => !empty($opt['terms_id']) ? (int)$opt['terms_id'] : null,
                    'credit_limit' => isset($opt['credit_limit']) ? r2((float)$opt['credit_limit']) : 0,
                    // no level of its own: the member or nonmember level of the line of business applies, and follows the membership
                    'price_level_id' => !empty($opt['price_level_id']) ? (int)$opt['price_level_id'] : null,
                    'allow_pos_charge' => !empty($opt['allow_pos_charge']) ? 1 : 0, 'is_active' => 1,
                ];
                $id = DB::insert('customers', $data);
                Audit::log('create', 'cust', 'customer', $id, $data['name'], ['snapshot' => $data, 'page_key' => $pageKey, 'reason' => 'Customer role of party ' . $p['party_no']]);
            } elseif ($source === 'vendor') {
                $old = DB::row('SELECT * FROM vendors WHERE party_id = :p ORDER BY is_active DESC, id LIMIT 1', ['p' => $partyId]);
                if ($old && (int)$old['is_active'] === 1) {
                    fail($p['display_name'] . ' is already a supplier (' . $old['name'] . ').');
                }
                if ($old) {
                    self::restore('supplier', (int)$old['id'], $reason ?: 'Supplier role given again', $pageKey);
                    return (int)$old['id'];
                }
                $data = [
                    'party_id' => $partyId, 'name' => self::uniqueName('vendors', $p['display_name']),
                    'company_name' => $org ? mb_substr($p['legal_name'], 0, 120) : null,
                    'phone' => $p['phone'] ?: $p['mobile'], 'email' => $p['email'], 'address' => $p['address'], 'tin' => $p['tin'],
                    'terms_id' => !empty($opt['terms_id']) ? (int)$opt['terms_id'] : null, 'is_active' => 1,
                ];
                $id = DB::insert('vendors', $data);
                Audit::log('create', 'vend', 'vendor', $id, $data['name'], ['snapshot' => $data, 'page_key' => $pageKey, 'reason' => 'Supplier role of party ' . $p['party_no']]);
            } else {
                $type = $role === 'employee' ? 'employee' : 'other';
                $old = DB::row('SELECT * FROM other_names WHERE party_id = :p AND member_id IS NULL AND name_type = :t ORDER BY is_active DESC, id LIMIT 1', ['p' => $partyId, 't' => $type]);
                if ($old && (int)$old['is_active'] === 1) {
                    fail($p['display_name'] . ' is already on Employees & Other Names (' . $old['name'] . ').');
                }
                if ($old) {
                    self::restore($role, (int)$old['id'], $reason ?: self::ROLES[$role] . ' role given again', $pageKey);
                    return (int)$old['id'];
                }
                $data = ['party_id' => $partyId, 'name_type' => $type, 'name' => self::uniqueName('other_names', $p['display_name'], 'name_type = ' . DB::pdo()->quote($type)),
                    'employee_no' => self::nn($opt['employee_no'] ?? null), 'position' => self::nn($opt['position'] ?? null),
                    'phone' => $p['mobile'] ?: $p['phone'], 'address' => $p['address'], 'tin' => $p['tin'], 'is_active' => 1];
                $id = DB::insert('other_names', $data);
                Names::reset();
                Audit::log('create', 'coa', 'other_name', $id, $data['name'], ['snapshot' => $data, 'page_key' => $pageKey, 'reason' => self::ROLES[$role] . ' role of party ' . $p['party_no']]);
            }
            self::crosswalk($source, $id, mb_substr($data['name'], 0, 160), $partyId, 'created');
            self::log($partyId, $role, $id, 'granted', $reason !== '' ? $reason : 'Role given on the Party Center');
            return $id;
        });
    }

    /** Takes a customer, supplier, employee or other-name role away: the record becomes inactive, nothing is deleted */
    public static function revoke(string $role, int $refId, string $reason, string $pageKey = 'party.center'): void
    {
        self::setRoleActive($role, $refId, false, $reason, $pageKey);
    }

    public static function restore(string $role, int $refId, string $reason, string $pageKey = 'party.center'): void
    {
        self::setRoleActive($role, $refId, true, $reason, $pageKey);
    }

    private static function setRoleActive(string $role, int $refId, bool $on, string $reason, string $pageKey): void
    {
        if ($role === 'member') {
            fail('Membership is ended or restored through its status on the Member Center (resignation, termination, reinstatement).');
        }
        if (trim($reason) === '') {
            fail('Enter the reason.');
        }
        DB::tx(function () use ($role, $refId, $on, $reason, $pageKey) {
            $source = self::sourceOf($role);
            $table = self::TABLES[$source];
            $rec = DB::row("SELECT * FROM $table WHERE id = :id FOR UPDATE", ['id' => $refId]) ?? fail('Role record not found.');
            if ((int)$rec['is_active'] === ($on ? 1 : 0)) {
                fail('The ' . strtolower(self::ROLES[$role]) . ' record is already ' . ($on ? 'active' : 'inactive') . '.');
            }
            DB::update($table, ['is_active' => $on ? 1 : 0], 'id = :id', ['id' => $refId]);
            if ($source === 'customer') {
                // the jobs follow their customer
                DB::run('UPDATE customers SET is_active = :a WHERE parent_id = :id', ['a' => $on ? 1 : 0, 'id' => $refId]);
            }
            if ($source === 'other_name') {
                Names::reset();
            }
            $partyId = (int)($rec['party_id'] ?: self::ensureFor($source, $refId));
            self::log($partyId, $role, $refId, $on ? 'restored' : 'revoked', $reason);
            if ($on) {
                self::reactivate($partyId, self::ROLES[$role] . ' role restored');
            }
            Audit::log($on ? 'role_restored' : 'role_revoked', 'party', $source, $refId, (string)$rec['name'], [
                'page_key' => $pageKey, 'reason' => $reason, 'changes' => [['field' => 'is_active', 'old' => $rec['is_active'], 'new' => $on ? 1 : 0]],
            ]);
        });
    }

    /**
     * Moves a role record (and the party on its documents) to another party, or to a new party made from the record
     * when $toPartyId is null: corrects a record that was linked to the wrong party. Needs the party_merge permission
     * and a reason. The documents keep the name they were issued with.
     */
    public static function move(string $role, int $refId, ?int $toPartyId, string $reason, string $pageKey = 'party.center'): int
    {
        if (!Access::special('party_merge')) {
            fail('You are not allowed to move roles between parties (special permission: link or move parties).');
        }
        if (trim($reason) === '') {
            fail('Enter the reason for moving the role.');
        }
        if ($role === 'member') {
            fail('A membership cannot be moved. If two parties are the same person, link them on Identity Review.');
        }
        return DB::tx(function () use ($role, $refId, $toPartyId, $reason, $pageKey) {
            $source = self::sourceOf($role);
            $table = self::TABLES[$source];
            $rec = DB::row("SELECT * FROM $table WHERE id = :id FOR UPDATE", ['id' => $refId]) ?? fail('Role record not found.');
            $from = (int)($rec['party_id'] ?: self::ensureFor($source, $refId));
            if ($toPartyId) {
                $to = self::live($toPartyId) ?? fail('Choose the party to move the role to.');
                if ($to['status'] !== 'active') {
                    fail('Party ' . $to['party_no'] . ' is not active.');
                }
                $toId = (int)$to['id'];
                if ($toId === $from) {
                    fail('The role is already on that party.');
                }
            } else {
                $toId = self::create(self::fields($source, $rec), null, null, $pageKey);
            }
            DB::run("UPDATE $table SET party_id = :p WHERE id = :id", ['p' => $toId, 'id' => $refId]);
            $ids = [$refId];
            if ($source === 'customer') {
                DB::run('UPDATE customers SET party_id = :p WHERE parent_id = :id', ['p' => $toId, 'id' => $refId]);
                $ids = array_merge($ids, array_map('intval', DB::column('SELECT id FROM customers WHERE parent_id = :id', ['id' => $refId])));
            }
            $col = ['customer' => 'customer_id', 'vendor' => 'vendor_id', 'other_name' => 'other_name_id'][$source];
            [$in, $ip] = DB::in('mv', $ids);
            $docs = DB::run("UPDATE transactions SET party_id = :p WHERE $col IN $in AND party_id = :f", ['p' => $toId, 'f' => $from] + $ip)->rowCount();
            DB::run('UPDATE party_crosswalk SET party_id = :p, how = \'moved\' WHERE source = :s AND source_id = :i', ['p' => $toId, 's' => $source, 'i' => $refId]);
            self::crosswalk($source, $refId, self::refText($source, $rec), $toId, 'moved');
            self::log($from, $role, $refId, 'moved_out', $reason, $toId);
            self::log($toId, $role, $refId, 'moved_in', $reason, $from);
            Audit::log('role_moved', 'party', $source, $refId, (string)$rec['name'], ['page_key' => $pageKey, 'reason' => $reason,
                'changes' => [['field' => 'party_id', 'old' => $from, 'new' => $toId], ['field' => 'documents', 'old' => '', 'new' => $docs . ' document(s) moved']]]);
            return $toId;
        });
    }

    /**
     * Links two parties found to be the same person or organization: every role record and document of $dropId goes
     * to $keepId, details $keepId lacks are filled from $dropId, and $dropId stays as a merged party pointing to $keepId
     * (its number keeps working through the crosswalk). Needs the party_merge permission and a reason.
     */
    public static function merge(int $keepId, int $dropId, string $reason, ?int $approvedBy = null, string $pageKey = 'party.review'): void
    {
        if (!Access::special('party_merge')) {
            fail('You are not allowed to link parties (special permission: link or move parties).');
        }
        if (trim($reason) === '') {
            fail('Enter the reason for linking the two parties (what shows they are the same).');
        }
        if ($keepId === $dropId) {
            fail('Choose two different parties.');
        }
        DB::tx(function () use ($keepId, $dropId, $reason, $approvedBy, $pageKey) {
            $keep = DB::row('SELECT * FROM parties WHERE id = :id FOR UPDATE', ['id' => $keepId]) ?? fail('Party not found.');
            $drop = DB::row('SELECT * FROM parties WHERE id = :id FOR UPDATE', ['id' => $dropId]) ?? fail('Party not found.');
            if ($keep['status'] === 'merged' || $drop['status'] === 'merged') {
                fail('One of the parties was already merged.');
            }
            $mk = self::member($keepId);
            $md = self::member($dropId);
            if ($mk && $md) {
                fail('Both parties are members (' . $mk['member_no'] . ' and ' . $md['member_no'] . '). A person holds one membership: settle and close one membership first.');
            }
            foreach (['customers', 'vendors', 'members', 'other_names'] as $t) {
                DB::run("UPDATE $t SET party_id = :k WHERE party_id = :d", ['k' => $keepId, 'd' => $dropId]);
            }
            if (self::waterReady()) {
                // the water-service accounts and their postings follow; their receivables keep posting to the customer that stays
                DB::run('UPDATE water_accounts SET party_id = :k WHERE party_id = :d', ['k' => $keepId, 'd' => $dropId]);
                DB::run('UPDATE water_postings SET party_id = :k WHERE party_id = :d', ['k' => $keepId, 'd' => $dropId]);
                $keepCust = DB::value('SELECT id FROM customers WHERE party_id = :k AND parent_id IS NULL AND is_active = 1 ORDER BY id LIMIT 1', ['k' => $keepId]);
                if ($keepCust) {
                    DB::run('UPDATE water_accounts SET customer_id = :c WHERE party_id = :k', ['c' => $keepCust, 'k' => $keepId]);
                }
            }
            $docs = DB::run('UPDATE transactions SET party_id = :k WHERE party_id = :d', ['k' => $keepId, 'd' => $dropId])->rowCount();
            DB::run('UPDATE party_crosswalk SET party_id = :k WHERE party_id = :d', ['k' => $keepId, 'd' => $dropId]);
            self::crosswalk('party', $dropId, mb_substr($drop['party_no'] . ' ' . $drop['legal_name'], 0, 160), $keepId, 'merged');
            DB::update('parties', ['status' => 'merged', 'merged_into_id' => $keepId, 'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $dropId]);
            self::fillBlanks($keepId, $drop, $pageKey);
            if ($keep['status'] === 'inactive' && array_filter(self::roles($keepId), fn($r) => $r['active'])) {
                self::reactivate($keepId, 'Linked with ' . $drop['party_no'] . ', which has an active role');
            }
            if (!$mk && $md) {
                // the membership came from the other party: the member's registered details lead
                self::syncFrom('member', (int)$md['id'], null, $pageKey);
            }
            [$a, $b] = [min($keepId, $dropId), max($keepId, $dropId)];
            $exists = DB::value('SELECT id FROM party_match_reviews WHERE party_a = :a AND party_b = :b', ['a' => $a, 'b' => $b]);
            if ($exists) {
                DB::update('party_match_reviews', ['status' => 'merged', 'decided_by' => Auth::id(), 'decided_at' => date('Y-m-d H:i:s'), 'reason' => mb_substr($reason, 0, 255)], 'id = :id', ['id' => $exists]);
            } else {
                DB::insert('party_match_reviews', ['party_a' => $a, 'party_b' => $b, 'basis' => 'Linked by hand', 'score' => 0, 'status' => 'merged',
                    'decided_by' => Auth::id(), 'decided_at' => date('Y-m-d H:i:s'), 'reason' => mb_substr($reason, 0, 255)]);
            }
            // undecided proposals that involve the merged party are found again against the party that stays
            DB::run("DELETE FROM party_match_reviews WHERE status = 'open' AND (party_a = :d1 OR party_b = :d2)", ['d1' => $dropId, 'd2' => $dropId]);
            self::log($keepId, 'party', null, 'merged', $reason, $dropId, $approvedBy);
            self::log($dropId, 'party', null, 'merged', $reason, $keepId, $approvedBy);
            Audit::log('merge', 'party', 'party', $keepId, $keep['party_no'] . ' ' . $keep['legal_name'] . ' <- ' . $drop['party_no'] . ' ' . $drop['legal_name'], [
                'page_key' => $pageKey, 'reason' => $reason, 'approved_by' => $approvedBy, 'snapshot' => ['kept' => $keep, 'merged' => $drop, 'documents_moved' => $docs],
            ]);
        });
    }

    /** Records that two parties were checked and are different people or organizations */
    public static function markDistinct(int $reviewId, string $reason, string $pageKey = 'party.review'): void
    {
        if (trim($reason) === '') {
            fail('Enter the reason (what shows they are different).');
        }
        $r = DB::row('SELECT * FROM party_match_reviews WHERE id = :id', ['id' => $reviewId]) ?? fail('Review not found.');
        if ($r['status'] !== 'open') {
            fail('This pair was already decided.');
        }
        DB::update('party_match_reviews', ['status' => 'distinct', 'decided_by' => Auth::id(), 'decided_at' => date('Y-m-d H:i:s'), 'reason' => mb_substr($reason, 0, 255)], 'id = :id', ['id' => $reviewId]);
        Audit::log('match_distinct', 'party', 'party_match', $reviewId, 'Parties ' . $r['party_a'] . ' and ' . $r['party_b'] . ' are different', ['page_key' => $pageKey, 'reason' => $reason]);
    }

    private static function uniqueName(string $table, string $name, string $where = '1 = 1'): string
    {
        $base = mb_substr(trim($name), 0, 110);
        $try = $base;
        for ($n = 2; $n < 500; $n++) {
            if (!DB::value("SELECT id FROM $table WHERE name = :n AND $where", ['n' => $try])) {
                return $try;
            }
            $try = $base . ' (' . $n . ')';
        }
        return $base . ' ' . bin2hex(random_bytes(2));
    }

    public static function crosswalk(string $source, int $sourceId, string $ref, int $partyId, string $how): void
    {
        DB::run('INSERT INTO party_crosswalk (source, source_id, source_ref, party_id, how) VALUES (:s, :i, :r, :p, :h)
                 ON DUPLICATE KEY UPDATE party_id = VALUES(party_id), how = VALUES(how)',
            ['s' => $source, 'i' => $sourceId, 'r' => mb_substr($ref, 0, 160), 'p' => $partyId, 'h' => $how]);
    }

    public static function log(int $partyId, string $role, ?int $refId, string $action, string $reason = '', ?int $otherParty = null, ?int $approvedBy = null): void
    {
        DB::insert('party_role_log', ['party_id' => $partyId, 'role' => $role, 'ref_id' => $refId, 'action' => $action, 'other_party_id' => $otherParty,
            'reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null, 'approved_by' => $approvedBy, 'created_by' => Auth::id()]);
    }

    /** A member's status changed: the membership role is recorded as ended or restored on the party */
    public static function memberStatusChanged(int $memberId, string $from, string $to, string $reason): void
    {
        if (!self::ready()) {
            return;
        }
        $on = ['active', 'applicant', 'suspended'];
        $wasOn = in_array($from, $on, true);
        $isOn = in_array($to, $on, true);
        if ($wasOn === $isOn) {
            return;
        }
        $pid = self::ensureFor('member', $memberId);
        if ($pid) {
            self::log($pid, 'member', $memberId, $isOn ? 'restored' : 'revoked', (Coop::MEMBER_STATUSES[$to] ?? $to) . ': ' . $reason);
        }
    }

    // ------------------------------------------------------------------ identity matching

    /** "Santos, Maria R." and "Maria Santos" give the same key: lower case, no punctuation or initials, words sorted */
    public static function normName(string $name): string
    {
        $s = mb_strtolower($name);
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false && $t !== '') {
            $s = $t;
        }
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        $words = array_filter(explode(' ', $s), fn($w) => strlen($w) > 1);
        sort($words);
        return implode(' ', $words);
    }

    public static function digits(?string $v): string
    {
        return preg_replace('/\D+/', '', (string)$v);
    }

    /** The keys two records of the same person or organization would share: [basis => [key, score]] */
    private static function keys(array $f): array
    {
        $out = [];
        $tin = substr(self::digits($f['tin'] ?? ''), 0, 9);
        if (strlen($tin) === 9 && trim($tin, '0') !== '') {
            $out['Same TIN'] = ['t:' . $tin, 60];
        }
        $email = mb_strtolower(trim((string)($f['email'] ?? '')));
        if ($email !== '' && str_contains($email, '@')) {
            $out['Same e-mail'] = ['e:' . $email, 30];
        }
        // a mobile number is often typed in the phone box of a customer or supplier: both count, by their last ten digits
        foreach (['mobile' => 'Same mobile number', 'phone' => 'Same phone number'] as $col => $label) {
            $num = self::digits($f[$col] ?? '');
            $k = 'm:' . substr($num, -10);
            if (strlen($num) >= 10 && !in_array($k, array_column($out, 0), true)) {
                $out[$label] = [$k, 30];
            }
        }
        $names = array_unique(array_filter([self::normName((string)($f['legal_name'] ?? '')), self::normName((string)($f['display_name'] ?? ''))]));
        foreach (array_values($names) as $i => $n) {
            if (!empty($f['birth_date'])) {
                $out['Same name and date of birth' . ($i ? ' ' : '')] = ['b:' . $n . '|' . $f['birth_date'], 50];
            }
            $out['Same name' . ($i ? ' ' : '')] = ['n:' . $n, 20];
        }
        return $out;
    }

    /**
     * Possible existing parties for details about to be saved as a new party (customer, supplier, member, other name
     * or party forms). Returns [party_id => ['party' => row, 'basis' => [...], 'roles' => [...]]], best first.
     */
    public static function candidatesFor(array $f, ?int $excludeParty = null): array
    {
        if (!self::ready()) {
            return [];
        }
        $want = self::keys($f);
        if (!$want) {
            return [];
        }
        $where = [];
        $params = [];
        $tin = substr(self::digits($f['tin'] ?? ''), 0, 9);
        if (isset($want['Same TIN'])) {
            $where[] = 'REPLACE(REPLACE(REPLACE(tin, \'-\', \'\'), \' \', \'\'), \'.\', \'\') LIKE :tin';
            $params['tin'] = $tin . '%';
        }
        if (isset($want['Same e-mail'])) {
            $where[] = 'email = :email';
            $params['email'] = trim((string)$f['email']);
        }
        $n = 0;
        foreach (['Same mobile number', 'Same phone number'] as $label) {
            if (isset($want[$label])) {
                $n++;
                foreach (['mobile', 'phone'] as $col) {
                    $where[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($col, '-', ''), ' ', ''), '+', ''), '(', ''), ')', '') LIKE :num{$n}{$col}";
                    $params["num{$n}{$col}"] = '%' . substr($want[$label][0], 2);
                }
            }
        }
        // names: the longest word narrows the search, the normalized comparison decides
        $words = explode(' ', self::normName((string)($f['legal_name'] ?? '') . ' ' . (string)($f['display_name'] ?? '')));
        usort($words, fn($a, $b) => strlen($b) <=> strlen($a));
        if (($words[0] ?? '') !== '' && strlen($words[0]) >= 3) {
            $where[] = '(legal_name LIKE :w1 OR display_name LIKE :w2)';
            $params['w1'] = '%' . $words[0] . '%';
            $params['w2'] = '%' . $words[0] . '%';
        }
        if (!$where) {
            return [];
        }
        $rows = DB::all("SELECT * FROM parties WHERE status IN ('active', 'inactive') AND id <> :x AND (" . implode(' OR ', $where) . ') LIMIT 300', ['x' => $excludeParty ?? 0] + $params);
        $out = [];
        $wantKeys = [];
        foreach ($want as $basis => [$k, $score]) {
            $wantKeys[$k] = [trim($basis), $score];
        }
        foreach ($rows as $r) {
            $basis = [];
            $score = 0;
            foreach (self::keys($r) as [$k]) {
                if (isset($wantKeys[$k]) && !in_array($wantKeys[$k][0], $basis, true)) {
                    $basis[] = $wantKeys[$k][0];
                    $score += $wantKeys[$k][1];
                }
            }
            if ($basis) {
                $out[(int)$r['id']] = ['party' => $r, 'basis' => $basis, 'score' => $score];
            }
        }
        uasort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        $roles = self::roleNamesAll(array_keys($out));
        foreach ($out as $id => &$c) {
            $c['roles'] = $roles[$id] ?? [];
        }
        return $out;
    }

    /** The message shown when a new record looks like an existing party */
    public static function candidatesMessage(array $cands, string $what, string $tick = 'This is a different person or organization'): string
    {
        $list = [];
        $inactive = false;
        foreach (array_slice($cands, 0, 4, true) as $c) {
            $off = ($c['party']['status'] ?? 'active') === 'inactive';
            $inactive = $inactive || $off;
            $list[] = $c['party']['display_name'] . ' (' . $c['party']['party_no'] . ($off ? ', inactive' : '') . ($c['roles'] ? ', ' . implode(', ', $c['roles']) : '') . '; ' . strtolower(implode(', ', $c['basis'])) . ')';
        }
        // one name per member: a member is already the cooperative's customer and supplier under the member's name
        $member = null;
        if (in_array($what, ['customer', 'supplier'], true)) {
            foreach (array_keys(array_slice($cands, 0, 4, true)) as $pid) {
                if ($member = self::member((int)$pid)) {
                    break;
                }
            }
        }
        if ($member) {
            return 'This ' . $what . ' may already be on file: ' . implode('; ', $list) . '. ' . $member['full_name'] . ' is a cooperative member and is already a '
                . $what . ' of the cooperative under the member\'s name: enter the ' . $what . ' details on the member\'s '
                . ($what === 'customer' ? 'Customer Defaults' : 'Vendor Defaults') . ' tab (Members & Others > Member Center > the member). If this is a different person or organization, tick "'
                . $tick . '" to make a new one.';
        }
        return 'This ' . $what . ' may already be on file: ' . implode('; ', $list) . '. Choose the existing party'
            . ($inactive ? ' (an inactive party is made active again on the Party Center first, or by giving it the role there)' : '')
            . ', or tick "' . $tick . '" to make a new one.';
    }

    /**
     * Scans every party for possible duplicates and records the new pairs for review. Pairs already decided stay
     * decided. Returns the number of new pairs.
     */
    public static function findMatches(): int
    {
        $rows = DB::all("SELECT id, legal_name, display_name, tin, email, mobile, phone, birth_date FROM parties WHERE status <> 'merged'");
        $groups = [];
        foreach ($rows as $r) {
            foreach (self::keys($r) as $basis => [$k, $score]) {
                $groups[$k][(int)$r['id']] = [trim($basis), $score];
            }
        }
        $pairs = [];
        foreach ($groups as $members) {
            if (count($members) < 2 || count($members) > 40) {
                continue;  // a key shared by very many parties (a placeholder TIN or e-mail) says nothing
            }
            $ids = array_keys($members);
            sort($ids);
            foreach ($ids as $i => $a) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    $b = $ids[$j];
                    [$basis, $score] = $members[$a];
                    $pairs["$a-$b"]['basis'][$basis] = $score;
                }
            }
        }
        $new = 0;
        foreach ($pairs as $key => $p) {
            [$a, $b] = array_map('intval', explode('-', $key));
            $basis = implode(', ', array_keys($p['basis']));
            $score = array_sum($p['basis']);
            $old = DB::row('SELECT id, status FROM party_match_reviews WHERE party_a = :a AND party_b = :b', ['a' => $a, 'b' => $b]);
            if (!$old) {
                DB::insert('party_match_reviews', ['party_a' => $a, 'party_b' => $b, 'basis' => mb_substr($basis, 0, 120), 'score' => $score, 'status' => 'open']);
                $new++;
            } elseif ($old['status'] === 'open') {
                DB::update('party_match_reviews', ['basis' => mb_substr($basis, 0, 120), 'score' => $score], 'id = :id', ['id' => $old['id']]);
            }
        }
        return $new;
    }

    /**
     * The possible duplicates of one party, recorded for Identity Review (the pairs a full scan would find for it).
     * Returns the number of new pairs.
     */
    public static function proposeFor(int $partyId): int
    {
        $p = self::get($partyId);
        if (!$p || $p['status'] === 'merged') {
            return 0;
        }
        $new = 0;
        foreach (self::candidatesFor($p, $partyId) as $cid => $c) {
            [$a, $b] = [min($partyId, $cid), max($partyId, $cid)];
            if (!DB::value('SELECT id FROM party_match_reviews WHERE party_a = :a AND party_b = :b', ['a' => $a, 'b' => $b])) {
                DB::insert('party_match_reviews', ['party_a' => $a, 'party_b' => $b, 'basis' => mb_substr(implode(', ', $c['basis']), 0, 120), 'score' => (int)$c['score'], 'status' => 'open']);
                $new++;
            }
        }
        return $new;
    }

    // ------------------------------------------------------------------ documents

    /**
     * The counterparty of a document as it should be printed, from the party master at the time of issue:
     * [party_id, cp_role, cp_name, cp_address, cp_tin, cp_member_no]. $h holds the document header
     * (txn_type, customer_id, vendor_id, other_name_id, payee_name, billing_address).
     */
    public static function counterparty(array $h): array
    {
        $type = (string)($h['txn_type'] ?? '');
        $out = ['party_id' => null, 'cp_role' => self::docRole($type), 'cp_name' => null, 'cp_address' => null, 'cp_tin' => null, 'cp_member_no' => null];
        $rec = null;
        $partyId = null;
        if (!empty($h['customer_id'])) {
            $partyId = self::ensureFor('customer', (int)$h['customer_id']);
            $rec = DB::row('SELECT c.billing_address AS address, c.tin, p.billing_address AS parent_address, p.tin AS parent_tin FROM customers c LEFT JOIN customers p ON p.id = c.parent_id WHERE c.id = :id', ['id' => $h['customer_id']]);
            if ($rec) {
                $rec['address'] = $rec['address'] ?: $rec['parent_address'];
                $rec['tin'] = $rec['tin'] ?: $rec['parent_tin'];
            }
        } elseif (!empty($h['vendor_id'])) {
            $partyId = self::ensureFor('vendor', (int)$h['vendor_id']);
            $rec = DB::row('SELECT address, tin FROM vendors WHERE id = :id', ['id' => $h['vendor_id']]);
        } elseif (!empty($h['other_name_id'])) {
            $partyId = self::ensureFor('other_name', (int)$h['other_name_id']);
            $rec = DB::row('SELECT address, tin FROM other_names WHERE id = :id', ['id' => $h['other_name_id']]);
        }
        $p = $partyId ? self::get($partyId) : null;
        if ($p) {
            $out['party_id'] = (int)$p['id'];
            $out['cp_name'] = mb_substr((string)$p['legal_name'], 0, 160);
            $addr = trim((string)($h['billing_address'] ?? ''));
            $out['cp_address'] = mb_substr($addr !== '' ? $addr : (string)(($rec['address'] ?? '') ?: ($p['address'] ?? '')), 0, 255) ?: null;
            $out['cp_tin'] = (($rec['tin'] ?? '') ?: ($p['tin'] ?? '')) ?: null;
            $m = DB::row("SELECT member_no FROM members WHERE party_id = :p ORDER BY status IN ('active','applicant','suspended') DESC, id LIMIT 1", ['p' => $p['id']]);
            $out['cp_member_no'] = $m['member_no'] ?? null;
        } else {
            $walkIn = trim((string)($h['payee_name'] ?? ''));
            $out['cp_name'] = $walkIn !== '' ? mb_substr($walkIn, 0, 160) : null;
            $addr = trim((string)($h['billing_address'] ?? ''));
            $out['cp_address'] = $addr !== '' ? mb_substr($addr, 0, 255) : null;
        }
        return $out;
    }

    /** The label of the counterparty on a document of this type */
    public static function docRole(string $type): string
    {
        return match (true) {
            in_array($type, ['estimate', 'sales_order', 'invoice', 'sales_receipt', 'statement_charge', 'finance_charge', 'credit_memo', 'pos_sale', 'pos_return'], true) => 'customer',
            in_array($type, ['payment', 'coop_or'], true) => 'payer',
            in_array($type, ['purchase_order', 'bill', 'vendor_credit', 'item_receipt'], true) => 'supplier',
            in_array($type, ['check', 'bill_payment', 'refund', 'vendor_advance', 'cash_voucher', 'coop_cv', 'loan_release'], true) => 'payee',
            default => 'name',
        };
    }

    /** "Bill to", "Supplier", "Pay to", "Received from", "Name" */
    public static function roleLabel(?string $cpRole, string $txnType = ''): string
    {
        $cpRole = $cpRole ?: self::docRole($txnType);
        return match ($cpRole) {
            'customer' => in_array($txnType, ['invoice', 'statement_charge', 'finance_charge', 'estimate', 'sales_order'], true) ? 'Bill to' : 'Customer',
            'supplier' => 'Supplier',
            'payee' => 'Pay to',
            'payer' => 'Received from',
            default => 'Name',
        };
    }
}
