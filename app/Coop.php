<?php
declare(strict_types=1);

/**
 * Shared pieces of the cooperative module: settings, branches and branch scope, numbering,
 * date arithmetic, the one-time form token that stops a double submission, and member look-ups.
 */
final class Coop
{
    public const MEMBER_STATUSES = [
        'applicant' => 'Applicant', 'active' => 'Active', 'suspended' => 'Suspended',
        'resigned' => 'Resigned', 'terminated' => 'Terminated', 'deceased' => 'Deceased',
    ];

    /** mode of payment => [label, days between amortizations (0 = calendar months), months between amortizations] */
    public const MODES = [
        'daily'        => ['Daily', 1, 0],
        'weekly'       => ['Weekly', 7, 0],
        'semi_monthly' => ['Semi-monthly', 15, 0],
        'monthly'      => ['Monthly', 0, 1],
        'quarterly'    => ['Quarterly', 0, 3],
        'semi_annual'  => ['Semi-annual', 0, 6],
        'annual'       => ['Annual', 0, 12],
        'lump_sum'     => ['Lump sum at maturity', 0, 0],
    ];

    private static ?array $branches = null;

    // ------------------------------------------------------------------ availability

    /** The cooperative tables are installed (the 2026_11_01 migration ran) */
    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(*) FROM members WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    public static function requireReady(string $title): void
    {
        if (!self::ready()) {
            layout_start($title);
            echo '<div class="alert alert-warning">The cooperative module needs a database update. Import <code>database/migrations/2026_11_01_cooperative.sql</code> (phpMyAdmin or Navicat), then reload this page.</div>';
            layout_end();
            exit;
        }
    }

    // ------------------------------------------------------------------ settings

    public static function setting(string $key, string $default = ''): string
    {
        $v = Settings::get('coop_' . $key, '');
        return $v === '' ? $default : $v;
    }

    public static function coopName(): string
    {
        return self::setting('registered_name') ?: Settings::get('company_name');
    }

    // ------------------------------------------------------------------ branches

    /** id => row (active and inactive) */
    public static function branches(): array
    {
        if (self::$branches === null) {
            self::$branches = [];
            foreach (DB::all('SELECT * FROM coop_branches ORDER BY is_head_office DESC, code') as $b) {
                self::$branches[(int)$b['id']] = $b;
            }
        }
        return self::$branches;
    }

    public static function resetBranches(): void
    {
        self::$branches = null;
    }

    public static function branch(?int $id): ?array
    {
        return $id ? (self::branches()[$id] ?? null) : null;
    }

    public static function branchName(?int $id): string
    {
        $b = self::branch($id);
        return $b ? $b['name'] : '';
    }

    /** id => "CODE - Name" of the branches the user may work in */
    public static function branchOptions(bool $activeOnly = true): array
    {
        $out = [];
        $mine = self::userBranchId();
        foreach (self::branches() as $id => $b) {
            if ($activeOnly && (int)$b['is_active'] !== 1) {
                continue;
            }
            if ($mine !== null && $mine !== $id) {
                continue;
            }
            $out[$id] = $b['code'] . ' - ' . $b['name'];
        }
        return $out;
    }

    /** The branch the user is limited to, or null when they see every branch */
    public static function userBranchId(?int $userId = null): ?int
    {
        $userId ??= Auth::id();
        if (!$userId || Access::isAdmin($userId) || Access::special('coop_all_branches', $userId)) {
            return null;
        }
        static $cache = [];
        if (!array_key_exists($userId, $cache)) {
            $v = DB::value('SELECT coop_branch_id FROM users WHERE id = :id', ['id' => $userId]);
            $cache[$userId] = $v ? (int)$v : null;
        }
        return $cache[$userId];
    }

    /** The branch new records are filed under: the user's own, else the head office, else the first branch */
    public static function defaultBranchId(): ?int
    {
        $mine = self::userBranchId();
        if ($mine) {
            return $mine;
        }
        $v = DB::value('SELECT coop_branch_id FROM users WHERE id = :id', ['id' => Auth::id()]);
        if ($v) {
            return (int)$v;
        }
        foreach (self::branches() as $id => $b) {
            if ((int)$b['is_active'] === 1) {
                return $id;
            }
        }
        return null;
    }

    public static function branchAllowed(?int $branchId): bool
    {
        $mine = self::userBranchId();
        return $mine === null || $branchId === null || $mine === $branchId;
    }

    public static function requireBranch(?int $branchId): void
    {
        if (!self::branchAllowed($branchId)) {
            fail('That record belongs to another branch. You are limited to ' . self::branchName(self::userBranchId()) . '.');
        }
    }

    /** SQL fragment " AND col = :b" for the user's branch, or '' when they see every branch */
    public static function branchFilter(string $column, string $param = 'ubr'): array
    {
        $mine = self::userBranchId();
        if ($mine === null) {
            return ['', []];
        }
        return [" AND ($column IS NULL OR $column = :$param)", [$param => $mine]];
    }

    /** The class (Class List) of a branch, for the ledger lines */
    public static function branchClassId(?int $branchId): ?int
    {
        $b = self::branch($branchId);
        return $b && $b['class_id'] ? (int)$b['class_id'] : null;
    }

    // ------------------------------------------------------------------ numbering

    /** Member number from the sequence (prefix and padding on Cooperative > Document Numbering) */
    public static function nextMemberNo(): string
    {
        return Seq::next('member');
    }

    /**
     * Savings account number from the format setting, e.g. {BRANCH}{MEMBER}{PRODUCT}1 gives 01100001031 for
     * branch 01, member 100001, product 03 (the reference layout). {SEQ} draws the next number of 'sav_account'.
     */
    public static function savAccountNo(array $member, array $product, ?int $branchId): string
    {
        $fmt = self::setting('sav_account_format', '{BRANCH}{MEMBER}{PRODUCT}1');
        $branch = self::branch($branchId);
        $no = strtr($fmt, [
            '{BRANCH}'  => $branch ? str_pad(preg_replace('/\D/', '', (string)$branch['code']) ?: '0', 2, '0', STR_PAD_LEFT) : '00',
            '{BRANCHC}' => $branch ? (string)$branch['code'] : '',
            '{MEMBER}'  => (string)$member['member_no'],
            '{PRODUCT}' => str_pad(preg_replace('/\D/', '', (string)$product['code']) ?: '0', 2, '0', STR_PAD_LEFT),
            '{PRODUCTC}'=> (string)$product['code'],
            '{SEQ}'     => str_contains($fmt, '{SEQ}') ? Seq::next('sav_account') : '',
        ]);
        // a member may hold more than one account of the same product: add a suffix until the number is free
        $base = $no;
        $n = 1;
        while (DB::value('SELECT id FROM sav_accounts WHERE account_no = :n', ['n' => $no])) {
            $n++;
            $no = $base . '-' . $n;
        }
        return $no;
    }

    /** Loan account number, e.g. {BRANCH}-{PRODUCT}-{SEQ} gives 01-6-0000113 */
    public static function loanNo(array $product, ?int $branchId): string
    {
        $fmt = self::setting('loan_account_format', '{BRANCH}-{PRODUCT}-{SEQ}');
        $branch = self::branch($branchId);
        // a number already on file (a loan of the former system imported with its own number) is passed over
        for ($i = 0; $i < 1000; $i++) {
            $no = strtr($fmt, [
                '{BRANCH}'  => $branch ? str_pad(preg_replace('/\D/', '', (string)$branch['code']) ?: '0', 2, '0', STR_PAD_LEFT) : '00',
                '{BRANCHC}' => $branch ? (string)$branch['code'] : '',
                '{PRODUCT}' => (string)$product['code'],
                '{SEQ}'     => Seq::next('loan'),
                '{YEAR}'    => date('Y'),
            ]);
            if (!DB::value('SELECT id FROM loan_accounts WHERE loan_no = :n', ['n' => $no])) {
                break;
            }
        }
        return $no;
    }

    /** Official receipt number: the branch series prefix + the next number of coop_or (pre-printed numbers can be typed instead) */
    public static function nextReceiptNo(string $kind, ?int $branchId): string
    {
        $branch = self::branch($branchId);
        $no = Seq::next($kind === 'cv' ? 'coop_cv' : 'coop_or');
        return ($kind === 'or' && $branch ? (string)$branch['or_prefix'] : '') . $no;
    }

    // ------------------------------------------------------------------ dates

    public static function addMonths(string $date, int $months): string
    {
        // keeps the day of the month where it exists (Jan 31 + 1 month = Feb 28/29), as amortization dates do
        $d = new DateTime($date);
        $day = (int)$d->format('j');
        $d->modify('first day of this month');
        $d->modify(($months >= 0 ? '+' : '') . $months . ' months');
        $last = (int)$d->format('t');
        $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($day, $last));
        return $d->format('Y-m-d');
    }

    public static function addDays(string $date, int $days): string
    {
        return date('Y-m-d', strtotime($date . ' ' . ($days >= 0 ? '+' : '') . $days . ' days'));
    }

    /** Whole days from $from to $to (negative when $to is earlier) */
    public static function daysBetween(string $from, string $to): int
    {
        $a = new DateTime($from);
        $b = new DateTime($to);
        $diff = $a->diff($b);
        return (int)$diff->days * ($diff->invert ? -1 : 1);
    }

    /** The amortization date $n periods after $start for a mode of payment */
    public static function nthDue(string $start, string $mode, int $n): string
    {
        [, $days, $months] = self::MODES[$mode] ?? self::MODES['monthly'];
        if ($months > 0) {
            return self::addMonths($start, $months * $n);
        }
        if ($days > 0) {
            return self::addDays($start, $days * $n);
        }
        return $start;
    }

    /** The number of amortizations in a term (term in months or days) for a mode of payment */
    public static function installments(string $mode, string $term, string $termUnit): int
    {
        [, $days, $months] = self::MODES[$mode] ?? self::MODES['monthly'];
        $term = (float)$term;
        if ($mode === 'lump_sum') {
            return 1;
        }
        if ($termUnit === 'months') {
            if ($months > 0) {
                return max(1, (int)round($term / $months));
            }
            return max(1, (int)round($term * 30 / $days));
        }
        // term in days
        if ($days > 0) {
            return max(1, (int)round($term / $days));
        }
        return max(1, (int)round($term / (30 * $months)));
    }

    /** Days in one amortization period, for interest "same as mode of payment" (months use the calendar year / 12) */
    public static function modeLabel(string $mode): string
    {
        return self::MODES[$mode][0] ?? ucfirst($mode);
    }

    public static function modeOptions(): array
    {
        $out = [];
        foreach (self::MODES as $k => [$label]) {
            $out[$k] = $label;
        }
        return $out;
    }

    // ------------------------------------------------------------------ one-time form token (double submission)

    /**
     * A hidden field with a token that can be used once. A page that saves money calls consume_once() at the top
     * of its POST handler: a second submission of the same form (double click, browser resend) is refused.
     */
    public static function onceField(): string
    {
        $token = bin2hex(random_bytes(12));
        $_SESSION['once'] = array_slice(array_filter((array)($_SESSION['once'] ?? []), fn($t) => $t > time() - 7200), -40, null, true);
        $_SESSION['once'][$token] = time();
        return '<input type="hidden" name="_once" value="' . h($token) . '">';
    }

    public static function consumeOnce(): void
    {
        $token = (string)($_POST['_once'] ?? '');
        if ($token === '' || !isset($_SESSION['once'][$token])) {
            fail('This form was already submitted, or the page is out of date. The entry was not saved a second time; refresh the page and check the list before entering it again.');
        }
        unset($_SESSION['once'][$token]);
    }

    /** Puts the token back when the save failed with a user error, so the corrected form can be sent again */
    public static function restoreOnce(): void
    {
        $token = (string)($_POST['_once'] ?? '');
        if ($token !== '') {
            $_SESSION['once'][$token] = time();
        }
    }

    // ------------------------------------------------------------------ back-dating

    /** Back-dated member transactions: refused beyond the policy, or allowed with the special permission (and audited) */
    public static function checkDate(string $date, bool $allowFuture = false): void
    {
        if (!valid_date($date)) {
            fail('Enter a valid date.');
        }
        if (!$allowFuture && $date > today()) {
            fail('The transaction date cannot be in the future.');
        }
        $closing = Settings::closingDate();
        if ($closing && $date <= $closing && !Access::special('closed_period_changes')) {
            fail('The books are closed through ' . fdate($closing) . '.');
        }
        $limit = (int)self::setting('backdate_days', '0');
        $daysBack = self::daysBetween($date, today());
        if ($daysBack > $limit && !Access::special('coop_backdate')) {
            fail('Back-dated entries beyond ' . $limit . ' day(s) need the "can record back-dated member transactions" permission.');
        }
    }

    // ------------------------------------------------------------------ members

    public static function member(int $id): ?array
    {
        return DB::row('SELECT * FROM members WHERE id = :id', ['id' => $id]);
    }

    public static function memberLabel(array $m): string
    {
        return $m['full_name'] . ' - ' . $m['member_no'];
    }

    /** Server-side search for the member pickers: by name, member no., passbook, mobile, account or loan number */
    public static function searchMembers(string $q, int $limit = 20, ?string $status = null): array
    {
        $q = trim($q);
        [$bf, $bp] = self::branchFilter('m.branch_id', 'mb');
        $sql = 'SELECT m.id, m.member_no, m.full_name, m.status, m.member_type_id, m.hold_flag, m.alert_note, m.res_city, m.mobile
                  FROM members m WHERE 1 = 1' . $bf;
        $p = $bp;
        if ($status) {
            $sql .= ' AND m.status = :st';
            $p['st'] = $status;
        }
        if ($q !== '') {
            $sql .= ' AND (m.full_name LIKE :q1 OR m.member_no LIKE :q2 OR m.passbook_no = :q3 OR m.mobile LIKE :q4
                       OR m.id IN (SELECT member_id FROM sav_accounts WHERE account_no = :q5)
                       OR m.id IN (SELECT member_id FROM loan_accounts WHERE loan_no = :q6))';
            $p += ['q1' => '%' . $q . '%', 'q2' => $q . '%', 'q3' => $q, 'q4' => '%' . $q . '%', 'q5' => $q, 'q6' => $q];
        }
        return DB::all($sql . ' ORDER BY m.full_name LIMIT ' . (int)$limit, $p);
    }

    /** id => "Name - no." of active members (for small drop-downs; the pickers search as you type) */
    public static function memberOptions(?int $keepId = null, int $limit = 400): array
    {
        [$bf, $bp] = self::branchFilter('branch_id', 'mb');
        $rows = DB::all("SELECT id, full_name, member_no FROM members WHERE (status IN ('active','applicant') OR id = :k)" . $bf . ' ORDER BY full_name LIMIT ' . (int)$limit, $bp + ['k' => $keepId ?? 0]);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = $r['full_name'] . ' - ' . $r['member_no'];
        }
        return $out;
    }

    /** The member's name for a GL line: their row in Employees & Other Names (made with the member) */
    public static function otherNameId(array $member): int
    {
        if (!empty($member['other_name_id'])) {
            return (int)$member['other_name_id'];
        }
        $id = DB::insert('other_names', ['name_type' => 'member', 'name' => $member['full_name'], 'member_id' => $member['id'],
            'employee_no' => $member['member_no'], 'tin' => $member['tin'] ?? null, 'is_active' => 1]);
        DB::update('members', ['other_name_id' => $id], 'id = :id', ['id' => $member['id']]);
        Names::reset();
        return $id;
    }

    /** Guard before a member transacts: status, hold flag */
    public static function assertTransactable(array $member, bool $allowApplicant = false): void
    {
        if ((int)$member['hold_flag'] === 1) {
            fail('Member ' . $member['full_name'] . ' is on hold: ' . ($member['hold_reason'] ?: 'no transactions until released') . '.');
        }
        $ok = ['active'];
        if ($allowApplicant) {
            $ok[] = 'applicant';
        }
        if (!in_array($member['status'], $ok, true)) {
            fail('Member ' . $member['full_name'] . ' is ' . (self::MEMBER_STATUSES[$member['status']] ?? $member['status']) . ' and cannot transact.');
        }
    }

    /** "code - name" of an account */
    public static function accountLabel(?int $id): string
    {
        if (!$id) {
            return '';
        }
        $a = DB::row('SELECT code, name FROM accounts WHERE id = :id', ['id' => $id]);
        return $a ? $a['code'] . ' - ' . $a['name'] : '';
    }

    // ------------------------------------------------------------------ amounts

    /** A posted amount: "1,250.00" -> "1250.00"; refuses anything that is not a number */
    public static function amount(string $key, bool $required = false): string
    {
        $raw = trim((string)($_POST[$key] ?? ''));
        if ($raw === '') {
            if ($required) {
                fail('Enter the amount.');
            }
            return '0';
        }
        return Dec::round(Dec::of($raw), 2);
    }

    /** Shown beside a picker: current balances of a member in one line */
    public static function memberSummary(int $memberId): array
    {
        $sc = Dec::of(DB::value("SELECT COALESCE(SUM(a.balance),0) FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m AND p.product_type = 'share_capital' AND a.status <> 'closed'", ['m' => $memberId]));
        $sv = Dec::of(DB::value("SELECT COALESCE(SUM(a.balance),0) FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m AND p.product_type IN ('ordinary','special') AND a.status <> 'closed'", ['m' => $memberId]));
        $td = Dec::of(DB::value("SELECT COALESCE(SUM(a.balance),0) FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m AND p.product_type = 'time_deposit' AND a.status <> 'closed'", ['m' => $memberId]));
        $ln = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(principal_balance + interest_balance + penalty_balance),0) AS bal FROM loan_accounts WHERE member_id = :m AND status IN ('current','past_due','restructured')", ['m' => $memberId]);
        return ['share_capital' => $sc, 'savings' => $sv, 'time_deposits' => $td, 'loans' => (int)$ln['n'], 'loan_balance' => Dec::of($ln['bal'])];
    }
}
