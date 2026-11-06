<?php
declare(strict_types=1);

/** Member records: saving the profile and its tabs, the status workflow, and the member statement. */
final class Members
{
    private static ?array $nameKinds = null;

    public const KINDS = ['individual' => 'Individual', 'joint' => 'Joint account', 'institutional' => 'Institutional'];
    public const CIVIL = ['single' => 'Single', 'married' => 'Married', 'widowed' => 'Widowed', 'separated' => 'Separated', 'annulled' => 'Annulled'];
    public const EMPLOYMENT = ['employed' => 'Employed', 'self_employed' => 'Self-employed', 'business' => 'Business owner', 'ofw' => 'OFW', 'retired' => 'Retired', 'student' => 'Student', 'unemployed' => 'Unemployed', 'other' => 'Other'];

    /** the moves the status workflow allows: from => [to, ...] */
    /**
     * The lists kept on Member Types, Clusters & Other Lists (coop_lists.list_key => tab name). The first four group
     * the members for loans and savings (Loan and savings grouping on the member form); the member keeps the name.
     */
    public const LIST_KEYS = [
        'account_class'    => 'Account classes',
        'account_group'    => 'Account groups',
        'address_subgroup' => 'Address sub-groups',
        'savings_group'    => 'Savings groups',
        'loan_purpose'     => 'Loan purposes',
        'collateral_type'  => 'Collateral types',
        'doc_type'         => 'Member document types',
        'id_type'          => 'ID types',
    ];
    /** member grouping list => the length of the member column that keeps it */
    public const GROUPING_LISTS = ['account_class' => 40, 'account_group' => 60, 'address_subgroup' => 60, 'savings_group' => 60];
    public const LIST_SINGULAR = [
        'account_class' => 'account class', 'account_group' => 'account group', 'address_subgroup' => 'address sub-group', 'savings_group' => 'savings group',
        'loan_purpose' => 'loan purpose', 'collateral_type' => 'collateral type', 'doc_type' => 'document type', 'id_type' => 'ID type',
    ];
    public const GROUPING_HELP = [
        'account_class'    => 'Account classes sort the members by their standing with the cooperative (Regular, Board of Director, Officer, Employee ...). Chosen on the member form, Loan and savings grouping.',
        'account_group'    => 'Account groups put members together for collection and reporting (an employer, a market, a school). Chosen on the member form, Loan and savings grouping.',
        'address_subgroup' => 'Address sub-groups split an area into smaller parts (a purok, a sitio, a zone). Chosen on the member form and on the water-service accounts of Water Billing, so both systems use the same list.',
        'savings_group'    => 'Savings groups gather members who save together (a savings club, a youth savings group). Chosen on the member form, Loan and savings grouping.',
    ];

    public const TRANSITIONS = [
        'applicant'  => ['active', 'terminated'],
        'active'     => ['suspended', 'resigned', 'terminated', 'deceased'],
        'suspended'  => ['active', 'resigned', 'terminated', 'deceased'],
        'resigned'   => ['active'],
        'terminated' => ['active'],
        'deceased'   => [],
    ];

    public static function types(): array
    {
        static $t = null;
        if ($t === null) {
            $t = [];
            foreach (DB::all('SELECT * FROM member_types ORDER BY sort_order, name') as $r) {
                $t[(int)$r['id']] = $r;
            }
        }
        return $t;
    }

    public static function typeOptions(bool $activeOnly = true): array
    {
        $out = [];
        foreach (self::types() as $id => $r) {
            if (!$activeOnly || (int)$r['is_active'] === 1) {
                $out[$id] = $r['name'];
            }
        }
        return $out;
    }

    public static function listOptions(string $key): array
    {
        return DB::pairs('SELECT id, name FROM coop_lists WHERE list_key = :k AND is_active = 1 ORDER BY sort_order, name', ['k' => $key]);
    }

    public static function listNames(string $key): array
    {
        return DB::column('SELECT name FROM coop_lists WHERE list_key = :k AND is_active = 1 ORDER BY sort_order, name', ['k' => $key]);
    }

    /**
     * name => name for a drop-down of a member grouping list. A value already on the record that is no longer on the
     * list (switched off, or typed before the list existed) stays offered, so saving the form does not drop it.
     */
    public static function listChoices(string $key, $current = null): array
    {
        $names = self::listNames($key);
        $out = array_combine($names, $names) ?: [];
        $current = trim((string)$current);
        if ($current !== '' && !isset($out[$current])) {
            $out[$current] = $current . ' (not on the list)';
        }
        return $out;
    }

    /** Records that use a list entry, for the "cannot be deleted" message: ['3 member(s)', '2 water-service account(s)'] */
    public static function listUsage(string $key, string $name): array
    {
        $out = [];
        if (isset(self::GROUPING_LISTS[$key])) {
            $n = (int)DB::value("SELECT COUNT(*) FROM members WHERE `$key` = :n", ['n' => $name]);
            if ($n) {
                $out[] = $n . ' member(s)';
            }
            if ($key === 'address_subgroup' && self::waterSubgroupReady()) {
                $w = (int)DB::value('SELECT COUNT(*) FROM water_accounts WHERE address_subgroup = :n', ['n' => $name]);
                if ($w) {
                    $out[] = $w . ' water-service account(s)';
                }
            }
        } elseif ($key === 'loan_purpose') {
            $n = (int)DB::value('SELECT COUNT(*) FROM loan_applications WHERE purpose = :n', ['n' => $name]);
            if ($n) {
                $out[] = $n . ' loan application(s)';
            }
        }
        return $out;
    }

    /** lower-case name => how many records use it (the count column of the lists page) */
    public static function listCounts(string $key): array
    {
        $out = [];
        if (!isset(self::GROUPING_LISTS[$key])) {
            return $out;
        }
        foreach (DB::all("SELECT `$key` AS n, COUNT(*) AS c FROM members WHERE `$key` IS NOT NULL AND `$key` <> '' GROUP BY `$key`") as $r) {
            $out[mb_strtolower((string)$r['n'])] = (int)$r['c'];
        }
        if ($key === 'address_subgroup' && self::waterSubgroupReady()) {
            foreach (DB::all("SELECT address_subgroup AS n, COUNT(*) AS c FROM water_accounts WHERE address_subgroup IS NOT NULL AND address_subgroup <> '' GROUP BY address_subgroup") as $r) {
                $k = mb_strtolower((string)$r['n']);
                $out[$k] = ($out[$k] ?? 0) + (int)$r['c'];
            }
        }
        return $out;
    }

    /** A renamed grouping entry is renamed on the members (and water-service accounts) that have it; returns how many */
    public static function renameListEntry(string $key, string $old, string $new): int
    {
        if (!isset(self::GROUPING_LISTS[$key]) || $old === $new) {
            return 0;
        }
        $n = DB::run("UPDATE members SET `$key` = :new WHERE `$key` = :old", ['new' => $new, 'old' => $old])->rowCount();
        if ($key === 'address_subgroup' && self::waterSubgroupReady()) {
            $n += DB::run('UPDATE water_accounts SET address_subgroup = :new WHERE address_subgroup = :old', ['new' => $new, 'old' => $old])->rowCount();
        }
        return $n;
    }

    // ------------------------------------------------------------------ the address sub-groups (version 46)
    // Kept where the other member lists are (coop_lists, Member Types, Clusters & Other Lists > Address sub-groups) and
    // maintained there, on Water Billing > Address Sub-groups and from the Manage button beside every Address sub-group
    // box (the member form, the water-service accounts): one list for both systems.

    /** Every address sub-group (active or not) with how many members and water-service accounts have it */
    public static function subgroupRows(): array
    {
        $members = [];
        foreach (DB::all("SELECT address_subgroup AS n, COUNT(*) AS c FROM members WHERE address_subgroup IS NOT NULL AND address_subgroup <> '' GROUP BY address_subgroup") as $r) {
            $members[mb_strtolower((string)$r['n'])] = (int)$r['c'];
        }
        $accounts = [];
        if (self::waterSubgroupReady()) {
            foreach (DB::all("SELECT address_subgroup AS n, COUNT(*) AS c FROM water_accounts WHERE address_subgroup IS NOT NULL AND address_subgroup <> '' GROUP BY address_subgroup") as $r) {
                $accounts[mb_strtolower((string)$r['n'])] = (int)$r['c'];
            }
        }
        $out = [];
        foreach (DB::all("SELECT id, name, sort_order, is_active FROM coop_lists WHERE list_key = 'address_subgroup' ORDER BY sort_order, name") as $r) {
            $k = mb_strtolower((string)$r['name']);
            $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'sort_order' => (int)$r['sort_order'], 'is_active' => (int)$r['is_active'],
                'members' => $members[$k] ?? 0, 'accounts' => $accounts[$k] ?? 0];
        }
        return $out;
    }

    /**
     * Adds an address sub-group ($id null) or changes one (its name, order, active). A new name is carried to every member
     * and water-service account that has the old one. Returns the message for the user.
     */
    public static function saveSubgroup(?int $id, string $name, int $sortOrder, bool $active, string $pageKey): string
    {
        $key = 'address_subgroup';
        $name = mb_substr(trim((string)preg_replace('/\s+/u', ' ', $name)), 0, self::GROUPING_LISTS[$key]);
        if ($name === '') {
            fail('Enter the name of the address sub-group.');
        }
        $sortOrder = max(-9999, min(9999, $sortOrder));
        return DB::tx(function () use ($id, $name, $sortOrder, $active, $pageKey, $key) {
            if (DB::value('SELECT id FROM coop_lists WHERE list_key = :k AND name = :n AND id <> :id', ['k' => $key, 'n' => $name, 'id' => $id ?? 0])) {
                fail('"' . $name . '" is already an address sub-group.');
            }
            $d = ['name' => $name, 'sort_order' => $sortOrder, 'is_active' => $active ? 1 : 0];
            if ($id) {
                $old = DB::row('SELECT * FROM coop_lists WHERE id = :id AND list_key = :k FOR UPDATE', ['id' => $id, 'k' => $key]);
                if (!$old) {
                    fail('The address sub-group is no longer on the list. Reload the list.');
                }
                self::subgroupWrite(fn() => DB::update('coop_lists', $d, 'id = :id', ['id' => $id]), $name);
                $moved = $old['name'] !== $name ? self::renameListEntry($key, (string)$old['name'], $name) : 0;
                Audit::log('edit', 'coop', 'coop_lists', $id, $name, ['page_key' => $pageKey, 'before' => $old, 'after' => $d] + ($moved ? ['reason' => 'Renamed on ' . $moved . ' record(s)'] : []));
                if ($old['name'] !== $name) {
                    return '"' . $old['name'] . '" is now "' . $name . '"' . ($moved ? ', on ' . $moved . ' member(s) and water-service account(s)' : '') . '.';
                }
                return '"' . $name . '" was saved' . ((int)$old['is_active'] !== ($active ? 1 : 0) ? ($active ? ': offered again' : ': no longer offered (switched off)') : '') . '.';
            }
            $newId = (int)self::subgroupWrite(fn() => DB::insert('coop_lists', ['list_key' => $key] + $d), $name);
            Audit::log('create', 'coop', 'coop_lists', $newId, $name, ['page_key' => $pageKey, 'snapshot' => $d]);
            return '"' . $name . '" was added to the address sub-groups.';
        });
    }

    /** A write of the list: the same name saved at the same moment by someone else (the unique key) says so plainly */
    private static function subgroupWrite(callable $write, string $name)
    {
        try {
            return $write();
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                fail('"' . $name . '" is already an address sub-group.');
            }
            throw $e;
        }
    }

    /** Deletes an address sub-group no member and no water-service account has (else it is switched off instead) */
    public static function deleteSubgroup(int $id, string $pageKey): string
    {
        $key = 'address_subgroup';
        return DB::tx(function () use ($id, $pageKey, $key) {
            $row = DB::row('SELECT * FROM coop_lists WHERE id = :id AND list_key = :k FOR UPDATE', ['id' => $id, 'k' => $key]);
            if (!$row) {
                fail('The address sub-group is no longer on the list. Reload the list.');
            }
            $used = self::listUsage($key, (string)$row['name']);
            if ($used) {
                fail('"' . $row['name'] . '" cannot be deleted: it is used by ' . implode(' and ', $used) . '. Move them to another sub-group first, or switch this one off so it is no longer offered.');
            }
            DB::run('DELETE FROM coop_lists WHERE id = :id', ['id' => $id]);
            Audit::log('delete', 'coop', 'coop_lists', $id, $row['name'], ['page_key' => $pageKey, 'snapshot' => $row]);
            return '"' . $row['name'] . '" was deleted from the address sub-groups.';
        });
    }

    /**
     * Every member is on the customer list too, so a member can be chosen wherever a customer can (sales, the POS,
     * Customer:Job on expenses) and is recognised there by member type. The customer record is the party's own
     * customer role (Party::grant): one per party, never a copy. A party whose customer record was switched off on
     * purpose is left alone, and so is a deceased member. Returns how many customer records were made.
     */
    public static function ensureCustomerRoles(?int $memberId = null, int $limit = 400): int
    {
        if (!Party::ready()) {
            return 0;
        }
        // a list page makes at most $limit at a time, so the first page opened after an upgrade of a large
        // cooperative stays quick; the next pages make the rest
        $sql = "SELECT DISTINCT m.party_id FROM members m
                 WHERE m.party_id IS NOT NULL AND m.status <> 'deceased'
                   AND NOT EXISTS (SELECT 1 FROM customers c WHERE c.party_id = m.party_id AND c.parent_id IS NULL)"
            . ($memberId ? ' AND m.id = :id' : '') . ' LIMIT ' . max(1, $limit);
        $made = 0;
        foreach (DB::column($sql, $memberId ? ['id' => $memberId] : []) as $partyId) {
            try {
                Party::grant((int)$partyId, 'customer', 'Cooperative member: listed as a customer so the member can be chosen on sales, the POS and Customer:Job', [], 'coop.member');
                $made++;
            } catch (UserError $e) {
                // a merged or inactive party keeps no new role
            }
        }
        if ($made) {
            Lookup::reset();
            self::$nameKinds = null;
            if (class_exists('MemberDefaults')) {
                MemberDefaults::reset();
            }
        }
        return $made;
    }

    /**
     * Every member is on the supplier list too, the same way it is on the customer list: a member who sells produce
     * or services to the cooperative is paid on the member's own supplier record (Party::grant), never on a second
     * record typed for the same person. Its defaults are kept on Member Information > Vendor Defaults. A supplier
     * record switched off on purpose is left alone, and so is a deceased member. Returns how many were made.
     */
    public static function ensureVendorRoles(?int $memberId = null, int $limit = 400): int
    {
        if (!Party::ready()) {
            return 0;
        }
        $sql = "SELECT DISTINCT m.party_id FROM members m
                 WHERE m.party_id IS NOT NULL AND m.status <> 'deceased'
                   AND NOT EXISTS (SELECT 1 FROM vendors v WHERE v.party_id = m.party_id)"
            . ($memberId ? ' AND m.id = :id' : '') . ' LIMIT ' . max(1, $limit);
        $made = 0;
        foreach (DB::column($sql, $memberId ? ['id' => $memberId] : []) as $partyId) {
            try {
                Party::grant((int)$partyId, 'supplier', 'Cooperative member: listed as a supplier so the member can be paid for what it sells to the cooperative', [], 'coop.member');
                $made++;
            } catch (UserError $e) {
                // a merged or inactive party keeps no new role
            }
        }
        if ($made) {
            Lookup::reset();
            self::$nameKinds = null;
            if (class_exists('MemberDefaults')) {
                MemberDefaults::reset();
            }
        }
        return $made;
    }

    /** "Regular Member", "Associate - Joint Member", "Aflatoun (youth) Member": how a member is described on name lists */
    public static function kindLabel(?string $typeName, ?string $status = null): string
    {
        $t = trim((string)$typeName);
        $label = $t === '' ? 'Member' : (preg_match('/\bmember$/i', $t) ? $t : $t . ' Member');
        if ($status !== null && $status !== '' && $status !== 'active') {
            $label .= ', ' . mb_strtolower((string)(Coop::MEMBER_STATUSES[$status] ?? $status));
        }
        return $label;
    }

    /**
     * Who on the name lists is a cooperative member, by the record that stands for the member there:
     * ['o' => [other_name_id => label], 'c' => [customer_id => label], 'v' => [vendor_id => label]]
     */
    public static function nameKinds(): array
    {
        if (self::$nameKinds !== null) {
            return self::$nameKinds;
        }
        $out = ['o' => [], 'c' => [], 'v' => []];
        try {
            foreach (DB::all('SELECT o.id, t.name AS type_name, m.status FROM other_names o
                                JOIN members m ON m.id = o.member_id OR m.other_name_id = o.id
                                LEFT JOIN member_types t ON t.id = m.member_type_id') as $r) {
                $out['o'][(int)$r['id']] = self::kindLabel($r['type_name'], $r['status']);
            }
            if (Party::ready()) {
                foreach (DB::all('SELECT c.id, t.name AS type_name, m.status FROM customers c
                                    JOIN members m ON m.party_id = c.party_id
                                    LEFT JOIN member_types t ON t.id = m.member_type_id
                                   WHERE c.party_id IS NOT NULL') as $r) {
                    $out['c'][(int)$r['id']] = self::kindLabel($r['type_name'], $r['status']);
                }
                foreach (DB::all('SELECT v.id, t.name AS type_name, m.status FROM vendors v
                                    JOIN members m ON m.party_id = v.party_id
                                    LEFT JOIN member_types t ON t.id = m.member_type_id
                                   WHERE v.party_id IS NOT NULL') as $r) {
                    $out['v'][(int)$r['id']] = self::kindLabel($r['type_name'], $r['status']);
                }
            }
        } catch (Throwable $e) {
            // the cooperative tables are not installed
        }
        return self::$nameKinds = $out;
    }

    /** True once the water-service accounts carry an address sub-group (upgrade 2026_12_20) */
    public static function waterSubgroupReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(address_subgroup) FROM water_accounts WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    public static function clusters(): array
    {
        return DB::pairs('SELECT id, name FROM member_clusters WHERE is_active = 1 ORDER BY name');
    }

    public static function segments(): array
    {
        return DB::pairs('SELECT id, name FROM member_segments WHERE is_active = 1 ORDER BY name');
    }

    /**
     * The member form sends the boxes of one kind of member. A joint account or an institution has its own boxes
     * (Joint/Institution Details, Membership Information), named j_*: they take the place of the individual's, and what
     * only an individual has (the person's name and birth, family and beneficiaries, financial details, loan and savings
     * grouping, attendance) is not read for it. An individual's form sends neither the joint boxes nor the representatives.
     */
    public static function postedForKind(string $kind): void
    {
        $shared = ['tin', 'email', 'res_house', 'res_street', 'res_barangay', 'res_city', 'res_province', 'res_postal', 'off_house', 'off_street', 'off_city',
            'off_province', 'off_postal', 'branch_id', 'member_type_id', 'pmes_date', 'membership_date', 'subscribed_shares', 'par_value', 'cluster_id',
            'segment_id', 'account_officer_id', 'passbook_no', 'passbook_series', 'passbook_peek', 'alert_note', 'notes', 'contacts', 'contacts_sent'];
        if ($kind === 'individual') {
            foreach (array_keys($_POST) as $k) {
                if (str_starts_with((string)$k, 'j_')) {
                    unset($_POST[$k]);
                }
            }
            unset($_POST['institution_name'], $_POST['representatives'], $_POST['representatives_sent'], $_FILES['j_signature']);
            return;
        }
        foreach ($shared as $k) {
            unset($_POST[$k]);
            if (array_key_exists('j_' . $k, $_POST)) {
                $_POST[$k] = $_POST['j_' . $k];
            }
            unset($_POST['j_' . $k]);
        }
        foreach (['salutation', 'last_name', 'first_name', 'middle_name', 'suffix', 'birth_date', 'birth_place', 'gender', 'civil_status', 'nationality', 'mobile',
            'prov_house', 'prov_street', 'prov_city', 'prov_province', 'prov_postal', 'education', 'year_completed', 'course', 'occupation', 'organization',
            'employment_type', 'employer_name', 'employer_contact', 'employer_address', 'account_class', 'account_group', 'savings_group',
            'dependents', 'dependents_sent', 'relatives', 'relatives_sent', 'beneficiaries', 'beneficiaries_sent', 'finances_sent', 'fin_income', 'fin_expense',
            'fin_outlay', 'assets', 'assets_sent', 'attendance', 'attendance_sent'] as $k) {
            unset($_POST[$k]);
        }
        if (!MemberDefaults::waterReady()) {
            unset($_POST['address_subgroup']);   // on the grouping tab when Water Billing is not installed
        }
        unset($_FILES['photo'], $_FILES['signature']);
        if (isset($_FILES['j_signature'])) {
            $_FILES['signature'] = $_FILES['j_signature'];
            unset($_FILES['j_signature']);
        }
    }

    /** The number Get passbook number shows: the next one of the Passbook series that no member has (nothing is taken) */
    public static function peekPassbook(): string
    {
        $row = DB::row("SELECT prefix, next_no, pad FROM sequences WHERE seq_key = 'passbook'");
        if (!$row) {
            return '';
        }
        for ($n = (int)$row['next_no'], $i = 0; $i < 200; $n++, $i++) {
            $no = $row['prefix'] . str_pad((string)$n, (int)$row['pad'], '0', STR_PAD_LEFT);
            if (!DB::value('SELECT id FROM members WHERE passbook_no = :p', ['p' => $no])) {
                return $no;
            }
        }
        return '';
    }

    /** The next number of Document Numbering > Passbook that no member has yet (a number typed by hand is skipped) */
    public static function nextPassbook(): string
    {
        for ($i = 0; $i < 200; $i++) {
            $no = Seq::next('passbook');
            if (!DB::value('SELECT id FROM members WHERE passbook_no = :p', ['p' => $no])) {
                return $no;
            }
        }
        fail('No free passbook number was found in the series: check Document Numbering > Passbook.');
    }

    /** "Last, First M." as the reference system lists members */
    public static function fullName(array $d): string
    {
        if (($d['member_kind'] ?? 'individual') !== 'individual') {
            return trim((string)($d['institution_name'] ?? '')) ?: trim((string)($d['last_name'] ?? ''));
        }
        $mi = trim((string)($d['middle_name'] ?? ''));
        $mi = $mi !== '' ? ' ' . mb_substr($mi, 0, 1) . '.' : '';
        $sfx = trim((string)($d['suffix'] ?? ''));
        return trim((string)$d['last_name']) . ', ' . trim((string)$d['first_name']) . $mi . ($sfx !== '' ? ' ' . $sfx : '');
    }

    /** The residence address on one line */
    public static function address(array $m): string
    {
        return trim(implode(', ', array_filter(array_map(fn($k) => trim((string)($m[$k] ?? '')), ['res_house', 'res_street', 'res_barangay', 'res_city', 'res_province']))));
    }

    public static function age(?string $birth, ?string $asOf = null): ?int
    {
        if (!$birth || !valid_date($birth)) {
            return null;
        }
        return (new DateTime($birth))->diff(new DateTime($asOf ?? today()))->y;
    }

    /**
     * Saves the member and the rows of the tabs. $d holds the members columns, $tabs the child lists:
     * contacts, dependents, relatives, finances, assets, beneficiaries, representatives, attendance.
     */
    public static function save(array $d, array $tabs, ?int $id, string $pageKey): int
    {
        return DB::tx(function () use ($d, $tabs, $id, $pageKey) {
            $old = $id ? Coop::member($id) : null;
            if ($id && !$old) {
                fail('Member not found.');
            }
            if ($old) {
                Coop::requireBranch($old['branch_id'] ? (int)$old['branch_id'] : null);
            }
            $kind = $d['member_kind'] ?? 'individual';
            if ($kind === 'individual' && (trim((string)$d['last_name']) === '' || trim((string)$d['first_name']) === '')) {
                fail('Enter the last name and first name.');
            }
            if ($kind !== 'individual' && trim((string)($d['institution_name'] ?? '')) === '') {
                fail('Enter the name of the joint account or institution.');
            }
            if (!empty($d['birth_date']) && !valid_date($d['birth_date'])) {
                fail('Enter a valid date of birth.');
            }
            if (!empty($d['membership_date']) && !valid_date($d['membership_date'])) {
                fail('Enter a valid membership date.');
            }
            $d['full_name'] = mb_substr(self::fullName($d), 0, 200);
            if (!Coop::branchAllowed($d['branch_id'] ? (int)$d['branch_id'] : null)) {
                fail('You may only file members under your own branch.');
            }
            // the same person twice: same full name and birth date
            $dup = DB::value('SELECT member_no FROM members WHERE full_name = :n AND COALESCE(birth_date, \'\') = :b AND id <> :id',
                ['n' => $d['full_name'], 'b' => (string)($d['birth_date'] ?: ''), 'id' => $id ?? 0]);
            if ($dup && empty($d['allow_duplicate'])) {
                fail('A member with the same name and date of birth already exists (member no. ' . $dup . '). Tick "This is a different person" to save anyway.');
            }
            unset($d['allow_duplicate']);
            // a new member who is already on file as a customer, supplier or other name joins that party
            $linkParty = !empty($d['link_party_id']) ? (int)$d['link_party_id'] : null;
            unset($d['link_party_id'], $d['party_id']);
            $d['subscribed_capital'] = Dec::fix(Dec::mul($d['subscribed_shares'] ?? '0', $d['par_value'] ?? '0', 2));
            if ($old) {
                unset($d['member_no'], $d['status'], $d['status_date'], $d['status_reason']);
                $d['updated_by'] = Auth::id();
                DB::update('members', $d, 'id = :id', ['id' => $id]);
                DB::run('UPDATE other_names SET name = :n, tin = :t, employee_no = :e WHERE member_id = :m', ['n' => $d['full_name'], 't' => $d['tin'] ?? null, 'e' => $old['member_no'], 'm' => $id]);
                Names::reset();
            } else {
                $d['member_no'] = !empty($d['member_no']) ? mb_substr(trim((string)$d['member_no']), 0, 20) : Coop::nextMemberNo();
                if (DB::value('SELECT id FROM members WHERE member_no = :n', ['n' => $d['member_no']])) {
                    fail('Member number ' . $d['member_no'] . ' is already used.');
                }
                $d['status'] = $d['status'] ?? 'applicant';
                $d['status_date'] = today();
                $d['created_by'] = Auth::id();
                $id = DB::insert('members', $d);
                $onId = DB::insert('other_names', ['name_type' => 'member', 'name' => $d['full_name'], 'member_id' => $id, 'employee_no' => $d['member_no'], 'tin' => $d['tin'] ?? null, 'is_active' => 1]);
                DB::update('members', ['other_name_id' => $onId], 'id = :id', ['id' => $id]);
                DB::insert('member_status_history', ['member_id' => $id, 'old_status' => null, 'new_status' => $d['status'], 'changed_on' => today(), 'reason' => 'Record created', 'changed_by' => Auth::id()]);
                Names::reset();
            }
            // the tabs: replace the rows of each list the form sent
            $lists = [
                'contacts' => ['member_contacts', ['contact_type', 'country_code', 'area_code', 'number', 'is_primary']],
                'dependents' => ['member_dependents', ['kind', 'first_name', 'last_name', 'middle_name', 'relationship', 'birth_date', 'contact_no', 'is_dependent']],
                'relatives' => ['member_relatives', ['relative_member_id', 'relationship']],
                'finances' => ['member_finances', ['kind', 'name', 'member_amount', 'spouse_amount']],
                'assets' => ['member_assets', ['description', 'asset_type', 'amount']],
                'beneficiaries' => ['member_beneficiaries', ['name', 'relationship', 'birth_date', 'contact_no', 'address', 'share_pct', 'is_primary']],
                'representatives' => ['member_representatives', ['name', 'position', 'rep_member_id']],
                'attendance' => ['member_attendance', ['meeting_type', 'meeting_date', 'attended', 'remarks']],
            ];
            foreach ($lists as $key => [$table, $cols]) {
                if (!array_key_exists($key, $tabs)) {
                    continue;
                }
                DB::run("DELETE FROM $table WHERE member_id = :m", ['m' => $id]);
                foreach ($tabs[$key] as $row) {
                    $ins = ['member_id' => $id];
                    $empty = true;
                    foreach ($cols as $c) {
                        $v = $row[$c] ?? null;
                        if (is_string($v)) {
                            $v = trim($v);
                            if ($v === '') {
                                $v = null;
                            }
                        }
                        if ($v !== null && !in_array($c, ['kind', 'contact_type', 'asset_type', 'meeting_type', 'is_primary', 'is_dependent', 'attended', 'share_pct'], true)) {
                            $empty = false;
                        }
                        $ins[$c] = $v;
                    }
                    if ($empty) {
                        continue;
                    }
                    foreach (['birth_date', 'meeting_date'] as $dc) {
                        if (array_key_exists($dc, $ins) && $ins[$dc] !== null && !valid_date($ins[$dc])) {
                            fail('Enter a valid date on the ' . $key . ' list.');
                        }
                    }
                    foreach (['member_amount', 'spouse_amount', 'amount', 'share_pct'] as $ac) {
                        if (array_key_exists($ac, $ins)) {
                            $ins[$ac] = Dec::fix(Dec::of($ins[$ac] ?? '0'));
                        }
                    }
                    foreach (['is_primary', 'is_dependent', 'attended'] as $bc) {
                        if (array_key_exists($bc, $ins)) {
                            $ins[$bc] = !empty($ins[$bc]) ? 1 : 0;
                        }
                    }
                    if (array_key_exists('relative_member_id', $ins)) {
                        $ins['relative_member_id'] = (int)$ins['relative_member_id'];
                        if (!$ins['relative_member_id']) {
                            continue;
                        }
                    }
                    if (array_key_exists('rep_member_id', $ins)) {
                        $ins['rep_member_id'] = $ins['rep_member_id'] ? (int)$ins['rep_member_id'] : null;
                    }
                    if (array_key_exists('kind', $ins) && $ins['kind'] === null) {
                        $ins['kind'] = $key === 'finances' ? 'income' : 'dependent';
                    }
                    if (array_key_exists('contact_type', $ins) && $ins['contact_type'] === null) {
                        $ins['contact_type'] = 'mobile';
                    }
                    if (array_key_exists('asset_type', $ins) && $ins['asset_type'] === null) {
                        $ins['asset_type'] = 'asset';
                    }
                    if (array_key_exists('meeting_type', $ins) && $ins['meeting_type'] === null) {
                        $ins['meeting_type'] = 'general_assembly';
                    }
                    DB::insert($table, $ins);
                }
            }
            // the mobile shown on the member comes from the first mobile contact when the box is empty
            $mobile = DB::value("SELECT number FROM member_contacts WHERE member_id = :m AND contact_type = 'mobile' ORDER BY is_primary DESC, id LIMIT 1", ['m' => $id]);
            if ($mobile && empty($d['mobile'])) {
                DB::update('members', ['mobile' => mb_substr((string)$mobile, 0, 40)], 'id = :id', ['id' => $id]);
            }
            // the party master: the member's registered name and details lead its party
            Party::syncMember($id, $old ? null : $linkParty, $pageKey);
            // a member is also a customer of the cooperative (sales, POS, Customer:Job) and a supplier (purchases from the
            // member), on its own records, which carry the member's name and details (one name in every line of business)
            self::ensureCustomerRoles($id);
            self::ensureVendorRoles($id);
            if (MemberDefaults::ready()) {
                MemberDefaults::syncRoles($id, $pageKey);
            }
            $new = Coop::member($id);
            Audit::log($old ? 'edit' : 'create', 'coop', 'member', $id, $new['full_name'] . ' (' . $new['member_no'] . ')', [
                'page_key' => $pageKey, 'before' => $old ?: [], 'after' => $new, 'changes' => $old ? null : [], 'snapshot' => $old ? null : $new,
            ]);
            return $id;
        });
    }

    /** Net disposable income from the Financial Information tab: income less expenses less other cash outlay */
    public static function ndi(int $memberId): array
    {
        $rows = DB::all('SELECT kind, SUM(member_amount) AS m, SUM(spouse_amount) AS s FROM member_finances WHERE member_id = :m GROUP BY kind', ['m' => $memberId]);
        $t = ['income' => '0', 'expense' => '0', 'outlay' => '0', 'spouse_income' => '0'];
        foreach ($rows as $r) {
            $t[$r['kind']] = Dec::add($r['m'], $r['s'], 2);
            if ($r['kind'] === 'income') {
                $t['spouse_income'] = Dec::of($r['s']);
                $t['member_income'] = Dec::of($r['m']);
            }
        }
        $t['ndi'] = Dec::sub(Dec::sub($t['income'], $t['expense'], 2), $t['outlay'], 2);
        return $t;
    }

    // ------------------------------------------------------------------ files (photo, signature, KYC documents)

    public const FILE_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
    public const FILE_MAX_BYTES = 8388608;

    /** The company's own folder of member files (not reachable from the web: storage/.htaccess) */
    public static function fileDir(): string
    {
        return Tenant::storage('coop/members');
    }

    /** Checks and stores one uploaded file ($_FILES entry). Returns [stored name, original name, mime] or null when nothing was sent. */
    public static function storeUpload(?array $up, int $memberId, string $kind, bool $imagesOnly): ?array
    {
        if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($up['error'] !== UPLOAD_ERR_OK) {
            fail('The file could not be uploaded' . (in_array($up['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? ': it is too large.' : '.'));
        }
        if ((int)$up['size'] > self::FILE_MAX_BYTES) {
            fail('The file is larger than 8 MB.');
        }
        $ext = strtolower(pathinfo((string)$up['name'], PATHINFO_EXTENSION));
        if (!isset(self::FILE_TYPES[$ext]) || ($imagesOnly && $ext === 'pdf')) {
            fail('Upload a ' . ($imagesOnly ? 'JPG, PNG, GIF or WEBP picture' : 'picture (JPG, PNG, GIF, WEBP) or a PDF file') . '.');
        }
        if ($ext === 'pdf') {
            $head = (string)@file_get_contents($up['tmp_name'], false, null, 0, 5);
            if ($head !== '%PDF-') {
                fail('The file is not a PDF document.');
            }
            $mime = 'application/pdf';
        } else {
            $info = @getimagesize($up['tmp_name']);
            if (!$info || !in_array($info['mime'], self::FILE_TYPES, true)) {
                fail('The file is not a picture.');
            }
            $mime = $info['mime'];
        }
        $stored = 'm' . $memberId . '_' . $kind . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dir = self::fileDir();
        if (!is_uploaded_file($up['tmp_name']) || !move_uploaded_file($up['tmp_name'], $dir . '/' . $stored)) {
            fail('The file could not be saved. Make the storage folder writable by the web server.');
        }
        return [$stored, mb_substr((string)$up['name'], 0, 190), $mime];
    }

    /** The member's photo or specimen signature */
    public static function setPicture(int $memberId, string $which, ?array $up, string $pageKey): void
    {
        $m = Coop::member($memberId);
        if (!$m || !in_array($which, ['photo', 'signature'], true)) {
            fail('Member not found.');
        }
        $f = self::storeUpload($up, $memberId, $which, true);
        if (!$f) {
            return;
        }
        DB::update('members', [$which . '_file' => $f[0]], 'id = :id', ['id' => $memberId]);
        Audit::log('upload', 'coop', 'member', $memberId, $m['full_name'] . ' ' . $which, ['page_key' => $pageKey, 'reason' => 'New ' . $which . ' ' . $f[1]]);
    }

    public static function addDocument(int $memberId, array $d, ?array $up, string $pageKey): int
    {
        $m = Coop::member($memberId);
        if (!$m) {
            fail('Member not found.');
        }
        Coop::requireBranch($m['branch_id'] ? (int)$m['branch_id'] : null);
        if (trim((string)($d['doc_type'] ?? '')) === '') {
            fail('Choose the kind of document.');
        }
        foreach (['issued_on', 'expires_on'] as $k) {
            if (!empty($d[$k]) && !valid_date($d[$k])) {
                fail('Enter valid dates.');
            }
        }
        $f = self::storeUpload($up, $memberId, 'doc', false);
        $id = DB::insert('member_documents', ['member_id' => $memberId, 'doc_type' => mb_substr((string)$d['doc_type'], 0, 60), 'doc_no' => mb_substr(trim((string)($d['doc_no'] ?? '')), 0, 60) ?: null,
            'file_name' => $f[0] ?? null, 'original_name' => $f[1] ?? null, 'mime_type' => $f[2] ?? null, 'issued_on' => ($d['issued_on'] ?? '') ?: null, 'expires_on' => ($d['expires_on'] ?? '') ?: null,
            'notes' => mb_substr(trim((string)($d['notes'] ?? '')), 0, 255) ?: null, 'uploaded_by' => Auth::id()]);
        Audit::log('upload', 'coop', 'member_document', $id, $m['full_name'] . ': ' . $d['doc_type'], ['page_key' => $pageKey]);
        return $id;
    }

    public static function sendFile(string $stored, string $mime, string $name): never
    {
        $file = self::fileDir() . '/' . basename($stored);
        if (!is_file($file)) {
            http_response_code(404);
            exit('File not found.');
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\- ]/', '_', $name) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');
        readfile($file);
        exit;
    }

    // ------------------------------------------------------------------ status workflow

    public static function changeStatus(int $id, string $to, string $date, string $reason, string $reference = '', string $pageKey = 'coop.members'): void
    {
        DB::tx(function () use ($id, $to, $date, $reason, $reference, $pageKey) {
            $m = DB::row('SELECT * FROM members WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (!$m) {
                fail('Member not found.');
            }
            Coop::requireBranch($m['branch_id'] ? (int)$m['branch_id'] : null);
            if (!isset(Coop::MEMBER_STATUSES[$to])) {
                fail('Unknown status.');
            }
            if (!in_array($to, self::TRANSITIONS[$m['status']] ?? [], true)) {
                fail('A ' . Coop::MEMBER_STATUSES[$m['status']] . ' member cannot be made ' . Coop::MEMBER_STATUSES[$to] . ' directly.');
            }
            if (!valid_date($date)) {
                fail('Enter the effective date.');
            }
            if (trim($reason) === '') {
                fail('Enter the reason (it is recorded in the member\'s history and the audit trail).');
            }
            if ($to === 'active') {
                $policyMin = Dec::of(Coop::setting('min_share_capital', '0'));
                $type = self::types()[(int)$m['member_type_id']] ?? null;
                if ($type && Dec::isPos($type['min_share_capital'])) {
                    $policyMin = Dec::max($policyMin, $type['min_share_capital']);
                }
                $paid = Dec::of(DB::value("SELECT COALESCE(SUM(a.balance),0) FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m AND p.product_type = 'share_capital' AND a.status <> 'closed'", ['m' => $id]));
                if (Dec::isPos($policyMin) && Dec::cmp($paid, $policyMin) < 0 && empty($_POST['waive_capital'])) {
                    fail('Paid-up share capital is ' . Dec::money($paid) . ', below the minimum of ' . Dec::money($policyMin) . ' for activation. Collect the share capital first, or tick the waiver (recorded in the audit trail).');
                }
            }
            if (in_array($to, ['resigned', 'terminated'], true)) {
                $open = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(principal_balance + interest_balance + penalty_balance),0) AS bal FROM loan_accounts WHERE member_id = :m AND status IN ('current','past_due','restructured')", ['m' => $id]);
                if ((int)$open['n'] > 0 && empty($_POST['allow_with_loans'])) {
                    fail('The member still has ' . $open['n'] . ' open loan(s) with a balance of ' . Dec::money($open['bal']) . '. Settle them, or tick "allow with open loans" (for offsetting against deposits and share capital).');
                }
            }
            $upd = ['status' => $to, 'status_date' => $date, 'status_reason' => mb_substr($reason, 0, 255), 'updated_by' => Auth::id()];
            if ($to === 'deceased') {
                $upd['deceased_date'] = $date;
                $upd['hold_flag'] = 1;
                $upd['hold_reason'] = 'Deceased: claims by the beneficiaries only';
            }
            if ($to === 'active' && !$m['membership_date']) {
                $upd['membership_date'] = $date;
            }
            if ($to === 'active' && (int)$m['hold_flag'] === 1 && $m['status'] !== 'deceased') {
                $upd['hold_flag'] = 0;
                $upd['hold_reason'] = null;
            }
            DB::update('members', $upd, 'id = :id', ['id' => $id]);
            DB::insert('member_status_history', ['member_id' => $id, 'old_status' => $m['status'], 'new_status' => $to, 'changed_on' => $date,
                'reason' => mb_substr($reason, 0, 255), 'reference' => mb_substr($reference, 0, 80) ?: null, 'changed_by' => Auth::id()]);
            DB::run('UPDATE other_names SET is_active = :a WHERE member_id = :m', ['a' => in_array($to, ['active', 'applicant', 'suspended'], true) ? 1 : 0, 'm' => $id]);
            Party::memberStatusChanged($id, (string)$m['status'], $to, $reason);
            Audit::log('status_change', 'coop', 'member', $id, $m['full_name'] . ' (' . $m['member_no'] . ')', [
                'page_key' => $pageKey, 'reason' => $reason . ($reference ? ' [' . $reference . ']' : '') . (!empty($_POST['waive_capital']) ? ' [share capital minimum waived]' : ''),
                'changes' => [['field' => 'status', 'old' => $m['status'], 'new' => $to]],
            ]);
        });
    }

    public static function setHold(int $id, bool $hold, string $reason, string $pageKey): void
    {
        $m = Coop::member($id);
        if (!$m) {
            fail('Member not found.');
        }
        Coop::requireBranch($m['branch_id'] ? (int)$m['branch_id'] : null);
        if ($hold && trim($reason) === '') {
            fail('Enter the reason for the hold.');
        }
        DB::update('members', ['hold_flag' => $hold ? 1 : 0, 'hold_reason' => $hold ? mb_substr($reason, 0, 255) : null, 'updated_by' => Auth::id()], 'id = :id', ['id' => $id]);
        Audit::log($hold ? 'hold' : 'release_hold', 'coop', 'member', $id, $m['full_name'], ['page_key' => $pageKey, 'reason' => $reason]);
    }

    // ------------------------------------------------------------------ statement

    /** Everything on the member's statement: accounts with balances and the movements of the period */
    public static function statement(int $memberId, string $from, string $to): array
    {
        $m = Coop::member($memberId);
        if (!$m) {
            fail('Member not found.');
        }
        $accounts = DB::all('SELECT a.*, p.name AS product_name, p.product_type FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m ORDER BY p.product_type, a.account_no', ['m' => $memberId]);
        foreach ($accounts as &$a) {
            $a['opening'] = Dec::of(DB::value('SELECT COALESCE(SUM(credit - debit),0) FROM sav_txns WHERE account_id = :a AND is_voided = 0 AND txn_date < :f', ['a' => $a['id'], 'f' => $from]));
            $a['txns'] = DB::all('SELECT * FROM sav_txns WHERE account_id = :a AND is_voided = 0 AND txn_date BETWEEN :f AND :t ORDER BY txn_date, id', ['a' => $a['id'], 'f' => $from, 't' => $to]);
            $a['closing'] = Dec::of(DB::value('SELECT COALESCE(SUM(credit - debit),0) FROM sav_txns WHERE account_id = :a AND is_voided = 0 AND txn_date <= :t', ['a' => $a['id'], 't' => $to]));
            $a['td'] = $a['product_type'] === 'time_deposit' ? DB::all('SELECT * FROM td_placements WHERE account_id = :a ORDER BY placement_date', ['a' => $a['id']]) : [];
        }
        unset($a);
        $loans = DB::all('SELECT l.*, p.name AS product_name FROM loan_accounts l JOIN loan_products p ON p.id = l.product_id WHERE l.member_id = :m ORDER BY l.release_date', ['m' => $memberId]);
        foreach ($loans as &$l) {
            $l['txns'] = DB::all('SELECT * FROM loan_txns WHERE loan_id = :l AND is_voided = 0 AND txn_date BETWEEN :f AND :t ORDER BY txn_date, id', ['l' => $l['id'], 'f' => $from, 't' => $to]);
        }
        unset($l);
        $dist = DB::all('SELECT bl.*, b.kind, b.batch_no, b.date_to, b.posted_at, b.status AS batch_status FROM dist_batch_lines bl JOIN dist_batches b ON b.id = bl.batch_id
                          WHERE bl.member_id = :m AND bl.is_excluded = 0 AND b.status = \'posted\' AND b.date_to BETWEEN :f AND :t ORDER BY b.date_to', ['m' => $memberId, 'f' => $from, 't' => $to]);
        return ['member' => $m, 'accounts' => $accounts, 'loans' => $loans, 'distributions' => $dist, 'from' => $from, 'to' => $to];
    }

    /**
     * Every account's balance on a date, from the subsidiary ledgers: deposits and share capital from the passbook
     * ledger, loans' principal from the loan ledger (the loan ledger carries the whole scheduled interest from the
     * release, so interest is not a balance on a date; what is past due is worked out by Loans::dues for today).
     * Used for the certificate of balances.
     */
    public static function balancesAsOf(int $memberId, string $asOf): array
    {
        $accounts = DB::all("SELECT a.id, a.account_no, a.status, p.name AS product_name, p.product_type,
                                    COALESCE(SUM(CASE WHEN x.is_voided = 0 AND x.txn_date <= :d THEN x.credit - x.debit ELSE 0 END),0) AS balance
                               FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id LEFT JOIN sav_txns x ON x.account_id = a.id
                              WHERE a.member_id = :m AND (a.opened_on IS NULL OR a.opened_on <= :d2)
                              GROUP BY a.id, a.account_no, a.status, p.name, p.product_type
                              ORDER BY FIELD(p.product_type, 'share_capital', 'ordinary', 'special', 'time_deposit'), a.account_no",
            ['m' => $memberId, 'd' => $asOf, 'd2' => $asOf]);
        $accounts = array_values(array_filter($accounts, fn($a) => !Dec::isZero($a['balance']) || in_array($a['status'], ['active', 'dormant'], true)));
        $loans = DB::all("SELECT l.id, l.loan_no, l.status, l.release_date, l.due_date, l.principal, p.name AS product_name,
                                 COALESCE(SUM(CASE WHEN x.is_voided = 0 AND x.txn_date <= :d THEN x.principal_dr - x.principal_cr ELSE 0 END),0) AS principal_balance
                            FROM loan_accounts l JOIN loan_products p ON p.id = l.product_id LEFT JOIN loan_txns x ON x.loan_id = l.id
                           WHERE l.member_id = :m AND l.release_date <= :d2 AND l.status <> 'cancelled'
                           GROUP BY l.id, l.loan_no, l.status, l.release_date, l.due_date, l.principal, p.name
                           ORDER BY l.release_date, l.id",
            ['m' => $memberId, 'd' => $asOf, 'd2' => $asOf]);
        $loans = array_values(array_filter($loans, fn($l) => !Dec::isZero($l['principal_balance'])));
        $tot = ['share_capital' => '0', 'deposits' => '0', 'loans' => '0'];
        foreach ($accounts as $a) {
            $k = $a['product_type'] === 'share_capital' ? 'share_capital' : 'deposits';
            $tot[$k] = Dec::add($tot[$k], $a['balance'], 2);
        }
        foreach ($loans as $l) {
            $tot['loans'] = Dec::add($tot['loans'], $l['principal_balance'], 2);
        }
        return ['accounts' => $accounts, 'loans' => $loans, 'totals' => $tot];
    }
}
