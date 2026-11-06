<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
// the official receipts and member disbursements of the cooperative, with the voids; each opens on its own page
coop_voucher_list('teller.receipts', ['coop_or', 'coop_cv'], 'Official Receipt List', ['actions' => page_link('teller.or', [], 'New official receipt', 'btn-primary', 'plus-lg', 'create')]);
