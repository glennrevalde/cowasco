<?php
declare(strict_types=1);

/**
 * The CSV uploads of the migration from the former system and of the batch transactions, each reviewed in a grid
 * first (app/CsvReview.php):
 *  - members (Member Center > Import Member): individual members, created Active;
 *  - savings (Savings Account Center > Import Savings): one account per row, created Active, its balance entered
 *    with the savings transaction code chosen (the code's GL account takes the other side of the journal entry);
 *  - time_deposits (Time Deposit Center > Import Time Deposit): one placement per row, the same way;
 *  - deposit / withdrawal (Savings Account Transaction > Batch Deposit / Batch Withdrawal): one transaction of the
 *    Savings Transaction Center per row (account no. and amount), with the code, date and memo of the batch.
 * check() checks every row and saves nothing; post() checks again and saves all the rows or none.
 */
final class CsvImports
{
    /**
     * Who a savings or time deposit row's member code names (the consolidated import of Member Center gives the members
     * of its file, not yet on file); null: the member on file
     */
    public static ?Closure $memberResolver = null;

    /** The lists check() reads are read once a request (the consolidated import checks one member at a time) */
    public static bool $memo = false;

    private static array $lists = [];

    public const KINDS = [
        'members' => ['Members', 'member', 'members'],
        'savings' => ['Savings', 'account', 'accounts'],
        'time_deposits' => ['Time deposits', 'placement', 'placements'],
        'deposit' => ['Batch deposit', 'deposit', 'deposits'],
        'withdrawal' => ['Batch withdrawal', 'withdrawal', 'withdrawals'],
    ];

    /** The columns of a kind (see CsvReview) */
    public static function cols(string $kind): array
    {
        return match ($kind) {
            'members' => [
                'member_no' => ['label' => 'Member no.', 'size' => 'sm', 'hint' => 'The member no. of the former system. Blank: the next member no. of this system.'],
                'last_name' => ['label' => 'Last name', 'req' => true],
                'first_name' => ['label' => 'First name', 'req' => true],
                'middle_name' => ['label' => 'Middle name', 'req' => true, 'hint' => 'Write - for a member who has none.'],
                'suffix' => ['label' => 'Suffix', 'size' => 'xs', 'hint' => 'Jr., Sr., III ...'],
                'birth_date' => ['label' => 'Date of birth', 'req' => true, 'type' => 'date', 'size' => 'sm', 'hint' => 'YYYY-MM-DD or MM/DD/YYYY.'],
                'gender' => ['label' => 'Sex', 'type' => 'select', 'options' => ['F' => 'Female', 'M' => 'Male'], 'size' => 'sm'],
                'civil_status' => ['label' => 'Civil status', 'type' => 'select', 'options' => Members::CIVIL, 'size' => 'sm'],
                'member_type' => ['label' => 'Member type', 'req' => true, 'type' => 'select', 'options' => self::memberTypes(), 'size' => 'sm', 'hint' => 'The code or the name, as on Member Types.'],
                'membership_date' => ['label' => 'Membership date', 'req' => true, 'type' => 'date', 'size' => 'sm', 'hint' => 'YYYY-MM-DD or MM/DD/YYYY.'],
                'street' => ['label' => 'Street', 'size' => 'md', 'more' => true, 'hint' => 'House no. and street of the residence address.'],
                'barangay' => ['label' => 'Barangay', 'req' => true],
                'city' => ['label' => 'Municipality / City', 'req' => true],
                'province' => ['label' => 'Province', 'req' => true],
                'branch' => ['label' => 'Branch', 'type' => 'select', 'options' => self::branchCodes(), 'size' => 'sm', 'more' => true, 'hint' => 'The branch code or name. Blank: the branch of the user.'],
                'mobile' => ['label' => 'Mobile', 'more' => true],
                'email' => ['label' => 'E-mail', 'more' => true],
                'tin' => ['label' => 'TIN', 'more' => true],
            ] + self::memberExtras(),
            'savings' => [
                'member_code' => ['label' => 'Member code', 'req' => true, 'size' => 'sm', 'hint' => 'The member no. of the member (imported first).'],
                'member_name' => ['label' => 'Member name', 'req' => true, 'size' => 'lg', 'hint' => 'Checked against the member no.: "Dela Cruz, Juan S." or "Juan S. Dela Cruz".'],
                'savings_code' => ['label' => 'Savings code', 'req' => true, 'type' => 'select', 'options' => self::savProducts(['ordinary', 'special', 'share_capital']), 'size' => 'md', 'hint' => 'The code of the savings or share capital product (Savings & Deposit Products).'],
                'amount' => ['label' => 'Amount', 'req' => true, 'type' => 'money', 'size' => 'sm', 'hint' => 'The balance of the account at the cut-off date (0 opens the account without a balance).'],
                'savings_txn_code' => ['label' => 'Savings transaction code', 'req' => true, 'filled' => true, 'type' => 'select', 'options' => self::importCodes(), 'size' => 'sm', 'hint' => 'A deposit code with a GL account (Savings Transaction Codes). Blank: the code chosen on the page.'],
                'cut_off_date' => ['label' => 'Savings cut-off date', 'req' => true, 'filled' => true, 'type' => 'date', 'size' => 'sm', 'hint' => 'The date of the balance. Blank: the date chosen on the page.'],
                'account_no' => ['label' => 'Account no.', 'size' => 'md', 'more' => true, 'hint' => 'The account no. of the former system. Blank: a new account no.'],
                'passbook_no' => ['label' => 'Passbook no.', 'more' => true],
                'opened_on' => ['label' => 'Opened on', 'type' => 'date', 'more' => true, 'hint' => 'Blank: the cut-off date.'],
            ],
            'time_deposits' => [
                'member_code' => ['label' => 'Member code', 'req' => true, 'size' => 'sm', 'hint' => 'The member no. of the member (imported first).'],
                'member_name' => ['label' => 'Member name', 'req' => true, 'size' => 'lg', 'hint' => 'Checked against the member no.'],
                'td_term' => ['label' => 'Time deposit term', 'req' => true, 'size' => 'sm', 'hint' => 'In days (180), months (6 months, 6M) or years (1 year, 1Y).'],
                'amount' => ['label' => 'Amount', 'req' => true, 'type' => 'money', 'size' => 'sm', 'hint' => 'The principal placed.'],
                'savings_txn_code' => ['label' => 'Savings transaction code', 'req' => true, 'filled' => true, 'type' => 'select', 'options' => self::importCodes(), 'size' => 'sm', 'hint' => 'A deposit code with a GL account. Blank: the code chosen on the page.'],
                'date_opened' => ['label' => 'Date opened', 'req' => true, 'type' => 'date', 'size' => 'sm'],
                'maturity_date' => ['label' => 'Maturity date', 'req' => true, 'type' => 'date', 'size' => 'sm'],
                'product' => ['label' => 'Time deposit product', 'type' => 'select', 'options' => self::savProducts(['time_deposit']), 'more' => true, 'hint' => 'Blank: the product chosen on the page.'],
                'rate' => ['label' => 'Rate % a year', 'type' => 'num', 'more' => true, 'hint' => 'Blank: the rate of the product for the amount and term.'],
                'certificate_no' => ['label' => 'Certificate no.', 'more' => true, 'hint' => 'The certificate no. of the former system. Blank: the next certificate no.'],
                'maturity_action' => ['label' => 'At maturity', 'type' => 'select', 'options' => ['payout' => 'Pay out', 'renew_principal' => 'Renew the principal', 'renew_all' => 'Renew with the interest', 'credit_savings' => 'Credit to savings'], 'more' => true],
                'accrued_interest' => ['label' => 'Accrued interest', 'type' => 'money', 'more' => true, 'hint' => 'The interest already booked as payable at the posting date.'],
            ],
            'deposit', 'withdrawal' => [
                'account_no' => ['label' => 'Account no.', 'req' => true, 'size' => 'md'],
                'amount' => ['label' => 'Amount', 'req' => true, 'type' => 'money', 'size' => 'sm'],
                'memo' => ['label' => 'Memo', 'size' => 'lg', 'hint' => 'Blank: the memo of the batch.'],
            ],
            default => fail('Choose what to import.'),
        };
    }

    /** Header names people use for the columns */
    public static function aliases(string $kind): array
    {
        return [
            'member_code' => $kind === 'members' ? 'member_no' : 'member_code', 'member_number' => $kind === 'members' ? 'member_no' : 'member_code', 'member_id' => $kind === 'members' ? 'member_no' : 'member_code',
            'member_no' => $kind === 'members' ? 'member_no' : 'member_code',
            'lastname' => 'last_name', 'surname' => 'last_name', 'family_name' => 'last_name', 'firstname' => 'first_name', 'given_name' => 'first_name', 'middlename' => 'middle_name',
            'date_of_birth' => 'birth_date', 'birthdate' => 'birth_date', 'dob' => 'birth_date', 'birthday' => 'birth_date', 'sex' => 'gender', 'type' => 'member_type', 'membership_type' => 'member_type',
            'date_of_membership' => 'membership_date', 'member_since' => 'membership_date', 'municipality' => 'city', 'municipality_city' => 'city', 'city_municipality' => 'city', 'town' => 'city',
            'brgy' => 'barangay', 'name' => 'member_name', 'savings_product' => 'savings_code', 'product_code' => $kind === 'time_deposits' ? 'product' : 'savings_code',
            'balance' => 'amount', 'principal' => 'amount', 'transaction_code' => 'savings_txn_code', 'txn_code' => 'savings_txn_code', 'savings_transaction_code' => 'savings_txn_code', 'code' => $kind === 'savings' ? 'savings_code' : 'savings_txn_code',
            'savings_cut_off_date' => 'cut_off_date', 'cutoff_date' => 'cut_off_date', 'cut_off' => 'cut_off_date', 'as_of' => 'cut_off_date',
            'time_deposit_term' => 'td_term', 'term' => 'td_term', 'term_days' => 'td_term', 'opened' => $kind === 'time_deposits' ? 'date_opened' : 'opened_on', 'placement_date' => 'date_opened', 'date_placed' => 'date_opened',
            'maturity' => 'maturity_date', 'account_number' => 'account_no', 'account' => 'account_no', 'acct_no' => 'account_no',
        ];
    }

    /** Example rows of the template */
    public static function samples(string $kind): array
    {
        $type = (string)(array_key_first(self::memberTypes()) ?? '');
        $sav = array_keys(self::savProducts(['ordinary', 'special', 'share_capital']));
        $code = (string)(array_key_first(self::importCodes()) ?? '');
        $td = (string)(array_key_first(self::savProducts(['time_deposit'])) ?? '');
        $acct = (string)(DB::value("SELECT a.account_no FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.status = 'active' AND p.product_type IN ('ordinary','special') ORDER BY a.id LIMIT 1") ?: '01100001011');
        return match ($kind) {
            'members' => [
                ['member_no' => '100201', 'last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => 'Santos', 'birth_date' => '1985-04-12', 'gender' => 'M', 'civil_status' => 'married', 'member_type' => $type,
                    'membership_date' => '2015-06-01', 'street' => '12 Rizal St.', 'barangay' => 'Poblacion', 'city' => 'San Isidro', 'province' => 'Nueva Ecija', 'mobile' => '09171234567',
                    // the full template's details (only in the full template)
                    'salutation' => 'Mr.', 'birth_place' => 'Cabanatuan City', 'nationality' => 'Filipino', 'res_house' => '12', 'res_postal' => '3106', 'education' => 'College', 'course' => 'BS Agriculture',
                    'occupation' => 'Farmer', 'employment_type' => 'self_employed', 'pmes_date' => '2015-05-20', 'subscribed_shares' => '50', 'landline' => '044-940-1234',
                    'spouse_last_name' => 'Dela Cruz', 'spouse_first_name' => 'Ana', 'spouse_middle_name' => 'Reyes', 'spouse_birth_date' => '1987-09-03',
                    'beneficiary1_name' => 'Ana R. Dela Cruz', 'beneficiary1_relationship' => 'Spouse', 'beneficiary1_share' => '60', 'beneficiary2_name' => 'Jose R. Dela Cruz', 'beneficiary2_relationship' => 'Son', 'beneficiary2_share' => '40'],
                ['member_no' => '', 'last_name' => 'Reyes', 'first_name' => 'Maria Luisa', 'middle_name' => '-', 'birth_date' => '03/28/1990', 'gender' => 'F', 'civil_status' => 'single', 'member_type' => $type,
                    'membership_date' => '01/15/2019', 'barangay' => 'San Roque', 'city' => 'Cabanatuan City', 'province' => 'Nueva Ecija'],
            ],
            'savings' => [
                ['member_code' => '100201', 'member_name' => 'Dela Cruz, Juan S.', 'savings_code' => $sav[0] ?? '01', 'amount' => '12500.00', 'savings_txn_code' => $code, 'cut_off_date' => today()],
                ['member_code' => '100201', 'member_name' => 'Dela Cruz, Juan S.', 'savings_code' => $sav[count($sav) - 1] ?? '03', 'amount' => '5000.00', 'savings_txn_code' => $code, 'cut_off_date' => today()],
            ],
            'time_deposits' => [
                ['member_code' => '100201', 'member_name' => 'Dela Cruz, Juan S.', 'td_term' => '180', 'amount' => '50000.00', 'savings_txn_code' => $code, 'date_opened' => Coop::addDays(today(), -60),
                    'maturity_date' => Coop::addDays(Coop::addDays(today(), -60), 180), 'product' => $td],
                ['member_code' => '100201', 'member_name' => 'Dela Cruz, Juan S.', 'td_term' => '1 year', 'amount' => '100000.00', 'savings_txn_code' => $code, 'date_opened' => Coop::addDays(today(), -30),
                    'maturity_date' => Coop::addMonths(Coop::addDays(today(), -30), 12), 'product' => $td],
            ],
            'deposit', 'withdrawal' => [
                ['account_no' => $acct, 'amount' => '500.00', 'memo' => ''],
                ['account_no' => $acct, 'amount' => '250.00', 'memo' => $kind === 'deposit' ? 'Payroll deduction' : 'Payroll release'],
            ],
            default => [],
        };
    }

    /**
     * Every other detail of a member, for the full template of Import Member: the columns a file may add as the user
     * chooses (only those the file has are shown in the review). 'group' heads them on the page; 'max' is the length
     * the member record keeps; 'list' names a list the value must be on (by its name).
     */
    public static function memberExtras(): array
    {
        $x = fn(string $label, string $group, array $more = []) => ['label' => $label, 'more' => true, 'extra' => true, 'group' => $group] + $more;
        $names = fn(array $list) => array_combine(array_values($list), array_values($list)) ?: [];
        $grouping = [];
        foreach (Members::GROUPING_LISTS as $k => $len) {
            $grouping[$k] = $x(ucfirst(Members::LIST_SINGULAR[$k]), 'Membership and grouping',
                ['type' => 'select', 'options' => $names(Members::listNames($k)), 'max' => $len, 'list' => Members::LIST_KEYS[$k], 'hint' => 'A name on ' . Members::LIST_KEYS[$k] . ' (Member Types, Clusters & Other Lists).']);
        }
        return [
            'salutation' => $x('Salutation', 'Personal', ['type' => 'select', 'options' => self::SALUTATIONS, 'max' => 10, 'hint' => 'Mr., Ms., Mrs., Dr., Engr., Atty. or Hon.']),
            'birth_place' => $x('Place of birth', 'Personal', ['max' => 120]),
            'nationality' => $x('Nationality', 'Personal', ['max' => 40, 'hint' => 'Blank: Filipino.']),
            'passbook_no' => $x('Passbook no.', 'Personal', ['max' => 20]),
            'res_house' => $x('House / unit no.', 'Residence address', ['max' => 60]),
            'res_postal' => $x('ZIP code', 'Residence address', ['max' => 10]),
            'prov_house' => $x('Provincial: house no.', 'Provincial address', ['max' => 60]),
            'prov_street' => $x('Provincial: street / barangay', 'Provincial address', ['max' => 120]),
            'prov_city' => $x('Provincial: municipality / city', 'Provincial address', ['max' => 80]),
            'prov_province' => $x('Provincial: province', 'Provincial address', ['max' => 80]),
            'prov_postal' => $x('Provincial: ZIP code', 'Provincial address', ['max' => 10]),
            'off_house' => $x('Office: building / no.', 'Office address', ['max' => 60]),
            'off_street' => $x('Office: street / barangay', 'Office address', ['max' => 120]),
            'off_city' => $x('Office: municipality / city', 'Office address', ['max' => 80]),
            'off_province' => $x('Office: province', 'Office address', ['max' => 80]),
            'off_postal' => $x('Office: ZIP code', 'Office address', ['max' => 10]),
            'education' => $x('Highest education', 'Education and work', ['type' => 'select', 'options' => $names(self::EDUCATION), 'max' => 60]),
            'year_completed' => $x('Year completed', 'Education and work', ['max' => 10]),
            'course' => $x('Course', 'Education and work', ['max' => 120]),
            'occupation' => $x('Occupation', 'Education and work', ['max' => 80]),
            'employment_type' => $x('Employment', 'Education and work', ['type' => 'select', 'options' => Members::EMPLOYMENT, 'hint' => implode(', ', Members::EMPLOYMENT) . '.']),
            'employer_name' => $x('Employer / business name', 'Education and work', ['max' => 120]),
            'employer_contact' => $x('Employer contact', 'Education and work', ['max' => 40]),
            'employer_address' => $x('Employer address', 'Education and work', ['max' => 255]),
            'organization' => $x('Other organizations', 'Education and work', ['max' => 120]),
            'pmes_date' => $x('PMES attended', 'Membership and grouping', ['type' => 'date', 'hint' => 'The date of the Pre-Membership Education Seminar.']),
            'subscribed_shares' => $x('Subscribed shares', 'Membership and grouping', ['type' => 'num', 'hint' => 'A whole number of shares.']),
            'par_value' => $x('Par value per share', 'Membership and grouping', ['type' => 'money', 'hint' => 'Blank: the par value of Cooperative Profile & Policies.']),
            'cluster' => $x('Cluster', 'Membership and grouping', ['type' => 'select', 'options' => $names(Members::clusters()), 'list' => 'Clusters', 'hint' => 'A cluster name (Member Types, Clusters & Other Lists).']),
            'segment' => $x('Segmentation', 'Membership and grouping', ['type' => 'select', 'options' => $names(Members::segments()), 'list' => 'Segmentation']),
            'account_officer' => $x('Account officer', 'Membership and grouping', ['type' => 'select', 'options' => self::officers(), 'list' => 'User Accounts', 'hint' => 'The username or the name of a user.']),
        ] + $grouping + [
            'alert_note' => $x('Alert shown at the counter', 'Contacts and notes', ['max' => 255]),
            'notes' => $x('Notes', 'Contacts and notes', ['max' => 2000]),
            'landline' => $x('Landline', 'Contacts and notes', ['max' => 60]),
            'spouse_last_name' => $x('Spouse: last name', 'Spouse', ['max' => 80]),
            'spouse_first_name' => $x('Spouse: first name', 'Spouse', ['max' => 80]),
            'spouse_middle_name' => $x('Spouse: middle name', 'Spouse', ['max' => 80]),
            'spouse_birth_date' => $x('Spouse: date of birth', 'Spouse', ['type' => 'date']),
            'spouse_contact' => $x('Spouse: contact no.', 'Spouse', ['max' => 40]),
            'beneficiary1_name' => $x('Beneficiary 1: name', 'Beneficiaries', ['max' => 160]),
            'beneficiary1_relationship' => $x('Beneficiary 1: relationship', 'Beneficiaries', ['max' => 40]),
            'beneficiary1_birth_date' => $x('Beneficiary 1: date of birth', 'Beneficiaries', ['type' => 'date']),
            'beneficiary1_contact' => $x('Beneficiary 1: contact no.', 'Beneficiaries', ['max' => 40]),
            'beneficiary1_share' => $x('Beneficiary 1: share %', 'Beneficiaries', ['type' => 'num', 'hint' => '0 to 100; the shares add up to 100 at most.']),
            'beneficiary2_name' => $x('Beneficiary 2: name', 'Beneficiaries', ['max' => 160]),
            'beneficiary2_relationship' => $x('Beneficiary 2: relationship', 'Beneficiaries', ['max' => 40]),
            'beneficiary2_birth_date' => $x('Beneficiary 2: date of birth', 'Beneficiaries', ['type' => 'date']),
            'beneficiary2_contact' => $x('Beneficiary 2: contact no.', 'Beneficiaries', ['max' => 40]),
            'beneficiary2_share' => $x('Beneficiary 2: share %', 'Beneficiaries', ['type' => 'num']),
        ];
    }

    public const SALUTATIONS = ['Mr.' => 'Mr.', 'Ms.' => 'Ms.', 'Mrs.' => 'Mrs.', 'Dr.' => 'Dr.', 'Engr.' => 'Engr.', 'Atty.' => 'Atty.', 'Hon.' => 'Hon.'];
    public const EDUCATION = ['Elementary', 'High school', 'Senior high school', 'Vocational', 'College', 'Post-graduate'];

    /** username => "full name (username)" of the active users, for the account officer */
    public static function officers(): array
    {
        $out = [];
        foreach (DB::all("SELECT username, full_name FROM users WHERE status = 'active' AND COALESCE(is_platform, 0) = 0 ORDER BY full_name") as $u) {
            $out[(string)$u['username']] = $u['full_name'] . ' (' . $u['username'] . ')';
        }
        return $out;
    }

    /** The value of a choice as typed in a file (its key, its label, case and a final period ignored); null when it is none of them */
    public static function choice(string $v, array $options): ?string
    {
        $n = fn(string $x) => rtrim(mb_strtolower(trim($x)), '.');
        $v = $n($v);
        foreach ($options as $k => $label) {
            // "System Administrator" for "System Administrator (admin)"
            if ($v === $n((string)$k) || $v === $n((string)$label) || $v === $n((string)preg_replace('/\s*\([^)]*\)$/', '', (string)$label))) {
                return (string)$k;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ lists

    /** member type code => name */
    public static function memberTypes(): array
    {
        $out = [];
        foreach (Members::types() as $t) {
            if ((int)$t['is_active'] === 1) {
                $out[(string)($t['code'] ?: $t['id'])] = $t['name'];
            }
        }
        return $out;
    }

    /** branch code => name */
    public static function branchCodes(): array
    {
        $out = [];
        foreach (Coop::branches() as $b) {
            if ((int)$b['is_active'] === 1) {
                $out[(string)$b['code']] = $b['name'];
            }
        }
        return $out;
    }

    /** product code => "code name" of the given types */
    public static function savProducts(array $types): array
    {
        [$in, $p] = DB::in('pt', $types);
        return DB::pairs("SELECT code, CONCAT(code, ' ', name) FROM sav_products WHERE is_active = 1 AND product_type IN $in ORDER BY code", $p);
    }

    /** The codes that may enter an imported balance: active deposit codes with a GL account of their own */
    public static function importCodes(): array
    {
        return DB::pairs("SELECT c.code, CONCAT(c.code, ' ', c.description, ' (', a.code, ')') FROM sav_txn_codes c JOIN accounts a ON a.id = c.opposite_account_id
                           WHERE c.is_active = 1 AND c.entry_side = 'credit' AND c.opposite_kind = 'gl' AND c.is_error_correct = 0 AND c.is_check_deposit = 0 AND COALESCE(c.is_memo, 0) = 0
                           ORDER BY c.code");
    }

    /** The codes of a batch: the teller's deposit or withdrawal codes (not a check deposit, an error correction or a memo) */
    public static function batchCodes(string $kind): array
    {
        $out = [];
        foreach (SavTxn::codes('teller') as $code => $c) {
            if ($c['entry_side'] !== ($kind === 'deposit' ? 'credit' : 'debit') || (int)$c['is_check_deposit'] === 1 || (int)$c['is_error_correct'] === 1 || (int)($c['is_memo'] ?? 0) === 1) {
                continue;
            }
            // a code whose GL account is set by a process (interest, transfers ...) is not entered by hand
            if ($c['opposite_kind'] === 'gl' && !$c['opposite_account_id']) {
                continue;
            }
            $out[$code] = $code . ' ' . $c['description'] . ($c['opposite_kind'] === 'gl' ? ($c['opposite_account_id'] ? ' (' . Coop::accountLabel((int)$c['opposite_account_id']) . ')' : '') : ' (your teller cash)');
        }
        return $out;
    }

    /** "180", "180 days", "6 months", "6M", "1 year", "1Y" => [count, unit days|months] or null */
    public static function term(string $v): ?array
    {
        $v = strtolower(trim($v));
        if (!preg_match('/^(\d+)\s*(d|day|days|m|mo|mos|month|months|y|yr|yrs|year|years)?$/', $v, $m) || (int)$m[1] <= 0) {
            return null;
        }
        $u = $m[2] ?? 'd';
        return [(int)$m[1], match ($u[0] ?? 'd') {
            'm' => 'months',
            'y' => 'years',
            default => 'days',
        }];
    }

    /** "180" => "180 days", "6M" => "6 months", "1y" => "1 year" */
    public static function termLabel(string $v): string
    {
        $t = self::term($v);
        if (!$t) {
            return mb_substr(trim($v), 0, 30);
        }
        $unit = $t[1] === 'years' ? 'year' : ($t[1] === 'months' ? 'month' : 'day');
        return $t[0] . ' ' . $unit . ($t[0] === 1 ? '' : 's');
    }

    // ------------------------------------------------------------------ the check

    /**
     * Checks every row; nothing is saved. $opt: kind-wide choices (savings_txn_code, cut_off_date, product,
     * post_date, code, date, memo). Returns [rows with _errors/_notes/_info, summary [rows, bad, total], general errors].
     */
    public static function check(string $kind, array $rows, array $opt): array
    {
        $general = [];
        $cols = self::cols($kind);
        $seen = [];
        $total = '0';
        // defaults of the page fill the blank cells
        foreach ($rows as &$r) {
            foreach (['savings_txn_code' => 'savings_txn_code', 'cut_off_date' => 'cut_off_date', 'product' => 'product'] as $k => $o) {
                if (isset($cols[$k]) && ($r[$k] ?? '') === '' && ($opt[$o] ?? '') !== '') {
                    $r[$k] = (string)$opt[$o];
                }
            }
        }
        unset($r);
        [$types, $branches, $products, $codes] = self::lists();
        if (in_array($kind, ['deposit', 'withdrawal'], true)) {
            $general = array_merge($general, self::checkBatchHeader($kind, $opt, $codes));
        }
        if ($kind === 'time_deposits') {
            if (!valid_date((string)($opt['post_date'] ?? ''))) {
                $general[] = 'Enter the posting date (the cut-off date the balances are entered on).';
            } elseif ($opt['post_date'] > today()) {
                $general[] = 'The posting date cannot be after today.';
            } else {
                try {
                    Coop::checkDate((string)$opt['post_date']);
                } catch (UserError $e) {
                    $general[] = 'Posting date: ' . $e->getMessage();
                }
            }
        }
        $balances = [];   // batch withdrawals: what is left on each account after the rows before
        foreach ($rows as &$r) {
            $err = [];
            $notes = [];
            $info = '';
            $r['_cells'] = [];
            if ($kind === 'members') {
                [$err, $notes, $info] = self::checkMember($r, $types, $branches, $seen);
            } elseif ($kind === 'savings' || $kind === 'time_deposits') {
                $m = self::memberOf($r, $err, $kind === 'savings');
                $code = self::txnCode($r, $codes, $err);
                $amt = CsvReview::amount((string)$r['amount']);
                if ($r['amount'] === '') {
                    $err[] = 'Enter the amount.';
                } elseif ($amt === null) {
                    $err[] = 'The amount "' . $r['amount'] . '" is not a number.';
                } elseif (Dec::isNeg($amt) || ($kind === 'time_deposits' && !Dec::isPos($amt))) {
                    $err[] = $kind === 'time_deposits' ? 'The amount must be more than zero.' : 'The amount cannot be negative.';
                } else {
                    $r['amount'] = Dec::fix($amt);
                }
                if ($kind === 'savings') {
                    $info = self::checkSavings($r, $m, $products, $seen, $err, $notes);
                } else {
                    $info = self::checkTd($r, $m, $products, $opt, $seen, $err, $notes);
                }
                if ($code && !$err) {
                    $info .= ($info !== '' ? '; ' : '') . 'entered with ' . h($code['code']) . ' against ' . h($code['acode'] . ' ' . $code['aname']);
                }
            } else {
                $info = self::checkBatchRow($kind, $r, $opt, $balances, $err, $notes);
            }
            $r['_errors'] = $err;
            $r['_notes'] = $notes;
            $r['_info'] = $info;
            if (!$err && isset($cols['amount'])) {
                $total = Dec::add($total, Dec::of($r['amount']), 2);
            }
        }
        unset($r);
        $bad = count(array_filter($rows, fn($x) => $x['_errors']));
        return [$rows, ['rows' => count($rows), 'bad' => $bad, 'total' => Dec::fix($total)], $general];
    }

    /** The member types, branches, savings products and savings transaction codes, by what a file may write */
    private static function lists(): array
    {
        if (self::$memo && self::$lists) {
            return self::$lists;
        }
        $types = [];
        foreach (Members::types() as $t) {
            $types[mb_strtolower((string)$t['code'])] = $t;
            $types[mb_strtolower((string)$t['name'])] = $t;
        }
        $branches = [];
        foreach (Coop::branches() as $b) {
            $branches[mb_strtolower((string)$b['code'])] = $b;
            $branches[mb_strtolower((string)$b['name'])] = $b;
        }
        $products = [];
        foreach (DB::all('SELECT * FROM sav_products') as $p) {
            $products[mb_strtoupper((string)$p['code'])] = $p;
        }
        $codes = [];
        foreach (DB::all('SELECT c.*, a.code AS acode, a.name AS aname FROM sav_txn_codes c LEFT JOIN accounts a ON a.id = c.opposite_account_id') as $c) {
            $codes[mb_strtoupper((string)$c['code'])] = $c;
        }
        $l = [$types, $branches, $products, $codes];
        self::$lists = self::$memo ? $l : [];
        return $l;
    }

    private static function checkMember(array &$r, array $types, array $branches, array &$seen): array
    {
        $err = [];
        $notes = [];
        foreach (['last_name' => 'last name', 'first_name' => 'first name', 'middle_name' => 'middle name (write - when there is none)', 'barangay' => 'barangay', 'city' => 'municipality / city', 'province' => 'province'] as $k => $w) {
            if (trim((string)$r[$k]) === '') {
                $err[] = 'Enter the ' . $w . '.';
            }
        }
        foreach (['last_name' => 60, 'first_name' => 60, 'middle_name' => 60, 'suffix' => 10, 'street' => 120, 'barangay' => 80, 'city' => 80, 'province' => 80, 'mobile' => 30, 'email' => 120, 'tin' => 20] as $k => $len) {
            if (mb_strlen((string)($r[$k] ?? '')) > $len) {
                $err[] = 'The ' . str_replace('_', ' ', $k) . ' is longer than ' . $len . ' characters.';
            }
        }
        $birth = CsvReview::date((string)$r['birth_date']);
        if ($r['birth_date'] === '') {
            $err[] = 'Enter the date of birth.';
        } elseif (!$birth) {
            $err[] = 'The date of birth "' . $r['birth_date'] . '" is not a date.';
        } elseif ($birth > today() || $birth < date('Y-m-d', strtotime('-120 years'))) {
            $err[] = 'The date of birth ' . fdate($birth) . ' cannot be right.';
        } else {
            $r['birth_date'] = $birth;
        }
        $since = CsvReview::date((string)$r['membership_date']);
        if ($r['membership_date'] === '') {
            $err[] = 'Enter the membership date.';
        } elseif (!$since) {
            $err[] = 'The membership date "' . $r['membership_date'] . '" is not a date.';
        } elseif ($since > today()) {
            $err[] = 'The membership date cannot be after today.';
        } else {
            $r['membership_date'] = $since;
            if ($birth && $since <= $birth) {
                $err[] = 'The membership date is before the date of birth.';
            } elseif ($birth && $since < date('Y-m-d', strtotime($birth . ' +15 years'))) {
                $notes[] = 'The member was under 15 years old on the membership date.';
            }
        }
        $t = $types[mb_strtolower(trim((string)$r['member_type']))] ?? null;
        if (trim((string)$r['member_type']) === '') {
            $err[] = 'Choose the member type.';
        } elseif (!$t) {
            $err[] = 'Member type "' . $r['member_type'] . '" is not on Member Types.';
        } elseif ((int)$t['is_active'] !== 1) {
            $err[] = 'Member type ' . $t['name'] . ' is not active.';
        } else {
            $r['member_type'] = (string)($t['code'] ?: $t['id']);
        }
        if (trim((string)($r['branch'] ?? '')) !== '') {
            $b = $branches[mb_strtolower(trim((string)$r['branch']))] ?? null;
            if (!$b) {
                $err[] = 'Branch "' . $r['branch'] . '" is not set up.';
            } elseif (!Coop::branchAllowed((int)$b['id'])) {
                $err[] = 'You may only file members under your own branch.';
            } else {
                $r['branch'] = (string)$b['code'];
            }
        }
        $g = strtoupper(substr(trim((string)($r['gender'] ?? '')), 0, 1));
        if (trim((string)($r['gender'] ?? '')) !== '') {
            if (!in_array($g, ['M', 'F'], true)) {
                $err[] = 'Sex is M (male) or F (female).';
            } else {
                $r['gender'] = $g;
            }
        }
        $cs = strtolower(trim((string)($r['civil_status'] ?? '')));
        if ($cs !== '') {
            $hit = null;
            foreach (Members::CIVIL as $k => $lbl) {
                if ($cs === $k || $cs === strtolower($lbl) || ($cs !== '' && str_starts_with($k, $cs))) {
                    $hit = $k;
                    break;
                }
            }
            if (!$hit) {
                $err[] = 'Civil status is one of ' . implode(', ', Members::CIVIL) . '.';
            } else {
                $r['civil_status'] = $hit;
            }
        }
        if (($r['email'] ?? '') !== '' && !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
            $err[] = 'The e-mail address "' . $r['email'] . '" is not valid.';
        }
        // the same member twice: the member no., or the same name and date of birth (on file or earlier in the file)
        $no = trim((string)$r['member_no']);
        if ($no !== '') {
            if (mb_strlen($no) > 20) {
                $err[] = 'The member no. is longer than 20 characters.';
            } elseif ($ex = DB::row('SELECT member_no, full_name FROM members WHERE member_no = :n', ['n' => $no])) {
                $err[] = 'Member no. ' . $no . ' is already on file (' . $ex['full_name'] . ').';
            } elseif (isset($seen['no:' . mb_strtolower($no)])) {
                $err[] = 'Member no. ' . $no . ' is also on line ' . $seen['no:' . mb_strtolower($no)] . ' of the file.';
            }
            $seen['no:' . mb_strtolower($no)] ??= (int)$r['_line'];
        }
        $full = Members::fullName(['last_name' => $r['last_name'], 'first_name' => $r['first_name'], 'middle_name' => self::middle((string)$r['middle_name']), 'suffix' => $r['suffix'] ?? '']);
        if (trim((string)$r['last_name']) !== '' && trim((string)$r['first_name']) !== '' && $birth) {
            $dup = DB::value('SELECT member_no FROM members WHERE full_name = :n AND birth_date = :b', ['n' => $full, 'b' => $birth]);
            $key = 'nb:' . mb_strtolower($full) . '|' . $birth;
            if ($dup) {
                $err[] = $full . ', born ' . fdate($birth) . ', is already a member: ' . $dup . '.';
            } elseif (isset($seen[$key])) {
                $err[] = 'The same person (name and date of birth) is also on line ' . $seen[$key] . ' of the file.';
            }
            $seen[$key] ??= (int)$r['_line'];
        }
        $extra = self::checkMemberExtras($r, $err);
        $info = $err ? '' : 'Becomes <strong>' . h($full) . '</strong>, ' . ($no !== '' ? 'member no. ' . h($no) : 'the next member no.') . ', Active' . ($extra ? ', with ' . h($extra) : '');
        return [$err, $notes, $info];
    }

    /** The other details of a member row (the full template); returns what they add, in words, for the row's line */
    private static function checkMemberExtras(array &$r, array &$err): string
    {
        $adds = [];
        foreach (self::memberExtras() as $k => $c) {
            $v = trim((string)($r[$k] ?? ''));
            if ($v === '') {
                continue;
            }
            $type = $c['type'] ?? 'text';
            if ($type === 'date') {
                $d = CsvReview::date($v);
                if (!$d || $d > today()) {
                    $err[] = $c['label'] . ': "' . $v . '" is not a date' . ($d ? ' before today' : '') . '.';
                } else {
                    $r[$k] = $d;
                }
            } elseif ($type === 'select') {
                $hit = self::choice($v, $c['options']);
                if ($hit === null) {
                    $err[] = $c['label'] . ': "' . $v . '" is not ' . (!empty($c['list']) ? 'on the list ' . $c['list'] : (count($c['options']) <= 8 && $c['options'] ? 'one of ' . implode(', ', $c['options']) : 'on file')) . '.';
                } else {
                    $r[$k] = $hit;
                }
            } elseif ($type === 'num' || $type === 'money') {
                $n = CsvReview::amount($v);
                if ($n === null || Dec::isNeg($n)) {
                    $err[] = $c['label'] . ': "' . $v . '" is not a number.';
                } elseif ($k === 'subscribed_shares' && Dec::cmp($n, (string)floor((float)$n)) !== 0) {
                    $err[] = 'Subscribed shares is a whole number.';
                } elseif (str_ends_with($k, '_share') && Dec::cmp($n, '100') > 0) {
                    $err[] = $c['label'] . ' is at most 100.';
                } elseif ($k === 'par_value' && !Dec::isPos($n)) {
                    $err[] = 'The par value per share must be more than zero.';
                } else {
                    $r[$k] = $k === 'subscribed_shares' ? (string)(int)$n : Dec::fix($n);
                }
            }
            if (!empty($c['max']) && mb_strlen((string)$r[$k]) > $c['max']) {
                $err[] = $c['label'] . ' is longer than ' . $c['max'] . ' characters.';
            }
            $adds[$c['group']] = true;
        }
        $shares = '0';
        foreach ([1, 2] as $i) {
            $named = trim((string)($r['beneficiary' . $i . '_name'] ?? '')) !== '';
            foreach (['relationship', 'birth_date', 'contact', 'share'] as $f) {
                if (!$named && trim((string)($r['beneficiary' . $i . '_' . $f] ?? '')) !== '') {
                    $err[] = 'Beneficiary ' . $i . ': enter the name.';
                    $r['_cells'][count($err) - 1] = ['beneficiary' . $i . '_name'];
                    break;
                }
            }
            if ($named && ($r['beneficiary' . $i . '_share'] ?? '') !== '' && CsvReview::amount((string)$r['beneficiary' . $i . '_share']) !== null) {
                $shares = Dec::add($shares, (string)CsvReview::amount((string)$r['beneficiary' . $i . '_share']), 2);
            }
        }
        if (Dec::cmp($shares, '100') > 0) {
            $err[] = 'The shares of the beneficiaries add up to ' . rtrim(rtrim(Dec::fix($shares), '0'), '.') . ' %: at most 100 %.';
            $r['_cells'][count($err) - 1] = ['beneficiary1_share', 'beneficiary2_share'];
        }
        $spouse = array_filter(['last_name', 'first_name', 'middle_name', 'birth_date', 'contact'], fn($f) => trim((string)($r['spouse_' . $f] ?? '')) !== '');
        if ($spouse && (trim((string)($r['spouse_last_name'] ?? '')) === '' || trim((string)($r['spouse_first_name'] ?? '')) === '')) {
            $err[] = 'Spouse: enter the last name and the first name.';
            $r['_cells'][count($err) - 1] = ['spouse_last_name', 'spouse_first_name'];
        }
        $groups = array_keys($adds);
        return $groups ? 'details on ' . mb_strtolower(implode('; ', $groups)) : '';
    }

    /** "-" or "N/A" in the middle name: none */
    private static function middle(string $v): string
    {
        $v = trim($v);
        return in_array(mb_strtolower($v), ['-', '--', 'n/a', 'na', 'none', '.'], true) ? '' : $v;
    }

    private static function memberOf(array $r, array &$err, bool $allowApplicant = true): ?array
    {
        $no = trim((string)$r['member_code']);
        if ($no === '') {
            $err[] = 'Enter the member code.';
            return null;
        }
        $m = self::$memberResolver ? (self::$memberResolver)($no) : DB::row('SELECT * FROM members WHERE member_no = :n', ['n' => $no]);
        if (!$m) {
            $err[] = 'Member code ' . $no . ' is not on file (import the members first).';
            return null;
        }
        if (!Coop::branchAllowed($m['branch_id'] ? (int)$m['branch_id'] : null)) {
            $err[] = 'Member ' . $no . ' belongs to another branch.';
            return null;
        }
        if (trim((string)$r['member_name']) === '') {
            $err[] = 'Enter the member name.';
        } elseif (!CsvReview::nameMatches((string)$r['member_name'], (string)$m['last_name'], (string)$m['first_name'], (string)$m['full_name'])) {
            $err[] = 'The name "' . $r['member_name'] . '" is not the name of member ' . $no . ' on file (' . $m['full_name'] . ').';
        }
        if (!in_array($m['status'], $allowApplicant ? ['active', 'applicant'] : ['active'], true)) {
            $err[] = 'Member ' . $no . ' is ' . (Coop::MEMBER_STATUSES[$m['status']] ?? $m['status']) . ($allowApplicant ? '.' : ': a time deposit is placed for an active member.');
        } elseif ((int)$m['hold_flag'] === 1) {
            $err[] = 'Member ' . $no . ' is on hold: ' . $m['hold_reason'];
        }
        return $m;
    }

    private static function txnCode(array &$r, array $codes, array &$err): ?array
    {
        $v = mb_strtoupper(trim((string)$r['savings_txn_code']));
        if ($v === '') {
            $err[] = 'Choose the savings transaction code (or choose one on the page for every row).';
            return null;
        }
        $c = $codes[$v] ?? null;
        if (!$c) {
            $err[] = 'Savings transaction code ' . $v . ' is not on Savings Transaction Codes.';
            return null;
        }
        $r['savings_txn_code'] = $c['code'];
        if ((int)$c['is_active'] !== 1) {
            $err[] = 'Code ' . $c['code'] . ' is not active.';
        } elseif ($c['entry_side'] !== 'credit' || (int)$c['is_error_correct'] === 1 || (int)$c['is_check_deposit'] === 1 || (int)($c['is_memo'] ?? 0) === 1) {
            $err[] = 'Code ' . $c['code'] . ' is not a plain deposit (credit) code.';
        } elseif ($c['opposite_kind'] !== 'gl' || !$c['opposite_account_id']) {
            $err[] = 'Code ' . $c['code'] . ' has no GL account of its own (it takes the teller\'s cash): choose a code with a GL account, such as an opening balance code.';
        } else {
            return $c;
        }
        return null;
    }

    private static function checkSavings(array &$r, ?array $m, array $products, array &$seen, array &$err, array &$notes): string
    {
        $p = $products[mb_strtoupper(trim((string)$r['savings_code']))] ?? null;
        if (trim((string)$r['savings_code']) === '') {
            $err[] = 'Choose the savings code (the product).';
        } elseif (!$p) {
            $err[] = 'Savings code ' . $r['savings_code'] . ' is not a savings product.';
        } elseif ($p['product_type'] === 'time_deposit') {
            $err[] = 'Product ' . $p['code'] . ' is a time deposit: import it on the Time Deposit Center.';
            $p = null;
        } elseif ((int)$p['is_active'] !== 1) {
            $err[] = 'Product ' . $p['code'] . ' ' . $p['name'] . ' is not active.';
        } else {
            $r['savings_code'] = $p['code'];
        }
        $cut = CsvReview::date((string)$r['cut_off_date']);
        if ($r['cut_off_date'] === '') {
            $err[] = 'Enter the savings cut-off date (or choose it on the page for every row).';
        } elseif (!$cut) {
            $err[] = 'The cut-off date "' . $r['cut_off_date'] . '" is not a date.';
        } elseif ($cut > today()) {
            $err[] = 'The cut-off date cannot be after today.';
        } elseif (($closing = Settings::closingDate()) && $cut <= $closing && !Access::special('closed_period_changes')) {
            $err[] = 'The books are closed through ' . fdate($closing) . ': the cut-off date must be after it.';
        } else {
            $r['cut_off_date'] = $cut;
        }
        $opened = null;
        if (trim((string)$r['opened_on']) !== '') {
            $opened = CsvReview::date((string)$r['opened_on']);
            if (!$opened) {
                $err[] = 'The opening date "' . $r['opened_on'] . '" is not a date.';
            } elseif ($cut && $opened > $cut) {
                $err[] = 'The account was opened after the cut-off date.';
            } else {
                $r['opened_on'] = $opened;
            }
        }
        if ($m && $p && $p['eligible_types'] && !in_array((string)(int)$m['member_type_id'], array_map('trim', explode(',', $p['eligible_types'])), true)) {
            $err[] = 'Product ' . $p['name'] . ' is not open to the member type of ' . $m['member_no'] . '.';
        }
        $no = trim((string)$r['account_no']);
        if ($no !== '') {
            if (mb_strlen($no) > 30) {
                $err[] = 'The account no. is longer than 30 characters.';
            } elseif (DB::value('SELECT id FROM sav_accounts WHERE account_no = :a', ['a' => $no])) {
                $err[] = 'Account no. ' . $no . ' is already on file.';
            } elseif (isset($seen['acct:' . $no])) {
                $err[] = 'Account no. ' . $no . ' is also on line ' . $seen['acct:' . $no] . ' of the file.';
            }
            $seen['acct:' . $no] ??= (int)$r['_line'];
        }
        $empty = null;
        if ($m && $p) {
            $key = 'mp:' . $m['id'] . ':' . $p['id'];
            if ($no === '' && isset($seen[$key])) {
                $err[] = 'Member ' . $m['member_no'] . ' has a ' . $p['name'] . ' row on line ' . $seen[$key] . ' already (give each its account no. to import two accounts).';
            }
            $seen[$key] ??= (int)$r['_line'];
            if ($no === '') {
                $withTxns = DB::value("SELECT a.account_no FROM sav_accounts a WHERE a.member_id = :m AND a.product_id = :p AND a.status <> 'closed' AND EXISTS (SELECT 1 FROM sav_txns t WHERE t.account_id = a.id)", ['m' => $m['id'], 'p' => $p['id']]);
                if ($withTxns) {
                    $err[] = 'Member ' . $m['member_no'] . ' already has ' . $p['name'] . ' account ' . $withTxns . ' with transactions.';
                }
                $empty = DB::value("SELECT a.account_no FROM sav_accounts a WHERE a.member_id = :m AND a.product_id = :p AND a.status <> 'closed' AND NOT EXISTS (SELECT 1 FROM sav_txns t WHERE t.account_id = a.id) ORDER BY a.id LIMIT 1", ['m' => $m['id'], 'p' => $p['id']]);
            }
        }
        if ($p && !$err && $r['amount'] !== '' && Dec::isPos($p['max_balance']) && Dec::cmp($r['amount'], $p['max_balance']) > 0) {
            $err[] = 'The maximum balance of ' . $p['name'] . ' is ' . Dec::money($p['max_balance']) . '.';
        }
        if ($r['amount'] !== '' && !$err && Dec::isZero($r['amount'])) {
            $notes[] = 'No balance: the account is opened without an entry.';
        }
        if (!$m || !$p) {
            return '';
        }
        return h($m['full_name']) . ' (' . h($m['member_no']) . '): ' . h($p['name']) . ', ' . ($empty ? 'account ' . h($empty) . ' (opened, still empty)' : ($no !== '' ? 'account ' . h($no) : 'a new account')) . ', Active';
    }

    private static function checkTd(array &$r, ?array $m, array $products, array $opt, array &$seen, array &$err, array &$notes): string
    {
        $p = $products[mb_strtoupper(trim((string)$r['product']))] ?? null;
        if (trim((string)$r['product']) === '') {
            $tds = array_filter($products, fn($x) => $x['product_type'] === 'time_deposit' && (int)$x['is_active'] === 1);
            if (count($tds) === 1) {
                $p = reset($tds);
                $r['product'] = $p['code'];
            } else {
                $err[] = 'Choose the time deposit product (or choose it on the page for every row).';
            }
        } elseif (!$p || $p['product_type'] !== 'time_deposit') {
            $err[] = 'Product ' . $r['product'] . ' is not a time deposit product.';
            $p = null;
        } else {
            $r['product'] = $p['code'];
        }
        $opened = CsvReview::date((string)$r['date_opened']);
        if ($r['date_opened'] === '') {
            $err[] = 'Enter the date opened.';
        } elseif (!$opened) {
            $err[] = 'The date opened "' . $r['date_opened'] . '" is not a date.';
        } elseif (valid_date((string)($opt['post_date'] ?? '')) && $opened > $opt['post_date']) {
            $err[] = 'The time deposit was opened after the posting date ' . fdate($opt['post_date']) . '.';
        } else {
            $r['date_opened'] = $opened;
        }
        $mat = CsvReview::date((string)$r['maturity_date']);
        if ($r['maturity_date'] === '') {
            $err[] = 'Enter the maturity date.';
        } elseif (!$mat) {
            $err[] = 'The maturity date "' . $r['maturity_date'] . '" is not a date.';
        } elseif ($opened && $mat <= $opened) {
            $err[] = 'The maturity date is not after the date opened.';
        } else {
            $r['maturity_date'] = $mat;
        }
        $term = self::term((string)$r['td_term']);
        if (trim((string)$r['td_term']) === '') {
            $err[] = 'Enter the time deposit term.';
        } elseif (!$term) {
            $err[] = 'The term "' . $r['td_term'] . '" is not a term: write days (180), months (6 months) or years (1 year).';
        } elseif ($opened && $mat && $mat > $opened) {
            $expected = match ($term[1]) {
                'months' => Coop::addMonths($opened, $term[0]),
                'years' => Coop::addMonths($opened, 12 * $term[0]),
                default => Coop::addDays($opened, $term[0]),
            };
            if ($expected !== $mat) {
                $notes[] = 'A term of ' . trim($r['td_term']) . ' from ' . fdate($opened) . ' ends on ' . fdate($expected) . '; the maturity date given (' . fdate($mat) . ') is kept: ' . Coop::daysBetween($opened, $mat) . ' days.';
            }
        }
        $days = $opened && $mat && $mat > $opened ? Coop::daysBetween($opened, $mat) : 0;
        if ($mat && $mat < today() && !$err) {
            $notes[] = 'Matured on ' . fdate($mat) . ': it is settled on the Time Deposit Center after the import.';
        }
        $rate = null;
        if (trim((string)$r['rate']) !== '') {
            $rv = trim(str_replace(['%', ',', ' '], '', (string)$r['rate']));
            $rate = preg_match('/^\d+(\.\d+)?$/', $rv) ? Dec::round($rv, 4) : null;
            if ($rate === null || Dec::cmp($rate, '100') > 0) {
                $err[] = 'The rate "' . $r['rate'] . '" is not a rate in % a year.';
                $rate = null;
            }
        } elseif ($p && $days && $opened && ($amt = CsvReview::amount((string)$r['amount'])) !== null && Dec::isPos($amt)) {
            $tier = Savings::rateFor((int)$p['id'], $amt, $opened, $days);
            if (!$tier) {
                $err[] = 'No interest rate is set up for ' . $p['name'] . ' at ' . Dec::money($amt) . ' for ' . $days . ' days: enter the rate.';
            } else {
                $rate = (string)$tier['rate'];
            }
        }
        if ($p && !$err) {
            if (Dec::isPos($p['opening_amount']) && Dec::cmp($r['amount'], $p['opening_amount']) < 0) {
                $err[] = 'The minimum placement for ' . $p['name'] . ' is ' . Dec::money($p['opening_amount']) . '.';
            }
            if (Dec::isPos($p['max_balance']) && Dec::cmp($r['amount'], $p['max_balance']) > 0) {
                $err[] = 'The maximum placement for ' . $p['name'] . ' is ' . Dec::money($p['max_balance']) . '.';
            }
            if ($m && $p['eligible_types'] && !in_array((string)(int)$m['member_type_id'], array_map('trim', explode(',', $p['eligible_types'])), true)) {
                $err[] = 'Product ' . $p['name'] . ' is not open to the member type of ' . $m['member_no'] . '.';
            }
        }
        $cert = trim((string)$r['certificate_no']);
        if ($cert !== '') {
            if (mb_strlen($cert) > 30) {
                $err[] = 'The certificate no. is longer than 30 characters.';
            } elseif (DB::value('SELECT id FROM td_placements WHERE certificate_no = :c', ['c' => $cert])) {
                $err[] = 'Certificate no. ' . $cert . ' is already on file.';
            } elseif (isset($seen['cert:' . $cert])) {
                $err[] = 'Certificate no. ' . $cert . ' is also on line ' . $seen['cert:' . $cert] . ' of the file.';
            }
            $seen['cert:' . $cert] ??= (int)$r['_line'];
        }
        if (trim((string)$r['maturity_action']) !== '' && !in_array($r['maturity_action'], ['payout', 'renew_principal', 'renew_all', 'credit_savings'], true)) {
            $err[] = 'At maturity is payout, renew_principal, renew_all or credit_savings.';
        }
        if (trim((string)$r['accrued_interest']) !== '') {
            $acc = CsvReview::amount((string)$r['accrued_interest']);
            if ($acc === null || Dec::isNeg($acc)) {
                $err[] = 'The accrued interest "' . $r['accrued_interest'] . '" is not an amount.';
            } else {
                $r['accrued_interest'] = Dec::fix($acc);
            }
        }
        if (!$err && $opened && valid_date((string)($opt['post_date'] ?? '')) && $opened < $opt['post_date'] && trim((string)$r['accrued_interest']) === '') {
            $notes[] = 'No accrued interest given: the interest earned from ' . fdate($opened) . ' to ' . fdate($opt['post_date']) . ' is booked by the next accrual run.';
        }
        $r['_days'] = $days;
        $r['_rate'] = $rate;
        if (!$m || !$p || $err) {
            return '';
        }
        $calc = TimeDeposits::compute(Dec::of($r['amount']), $rate, $days, $p['interest_method'] ?? 'simple', 'maturity', (int)$p['calendar_days'] ?: 365);
        return h($m['full_name']) . ' (' . h($m['member_no']) . '): ' . h($p['name']) . ', ' . $days . ' days at ' . h(rtrim(rtrim(Dec::fix($rate, 4), '0'), '.') ?: '0') . ' %, maturity value ' . Dec::money($calc['maturity_value']) . ', Active';
    }

    private static function checkBatchHeader(string $kind, array $opt, array $codes): array
    {
        $g = [];
        $c = $codes[mb_strtoupper(trim((string)($opt['code'] ?? '')))] ?? null;
        if (!$c || !isset(self::batchCodes($kind)[$c['code']])) {
            $g[] = 'Choose the ' . ($kind === 'deposit' ? 'deposit' : 'withdrawal') . ' transaction code of the batch.';
        } elseif ($c['opposite_kind'] !== 'gl' && !Teller::openShift()) {
            $g[] = 'Code ' . $c['code'] . ' takes your teller cash: open your teller shift first (Teller > Teller Shift & Cash Count).';
        }
        $d = (string)($opt['date'] ?? '');
        if (!valid_date($d)) {
            $g[] = 'Enter the date of the batch.';
        } elseif ($d > today()) {
            $g[] = 'The date cannot be after today.';
        } else {
            try {
                Coop::checkDate($d);
            } catch (UserError $e) {
                $g[] = $e->getMessage();
            }
        }
        return $g;
    }

    private static function checkBatchRow(string $kind, array &$r, array $opt, array &$balances, array &$err, array &$notes): string
    {
        $no = trim((string)$r['account_no']);
        $a = $no !== '' ? Savings::byNo($no) : null;
        if ($no === '') {
            $err[] = 'Enter the account no.';
        } elseif (!$a) {
            $err[] = 'Account no. ' . $no . ' is not on file.';
        } elseif ($a['product_type'] === 'time_deposit') {
            $err[] = 'Account ' . $no . ' is a time deposit (its transactions are made on the Time Deposit Center).';
            $a = null;
        } elseif (!Coop::branchAllowed($a['branch_id'] ? (int)$a['branch_id'] : null)) {
            $err[] = 'Account ' . $no . ' belongs to another branch.';
            $a = null;
        } elseif ($a['status'] === 'closed') {
            $err[] = 'Account ' . $no . ' is closed.';
        }
        $amt = CsvReview::amount((string)$r['amount']);
        if ($r['amount'] === '') {
            $err[] = 'Enter the amount.';
        } elseif ($amt === null) {
            $err[] = 'The amount "' . $r['amount'] . '" is not a number.';
        } elseif (!Dec::isPos($amt)) {
            $err[] = 'The amount must be more than zero.';
        } else {
            $r['amount'] = Dec::fix($amt);
        }
        if (mb_strlen((string)$r['memo']) > 160) {
            $err[] = 'The memo is longer than 160 characters.';
        }
        if (!$a) {
            return '';
        }
        $m = Coop::member((int)$a['member_id']);
        // the balance left by the rows above on the same account
        $a['balance'] = $balances[(int)$a['id']] ?? $a['balance'];
        $info = h($m['full_name'] ?? '') . ' (' . h($m['member_no'] ?? '') . '), ' . h($a['product_name']) . ', balance ' . Dec::money($a['balance']);
        $floor = Dec::of($a['min_balance']);
        if ($kind === 'withdrawal' && $a['status'] !== 'closed') {
            $left = Dec::sub(Savings::available($a), $floor, 2);
            $info .= ', withdrawable ' . Dec::money(Dec::isNeg($left) ? '0' : $left) . (Dec::isPos($floor) ? ' (' . Dec::money($floor) . ' stays as the minimum balance)' : '');
        }
        if ($amt !== null && Dec::isPos($amt) && $a['status'] !== 'closed') {
            // the rules the posting applies (member status and hold, restriction, minimum and maximum, lock-in ...)
            try {
                Savings::checkMove($a, $kind === 'deposit' ? 'credit' : 'debit', $amt, (string)(($opt['date'] ?? '') ?: today()), $kind === 'deposit' ? ['allow_pending' => true] : []);
                $balances[(int)$a['id']] = $kind === 'deposit' ? Dec::add($a['balance'], $amt, 2) : Dec::sub($a['balance'], $amt, 2);
            } catch (UserError $e) {
                $err[] = $e->getMessage();
            }
        }
        if ($kind === 'withdrawal' && $a['status'] !== 'closed') {
            if ($a['status'] === 'dormant' || (int)($a['withdrawal_approval'] ?? 0) === 1 || $a['product_type'] === 'share_capital') {
                $notes[] = 'Needs a supervisor\'s approval (' . ($a['status'] === 'dormant' ? 'dormant account' : ($a['product_type'] === 'share_capital' ? 'share capital' : 'the product requires it')) . ').';
            }
        } elseif ($kind === 'deposit' && $a['status'] === 'pending') {
            $notes[] = 'The account still waits for approval: the deposit is accepted.';
        }
        return $info;
    }

    // ------------------------------------------------------------------ the upload

    /**
     * Checks the rows again and saves them all (or none). Returns ['count', 'total', 'refs' => what was made].
     * $fileName is kept in the import log.
     */
    public static function post(string $kind, array $rows, array $opt, string $fileName, string $pageKey): array
    {
        return DB::tx(function () use ($kind, $rows, $opt, $fileName, $pageKey) {
            [$rows, $sum, $general] = self::check($kind, $rows, $opt);
            if ($general) {
                fail(implode(' ', $general));
            }
            if (!$rows) {
                fail('There is no row to upload.');
            }
            if ($sum['bad']) {
                fail($sum['bad'] . ' row(s) have a problem: correct them or remove them, then upload.');
            }
            $refs = match ($kind) {
                'members' => self::postMembers($rows, $pageKey),
                'savings' => self::postSavings($rows, $fileName, $pageKey),
                'time_deposits' => self::postTd($rows, $opt, $fileName, $pageKey),
                default => self::postBatch($kind, $rows, $opt, $pageKey),
            };
            if (in_array($kind, ['members', 'deposit', 'withdrawal'], true)) {
                DB::insert('coop_imports', ['kind' => $kind === 'members' ? 'members' : ($kind === 'deposit' ? 'batch_deposit' : 'batch_withdrawal'), 'as_of' => $kind === 'members' ? today() : (string)$opt['date'],
                    'rows_count' => count($rows), 'total' => Dec::fix($sum['total']), 'file_name' => mb_substr($fileName, 0, 190) ?: null,
                    'summary_json' => json_encode(['source' => 'csv_review', 'refs' => array_slice($refs, 0, 500)]), 'created_by' => Auth::id()]);
            }
            Audit::log('import', 'coop', 'csv_' . $kind, null, self::KINDS[$kind][0] . ': ' . count($rows) . ' row(s)', ['page_key' => $pageKey, 'amount' => (float)$sum['total'], 'reason' => mb_substr($fileName, 0, 190)]);
            return ['count' => count($rows), 'total' => $sum['total'], 'refs' => $refs];
        });
    }

    private static function postMembers(array $rows, string $pageKey): array
    {
        $types = [];
        foreach (Members::types() as $t) {
            $types[(string)($t['code'] ?: $t['id'])] = (int)$t['id'];
        }
        $branches = [];
        foreach (Coop::branches() as $b) {
            $branches[(string)$b['code']] = (int)$b['id'];
        }
        $out = [];
        // the members with their former number first, so a number drawn for a row without one never takes a number of the file
        $order = array_keys($rows);
        usort($order, fn($a, $b) => (trim((string)$rows[$a]['member_no']) === '') <=> (trim((string)$rows[$b]['member_no']) === '') ?: $a <=> $b);
        foreach ($order as $i) {
            $r = $rows[$i];
            $no = trim((string)$r['member_no']);
            if ($no === '') {
                for ($try = 0; $try < 1000; $try++) {
                    $no = Coop::nextMemberNo();
                    if (!DB::value('SELECT id FROM members WHERE member_no = :n', ['n' => $no])) {
                        break;
                    }
                }
            }
            $x = self::memberExtraData($r);
            $id = Members::save($x['member'] + [
                'member_no' => $no, 'member_kind' => 'individual', 'last_name' => trim((string)$r['last_name']), 'first_name' => trim((string)$r['first_name']),
                'middle_name' => self::middle((string)$r['middle_name']) ?: null, 'suffix' => trim((string)$r['suffix']) ?: null, 'gender' => $r['gender'] ?: null, 'birth_date' => $r['birth_date'],
                'civil_status' => $r['civil_status'] ?: null, 'member_type_id' => $types[$r['member_type']] ?? null,
                'branch_id' => ($r['branch'] ?? '') !== '' ? ($branches[$r['branch']] ?? null) : Coop::defaultBranchId(), 'membership_date' => $r['membership_date'],
                'res_street' => trim((string)$r['street']) ?: null, 'res_barangay' => trim((string)$r['barangay']), 'res_city' => trim((string)$r['city']), 'res_province' => trim((string)$r['province']),
                'mobile' => trim((string)$r['mobile']) ?: null, 'email' => trim((string)$r['email']) ?: null, 'tin' => trim((string)$r['tin']) ?: null,
                'par_value' => Dec::fix(Dec::of(Coop::setting('default_par_value', '100'))), 'status' => 'active',
            ] + (!empty($r['_link_party_id']) ? ['link_party_id' => (int)$r['_link_party_id']] : []), $x['tabs'], null, $pageKey);
            $out[$i] = (string)DB::value('SELECT member_no FROM members WHERE id = :id', ['id' => $id]);
        }
        ksort($out);
        return array_values($out);
    }

    /** The other details of a checked member row: ['member' => columns of the member record, 'tabs' => the lists of Member Information] */
    private static function memberExtraData(array $r): array
    {
        $v = fn(string $k) => trim((string)($r[$k] ?? ''));
        $d = [];
        foreach (['salutation', 'birth_place', 'nationality', 'passbook_no', 'res_house', 'res_postal', 'prov_house', 'prov_street', 'prov_city', 'prov_province', 'prov_postal',
                  'off_house', 'off_street', 'off_city', 'off_province', 'off_postal', 'education', 'year_completed', 'course', 'occupation', 'employment_type', 'employer_name',
                  'employer_contact', 'employer_address', 'organization', 'pmes_date', 'alert_note', 'notes', ...array_keys(Members::GROUPING_LISTS)] as $k) {
            if ($v($k) !== '') {
                $d[$k] = $v($k);
            }
        }
        if ($v('nationality') === '') {
            $d['nationality'] = 'Filipino';
        }
        if ($v('subscribed_shares') !== '') {
            $d['subscribed_shares'] = (int)$v('subscribed_shares');
        }
        if ($v('par_value') !== '') {
            $d['par_value'] = Dec::fix(Dec::of($v('par_value')));
        }
        if ($v('cluster') !== '') {
            $d['cluster_id'] = (int)array_search($v('cluster'), Members::clusters(), true) ?: null;
        }
        if ($v('segment') !== '') {
            $d['segment_id'] = (int)array_search($v('segment'), Members::segments(), true) ?: null;
        }
        if ($v('account_officer') !== '') {
            $d['account_officer_id'] = (int)DB::value('SELECT id FROM users WHERE username = :u', ['u' => $v('account_officer')]) ?: null;
        }
        $tabs = [];
        if ($v('spouse_last_name') !== '' || $v('spouse_first_name') !== '') {
            $tabs['dependents'] = [['kind' => 'spouse', 'last_name' => $v('spouse_last_name'), 'first_name' => $v('spouse_first_name'), 'middle_name' => self::middle($v('spouse_middle_name')),
                'relationship' => 'Spouse', 'birth_date' => $v('spouse_birth_date') ?: null, 'contact_no' => $v('spouse_contact'), 'is_dependent' => 0]];
        }
        foreach ([1, 2] as $i) {
            if ($v('beneficiary' . $i . '_name') !== '') {
                $tabs['beneficiaries'][] = ['name' => $v('beneficiary' . $i . '_name'), 'relationship' => $v('beneficiary' . $i . '_relationship'), 'birth_date' => $v('beneficiary' . $i . '_birth_date') ?: null,
                    'contact_no' => $v('beneficiary' . $i . '_contact'), 'address' => '', 'share_pct' => $v('beneficiary' . $i . '_share') ?: '0', 'is_primary' => $i === 1 ? 1 : 0];
            }
        }
        if ($v('landline') !== '') {
            $tabs['contacts'] = [['contact_type' => 'landline', 'country_code' => '63', 'area_code' => '', 'number' => $v('landline'), 'is_primary' => 0]];
            if ($v('mobile') !== '') {
                array_unshift($tabs['contacts'], ['contact_type' => 'mobile', 'country_code' => '63', 'area_code' => '', 'number' => $v('mobile'), 'is_primary' => 1]);
            }
        }
        return ['member' => $d, 'tabs' => $tabs];
    }

    private static function postSavings(array $rows, string $fileName, string $pageKey): array
    {
        $products = [];
        foreach (DB::all('SELECT * FROM sav_products') as $p) {
            $products[$p['code']] = $p;
        }
        // one journal voucher per cut-off date and code: the member accounts credited, the code's GL account debited
        $groups = [];
        foreach ($rows as $i => $r) {
            $groups[$r['cut_off_date'] . '|' . $r['savings_txn_code']][$i] = $r;
        }
        $out = [];
        foreach ($groups as $key => $grp) {
            [$date, $codeKey] = explode('|', $key);
            $c = Savings::code($codeKey);
            $gl = [];
            $sum = '0';
            foreach ($grp as $r) {
                if (Dec::isPos($r['amount'])) {
                    $m = DB::row('SELECT id, member_no FROM members WHERE member_no = :n', ['n' => $r['member_code']]);
                    $gl[] = ['account_id' => Savings::liabilityAccount($products[$r['savings_code']]), 'credit' => $r['amount'], 'memo' => $c['code'] . ' ' . $r['savings_code'] . ' ' . $m['member_no'], 'member_id' => (int)$m['id']];
                    $sum = Dec::add($sum, $r['amount'], 2);
                }
            }
            $txnId = null;
            if (Dec::isPos($sum)) {
                $gl[] = ['account_id' => (int)$c['opposite_account_id'], 'debit' => $sum, 'memo' => $c['code'] . ' ' . $c['description']];
                $txnId = CoopLedger::post('coop_jv', ['txn_date' => $date, 'memo' => 'Savings import as of ' . fdate($date) . ' (' . $c['code'] . ' ' . $c['description'] . ')', 'reason' => 'Import of savings balances'], $gl);
            }
            foreach ($grp as $i => $r) {
                $m = DB::row('SELECT * FROM members WHERE member_no = :n', ['n' => $r['member_code']]);
                $p = $products[$r['savings_code']];
                $opened = $r['opened_on'] !== '' ? $r['opened_on'] : $date;
                $id = trim((string)$r['account_no']) === ''
                    ? DB::value("SELECT a.id FROM sav_accounts a WHERE a.member_id = :m AND a.product_id = :p AND a.status <> 'closed' AND NOT EXISTS (SELECT 1 FROM sav_txns t WHERE t.account_id = a.id) ORDER BY a.id LIMIT 1", ['m' => $m['id'], 'p' => $p['id']])
                    : null;
                $id = $id ? (int)$id : Savings::open((int)$m['id'], (int)$p['id'], null, $opened, trim((string)$r['passbook_no']) ?: null, 'sav.accounts');
                $upd = ['status' => 'active', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s'), 'opened_on' => $opened];
                if (trim((string)$r['account_no']) !== '') {
                    $upd['account_no'] = mb_substr(trim((string)$r['account_no']), 0, 30);
                }
                if (trim((string)$r['passbook_no']) !== '') {
                    $upd['passbook_no'] = mb_substr(trim((string)$r['passbook_no']), 0, 20);
                }
                DB::update('sav_accounts', $upd, 'id = :id', ['id' => $id]);
                if (Dec::isPos($r['amount'])) {
                    Savings::move(Savings::account($id, true), $c['code'], 'credit', $r['amount'], $date, ['txn_id' => $txnId, 'description' => $c['description'], 'ref_type' => 'import',
                        'opposite_account_id' => (int)$c['opposite_account_id'], 'is_system' => true]);
                }
                $out[$i] = (string)DB::value('SELECT account_no FROM sav_accounts WHERE id = :id', ['id' => $id]);
            }
            DB::insert('coop_imports', ['kind' => 'savings', 'as_of' => $date, 'rows_count' => count($grp), 'total' => Dec::fix($sum), 'offset_account_id' => (int)$c['opposite_account_id'], 'txn_id' => $txnId,
                'file_name' => mb_substr($fileName, 0, 190) ?: null, 'summary_json' => json_encode(['source' => 'csv_review', 'code' => $c['code']]), 'created_by' => Auth::id()]);
        }
        // the account numbers in the order of the rows
        ksort($out);
        return array_values($out);
    }

    private static function postTd(array $rows, array $opt, string $fileName, string $pageKey): array
    {
        $date = (string)$opt['post_date'];
        $products = [];
        foreach (DB::all('SELECT * FROM sav_products') as $p) {
            $products[$p['code']] = $p;
        }
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['savings_txn_code']][] = $r;
        }
        $out = [];
        foreach ($groups as $codeKey => $grp) {
            $c = Savings::code((string)$codeKey);
            $gl = [];
            $sum = '0';
            foreach ($grp as $r) {
                $m = DB::row('SELECT id, member_no FROM members WHERE member_no = :n', ['n' => $r['member_code']]);
                $p = $products[$r['product']];
                $gl[] = ['account_id' => Savings::liabilityAccount($p), 'credit' => $r['amount'], 'memo' => $c['code'] . ' time deposit ' . $m['member_no'], 'member_id' => (int)$m['id']];
                $sum = Dec::add($sum, $r['amount'], 2);
                if (($r['accrued_interest'] ?? '') !== '' && Dec::isPos($r['accrued_interest'])) {
                    $gl[] = ['account_id' => (int)($p['accrued_account_id'] ?: Accts::get('deposit_interest_payable')), 'credit' => $r['accrued_interest'], 'memo' => 'Accrued interest at ' . fdate($date) . ' ' . $m['member_no'], 'member_id' => (int)$m['id']];
                    $sum = Dec::add($sum, $r['accrued_interest'], 2);
                }
            }
            $gl[] = ['account_id' => (int)$c['opposite_account_id'], 'debit' => $sum, 'memo' => $c['code'] . ' ' . $c['description']];
            $txnId = CoopLedger::post('coop_jv', ['txn_date' => $date, 'memo' => 'Time deposit import as of ' . fdate($date) . ' (' . $c['code'] . ' ' . $c['description'] . ')', 'reason' => 'Import of time deposits'], $gl);
            foreach ($grp as $r) {
                $m = DB::row('SELECT id FROM members WHERE member_no = :n', ['n' => $r['member_code']]);
                $p = $products[$r['product']];
                [$pid] = TimeDeposits::place((int)$m['id'], (int)$p['id'], $r['amount'], (int)$r['_days'], $r['date_opened'], ['kind' => 'opening', 'txn_id' => $txnId], [
                    'rate' => Dec::fix($r['_rate'], 4), 'post_date' => $date, 'certificate_no' => trim((string)$r['certificate_no']) ?: null, 'maturity_action' => $r['maturity_action'] ?: 'payout',
                    'term_label' => self::termLabel((string)$r['td_term']), 'notes' => 'Imported (' . $c['code'] . ')', 'opening_code' => $c['code'],
                ], $pageKey);
                $acc = ($r['accrued_interest'] ?? '') !== '' ? Dec::of($r['accrued_interest']) : '0';
                if (Dec::isPos($acc)) {
                    DB::insert('td_accruals', ['placement_id' => $pid, 'accrual_date' => $date, 'period_from' => $r['date_opened'], 'period_to' => $date, 'amount' => Dec::fix($acc), 'txn_id' => $txnId]);
                    DB::update('td_placements', ['accrued_interest' => Dec::fix($acc)], 'id = :id', ['id' => $pid]);
                }
                $out[] = (string)DB::value('SELECT certificate_no FROM td_placements WHERE id = :id', ['id' => $pid]);
            }
            DB::insert('coop_imports', ['kind' => 'time_deposits', 'as_of' => $date, 'rows_count' => count($grp), 'total' => Dec::fix($sum), 'offset_account_id' => (int)$c['opposite_account_id'], 'txn_id' => $txnId,
                'file_name' => mb_substr($fileName, 0, 190) ?: null, 'summary_json' => json_encode(['source' => 'csv_review', 'code' => $c['code']]), 'created_by' => Auth::id()]);
        }
        return $out;
    }

    private static function postBatch(string $kind, array $rows, array $opt, string $pageKey): array
    {
        $date = (string)$opt['date'];
        $out = [];
        foreach ($rows as $r) {
            $a = Savings::byNo((string)$r['account_no']);
            $res = SavTxn::post(['account_id' => (int)$a['id'], 'code' => strtoupper((string)$opt['code']), 'amount' => $r['amount'], 'date' => $date, 'back_entry' => $date < today(),
                'memo' => trim((string)$r['memo']) !== '' ? trim((string)$r['memo']) : trim((string)($opt['memo'] ?? '')), 'ref_type' => 'batch'], $pageKey);
            $out[] = $res['trans_no'];
        }
        return $out;
    }
}
