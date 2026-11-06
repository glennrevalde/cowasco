<?php
declare(strict_types=1);

/**
 * What needs attention in the cooperative today, for the user looking: loans due and past due, time deposits
 * maturing, accounts about to go dormant, documents and collateral expiring, work waiting for their approval,
 * regulatory deadlines, teller shifts left open. Each item links to the page that deals with it, and only items the
 * user may open are listed. The lead days come from Cooperative Profile & Policies.
 */
final class CoopAlerts
{
    /** The next date a calendar item falls due on or after $from (yearly / quarterly / monthly items roll forward) */
    public static function calendarNext(array $c, string $from): string
    {
        $d = $c['due_date'];
        $step = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12][$c['recurrence']] ?? 0;
        if ($step === 0) {
            return $d;
        }
        $guard = 0;
        while ($d < $from && $guard++ < 600) {
            $d = Coop::addMonths($d, $step);
        }
        return $d;
    }

    /** Alerts for the header bell: counted at most every five minutes per session (cheap queries only) */
    public static function count(): int
    {
        if (!Coop::ready()) {
            return 0;
        }
        $c = $_SESSION['coop_alerts'] ?? null;
        if (is_array($c) && $c['at'] > time() - 300 && $c['user'] === Auth::id()) {
            return (int)$c['n'];
        }
        $n = 0;
        try {
            foreach (self::all(false) as $a) {
                if ($a['level'] !== 'info') {
                    $n++;
                }
            }
        } catch (Throwable $e) {
            $n = 0;
        }
        $_SESSION['coop_alerts'] = ['at' => time(), 'user' => Auth::id(), 'n' => $n];
        return $n;
    }

    /**
     * [['level' => bad|warn|info, 'text', 'count', 'amount' (or null), 'link']]; $full adds the ledger reconciliation.
     */
    public static function all(bool $full = true): array
    {
        $out = [];
        $today = today();
        $add = function (string $page, string $level, string $text, int $count, ?string $amount, array $params = []) use (&$out) {
            if ($count > 0 && Access::page($page)) {
                $out[] = ['level' => $level, 'text' => $text, 'count' => $count, 'amount' => $amount, 'link' => url($page, $params)];
            }
        };
        [$bf, $bp] = Coop::branchFilter('l.branch_id', 'abr');
        $days = max(0, (int)Coop::setting('loan_due_alert_days', '7'));
        $r = DB::row("SELECT COUNT(DISTINCT l.id) AS n, COALESCE(SUM(s.total - s.principal_paid - s.interest_paid),0) AS amt FROM loan_schedule s JOIN loan_accounts l ON l.id = s.loan_id
                       WHERE l.status IN ('current','past_due','restructured') AND s.is_paid = 0 AND s.due_date BETWEEN :a AND :b" . $bf, ['a' => $today, 'b' => Coop::addDays($today, $days)] + $bp);
        $add('loan.collection', 'info', 'Loans with an amortization due in the next ' . $days . ' days', (int)$r['n'], Dec::fix($r['amt']));
        $r = DB::row("SELECT COUNT(DISTINCT l.id) AS n, COALESCE(SUM(s.total - s.principal_paid - s.interest_paid),0) AS amt FROM loan_schedule s JOIN loan_accounts l ON l.id = s.loan_id
                       WHERE l.status IN ('current','past_due','restructured') AND s.is_paid = 0 AND s.due_date < :a" . $bf, ['a' => $today] + $bp);
        $add('loan.accounts', 'bad', 'Loans past due (amount in arrears)', (int)$r['n'], Dec::fix($r['amt']), ['status' => 'past_due']);
        $n = (int)DB::value("SELECT COUNT(*) FROM loan_accounts l WHERE l.status IN ('current','past_due','restructured') AND l.due_date < :a" . $bf, ['a' => $today] + $bp);
        $add('loan.accounts', 'bad', 'Loans past their maturity date, still unpaid', $n, null, ['status' => 'matured']);

        [$sf, $sp] = Coop::branchFilter('a.branch_id', 'sbr');
        $tdDays = max(0, (int)Coop::setting('td_maturity_alert_days', '15'));
        $r = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(t.principal),0) AS amt FROM td_placements t JOIN sav_accounts a ON a.id = t.account_id WHERE t.status = 'active' AND t.maturity_date <= :b" . $sf,
            ['b' => Coop::addDays($today, $tdDays)] + $sp);
        $add('sav.td', 'warn', 'Time deposits maturing within ' . $tdDays . ' days (or matured)', (int)$r['n'], Dec::fix($r['amt']));
        $dormDays = max(0, (int)Coop::setting('dormancy_alert_days', '30'));
        $n = (int)DB::value("SELECT COUNT(*) FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id
                              WHERE a.status = 'active' AND p.dormancy_months > 0 AND a.last_txn_date IS NOT NULL
                                AND DATE_ADD(a.last_txn_date, INTERVAL p.dormancy_months MONTH) BETWEEN :a AND :b" . $sf, ['a' => $today, 'b' => Coop::addDays($today, $dormDays)] + $sp);
        $add('sav.interest', 'info', 'Savings accounts that become dormant within ' . $dormDays . ' days', $n, null);

        $docDays = max(0, (int)Coop::setting('doc_expiry_alert_days', '30'));
        [$mf, $mp] = Coop::branchFilter('m.branch_id', 'mbr');
        $n = (int)DB::value("SELECT COUNT(*) FROM member_documents d JOIN members m ON m.id = d.member_id WHERE d.expires_on IS NOT NULL AND d.expires_on <= :b AND m.status IN ('active','applicant')" . $mf,
            ['b' => Coop::addDays($today, $docDays)] + $mp);
        $add('coop.members', 'warn', 'Member documents expiring within ' . $docDays . ' days or expired', $n, null);
        $n = (int)DB::value("SELECT COUNT(*) FROM loan_collaterals c JOIN loan_accounts l ON l.application_id = c.application_id
                              WHERE l.status IN ('current','past_due','restructured') AND c.status = 'held' AND ((c.expiry_date IS NOT NULL AND c.expiry_date <= :b) OR (c.insurance_expiry IS NOT NULL AND c.insurance_expiry <= :b2))" . $bf,
            ['b' => Coop::addDays($today, $docDays), 'b2' => Coop::addDays($today, $docDays)] + $bp);
        $add('loan.accounts', 'warn', 'Collateral documents or insurance expiring within ' . $docDays . ' days or expired', $n, null);

        // work waiting for this user's approval
        if (Access::can('loan.applications', 'approve')) {
            [$af, $ap] = Coop::branchFilter('a.branch_id', 'apb');
            $add('loan.applications', 'warn', 'Loan applications waiting for approval', (int)DB::value("SELECT COUNT(*) FROM loan_applications a WHERE a.status = 'for_approval'" . $af, $ap), null, ['status' => 'for_approval']);
        }
        if (Access::can('loan.release', 'create')) {
            [$af, $ap] = Coop::branchFilter('a.branch_id', 'arb');
            $add('loan.release', 'info', 'Approved loans waiting for release', (int)DB::value("SELECT COUNT(*) FROM loan_applications a WHERE a.status = 'approved'" . $af, $ap), null);
        }
        if (Access::can('loan.waivers', 'approve')) {
            $add('loan.waivers', 'warn', 'Waivers waiting for approval', (int)DB::value("SELECT COUNT(*) FROM loan_waivers WHERE status = 'pending' AND requested_by <> :u", ['u' => Auth::id()]), null);
        }
        if (Access::can('sav.accounts', 'approve')) {
            $add('sav.accounts', 'warn', 'Savings accounts waiting for approval', (int)DB::value("SELECT COUNT(*) FROM sav_accounts a WHERE a.status = 'pending'" . $sf, $sp), null, ['status' => 'pending']);
        }
        if (Access::can('coop.members', 'approve') || Access::can('coop.member', 'approve')) {
            $add('coop.members', 'info', 'Membership applicants', (int)DB::value("SELECT COUNT(*) FROM members m WHERE m.status = 'applicant'" . $mf, $mp), null, ['status' => 'applicant']);
        }
        if (Access::can('dist.isc', 'approve') || Access::can('dist.pr', 'approve')) {
            $add('dist.batches', 'warn', 'Distribution batches waiting for approval', (int)DB::value("SELECT COUNT(*) FROM dist_batches WHERE status = 'validated' AND created_by <> :u", ['u' => Auth::id()]), null, ['status' => 'validated']);
        }

        // regulatory deadlines
        $cal = 0;
        foreach (DB::all('SELECT * FROM cda_calendar WHERE is_active = 1') as $c) {
            $next = self::calendarNext($c, $today);
            if ($next <= Coop::addDays($today, (int)$c['remind_days'])) {
                $cal++;
            }
        }
        $add('cda.calendar', 'warn', 'Regulatory deadlines coming up', $cal, null);
        $add('cda.compliance', 'bad', 'Regulatory submissions overdue', (int)DB::value("SELECT COUNT(*) FROM cda_compliance WHERE status = 'pending' AND due_date IS NOT NULL AND due_date < :a", ['a' => $today]), null);

        // teller shifts left open from an earlier business date
        if (Access::page('teller.shift')) {
            $own = DB::value("SELECT COUNT(*) FROM teller_shifts WHERE user_id = :u AND status = 'open' AND business_date < :a", ['u' => Auth::id(), 'a' => $today]);
            $add('teller.shift', 'bad', 'Your teller shift from an earlier day is still open', (int)$own, null);
        }
        if (Access::can('teller.shift', 'approve')) {
            $add('teller.shift', 'warn', 'Teller shifts of other users left open from an earlier day',
                (int)DB::value("SELECT COUNT(*) FROM teller_shifts WHERE status = 'open' AND business_date < :a AND user_id <> :u", ['a' => $today, 'u' => Auth::id()]), null);
        }

        if ($full && Access::page('rpt.coop_sl_gl')) {
            $off = array_filter(CoopRecon::slVsGl($today), fn($x) => !Dec::isZero($x['difference']));
            $add('rpt.coop_sl_gl', 'bad', 'Member ledgers that do not agree with the general ledger', count($off), null);
        }
        $rank = ['bad' => 0, 'warn' => 1, 'info' => 2];
        usort($out, fn($a, $b) => $rank[$a['level']] <=> $rank[$b['level']]);
        return $out;
    }

    /** The headline figures for the dashboard */
    public static function kpis(): array
    {
        $today = today();
        $loans = Dec::of(DB::value("SELECT COALESCE(SUM(principal_balance),0) FROM loan_accounts WHERE status IN ('current','past_due','restructured')"));
        $pd = Dec::of(DB::value("SELECT COALESCE(SUM(principal_balance),0) FROM loan_accounts WHERE status = 'past_due'"));
        return [
            'members' => (int)DB::value("SELECT COUNT(*) FROM members WHERE status IN ('active','suspended')"),
            'applicants' => (int)DB::value("SELECT COUNT(*) FROM members WHERE status = 'applicant'"),
            'share_capital' => Dec::of(DB::value("SELECT COALESCE(SUM(a.balance),0) FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE p.product_type = 'share_capital' AND a.status <> 'closed'")),
            'savings' => Dec::of(DB::value("SELECT COALESCE(SUM(a.balance),0) FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE p.product_type IN ('ordinary','special') AND a.status <> 'closed'")),
            'time_deposits' => Dec::of(DB::value("SELECT COALESCE(SUM(principal),0) FROM td_placements WHERE status IN ('active','matured')")),
            'loans' => $loans,
            'loans_count' => (int)DB::value("SELECT COUNT(*) FROM loan_accounts WHERE status IN ('current','past_due','restructured')"),
            'par' => Dec::isPos($loans) ? Dec::fix(Dec::div(Dec::mul($pd, '100', 6), $loans, 6), 2) : '0.00',
            'collections_today' => Dec::of(DB::value("SELECT COALESCE(SUM(principal_cr + interest_cr + penalty_cr),0) FROM loan_txns WHERE kind = 'payment' AND is_voided = 0 AND txn_id IS NOT NULL AND txn_date = :d", ['d' => $today])),
            'releases_month' => Dec::of(DB::value('SELECT COALESCE(SUM(l.principal),0) FROM loan_accounts l JOIN transactions t ON t.id = l.release_txn_id WHERE t.is_voided = 0 AND l.release_date BETWEEN :a AND :b', ['a' => date('Y-m-01'), 'b' => $today])),
        ];
    }
}
