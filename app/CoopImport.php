<?php
declare(strict_types=1);

/**
 * Opening balances brought over from the previous system, as of a cut-off date: members, savings and share capital
 * balances, time deposit placements and loan balances. Each import is checked first (every row, nothing saved),
 * then posted in one database transaction with one journal voucher: the member ledgers are credited / debited and
 * the GL control accounts receive the same totals against an offset account (Opening Balance Equity by default), so
 * the subsidiary ledgers agree with the general ledger from the first day. Any error in any row and nothing is saved.
 */
final class CoopImport
{
    /** kind => [label, columns (* = required), help] */
    public const KINDS = [
        'members' => ['Members', ['member_no*', 'last_name*', 'first_name*', 'middle_name', 'suffix', 'gender', 'birth_date', 'civil_status', 'member_type', 'branch', 'membership_date', 'mobile', 'email', 'tin',
            'street', 'barangay', 'city', 'province', 'subscribed_shares', 'par_value', 'status'], 'member_type and branch are codes; status active (default) or applicant; dates as YYYY-MM-DD.'],
        'savings' => ['Savings and share capital balances', ['member_no*', 'product*', 'balance*', 'account_no', 'opened_on', 'passbook_no'],
            'product is the savings / share capital product code. One row per account; the balance becomes the opening entry dated on the cut-off date.'],
        'time_deposits' => ['Time deposit placements', ['member_no*', 'product*', 'principal*', 'rate*', 'placement_date*', 'term_days*', 'certificate_no', 'maturity_action', 'accrued_interest'],
            'rate in % a year; maturity_action payout, renew_principal, renew_all or credit_savings; accrued_interest is the interest already booked as payable at the cut-off.'],
        'loans' => ['Loan balances', ['member_no*', 'product*', 'principal_balance*', 'first_due*', 'remaining_term*', 'term_unit', 'mode', 'rate', 'loan_no', 'pn_no', 'original_principal', 'original_release',
            'interest_balance', 'penalty_balance', 'accrued_interest'],
            'The schedule is rebuilt from the cut-off: principal_balance over remaining_term (months unless term_unit is days) from the first_due date; a first_due before the cut-off makes those amortizations past due. interest_balance replaces the scheduled interest of add-on loans; accrued_interest is interest receivable already in the GL.'],
    ];

    /** Rows of a CSV text (comma, semicolon or tab), keyed by the lower-case header */
    public static function parse(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        if (!$lines || trim($lines[0]) === '') {
            return [];
        }
        $first = $lines[0];
        $delim = substr_count($first, "\t") > substr_count($first, ',') ? "\t" : (substr_count($first, ';') > substr_count($first, ',') ? ';' : ',');
        $head = array_map(fn($h) => strtolower(trim(str_replace([' ', '-'], '_', $h), " *\"'")), str_getcsv($first, $delim));
        $rows = [];
        foreach (array_slice($lines, 1) as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            $vals = str_getcsv($line, $delim);
            $r = ['_line' => $n + 2];
            foreach ($head as $i => $h) {
                if ($h !== '') {
                    $r[$h] = trim((string)($vals[$i] ?? ''));
                }
            }
            $rows[] = $r;
        }
        return $rows;
    }

    /**
     * Checks every row. Returns ['rows' => rows with 'errors' (list) and the resolved ids, 'gl' => [account_id => signed amount],
     * 'total' => amount, 'ok' => bool]. Nothing is saved.
     */
    public static function check(string $kind, array $rows, string $asOf): array
    {
        if (!isset(self::KINDS[$kind])) {
            fail('Choose what to import.');
        }
        if (!valid_date($asOf)) {
            fail('Enter the cut-off date.');
        }
        if (!$rows) {
            fail('There are no rows: the first line is the header, one row per line after it.');
        }
        $required = array_map(fn($c) => rtrim($c, '*'), array_filter(self::KINDS[$kind][1], fn($c) => str_ends_with($c, '*')));
        $missing = array_diff($required, array_keys($rows[0]));
        if ($missing) {
            fail('Missing column(s): ' . implode(', ', $missing) . '.');
        }
        $gl = [];
        $total = '0';
        $seen = [];
        $num = function (array &$r, string $k, bool $req, array &$err): ?string {
            $v = trim((string)($r[$k] ?? ''));
            if ($v === '') {
                if ($req) {
                    $err[] = $k . ' is required';
                }
                return null;
            }
            try {
                return Dec::of($v);
            } catch (UserError $e) {
                $err[] = $k . ' is not a number';
                return null;
            }
        };
        $date = function (array &$r, string $k, bool $req, array &$err): ?string {
            $v = trim((string)($r[$k] ?? ''));
            if ($v === '') {
                if ($req) {
                    $err[] = $k . ' is required';
                }
                return null;
            }
            $t = strtotime($v);
            if ($t === false) {
                $err[] = $k . ' is not a date';
                return null;
            }
            return date('Y-m-d', $t);
        };
        $addGl = function (int $acct, string $amt) use (&$gl) {
            $gl[$acct] = Dec::add($gl[$acct] ?? '0', $amt, 2);
        };
        $products = [];
        foreach (DB::all('SELECT * FROM sav_products') as $p) {
            $products['sav'][strtoupper($p['code'])] = $p;
        }
        foreach (DB::all('SELECT * FROM loan_products') as $p) {
            $products['loan'][strtoupper($p['code'])] = $p;
        }
        foreach ($rows as &$r) {
            $err = [];
            $no = trim((string)($r['member_no'] ?? ''));
            $m = $no !== '' ? DB::row('SELECT * FROM members WHERE member_no = :n', ['n' => $no]) : null;
            if ($kind === 'members') {
                if ($no === '') {
                    $err[] = 'member_no is required';
                } elseif ($m) {
                    $err[] = 'member no. ' . $no . ' already exists';
                } elseif (isset($seen[$no])) {
                    $err[] = 'member no. ' . $no . ' appears twice in the file';
                }
                $seen[$no] = true;
                foreach (['last_name', 'first_name'] as $k) {
                    if (trim((string)($r[$k] ?? '')) === '') {
                        $err[] = $k . ' is required';
                    }
                }
                $r['_birth'] = $date($r, 'birth_date', false, $err);
                $r['_since'] = $date($r, 'membership_date', false, $err) ?? $asOf;
                $r['_type'] = null;
                if (($r['member_type'] ?? '') !== '') {
                    $r['_type'] = DB::value('SELECT id FROM member_types WHERE code = :c', ['c' => strtoupper($r['member_type'])]);
                    if (!$r['_type']) {
                        $err[] = 'member type ' . $r['member_type'] . ' is not set up';
                    }
                }
                $r['_branch'] = null;
                if (($r['branch'] ?? '') !== '') {
                    $r['_branch'] = DB::value('SELECT id FROM coop_branches WHERE code = :c', ['c' => $r['branch']]);
                    if (!$r['_branch']) {
                        $err[] = 'branch ' . $r['branch'] . ' is not set up';
                    }
                }
                if (($r['status'] ?? '') !== '' && !in_array(strtolower($r['status']), ['active', 'applicant'], true)) {
                    $err[] = 'status is active or applicant';
                }
                $num($r, 'subscribed_shares', false, $err);
                $num($r, 'par_value', false, $err);
            } else {
                if (!$m) {
                    $err[] = $no === '' ? 'member_no is required' : 'member no. ' . $no . ' is not on file (import the members first)';
                } elseif (!in_array($m['status'], ['active', 'applicant', 'suspended'], true)) {
                    $err[] = 'member ' . $no . ' is ' . $m['status'];
                }
                $r['_member'] = $m ? (int)$m['id'] : null;
            }
            if ($kind === 'savings' || $kind === 'time_deposits') {
                $p = $products['sav'][strtoupper((string)($r['product'] ?? ''))] ?? null;
                if (!$p) {
                    $err[] = 'product ' . ($r['product'] ?? '') . ' is not a savings / deposit product';
                } elseif ($kind === 'savings' && $p['product_type'] === 'time_deposit') {
                    $err[] = 'time deposits are imported as placements (choose Time deposit placements)';
                } elseif ($kind === 'time_deposits' && $p['product_type'] !== 'time_deposit') {
                    $err[] = 'product ' . $p['code'] . ' is not a time deposit product';
                }
                $r['_product'] = $p ? (int)$p['id'] : null;
                if ($kind === 'savings') {
                    $bal = $num($r, 'balance', true, $err);
                    if ($bal !== null && Dec::isNeg($bal)) {
                        $err[] = 'the balance cannot be negative';
                    }
                    $r['_amount'] = $bal ?? '0';
                    $r['_opened'] = $date($r, 'opened_on', false, $err) ?? $asOf;
                    if ($r['_opened'] > $asOf) {
                        $err[] = 'opened after the cut-off date';
                    }
                    if (($r['account_no'] ?? '') !== '' && DB::value('SELECT id FROM sav_accounts WHERE account_no = :a', ['a' => $r['account_no']])) {
                        $err[] = 'account no. ' . $r['account_no'] . ' already exists';
                    }
                    if ($m && $p && DB::value("SELECT a.id FROM sav_accounts a WHERE a.member_id = :m AND a.product_id = :p AND a.status <> 'closed' AND EXISTS (SELECT 1 FROM sav_txns t WHERE t.account_id = a.id)", ['m' => $m['id'], 'p' => $p['id']])
                        && ($r['account_no'] ?? '') === '') {
                        $err[] = 'the member already has a ' . $p['name'] . ' account with transactions';
                    }
                    if ($p && !$err) {
                        $addGl(Savings::liabilityAccount($p), Dec::neg($r['_amount']));
                    }
                } else {
                    $pr = $num($r, 'principal', true, $err);
                    $rate = $num($r, 'rate', true, $err);
                    $r['_placed'] = $date($r, 'placement_date', true, $err);
                    $days = (int)($r['term_days'] ?? 0);
                    if ($days <= 0) {
                        $err[] = 'term_days is required';
                    }
                    if ($r['_placed'] && $r['_placed'] > $asOf) {
                        $err[] = 'placed after the cut-off date';
                    }
                    if (($r['certificate_no'] ?? '') !== '' && DB::value('SELECT id FROM td_placements WHERE certificate_no = :c', ['c' => $r['certificate_no']])) {
                        $err[] = 'certificate no. ' . $r['certificate_no'] . ' already exists';
                    }
                    if (($r['maturity_action'] ?? '') !== '' && !in_array($r['maturity_action'], ['payout', 'renew_principal', 'renew_all', 'credit_savings'], true)) {
                        $err[] = 'maturity_action is payout, renew_principal, renew_all or credit_savings';
                    }
                    $acc = $num($r, 'accrued_interest', false, $err) ?? '0';
                    $r['_amount'] = $pr ?? '0';
                    $r['_rate'] = $rate;
                    $r['_days'] = $days;
                    $r['_accrued'] = $acc;
                    if ($p && !$err) {
                        $addGl(Savings::liabilityAccount($p), Dec::neg($r['_amount']));
                        if (Dec::isPos($acc)) {
                            $addGl((int)($p['accrued_account_id'] ?: Accts::get('deposit_interest_payable')), Dec::neg($acc));
                        }
                    }
                }
            }
            if ($kind === 'loans') {
                $p = $products['loan'][strtoupper((string)($r['product'] ?? ''))] ?? null;
                if (!$p) {
                    $err[] = 'product ' . ($r['product'] ?? '') . ' is not a loan product';
                }
                $r['_product'] = $p ? (int)$p['id'] : null;
                $bal = $num($r, 'principal_balance', true, $err);
                if ($bal !== null && !Dec::isPos($bal)) {
                    $err[] = 'principal_balance must be more than zero';
                }
                $r['_amount'] = $bal ?? '0';
                $r['_first'] = $date($r, 'first_due', true, $err);
                $term = $num($r, 'remaining_term', true, $err);
                if ($term !== null && !Dec::isPos($term)) {
                    $err[] = 'remaining_term must be more than zero';
                }
                $r['_term'] = $term;
                $r['_unit'] = strtolower((string)($r['term_unit'] ?? '')) === 'days' ? 'days' : 'months';
                $mode = strtolower((string)($r['mode'] ?? '')) ?: ($p['mode_of_payment'] ?? 'monthly');
                if (!isset(Coop::MODES[$mode])) {
                    $err[] = 'mode ' . $mode . ' is not a mode of payment (' . implode(', ', array_keys(Coop::MODES)) . ')';
                }
                $r['_mode'] = $mode;
                $r['_rate'] = $num($r, 'rate', false, $err) ?? ($p ? Dec::of($p['rate_default'] ?? '0') : '0');
                $r['_ib'] = $num($r, 'interest_balance', false, $err);
                $r['_pen'] = $num($r, 'penalty_balance', false, $err) ?? '0';
                $r['_acc'] = $num($r, 'accrued_interest', false, $err) ?? '0';
                $r['_orig'] = $num($r, 'original_principal', false, $err);
                $r['_origDate'] = $date($r, 'original_release', false, $err);
                if (($r['loan_no'] ?? '') !== '') {
                    if (DB::value('SELECT id FROM loan_accounts WHERE loan_no = :n', ['n' => $r['loan_no']])) {
                        $err[] = 'loan no. ' . $r['loan_no'] . ' already exists';
                    } elseif (isset($seen['loan:' . $r['loan_no']])) {
                        $err[] = 'loan no. ' . $r['loan_no'] . ' appears twice in the file';
                    }
                    $seen['loan:' . $r['loan_no']] = true;
                }
                if ($p && !$err) {
                    $addGl((int)($p['receivable_account_id'] ?: Accts::get('loans_receivable')), $r['_amount']);
                    if (Dec::isPos($r['_acc'])) {
                        $addGl(Accts::get('loan_interest_receivable'), $r['_acc']);
                    }
                }
            }
            $r['errors'] = $err;
            if (!$err && $kind !== 'members') {
                $total = Dec::add($total, $r['_amount'], 2);
            }
        }
        unset($r);
        $ok = !array_filter($rows, fn($x) => $x['errors']);
        return ['rows' => $rows, 'gl' => $gl, 'total' => $total, 'ok' => $ok];
    }

    /** Posts a checked import (all rows or none). Returns the import id. */
    public static function run(string $kind, array $rows, string $asOf, ?int $offsetAccountId, string $fileName, string $pageKey): int
    {
        return DB::tx(function () use ($kind, $rows, $asOf, $offsetAccountId, $fileName, $pageKey) {
            if (!Access::isAdmin() && !Access::can($pageKey, 'approve')) {
                fail('Importing opening balances needs the Approve right on this page.');
            }
            $c = self::check($kind, $rows, $asOf);
            if (!$c['ok']) {
                fail('Some rows have errors. Check the file again.');
            }
            $rows = $c['rows'];
            $txnId = null;
            if ($kind !== 'members') {
                $offset = $offsetAccountId ?: Accts::get('opening_equity');
                // the member lines carry the member's name on the control accounts (subsidiary ledger by member)
                $gl = self::memberLines($kind, $rows, $asOf);
                $net = '0';
                foreach ($gl as $g) {
                    $net = Dec::add($net, Dec::sub($g['debit'] ?? '0', $g['credit'] ?? '0', 2), 2);
                }
                if (!Dec::isZero($net)) {
                    $gl[] = ['account_id' => $offset, Dec::isPos($net) ? 'credit' : 'debit' => Dec::abs($net), 'memo' => 'Opening balances offset as of ' . fdate($asOf)];
                }
                $txnId = CoopLedger::post('coop_jv', ['txn_date' => $asOf, 'memo' => 'Opening balances: ' . self::KINDS[$kind][0] . ' as of ' . fdate($asOf), 'reason' => 'Import of opening balances'], $gl);
            }
            $done = 0;
            foreach ($rows as $r) {
                match ($kind) {
                    'members' => self::member($r, $pageKey),
                    'savings' => self::savings($r, $asOf, (int)$txnId),
                    'time_deposits' => self::timeDeposit($r, $asOf, (int)$txnId),
                    'loans' => self::loan($r, $asOf, (int)$txnId),
                };
                $done++;
            }
            $id = DB::insert('coop_imports', ['kind' => $kind, 'as_of' => $asOf, 'rows_count' => $done, 'total' => Dec::fix($c['total']), 'offset_account_id' => $kind === 'members' ? null : ($offsetAccountId ?: Accts::get('opening_equity')),
                'txn_id' => $txnId, 'file_name' => mb_substr($fileName, 0, 190) ?: null, 'summary_json' => json_encode(['gl' => $c['gl']]), 'created_by' => Auth::id()]);
            Audit::log('import', 'coop', 'opening_balances', $id, self::KINDS[$kind][0] . ' as of ' . fdate($asOf), ['page_key' => $pageKey, 'amount' => (float)$c['total'], 'reason' => $done . ' row(s)']);
            return $id;
        });
    }

    /** One GL line per member row on its control account */
    private static function memberLines(string $kind, array $rows, string $asOf): array
    {
        $gl = [];
        foreach ($rows as $r) {
            if ($kind === 'savings' || $kind === 'time_deposits') {
                $p = Savings::product((int)$r['_product']);
                if (Dec::isPos($r['_amount'])) {
                    $gl[] = ['account_id' => Savings::liabilityAccount($p), 'credit' => $r['_amount'], 'memo' => 'Opening balance ' . $p['code'] . ' ' . $r['member_no'], 'member_id' => $r['_member']];
                }
                if ($kind === 'time_deposits' && Dec::isPos($r['_accrued'])) {
                    $gl[] = ['account_id' => (int)($p['accrued_account_id'] ?: Accts::get('deposit_interest_payable')), 'credit' => $r['_accrued'], 'memo' => 'Accrued interest at the cut-off ' . $r['member_no'], 'member_id' => $r['_member']];
                }
            } elseif ($kind === 'loans') {
                $p = Loans::product((int)$r['_product']);
                $gl[] = ['account_id' => (int)($p['receivable_account_id'] ?: Accts::get('loans_receivable')), 'debit' => $r['_amount'], 'memo' => 'Opening loan balance ' . ($r['loan_no'] ?? '') . ' ' . $r['member_no'], 'member_id' => $r['_member']];
                if (Dec::isPos($r['_acc'])) {
                    $gl[] = ['account_id' => Accts::get('loan_interest_receivable'), 'debit' => $r['_acc'], 'memo' => 'Accrued interest at the cut-off ' . $r['member_no'], 'member_id' => $r['_member']];
                }
            }
        }
        return $gl;
    }

    private static function member(array $r, string $pageKey): void
    {
        $d = ['member_no' => $r['member_no'], 'member_kind' => 'individual', 'last_name' => $r['last_name'], 'first_name' => $r['first_name'], 'middle_name' => ($r['middle_name'] ?? '') ?: null,
            'suffix' => ($r['suffix'] ?? '') ?: null, 'gender' => ($r['gender'] ?? '') ?: null, 'birth_date' => $r['_birth'], 'civil_status' => strtolower((string)($r['civil_status'] ?? '')) ?: null,
            'member_type_id' => $r['_type'] ? (int)$r['_type'] : (DB::value('SELECT id FROM member_types WHERE is_active = 1 ORDER BY sort_order, id LIMIT 1') ?: null),
            'branch_id' => $r['_branch'] ? (int)$r['_branch'] : Coop::defaultBranchId(), 'membership_date' => $r['_since'], 'mobile' => ($r['mobile'] ?? '') ?: null, 'email' => ($r['email'] ?? '') ?: null,
            'tin' => ($r['tin'] ?? '') ?: null, 'res_street' => ($r['street'] ?? '') ?: null, 'res_barangay' => ($r['barangay'] ?? '') ?: null, 'res_city' => ($r['city'] ?? '') ?: null,
            'res_province' => ($r['province'] ?? '') ?: null, 'subscribed_shares' => Dec::fix(Dec::of($r['subscribed_shares'] ?? '0'), 0), 'par_value' => Dec::fix(Dec::of(($r['par_value'] ?? '') ?: Coop::setting('default_par_value', '100'))),
            'status' => strtolower((string)($r['status'] ?? '')) === 'applicant' ? 'applicant' : 'active', 'allow_duplicate' => true];
        Members::save($d, [], null, $pageKey);
    }

    private static function savings(array $r, string $asOf, int $txnId): void
    {
        $existing = DB::value("SELECT a.id FROM sav_accounts a WHERE a.member_id = :m AND a.product_id = :p AND a.status <> 'closed' AND NOT EXISTS (SELECT 1 FROM sav_txns t WHERE t.account_id = a.id) ORDER BY a.id LIMIT 1",
            ['m' => $r['_member'], 'p' => $r['_product']]);
        $id = $existing ? (int)$existing : Savings::open((int)$r['_member'], (int)$r['_product'], null, $r['_opened'], ($r['passbook_no'] ?? '') ?: null, 'sav.accounts');
        $upd = ['status' => 'active', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s'), 'opened_on' => $r['_opened']];
        if (($r['account_no'] ?? '') !== '') {
            $upd['account_no'] = mb_substr($r['account_no'], 0, 30);
        }
        DB::update('sav_accounts', $upd, 'id = :id', ['id' => $id]);
        if (Dec::isPos($r['_amount'])) {
            Savings::move(Savings::account($id, true), 'OB', 'credit', $r['_amount'], $asOf, ['txn_id' => $txnId, 'description' => 'Opening balance', 'ref_type' => 'import', 'is_system' => true]);
        }
    }

    private static function timeDeposit(array $r, string $asOf, int $txnId): void
    {
        [$pid] = TimeDeposits::place((int)$r['_member'], (int)$r['_product'], $r['_amount'], (int)$r['_days'], $r['_placed'], ['kind' => 'opening', 'txn_id' => $txnId],
            ['rate' => Dec::fix($r['_rate'], 4), 'post_date' => $asOf, 'certificate_no' => ($r['certificate_no'] ?? '') ?: null, 'maturity_action' => ($r['maturity_action'] ?? '') ?: 'payout', 'notes' => 'Opening balance'], 'sav.td');
        if (Dec::isPos($r['_accrued'])) {
            DB::insert('td_accruals', ['placement_id' => $pid, 'accrual_date' => $asOf, 'period_from' => $r['_placed'], 'period_to' => $asOf, 'amount' => Dec::fix($r['_accrued']), 'txn_id' => $txnId]);
            DB::update('td_placements', ['accrued_interest' => Dec::fix($r['_accrued'])], 'id = :id', ['id' => $pid]);
        }
    }

    private static function loan(array $r, string $asOf, int $txnId): void
    {
        $p = Loans::product((int)$r['_product']);
        $m = Coop::member((int)$r['_member']);
        $branchId = $m['branch_id'] ? (int)$m['branch_id'] : Coop::defaultBranchId();
        $amort = $p['amort_type'];
        $sched = Loans::schedule($r['_amount'], $r['_rate'], $r['_term'], $r['_unit'], $r['_mode'], $p['interest_method'], $amort, $r['_first'], (int)$p['calendar_days'] ?: 365, $p['diminishing_by']);
        $interest = $r['_ib'] !== null ? Dec::round($r['_ib'], 2) : Dec::of($sched['total_interest']);
        if ($r['_ib'] !== null && Dec::cmp($interest, $sched['total_interest']) !== 0) {
            // the interest still to be collected on an add-on loan, as the old system shows it: spread over the amortizations
            $sched = self::spreadInterest($sched, $interest);
        }
        $loanNo = ($r['loan_no'] ?? '') !== '' ? mb_substr($r['loan_no'], 0, 30) : Coop::loanNo($p, $branchId);
        $note = 'Opening balance as of ' . fdate($asOf) . ($r['_orig'] !== null ? '; originally ' . Dec::money($r['_orig']) : '') . ($r['_origDate'] ? ' released ' . fdate($r['_origDate']) : '') . ' in the previous system';
        $loanId = DB::insert('loan_accounts', [
            'loan_no' => $loanNo, 'application_id' => null, 'member_id' => $m['id'], 'product_id' => $p['id'], 'branch_id' => $branchId, 'pn_no' => ($r['pn_no'] ?? '') ?: null,
            'principal' => Dec::fix($r['_amount']), 'rate' => Dec::fix($r['_rate'], 4), 'interest_method' => $p['interest_method'], 'amort_type' => $amort,
            'term' => Dec::fix($r['_term']), 'term_unit' => $r['_unit'], 'mode_of_payment' => $r['_mode'], 'calendar_days' => (int)$p['calendar_days'] ?: 365,
            'release_date' => $asOf, 'start_date' => $r['_first'], 'due_date' => $sched['due_date'], 'release_mode' => 'opening',
            'receivable_account_id' => (int)($p['receivable_account_id'] ?: Accts::get('loans_receivable')), 'interest_account_id' => (int)($p['interest_account_id'] ?: Accts::get('loan_interest_income')),
            'penalty_account_id' => $p['penalty_account_id'] ?: Accts::get('loan_penalty_income'), 'penalty_rate' => $p['penalty_rate'], 'penalty_grace_days' => (int)$p['penalty_grace_days'], 'penalty_base' => $p['penalty_base'],
            'total_interest' => Dec::fix($interest), 'status' => 'current', 'principal_balance' => Dec::fix($r['_amount']), 'interest_balance' => Dec::fix($interest), 'penalty_balance' => Dec::fix($r['_pen']),
            // penalties up to the cut-off are in the opening penalty balance; new ones count from the day after
            'last_penalty_date' => $asOf, 'notes' => mb_substr($note, 0, 255), 'created_by' => Auth::id(),
        ]);
        foreach ($sched['rows'] as $row) {
            DB::insert('loan_schedule', ['loan_id' => $loanId, 'inst_no' => $row['inst_no'], 'due_date' => $row['due_date'], 'principal' => Dec::fix($row['principal']), 'interest' => Dec::fix($row['interest']),
                'total' => Dec::fix($row['total']), 'balance_after' => Dec::fix($row['balance_after'])]);
        }
        DB::insert('loan_txns', ['loan_id' => $loanId, 'txn_date' => $asOf, 'kind' => 'release', 'memo' => 'Opening balance', 'principal_dr' => Dec::fix($r['_amount']), 'interest_dr' => Dec::fix($interest),
            'penalty_dr' => Dec::fix($r['_pen']), 'principal_bal' => Dec::fix($r['_amount']), 'interest_bal' => Dec::fix($interest), 'penalty_bal' => Dec::fix($r['_pen']), 'txn_id' => $txnId, 'created_by' => Auth::id()]);
        if (Dec::isPos($r['_acc'])) {
            // interest already in income at the cut-off: it stands in the receivable until collected
            DB::insert('loan_txns', ['loan_id' => $loanId, 'txn_date' => $asOf, 'kind' => 'accrual', 'memo' => 'Interest accrued before the cut-off', 'interest_dr' => Dec::fix($r['_acc']), 'interest_cr' => Dec::fix($r['_acc']),
                'principal_bal' => Dec::fix($r['_amount']), 'interest_bal' => Dec::fix($interest), 'penalty_bal' => Dec::fix($r['_pen']), 'txn_id' => $txnId, 'created_by' => Auth::id()]);
        }
        Loans::refreshBalances($loanId, $asOf);
        Audit::log('import', 'loan', 'loan_account', $loanId, $loanNo . ' ' . $m['full_name'], ['page_key' => 'coop.import', 'amount' => (float)$r['_amount'], 'reason' => $note]);
    }

    /** Replaces the scheduled interest by a given total, spread evenly (the last amortization takes the rounding) */
    private static function spreadInterest(array $sched, string $interest): array
    {
        $n = count($sched['rows']);
        $each = Dec::round(Dec::div($interest, (string)$n, 10), 2);
        $left = $interest;
        foreach ($sched['rows'] as $i => &$row) {
            $v = $i === $n - 1 ? $left : $each;
            $left = Dec::sub($left, $v, 2);
            $row['interest'] = $v;
            $row['total'] = Dec::add($row['principal'], $v, 2);
        }
        unset($row);
        $sched['total_interest'] = $interest;
        return $sched;
    }

    /** A sample file for a kind (header and one example row) */
    public static function sample(string $kind): string
    {
        $cols = array_map(fn($c) => rtrim($c, '*'), self::KINDS[$kind][1]);
        $ex = match ($kind) {
            'members' => ['100101', 'Dela Cruz', 'Juan', 'Santos', '', 'Male', '1985-04-12', 'married', '', '', '2015-06-01', '09171234567', '', '', 'Rizal St.', 'Poblacion', 'San Isidro', 'Nueva Ecija', '100', '100', 'active'],
            'savings' => ['100101', '01', '12500.00', '', '2015-06-01', ''],
            'time_deposits' => ['100101', '04', '50000.00', '4.00', '2026-08-01', '180', '', 'payout', '330.00'],
            'loans' => ['100101', '6', '45000.00', '2026-11-15', '15', 'months', 'monthly', '18', '', '', '60000.00', '2025-11-15', '', '0', '0'],
            default => [],
        };
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, $cols);
        fputcsv($fh, array_pad($ex, count($cols), ''));
        rewind($fh);
        return (string)stream_get_contents($fh);
    }
}
