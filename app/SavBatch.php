<?php
declare(strict_types=1);

/**
 * Batch deductions: the payroll (or CBU / collection list) of an employer or a collector, posted as one voucher.
 * A line is a deposit to a savings / share capital account or a payment on a loan. The money side is one debit to
 * the contra account (Payroll Deductions Receivable until the employer remits, or the bank / cash).
 * Lines are checked first; nothing is posted while any line has an error.
 */
final class SavBatch
{
    /** True once the batches carry the class chosen for them (upgrade 2026_12_20) */
    public static function classReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(class_id) FROM sav_batches WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    public static function batch(int $id, bool $lock = false): ?array
    {
        return DB::row('SELECT * FROM sav_batches WHERE id = :id' . ($lock ? ' FOR UPDATE' : ''), ['id' => $id]);
    }

    /**
     * Reads pasted / uploaded text: one line per row, "reference, amount[, remarks]" separated by commas, tabs or
     * semicolons. The reference is an account number, a loan number or a member number (with $productId, the member's
     * account of that product).
     */
    public static function parse(string $text): array
    {
        $out = [];
        $first = true;
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        foreach (preg_split('/\R/', $text) as $n => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $isFirst = $first;
            $first = false;
            $cols = array_map(fn($c) => trim($c, " \t\"'"), preg_split('/[\t;,](?=(?:[^"]*"[^"]*")*[^"]*$)/', $line));
            if (count($cols) < 2) {
                $out[] = ['ref' => $cols[0], 'amount' => '', 'remarks' => '', 'line' => $n + 1];
                continue;
            }
            $amount = str_replace(',', '', $cols[1]);
            if ($isFirst && !is_numeric($amount)) {
                continue;        // a heading row (the first line that is not blank)
            }
            $out[] = ['ref' => $cols[0], 'amount' => $amount, 'remarks' => $cols[2] ?? '', 'line' => $n + 1];
        }
        return $out;
    }

    /** Creates a draft batch with its lines (each line resolved and checked) */
    public static function create(array $h, array $rows, ?int $productId, string $pageKey): int
    {
        return DB::tx(function () use ($h, $rows, $productId, $pageKey) {
            if (!valid_date($h['batch_date'] ?? '')) {
                fail('Enter the date of the batch.');
            }
            if (empty($h['contra_account_id'])) {
                fail('Choose the account the money comes from (payroll deductions receivable, bank or cash).');
            }
            if (!$rows) {
                fail('There are no lines.');
            }
            $id = DB::insert('sav_batches', ['batch_no' => Seq::next('sav_batch'), 'batch_date' => $h['batch_date'], 'description' => mb_substr((string)($h['description'] ?? ''), 0, 160) ?: null,
                'source' => in_array($h['source'] ?? '', ['payroll', 'cbu', 'collection', 'other'], true) ? $h['source'] : 'payroll', 'contra_account_id' => (int)$h['contra_account_id'],
                'branch_id' => Coop::defaultBranchId(), 'status' => 'draft', 'created_by' => Auth::id()]
                + (self::classReady() && !empty($h['class_id']) ? ['class_id' => (int)$h['class_id']] : []));
            foreach ($rows as $r) {
                DB::insert('sav_batch_lines', ['batch_id' => $id, 'member_no' => mb_substr((string)$r['ref'], 0, 30), 'amount' => is_numeric($r['amount']) ? Dec::fix(Dec::of($r['amount'])) : '0.00',
                    'remarks' => mb_substr((string)($r['remarks'] ?? ''), 0, 120) ?: null, 'error_text' => is_numeric($r['amount']) ? null : 'Amount missing or not a number (line ' . $r['line'] . ')']);
            }
            self::check($id, $productId);
            Audit::log('create', 'sav', 'sav_batch', $id, 'Batch ' . DB::value('SELECT batch_no FROM sav_batches WHERE id = :id', ['id' => $id]), ['page_key' => $pageKey, 'reason' => count($rows) . ' line(s)']);
            return $id;
        });
    }

    /** Resolves every line to an account or a loan and records what is wrong with it */
    public static function check(int $id, ?int $productId = null): array
    {
        $b = self::batch($id);
        $errors = 0;
        $seenLoans = [];
        foreach (DB::all('SELECT * FROM sav_batch_lines WHERE batch_id = :b ORDER BY id', ['b' => $id]) as $l) {
            $err = null;
            $acct = null;
            $loan = null;
            $ref = trim((string)$l['member_no']);
            if (!Dec::isPos($l['amount'])) {
                $err = 'The amount must be more than zero.';
            }
            if (!$err) {
                $acct = Savings::byNo($ref);
                if (!$acct) {
                    $loan = Loans::byNo($ref);
                }
                if (!$acct && !$loan) {
                    $m = DB::row('SELECT * FROM members WHERE member_no = :n', ['n' => $ref]);
                    if ($m && $productId) {
                        $aid = DB::value("SELECT id FROM sav_accounts WHERE member_id = :m AND product_id = :p AND status IN ('active','dormant','pending') ORDER BY id LIMIT 1", ['m' => $m['id'], 'p' => $productId]);
                        $acct = $aid ? Savings::account((int)$aid) : null;
                        if (!$acct) {
                            $err = 'Member ' . $ref . ' has no account of the chosen product.';
                        }
                    } elseif ($m && $l['account_id'] && ($prev = Savings::account((int)$l['account_id'])) && (int)$prev['member_id'] === (int)$m['id']) {
                        // resolved when the batch was created with a product: a later check keeps that account
                        $acct = $prev;
                    } elseif ($m) {
                        $err = 'Member number given: choose the product to credit, or give the account number.';
                    } else {
                        $err = 'No account, loan or member ' . $ref . '.';
                    }
                }
            }
            if (!$err && $acct) {
                if (!Coop::branchAllowed($acct['branch_id'] ? (int)$acct['branch_id'] : null)) {
                    $err = 'Account of another branch.';
                } elseif ($acct['status'] === 'closed') {
                    $err = 'Account ' . $acct['account_no'] . ' is closed.';
                } elseif ((int)$acct['hold_flag'] === 1) {
                    $err = 'Member on hold.';
                } elseif ($acct['product_type'] === 'time_deposit') {
                    $err = 'Time deposits are placed on their own page.';
                }
            }
            if (!$err && $loan) {
                if (!in_array($loan['status'], Loans::OPEN_STATUSES, true)) {
                    $err = 'Loan ' . $loan['loan_no'] . ' is ' . $loan['status'] . '.';
                } elseif (Dec::cmp($l['amount'], Loans::dues($loan, $b['batch_date'])['payoff']) > 0) {
                    $err = 'More than the payoff of loan ' . $loan['loan_no'] . '.';
                } elseif (isset($seenLoans[(int)$loan['id']])) {
                    $err = 'Loan ' . $loan['loan_no'] . ' is on the batch twice: put its payment on one line.';
                }
                $seenLoans[(int)$loan['id']] = true;
            }
            if ($err) {
                $errors++;
            }
            DB::update('sav_batch_lines', ['account_id' => $acct['id'] ?? null, 'loan_id' => $loan['id'] ?? null, 'account_no' => $acct['account_no'] ?? ($loan['loan_no'] ?? null), 'error_text' => $err], 'id = :id', ['id' => $l['id']]);
        }
        $t = DB::row('SELECT COUNT(*) n, COALESCE(SUM(amount),0) s FROM sav_batch_lines WHERE batch_id = :b', ['b' => $id]);
        DB::update('sav_batches', ['total' => Dec::fix($t['s']), 'lines_count' => (int)$t['n']], 'id = :id', ['id' => $id]);
        return ['errors' => $errors, 'lines' => (int)$t['n'], 'total' => $t['s']];
    }

    /** Posts a checked batch: one voucher, the member ledgers, the loan allocations */
    public static function post(int $id, string $pageKey): int
    {
        return DB::tx(function () use ($id, $pageKey) {
            $b = self::batch($id, true);
            if (!$b || $b['status'] !== 'draft') {
                fail('Only a draft batch is posted, and only once.');
            }
            Coop::checkDate($b['batch_date']);
            $c = self::check($id);
            if ($c['errors']) {
                fail($c['errors'] . ' line(s) have errors. Correct them (or remove them) first.');
            }
            $lines = DB::all('SELECT * FROM sav_batch_lines WHERE batch_id = :b ORDER BY id', ['b' => $id]);
            $memo = 'Batch ' . $b['batch_no'] . ($b['description'] ? ': ' . $b['description'] : '');
            $gl = [['account_id' => (int)$b['contra_account_id'], 'debit' => $b['total'], 'memo' => $memo]];
            $after = [];
            foreach ($lines as $l) {
                if ($l['account_id']) {
                    $a = Savings::validate((int)$l['account_id'], 'credit', $l['amount'], $b['batch_date'], ['allow_pending' => true]);
                    $gl[] = ['account_id' => Savings::ledgerAccount($a), 'credit' => $l['amount'], 'memo' => $memo . ' ' . $a['account_no'], 'member_id' => $a['member_id'],
                        'class_id' => Coop::branchClassId($a['branch_id'] ? (int)$a['branch_id'] : null)];
                    $after[] = ['sav', (int)$a['id'], $l['amount']];
                } else {
                    $loan = Loans::loan((int)$l['loan_id'], true);
                    if (Coop::setting('penalty_on_collection', '1') === '1') {
                        Loans::chargePenalty($loan, $b['batch_date']);
                        $loan = Loans::loan((int)$l['loan_id'], true);
                    }
                    $dues = Loans::dues($loan, $b['batch_date']);
                    [$pen, $int, $prn, $excess] = Loans::allocate($dues, $l['amount'], null);
                    if (Dec::isPos($excess)) {
                        fail('Line ' . $l['member_no'] . ' is more than loan ' . $loan['loan_no'] . ' can take.');
                    }
                    foreach (Loans::paymentLines($loan, $pen, $int, $prn, $memo . ' ' . $loan['loan_no']) as $g) {
                        $gl[] = $g;
                    }
                    $after[] = ['loan', (int)$loan['id'], $l['amount'], $dues];
                }
            }
            // the class of the batch (chosen when it was made, confirmed when it is posted) goes on the voucher and every line
            $classId = CoopLedger::currentClass() ?? (!empty($b['class_id']) ? (int)$b['class_id'] : null);
            $txnId = CoopLedger::withClass($classId, fn() => CoopLedger::post('coop_jv', ['txn_date' => $b['batch_date'], 'memo' => $memo, 'ref_no' => $b['batch_no'], 'branch_id' => $b['branch_id']], $gl));
            foreach ($after as $x) {
                if ($x[0] === 'sav') {
                    Savings::move(Savings::account($x[1], true), 'PDD', 'credit', $x[2], $b['batch_date'], ['txn_id' => $txnId, 'description' => $memo, 'ref_type' => 'batch', 'ref_id' => $id]);
                } else {
                    Loans::applyPayment(Loans::loan($x[1], true), $x[3], $b['batch_date'], $x[2], null, $txnId, ['memo' => $memo]);
                }
            }
            DB::update('sav_batches', ['status' => 'posted', 'txn_id' => $txnId, 'posted_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            Audit::log('post', 'sav', 'sav_batch', $id, $b['batch_no'], ['page_key' => $pageKey, 'amount' => (float)$b['total'], 'reason' => count($lines) . ' line(s)']);
            return $txnId;
        });
    }
}
