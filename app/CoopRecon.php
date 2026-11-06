<?php
declare(strict_types=1);

/**
 * Subsidiary ledgers against the general ledger: every member ledger total must equal the balance of the GL
 * control account it feeds. Used by the reconciliation report, the dashboard warning and the tests.
 */
final class CoopRecon
{
    /** GL balance (debit - credit) of an account through a date */
    public static function gl(int $accountId, string $asOf): string
    {
        return Dec::of(DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND txn_date <= :d', ['a' => $accountId, 'd' => $asOf]));
    }

    /**
     * Rows: ledger, account_id, code, name, sl, gl, difference, count (member accounts / loans).
     * Savings, share capital and time deposits by their liability / equity account (credit balances shown positive);
     * loans receivable by receivable account; accrued interest receivable; accrued interest payable on time deposits.
     */
    public static function slVsGl(string $asOf): array
    {
        $rows = [];
        $acctName = fn(int $id) => DB::row('SELECT code, name FROM accounts WHERE id = :a', ['a' => $id]) ?: ['code' => '?', 'name' => 'missing account'];

        // savings, share capital, time deposits: one line per GL account the products post to
        $byAcct = [];
        foreach (DB::all('SELECT * FROM sav_products') as $p) {
            $byAcct[Savings::liabilityAccount($p)][] = (int)$p['id'];
        }
        foreach ($byAcct as $acctId => $productIds) {
            [$in, $ip] = DB::in('pr', $productIds);
            $sl = Dec::of(DB::value("SELECT COALESCE(SUM(t.credit - t.debit),0) FROM sav_txns t JOIN sav_accounts a ON a.id = t.account_id
                                       WHERE a.product_id IN $in AND t.is_voided = 0 AND t.txn_date <= :d", $ip + ['d' => $asOf]));
            $n = (int)DB::value("SELECT COUNT(*) FROM sav_accounts a WHERE a.product_id IN $in AND a.status <> 'closed'", $ip);
            $gl = Dec::neg(self::gl($acctId, $asOf));
            $types = DB::column("SELECT DISTINCT product_type FROM sav_products WHERE id IN $in", $ip);
            $label = in_array('share_capital', $types, true) ? 'Share capital' : (in_array('time_deposit', $types, true) && count($types) === 1 ? 'Time deposits' : 'Savings deposits');
            $a = $acctName($acctId);
            $rows[] = ['ledger' => $label, 'account_id' => $acctId, 'code' => $a['code'], 'name' => $a['name'], 'sl' => Dec::fix($sl), 'gl' => Dec::fix($gl), 'difference' => Dec::fix(Dec::sub($sl, $gl, 2)), 'count' => $n];
        }

        // loans receivable: principal charged less principal paid / written off, per receivable account
        foreach (DB::column('SELECT DISTINCT receivable_account_id FROM loan_accounts') as $acctId) {
            $acctId = (int)$acctId;
            $sl = Dec::of(DB::value('SELECT COALESCE(SUM(x.principal_dr - x.principal_cr),0) FROM loan_txns x JOIN loan_accounts l ON l.id = x.loan_id
                                       WHERE l.receivable_account_id = :a AND x.is_voided = 0 AND x.txn_date <= :d', ['a' => $acctId, 'd' => $asOf]));
            $n = (int)DB::value("SELECT COUNT(*) FROM loan_accounts WHERE receivable_account_id = :a AND status IN ('current','past_due','restructured')", ['a' => $acctId]);
            $gl = self::gl($acctId, $asOf);
            $a = $acctName($acctId);
            $rows[] = ['ledger' => 'Loans receivable', 'account_id' => $acctId, 'code' => $a['code'], 'name' => $a['name'], 'sl' => Dec::fix($sl), 'gl' => Dec::fix($gl), 'difference' => Dec::fix(Dec::sub($sl, $gl, 2)), 'count' => $n];
        }

        // accrued interest receivable on loans (the loan ledgers carry what stands in the receivable today)
        if ($asOf >= today()) {
            try {
                $irec = Accts::get('loan_interest_receivable');
                $sl = Dec::of(DB::value("SELECT COALESCE(SUM(interest_accrued),0) FROM loan_accounts WHERE status IN ('current','past_due','restructured')"));
                $gl = self::gl($irec, $asOf);
                $a = $acctName($irec);
                $rows[] = ['ledger' => 'Interest receivable on loans', 'account_id' => $irec, 'code' => $a['code'], 'name' => $a['name'], 'sl' => Dec::fix($sl), 'gl' => Dec::fix($gl), 'difference' => Dec::fix(Dec::sub($sl, $gl, 2)),
                    'count' => (int)DB::value("SELECT COUNT(*) FROM loan_accounts WHERE interest_accrued <> 0 AND status IN ('current','past_due','restructured')")];
            } catch (UserError $e) {
                // the default account is not set: nothing to compare
            }
            // accrued interest payable on time deposits: what was accrued less what the members already withdrew of it
            // before maturity (Withdraw Accrued, CAIW: accrued_paid), one line per accrued-interest account
            try {
                foreach (self::tdAccruedByAccount() as $acctId => $placements) {
                    [$in, $ip] = DB::in('tp', $placements ?: [0]);
                    $sl = Dec::of(DB::value("SELECT COALESCE(SUM(accrued_interest - accrued_paid),0) FROM td_placements WHERE id IN $in", $ip));
                    $gl = Dec::neg(self::gl($acctId, $asOf));
                    $a = $acctName($acctId);
                    $rows[] = ['ledger' => 'Accrued interest on time deposits', 'account_id' => $acctId, 'code' => $a['code'], 'name' => $a['name'], 'sl' => Dec::fix($sl), 'gl' => Dec::fix($gl), 'difference' => Dec::fix(Dec::sub($sl, $gl, 2)),
                        'count' => (int)DB::value("SELECT COUNT(*) FROM td_placements WHERE id IN $in AND accrued_interest - accrued_paid <> 0", $ip)];
                }
            } catch (UserError $e) {
            }
        }
        return array_merge($rows, self::tradeRows($asOf));
    }

    /**
     * accrued-interest account => the time deposits (active or matured) whose interest is accrued there: the product's
     * Accrued interest payable, or the module default. The default account is always listed, even with no deposit.
     */
    private static function tdAccruedByAccount(): array
    {
        try {
            $default = Accts::get('deposit_interest_payable');
        } catch (UserError $e) {
            $default = 0;
        }
        $out = $default ? [$default => []] : [];
        foreach (DB::all("SELECT t.id, p.accrued_account_id FROM td_placements t JOIN sav_accounts a ON a.id = t.account_id JOIN sav_products p ON p.id = a.product_id
                           WHERE t.status IN ('active','matured')") as $r) {
            $acct = (int)($r['accrued_account_id'] ?: $default);
            if ($acct) {
                $out[$acct][] = (int)$r['id'];
            }
        }
        return $out;
    }

    /**
     * Accounts receivable and payable: the open balances of the documents (invoices, statement and finance charges
     * less unapplied payments and credit memos; bills less vendor credits) on the date, against the A/R and A/P
     * control accounts. A journal entry posted to a control account without a document shows as a difference.
     */
    public static function tradeRows(string $asOf): array
    {
        $rows = [];
        foreach (self::TRADE as $ledger => [$docs, $credits, $col, $accts]) {
            $ids = $accts === 'ar' ? Accts::arIds() : Accts::apIds();
            if (!$ids) {
                continue;
            }
            $by = self::tradeSl($docs, $credits, $col, $asOf);
            $sl = Dec::sum(array_values($by));
            [$ain, $ap] = DB::in('ta', $ids);
            $sign = $accts === 'ar' ? 'debit - credit' : 'credit - debit';
            $gl = Dec::of(DB::value("SELECT COALESCE(SUM($sign),0) FROM journal_lines WHERE account_id IN $ain AND txn_date <= :d", $ap + ['d' => $asOf]));
            $a = DB::row('SELECT id, code, name FROM accounts WHERE id = :a', ['a' => $ids[0]]);
            $rows[] = ['ledger' => $ledger, 'account_id' => (int)$a['id'], 'code' => $a['code'] . (count($ids) > 1 ? ' +' . (count($ids) - 1) : ''), 'name' => $a['name'],
                'sl' => Dec::fix($sl), 'gl' => Dec::fix($gl), 'difference' => Dec::fix(Dec::sub($sl, $gl, 2)), 'count' => count(array_filter($by, fn($v) => !Dec::isZero($v)))];
        }
        return $rows;
    }

    /** ledger => [documents, credits, name column, control accounts] */
    private const TRADE = [
        'Accounts receivable (customers)' => [Balances::RECEIVABLE_DOCS, Balances::CUSTOMER_CREDITS, 'customer_id', 'ar'],
        'Accounts payable (suppliers)' => [Balances::PAYABLE_DOCS, Balances::VENDOR_CREDITS, 'vendor_id', 'ap'],
    ];

    /** Open document balances by customer or supplier on a date */
    private static function tradeSl(array $docs, array $credits, string $col, string $asOf): array
    {
        $by = [];
        [$in, $ip] = DB::in('td', $docs);
        foreach (DB::all("SELECT * FROM transactions WHERE txn_type IN $in AND txn_date <= :d AND is_voided = 0", $ip + ['d' => $asOf]) as $t) {
            $k = (int)$t[$col];
            $by[$k] = Dec::add($by[$k] ?? '0', Dec::of(Balances::open($t, $asOf)), 2);
        }
        [$cin, $cp] = DB::in('tc', $credits);
        foreach (DB::all("SELECT * FROM transactions WHERE txn_type IN $cin AND txn_date <= :d AND is_voided = 0", $cp + ['d' => $asOf]) as $t) {
            $k = (int)$t[$col];
            $by[$k] = Dec::sub($by[$k] ?? '0', Dec::of(Balances::creditRemaining($t, $asOf)), 2);
        }
        return $by;
    }

    /** Customers or suppliers whose open documents differ from their ledger balance on the control accounts */
    private static function tradeDiffs(string $ledger, string $asOf): array
    {
        [$docs, $credits, $col, $accts] = self::TRADE[$ledger];
        $ids = $accts === 'ar' ? Accts::arIds() : Accts::apIds();
        [$ain, $ap] = DB::in('ta', $ids ?: [0]);
        $sign = $accts === 'ar' ? 'debit - credit' : 'credit - debit';
        $gl = DB::pairs("SELECT COALESCE($col, 0), SUM($sign) FROM journal_lines WHERE account_id IN $ain AND txn_date <= :d GROUP BY COALESCE($col, 0)", $ap + ['d' => $asOf]);
        $sl = self::tradeSl($docs, $credits, $col, $asOf);
        $out = [];
        $table = $col === 'customer_id' ? 'customers' : 'vendors';
        foreach (array_unique(array_merge(array_keys($sl), array_map('intval', array_keys($gl)))) as $id) {
            $s = $sl[$id] ?? '0';
            $g = Dec::of($gl[$id] ?? '0');
            $diff = Dec::sub($s, $g, 2);
            if (!Dec::isZero($diff)) {
                $name = $id ? (string)DB::value("SELECT name FROM $table WHERE id = :i", ['i' => $id]) : '(no name on the ledger line)';
                $out[] = ['member_id' => 0, 'record_id' => $id, 'record' => $table, 'member' => $name, 'member_no' => ($col === 'customer_id' ? 'C-' : 'V-') . $id, 'sl' => Dec::fix($s), 'gl' => Dec::fix($g), 'difference' => Dec::fix($diff)];
            }
        }
        return $out;
    }

    /** The members whose subsidiary balance differs from their GL balance on one control account (drill-down) */
    public static function memberDiffs(array $row, string $asOf): array
    {
        if (isset(self::TRADE[$row['ledger']])) {
            return self::tradeDiffs($row['ledger'], $asOf);
        }
        $out = [];
        if ($row['ledger'] === 'Loans receivable') {
            $list = DB::all('SELECT l.member_id, m.full_name, m.member_no, m.other_name_id, SUM(x.principal_dr - x.principal_cr) AS sl
                               FROM loan_txns x JOIN loan_accounts l ON l.id = x.loan_id JOIN members m ON m.id = l.member_id
                              WHERE l.receivable_account_id = :a AND x.is_voided = 0 AND x.txn_date <= :d GROUP BY l.member_id, m.full_name, m.member_no, m.other_name_id', ['a' => $row['account_id'], 'd' => $asOf]);
            $sign = '1';
        } elseif (in_array($row['ledger'], ['Share capital', 'Savings deposits', 'Time deposits'], true)) {
            $ids = [];
            foreach (DB::all('SELECT * FROM sav_products') as $p) {
                if (Savings::liabilityAccount($p) === (int)$row['account_id']) {
                    $ids[] = (int)$p['id'];
                }
            }
            if (!$ids) {
                return [];
            }
            [$in, $ip] = DB::in('pr', $ids);
            $list = DB::all("SELECT a.member_id, m.full_name, m.member_no, m.other_name_id, SUM(t.credit - t.debit) AS sl FROM sav_txns t JOIN sav_accounts a ON a.id = t.account_id JOIN members m ON m.id = a.member_id
                              WHERE a.product_id IN $in AND t.is_voided = 0 AND t.txn_date <= :d GROUP BY a.member_id, m.full_name, m.member_no, m.other_name_id", $ip + ['d' => $asOf]);
            $sign = '-1';
        } elseif ($row['ledger'] === 'Accrued interest on time deposits') {
            // each member's accrued interest less what was withdrawn of it, against the member's lines on the account
            $ids = self::tdAccruedByAccount()[(int)$row['account_id']] ?? [];
            if (!$ids) {
                return [];
            }
            [$in, $ip] = DB::in('tp', $ids);
            $list = DB::all("SELECT a.member_id, m.full_name, m.member_no, m.other_name_id, SUM(t.accrued_interest - t.accrued_paid) AS sl FROM td_placements t JOIN sav_accounts a ON a.id = t.account_id
                               JOIN members m ON m.id = a.member_id WHERE t.id IN $in GROUP BY a.member_id, m.full_name, m.member_no, m.other_name_id", $ip);
            $sign = '-1';
        } else {
            return [];
        }
        foreach ($list as $r) {
            $gl = Dec::of(DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND other_name_id = :o AND txn_date <= :d', ['a' => $row['account_id'], 'o' => (int)$r['other_name_id'], 'd' => $asOf]));
            $gl = Dec::mul($gl, $sign, 2);
            $diff = Dec::sub($r['sl'], $gl, 2);
            if (!Dec::isZero($diff)) {
                $out[] = ['member_id' => (int)$r['member_id'], 'member' => $r['full_name'], 'member_no' => $r['member_no'], 'sl' => Dec::fix($r['sl']), 'gl' => Dec::fix($gl), 'difference' => Dec::fix($diff)];
            }
        }
        return $out;
    }

    /** True when every ledger agrees */
    public static function balanced(string $asOf): bool
    {
        foreach (self::slVsGl($asOf) as $r) {
            if (!Dec::isZero($r['difference'])) {
                return false;
            }
        }
        return true;
    }
}
