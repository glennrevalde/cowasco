<?php
declare(strict_types=1);

/**
 * Changes to a journal entry that is already saved: maker and checker.
 *
 * A user with the Edit right on Make Journal Entries prepares the change on the entry's own form and writes why it
 * is needed. The change is not made then: it waits as a request until a second user, one with the Approve right on
 * Make Journal Entries, compares the entry as it is with the entry as proposed and approves it (the entry is then
 * saved with the change, through the posting engine, the closing date and the audit trail like any save) or rejects
 * it with the reason. A supervisor who is at the desk can approve it on the spot with their own username and password
 * or a one-time approval code. The requester never approves their own change.
 *
 * Every request stays on file with the entry: who asked, why, the entry before and after, who decided and when.
 */
final class JournalEdits
{
    public const TYPES = ['journal', 'opening_balance'];
    public const PAGE = 'coa.journal';
    public const STATUSES = [
        'pending'    => 'Waiting for approval',
        'approved'   => 'Approved and applied',
        'rejected'   => 'Rejected',
        'withdrawn'  => 'Withdrawn',
        'superseded' => 'Replaced by a later request',
    ];
    /** an explanation shorter than this is not an explanation */
    public const MIN_EXPLANATION = 10;

    private static ?bool $ready = null;

    /** False until the upgrade that adds the requests table (2026_12_20) has been run */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                DB::value('SELECT COUNT(*) FROM journal_edit_requests WHERE 1 = 0');
                self::$ready = true;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function get(int $id): ?array
    {
        return DB::row('SELECT r.*, ur.full_name AS requester, ud.full_name AS decider, t.txn_no, t.txn_type, t.txn_date
                          FROM journal_edit_requests r JOIN transactions t ON t.id = r.txn_id
                          LEFT JOIN users ur ON ur.id = r.requested_by LEFT JOIN users ud ON ud.id = r.decided_by
                         WHERE r.id = :id', ['id' => $id]);
    }

    /** The request still waiting on an entry, if any */
    public static function pendingFor(int $txnId): ?array
    {
        if (!self::ready()) {
            return null;
        }
        $id = DB::value("SELECT id FROM journal_edit_requests WHERE txn_id = :t AND status = 'pending' ORDER BY id DESC LIMIT 1", ['t' => $txnId]);
        return $id ? self::get((int)$id) : null;
    }

    /** Every request made on an entry, newest first */
    public static function history(int $txnId): array
    {
        if (!self::ready()) {
            return [];
        }
        return DB::all('SELECT r.*, ur.full_name AS requester, ud.full_name AS decider FROM journal_edit_requests r
                          LEFT JOIN users ur ON ur.id = r.requested_by LEFT JOIN users ud ON ud.id = r.decided_by
                         WHERE r.txn_id = :t ORDER BY r.id DESC', ['t' => $txnId]);
    }

    public static function pendingCount(): int
    {
        return self::ready() ? (int)DB::value("SELECT COUNT(*) FROM journal_edit_requests WHERE status = 'pending'") : 0;
    }

    /** True when this user may approve or reject a request (the Approve right on Make Journal Entries) */
    public static function canDecide(?int $userId = null): bool
    {
        return Access::can(self::PAGE, 'approve', $userId);
    }

    // ------------------------------------------------------------------ the entry as it is, as one comparable record

    /** The entry as saved now: header and lines, in the shape the comparison and the hash use */
    public static function snapshot(int $txnId): array
    {
        $t = Txn::load($txnId);
        $lines = [];
        foreach (Txn::lines($txnId) as $l) {
            if (($l['line_kind'] ?? 'journal') !== 'journal') {
                continue;
            }
            $lines[] = [
                'account_id' => (int)$l['account_id'], 'amount' => r2((float)$l['amount']), 'description' => (string)($l['description'] ?? ''),
                'customer_id' => $l['customer_id'] ? (int)$l['customer_id'] : null, 'vendor_id' => $l['vendor_id'] ? (int)$l['vendor_id'] : null,
                'other_name_id' => !empty($l['other_name_id']) ? (int)$l['other_name_id'] : null, 'class_id' => !empty($l['class_id']) ? (int)$l['class_id'] : null,
            ];
        }
        return [
            'type' => (string)$t['txn_type'],
            'header' => ['txn_date' => (string)$t['txn_date'], 'txn_no' => (string)$t['txn_no'], 'ref_no' => (string)($t['ref_no'] ?? ''), 'memo' => (string)($t['memo'] ?? ''),
                'class_id' => !empty($t['class_id']) ? (int)$t['class_id'] : null, 'business_unit_id' => !empty($t['business_unit_id']) ? (int)$t['business_unit_id'] : null],
            'lines' => $lines,
        ];
    }

    private static function hash(array $snap): string
    {
        return md5(json_encode($snap));
    }

    /** The proposed change in the same shape as snapshot() */
    private static function proposal(string $type, array $h, array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $out[] = [
                'account_id' => (int)$l['account_id'], 'amount' => r2((float)$l['amount']), 'description' => (string)($l['description'] ?? ''),
                'customer_id' => !empty($l['customer_id']) ? (int)$l['customer_id'] : null, 'vendor_id' => !empty($l['vendor_id']) ? (int)$l['vendor_id'] : null,
                'other_name_id' => !empty($l['other_name_id']) ? (int)$l['other_name_id'] : null, 'class_id' => !empty($l['class_id']) ? (int)$l['class_id'] : null,
            ];
        }
        return [
            'type' => $type,
            'header' => ['txn_date' => (string)$h['txn_date'], 'txn_no' => (string)($h['txn_no'] ?? ''), 'ref_no' => (string)($h['ref_no'] ?? ''), 'memo' => (string)($h['memo'] ?? ''),
                'class_id' => !empty($h['class_id']) ? (int)$h['class_id'] : null, 'business_unit_id' => !empty($h['business_unit_id']) ? (int)$h['business_unit_id'] : null],
            'lines' => $out,
        ];
    }

    // ------------------------------------------------------------------ the second person

    /**
     * The supervisor approving on the spot: their username and password, or a one-time approval code, typed on the
     * form. Null when nothing was typed. The approver has the Approve right on Make Journal Entries and is not the
     * user making the change.
     */
    public static function approverOnForm(string $what): ?array
    {
        $code = trim((string)($_POST['approver_code'] ?? ''));
        $username = trim((string)($_POST['approver_username'] ?? ''));
        if ($code === '' && $username === '') {
            return null;
        }
        if ($code !== '') {
            $u = ApprovalCode::redeem($code, self::PAGE, $what);
        } else {
            $u = Auth::verifyOther($username, (string)($_POST['approver_password'] ?? ''));
            if (!$u) {
                Audit::log('approval_failed', 'security', 'user', null, $username, ['reason' => $what]);
                $GLOBALS['NEEDS_APPROVAL'] = $what;
                fail('Approval failed: the approver username or password is wrong.');
            }
        }
        if ((int)$u['id'] === Auth::id()) {
            fail('A change to a journal entry is approved by someone other than the user who makes it.');
        }
        if (!self::canDecide((int)$u['id'])) {
            $GLOBALS['NEEDS_APPROVAL'] = $what;
            fail($u['username'] . ' may not approve changes to journal entries (the Approve right on Make Journal Entries).');
        }
        return $u;
    }

    // ------------------------------------------------------------------ request, approve, reject, withdraw

    /**
     * Files the change as a request. A request already waiting on the entry is replaced by this one. Returns the id.
     */
    public static function request(array $t, string $type, array $h, array $lines, string $explanation): int
    {
        if (!self::ready()) {
            fail('Run the upgrade script first: changes to journal entries need their approval table.');
        }
        $explanation = trim(preg_replace('/\s+/', ' ', $explanation));
        if (mb_strlen($explanation) < self::MIN_EXPLANATION) {
            fail('Explain why the journal entry is changed (at least ' . self::MIN_EXPLANATION . ' characters): the approver and the auditors read it.');
        }
        if (!in_array($t['txn_type'], self::TYPES, true) || (int)$t['is_voided'] === 1) {
            fail('Only a journal entry that is not void can be changed here.');
        }
        $before = self::snapshot((int)$t['id']);
        $after = self::proposal($type, $h, $lines);
        // what the form does not send stays as it is on the entry (its number, line of business and class)
        if ($after['header']['txn_no'] === '') {
            $after['header']['txn_no'] = $before['header']['txn_no'];
        }
        foreach (['business_unit_id', 'class_id'] as $k) {
            if (!array_key_exists($k, $h)) {
                $after['header'][$k] = $before['header'][$k];
            }
        }
        if (self::hash($before) === self::hash($after)) {
            fail('Nothing was changed on the entry.');
        }
        // the closing date: a change into or out of a closed period is refused before anybody approves it
        Txn::assertPeriod((string)$after['header']['txn_date'], $t);
        return DB::tx(function () use ($t, $before, $after, $explanation) {
            DB::run("UPDATE journal_edit_requests SET status = 'superseded', decided_at = NOW() WHERE txn_id = :t AND status = 'pending'", ['t' => $t['id']]);
            $id = DB::insert('journal_edit_requests', [
                'txn_id' => (int)$t['id'], 'status' => 'pending', 'explanation' => mb_substr($explanation, 0, 1000),
                'before_json' => json_encode($before, JSON_UNESCAPED_UNICODE), 'proposed_json' => json_encode($after, JSON_UNESCAPED_UNICODE),
                'before_hash' => self::hash($before), 'requested_by' => Auth::id(), 'requested_at' => date('Y-m-d H:i:s'),
            ]);
            Audit::log('request', 'coa', 'journal_edit', $id, Txn::ref($t), ['page_key' => self::PAGE, 'reason' => $explanation]);
            return $id;
        });
    }

    /** Approves a request and saves the entry with the change. $approver is the approving user (default: the one logged in). */
    public static function approve(int $id, string $remarks = '', ?array $approver = null): int
    {
        $approver = $approver ?? Auth::user();
        return DB::tx(function () use ($id, $remarks, $approver) {
            $r = DB::row('SELECT * FROM journal_edit_requests WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (!$r || $r['status'] !== 'pending') {
                fail('The request is no longer waiting for approval.');
            }
            if ((int)$r['requested_by'] === (int)$approver['id']) {
                fail('A change to a journal entry is approved by someone other than the user who asked for it.');
            }
            if (!self::canDecide((int)$approver['id'])) {
                fail('Approving changes to journal entries needs the Approve right on Make Journal Entries.');
            }
            $t = Txn::load((int)$r['txn_id']);
            if (!$t || (int)$t['is_voided'] === 1) {
                fail('The journal entry was voided since the change was requested.');
            }
            if (self::hash(self::snapshot((int)$t['id'])) !== $r['before_hash']) {
                fail('The journal entry was changed since this request was made. Reject it and ask for the change again on the entry as it is now.');
            }
            $p = json_decode((string)$r['proposed_json'], true);
            $h = ['txn_date' => $p['header']['txn_date'], 'memo' => $p['header']['memo'] !== '' ? $p['header']['memo'] : null, 'ref_no' => $p['header']['ref_no'] !== '' ? $p['header']['ref_no'] : null,
                'status' => 'open', 'class_id' => $p['header']['class_id'], 'business_unit_id' => $p['header']['business_unit_id']];
            if ($p['header']['txn_no'] !== '') {
                $h['txn_no'] = $p['header']['txn_no'];
            }
            if (!Classes::ready()) {
                unset($h['class_id']);
            }
            if (!BusinessUnits::ready()) {
                unset($h['business_unit_id']);
            }
            $lines = [];
            foreach ($p['lines'] as $l) {
                $line = ['line_kind' => 'journal', 'account_id' => (int)$l['account_id'], 'amount' => (float)$l['amount'], 'description' => $l['description'] !== '' ? $l['description'] : null,
                    'customer_id' => $l['customer_id'], 'vendor_id' => $l['vendor_id'], 'other_name_id' => $l['other_name_id'], 'class_id' => $l['class_id']];
                if (!Classes::ready()) {
                    unset($line['class_id'], $line['other_name_id']);
                }
                $lines[] = $line;
            }
            $requester = (string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $r['requested_by']]);
            $reason = mb_substr('Change requested by ' . $requester . ': ' . $r['explanation'] . '. Approved by ' . $approver['full_name'] . ($remarks !== '' ? ': ' . $remarks : ''), 0, 255);
            Txn::save((string)$p['type'], $h, $lines, (int)$t['id'], ['reason' => $reason, 'ledger_change_ok' => true, 'approved_by' => $approver['username'] ?? null, 'business_unit_id' => $h['business_unit_id'] ?? null]);
            DB::update('journal_edit_requests', ['status' => 'approved', 'decided_by' => (int)$approver['id'], 'decided_at' => date('Y-m-d H:i:s'), 'decision_remarks' => mb_substr($remarks, 0, 255) ?: null], 'id = :id', ['id' => $id]);
            Audit::log('approve', 'coa', 'journal_edit', $id, Txn::ref($t), ['page_key' => self::PAGE, 'reason' => $remarks !== '' ? $remarks : $r['explanation'], 'approved_by' => $approver['username'] ?? null]);
            return (int)$t['id'];
        });
    }

    public static function reject(int $id, string $remarks): void
    {
        $remarks = trim($remarks);
        if ($remarks === '') {
            fail('Write why the change is rejected: the user who asked for it reads it.');
        }
        DB::tx(function () use ($id, $remarks) {
            $r = DB::row('SELECT * FROM journal_edit_requests WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (!$r || $r['status'] !== 'pending') {
                fail('The request is no longer waiting for approval.');
            }
            if (!self::canDecide()) {
                fail('Rejecting changes to journal entries needs the Approve right on Make Journal Entries.');
            }
            DB::update('journal_edit_requests', ['status' => 'rejected', 'decided_by' => Auth::id(), 'decided_at' => date('Y-m-d H:i:s'), 'decision_remarks' => mb_substr($remarks, 0, 255)], 'id = :id', ['id' => $id]);
            Audit::log('reject', 'coa', 'journal_edit', $id, (string)DB::value('SELECT txn_no FROM transactions WHERE id = :t', ['t' => $r['txn_id']]), ['page_key' => self::PAGE, 'reason' => $remarks]);
        });
    }

    /** The requester takes their request back before it is decided */
    public static function withdraw(int $id): void
    {
        DB::tx(function () use ($id) {
            $r = DB::row('SELECT * FROM journal_edit_requests WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (!$r || $r['status'] !== 'pending') {
                fail('The request is no longer waiting for approval.');
            }
            if ((int)$r['requested_by'] !== Auth::id() && !Access::isAdmin()) {
                fail('Only the user who asked for the change can withdraw it.');
            }
            DB::update('journal_edit_requests', ['status' => 'withdrawn', 'decided_by' => Auth::id(), 'decided_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            Audit::log('cancel', 'coa', 'journal_edit', $id, (string)DB::value('SELECT txn_no FROM transactions WHERE id = :t', ['t' => $r['txn_id']]), ['page_key' => self::PAGE, 'reason' => 'Request withdrawn']);
        });
    }

    // ------------------------------------------------------------------ showing a request

    /** Lines of a snapshot as display rows: account, name, class, debit, credit, description */
    public static function displayLines(array $snap): array
    {
        $out = [];
        foreach ($snap['lines'] ?? [] as $l) {
            $name = Names::label(['customer_id' => $l['customer_id'], 'vendor_id' => $l['vendor_id'], 'other_name_id' => $l['other_name_id']]);
            $a = DB::row('SELECT code, name FROM accounts WHERE id = :id', ['id' => $l['account_id']]);
            $out[] = ['account' => $a ? $a['code'] . ' - ' . $a['name'] : '#' . $l['account_id'], 'name' => $name, 'class' => $l['class_id'] ? Classes::name($l['class_id']) : '',
                'debit' => $l['amount'] > 0 ? (float)$l['amount'] : 0.0, 'credit' => $l['amount'] < 0 ? -(float)$l['amount'] : 0.0, 'description' => (string)$l['description']];
        }
        return $out;
    }

    /** The header fields that differ between two snapshots: label => [before, after] */
    public static function headerChanges(array $before, array $after): array
    {
        $labels = ['txn_date' => 'Date', 'txn_no' => 'Entry no.', 'ref_no' => 'Reference', 'memo' => 'Memo', 'class_id' => 'Class', 'business_unit_id' => 'Line of business'];
        $show = function (string $k, $v): string {
            if ($v === null || $v === '') {
                return '(blank)';
            }
            return match ($k) {
                'txn_date' => fdate((string)$v),
                'class_id' => Classes::name((int)$v),
                'business_unit_id' => BusinessUnits::ready() ? BusinessUnits::name((int)$v) : (string)$v,
                default => (string)$v,
            };
        };
        $out = [];
        if (($before['type'] ?? '') !== ($after['type'] ?? '')) {
            $out['Kind'] = [Txn::label((string)$before['type']), Txn::label((string)$after['type'])];
        }
        foreach ($labels as $k => $label) {
            $b = $before['header'][$k] ?? null;
            $a = $after['header'][$k] ?? null;
            if ((string)$b !== (string)$a && !($k === 'txn_no' && (string)$a === '')) {
                $out[$label] = [$show($k, $b), $show($k, $a)];
            }
        }
        return $out;
    }

    /** "before / after" of a request as HTML: header changes and the two sets of lines side by side */
    public static function comparison(array $r): string
    {
        $before = json_decode((string)$r['before_json'], true) ?: [];
        $after = json_decode((string)$r['proposed_json'], true) ?: [];
        $html = '';
        $hc = self::headerChanges($before, $after);
        if ($hc) {
            $html .= '<div class="table-responsive mb-3"><table class="table table-sm mb-0 je-diff-head"><thead><tr><th>Field</th><th>Now</th><th>Proposed</th></tr></thead><tbody>';
            foreach ($hc as $label => [$b, $a]) {
                $html .= '<tr><td>' . h($label) . '</td><td>' . h($b) . '</td><td class="je-new">' . h($a) . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        // the proposed side marks what differs from the entry now, line by line (a line added at the end is new)
        $table = function (array $snap, string $title, string $cls, ?array $compare = null) {
            $rows = self::displayLines($snap);
            $was = $compare !== null ? self::displayLines($compare) : null;
            $dr = array_sum(array_column($rows, 'debit'));
            $cr = array_sum(array_column($rows, 'credit'));
            $mark = function (int $i, string $k) use ($was, $rows): string {
                if ($was === null) {
                    return '';
                }
                if (!isset($was[$i])) {
                    return ' je-changed';
                }
                $a = $rows[$i][$k];
                $b = $was[$i][$k];
                return is_float($a) || is_float($b) ? (abs((float)$a - (float)$b) >= 0.005 ? ' je-changed' : '') : ((string)$a !== (string)$b ? ' je-changed' : '');
            };
            $out = '<div class="col-12 col-xxl-6"><div class="je-diff-side ' . $cls . '"><div class="je-diff-title">' . h($title) . '</div><div class="table-responsive"><table class="table table-sm mb-0">'
                . '<thead><tr><th>Account</th><th>Name</th><th>Class</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>';
            foreach ($rows as $i => $x) {
                $out .= '<tr><td class="je-acct' . $mark($i, 'account') . '">' . h($x['account']) . ($x['description'] !== '' ? '<div class="small text-muted">' . h($x['description']) . '</div>' : '') . '</td>'
                    . '<td class="small' . $mark($i, 'name') . '">' . h($x['name']) . '</td>'
                    . '<td class="small' . $mark($i, 'class') . '">' . h($x['class']) . '</td>'
                    . '<td class="num' . $mark($i, 'debit') . '">' . ($x['debit'] ? money($x['debit']) : '') . '</td>'
                    . '<td class="num' . $mark($i, 'credit') . '">' . ($x['credit'] ? money($x['credit']) : '') . '</td></tr>';
            }
            if ($was !== null && count($was) > count($rows)) {
                $out .= '<tr><td colspan="5" class="small je-changed">' . (count($was) - count($rows)) . ' line(s) of the entry now are taken out</td></tr>';
            }
            return $out . '</tbody><tfoot><tr><td colspan="3" class="fw-semibold">Totals</td><td class="num fw-semibold">' . money($dr) . '</td><td class="num fw-semibold">' . money($cr) . '</td></tr></tfoot></table></div></div></div>';
        };
        return $html . '<div class="row g-3">' . $table($before, 'The entry now', 'is-before') . $table($after, 'As proposed', 'is-after', $before) . '</div>'
            . '<div class="small text-muted mt-2 je-diff-key"><span class="je-changed">Highlighted</span> values are what the change alters.</div>';
    }

    /** The notice on the entry: a change waiting for approval, and the changes made before */
    public static function panel(array $t): string
    {
        if (!self::ready() || !in_array($t['txn_type'], self::TYPES, true)) {
            return '';
        }
        $html = '';
        $pending = self::pendingFor((int)$t['id']);
        if ($pending) {
            $html .= '<div class="alert alert-warning d-flex flex-wrap gap-2 align-items-center justify-content-between"><div><i class="bi bi-hourglass-split"></i> '
                . 'A change to this entry is waiting for approval: requested by ' . h((string)$pending['requester']) . ', ' . h(fdatetime($pending['requested_at'])) . '. '
                . '<span class="text-muted">' . h($pending['explanation']) . '</span></div>'
                . (Access::page('coa.je_edits') ? '<a class="btn btn-sm btn-outline-dark" href="' . h(url('coa.je_edits', ['id' => $pending['id']])) . '">Review the change</a>' : '') . '</div>';
        }
        $done = array_values(array_filter(self::history((int)$t['id']), fn($r) => $r['status'] === 'approved'));
        if ($done) {
            $html .= '<details class="card mb-3 je-history"><summary class="card-header">Changes made to this entry (' . count($done) . ')</summary><div class="table-responsive"><table class="table table-sm mb-0">'
                . '<thead><tr><th>Requested</th><th>Why</th><th>Approved</th></tr></thead><tbody>';
            foreach ($done as $r) {
                $html .= '<tr><td class="small">' . h((string)$r['requester']) . '<div class="text-muted">' . h(fdatetime($r['requested_at'])) . '</div></td><td class="small">' . h($r['explanation']) . '</td>'
                    . '<td class="small">' . h((string)$r['decider']) . '<div class="text-muted">' . h(fdatetime((string)$r['decided_at'])) . '</div>'
                    . (Access::page('coa.je_edits') ? '<a href="' . h(url('coa.je_edits', ['id' => $r['id']])) . '">Before and after</a>' : '') . '</td></tr>';
            }
            $html .= '</tbody></table></div></details>';
        }
        return $html;
    }
}
