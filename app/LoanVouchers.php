<?php
declare(strict_types=1);

/**
 * The vouchers of the loan releases. From version 33 a loan is released through Banking, not through a cooperative
 * voucher of its own:
 *   - released in cash   : a Cash Voucher (Banking > Cash Voucher), credited to the cash account chosen on the
 *                          Loan Release Form (a teller drawer, the vault, the revolving fund ...);
 *   - released by cheque : a check (Banking > Write Checks) drawn on the bank account chosen, numbered with the
 *                          cheque no.
 * Each carries the class chosen on the form, on the voucher and on every line. The loan keeps the voucher
 * (loan_accounts.release_txn_id) and its voucher ID (loan_accounts.voucher_no, the LR- number series), so the
 * vouchers of the loan releases are listed together on Loans > Loan Release Vouchers. A release that pays out
 * nothing (all of it used for the charges and a previous loan) stays a loan release voucher (a journal entry).
 *
 * A Banking voucher of a loan release is corrected like any cooperative voucher: by voiding it (the loan is
 * cancelled with it), never by editing or deleting it.
 */
final class LoanVouchers
{
    /** release type => the Banking transaction type */
    public const TYPES = ['cash' => 'cash_voucher', 'cheque' => 'check'];

    public const LABELS = ['cash_voucher' => 'Cash Voucher', 'check' => 'Check Voucher', 'loan_release' => 'Loan Release Voucher'];

    private static array $loanOf = [];

    /** True once the voucher ID of a loan release is kept (upgrade 2027_01_10) */
    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(voucher_no) FROM loan_accounts WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** Cash and bank accounts (chart of accounts, type Bank): [cash => [...], bank => [...]] */
    public static function accounts(): array
    {
        static $out = null;
        if ($out !== null) {
            return $out;
        }
        $cash = [];
        $bank = [];
        foreach (DB::all("SELECT id, code, name, bank_account_no FROM accounts WHERE is_active = 1 AND account_type = 'Bank' ORDER BY code") as $a) {
            $label = $a['code'] . ' - ' . $a['name'];
            // a bank account has its account no. at the bank, or says so in its name (Cash in Bank - ...)
            if (trim((string)$a['bank_account_no']) !== '' || stripos((string)$a['name'], 'bank') !== false) {
                $bank[(int)$a['id']] = $label;
            } else {
                $cash[(int)$a['id']] = $label;
            }
        }
        // a chart that does not tell them apart: every Bank-type account is offered for both
        if (!$cash || !$bank) {
            $cash = $bank = $cash + $bank;
        }
        return $out = ['cash' => $cash, 'bank' => $bank];
    }

    public static function cashAccounts(): array
    {
        return self::accounts()['cash'];
    }

    public static function bankAccounts(): array
    {
        return self::accounts()['bank'];
    }

    /** The loan whose release a transaction is (by its voucher), or null */
    public static function loanOf(int $txnId): ?array
    {
        if (!array_key_exists($txnId, self::$loanOf)) {
            try {
                self::$loanOf[$txnId] = DB::row('SELECT l.*, m.full_name AS member_name, m.member_no, m.passbook_no, p.name AS product_name, p.code AS product_code
                                                   FROM loan_accounts l JOIN members m ON m.id = l.member_id JOIN loan_products p ON p.id = l.product_id
                                                  WHERE l.release_txn_id = :t LIMIT 1', ['t' => $txnId]);
            } catch (Throwable $e) {
                self::$loanOf[$txnId] = null;        // the cooperative tables are not installed
            }
        }
        return self::$loanOf[$txnId];
    }

    public static function forget(int $txnId): void
    {
        unset(self::$loanOf[$txnId]);
    }

    /** A check or a cash voucher (Banking) that is the release of a loan */
    public static function isLoanVoucher(array $t): bool
    {
        return in_array($t['txn_type'] ?? '', self::TYPES, true) && !empty($t['id']) && self::loanOf((int)$t['id']) !== null;
    }

    /** The name of the voucher: Cash Voucher, Check Voucher or Loan Release Voucher */
    public static function label(string $txnType): string
    {
        return self::LABELS[$txnType] ?? Txn::label($txnType);
    }

    /** The voucher ID printed on the voucher: the LR- number of the release (the number of the voucher before version 33) */
    public static function voucherId(array $loan, ?array $t): string
    {
        return (string)(($loan['voucher_no'] ?? '') ?: ($t['txn_no'] ?? ''));
    }

    /**
     * Who approved the loan release: the user who approved the release above the releasing user's limit, else the last
     * approver of the loan application.
     */
    public static function approvedBy(array $loan, ?array $t): string
    {
        $id = $t['approved_by'] ?? null;
        if (!$id && !empty($loan['application_id'])) {
            $id = DB::value("SELECT approver_id FROM loan_approvals WHERE application_id = :a AND action = 'approved' ORDER BY level_no DESC, id DESC LIMIT 1", ['a' => $loan['application_id']]);
        }
        return $id ? (string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $id]) : '';
    }

    /** Who processed the loan release */
    public static function preparedBy(array $loan, ?array $t): string
    {
        $id = ($t['created_by'] ?? null) ?: ($loan['created_by'] ?? null);
        return $id ? (string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $id]) : '';
    }

    /** Where the voucher is opened: Banking for a cash voucher or a check, the loan release page for the older ones */
    public static function url(array $t, string $action = 'view'): string
    {
        if ($t['txn_type'] === 'loan_release') {
            return url('loan.release', ['a' => $action, 'id' => $t['id']]);
        }
        return Txn::url($t, $action);
    }
}
