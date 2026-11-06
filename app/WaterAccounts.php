<?php
declare(strict_types=1);

/**
 * Water-service accounts (table water_accounts, the former water "members") and their place in the Party Master.
 *
 *  - A water-service account holder is a party (parties). One party may hold several water-service accounts, be a
 *    cooperative member, a supplier, an employee ... or none of these: a water customer is not automatically a member.
 *  - The account's receivables are posted to the party's customer record (customers.party_id), one per party, so
 *    the A/R subsidiary ledger, aging, statements and the Consolidated Party Statement all see the same balance.
 *  - The water account keeps its own key (water_accounts.pkey, the former members.pkey), account number, meter,
 *    rate code, addresses and history; party_id and customer_id are the links. Nothing is merged by name: a new
 *    account gets a new party unless the user picks an existing party (possible duplicates go to Identity Review).
 */
final class WaterAccounts
{
    public const SOURCE = 'water_acct';     // party_crosswalk.source / parties.src_table of a water-service account
    public const CUSTOMER_TYPE = 'Water Service';

    public static function get(int $id): ?array
    {
        return DB::row('SELECT * FROM water_accounts WHERE pkey = :id', ['id' => $id]);
    }

    public static function name(array $a): string
    {
        return Party::personName((string)($a['firstname'] ?? ''), (string)($a['middlename'] ?? ''), (string)($a['lastname'] ?? ''), null);
    }

    /** "Last, First M." as the water lists show it */
    public static function listName(array $a): string
    {
        $mi = trim((string)($a['middlename'] ?? ''));
        $first = trim((string)($a['firstname'] ?? ''));
        // an organization, a joint account or an institution has its whole name in the last name and no first name
        return trim((string)($a['lastname'] ?? '')) . ($first !== '' ? ', ' . $first . ($mi !== '' ? ' ' . mb_substr($mi, 0, 1) . '.' : '') : '');
    }

    /** The party fields a water account supplies */
    public static function partyFields(array $a): array
    {
        $addr = self::address((int)$a['pkey']);
        // an institution's account carries its name in lastname only; an account without any name is named after
        // its account number (a party always has a name)
        $legal = trim(self::name($a));
        $display = trim(self::listName($a), " ,");
        if ($legal === '') {
            $legal = 'Water account ' . (string)($a['account_number'] ?? $a['pkey']);
        }
        return [
            'party_type'   => 'person',
            'legal_name'   => $legal,
            'display_name' => $display !== '' ? $display : $legal,
            'last_name'    => (string)($a['lastname'] ?? ''),
            'first_name'   => (string)($a['firstname'] ?? ''),
            'middle_name'  => (string)($a['middlename'] ?? ''),
            'tin'          => (string)($a['tinno'] ?? ''),
            'birth_date'   => !empty($a['dateofbirth']) && $a['dateofbirth'] !== '0000-00-00' ? $a['dateofbirth'] : null,
            'address'      => $addr,
            'mobile'       => self::phone((int)$a['pkey']),
            'notes'        => 'Water-service account ' . (string)($a['account_number'] ?? $a['pkey']),
        ];
    }

    /** The current contact number of the account, from the water contact tables ('' when none) */
    public static function phone(int $id): string
    {
        try {
            $v = DB::value("SELECT contactdata FROM membercontactchannels WHERE memberkey = :id AND COALESCE(status, 'A') = 'A'
                             ORDER BY activefromdate DESC LIMIT 1", ['id' => $id]);
        } catch (Throwable $e) {
            $v = null;
        }
        return mb_substr(trim((string)$v), 0, 40);
    }

    /** The current address of the account, from the water address tables */
    public static function address(int $id): string
    {
        try {
            $r = DB::row("SELECT a.housebldg, a.street, a.other_address_detail, c.city, c.province
                            FROM memberaddress ma JOIN addresses a ON a.pkey = ma.address_key LEFT JOIN cities c ON c.pkey = a.city_id
                           WHERE ma.member_key = :id AND COALESCE(ma.status, 'A') = 'A' ORDER BY ma.dtaddressFrom DESC, ma.address_key DESC LIMIT 1", ['id' => $id]);
        } catch (Throwable $e) {
            $r = null;
        }
        if (!$r) {
            return '';
        }
        $parts = array_filter(array_map('trim', [(string)$r['housebldg'], (string)$r['street'], (string)$r['other_address_detail'], (string)$r['city'], (string)$r['province']]), fn($p) => $p !== '');
        return mb_substr(implode(', ', $parts), 0, 255);
    }

    /**
     * The party of a water-service account, made now when it has none (an account saved before the integration,
     * or imported). $choosePartyId links the account to an existing party the user picked (never by name alone).
     */
    public static function ensureParty(int $id, ?int $choosePartyId = null, string $reason = ''): int
    {
        $a = self::get($id) ?? fail('Water-service account not found.');
        if (!empty($a['party_id'])) {
            $p = Party::get((int)$a['party_id']);
            if ($p && $p['status'] === 'merged' && $p['merged_into_id']) {
                $live = Party::live((int)$p['id']);
                DB::run('UPDATE water_accounts SET party_id = :p WHERE pkey = :id', ['p' => $live['id'], 'id' => $id]);
                return (int)$live['id'];
            }
            return (int)$a['party_id'];
        }
        return DB::tx(function () use ($a, $id, $choosePartyId, $reason) {
            $fields = self::partyFields($a);
            if ($choosePartyId) {
                $p = Party::live($choosePartyId) ?? fail('Choose an existing party.');
                if ($p['status'] === 'merged') {
                    fail('Party ' . $p['party_no'] . ' is not active.');
                }
                $partyId = (int)$p['id'];
                $how = 'linked';
            } else {
                $partyId = Party::create($fields, self::SOURCE, $id, 'water.member_list');
                $how = 'created';
            }
            DB::run('UPDATE water_accounts SET party_id = :p WHERE pkey = :id', ['p' => $partyId, 'id' => $id]);
            Party::crosswalk(self::SOURCE, $id, mb_substr((string)($a['account_number'] ?: $id) . ' ' . $fields['display_name'], 0, 160), $partyId, $how);
            Party::log($partyId, 'water', $id, $how === 'linked' ? 'linked' : 'granted', $reason !== '' ? $reason : ($how === 'linked' ? 'Water-service account linked to an existing party' : 'Water-service account'));
            return $partyId;
        });
    }

    /**
     * The customer record the account's receivables post to: the party's customer (one per party), made now when
     * the party has none.
     */
    public static function ensureCustomer(int $id): int
    {
        $a = self::get($id) ?? fail('Water-service account not found.');
        if (!empty($a['customer_id'])) {
            $c = DB::row('SELECT id, party_id, is_active FROM customers WHERE id = :id', ['id' => $a['customer_id']]);
            if ($c && (int)$c['is_active'] === 1) {
                return (int)$c['id'];
            }
        }
        return DB::tx(function () use ($a, $id) {
            $partyId = self::ensureParty($id);
            $c = DB::row('SELECT id, is_active FROM customers WHERE party_id = :p AND parent_id IS NULL ORDER BY is_active DESC, id LIMIT 1', ['p' => $partyId]);
            if ($c && (int)$c['is_active'] === 1) {
                $customerId = (int)$c['id'];
            } elseif ($c) {
                Party::restore('customer', (int)$c['id'], 'Water-service account ' . ($a['account_number'] ?: $id), 'water.member_list');
                $customerId = (int)$c['id'];
            } else {
                $customerId = Party::grant($partyId, 'customer', 'Water-service account ' . ($a['account_number'] ?: $id), ['customer_type_id' => self::customerTypeId()], 'water.member_list');
            }
            if ($typeId = self::customerTypeId()) {
                DB::run('UPDATE customers SET customer_type_id = COALESCE(customer_type_id, :t) WHERE id = :id', ['t' => $typeId, 'id' => $customerId]);
            }
            DB::run('UPDATE water_accounts SET customer_id = :c WHERE pkey = :id', ['c' => $customerId, 'id' => $id]);
            return $customerId;
        });
    }

    /**
     * Opens a water-service account for a party already on file (a member, from Member Information > Water Defaults):
     * the next account key (syssequence SysMemberIDSeq) and account number (from 50001), the address and the mobile
     * number in the water address and contact tables, linked to the party and to its customer record. The caller has
     * checked the rate code and pipe size. Returns the account key (water_accounts.pkey).
     * $f: lastname, firstname, middlename, dateofbirth, placeofbirth, tinno, gender, civilstatus, occupation, zone,
     *     meter_sequence, rc_id, pipe_size_id, connection_date, address_subgroup, party_id, customer_id,
     *     housebldg, street, barangay, city, province, zip, mobile, reason
     */
    public static function open(array $f, string $pageKey = 'water.member_list'): int
    {
        MemberDefaults::loadWaterHelpers();
        return DB::tx(function () use ($f, $pageKey) {
            $pdo = DB::pdo();
            $max = (int)DB::value('SELECT COALESCE(MAX(pkey), 0) FROM water_accounts');
            $pkey = sysseq_next_numeric($pdo, 'SysMemberIDSeq', $max);
            $no = max(50001, (int)DB::value("SELECT COALESCE(MAX(CAST(account_number AS UNSIGNED)), 50000) FROM water_accounts WHERE account_number REGEXP '^[0-9]+$'") + 1);
            for ($i = 0; $i < 100 && DB::value('SELECT pkey FROM water_accounts WHERE account_number = :n', ['n' => (string)$no]); $i++) {
                $no++;
            }
            $date = !empty($f['connection_date']) ? (string)$f['connection_date'] : today();
            $row = [
                'pkey' => $pkey, 'lastname' => $f['lastname'] ?? null, 'firstname' => $f['firstname'] ?? null, 'middlename' => $f['middlename'] ?? null,
                'dateofbirth' => $f['dateofbirth'] ?? null, 'placeofbirth' => $f['placeofbirth'] ?? null, 'tinno' => $f['tinno'] ?? null, 'gender' => $f['gender'] ?? null,
                'civilstatus' => $f['civilstatus'] ?? null, 'occupation' => $f['occupation'] ?? null, 'datecreated' => date('Y-m-d H:i:s'),
                'createdby' => mb_substr((string)(Auth::user()['username'] ?? 'system'), 0, 45), 'status' => 'A', 'account_number' => (string)$no,
                'zone' => $f['zone'] ?? null, 'meter_sequence' => $f['meter_sequence'] ?? null, 'rc_id' => (int)$f['rc_id'], 'pipe_size_id' => (int)$f['pipe_size_id'],
                'connection_date' => $date, 'party_id' => !empty($f['party_id']) ? (int)$f['party_id'] : null,
            ];
            if (Members::waterSubgroupReady()) {
                $row['address_subgroup'] = $f['address_subgroup'] ?? null;
            }
            DB::insert('water_accounts', $row);
            // the address: the city of the water module's list when it is there, else the city kept in the details
            $city = trim((string)($f['city'] ?? ''));
            $cityId = $city !== '' ? DB::value("SELECT pkey FROM cities WHERE status = 'A' AND LOWER(TRIM(city)) = LOWER(:c) ORDER BY pkey LIMIT 1", ['c' => $city]) : null;
            $other = trim(implode(', ', array_filter([$cityId ? '' : trim((string)($f['barangay'] ?? '')), $cityId ? '' : $city, $cityId ? '' : trim((string)($f['province'] ?? ''))])));
            if (!empty($f['housebldg']) || !empty($f['street']) || $city !== '') {
                $addressId = DB::insert('addresses', ['city_id' => $cityId ? (int)$cityId : null, 'housebldg' => mb_substr((string)($f['housebldg'] ?? ''), 0, 30) ?: null,
                    'street' => mb_substr((string)($f['street'] ?? ''), 0, 100) ?: null, 'other_address_detail' => mb_substr($other, 0, 50) ?: null,
                    'zippostal_code' => ctype_digit((string)($f['zip'] ?? '')) && (int)$f['zip'] <= 32767 ? (int)$f['zip'] : 0, 'status' => 'A']);
                DB::insert('memberaddress', ['member_key' => $pkey, 'address_key' => $addressId, 'dtaddressFrom' => $date, 'address_type_key' => 1, 'status' => 'A']);
            }
            $mobile = trim((string)($f['mobile'] ?? ''));
            if ($mobile !== '' && preg_match('/^[0-9+()\-\s]{5,50}$/', $mobile)) {
                $channel = (int)DB::value("SELECT pkey FROM contactchannels WHERE status = 'A' AND LOWER(name) IN ('mobile', 'phone', 'contact', 'cellphone') ORDER BY LOWER(name) = 'mobile' DESC, pkey LIMIT 1");
                if (!$channel) {
                    $channel = DB::insert('contactchannels', ['name' => 'Mobile', 'description' => 'Mobile Number', 'datecreated' => date('Y-m-d H:i:s'), 'status' => 'A']);
                }
                DB::insert('membercontactchannels', ['conchannelkey' => $channel, 'memberkey' => $pkey, 'activefromdate' => $date, 'activetodate' => null, 'contactdata' => mb_substr($mobile, 0, 50), 'status' => 'A']);
            }
            if (!empty($f['party_id'])) {
                Party::crosswalk(self::SOURCE, $pkey, mb_substr($no . ' ' . trim((string)($f['lastname'] ?? '') . ', ' . (string)($f['firstname'] ?? ''), ' ,'), 0, 160), (int)$f['party_id'], 'linked');
                Party::log((int)$f['party_id'], 'water', $pkey, 'granted', (string)($f['reason'] ?? 'Water-service account opened'));
            }
            if (!empty($f['customer_id'])) {
                DB::run('UPDATE water_accounts SET customer_id = :c WHERE pkey = :id', ['c' => (int)$f['customer_id'], 'id' => $pkey]);
            }
            if (!empty($f['party_id'])) {
                self::ensureCustomer($pkey);
            }
            Audit::log('create', 'water', 'water_account', $pkey, $no . ' ' . trim((string)($f['lastname'] ?? '') . ', ' . (string)($f['firstname'] ?? ''), ' ,'),
                ['page_key' => $pageKey, 'snapshot' => $row, 'reason' => $f['reason'] ?? null]);
            return $pkey;
        });
    }

    /** The "Water Service" customer type (made once) */
    public static function customerTypeId(): ?int
    {
        static $id = null;
        if ($id !== null) {
            return $id ?: null;
        }
        try {
            $found = DB::value('SELECT id FROM customer_types WHERE name = :n', ['n' => self::CUSTOMER_TYPE]);
            if (!$found) {
                $found = DB::insert('customer_types', ['name' => self::CUSTOMER_TYPE]);
            }
            $id = (int)$found;
        } catch (Throwable $e) {
            $id = 0;
        }
        return $id ?: null;
    }

    /** The water-service accounts of a party */
    public static function ofParty(int $partyId): array
    {
        return DB::all("SELECT w.*, rc.rc_code, rc.rc_name FROM water_accounts w LEFT JOIN rate_code rc ON rc.rc_id = w.rc_id
                         WHERE w.party_id = :p ORDER BY w.status = 'A' DESC, w.account_number", ['p' => $partyId]);
    }

    /** Outstanding water receivables of one account, by source, from the water records (the operational balance) */
    public static function openBalance(int $id): array
    {
        $out = ['water' => 0.0, 'one_time' => 0.0, 'installment' => 0.0, 'recurring' => 0.0];
        $out['water'] = (float)DB::value("SELECT COALESCE(SUM(GREATEST(0, amount_due - COALESCE(amount_paid, 0))), 0) FROM bill_readings
            WHERE member_id = :m AND is_billed = 1 AND COALESCE(bill_lifecycle_status, 'active') = 'active'", ['m' => $id]);
        $out['one_time'] = (float)DB::value("SELECT COALESCE(SUM(GREATEST(0, total_amount - COALESCE(amount_paid, 0))), 0) FROM one_time_bills
            WHERE member_id = :m AND status = 'unpaid' AND COALESCE(bill_lifecycle_status, 'active') = 'active'", ['m' => $id]);
        $out['installment'] = (float)DB::value("SELECT COALESCE(SUM(GREATEST(0, s.amount - COALESCE(s.amount_paid, 0))), 0) FROM installment_schedules s
            JOIN installment_bills b ON b.installment_id = s.installment_id
            WHERE b.member_id = :m AND b.status = 'active' AND COALESCE(b.bill_lifecycle_status, 'active') = 'active'", ['m' => $id]);
        $out['recurring'] = (float)DB::value("SELECT COALESCE(SUM(GREATEST(0, total_amount - COALESCE(amount_paid, 0))), 0) FROM recurring_bills
            WHERE member_id = :m AND status IN ('unpaid', 'overdue') AND COALESCE(bill_lifecycle_status, 'active') = 'active'", ['m' => $id]);
        $out['total'] = r2(array_sum($out));
        return $out;
    }

    /** The same balance as the ledger sees it: open invoices of the account's postings */
    public static function ledgerBalance(int $id): float
    {
        $v = DB::value("SELECT COALESCE(SUM(t.total), 0) - COALESCE((SELECT SUM(pa.amount + pa.discount + pa.withholding) FROM payment_applications pa
                               JOIN transactions p ON p.id = pa.payment_txn_id AND p.is_voided = 0
                               JOIN water_postings w2 ON w2.txn_id = pa.target_txn_id AND w2.water_account_id = :m2 AND w2.status = 'posted'), 0)
                          FROM water_postings w JOIN transactions t ON t.id = w.txn_id
                         WHERE w.water_account_id = :m AND w.status = 'posted' AND t.is_voided = 0
                           AND t.txn_type IN ('invoice', 'finance_charge')", ['m' => $id, 'm2' => $id]);
        return r2((float)$v);
    }
}
