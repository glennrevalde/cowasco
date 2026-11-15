<?php
declare(strict_types=1);

/**
 * v40: the files attached to a transaction (see app/Attachments.php), for logged-in users with the rights on the
 * transaction's page:
 *   GET  index.php?p=file.attachment&id=N           download the file (&view=1: show a PDF or picture in the tab)
 *   POST index.php?p=file.attachment&a=add&txn=N    attach the posted files to a saved transaction
 *   POST index.php?p=file.attachment&a=remove&id=N  remove a file
 */
$a = g('a');
if (!Attachments::ready()) {
    http_response_code(404);
    exit('Attachments are not set up yet: run database/migrations/2027_05_09_accounting_update_v40.sql.');
}

if ($a === 'add') {
    $t = Txn::load((int)g('txn'));
    if (!is_post() || !$t || !in_array($t['txn_type'], Attachments::TYPES, true)) {
        redirect(url('home.dashboard'));
    }
    $page = Txn::page($t['txn_type']);
    if (!Attachments::canAdd($t)) {
        Access::denyPage($page, 'attach files on');
    }
    $n = Attachments::addNow($t, $page);
    if ($n > 0) {
        flash('success', ($n === 1 ? '1 file' : $n . ' files') . ' attached to ' . Txn::ref($t) . '.');
    } elseif (empty($_FILES[Attachments::FIELD])) {
        flash('warning', 'Choose the files to attach first.');
    }
    redirect(Txn::url($t) . '#attachments');
}

$att = Attachments::find((int)g('id'));
$t = $att && $att['transaction_id'] ? Txn::load((int)$att['transaction_id']) : null;
if (!$att || !$t) {
    // a file of a form not saved yet can be opened only from the same session
    if ($att && !$att['transaction_id'] && $a === '' && ($att['stage_key'] ?? '') === ($_SESSION['att_stage_key'] ?? '-')) {
        Attachments::send($att, g('view') === '1');
    }
    http_response_code(404);
    layout_start('File not found');
    echo '<p>The file was not found. It may have been removed.</p>';
    layout_end();
    exit;
}
$page = Txn::page($t['txn_type']);

if ($a === 'remove') {
    if (!is_post()) {
        redirect(Txn::url($t) . '#attachments');
    }
    if (!Attachments::canRemove($t)) {
        Access::denyPage($page, 'remove attached files on');
    }
    Attachments::remove($att, $t, $page);
    flash('success', $att['file_name'] . ' was removed from ' . Txn::ref($t) . '.');
    redirect(Txn::url($t) . '#attachments');
}

if (!Attachments::canView($t)) {
    Access::denyPage($page, 'open attached files on');
}
Attachments::send($att, g('view') === '1');
