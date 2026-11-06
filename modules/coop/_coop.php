<?php
declare(strict_types=1);

/**
 * Shared pieces of the cooperative screens: the page frame, the member picker and member panel, status words,
 * the voucher view / print / void used by every cooperative transaction page, print headers and the document
 * print log (reprints are counted and need a reason).
 */
require_once APP_ROOT . '/modules/txn/_common.php';

Coop::requireReady($PAGE['name'] ?? 'Cooperative');

/** layout_start with the cooperative script */
function coop_layout(string $title, array $opt = []): void
{
    $opt['js'] = array_merge((array)($opt['js'] ?? []), ['js/coop.js']);
    layout_start($title, $opt);
}

function cm($v, bool $blankZero = false): string
{
    return Dec::money($v ?? '0', $blankZero);
}

/** The status of a member / account / loan as a coloured word (never a capsule) */
function coop_status(?string $s): string
{
    $s = (string)$s;
    $map = [
        'applicant' => ['Applicant', 'warning'], 'active' => ['Active', 'success'], 'suspended' => ['Suspended', 'warning'], 'resigned' => ['Resigned', 'secondary'],
        'terminated' => ['Terminated', 'danger'], 'deceased' => ['Deceased', 'dark'], 'pending' => ['Waiting for approval', 'warning'], 'dormant' => ['Dormant', 'warning'],
        'closed' => ['Closed', 'secondary'], 'current' => ['Current', 'success'], 'past_due' => ['Past due', 'danger'], 'paid' => ['Fully paid', 'secondary'],
        'restructured' => ['Restructured', 'warning'], 'renewed' => ['Renewed', 'secondary'], 'written_off' => ['Written off', 'dark'], 'cancelled' => ['Cancelled', 'secondary'],
        'new' => ['New', 'primary'], 'validated' => ['Validated', 'info'], 'for_ci' => ['For credit investigation', 'info'], 'for_approval' => ['For approval', 'warning'],
        'approved' => ['Approved', 'success'], 'disapproved' => ['Disapproved', 'danger'], 'returned' => ['Returned for correction', 'warning'], 'released' => ['Released', 'secondary'],
        'draft' => ['Draft', 'primary'], 'posted' => ['Posted', 'success'], 'voided' => ['Voided', 'dark'], 'void' => ['Void', 'dark'], 'open' => ['Open', 'primary'],
        'matured' => ['Matured', 'warning'], 'renewed_td' => ['Renewed', 'secondary'], 'preterminated' => ['Pre-terminated', 'secondary'],
        'rejected' => ['Rejected', 'danger'], 'submitted' => ['Submitted', 'success'], 'late' => ['Submitted late', 'warning'], 'not_applicable' => ['Not applicable', 'secondary'],
        'accepted' => ['Accepted', 'success'],
    ];
    [$label, $color] = $map[$s] ?? [ucwords(str_replace('_', ' ', $s)), 'secondary'];
    return badge($label, $color);
}

/** Tabs as links (each tab is its own URL, so it can be bookmarked and the back button works) */
function coop_tabs(array $tabs, string $current, string $pageKey, array $params = []): string
{
    $html = '<ul class="nav nav-tabs mb-3 no-print">';
    foreach ($tabs as $key => $label) {
        $html .= '<li class="nav-item"><a class="nav-link' . ($key === $current ? ' active' : '') . '" href="' . h(url($pageKey, $params + ['tab' => $key])) . '">' . h($label) . '</a></li>';
    }
    return $html . '</ul>';
}

/** Label / value pairs in a compact grid */
function coop_kv(array $pairs, string $cls = ''): string
{
    $html = '<dl class="kv ' . h($cls) . '">';
    foreach ($pairs as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        $html .= '<div><dt>' . h($k) . '</dt><dd>' . $v . '</dd></div>';
    }
    return $html . '</dl>';
}

/** A money input: right-aligned, decimal keyboard on phones, formatted when the field is left */
function coop_money(string $name, string $label, $value, array $attr = []): string
{
    $attr['extra'] = trim(($attr['extra'] ?? '') . ' inputmode="decimal" data-money autocomplete="off"');
    $attr['class'] = ($attr['class'] ?? 'col-md-4');
    $v = $value === null || $value === '' ? '' : (is_post() ? (string)$value : Dec::money($value));
    return input_field($name, $label, $v, $attr);
}

/** Where Capacity to pay of a new loan application came from (Loans::capacityStart()) */
function coop_capacity_note(array $c): string
{
    if ($c['source'] === 'application') {
        return 'Filled from the borrower\'s last loan application, ' . $c['app_no'] . ($c['date'] ? ' (saved ' . fdate($c['date']) . ')' : '')
            . '. Change what is no longer true: what is saved here is where the borrower\'s next loan starts.';
    }
    return 'The member\'s first loan: filled from the Financial Information tab. What is saved here is where the borrower\'s next loan starts.';
}

/**
 * The window that deletes a loan application, with the reason kept in the audit trail. With $app, it deletes that
 * application (posted to $action); without, it is the one window of the Loan Application Center and the Delete button
 * of each row fills it (data-del-action, data-del-no, data-del-member).
 */
function coop_app_delete_modal(string $id, ?array $app, string $action = ''): string
{
    $no = $app ? (string)$app['application_no'] : '';
    $member = $app ? (string)($app['member_name'] ?? DB::value('SELECT full_name FROM members WHERE id = :m', ['m' => $app['member_id']])) : '';
    $html = '<div class="modal fade" id="' . h($id) . '" tabindex="-1" aria-labelledby="' . h($id) . 'T"><div class="modal-dialog modal-dialog-centered">'
        . '<form class="modal-content app-del" method="post" action="' . h($action) . '" data-once>' . csrf_field() . '<input type="hidden" name="action" value="delete">'
        . '<div class="modal-header"><h5 class="modal-title" id="' . h($id) . 'T">Delete the loan application</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body"><div class="app-del-head"><i class="bi bi-trash3"></i><div><strong data-del-no>' . h($no) . '</strong><div class="small text-muted" data-del-member>' . h($member) . '</div></div></div>'
        . '<p class="small mb-2">The application is removed with its co-makers, collateral, approvals, requirements and credit investigation. '
        . 'It cannot be undone; the application as it was stays in the audit trail and its number is not used again.</p>'
        . '<label class="form-label" for="' . h($id) . 'R">Reason (required, recorded in the audit trail)</label>'
        . '<textarea class="form-control" id="' . h($id) . 'R" name="reason" rows="2" maxlength="255" required></textarea></div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep it</button>'
        . '<button class="btn btn-danger"><i class="bi bi-trash3"></i> Delete</button></div></form></div></div>';
    if (!$app) {
        $html .= '<script>document.addEventListener("DOMContentLoaded", function () {'
            . 'var m = document.getElementById(' . json_encode($id) . '); if (!m) return;'
            . 'm.addEventListener("show.bs.modal", function (e) { var b = e.relatedTarget; if (!b) return;'
            . 'm.querySelector("form").setAttribute("action", b.getAttribute("data-del-action"));'
            . 'm.querySelector("[data-del-no]").textContent = b.getAttribute("data-del-no") || "";'
            . 'm.querySelector("[data-del-member]").textContent = b.getAttribute("data-del-member") || "";'
            . 'm.querySelector("textarea").value = ""; });'
            . 'm.addEventListener("shown.bs.modal", function () { m.querySelector("textarea").focus(); }); });</script>';
    }
    return $html;
}

/**
 * The window that deletes an interest on share capital or patronage refund computation never posted, with the reason
 * kept in the audit trail. With $batch, it deletes that batch (posted to the page itself); without, it is the one window
 * of Distribution Batches and the Delete button of each row fills it (data-del-id, data-del-no, data-del-what).
 */
function coop_dist_delete_modal(string $id, ?array $batch): string
{
    $what = '';
    if ($batch) {
        $what = ($batch['kind'] === 'isc' ? 'Interest on share capital' : 'Patronage refund') . ', ' . (int)$batch['members_count'] . ' member(s), ' . Dec::money($batch['total_amount'])
            . ', ' . (['draft' => 'in review', 'validated' => 'reviewed', 'approved' => 'approved'][$batch['status']] ?? $batch['status']) . ', not posted';
    }
    $html = '<div class="modal fade" id="' . h($id) . '" tabindex="-1" aria-labelledby="' . h($id) . 'T"><div class="modal-dialog modal-dialog-centered">'
        . '<form class="modal-content app-del" method="post" data-once>' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="batch_id" value="' . ($batch ? (int)$batch['id'] : '') . '" data-del-id>'
        . '<div class="modal-header"><h5 class="modal-title" id="' . h($id) . 'T">Delete the computation</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body"><div class="app-del-head"><i class="bi bi-trash3"></i><div><strong data-del-no>' . h($batch['batch_no'] ?? '') . '</strong><div class="small text-muted" data-del-what>' . h($what) . '</div></div></div>'
        . '<p class="small mb-2">Nothing of it was posted: the batch and its list of members are removed, and no member account, voucher or payable changes. '
        . 'It cannot be undone; the batch as it was stays in the audit trail and its number is not used again. Compute a new batch to start over.</p>'
        . '<label class="form-label" for="' . h($id) . 'R">Reason (required, recorded in the audit trail)</label>'
        . '<textarea class="form-control" id="' . h($id) . 'R" name="reason" rows="2" maxlength="255" required></textarea></div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep it</button>'
        . '<button class="btn btn-danger"><i class="bi bi-trash3"></i> Delete the batch</button></div></form></div></div>';
    if (!$batch) {
        $html .= '<script>document.addEventListener("DOMContentLoaded", function () {'
            . 'var m = document.getElementById(' . json_encode($id) . '); if (!m) return;'
            . 'm.addEventListener("show.bs.modal", function (e) { var b = e.relatedTarget; if (!b) return;'
            . 'm.querySelector("[data-del-id]").value = b.getAttribute("data-del-id") || "";'
            . 'm.querySelector("[data-del-no]").textContent = b.getAttribute("data-del-no") || "";'
            . 'm.querySelector("[data-del-what]").textContent = b.getAttribute("data-del-what") || "";'
            . 'm.querySelector("textarea").value = ""; });'
            . 'm.addEventListener("shown.bs.modal", function () { m.querySelector("textarea").focus(); }); });</script>';
    } else {
        $html .= '<script>document.addEventListener("DOMContentLoaded", function () { var m = document.getElementById(' . json_encode($id) . '); if (m) m.addEventListener("shown.bs.modal", function () { m.querySelector("textarea").focus(); }); });</script>';
    }
    return $html;
}

/**
 * The member picker: a search box (name, member no., passbook, account or loan number, mobile) that fills a hidden
 * member_id. $opt: status (applicant|active ...), submit (send the form when a member is picked), href (go to this
 * URL with {id} replaced), label, required, class, placeholder.
 */
function member_pick(string $name, ?int $memberId, array $opt = []): string
{
    $m = $memberId ? Coop::member($memberId) : null;
    if ($m && !Coop::branchAllowed($m['branch_id'] ? (int)$m['branch_id'] : null)) {
        $m = null;
    }
    $label = $opt['label'] ?? 'Member';
    $cls = $opt['class'] ?? 'col-md-6';
    $text = $m ? $m['full_name'] . ' (' . $m['member_no'] . ')' : '';
    $id = 'mp_' . preg_replace('/\W/', '', $name);
    return '<div class="' . h($cls) . '"><label class="form-label" for="' . h($id) . '">' . h($label) . '</label>'
        . '<div class="member-pick" data-member-pick data-api="' . h(url('api.coop', ['a' => 'members'])) . '"'
        . (!empty($opt['status']) ? ' data-status="' . h($opt['status']) . '"' : '')
        . (!empty($opt['submit']) ? ' data-submit' : '')
        . (!empty($opt['href']) ? ' data-href="' . h($opt['href']) . '"' : '') . '>'
        . '<i class="bi bi-search"></i>'
        . '<input type="search" class="form-control form-control-sm" id="' . h($id) . '" value="' . h($text) . '" placeholder="' . h($opt['placeholder'] ?? 'Name, member no., account or loan no.') . '" autocomplete="off"'
        . (!empty($opt['required']) ? ' required' : '') . (!empty($opt['autofocus']) ? ' autofocus' : '') . '>'
        . '<input type="hidden" name="' . h($name) . '" value="' . h($m ? (string)$m['id'] : '') . '">'
        . '<div class="member-pick-list" hidden></div></div></div>';
}

/** The member panel: who they are, flags, and the balances at a glance */
function member_card(array $m, bool $links = true): string
{
    $s = Coop::memberSummary((int)$m['id']);
    $type = Members::types()[(int)$m['member_type_id']]['name'] ?? '';
    $flags = '';
    if ((int)$m['hold_flag'] === 1) {
        $flags .= '<div class="member-flag is-hold"><i class="bi bi-slash-circle"></i> On hold: ' . h($m['hold_reason'] ?: 'no transactions') . '</div>';
    }
    if (trim((string)$m['alert_note']) !== '') {
        $flags .= '<div class="member-flag"><i class="bi bi-exclamation-triangle"></i> ' . h($m['alert_note']) . '</div>';
    }
    $name = $links && Access::page('coop.member') ? '<a href="' . h(url('coop.member', ['id' => $m['id']])) . '">' . h($m['full_name']) . '</a>' : h($m['full_name']);
    $photo = $m['photo_file'] ? '<img class="member-photo" src="' . h(url('file.member', ['id' => $m['id'], 'f' => 'photo'])) . '" alt="">'
        : '<span class="member-photo is-blank">' . h(mb_strtoupper(mb_substr((string)$m['first_name'] ?: (string)$m['full_name'], 0, 1) . mb_substr((string)$m['last_name'], 0, 1))) . '</span>';
    return '<div class="member-card">' . $photo . '<div class="member-card-main"><div class="member-card-name">' . $name . '</div>'
        . '<div class="member-card-sub">' . h($m['member_no']) . ($type ? ', ' . h($type) : '') . ', ' . coop_status($m['status'])
        . ($m['branch_id'] ? ', ' . h(Coop::branchName((int)$m['branch_id'])) : '') . '</div>' . $flags . '</div>'
        . '<div class="member-card-figs">'
        . '<div><span>Share capital</span><strong>' . cm($s['share_capital']) . '</strong></div>'
        . '<div><span>Savings</span><strong>' . cm($s['savings']) . '</strong></div>'
        . '<div><span>Time deposits</span><strong>' . cm($s['time_deposits']) . '</strong></div>'
        . '<div><span>Loans (' . (int)$s['loans'] . ')</span><strong>' . cm($s['loan_balance']) . '</strong></div>'
        . '</div></div>';
}

/** The member a page works on: ?member=, checked for the user's branch */
function coop_member_param(string $key = 'member'): ?array
{
    $id = gint($key) ?: (pint($key) ?: null);
    if (!$id) {
        return null;
    }
    $m = Coop::member($id);
    if (!$m) {
        return null;
    }
    if (!Coop::branchAllowed($m['branch_id'] ? (int)$m['branch_id'] : null)) {
        flash('warning', 'That member belongs to another branch.');
        return null;
    }
    return $m;
}

/**
 * The one-time token check at the top of a money-saving POST handler. A failed save re-renders the form with a
 * fresh token, so the corrected form can be sent; the same submission can never be posted twice.
 */
function coop_once(): void
{
    Coop::consumeOnce();
}

// ======================================================================================== printing

/** The cooperative's name block for printed documents */
function coop_print_head(string $title, string $no = '', string $date = '', array $extra = []): string
{
    $s = Settings::all();
    $logo = Logo::img('doc.coop');
    $reg = Coop::setting('cda_reg_no');
    $html = '<div class="coop-doc-head"><div class="doc-company' . ($logo !== '' ? ' has-logo' : '') . '">' . $logo . '<div>'
        . '<h2 class="mb-0">' . h(Coop::coopName()) . '</h2>' . (trim((string)Coop::setting('division_name')) !== '' ? '<div class="small fw-semibold">' . h(trim((string)Coop::setting('division_name'))) . '</div>' : '')
        . '<div class="small">' . h($s['company_address'] ?? '')
        . ($reg ? '<br>CDA Registration No. ' . h($reg) : '') . (!empty($s['company_tin']) ? '<br>TIN ' . h($s['company_tin']) : '') . '</div></div></div>'
        . '<div class="text-end"><div class="coop-doc-title">' . h($title) . '</div>'
        . ($no !== '' ? '<div>No. <strong>' . h($no) . '</strong></div>' : '') . ($date !== '' ? '<div>' . h(fdate($date)) . '</div>' : '');
    foreach ($extra as $k => $v) {
        $html .= '<div class="small">' . h($k) . ': ' . h($v) . '</div>';
    }
    return $html . '</div></div>';
}

/** Signature lines with names (signatories from Cooperative Profile & Policies) */
function coop_signatures(array $who): string
{
    $html = '<div class="row mt-5 small signatures">';
    foreach ($who as $label => $name) {
        $html .= '<div class="col"><div class="sig-label">' . h($label) . '</div><div class="sig-line">' . ($name !== '' ? h($name) : '&nbsp;') . '</div></div>';
    }
    return $html . '</div>';
}

/**
 * Records a print of a document. The first print is the original; every later one is a reprint that needs a reason
 * (asked on the page) and is counted. Returns the copy number.
 */
function coop_doc_printed(string $docKey, string $refType, int $refId, string $docNo, string $pageKey): int
{
    $n = (int)DB::value('SELECT COUNT(*) FROM document_prints WHERE doc_key = :k AND ref_type = :t AND ref_id = :r', ['k' => $docKey, 't' => $refType, 'r' => $refId]);
    $reason = trim((string)(g('reason') ?: p('reason')));
    if ($n > 0 && $reason === '') {
        return -$n;       // a reprint without a reason: the page asks for one
    }
    DB::insert('document_prints', ['doc_key' => $docKey, 'ref_type' => $refType, 'ref_id' => $refId, 'doc_no' => mb_substr($docNo, 0, 40), 'copy_no' => $n + 1,
        'reason' => $n > 0 ? mb_substr($reason, 0, 255) : null, 'printed_by' => Auth::id()]);
    Audit::log($n > 0 ? 'reprint' : 'print', 'coop', $refType, $refId, $docKey . ' ' . $docNo, ['page_key' => $pageKey, 'reason' => $n > 0 ? $reason : null]);
    return $n + 1;
}

/** The page shown when a reprint needs a reason */
function coop_reprint_ask(int $copies, string $what): void
{
    layout_start('Reprint ' . $what, ['bare' => true]);
    echo '<form method="get" class="card mx-auto mt-4" style="max-width:520px"><div class="card-body">';
    foreach ($_GET as $k => $v) {
        if ($k !== 'reason' && is_scalar($v)) {
            echo '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
        }
    }
    echo '<p>This ' . h($what) . ' was already printed ' . $copies . ' time' . ($copies === 1 ? '' : 's') . '. A reprint is marked as a copy and recorded in the audit trail.</p>'
        . '<label class="form-label">Reason for the reprint</label><input class="form-control form-control-sm" name="reason" required maxlength="200" autofocus>'
        . '<div class="mt-3 d-flex gap-2"><button class="btn btn-primary btn-sm"><i class="bi bi-printer"></i> Reprint</button>'
        . '<a class="btn btn-outline-secondary btn-sm" href="javascript:history.back()">Cancel</a></div></div></form>';
    layout_end();
}

/** Prints the document when allowed, else asks for the reprint reason; returns the copy number or null when it asked */
function coop_print_gate(string $docKey, string $refType, int $refId, string $docNo, string $pageKey, string $what): ?int
{
    Access::require($pageKey, 'print');
    $copy = coop_doc_printed($docKey, $refType, $refId, $docNo, $pageKey);
    if ($copy < 0) {
        coop_reprint_ask(-$copy, $what);
        return null;
    }
    return $copy;
}

function coop_copy_mark(int $copy): string
{
    return $copy > 1 ? '<div class="copy-mark">COPY ' . ($copy - 1) . ' (reprint)</div>' : '';
}

// ======================================================================================== printer sheets

/**
 * The margins (inches), the distance between the lines (inches) and the font size (points) of the machine validation
 * printed on a deposit / withdrawal slip or a loan payslip. Set on Cooperative > Passbook Types; until they are set,
 * the positions of version 31 (twips) are used.
 */
function coop_slip_settings(): array
{
    $twips = fn(string $k, string $d) => max(0, (int)Coop::setting($k, $d)) / 1440;
    $in = function (string $k, float $def): float {
        $v = Coop::setting($k, '');
        return $v === '' || !is_numeric($v) ? round($def, 2) : max(0.0, min(5.0, (float)$v));
    };
    return [
        'top' => $in('slip_margin_top', $twips('sav_validation_top', '360')),
        'right' => $in('slip_margin_right', 0.25),
        'bottom' => $in('slip_margin_bottom', 0.25),
        'left' => $in('slip_margin_left', $twips('sav_validation_left', '360')),
        'gap' => max(0.1, $in('slip_line_gap', $twips('sav_validation_gap', '300'))),
        'font' => max(6.0, min(16.0, (float)Coop::setting('sav_validation_font', '10'))),
    ];
}

/**
 * Starts a page that is printed on a form already in the printer (a passbook page, a deposit slip): the page has no
 * title and no margins, so the browser prints nothing of its own (no title, address or page number), only the
 * lines at their positions. $hint is shown on the screen only.
 */
function coop_sheet_start(string $title, string $hint, array $js = []): void
{
    layout_start($title, ['bare' => true, 'no_title' => true, 'body_class' => 'pb-print', 'js' => $js]);
    echo '<div class="no-print sheet-hint"><i class="bi bi-printer"></i><div>' . $hint
        . ' <span class="text-muted">In the print window: Scale 100 %, Margins None (or Default), and untick "Headers and footers".</span></div></div>';
}

/**
 * Ends a printer page. $autoPrint opens the print window when the page has loaded. $after['close']: once printed, the
 * page closes and the teller is back on the page it was opened from (told by $after['notify'], a message to that
 * page); a page that cannot close itself (opened in the same tab) goes to $after['back'] instead.
 */
function coop_sheet_end(bool $autoPrint = true, array $after = []): void
{
    // the sheet is the paper: no margin of the page around it, so a sheet the size of the passbook (or the slip) fills one
    // page and nothing spills onto a second (the bare layout's padding is a utility class, hence the body class here)
    echo '<style>@page { margin: 0; } @media print { html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; } body.pb-print main, body.pb-print main.p-3 { padding: 0 !important; margin: 0 !important; } }</style>';
    // back to the page this one was opened from: it is told what was printed, then this window closes
    echo '<script>window.coopSheetBack = function (msg) {'
        . 'try { if (window.opener && !window.opener.closed && msg) { window.opener.postMessage(msg, window.location.origin); window.opener.focus(); } } catch (e) { /* another site */ }'
        . 'setTimeout(function () { try { window.close(); } catch (e) { /* not closable */ }'
        . 'setTimeout(function () { var b = ' . json_encode((string)($after['back'] ?? '')) . '; if (!window.closed && b) window.location.replace(b); }, 450); }, 120); };</script>';
    if ($autoPrint) {
        echo !empty($after['close'])
            ? '<script>(function () { var gone = false; window.addEventListener("afterprint", function () { if (gone) return; gone = true; window.coopSheetBack(' . json_encode($after['notify'] ?? null) . '); });'
                . ' window.addEventListener("load", function () { window.print(); }); })();</script>'
            : '<script>window.addEventListener("load", function () { window.print(); });</script>';
    }
    layout_end();
}

/**
 * The machine validation: its lines at the slip margins, nothing else on the paper. Once printed, the window closes
 * and the teller is back on the transaction form ($back when it was not opened from it).
 */
function coop_slip_validation(array $lines, string $title, string $hint, int $copy = 1, string $back = '', array $notify = []): void
{
    $s = coop_slip_settings();
    coop_sheet_start($title, $hint . ($copy > 1 ? ' This is reprint ' . ($copy - 1) . '.' : '') . ' Once it is printed, this window closes and you are back on the transaction form.');
    printf('<div class="slip-sheet" style="padding:%.2fin %.2fin %.2fin %.2fin;font-size:%spt;line-height:%.2fin">', $s['top'], $s['right'], $s['bottom'], $s['left'], rtrim(rtrim(number_format($s['font'], 1, '.', ''), '0'), '.'), $s['gap']);
    foreach ($lines as $parts) {
        echo '<div class="slip-line">' . h(implode('    ', array_filter(array_map('strval', $parts), fn($p) => $p !== ''))) . '</div>';
    }
    echo '</div>';
    coop_sheet_end(true, ['close' => true, 'back' => $back, 'notify' => ['coop' => 'validation-printed'] + $notify]);
}

/** "page 2", "pages 2 and 3", "pages 2 to 4" */
function coop_pb_pages_words(array $nums): string
{
    $nums = array_values($nums);
    $n = count($nums);
    return $n === 1 ? 'page ' . $nums[0] : ($n === 2 ? 'pages ' . $nums[0] . ' and ' . $nums[1] : 'pages ' . $nums[0] . ' to ' . $nums[$n - 1]);
}

/**
 * Update Passbook / Print to Passbook of an account: where to put the passbook, the pages its lines print on in one
 * run (between two pages the print window asks the teller to turn the passbook to the next page), the Print button
 * and, once printed, the question that marks the lines printed (all the pages, or the first ones when a later page
 * went wrong). $hidden: the inputs of the confirm form (its action, the account); $who: whose passbook (HTML).
 */
function coop_pb_run_box(array $pb, array $run, string $id, string $hidden, string $who, bool $canPrint): string
{
    $pages = $run['pages'];
    if (!$pages) {
        return '';
    }
    $loan = $pb['ledger'] === 'loan';
    $first = $pages[0];
    $nums = array_column($pages, 'page');
    $printUrl = url('sav.passbook', ['a' => 'print', 'account' => $pb['account']['account_no']] + ($loan ? ['ledger' => 'loan'] : []));
    $range = fn(array $p) => $p['first'] === $p['last'] ? 'line ' . $p['first'] : 'lines ' . $p['first'] . ' to ' . $p['last'];
    $html = '<div class="pb-run-box" data-pb-run-box>'
        . '<p class="mb-2">Place the ' . ($loan ? 'loan ' : '') . 'passbook of ' . $who . ' in the passbook printer, open at <strong>page ' . (int)$first['page'] . '</strong>. '
        . ($first['header'] ? 'The header' . ($loan ? ' (Loan Type, Term, Due Date)' : '') . ' prints first, then <strong>line ' . (int)$first['first'] . '</strong>.' : 'Printing starts on <strong>line ' . (int)$first['first'] . '</strong>.') . '</p>';
    if (count($pages) > 1) {
        $html .= '<div class="pb-run-note"><i class="bi bi-journals"></i><div><strong>' . (int)$run['count'] . ' lines on ' . count($pages) . ' pages, printed in one run.</strong> '
            . 'When page ' . (int)$first['page'] . ' is printed, the print window asks you to turn the passbook to page ' . (int)$pages[1]['page']
            . (count($pages) > 2 ? ', then to the next page,' : '') . ' and goes on printing there. There is no need to press Update Passbook again.</div></div>'
            . '<ol class="pb-run-pages">';
        foreach ($pages as $p) {
            $html .= '<li><span class="pb-run-pg">Page ' . (int)$p['page'] . '</span><span>' . h($range($p)) . '</span><span class="text-muted">' . count($p['lines']) . ' line' . (count($p['lines']) === 1 ? '' : 's')
                . ($p['header'] ? ', header first' : '') . '</span></li>';
        }
        $html .= '</ol>';
    } else {
        $html .= '<div class="small text-muted mb-2">' . count($first['lines']) . ' line' . (count($first['lines']) === 1 ? '' : 's') . ' on this page (' . h($range($first)) . ').</div>';
    }
    if (!$canPrint) {
        return $html . '<p class="small text-muted mb-0">Printing the passbook needs the print permission of Passbook Printing.</p></div>';
    }
    $html .= '<button type="button" class="btn btn-primary btn-sm" data-print-then="#' . h($id) . '" data-url="' . h($printUrl) . '"><i class="bi bi-printer"></i> '
        . (count($pages) > 1 ? 'Print the ' . count($pages) . ' pages' : 'Print') . '</button>';
    // the question once printed
    $html .= '<div id="' . h($id) . '" class="pb-run-confirm" hidden><form method="post" data-once>' . csrf_field() . $hidden;
    foreach ($pages as $p) {
        foreach ($p['lines'] as $l) {
            $html .= '<input type="hidden" name="lines[' . (int)$l['id'] . ']" value="' . (int)$l['line'] . '"><input type="hidden" name="pg[' . (int)$l['id'] . ']" value="' . (int)$p['page'] . '">';
        }
    }
    $html .= '<div class="fw-semibold mb-2">Did every line print in the right place?</div>';
    if (count($pages) > 1) {
        $html .= '<div class="pb-run-upto" role="radiogroup" aria-label="Pages printed right">';
        for ($k = count($pages) - 1; $k >= 0; $k--) {
            $upto = array_slice($nums, 0, $k + 1);
            $label = $k === count($pages) - 1 ? 'Yes, every page (' . coop_pb_pages_words($upto) . ')' : 'Only ' . coop_pb_pages_words($upto) . '; ' . coop_pb_pages_words(array_slice($nums, $k + 1)) . (count($nums) - $k - 1 > 1 ? ' print' : ' prints') . ' again later';
            $html .= '<div class="form-check"><input class="form-check-input" type="radio" name="upto" id="' . h($id) . 'U' . (int)$pages[$k]['page'] . '" value="' . (int)$pages[$k]['page'] . '"' . ($k === count($pages) - 1 ? ' checked' : '') . '>'
                . '<label class="form-check-label" for="' . h($id) . 'U' . (int)$pages[$k]['page'] . '">' . h($label) . '</label></div>';
        }
        $html .= '</div><div class="pb-run-again small"><span class="text-muted">A page came out wrong?</span>';
        foreach ($pages as $p) {
            $html .= '<button type="button" class="btn btn-link btn-sm p-0" data-print-then data-url="' . h($printUrl . '&pg=' . (int)$p['page']) . '"><i class="bi bi-printer"></i> Print page ' . (int)$p['page'] . ' again</button>';
        }
        $html .= '</div>';
    } else {
        $html .= '<input type="hidden" name="upto" value="' . (int)$first['page'] . '">';
    }
    $html .= '<div class="d-flex flex-wrap gap-2 mt-2"><button class="btn btn-success btn-sm" data-pb-yes><i class="bi bi-check2-all"></i> Yes, mark them printed</button>'
        . '<button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal" data-pb-later>No, print again later</button></div></form></div></div>';
    return $html;
}

// ======================================================================================== vouchers

/**
 * The view / print / void actions every cooperative transaction page shares (?a=view|print|void&id=).
 * Returns true when it handled the request.
 */
function coop_voucher_actions(string $pageKey, array $types): bool
{
    $a = g('a');
    if (!in_array($a, ['view', 'print', 'void'], true)) {
        return false;
    }
    $t = Txn::load((int)g('id'));
    if (!$t || !in_array($t['txn_type'], $types, true)) {
        flash('warning', 'Voucher not found.');
        redirect(url($pageKey));
    }
    $branch = DB::value('SELECT branch_id FROM coop_receipts WHERE txn_id = :t', ['t' => $t['id']]);
    if ($branch && !Coop::branchAllowed((int)$branch)) {
        Access::denyPage($pageKey, 'open a voucher of another branch on');
    }
    if ($a === 'view') {
        coop_voucher_view($t, $pageKey);
        return true;
    }
    if ($a === 'print') {
        // a loan release voucher prints as the Check / Cash Voucher of the loan release (the format of the former program)
        if ($t['txn_type'] === 'loan_release') {
            require_once __DIR__ . '/_loan_voucher.php';
            if (loan_voucher_page($t, $pageKey)) {
                return true;
            }
        }
        $rc = Teller::receiptByTxn((int)$t['id']);
        $copy = coop_print_gate('voucher.' . $t['txn_type'], 'transaction', (int)$t['id'], (string)$t['txn_no'], $pageKey, Txn::label($t['txn_type']));
        if ($copy === null) {
            return true;
        }
        if ($rc) {
            DB::run('UPDATE coop_receipts SET print_count = print_count + 1 WHERE id = :id', ['id' => $rc['id']]);
        }
        layout_start(Txn::ref($t), ['bare' => true]);
        coop_voucher_print($t, $copy);
        echo '<script>window.addEventListener("load", function () { window.print(); });</script>';
        layout_end();
        return true;
    }
    // void
    Access::require($pageKey, 'delete');
    if (!is_post()) {
        redirect(Txn::url($t));
    }
    try {
        $approver = Approval::check($pageKey, !Access::can($pageKey, 'approve'), 'void of ' . Txn::ref($t));
        Txn::void((int)$t['id'], trim((string)p('reason')), $approver);
        flash('success', Txn::ref($t) . ' was voided. The member ledgers were reversed with it.');
    } catch (UserError $e) {
        flash('danger', $e->getMessage());
    }
    redirect(Txn::url($t));
}

/** The member ledger rows a voucher wrote */
function coop_voucher_effects(int $txnId): array
{
    return [
        'savings' => DB::all('SELECT s.*, a.account_no, p.name AS product_name, m.full_name FROM sav_txns s JOIN sav_accounts a ON a.id = s.account_id JOIN sav_products p ON p.id = a.product_id JOIN members m ON m.id = a.member_id WHERE s.txn_id = :t ORDER BY s.id', ['t' => $txnId]),
        'loans' => DB::all('SELECT l.*, la.loan_no, m.full_name FROM loan_txns l JOIN loan_accounts la ON la.id = l.loan_id JOIN members m ON m.id = la.member_id WHERE l.txn_id = :t ORDER BY l.id', ['t' => $txnId]),
    ];
}

function coop_voucher_view(array $t, string $pageKey): void
{
    $id = (int)$t['id'];
    $voided = (int)$t['is_voided'] === 1;
    $rc = Teller::receiptByTxn($id);
    $lines = CoopLedger::lines($id);
    $fx = coop_voucher_effects($id);
    $member = $t['other_name_id'] ? DB::row('SELECT m.* FROM members m JOIN other_names o ON o.member_id = m.id WHERE o.id = :o', ['o' => $t['other_name_id']]) : null;
    $actions = [];
    if (Access::can($pageKey, 'print')) {
        $actions[] = btn_link(Txn::url($t, 'print'), 'Print', 'btn-outline-secondary', 'printer');
    }
    $blockers = $voided ? [] : CoopLedger::voidBlockers($t);
    if (!$voided && Access::can($pageKey, 'delete')) {
        $actions[] = '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#voidModal"' . ($blockers ? ' disabled title="' . h(implode(' ', $blockers)) . '"' : '') . '><i class="bi bi-slash-circle"></i> Void</button>';
    }
    $actions[] = btn_link(url($pageKey), 'Back', 'btn-outline-secondary');
    coop_layout(Txn::ref($t), ['actions' => implode(' ', $actions)]);
    $dr = '0';
    $cr = '0';
    foreach ($lines as $l) {
        $dr = Dec::add($dr, $l['debit'], 2);
        $cr = Dec::add($cr, $l['credit'], 2);
    }
    ?>
    <?php if ($voided): ?><div class="alert alert-secondary">Voided <?= h(fdatetime($t['voided_at'])) ?> by <?= h((string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $t['voided_by']])) ?>: <?= h($t['void_reason']) ?></div><?php endif; ?>
    <?php if ($blockers): ?><div class="alert alert-light small"><i class="bi bi-info-circle"></i> This voucher cannot be voided now: <?= h(implode(' ', $blockers)) ?></div><?php endif; ?>
    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card"><div class="card-body">
          <?= coop_kv([
              'Voucher' => h(Txn::label($t['txn_type'])) . ' <strong>' . h($t['txn_no']) . '</strong>',
              'Date' => h(fdate($t['txn_date'])),
              'Member' => $member ? safe_link('coop.member', ['id' => $member['id']], $member['full_name'] . ' (' . $member['member_no'] . ')') : h((string)$t['payee_name']),
              'Class' => h(Classes::name($t['class_id'])),
              'Reference' => h((string)$t['ref_no']),
              'Memo' => h((string)$t['memo']),
              'Payment' => $rc ? h(ucfirst($rc['payment_mode'])) . ($rc['cash_account_id'] ? ', ' . h(Coop::accountLabel((int)$rc['cash_account_id'])) : '') : '',
              'Teller' => $rc ? h((string)$rc['teller_name']) . ($rc['shift_id'] ? ', shift ' . (int)$rc['shift_id'] : '') : '',
              'Approved by' => $t['approved_by'] ? h((string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $t['approved_by']])) : '',
              'Recorded' => h(fdatetime($t['created_at'])) . ' by ' . h((string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $t['created_by']])),
              'Status' => $voided ? coop_status('void') : coop_status('posted'),
          ]) ?>
          <div class="voucher-total"><span>Total</span><strong><?= cm($t['total']) ?></strong></div>
        </div></div>
      </div>
      <div class="col-lg-8">
        <?php if ($rc && $rc['lines']): ?>
        <div class="card mb-3"><div class="card-header"><?= h(Teller::KIND_LABELS[$rc['receipt_kind']] ?? 'Receipt') ?> <?= h($rc['receipt_no']) ?></div>
          <table class="table table-sm"><thead><tr><th>Item</th><th class="num">Amount</th></tr></thead><tbody>
          <?php foreach ($rc['lines'] as $l): ?><tr><td><?= h($l['description']) ?></td><td class="num"><?= cm($l['amount']) ?></td></tr><?php endforeach; ?>
          </tbody><tfoot><tr><td class="fw-semibold">Total</td><td class="num fw-semibold"><?= cm($rc['total']) ?></td></tr></tfoot></table>
          <?php if ($rc['cheque_json']): ?><div class="card-body small pt-2"><?php foreach ((array)json_decode($rc['cheque_json'], true) as $c): ?>Cheque <?= h($c['cheque_no']) ?> <?= h($c['bank'] ?? '') ?> <?= h(fdate($c['date'] ?? '')) ?>: <?= cm($c['amount']) ?><br><?php endforeach; ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="card mb-3"><div class="card-header">General ledger entry</div>
          <table class="table table-sm"><thead><tr><th>Account</th><th>Name</th><th>Memo</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>
          <?php foreach ($lines as $l): ?><tr><td><?= h($l['account_code'] . ' ' . $l['account_name']) ?></td><td><?= h((string)$l['other_name']) ?></td><td class="small"><?= h((string)$l['memo']) ?></td>
            <td class="num"><?= cm($l['debit'], true) ?></td><td class="num"><?= cm($l['credit'], true) ?></td></tr><?php endforeach; ?>
          <?php if (!$lines): ?><tr><td colspan="5" class="text-muted">No ledger lines (a voided voucher keeps none).</td></tr><?php endif; ?>
          </tbody><tfoot><tr><td colspan="3" class="fw-semibold">Total</td><td class="num fw-semibold"><?= cm($dr) ?></td><td class="num fw-semibold"><?= cm($cr) ?></td></tr></tfoot></table>
        </div>
        <?php if ($fx['savings'] || $fx['loans']): ?>
        <div class="card"><div class="card-header">Member ledgers</div>
          <table class="table table-sm"><thead><tr><th>Account</th><th>Member</th><th>Entry</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead><tbody>
          <?php foreach ($fx['savings'] as $s): ?><tr class="<?= (int)$s['is_voided'] ? 'text-muted text-decoration-line-through' : '' ?>">
            <td><?= safe_link('sav.accounts', ['id' => $s['account_id']], $s['account_no']) ?><div class="small text-muted"><?= h($s['product_name']) ?></div></td><td><?= h($s['full_name']) ?></td>
            <td><?= h($s['txn_code'] . ' ' . $s['description']) ?></td><td class="num"><?= cm($s['debit'], true) ?></td><td class="num"><?= cm($s['credit'], true) ?></td><td class="num"><?= cm($s['balance_after']) ?></td></tr><?php endforeach; ?>
          <?php foreach ($fx['loans'] as $l): $d = Dec::add(Dec::add($l['principal_dr'], $l['interest_dr'], 2), $l['penalty_dr'], 2); $c = Dec::add(Dec::add($l['principal_cr'], $l['interest_cr'], 2), $l['penalty_cr'], 2); ?>
            <tr class="<?= (int)$l['is_voided'] ? 'text-muted text-decoration-line-through' : '' ?>">
            <td><?= safe_link('loan.accounts', ['id' => $l['loan_id']], $l['loan_no']) ?></td><td><?= h($l['full_name']) ?></td>
            <td><?= h(Loans::ledgerKind($l) . ': ' . $l['memo']) ?><div class="small text-muted">Principal <?= cm(Dec::sub($l['principal_cr'], $l['principal_dr'], 2)) ?>, interest <?= cm(Dec::sub($l['interest_cr'], $l['interest_dr'], 2)) ?>, penalty <?= cm(Dec::sub($l['penalty_cr'], $l['penalty_dr'], 2)) ?></div></td>
            <td class="num"><?= cm($d, true) ?></td><td class="num"><?= cm($c, true) ?></td><td class="num"><?= cm($l['principal_bal']) ?></td></tr><?php endforeach; ?>
          </tbody></table>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    if (!$voided && !$blockers && Access::can($pageKey, 'delete')) {
        echo reason_modal('voidModal', 'Void ' . $t['txn_no'], Txn::url($t, 'void'), 'btn-danger',
            '<div class="small text-muted mt-2">The voucher stays on file marked VOID; the general ledger and the member ledgers are reversed together. A supervisor approves unless you may approve on this page.</div>');
    }
    layout_end();
}

/** The printed voucher / official receipt */
function coop_voucher_print(array $t, int $copy = 1): void
{
    $rc = Teller::receiptByTxn((int)$t['id']);
    $lines = CoopLedger::lines((int)$t['id']);
    $member = $t['other_name_id'] ? DB::row('SELECT m.* FROM members m JOIN other_names o ON o.member_id = m.id WHERE o.id = :o', ['o' => $t['other_name_id']]) : null;
    $title = match ($t['txn_type']) {
        'coop_or' => 'OFFICIAL RECEIPT',
        'coop_cv' => 'DISBURSEMENT VOUCHER',
        'loan_release' => 'LOAN RELEASE VOUCHER',
        default => 'JOURNAL VOUCHER',
    };
    $total = $rc ? $rc['total'] : $t['total'];
    ?>
    <div class="doc coop-doc">
      <?= coop_copy_mark($copy) ?>
      <?= coop_print_head($title, $rc['receipt_no'] ?? (string)$t['txn_no'], $t['txn_date']) ?>
      <?php if ((int)$t['is_voided'] === 1): ?><div class="void-mark">VOID</div><?php endif; ?>
      <div class="row my-3 small">
        <?php
          // the member's registered name as it was when the voucher was issued (the party master), else the member record
          $cpName = trim((string)($t['cp_name'] ?? ''));
          $printName = $cpName !== '' && $member ? $cpName : ($member ? $member['full_name'] : (string)$t['payee_name']);
          $printNo = (string)(($t['cp_member_no'] ?? '') ?: ($member['member_no'] ?? ''));
          $receivedBy = $member && $t['payee_name'] && trim((string)$t['payee_name']) !== $printName ? (string)$t['payee_name'] : '';
        ?>
        <div class="col-7"><?= $t['txn_type'] === 'coop_or' ? 'Received from' : 'Paid to / member' ?>: <strong><?= h($printName) ?></strong>
          <?= $printNo !== '' ? '<br>Member no. ' . h($printNo) : '' ?><?= $receivedBy !== '' ? '<br>Received by: <strong>' . h($receivedBy) . '</strong>' : '' ?>
          <?= function_exists('printed_reissue_mark') ? printed_reissue_mark($t) : '' ?></div>
        <div class="col-5 text-end"><?= $rc ? 'Mode: ' . h(ucfirst($rc['payment_mode'])) : '' ?><?= $t['ref_no'] ? '<br>Reference: ' . h($t['ref_no']) : '' ?></div>
      </div>
      <?php if ($rc && $rc['lines']): ?>
      <table class="table table-sm doc-table"><thead><tr><th>Particulars</th><th class="num">Amount</th></tr></thead><tbody>
        <?php foreach ($rc['lines'] as $l): ?><tr><td><?= h($l['description']) ?></td><td class="num"><?= cm($l['amount']) ?></td></tr><?php endforeach; ?>
      </tbody><tfoot><tr><td class="fw-bold">TOTAL</td><td class="num fw-bold"><?= cm($total) ?></td></tr></tfoot></table>
      <div class="small mb-3">Amount in words: <em><?= h(amount_in_words((float)$total)) ?></em></div>
      <?php if ($rc['cheque_json']): ?><div class="small mb-2"><?php foreach ((array)json_decode($rc['cheque_json'], true) as $c): ?>Cheque no. <?= h($c['cheque_no']) ?> <?= h($c['bank'] ?? '') ?> dated <?= h(fdate($c['date'] ?? '')) ?>, <?= cm($c['amount']) ?><br><?php endforeach; ?></div><?php endif; ?>
      <?php endif; ?>
      <?php if (!$rc || $t['txn_type'] !== 'coop_or'): ?>
      <table class="table table-sm doc-table"><thead><tr><th>Account</th><th>Name</th><th>Memo</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>
        <?php foreach ($lines as $l): ?><tr><td><?= h($l['account_code'] . ' ' . $l['account_name']) ?></td><td><?= h((string)$l['other_name']) ?></td><td><?= h((string)$l['memo']) ?></td><td class="num"><?= cm($l['debit'], true) ?></td><td class="num"><?= cm($l['credit'], true) ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <?php if (!$rc): ?><div class="small mb-3">Memo: <?= h((string)$t['memo']) ?></div><?php endif; ?>
      <?php endif; ?>
      <?php
      $who = fn($id) => $id ? (string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $id]) : '';
      if ($t['txn_type'] === 'coop_or') {
          echo coop_signatures(['Received by (teller)' => $who($t['created_by'])]);
      } else {
          echo coop_signatures(['Prepared by' => $who($t['created_by']), 'Checked by' => Coop::setting('signatory_bookkeeper'), 'Approved by' => $t['approved_by'] ? $who($t['approved_by']) : Coop::setting('signatory_gm'),
              'Received by' => $t['txn_type'] === 'coop_jv' ? '' : ((string)$t['payee_name'] !== '' && $member ? (string)$t['payee_name'] : ($member['full_name'] ?? ''))]);
      }
      ?>
    </div>
    <?php
}

/** A list of cooperative vouchers of some types (the "center" part of the OR / disbursement / voucher pages) */
function coop_voucher_list(string $pageKey, array $types, string $title, array $opt = []): void
{
    $from = gdate('from', date('Y-m-01'));
    $to = gdate('to', today());
    $q = trim((string)g('q'));
    [$in, $ip] = DB::in('ty', $types);
    $sql = "SELECT t.*, r.receipt_no, r.payment_mode, r.shift_id, m.full_name, m.member_no FROM transactions t
              LEFT JOIN coop_receipts r ON r.txn_id = t.id LEFT JOIN other_names o ON o.id = t.other_name_id LEFT JOIN members m ON m.id = o.member_id
             WHERE t.txn_type IN $in AND t.txn_date BETWEEN :f AND :t";
    $p = $ip + ['f' => $from, 't' => $to];
    [$bf, $bp] = Coop::branchFilter('r.branch_id', 'vbr');
    $sql .= $bf;
    $p += $bp;
    if ($q !== '') {
        $sql .= ' AND (t.txn_no LIKE :q1 OR r.receipt_no LIKE :q2 OR m.full_name LIKE :q3 OR m.member_no = :q4 OR t.memo LIKE :q5)';
        $p += ['q1' => '%' . $q . '%', 'q2' => '%' . $q . '%', 'q3' => '%' . $q . '%', 'q4' => $q, 'q5' => '%' . $q . '%'];
    }
    $rows = DB::all($sql . ' ORDER BY t.txn_date DESC, t.id DESC LIMIT 500', $p);
    $sum = '0';
    foreach ($rows as $r) {
        if ((int)$r['is_voided'] === 0) {
            $sum = Dec::add($sum, $r['total'], 2);
        }
    }
    coop_layout($title, ['actions' => $opt['actions'] ?? '']);
    ?>
    <form class="row g-2 align-items-end filters mb-3 no-print">
      <input type="hidden" name="p" value="<?= h($pageKey) ?>">
      <?= input_field('from', 'From', $from, ['type' => 'date', 'class' => 'col-6 col-md-2']) ?>
      <?= input_field('to', 'To', $to, ['type' => 'date', 'class' => 'col-6 col-md-2']) ?>
      <?= input_field('q', 'Find', $q, ['class' => 'col-md-4', 'extra' => 'placeholder="Number, member, memo"']) ?>
      <div class="col-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Show</button></div>
    </form>
    <div class="card"><table class="table table-sm table-hover"><thead><tr><th>Date</th><th>No.</th><th>Member</th><th>Memo</th><th>Mode</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= (int)$r['is_voided'] ? 'text-muted' : '' ?>"><td><?= h(fdate($r['txn_date'])) ?></td><td><a href="<?= h(Txn::url($r)) ?>"><?= h($r['receipt_no'] ?: $r['txn_no']) ?></a></td>
        <td><?= h((string)$r['full_name']) ?><div class="small text-muted"><?= h((string)$r['member_no']) ?></div></td><td class="small"><?= h(mb_strimwidth((string)$r['memo'], 0, 70, '...')) ?></td>
        <td class="small"><?= h(ucfirst((string)$r['payment_mode'])) ?></td><td class="num"><?= cm($r['total']) ?></td><td><?= (int)$r['is_voided'] ? coop_status('void') : coop_status('posted') ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-muted">Nothing in this period.</td></tr><?php endif; ?>
    </tbody><tfoot><tr><td colspan="5" class="fw-semibold"><?= count($rows) ?> voucher(s), voids excluded from the total</td><td class="num fw-semibold"><?= cm($sum) ?></td><td></td></tr></tfoot></table></div>
    <?php
    layout_end();
}

/**
 * An editable list of rows (contacts, dependents, collaterals ...): posted as $name[i][field].
 * $cols: field => [label, type (text|date|number|money|select|check|member), options, extra attributes]
 */
function coop_grid(string $id, string $name, array $cols, array $rows, int $minRows = 1, string $addLabel = 'Add a row'): string
{
    $cell = function (string $field, array $def, $value, string $idx) use ($name): string {
        [$label, $type] = $def;
        $opts = $def[2] ?? [];
        $extra = $def[3] ?? '';
        $n = h($name . '[' . $idx . '][' . $field . ']');
        $v = $value === null ? '' : (string)$value;
        switch ($type) {
            case 'select':
                return '<select class="form-select form-select-sm" name="' . $n . '" aria-label="' . h($label) . '" ' . $extra . '>' . options($opts, $v, '') . '</select>';
            case 'check':
                return '<input type="hidden" name="' . $n . '" value="0"><input class="form-check-input" type="checkbox" name="' . $n . '" value="1"' . checked($v === '1' || $v === 1) . ' aria-label="' . h($label) . '">';
            case 'money':
                return '<input class="form-control form-control-sm text-end" name="' . $n . '" value="' . h($v !== '' && is_numeric($v) && !is_post() ? Dec::money($v) : $v) . '" inputmode="decimal" data-money aria-label="' . h($label) . '" ' . $extra . '>';
            case 'date':
                return '<input class="form-control form-control-sm" type="date" name="' . $n . '" value="' . h($v) . '" aria-label="' . h($label) . '" ' . $extra . '>';
            case 'number':
                return '<input class="form-control form-control-sm text-end" type="number" step="any" name="' . $n . '" value="' . h($v) . '" aria-label="' . h($label) . '" ' . $extra . '>';
            default:
                return '<input class="form-control form-control-sm" name="' . $n . '" value="' . h($v) . '" aria-label="' . h($label) . '" ' . $extra . '>';
        }
    };
    $html = '<div class="table-responsive"><table class="table table-sm coop-grid" id="' . h($id) . '" data-rows="' . h($id) . 'Tpl" data-min-rows="' . $minRows . '"><thead><tr>';
    foreach ($cols as $def) {
        $html .= '<th>' . h($def[0]) . '</th>';
    }
    $html .= '<th></th></tr></thead><tbody>';
    $i = 0;
    foreach ($rows as $r) {
        $html .= '<tr>';
        foreach ($cols as $field => $def) {
            $html .= '<td data-label="' . h($def[0]) . '" data-type="' . h($def[1]) . '">' . $cell($field, $def, $r[$field] ?? null, 'e' . $i) . '</td>';
        }
        $html .= '<td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger" data-del title="Remove the row" aria-label="Remove the row"><i class="bi bi-x-lg"></i></button></td></tr>';
        $i++;
    }
    $html .= '</tbody></table></div><template id="' . h($id) . 'Tpl"><tr>';
    foreach ($cols as $field => $def) {
        $html .= '<td data-label="' . h($def[0]) . '" data-type="' . h($def[1]) . '">' . $cell($field, $def, null, '__i__') . '</td>';
    }
    $html .= '<td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger" data-del title="Remove the row" aria-label="Remove the row"><i class="bi bi-x-lg"></i></button></td></tr></template>'
        . '<button type="button" class="btn btn-outline-secondary btn-sm" data-add-rows="' . h($id) . '"><i class="bi bi-plus-lg"></i> ' . h($addLabel) . '</button>';
    return $html;
}

/** The rows of a grid as posted (the form after a failed save shows what was typed) */
function coop_grid_posted(string $name): array
{
    return array_values(array_filter((array)($_POST[$name] ?? []), 'is_array'));
}
