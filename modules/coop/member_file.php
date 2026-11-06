<?php
declare(strict_types=1);

// index.php?p=file.member&id=<member>&f=photo|signature|doc&doc=<document id> : a member's file, for users who open member records
if (!Coop::ready() || !(Access::page('coop.member') || Access::page('coop.members') || Access::page('teller.or') || Access::page('sav.accounts') || Access::page('loan.applications'))) {
    http_response_code(403);
    exit('Not allowed.');
}
$m = Coop::member((int)g('id'));
if (!$m || !Coop::branchAllowed($m['branch_id'] ? (int)$m['branch_id'] : null)) {
    http_response_code(404);
    exit('Not found.');
}
$f = (string)g('f');
if ($f === 'photo' || $f === 'signature') {
    $stored = (string)$m[$f . '_file'];
    if ($stored === '') {
        http_response_code(404);
        exit('Not found.');
    }
    $ext = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
    Members::sendFile($stored, Members::FILE_TYPES[$ext] ?? 'application/octet-stream', $m['member_no'] . '_' . $f . '.' . $ext);
}
if ($f === 'loandoc') {
    // a requirement file attached to one of the member's loan applications
    $ld = DB::row('SELECT d.*, a.application_no FROM loan_application_docs d JOIN loan_applications a ON a.id = d.application_id WHERE d.id = :d AND a.member_id = :m',
        ['d' => (int)g('doc'), 'm' => $m['id']]);
    if (!$ld || !$ld['file_name'] || !(Access::page('loan.applications') || Access::page('loan.application'))) {
        http_response_code(404);
        exit('Not found.');
    }
    $ext = strtolower(pathinfo((string)$ld['file_name'], PATHINFO_EXTENSION));
    Audit::log('view_file', 'loan', 'loan_application', (int)$ld['application_id'], $ld['application_no'] . ': ' . $ld['requirement'], ['page_key' => 'loan.application']);
    Members::sendFile($ld['file_name'], Members::FILE_TYPES[$ext] ?? 'application/octet-stream', (string)($ld['original_name'] ?: $ld['file_name']));
}
$doc = DB::row('SELECT * FROM member_documents WHERE id = :d AND member_id = :m', ['d' => (int)g('doc'), 'm' => $m['id']]);
if (!$doc || !$doc['file_name']) {
    http_response_code(404);
    exit('Not found.');
}
Audit::log('view_file', 'coop', 'member_document', (int)$doc['id'], $m['full_name'] . ': ' . $doc['doc_type'], ['page_key' => 'coop.member']);
Members::sendFile($doc['file_name'], $doc['mime_type'] ?: 'application/octet-stream', (string)($doc['original_name'] ?: $doc['file_name']));
