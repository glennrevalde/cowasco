<?php
declare(strict_types=1);

/**
 * Water Billing > Billing > Bill Accounts Center (version 57): every bill of the members and customers, one line per
 * invoice, as the Statement of Account and the water cashier know them (water/common/individualSoaHelpers.php):
 *
 *   water bills          posted (Post Billing), not deleted                        WTR-<reference>
 *   one-time bills       not deleted (cancelled ones under the Cancelled status)   OTB-<reference>
 *   installment bills    each amortization of a contract that is not deleted        INS-<reference>-<amortization>
 *   recurring bills      not deleted (cancelled ones under the Cancelled status)   REC-<reference>
 *   share capital bills  the posted Fixed Share Capital bills of the members       SCB-<bill>
 *
 * Amount is the bill's amount, Paid what was collected on it (never more than the amount), Balance the rest. A bill
 * is Open while it has a balance and Closed (fully paid) when it has none. The filters (status, bill code, bill type,
 * zone, address sub-group, members or customers, bill dates and the words searched) are put inside each part of the
 * query, so the database reads only the bills asked for. A share capital bill is shown on the member's first
 * water-service account (as the Statement of Account shows it), or on its share capital account when the member has
 * no water service.
 */
final class BillAccounts
{
    public const PAGE = 'bill.accounts';
    public const PAGE_SIZE = 100;
    /** The most lines an export or a print holds */
    public const EXPORT_MAX = 100000;

    public const STATUSES = [
        'open' => 'Open (not fully paid)',
        'overdue' => 'Open and overdue',
        'not_due' => 'Open, not yet due',
        'paid' => 'Closed (fully paid)',
        'all' => 'Open and closed',
        'cancelled' => 'Cancelled',
    ];
    public const TYPES = [
        'water' => 'Water bills', 'one_time' => 'One-time bills', 'installment' => 'Installment bills',
        'recurring' => 'Recurring bills', 'sharecap' => 'Fixed share capital bills',
    ];
    public const TYPE_LABEL = [
        'water' => 'Water bill', 'one_time' => 'One-time bill', 'installment' => 'Installment',
        'recurring' => 'Recurring bill', 'sharecap' => 'Share capital',
    ];
    public const HOLDERS = ['member' => 'Members', 'customer' => 'Customers'];
    public const SORTS = [
        'account' => 'Account no., then bill date', 'name' => 'Name, then bill date', 'bill_new' => 'Bill date, newest first',
        'bill_old' => 'Bill date, oldest first', 'due' => 'Due date, oldest first', 'balance' => 'Balance, largest first',
    ];
    /** The value of the Bill Code filter for the Fixed Share Capital bills (they have no bill code) */
    public const SC_CODE = 'sc';
    /** The value of the Zone and Address sub-group filters for the accounts that have none */
    public const NONE = '__none__';

    /** Whether the water-billing tables are installed (the center has nothing to show otherwise) */
    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(*) FROM bill_readings WHERE 1 = 0');
                DB::value('SELECT COUNT(*) FROM installment_schedules WHERE 1 = 0');
                DB::value('SELECT COUNT(address_subgroup) FROM water_accounts WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    // ================================================================================== the filters

    /** The filters of a request (the query string of the page); anything unknown falls back to its default */
    public static function filters(array $in): array
    {
        $s = static fn(string $k): string => is_string($in[$k] ?? null) ? trim((string)$in[$k]) : '';
        $status = $s('status');
        $code = $s('code');
        $zone = $s('zone');
        $from = $s('from');
        $to = $s('to');
        return [
            'status' => isset(self::STATUSES[$status]) ? $status : 'open',
            'code' => $code === self::SC_CODE || ctype_digit($code) ? $code : '',
            'type' => isset(self::TYPES[$s('type')]) ? $s('type') : '',
            'zone' => $zone === self::NONE || ctype_digit($zone) ? $zone : '',
            'subgroup' => mb_substr($s('subgroup'), 0, 60),
            'holder' => isset(self::HOLDERS[$s('holder')]) ? $s('holder') : '',
            'from' => valid_date($from) ? $from : '',
            'to' => valid_date($to) ? $to : '',
            'q' => mb_substr($s('q'), 0, 80),
            'sort' => isset(self::SORTS[$s('sort')]) ? $s('sort') : 'account',
            'pg' => max(1, (int)$s('pg')),
        ];
    }

    /** Whether the filters differ from the first view of the page (the open bills of everyone) */
    public static function filtered(array $f): bool
    {
        return $f['status'] !== 'open' || $f['code'] !== '' || $f['type'] !== '' || $f['zone'] !== '' || $f['subgroup'] !== ''
            || $f['holder'] !== '' || $f['from'] !== '' || $f['to'] !== '' || $f['sort'] !== 'account';
    }

    /** The filters as words, for a printout and an export */
    public static function describe(array $f): string
    {
        $out = [self::STATUSES[$f['status']]];
        if ($f['code'] === self::SC_CODE) {
            $out[] = 'bill code: fixed share capital';
        } elseif ($f['code'] !== '') {
            $c = DB::row('SELECT code, description FROM bill_codes WHERE code_id = :c', ['c' => (int)$f['code']]);
            $out[] = 'bill code ' . ($c ? $c['code'] : '#' . $f['code']);
        }
        if ($f['type'] !== '') {
            $out[] = mb_strtolower(self::TYPES[$f['type']]);
        }
        if ($f['zone'] !== '') {
            $out[] = $f['zone'] === self::NONE ? 'no zone' : 'zone ' . $f['zone'];
        }
        if ($f['subgroup'] !== '') {
            $out[] = $f['subgroup'] === self::NONE ? 'no address sub-group' : 'address sub-group ' . $f['subgroup'];
        }
        if ($f['holder'] !== '') {
            $out[] = mb_strtolower(self::HOLDERS[$f['holder']]) . ' only';
        }
        if ($f['from'] !== '' || $f['to'] !== '') {
            $out[] = 'billed ' . ($f['from'] !== '' ? 'from ' . fdate($f['from']) : '') . ($f['from'] !== '' && $f['to'] !== '' ? ' ' : '') . ($f['to'] !== '' ? 'to ' . fdate($f['to']) : '');
        }
        if ($f['q'] !== '') {
            $out[] = 'search "' . $f['q'] . '"';
        }
        return implode(', ', $out);
    }

    /** The choices of the filters: bill codes, zones, address sub-groups */
    public static function options(): array
    {
        $codes = [];
        foreach (DB::all('SELECT code_id, code, description FROM bill_codes ORDER BY code') as $c) {
            $d = trim((string)$c['description']);
            $codes[(string)$c['code_id']] = $c['code'] . ($d !== '' && strcasecmp($d, (string)$c['code']) !== 0 ? ' - ' . mb_substr($d, 0, 60) : '');
        }
        $codes[self::SC_CODE] = 'Fixed share capital';
        $zones = [];
        foreach (DB::column('SELECT DISTINCT zone FROM water_accounts WHERE zone IS NOT NULL ORDER BY zone') as $z) {
            $zones[(string)(int)$z] = 'Zone ' . (int)$z;
        }
        $zones[self::NONE] = 'No zone';
        $subgroups = [];
        foreach (Members::listChoices('address_subgroup', null) as $k => $v) {
            if ((string)$k !== '') {
                $subgroups[(string)$k] = (string)$v;
            }
        }
        foreach (DB::column("SELECT DISTINCT address_subgroup FROM water_accounts WHERE address_subgroup IS NOT NULL AND address_subgroup <> '' ORDER BY address_subgroup") as $sg) {
            $subgroups[(string)$sg] ??= (string)$sg;
        }
        $subgroups[self::NONE] = 'No address sub-group';
        return ['codes' => $codes, 'zones' => $zones, 'subgroups' => $subgroups];
    }

    /** The bill code of the water bills (Water Billing settings), 0 when none is set */
    public static function waterCodeId(): int
    {
        try {
            return (int)(DB::value("SELECT setting_value FROM water_billing_settings WHERE setting_key = 'water_billing_bill_code_id'") ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    // ================================================================================== the query

    /** A string column of a part, in the one character set of the result (the parts read tables of other collations) */
    private static function s(string $expr): string
    {
        return 'CONVERT(' . $expr . ' USING utf8mb4) COLLATE utf8mb4_unicode_ci';
    }

    /** "%words%" for LIKE, with the LIKE characters taken literally */
    private static function like(string $v): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v) . '%';
    }

    /**
     * The union of the parts asked for: [sql, params]. Each line: t (the bill type), id, acct_id (the water-service
     * account, null for a share capital bill of a member with none), acct_no, lastname, firstname, middlename,
     * display_name, is_member, zone, subgroup, code_id, code, code_desc, bill_date, due_date, inv (the invoice no.),
     * amount, paid, cancelled, x1, x2 (the details of its description).
     */
    public static function union(array $f): array
    {
        $p = [];
        $parts = [];
        $wc = self::waterCodeId();
        $want = static function (string $t) use ($f, $wc): bool {
            if ($f['type'] !== '' && $f['type'] !== $t) {
                return false;
            }
            if ($t === 'sharecap' && $f['holder'] === 'customer') {
                return false;   // a share capital bill is a member's
            }
            if ($f['code'] === self::SC_CODE) {
                return $t === 'sharecap';
            }
            if ($f['code'] !== '') {
                return $t === 'water' ? (int)$f['code'] === $wc && $wc > 0 : $t !== 'sharecap';
            }
            return true;
        };
        // the conditions every part shares: the bill's state, its dates, its account
        $common = function (string $n, string $amt, string $paid, string $cancel, string $billDate, string $due, string $inv, bool $sc) use ($f, &$p): string {
            $w = [];
            $open = "($cancel) = 0 AND ($amt) - ($paid) > 0.004";
            $w[] = match ($f['status']) {
                'open' => $open,
                'overdue' => "$open AND $due < CURDATE()",
                'not_due' => "$open AND ($due IS NULL OR $due >= CURDATE())",
                'paid' => "($cancel) = 0 AND ($amt) - ($paid) <= 0.004",
                'cancelled' => "($cancel) = 1",
                default => "($cancel) = 0",
            };
            if ($f['from'] !== '') {
                $w[] = "$billDate >= :{$n}_from";
                $p["{$n}_from"] = $f['from'];
            }
            if ($f['to'] !== '') {
                $w[] = "$billDate <= :{$n}_to";
                $p["{$n}_to"] = $f['to'];
            }
            if ($f['zone'] !== '') {
                if ($f['zone'] === self::NONE) {
                    $w[] = 'w.zone IS NULL';
                } else {
                    $w[] = "w.zone = :{$n}_zone";
                    $p["{$n}_zone"] = (int)$f['zone'];
                }
            }
            $sg = $sc ? "COALESCE(NULLIF(w.address_subgroup, ''), m.address_subgroup)" : 'w.address_subgroup';
            if ($f['subgroup'] !== '') {
                if ($f['subgroup'] === self::NONE) {
                    $w[] = "($sg IS NULL OR $sg = '')";
                } else {
                    $w[] = "$sg = :{$n}_sg";
                    $p["{$n}_sg"] = $f['subgroup'];
                }
            }
            if (!$sc && $f['holder'] !== '') {
                $w[] = ($f['holder'] === 'customer' ? 'NOT ' : '') . 'EXISTS (SELECT 1 FROM members mh WHERE mh.party_id = w.party_id)';
            }
            if ($f['code'] !== '' && $f['code'] !== self::SC_CODE && $n !== 'w') {
                $w[] = "bc.code_id = :{$n}_code";
                $p["{$n}_code"] = (int)$f['code'];
            }
            if ($f['q'] !== '') {
                // every word in the name, or the whole search in the account no., the invoice no. or the member no.
                $words = array_slice(array_values(array_filter(preg_split('/[\s,]+/u', $f['q']) ?: [], fn($x) => $x !== '')), 0, 5);
                $nameCond = [];
                foreach ($words as $i => $word) {
                    $k = "{$n}_w$i";
                    $p[$k . 'a'] = $p[$k . 'b'] = $p[$k . 'c'] = $p[$k . 'd'] = self::like($word);
                    $nameCond[] = $sc ? "(m.full_name LIKE :{$k}a OR m.last_name LIKE :{$k}b OR m.first_name LIKE :{$k}c OR m.member_no LIKE :{$k}d)"
                        : "(w.lastname LIKE :{$k}a OR w.firstname LIKE :{$k}b OR w.middlename LIKE :{$k}c OR p.display_name LIKE :{$k}d)";
                }
                $acct = $sc ? "COALESCE(w.account_number, a.account_no)" : 'w.account_number';
                $p["{$n}_qa"] = $p["{$n}_qi"] = self::like($f['q']);
                $p["{$n}_qm"] = $f['q'];
                $member = $sc ? "m.member_no = :{$n}_qm" : "EXISTS (SELECT 1 FROM members mq WHERE mq.party_id = w.party_id AND mq.member_no = :{$n}_qm)";
                $w[] = '(' . ($nameCond ? '(' . implode(' AND ', $nameCond) . ') OR ' : '') . "$acct LIKE :{$n}_qa OR $inv LIKE :{$n}_qi OR $member)";
            }
            return implode(' AND ', $w);
        };
        $s = [self::class, 's'];
        $acctCols = static fn(): string => 'w.pkey AS acct_id, ' . $s('w.account_number') . ' AS acct_no, ' . $s('w.lastname') . ' AS lastname, ' . $s('w.firstname') . ' AS firstname, '
            . $s('w.middlename') . ' AS middlename, ' . $s('p.display_name') . ' AS display_name, '
            . 'CASE WHEN EXISTS (SELECT 1 FROM members mk WHERE mk.party_id = w.party_id) THEN 1 ELSE 0 END AS is_member, w.zone AS zone, ' . $s('w.address_subgroup') . ' AS subgroup';
        $ref = static fn(string $prefix, string $col, string $id): string => "CONCAT('$prefix-', CASE WHEN COALESCE($col, 0) = 0 THEN $id ELSE $col END)";

        if ($want('water')) {
            $inv = $ref('WTR', 'br.global_ref_number', 'br.reading_id');
            $amt = 'br.amount_due';
            $paid = 'LEAST(br.amount_due, GREATEST(0, COALESCE(br.amount_paid, 0)))';
            $parts[] = "SELECT 'water' AS t, br.reading_id AS id, " . $acctCols() . ', bc.code_id AS code_id, ' . $s('bc.code') . ' AS code, ' . $s('bc.description') . ' AS code_desc,
                    br.billing_date AS bill_date, br.due_date AS due_date, ' . $s($inv) . " AS inv, $amt AS amount, $paid AS paid, 0 AS cancelled,
                    " . $s('CAST(br.consumption AS CHAR)') . ' AS x1, ' . $s('bp.bp_code') . " AS x2
               FROM bill_readings br
               JOIN water_accounts w ON w.pkey = br.member_id
               LEFT JOIN parties p ON p.id = w.party_id
               LEFT JOIN bill_periods bp ON bp.period_id = br.period_id
               LEFT JOIN bill_codes bc ON bc.code_id = " . $wc . "
              WHERE br.is_billed = 1 AND COALESCE(br.bill_lifecycle_status, 'active') <> 'deleted'
                AND " . $common('w', $amt, $paid, '0', 'br.billing_date', 'br.due_date', $inv, false);
        }
        if ($want('one_time')) {
            $inv = $ref('OTB', 'b.global_ref_number', 'b.bill_id');
            $amt = 'b.total_amount';
            $paid = 'LEAST(b.total_amount, GREATEST(0, COALESCE(b.amount_paid, 0)))';
            $cancel = "CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END";
            $parts[] = "SELECT 'one_time' AS t, b.bill_id AS id, " . $acctCols() . ', b.bill_code_id AS code_id, ' . $s('bc.code') . ' AS code, ' . $s('bc.description') . ' AS code_desc,
                    b.bill_date AS bill_date, b.due_date AS due_date, ' . $s($inv) . " AS inv, $amt AS amount, $paid AS paid, $cancel AS cancelled,
                    " . $s('CAST(b.quantity AS CHAR)') . ' AS x1, ' . $s('bc.type') . " AS x2
               FROM one_time_bills b
               JOIN water_accounts w ON w.pkey = b.member_id
               LEFT JOIN parties p ON p.id = w.party_id
               LEFT JOIN bill_codes bc ON bc.code_id = b.bill_code_id
              WHERE b.status <> 'deleted' AND COALESCE(b.bill_lifecycle_status, 'active') <> 'deleted'
                AND " . $common('o', $amt, $paid, $cancel, 'b.bill_date', 'b.due_date', $inv, false);
        }
        if ($want('installment')) {
            $inv = "CONCAT('INS-', CASE WHEN COALESCE(b.global_ref_number, 0) = 0 THEN b.installment_id ELSE b.global_ref_number END, '-', s.schedule_id)";
            $amt = 's.amount';
            $paid = 'LEAST(s.amount, GREATEST(0, COALESCE(s.amount_paid, 0)))';
            $cancel = "CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END";
            $parts[] = "SELECT 'installment' AS t, s.schedule_id AS id, " . $acctCols() . ', b.bill_code_id AS code_id, ' . $s('bc.code') . ' AS code, ' . $s('bc.description') . ' AS code_desc,
                    s.due_date AS bill_date, s.due_date AS due_date, ' . $s($inv) . " AS inv, $amt AS amount, $paid AS paid, $cancel AS cancelled,
                    " . $s('CAST(b.installment_id AS CHAR)') . ' AS x1, ' . $s('CAST(b.start_date AS CHAR)') . " AS x2
               FROM installment_schedules s
               JOIN installment_bills b ON b.installment_id = s.installment_id
               JOIN water_accounts w ON w.pkey = b.member_id
               LEFT JOIN parties p ON p.id = w.party_id
               LEFT JOIN bill_codes bc ON bc.code_id = b.bill_code_id
              WHERE b.status <> 'deleted' AND COALESCE(b.bill_lifecycle_status, 'active') <> 'deleted'
                AND " . $common('i', $amt, $paid, $cancel, 's.due_date', 's.due_date', $inv, false);
        }
        if ($want('recurring')) {
            $inv = $ref('REC', 'rb.global_ref_number', 'rb.bill_id');
            $amt = 'rb.total_amount';
            $paid = 'LEAST(rb.total_amount, GREATEST(0, COALESCE(rb.amount_paid, 0)))';
            $cancel = "CASE WHEN rb.status = 'cancelled' THEN 1 ELSE 0 END";
            $parts[] = "SELECT 'recurring' AS t, rb.bill_id AS id, " . $acctCols() . ', rb.bill_code_id AS code_id, ' . $s('bc.code') . ' AS code, ' . $s('bc.description') . ' AS code_desc,
                    rb.bill_date AS bill_date, rb.due_date AS due_date, ' . $s($inv) . " AS inv, $amt AS amount, $paid AS paid, $cancel AS cancelled,
                    " . $s('CAST(rb.billing_period_start AS CHAR)') . ' AS x1, ' . $s('CAST(rb.billing_period_end AS CHAR)') . " AS x2
               FROM recurring_bills rb
               JOIN water_accounts w ON w.pkey = rb.member_id
               LEFT JOIN parties p ON p.id = w.party_id
               LEFT JOIN bill_codes bc ON bc.code_id = rb.bill_code_id
              WHERE rb.status <> 'deleted' AND COALESCE(rb.bill_lifecycle_status, 'active') <> 'deleted'
                AND " . $common('r', $amt, $paid, $cancel, 'rb.bill_date', 'rb.due_date', $inv, false);
        }
        if ($want('sharecap') && self::shareCapitalReady()) {
            $inv = "CONCAT('SCB-', b.bill_id)";
            $amt = 'b.amount';
            $paid = 'LEAST(b.amount, GREATEST(0, COALESCE(b.amount_paid, 0)))';
            $cancel = "CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END";
            $parts[] = "SELECT 'sharecap' AS t, b.bill_id AS id, w.pkey AS acct_id, " . $s('COALESCE(w.account_number, a.account_no)') . ' AS acct_no, '
                . $s('m.last_name') . ' AS lastname, ' . $s('m.first_name') . ' AS firstname, ' . $s('m.middle_name') . ' AS middlename, ' . $s('m.full_name') . ' AS display_name,
                    1 AS is_member, w.zone AS zone, ' . $s("COALESCE(NULLIF(w.address_subgroup, ''), m.address_subgroup)") . ' AS subgroup, NULL AS code_id, '
                . $s("'SHARE CAPITAL'") . ' AS code, ' . $s("'Fixed share capital'") . " AS code_desc,
                    b.bill_date AS bill_date, b.due_date AS due_date, " . $s($inv) . " AS inv, $amt AS amount, $paid AS paid, $cancel AS cancelled,
                    " . $s('a.account_no') . ' AS x1, ' . $s('m.member_no') . " AS x2
               FROM share_capital_bills b
               JOIN members m ON m.id = b.member_id
               JOIN sav_accounts a ON a.id = b.sav_account_id
               LEFT JOIN water_accounts w ON w.pkey = (SELECT w2.pkey FROM water_accounts w2 WHERE w2.party_id = m.party_id
                                                        ORDER BY CASE WHEN COALESCE(w2.status, 'A') = 'A' THEN 0 ELSE 1 END, w2.pkey LIMIT 1)
              WHERE " . $common('c', $amt, $paid, $cancel, 'b.bill_date', 'b.due_date', $inv, true);
        }
        if (!$parts) {
            // nothing can match (a filter that excludes every part): one empty line set of the same columns
            $parts[] = "SELECT 'none' AS t, 0 AS id, NULL AS acct_id, '' AS acct_no, '' AS lastname, '' AS firstname, '' AS middlename, '' AS display_name, 0 AS is_member,
                    NULL AS zone, '' AS subgroup, NULL AS code_id, '' AS code, '' AS code_desc, NULL AS bill_date, NULL AS due_date, '' AS inv,
                    0 AS amount, 0 AS paid, 0 AS cancelled, '' AS x1, '' AS x2 FROM DUAL WHERE 1 = 0";
        }
        return ['(' . implode(")\n UNION ALL (", $parts) . ')', $p];
    }

    private static function shareCapitalReady(): bool
    {
        return class_exists('ShareCapitalBilling') && ShareCapitalBilling::ready();
    }

    private static function orderBy(string $sort): string
    {
        $acct = "LPAD(u.acct_no, 24, '0')";
        $name = "COALESCE(NULLIF(u.lastname, ''), u.display_name), u.firstname";
        return match ($sort) {
            'name' => "$name, $acct, u.bill_date, u.inv",
            'bill_new' => "u.bill_date DESC, $acct, u.inv",
            'bill_old' => "u.bill_date, $acct, u.inv",
            'due' => "CASE WHEN u.due_date IS NULL THEN 1 ELSE 0 END, u.due_date, $acct, u.inv",
            'balance' => "(u.amount - u.paid) DESC, $acct, u.bill_date, u.inv",
            default => "$acct, u.bill_date, u.inv",
        };
    }

    /** The count and totals of the bills the filters find: n, amount, paid, balance, overdue (count), overdue_balance */
    public static function totals(array $f): array
    {
        [$sql, $p] = self::union($f);
        $r = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(u.amount), 0) AS amount, COALESCE(SUM(u.paid), 0) AS paid, COALESCE(SUM(u.amount - u.paid), 0) AS balance,
                             COALESCE(SUM(CASE WHEN u.cancelled = 0 AND u.amount - u.paid > 0.004 AND u.due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue,
                             COALESCE(SUM(CASE WHEN u.cancelled = 0 AND u.amount - u.paid > 0.004 AND u.due_date < CURDATE() THEN u.amount - u.paid ELSE 0 END), 0) AS overdue_balance,
                             COUNT(DISTINCT u.acct_no) AS accounts
                        FROM ($sql) u", $p) ?: [];
        return [
            'n' => (int)($r['n'] ?? 0), 'accounts' => (int)($r['accounts'] ?? 0),
            'amount' => Dec::round((string)($r['amount'] ?? '0'), 2), 'paid' => Dec::round((string)($r['paid'] ?? '0'), 2),
            'balance' => Dec::round((string)($r['balance'] ?? '0'), 2), 'overdue' => (int)($r['overdue'] ?? 0),
            'overdue_balance' => Dec::round((string)($r['overdue_balance'] ?? '0'), 2),
        ];
    }

    /** The lines of the bills the filters find, sorted, $limit from $offset, each made ready to show (line()) */
    public static function rows(array $f, int $offset = 0, int $limit = self::PAGE_SIZE): array
    {
        [$sql, $p] = self::union($f);
        $limit = max(1, min(self::EXPORT_MAX, $limit));
        $offset = max(0, $offset);
        $raw = DB::all("SELECT u.* FROM ($sql) u ORDER BY " . self::orderBy($f['sort']) . " LIMIT $offset, $limit", $p);
        // the amortization of each installment line: its place among the amortizations of its contract
        $inst = array_values(array_unique(array_map(fn($r) => (int)$r['x1'], array_filter($raw, fn($r) => $r['t'] === 'installment'))));
        $place = [];
        foreach (array_chunk($inst, 500) as $chunk) {
            $byContract = [];
            foreach (DB::all('SELECT schedule_id, installment_id FROM installment_schedules WHERE installment_id IN (' . implode(',', array_map('intval', $chunk)) . ')
                               ORDER BY installment_id, due_date, schedule_id') as $s) {
                $byContract[(int)$s['installment_id']][] = (int)$s['schedule_id'];
            }
            foreach ($byContract as $ids) {
                foreach ($ids as $k => $sid) {
                    $place[$sid] = [$k + 1, count($ids)];
                }
            }
        }
        $today = today();
        return array_map(fn($r) => self::line($r, $place, $today), $raw);
    }

    /** A bill code as a line names it: its description, or its code when it has none ($withCode: the code after it) */
    private static function chargeName(?string $code, ?string $desc, bool $withCode = true): string
    {
        $code = trim((string)$code);
        $desc = trim((string)$desc);
        if ($desc === '') {
            return $code !== '' ? $code : 'Charge';
        }
        return !$withCode || strcasecmp($desc, $code) === 0 || $code === '' ? $desc : $desc . ' (' . $code . ')';
    }

    /** The holder's name as the water pages print it: LAST, First Middle */
    public static function holderName(array $r): string
    {
        $last = trim((string)($r['lastname'] ?? ''));
        $given = trim(implode(' ', array_filter([trim((string)($r['firstname'] ?? '')), trim((string)($r['middlename'] ?? ''))], fn($x) => $x !== '')));
        $name = $last !== '' ? $last . ($given !== '' ? ', ' . $given : '') : $given;
        if ($name === '') {
            $name = trim((string)($r['display_name'] ?? ''));
        }
        return $name !== '' ? $name : 'Account ' . (int)($r['acct_id'] ?? 0);
    }

    /** One line made ready to show: its description, its balance and its status in words */
    private static function line(array $r, array $place, string $today): array
    {
        $amount = Dec::round((string)$r['amount'], 2);
        $paid = Dec::round((string)$r['paid'], 2);
        $balance = Dec::sub($amount, $paid, 2);
        $t = (string)$r['t'];
        $code = trim((string)$r['code']);
        $charge = self::chargeName($r['code'], $r['code_desc'], false);
        [$desc, $detail] = match ($t) {
            'water' => ['Water consumption, ' . number_format((int)$r['x1']) . ' m³', trim((string)$r['x2']) !== '' ? 'period ' . trim((string)$r['x2']) : ''],
            'one_time' => [$charge . (strcasecmp(trim((string)$r['x2']), 'Inventory') === 0 ? ', quantity ' . rtrim(rtrim(number_format((float)$r['x1'], 2), '0'), '.') : ''), $code],
            'installment' => [$charge . (isset($place[(int)$r['id']]) ? ', amortization ' . $place[(int)$r['id']][0] . ' of ' . $place[(int)$r['id']][1] : ''), $code],
            'recurring' => [$charge . self::span((string)$r['x1'], (string)$r['x2']), $code],
            'sharecap' => ['Share capital ' . $r['x1'] . ' (' . ($r['bill_date'] ? date('M Y', strtotime((string)$r['bill_date'])) : '') . ')', trim((string)$r['x2']) !== '' ? 'member no. ' . trim((string)$r['x2']) : ''],
            default => ['', ''],
        };
        if (strcasecmp($detail, $charge) === 0) {
            $detail = '';
        }
        $due = $r['due_date'] ? substr((string)$r['due_date'], 0, 10) : null;
        $days = 0;
        if ((int)$r['cancelled'] === 1) {
            [$state, $label] = ['cancelled', 'Cancelled'];
        } elseif (!Dec::isPos($balance)) {
            [$state, $label] = ['paid', 'Paid'];
        } elseif ($due === null) {
            [$state, $label] = ['open', 'Open'];
        } elseif ($due < $today) {
            $days = (int)(new DateTimeImmutable($due))->diff(new DateTimeImmutable($today))->days;
            [$state, $label] = ['overdue', 'Overdue'];
        } elseif ($due === $today) {
            [$state, $label] = ['due', 'Due today'];
        } else {
            [$state, $label] = ['current', 'Not yet due'];
        }
        $partly = Dec::isPos($paid) && Dec::isPos($balance) && $state !== 'cancelled';
        return [
            'type' => $t, 'type_label' => self::TYPE_LABEL[$t] ?? $t, 'id' => (int)$r['id'],
            'acct_id' => $r['acct_id'] !== null ? (int)$r['acct_id'] : null, 'account_no' => trim((string)$r['acct_no']),
            'name' => self::holderName($r), 'kind' => (int)$r['is_member'] === 1 ? 'Member' : 'Customer',
            'zone' => $r['zone'] !== null ? (int)$r['zone'] : null, 'subgroup' => trim((string)$r['subgroup']),
            'code' => $code, 'description' => $desc, 'detail' => $detail,
            'full_description' => $desc . ($detail !== '' ? ' (' . $detail . ')' : ''),
            'bill_date' => $r['bill_date'] ? substr((string)$r['bill_date'], 0, 10) : null, 'due_date' => $due,
            'invoice_no' => (string)$r['inv'], 'amount' => $amount, 'paid' => $paid, 'balance' => $balance,
            'state' => $state, 'status' => $label . ($partly ? ', partly paid' : ''), 'days_overdue' => $days,
            'open' => in_array($state, ['open', 'overdue', 'due', 'current'], true),
        ];
    }

    /** ", Oct 2026" or ", Oct 1, 2026 to Dec 31, 2026": the service period of a recurring bill */
    private static function span(string $start, string $end): string
    {
        if (!valid_date(substr($start, 0, 10)) || !valid_date(substr($end, 0, 10))) {
            return '';
        }
        $a = strtotime(substr($start, 0, 10));
        $b = strtotime(substr($end, 0, 10));
        if (date('Y-m', $a) === date('Y-m', $b) && date('j', $a) === '1' && date('t', $b) === date('j', $b)) {
            return ', ' . date('M Y', $a);
        }
        return ', ' . date('M j, Y', $a) . ' to ' . date('M j, Y', $b);
    }

    // ================================================================================== the export

    /** The heading of the columns, as on the page */
    public const COLUMNS = ['Account No.', 'Name', 'Description', 'Bill Date', 'Invoice No.', 'Due Date', 'Amount', 'Paid', 'Balance', 'Status'];

    /** One bill as a row of an export (the columns of COLUMNS; the amounts as numbers) */
    public static function sheetRow(array $l): array
    {
        return [$l['account_no'], $l['name'], $l['full_description'], $l['bill_date'] ? fdate($l['bill_date']) : '', $l['invoice_no'], $l['due_date'] ? fdate($l['due_date']) : '',
            (float)$l['amount'], (float)$l['paid'], (float)$l['balance'], $l['status']];
    }

    /** The total row of an export */
    public static function totalRow(array $tot): array
    {
        return ['Total: ' . number_format($tot['n']) . ' bill' . ($tot['n'] === 1 ? '' : 's') . ' of ' . number_format($tot['accounts']) . ' account' . ($tot['accounts'] === 1 ? '' : 's'), '', '', '', '', '',
            (float)$tot['amount'], (float)$tot['paid'], (float)$tot['balance'], ''];
    }

    /** The bills the filters find, a few thousand at a time (an export or a printout of many bills keeps little in memory) */
    public static function chunks(array $f, int $n, int $size = 5000): Generator
    {
        for ($o = 0; $o < min($n, self::EXPORT_MAX); $o += $size) {
            yield self::rows($f, $o, min($size, self::EXPORT_MAX - $o));
        }
    }

    /** The rows of an export or a printout: the title, the filters, the heading, the lines, the total line; [rows, the row numbers in bold, the money columns] */
    public static function sheet(array $f, iterable $lines, array $tot, string $title): array
    {
        $rows = [[$title], [self::describe($f) . ', as of ' . fdate(today())], self::COLUMNS];
        foreach ($lines as $l) {
            $rows[] = self::sheetRow($l);
        }
        $rows[] = self::totalRow($tot);
        return [$rows, [0, 2, count($rows) - 1], [6, 7, 8]];
    }
}
