<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
require_once __DIR__ . '/../common/helpCenterHelpers.php';
$pdo = getDB();
requirePageAccess($pdo, 'user_manual');
/* AUTH_GUARD_END */

try {
    $manual = hc_load_manual_content();
} catch (Throwable $e) {
    http_response_code(500);
    $manual = ['metadata' => ['product' => 'Water Billing', 'title' => 'User Manual', 'subtitle' => 'Content unavailable', 'version' => '', 'revision_date' => '', 'audience' => '', 'support_note' => 'Contact the system administrator.'], 'chapters' => [], 'role_guides' => [], 'faqs' => [], 'glossary' => [], 'troubleshooting' => []];
    $manualError = 'The manual content could not be loaded.';
}
$metadata = (array)($manual['metadata'] ?? []);
$chapters = (array)($manual['chapters'] ?? []);
$roles = (array)($manual['role_guides'] ?? []);
$faqs = (array)($manual['faqs'] ?? []);
$glossary = (array)($manual['glossary'] ?? []);
$troubleshooting = (array)($manual['troubleshooting'] ?? []);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= hc_escape($metadata['title'] ?? 'User Manual') ?> - <?= hc_escape(app_name()) ?></title>
    <link rel="stylesheet" href="../common/fonts.css">
    <link rel="stylesheet" href="../resources/css/help-center.css">
</head>
<body>
<?php require_once __DIR__ . '/../common/navbar.php'; ?>
<div class="main-content">
    <main class="manual-shell">
        <div class="manual-toolbar no-print" aria-label="Manual actions">
            <a class="hc-btn hc-btn-light" href="help_center.php">Back to Help Center</a>
            <a class="hc-btn hc-btn-light" href="../process/downloadUserManual.php?format=docx">Download DOCX</a>
            <a class="hc-btn hc-btn-light" href="../process/downloadUserManual.php?format=pdf">Download PDF</a>
            <button class="hc-btn hc-btn-dark" type="button" onclick="window.print()">Print manual</button>
        </div>

        <header class="manual-cover">
            <h1><?= hc_escape($metadata['title'] ?? 'Water Billing System User Manual') ?></h1>
            <p><?= hc_escape($metadata['subtitle'] ?? '') ?></p>
            <div class="manual-meta"><span>Version <?= hc_escape($metadata['version'] ?? '') ?></span><span>Revised <?= hc_escape($metadata['revision_date'] ?? '') ?></span><span>Role-based page access</span></div>
        </header>

        <?php if (!empty($manualError)): ?><div class="manual-footer-note" role="alert"><?= hc_escape($manualError) ?></div><?php endif; ?>

        <section class="manual-toc" aria-labelledby="toc-heading">
            <h2 id="toc-heading">Table of Contents</h2>
            <ol>
                <?php foreach ($chapters as $chapter): $anchor = 'chapter-' . (int)($chapter['number'] ?? 0); ?>
                    <li><a href="#<?= hc_escape($anchor) ?>"><?= (int)($chapter['number'] ?? 0) ?>. <?= hc_escape($chapter['title'] ?? '') ?></a></li>
                <?php endforeach; ?>
            </ol>
        </section>

        <section class="manual-chapter" id="roles">
            <h2>Role and Responsibility Guide</h2>
            <p class="hc-section-copy"><?= hc_escape($metadata['support_note'] ?? '') ?></p>
            <div class="ui-table-scroll"><table class="manual-table">
                <thead><tr><th>Typical role</th><th>Primary responsibilities</th></tr></thead>
                <tbody>
                <?php foreach ($roles as $role): ?>
                    <tr><td><strong><?= hc_escape($role['name'] ?? '') ?></strong></td><td><ul><?php foreach ((array)($role['responsibilities'] ?? []) as $responsibility): ?><li><?= hc_escape($responsibility) ?></li><?php endforeach; ?></ul></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>

        <?php foreach ($chapters as $chapter):
            $number = (int)($chapter['number'] ?? 0);
        ?>
            <section class="manual-chapter" id="chapter-<?= $number ?>">
                <h2><?= $number ?>. <?= hc_escape($chapter['title'] ?? '') ?></h2>
                <?php foreach ((array)($chapter['sections'] ?? []) as $section): ?>
                    <div class="manual-section">
                        <h3><?= hc_escape($section['heading'] ?? '') ?></h3>
                        <?php foreach ((array)($section['paragraphs'] ?? []) as $paragraph): ?><p><?= hc_escape($paragraph) ?></p><?php endforeach; ?>
                        <?php if (!empty($section['steps'])): ?><ol><?php foreach ((array)$section['steps'] as $step): ?><li><?= hc_escape($step) ?></li><?php endforeach; ?></ol><?php endif; ?>
                        <?php if (!empty($section['bullets'])): ?><ul><?php foreach ((array)$section['bullets'] as $bullet): ?><li><?= hc_escape($bullet) ?></li><?php endforeach; ?></ul><?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if ($number === 21): ?>
                    <div class="manual-section"><h3>Troubleshooting matrix</h3><div class="ui-table-scroll"><table class="manual-table"><thead><tr><th>Problem</th><th>Safe checks</th><th>Avoid</th></tr></thead><tbody><?php foreach ($troubleshooting as $row): ?><tr><td><?= hc_escape($row['problem'] ?? '') ?></td><td><?= hc_escape($row['checks'] ?? '') ?></td><td><?= hc_escape($row['avoid'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div></div>
                <?php elseif ($number === 23): ?>
                    <?php foreach ($faqs as $faq): ?><div class="manual-section"><h3><?= hc_escape($faq['question'] ?? '') ?></h3><p><?= hc_escape($faq['answer'] ?? '') ?></p></div><?php endforeach; ?>
                <?php elseif ($number === 24): ?>
                    <div class="manual-section"><div class="ui-table-scroll"><table class="manual-table"><thead><tr><th>Term</th><th>Definition</th></tr></thead><tbody><?php foreach ($glossary as $item): ?><tr><td><strong><?= hc_escape($item['term'] ?? '') ?></strong></td><td><?= hc_escape($item['definition'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div></div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

        <div class="manual-footer-note">This guide explains the application workflow. Local approvals, tariff resolutions, accounting policies, privacy requirements, cash-handling rules, and records-retention procedures remain authoritative.</div>
    </main>
</div>
</body>
</html>
