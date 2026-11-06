<?php
declare(strict_types=1);

/**
 * One name for a cooperative member in every line of business.
 *
 * A member who buys from the cooperative, sells to it or takes water is recorded once, on the membership form. The
 * books still need a customer record (receivables, sales), a supplier record (payables, purchases) and a
 * water-service account (meter, rate code, bills), but those are the member's own records, kept by the program:
 *  - they are made with the member (Members::ensureCustomerRoles / ensureVendorRoles, on the member's party);
 *  - their name, TIN and contact details follow the member record (syncRoles);
 *  - their defaults are entered on the member form, on the tabs Water Defaults, Customer Defaults and Vendor
 *    Defaults (saveCustomer, saveVendor, applyWater), so no second form is filled for the same person;
 *  - the name pickers list the member once (Names, code m<member id>) and the role the member plays is chosen by
 *    the account of the entry (receivable: the customer record, payable: the supplier record).
 */
final class MemberDefaults
{
    public const CONNECTION = ['active' => 'Active', 'inactive' => 'Inactive'];
    /** customer columns kept on the Customer Defaults tab (plus credit_limit and wht_ids) */
    public const CUSTOMER_IDS = ['terms_id', 'tax_code_id', 'payment_method_id', 'customer_type_id', 'sales_rep_id', 'billing_group_id'];
    /** supplier columns kept on the Vendor Defaults tab (plus credit_limit, account_no and wht_ids) */
    public const VENDOR_IDS = ['terms_id', 'default_expense_account_id', 'tax_code_id'];
    /** member columns of the Water Defaults tab (the address sub-group is a member list of its own) */
    public const WATER_FIELDS = ['water_zone', 'water_sequence', 'water_rc_id', 'water_pipe_size_id', 'water_connection_date', 'water_disconnection_date', 'water_connection_status'];

    private const WATER_CIVIL = ['single' => 'Single', 'married' => 'Married', 'separated' => 'Separated', 'annulled' => 'Separated'];

    private static ?bool $ready = null;
    private static ?array $index = null;

    /** The member columns of the defaults are installed (upgrade 2026_12_27) */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                DB::value('SELECT COUNT(water_rc_id) + COUNT(water_account_id) FROM members WHERE 1 = 0');
                self::$ready = true;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** The water-billing module is installed and the member can have water defaults */
    public static function waterReady(): bool
    {
        return self::ready() && Party::ready() && Party::waterReady();
    }

    public static function reset(): void
    {
        self::$index = null;
    }

    // ------------------------------------------------------------------ the member behind a record

    /**
     * Who stands behind the records the name pickers know:
     * ['m' => [member id => row], 'c' => [customer id => member id], 'v' => [vendor id => member id], 'o' => [other name id => member id]]
     * Only the member's own customer (not its jobs) and supplier records, and its member name (not an employee record).
     */
    public static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }
        $out = ['m' => [], 'c' => [], 'v' => [], 'o' => []];
        try {
            foreach (DB::all('SELECT m.id, m.member_no, m.full_name, m.status, m.party_id, m.other_name_id, t.name AS type_name
                                FROM members m LEFT JOIN member_types t ON t.id = m.member_type_id ORDER BY m.full_name, m.id') as $r) {
                $out['m'][(int)$r['id']] = $r;
                if ($r['other_name_id']) {
                    $out['o'][(int)$r['other_name_id']] = (int)$r['id'];
                }
            }
            foreach (DB::all("SELECT id, member_id FROM other_names WHERE member_id IS NOT NULL AND name_type <> 'employee'") as $r) {
                $out['o'][(int)$r['id']] ??= (int)$r['member_id'];
            }
            if (Party::ready()) {
                foreach (DB::all('SELECT c.id, m.id AS mid FROM customers c JOIN members m ON m.party_id = c.party_id WHERE c.parent_id IS NULL ORDER BY m.id') as $r) {
                    $out['c'][(int)$r['id']] ??= (int)$r['mid'];
                }
                foreach (DB::all('SELECT v.id, m.id AS mid FROM vendors v JOIN members m ON m.party_id = v.party_id ORDER BY m.id') as $r) {
                    $out['v'][(int)$r['id']] ??= (int)$r['mid'];
                }
            }
        } catch (Throwable $e) {
            // the cooperative tables are not installed
        }
        return self::$index = $out;
    }

    /** The member a customer, supplier or other-name record belongs to (null: not a member's own record) */
    public static function memberOf(string $kind, int $id): ?int
    {
        return self::index()[$kind][$id] ?? null;
    }

    /** "Regular Member: Santos, Maria" as the name pickers show a member */
    public static function label(int $memberId): string
    {
        $m = self::index()['m'][$memberId] ?? null;
        return $m ? Members::kindLabel($m['type_name'], $m['status']) . ': ' . $m['full_name'] : '';
    }

    /** The line the Customer Center and Vendor Center show on a member's own record ($tab customer | vendor) */
    public static function centerNote(int $memberId, string $tab): string
    {
        $m = self::index()['m'][$memberId] ?? null;
        if (!$m) {
            return '';
        }
        $name = h($m['full_name'] . ' (' . $m['member_no'] . ')');
        $link = Access::page('coop.member') ? '<a href="' . h(url('coop.member', ['id' => $memberId, 'dtab' => $tab])) . '">' . $name . '</a>' : $name;
        return '<div class="small text-muted member-note">' . h(Members::kindLabel($m['type_name'], $m['status'])) . ' ' . $link
            . '. The ' . ($tab === 'vendor' ? 'supplier' : 'customer') . ' details are kept on the member\'s ' . ($tab === 'vendor' ? 'Vendor' : 'Customer') . ' Defaults tab; the name and contact details follow the member record.</div>';
    }

    // ------------------------------------------------------------------ the member's own records

    public static function customerOf(array $m): ?array
    {
        if (!Party::ready() || empty($m['party_id'])) {
            return null;
        }
        return DB::row('SELECT * FROM customers WHERE party_id = :p AND parent_id IS NULL ORDER BY is_active DESC, id LIMIT 1', ['p' => $m['party_id']]);
    }

    public static function vendorOf(array $m): ?array
    {
        if (!Party::ready() || empty($m['party_id'])) {
            return null;
        }
        return DB::row('SELECT * FROM vendors WHERE party_id = :p ORDER BY is_active DESC, id LIMIT 1', ['p' => $m['party_id']]);
    }

    /** The member's customer record, made now when there is none (or switched on again when it was switched off) */
    public static function ensureCustomer(int $memberId): int
    {
        $m = Coop::member($memberId) ?? fail('Member not found.');
        if (empty($m['party_id'])) {
            Party::syncMember($memberId);
            $m = Coop::member($memberId);
        }
        $c = self::customerOf($m);
        if ($c && (int)$c['is_active'] === 1) {
            return (int)$c['id'];
        }
        if ($c) {
            Party::restore('customer', (int)$c['id'], 'Member ' . $m['full_name'] . ' transacts as a customer', 'coop.member');
            self::reset();
            return (int)$c['id'];
        }
        Members::ensureCustomerRoles($memberId);
        $c = self::customerOf($m) ?? fail('Member ' . $m['full_name'] . ' has no customer record and one could not be made.');
        self::reset();
        return (int)$c['id'];
    }

    /** The member's supplier record, made now when there is none (or switched on again when it was switched off) */
    public static function ensureVendor(int $memberId): int
    {
        $m = Coop::member($memberId) ?? fail('Member not found.');
        if (empty($m['party_id'])) {
            Party::syncMember($memberId);
            $m = Coop::member($memberId);
        }
        $v = self::vendorOf($m);
        if ($v && (int)$v['is_active'] === 1) {
            return (int)$v['id'];
        }
        if ($v) {
            Party::restore('supplier', (int)$v['id'], 'Member ' . $m['full_name'] . ' transacts as a supplier', 'coop.member');
            self::reset();
            return (int)$v['id'];
        }
        Members::ensureVendorRoles($memberId);
        $v = self::vendorOf($m) ?? fail('Member ' . $m['full_name'] . ' has no supplier record and one could not be made.');
        self::reset();
        return (int)$v['id'];
    }

    // ------------------------------------------------------------------ one name: the records follow the member

    /**
     * The member's customer and supplier records and water-service accounts carry the member's name, TIN and contact
     * details (called on every save of the member). A record with the same name as someone else's gets a number.
     */
    public static function syncRoles(int $memberId, string $pageKey = 'coop.member'): void
    {
        if (!Party::ready()) {
            return;
        }
        $m = Coop::member($memberId);
        if (!$m || empty($m['party_id'])) {
            return;
        }
        $name = mb_substr(trim((string)$m['full_name']), 0, 110);
        $addr = Members::address($m);
        $institution = ($m['member_kind'] ?? 'individual') !== 'individual';
        $why = 'Follows the member record of ' . $m['member_no'] . ' (one name for the member in every line of business)';
        if ($c = self::customerOf($m)) {
            $set = self::changes($c, [
                'name' => self::uniqueName('customers', $name, (int)$c['id'], 'parent_id IS NULL'),
                // a blank on the member does not erase what the record has
                'tin' => self::nn($m['tin']) ?? $c['tin'], 'mobile' => self::nn($m['mobile']) ?? $c['mobile'], 'email' => self::nn($m['email']) ?? $c['email'],
                'billing_address' => $addr !== '' ? $addr : $c['billing_address'],
                'company_name' => $institution ? mb_substr((string)$m['full_name'], 0, 120) : $c['company_name'],
            ]);
            if ($set) {
                DB::update('customers', $set, 'id = :id', ['id' => $c['id']]);
                Audit::log('edit', 'cust', 'customer', (int)$c['id'], (string)($set['name'] ?? $c['name']), ['page_key' => $pageKey, 'before' => $c, 'after' => array_merge($c, $set), 'reason' => $why]);
            }
        }
        if ($v = self::vendorOf($m)) {
            $set = self::changes($v, [
                'name' => self::uniqueName('vendors', $name, (int)$v['id']),
                'tin' => self::nn($m['tin']) ?? $v['tin'], 'phone' => self::nn($m['mobile']) ?? $v['phone'], 'email' => self::nn($m['email']) ?? $v['email'],
                'address' => $addr !== '' ? $addr : $v['address'],
                'company_name' => $institution ? mb_substr((string)$m['full_name'], 0, 120) : $v['company_name'],
            ]);
            if ($set) {
                DB::update('vendors', $set, 'id = :id', ['id' => $v['id']]);
                Audit::log('edit', 'vend', 'vendor', (int)$v['id'], (string)($set['name'] ?? $v['name']), ['page_key' => $pageKey, 'before' => $v, 'after' => array_merge($v, $set), 'reason' => $why]);
            }
        }
        if (self::waterReady()) {
            foreach (DB::all('SELECT * FROM water_accounts WHERE party_id = :p', ['p' => $m['party_id']]) as $w) {
                // the name always follows the member; a detail the member does not have keeps the account's
                $ident = self::waterIdentity($m);
                foreach ($ident as $k => $val) {
                    if ($val === null && !in_array($k, ['firstname', 'middlename'], true)) {
                        unset($ident[$k]);
                    }
                }
                $set = self::changes($w, $ident);
                if ($set) {
                    $set['datelastmodified'] = date('Y-m-d H:i:s');
                    $set['modifiedby'] = mb_substr((string)(Auth::user()['username'] ?? 'system'), 0, 45);
                    DB::update('water_accounts', $set, 'pkey = :id', ['id' => $w['pkey']]);
                    Audit::log('edit', 'water', 'water_account', (int)$w['pkey'], (string)$w['account_number'], ['page_key' => $pageKey, 'before' => $w, 'after' => array_merge($w, $set), 'reason' => $why]);
                }
            }
        }
        self::reset();
        Lookup::reset();
        Names::reset();
    }

    /** The name and personal details a water-service account takes from the member */
    private static function waterIdentity(array $m): array
    {
        $individual = ($m['member_kind'] ?? 'individual') === 'individual';
        return [
            'lastname' => mb_substr($individual ? trim((string)$m['last_name']) : trim((string)$m['full_name']), 0, 100),
            'firstname' => $individual ? mb_substr(trim((string)$m['first_name']) . (trim((string)$m['suffix']) !== '' ? ' ' . trim((string)$m['suffix']) : ''), 0, 100) : null,
            'middlename' => $individual ? self::nn(mb_substr(trim((string)$m['middle_name']), 0, 45)) : null,
            'dateofbirth' => $individual ? self::nn($m['birth_date']) : null,
            'gender' => $individual ? (['M' => 'Male', 'F' => 'Female'][(string)$m['gender']] ?? null) : null,
            'civilstatus' => $individual ? (self::WATER_CIVIL[(string)$m['civil_status']] ?? ($m['civil_status'] === 'widowed' ? ((string)$m['gender'] === 'M' ? 'Widower' : 'Widow') : null)) : null,
            'tinno' => self::nn(mb_substr(trim((string)$m['tin']), 0, 30)),
            'placeofbirth' => self::nn(mb_substr(trim((string)$m['birth_place']), 0, 100)),
            'occupation' => self::nn(mb_substr(trim((string)$m['occupation']), 0, 50)),
        ];
    }

    // ------------------------------------------------------------------ Customer Defaults and Vendor Defaults

    /** The Customer Defaults tab as posted: ['terms_id' => .., 'credit_limit' => .., 'wht_ids' => '1,2' | null ...] */
    public static function postedCustomer(): array
    {
        $f = [];
        foreach (self::CUSTOMER_IDS as $k) {
            $f[$k] = pint('cust_' . $k);
        }
        $f['credit_limit'] = r2(pnum('cust_credit_limit'));
        $f['wht_ids'] = self::postedWht('cust_wht_ids');
        return $f;
    }

    public static function postedVendor(): array
    {
        $f = [];
        foreach (self::VENDOR_IDS as $k) {
            $f[$k] = pint('vend_' . $k);
        }
        $f['credit_limit'] = r2(pnum('vend_credit_limit'));
        $f['account_no'] = mb_substr(trim((string)p('vend_account_no')), 0, 255) ?: null;
        $f['wht_ids'] = self::postedWht('vend_wht_ids');
        return $f;
    }

    private static function postedWht(string $field): ?string
    {
        if (!Withholding::ready()) {
            return null;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST[$field] ?? [])), fn($i) => Withholding::get($i) !== null)));
        return $ids ? implode(',', $ids) : null;
    }

    /**
     * Writes the Customer Defaults on the member's customer record (made now when there is none). A change of the
     * credit limit by a user who may not approve on Customer Center needs a reason, as on the customer form.
     */
    public static function saveCustomer(int $memberId, array $f, string $reason, string $pageKey = 'coop.member'): void
    {
        $cid = self::ensureCustomer($memberId);
        $c = DB::row('SELECT * FROM customers WHERE id = :id', ['id' => $cid]);
        $set = [];
        foreach (self::CUSTOMER_IDS as $k) {
            if (array_key_exists($k, $f)) {
                $set[$k] = $f[$k] ? (int)$f[$k] : null;
            }
        }
        if (array_key_exists('credit_limit', $f)) {
            if ((float)$f['credit_limit'] < 0) {
                fail('The credit limit cannot be negative.');
            }
            $set['credit_limit'] = r2((float)$f['credit_limit']);
        }
        if (array_key_exists('wht_ids', $f) && Withholding::ready()) {
            $set['wht_ids'] = $f['wht_ids'];
        }
        $set = self::changes($c, $set);
        if (!$set) {
            return;
        }
        if (array_key_exists('credit_limit', $set) && !Access::can('cust.center', 'approve') && !Access::isAdmin() && trim($reason) === '') {
            fail('Enter a reason for changing the credit limit (Customer Defaults).');
        }
        DB::update('customers', $set, 'id = :id', ['id' => $cid]);
        Audit::log('edit', 'cust', 'customer', $cid, (string)$c['name'], ['page_key' => $pageKey, 'before' => $c, 'after' => array_merge($c, $set),
            'reason' => trim($reason) !== '' ? $reason : 'Customer Defaults of the member']);
        Lookup::reset();
    }

    /** Writes the Vendor Defaults on the member's supplier record (made now when there is none) */
    public static function saveVendor(int $memberId, array $f, string $pageKey = 'coop.member'): void
    {
        $vid = self::ensureVendor($memberId);
        $v = DB::row('SELECT * FROM vendors WHERE id = :id', ['id' => $vid]);
        $set = [];
        foreach (self::VENDOR_IDS as $k) {
            if (array_key_exists($k, $f)) {
                $set[$k] = $f[$k] ? (int)$f[$k] : null;
            }
        }
        if (!empty($set['default_expense_account_id']) && !DB::value('SELECT id FROM accounts WHERE id = :a', ['a' => $set['default_expense_account_id']])) {
            fail('Choose the default expense account from the chart of accounts.');
        }
        if (array_key_exists('credit_limit', $f)) {
            if ((float)$f['credit_limit'] < 0) {
                fail('The credit limit with the vendor cannot be negative.');
            }
            $set['credit_limit'] = r2((float)$f['credit_limit']);
        }
        if (array_key_exists('account_no', $f)) {
            $set['account_no'] = $f['account_no'];
        }
        if (array_key_exists('wht_ids', $f) && Withholding::ready()) {
            $set['wht_ids'] = $f['wht_ids'];
        }
        $set = self::changes($v, $set);
        if (!$set) {
            return;
        }
        DB::update('vendors', $set, 'id = :id', ['id' => $vid]);
        Audit::log('edit', 'vend', 'vendor', $vid, (string)$v['name'], ['page_key' => $pageKey, 'before' => $v, 'after' => array_merge($v, $set), 'reason' => 'Vendor Defaults of the member']);
        Lookup::reset();
    }

    // ------------------------------------------------------------------ Water Defaults

    /** The member's water-service accounts (the accounts of the member's party), active first */
    public static function waterAccounts(array $m): array
    {
        if (!self::waterReady() || empty($m['party_id'])) {
            return [];
        }
        return DB::all("SELECT w.*, rc.rc_code, rc.rc_name FROM water_accounts w LEFT JOIN rate_code rc ON rc.rc_id = w.rc_id
                         WHERE w.party_id = :p ORDER BY w.status = 'A' DESC, w.account_number", ['p' => $m['party_id']]);
    }

    /** The water-service account the Water Defaults apply to: the one chosen before, else the member's only account */
    public static function primaryWater(array $m): ?array
    {
        $accounts = self::waterAccounts($m);
        foreach ($accounts as $w) {
            if (!empty($m['water_account_id']) && (int)$w['pkey'] === (int)$m['water_account_id']) {
                return $w;
            }
        }
        return count($accounts) === 1 ? $accounts[0] : null;
    }

    /**
     * The values of the Water Defaults tab. With a water-service account they are the account's (Water Billing may have
     * changed them: a disconnection, a new rate code), else the member's own defaults.
     */
    public static function waterView(array $m): array
    {
        $v = [];
        foreach (self::WATER_FIELDS as $k) {
            $v[$k] = $m[$k] ?? null;
        }
        $v['address_subgroup'] = $m['address_subgroup'] ?? null;
        $v['water_connection_status'] = ($v['water_connection_status'] ?? '') === 'inactive' || !empty($v['water_disconnection_date']) ? 'inactive' : 'active';
        // with several accounts and none chosen yet, the first (active) one is shown, as the account box offers it
        $w = self::primaryWater($m) ?? (self::waterAccounts($m)[0] ?? null);
        $v['account'] = $w;
        if ($w) {
            $v['water_zone'] = $w['zone'];
            $v['water_sequence'] = $w['meter_sequence'];
            $v['water_rc_id'] = $w['rc_id'];
            $v['water_pipe_size_id'] = $w['pipe_size_id'];
            $v['address_subgroup'] = $w['address_subgroup'] ?? $v['address_subgroup'];
            $v['water_connection_date'] = $w['connection_date'] ?? $v['water_connection_date'];
            $off = ($w['connection_status'] ?? 'active') === 'disconnected';
            $v['water_connection_status'] = $off ? 'inactive' : 'active';
            $v['water_disconnection_date'] = $off ? ($w['disconnected_at'] ? substr((string)$w['disconnected_at'], 0, 10) : $v['water_disconnection_date']) : null;
        }
        return $v;
    }

    /** The Water Defaults as posted, checked; a disconnection date makes the connection inactive */
    public static function postedWater(): array
    {
        $zone = trim((string)p('water_zone'));
        if ($zone !== '' && !ctype_digit($zone)) {
            fail('The zone is a number (Water Defaults).');
        }
        $seq = trim((string)p('water_sequence'));
        if ($seq !== '' && (mb_strlen($seq) > 50 || !preg_match('~^[A-Za-z0-9 ._/-]+$~', $seq))) {
            fail('The sequence may have up to 50 letters, numbers, spaces, periods, underscores, slashes and hyphens (Water Defaults).');
        }
        $f = [
            'water_zone' => $zone !== '' ? (int)$zone : null,
            'water_sequence' => $seq !== '' ? $seq : null,
            'water_rc_id' => pint('water_rc_id'),
            'water_pipe_size_id' => pint('water_pipe_size_id'),
            'water_connection_date' => p('water_connection_date') ?: null,
            'water_disconnection_date' => p('water_disconnection_date') ?: null,
            'water_connection_status' => p('water_connection_status') === 'inactive' ? 'inactive' : 'active',
        ];
        foreach (['water_connection_date' => 'connection date', 'water_disconnection_date' => 'disconnection date'] as $k => $label) {
            if ($f[$k] !== null && !valid_date($f[$k])) {
                fail('Enter a valid ' . $label . ' (Water Defaults).');
            }
        }
        if ($f['water_connection_date'] && $f['water_disconnection_date'] && $f['water_disconnection_date'] < $f['water_connection_date']) {
            fail('The disconnection date cannot be before the connection date (Water Defaults).');
        }
        if ($f['water_disconnection_date']) {
            $f['water_connection_status'] = 'inactive';
        }
        if ($f['water_rc_id'] && !DB::value('SELECT rc_id FROM rate_code WHERE rc_id = :r', ['r' => $f['water_rc_id']])) {
            fail('Choose a rate code / classification from the list (Water Defaults).');
        }
        return $f;
    }

    /**
     * Applies the member's Water Defaults (already saved on the member) to the member's water-service account: the
     * account chosen ($accountId, one of the member's), else the member's own; with none, an account is opened when a
     * rate code and a pipe size are given and the user may open one. Returns the account (pkey) or null.
     */
    public static function applyWater(int $memberId, string $pageKey = 'coop.member', ?int $accountId = null, bool $mayOpen = true): ?int
    {
        if (!self::waterReady()) {
            return null;
        }
        $m = Coop::member($memberId) ?? fail('Member not found.');
        if (empty($m['party_id'])) {
            Party::syncMember($memberId);
            $m = Coop::member($memberId);
        }
        self::loadWaterHelpers();
        $pdo = DB::pdo();
        $rc = $m['water_rc_id'] ? (int)$m['water_rc_id'] : null;
        $pipe = $m['water_pipe_size_id'] ? (int)$m['water_pipe_size_id'] : null;
        $w = null;
        if ($accountId) {
            foreach (self::waterAccounts($m) as $acc) {
                if ((int)$acc['pkey'] === $accountId) {
                    $w = $acc;
                }
            }
            if (!$w) {
                fail('That water-service account is not the member\'s (Water Defaults).');
            }
        } else {
            $w = self::primaryWater($m);
            if (!$w && count(self::waterAccounts($m)) > 1) {
                fail('The member has several water-service accounts: choose the one the Water Defaults are for.');
            }
        }
        if ($rc || $pipe) {
            if (!$rc || !$pipe) {
                fail('Choose both the rate code / classification and the pipe size (Water Defaults).');
            }
            try {
                water_pipe_size_require_active($pdo, $pipe);
                water_rate_validate_tiers(water_rate_get_rates_for_configuration($pdo, $rc, $pipe), true, false);
            } catch (Throwable $e) {
                fail('Water Defaults: ' . $e->getMessage() . ' (the rate code and pipe size need a complete tariff on Water Meter Rates).');
            }
        }
        if (!$w) {
            if (!$rc) {
                return null;     // no water service for this member yet
            }
            if (!$mayOpen) {
                fail('Opening a water-service account needs the Create right on Water Billing > Water Service Accounts.');
            }
            $pkey = WaterAccounts::open(self::waterOpenFields($m), $pageKey);
            DB::update('members', ['water_account_id' => $pkey], 'id = :id', ['id' => $memberId]);
            $w = WaterAccounts::get($pkey);
            self::setConnection($w, $m, $pageKey);
            return $pkey;
        }
        $set = self::changes($w, [
            'zone' => $m['water_zone'] !== null && $m['water_zone'] !== '' ? (int)$m['water_zone'] : null,
            'meter_sequence' => self::nn($m['water_sequence']),
            'address_subgroup' => Members::waterSubgroupReady() ? self::nn($m['address_subgroup']) : ($w['address_subgroup'] ?? null),
            'connection_date' => self::nn($m['water_connection_date']),
        ] + ($rc ? ['rc_id' => $rc, 'pipe_size_id' => $pipe] : []));
        if (!Members::waterSubgroupReady()) {
            unset($set['address_subgroup']);
        }
        if ($set) {
            $set['datelastmodified'] = date('Y-m-d H:i:s');
            $set['modifiedby'] = mb_substr((string)(Auth::user()['username'] ?? 'system'), 0, 45);
            DB::update('water_accounts', $set, 'pkey = :id', ['id' => $w['pkey']]);
            Audit::log('edit', 'water', 'water_account', (int)$w['pkey'], (string)$w['account_number'], ['page_key' => $pageKey, 'before' => $w, 'after' => array_merge($w, $set),
                'reason' => 'Water Defaults of member ' . $m['member_no']]);
        }
        if ((int)($m['water_account_id'] ?? 0) !== (int)$w['pkey']) {
            DB::update('members', ['water_account_id' => (int)$w['pkey']], 'id = :id', ['id' => $memberId]);
        }
        self::setConnection(WaterAccounts::get((int)$w['pkey']), $m, $pageKey);
        return (int)$w['pkey'];
    }

    /**
     * The connection status of the member's account follows the Water Defaults: Inactive (or a disconnection date)
     * disconnects it, Active reconnects it; both are logged like Disconnection Management does.
     */
    private static function setConnection(array $w, array $m, string $pageKey): void
    {
        $off = ($m['water_connection_status'] ?? 'active') === 'inactive' || !empty($m['water_disconnection_date']);
        $was = (string)($w['connection_status'] ?? 'active');
        $date = $m['water_disconnection_date'] ?: null;
        $user = Auth::id() ?: null;
        if ($off && $was !== 'disconnected') {
            $reason = $date ? 'Member Information: disconnection date ' . fdate($date) : 'Member Information: connection status Inactive';
            DB::update('water_accounts', ['connection_status' => 'disconnected', 'disconnected_at' => ($date ?? today()) . ($date && $date !== today() ? ' 00:00:00' : ' ' . date('H:i:s')),
                'disconnected_by' => $user, 'disconnection_reason' => $reason, 'reconnected_at' => null, 'reconnected_by' => null, 'reconnection_reason' => null], 'pkey = :id', ['id' => $w['pkey']]);
            self::logConnection((int)$w['pkey'], 'DISCONNECT', $was, 'disconnected', $date ?? today(), $reason);
            Audit::log('disconnect', 'water', 'water_account', (int)$w['pkey'], (string)$w['account_number'], ['page_key' => $pageKey, 'reason' => $reason]);
        } elseif ($off && $date && substr((string)$w['disconnected_at'], 0, 10) !== $date) {
            // the disconnection date was corrected
            DB::update('water_accounts', ['disconnected_at' => $date . ' 00:00:00'], 'pkey = :id', ['id' => $w['pkey']]);
            Audit::log('edit', 'water', 'water_account', (int)$w['pkey'], (string)$w['account_number'], ['page_key' => $pageKey,
                'changes' => [['field' => 'disconnected_at', 'old' => (string)$w['disconnected_at'], 'new' => $date]], 'reason' => 'Member Information: disconnection date corrected']);
        } elseif (!$off && $was === 'disconnected') {
            $reason = 'Member Information: connection status Active';
            DB::update('water_accounts', ['connection_status' => 'active', 'reconnected_at' => date('Y-m-d H:i:s'), 'reconnected_by' => $user, 'reconnection_reason' => $reason], 'pkey = :id', ['id' => $w['pkey']]);
            self::logConnection((int)$w['pkey'], 'RECONNECT', $was, 'active', null, $reason);
            Audit::log('reconnect', 'water', 'water_account', (int)$w['pkey'], (string)$w['account_number'], ['page_key' => $pageKey, 'reason' => $reason]);
        }
    }

    private static function logConnection(int $pkey, string $action, string $from, string $to, ?string $date, string $reason): void
    {
        try {
            DB::insert('manual_disconnection_logs', ['member_id' => $pkey, 'action' => $action, 'bill_source_type' => null, 'bill_source_id' => null, 'amount_due' => '0.00',
                'due_date' => null, 'disconnection_date' => $date, 'previous_status' => $from, 'new_status' => $to, 'reason' => $reason, 'action_by' => Auth::id() ?: null,
                'action_at' => date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            // the disconnection log of the water module is not installed: the audit trail keeps the change
        }
    }

    /** The fields of a new water-service account opened from the member form */
    private static function waterOpenFields(array $m): array
    {
        $street = trim(implode(' ', array_filter([trim((string)$m['res_street']), trim((string)$m['res_barangay'])])));
        return self::waterIdentity($m) + [
            'party_id' => (int)$m['party_id'], 'customer_id' => self::ensureCustomer((int)$m['id']),
            'zone' => $m['water_zone'] !== null && $m['water_zone'] !== '' ? (int)$m['water_zone'] : null, 'meter_sequence' => self::nn($m['water_sequence']),
            'rc_id' => (int)$m['water_rc_id'], 'pipe_size_id' => (int)$m['water_pipe_size_id'],
            'connection_date' => $m['water_connection_date'] ?: today(), 'address_subgroup' => self::nn($m['address_subgroup']),
            'housebldg' => self::nn($m['res_house']), 'street' => $street !== '' ? $street : null, 'city' => self::nn($m['res_city']), 'province' => self::nn($m['res_province']),
            'barangay' => self::nn($m['res_barangay']), 'zip' => (string)($m['res_postal'] ?? ''), 'mobile' => self::nn($m['mobile']),
            'reason' => 'Water-service account of member ' . $m['member_no'] . ', opened from Member Information > Water Defaults',
        ];
    }

    /** The lists of the Water Defaults tab, as Water Billing shows them */
    public static function waterOptions(): array
    {
        if (!self::waterReady()) {
            return ['zones' => [], 'rates' => [], 'pipes' => []];
        }
        self::loadWaterHelpers();
        $pipes = [];
        foreach (water_pipe_size_options(DB::pdo(), true) as $p) {
            $pipes[(int)$p['pipe_size_id']] = water_pipe_size_format($p);
        }
        $rates = [];
        foreach (DB::all('SELECT rc_id, rc_code, rc_name FROM rate_code ORDER BY rc_code, rc_name') as $r) {
            $rates[(int)$r['rc_id']] = trim((string)$r['rc_code']) . ' - ' . trim((string)$r['rc_name']);
        }
        return ['zones' => array_map('intval', DB::column('SELECT DISTINCT zone FROM water_accounts WHERE zone IS NOT NULL ORDER BY zone')), 'rates' => $rates, 'pipes' => $pipes];
    }

    public static function loadWaterHelpers(): void
    {
        require_once APP_ROOT . '/water/common/waterRateBillingHelpers.php';
        require_once APP_ROOT . '/water/process/sysSequenceHelpers.php';
    }

    // ------------------------------------------------------------------ helpers

    /** The fields of $new that differ from $old */
    private static function changes(array $old, array $new): array
    {
        $set = [];
        foreach ($new as $k => $v) {
            if (!array_key_exists($k, $old)) {
                continue;
            }
            $a = $old[$k];
            if (is_numeric($a) && is_numeric($v) && !is_string($v)) {
                if ((float)$a !== (float)$v) {
                    $set[$k] = $v;
                }
            } elseif ((string)($a ?? '') !== (string)($v ?? '')) {
                $set[$k] = $v;
            }
        }
        return $set;
    }

    private static function nn($v): ?string
    {
        $v = trim((string)($v ?? ''));
        return $v === '' ? null : $v;
    }

    /** $name, or "$name (2)" when another record already has it */
    private static function uniqueName(string $table, string $name, int $selfId, string $where = '1 = 1'): string
    {
        $base = mb_substr(trim($name), 0, 110);
        $try = $base;
        for ($n = 2; $n < 500; $n++) {
            if (!DB::value("SELECT id FROM $table WHERE name = :n AND id <> :id AND $where", ['n' => $try, 'id' => $selfId])) {
                return $try;
            }
            $try = $base . ' (' . $n . ')';
        }
        return $base . ' ' . bin2hex(random_bytes(2));
    }
}
