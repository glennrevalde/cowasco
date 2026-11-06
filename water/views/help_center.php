<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
require_once __DIR__ . '/../common/helpCenterHelpers.php';
$pdo = getDB();
requirePageAccess($pdo, 'help_center');
/* AUTH_GUARD_END */

try {
    $manual = hc_load_manual_content();
} catch (Throwable $e) {
    http_response_code(500);
    $manual = [
        'metadata' => [
            'product' => 'Water Billing',
            'title' => 'Help Center',
            'subtitle' => 'The help content could not be loaded.',
            'version' => 'Unavailable',
            'revision_date' => '',
            'support_note' => 'Contact the system administrator.'
        ],
        'quick_start' => [],
        'articles' => [],
        'troubleshooting' => [],
        'faqs' => [],
        'glossary' => []
    ];
    $helpLoadError = 'Help Center content is unavailable. Please contact the system administrator and provide the current date and page name.';
}

$metadata = (array)($manual['metadata'] ?? []);
$articles = (array)($manual['articles'] ?? []);
$categories = hc_article_categories($articles);
$quickStart = (array)($manual['quick_start'] ?? []);
$troubleshooting = (array)($manual['troubleshooting'] ?? []);
$faqs = (array)($manual['faqs'] ?? []);
$glossary = (array)($manual['glossary'] ?? []);
$currentProfile = trim((string)($_SESSION['active_profile_name'] ?? $_SESSION['role'] ?? 'Authorized user'));
$canOpenProfile = userCanAccessPage($pdo, 'profile');
$accessibleArticleCount = 0;
foreach ($articles as $article) {
    if (hc_can_open_article_page($pdo, (array)$article)) {
        $accessibleArticleCount++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Help Center - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="../common/fonts.css">
    <link rel="stylesheet" href="../resources/css/help-center.css">

<style id="frontend-ui-refinement">
@media screen {
    :root { --hc-shadow: none !important; }
    .hc-shell { max-width: 1280px !important; padding: 28px 28px 56px !important; }
    .hc-hero {
        padding: 16px 20px !important;
        border: 1px solid var(--hc-border) !important;
        border-radius: 6px !important;
        color: var(--hc-text) !important;
        background: #fff !important;
        box-shadow: none !important;
        overflow: visible !important;
    }
    .hc-hero::after { display: none !important; }
    .hc-hero-inner { max-width: none !important; }
    .hc-eyebrow { color: #667085 !important; letter-spacing: .09em !important; }
    .hc-title { margin: 0 0 4px !important; font-size: 1.35rem !important; line-height: 1.25 !important; letter-spacing: 0 !important; }
    .hc-subtitle { max-width: 920px !important; color: var(--hc-muted) !important; font-size: .9rem !important; }
    .hc-search-wrap { margin-top: 12px !important; max-width: 760px !important; }
    .hc-search { border: 1px solid #d0d5dd !important; border-radius: 5px !important; padding: 12px 44px 12px 46px !important; box-shadow: none !important; }
    .hc-search:focus { border-color: #475467 !important; box-shadow: 0 0 0 3px rgba(71,84,103,.10) !important; }
    .hc-btn { min-height: 38px !important; padding: 8px 12px !important; border-radius: 5px !important; transition: background-color .15s ease, border-color .15s ease !important; }
    .hc-btn:hover { transform: none !important; }
    .hc-btn-primary, .hc-btn-dark { color: #fff !important; background: var(--hc-accent) !important; }
    .hc-btn-secondary, .hc-btn-light { color: var(--hc-text) !important; background: #fff !important; border-color: #d0d5dd !important; }
    .hc-manual-download {
        grid-template-columns: minmax(0, 1fr) auto !important;
        gap: 20px !important;
        margin-top: 16px !important;
        padding: 20px !important;
        border-radius: 6px !important;
        background: #fff !important;
        box-shadow: none !important;
    }
    .hc-manual-download h2 { font-size: 1.25rem !important; }
    .hc-manual-download p { line-height: 1.6 !important; }
    .hc-manual-download-meta span, .hc-filter, .hc-tag, .hc-profile-pill { border-radius: 4px !important; }
    .hc-download-card { padding: 12px 14px !important; border-radius: 5px !important; transition: background-color .15s ease !important; }
    .hc-download-card:hover, .hc-download-card:focus-visible { transform: none !important; box-shadow: none !important; background: #f8fafc !important; }
    .hc-stats {
        display: flex !important;
        gap: 0 !important;
        margin: 16px 0 24px !important;
        border: 1px solid var(--hc-border) !important;
        border-radius: 6px !important;
        overflow: hidden !important;
        background: #fff !important;
    }
    .hc-stat { flex: 1 !important; border: 0 !important; border-right: 1px solid var(--hc-border) !important; border-radius: 0 !important; padding: 13px 15px !important; }
    .hc-stat:last-child { border-right: 0 !important; }
    .hc-quick-grid { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; gap: 0 !important; border: 1px solid var(--hc-border) !important; border-radius: 6px !important; overflow: hidden !important; }
    .hc-quick-card { min-height: 0 !important; padding: 15px 16px !important; border: 0 !important; border-right: 1px solid var(--hc-border) !important; border-bottom: 1px solid var(--hc-border) !important; border-radius: 0 !important; }
    .hc-quick-card:nth-child(2n) { border-right: 0 !important; }
    .hc-quick-number, .hc-article-index { border-radius: 4px !important; }
    .hc-article-grid { grid-template-columns: 1fr !important; gap: 8px !important; }
    .hc-article, .hc-faq, .hc-support-card, .hc-table-wrap, .hc-empty { border-radius: 6px !important; box-shadow: none !important; }
    .hc-article summary { padding: 16px !important; }
    .hc-article-body { padding: 16px !important; }
    .hc-steps li { border-radius: 4px !important; }
    .hc-steps li::before { border-radius: 4px !important; }
    @media (max-width: 760px) {
        .hc-shell { padding: 20px 16px 44px !important; }
        .hc-stats { display: grid !important; grid-template-columns: 1fr 1fr !important; }
        .hc-stat { border-right: 0 !important; border-bottom: 1px solid var(--hc-border) !important; }
        .hc-manual-download, .hc-quick-grid { grid-template-columns: 1fr !important; }
        .hc-quick-card { border-right: 0 !important; }
    }
}
</style>
</head>
<body>
<?php require_once __DIR__ . '/../common/navbar.php'; ?>
<div class="main-content">
    <main class="hc-shell" id="main-content">
        <section class="hc-hero" aria-labelledby="help-center-title">
            <div class="hc-hero-inner">
                <h1 class="hc-title" id="help-center-title">Water Help Center</h1>
                <p class="hc-subtitle">Procedures for water-service accounts, rates, readings, billing, collections, corrections, materials and reports.</p>
                <div class="hc-search-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <label for="helpSearch" class="sr-only" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;">Search Help Center</label>
                    <input id="helpSearch" class="hc-search" type="search" placeholder="Search: meter reading, partial payment, print settings..." autocomplete="off" data-help-search>
                    <button type="button" class="hc-search-clear" data-help-clear aria-label="Clear search">×</button>
                </div>
                <div class="hc-hero-actions">
                    <a class="hc-btn hc-btn-primary" href="../process/downloadUserManual.php?format=pdf">Download Full User Manual (PDF)</a>
                    <a class="hc-btn hc-btn-secondary" href="../process/downloadUserManual.php?format=docx">Download Editable Manual (DOCX)</a>
                    <a class="hc-btn hc-btn-secondary" href="user_manual.php">Preview Manual in System</a>
                    <button class="hc-btn hc-btn-secondary" type="button" onclick="window.print()">Print Help Center</button>
                </div>
            </div>
        </section>

        <?php if (!empty($helpLoadError)): ?>
            <div class="manual-footer-note" role="alert"><?= hc_escape($helpLoadError) ?></div>
        <?php endif; ?>

        <section class="hc-manual-download" aria-labelledby="manual-download-heading">
            <div class="hc-manual-download-copy">
                <h2 id="manual-download-heading">Full User Manual</h2>
                <p>Download the complete operating guide from inside the Water Billing System. The PDF is best for reading and printing, while the DOCX copy can be edited for approved local procedures.</p>
                <div class="hc-manual-download-meta">
                    <span>Version <?= hc_escape($metadata['version'] ?? '1.0') ?></span>
                    <span>Revised <?= hc_escape($metadata['revision_date'] ?? '') ?></span>
                    <span>Authenticated download</span>
                </div>
            </div>
            <div class="hc-manual-download-actions">
                <a class="hc-download-card" href="../process/downloadUserManual.php?format=pdf">
                    <strong>Download PDF</strong>
                    <span>Print-ready full manual</span>
                </a>
                <a class="hc-download-card" href="../process/downloadUserManual.php?format=docx">
                    <strong>Download DOCX</strong>
                    <span>Editable Microsoft Word copy</span>
                </a>
            </div>
        </section>

        <section class="hc-stats" aria-label="Help Center summary">
            <div class="hc-stat"><div class="hc-stat-value"><?= count($articles) ?></div><div class="hc-stat-label">Step-by-step articles</div></div>
            <div class="hc-stat"><div class="hc-stat-value"><?= count($categories) ?></div><div class="hc-stat-label">Operational categories</div></div>
            <div class="hc-stat"><div class="hc-stat-value"><?= $accessibleArticleCount ?></div><div class="hc-stat-label">Articles linked to your accessible pages</div></div>
            <div class="hc-stat"><div class="hc-stat-value"><?= hc_escape($metadata['version'] ?? '1.0') ?></div><div class="hc-stat-label">Manual version, <?= hc_escape($metadata['revision_date'] ?? '') ?></div></div>
        </section>

        <section class="hc-section" aria-labelledby="quick-start-heading">
            <div class="hc-section-head">
                <div><h2 class="hc-section-title" id="quick-start-heading">Quick start</h2><p class="hc-section-copy">Use this order when operating or validating a new installation.</p></div>
            </div>
            <div class="hc-quick-grid">
                <?php foreach ($quickStart as $index => $item): ?>
                    <article class="hc-quick-card">
                        <div class="hc-quick-number"><?= $index + 1 ?></div>
                        <h3><?= hc_escape($item['title'] ?? '') ?></h3>
                        <p><?= hc_escape($item['text'] ?? '') ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="hc-section" aria-labelledby="articles-heading">
            <div class="hc-section-head">
                <div><h2 class="hc-section-title" id="articles-heading">Operational guides</h2><p class="hc-section-copy">Expand an article for exact steps, cautions, and a direct link to the relevant module when your active profile has access.</p></div>
                <div class="hc-count"><strong data-help-count><?= count($articles) ?></strong> visible</div>
            </div>

            <div class="hc-filter-row" role="group" aria-label="Filter articles by category">
                <button type="button" class="hc-filter active" data-help-filter="all" aria-pressed="true">All (<?= count($articles) ?>)</button>
                <?php foreach ($categories as $category => $count): ?>
                    <button type="button" class="hc-filter" data-help-filter="<?= hc_escape($category) ?>" aria-pressed="false"><?= hc_escape($category) ?> (<?= (int)$count ?>)</button>
                <?php endforeach; ?>
            </div>

            <div class="hc-article-grid">
                <?php foreach ($articles as $index => $article):
                    $article = (array)$article;
                    $anchor = hc_safe_anchor((string)($article['id'] ?? ('article-' . ($index + 1))));
                    $canOpen = hc_can_open_article_page($pdo, $article);
                    $pageFile = trim((string)($article['page_file'] ?? ''));
                    if (str_contains((string)($article['page'] ?? ''), '.')) {
                        // a page of the integrated system (Inventory, Report Center, User Accounts...)
                        $pageFile = url((string)$article['page']);
                    }
                ?>
                    <details class="hc-article" id="<?= hc_escape($anchor) ?>" data-help-article data-category="<?= hc_escape($article['category'] ?? 'Other') ?>" data-search="<?= hc_escape(hc_article_search_text($article)) ?>">
                        <summary>
                            <div class="hc-article-index"><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></div>
                            <div class="hc-article-copy">
                                <span class="hc-tag"><?= hc_escape($article['category'] ?? 'Other') ?></span>
                                <h3><?= hc_escape($article['title'] ?? '') ?></h3>
                                <p class="hc-article-summary"><?= hc_escape($article['summary'] ?? '') ?></p>
                            </div>
                            <svg class="hc-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                        </summary>
                        <div class="hc-article-body">
                            <h4>Procedure</h4>
                            <ol class="hc-steps">
                                <?php foreach ((array)($article['steps'] ?? []) as $step): ?><li><?= hc_escape($step) ?></li><?php endforeach; ?>
                            </ol>
                            <?php if (!empty($article['notes'])): ?>
                                <ul class="hc-notes">
                                    <?php foreach ((array)$article['notes'] as $note): ?><li><?= hc_escape($note) ?></li><?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <div class="hc-article-actions">
                                <?php if ($pageFile !== '' && $canOpen): ?>
                                    <a class="hc-btn hc-btn-dark" href="<?= hc_escape($pageFile) ?>">Open related module</a>
                                <?php elseif ($pageFile !== ''): ?>
                                    <span class="hc-btn hc-btn-disabled" title="The active profile does not have access to this page.">Module not assigned</span>
                                <?php endif; ?>
                                <button class="hc-btn hc-btn-light" type="button" data-copy-anchor="<?= hc_escape($anchor) ?>">Copy article link</button>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
            <div class="hc-empty" data-help-empty>No article matches your search and category. Clear the search or select another category.</div>
        </section>

        <section class="hc-section" aria-labelledby="troubleshooting-heading">
            <div class="hc-section-head"><div><h2 class="hc-section-title" id="troubleshooting-heading">Troubleshooting matrix</h2><p class="hc-section-copy">Check current state and history before retrying or changing financial data.</p></div></div>
            <div class="hc-table-wrap">
                <div class="ui-table-scroll"><table class="hc-table">
                    <thead><tr><th>Problem</th><th>Safe checks</th><th>Avoid</th></tr></thead>
                    <tbody>
                    <?php foreach ($troubleshooting as $row): ?>
                        <tr><td><strong><?= hc_escape($row['problem'] ?? '') ?></strong></td><td><?= hc_escape($row['checks'] ?? '') ?></td><td><?= hc_escape($row['avoid'] ?? '') ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </section>

        <section class="hc-section" aria-labelledby="faq-heading">
            <div class="hc-section-head"><div><h2 class="hc-section-title" id="faq-heading">Frequently asked questions</h2><p class="hc-section-copy">Answers to the most common workflow and control questions.</p></div></div>
            <div class="hc-faq-list">
                <?php foreach ($faqs as $faq): ?>
                    <details class="hc-faq"><summary><?= hc_escape($faq['question'] ?? '') ?><span aria-hidden="true">+</span></summary><p><?= hc_escape($faq['answer'] ?? '') ?></p></details>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="hc-section" aria-labelledby="glossary-heading">
            <div class="hc-section-head"><div><h2 class="hc-section-title" id="glossary-heading">Key terms</h2><p class="hc-section-copy">Common terms used throughout billing, collections, adjustments, and accounting review.</p></div></div>
            <div class="hc-table-wrap">
                <div class="ui-table-scroll"><table class="hc-table">
                    <thead><tr><th>Term</th><th>Meaning</th></tr></thead>
                    <tbody><?php foreach ($glossary as $item): ?><tr><td><strong><?= hc_escape($item['term'] ?? '') ?></strong></td><td><?= hc_escape($item['definition'] ?? '') ?></td></tr><?php endforeach; ?></tbody>
                </table></div>
            </div>
        </section>

        <section class="hc-section hc-support" aria-labelledby="support-heading">
            <article class="hc-support-card">
                <h3 id="support-heading">Escalate a problem with complete evidence</h3>
                <p>Contact the authorized system administrator or supervisor. Include enough information to reproduce the issue without exposing credentials or unrelated customer information.</p>
                <ul class="hc-support-list">
                    <li>Page or module name</li><li>Member, bill, receipt, or journal reference</li><li>Exact date and time</li><li>Steps performed</li><li>Full error message</li><li>Expected and actual result</li><li>Screenshot with sensitive data minimized</li>
                </ul>
            </article>
            <article class="hc-support-card">
                <h3>Your current access context</h3>
                <p>The Help Center is available to every signed-in operator. Direct module buttons appear only when the active profile has permission for that page.</p>
                <span class="hc-profile-pill">Active profile: <?= hc_escape($currentProfile) ?></span>
                <div class="hc-article-actions">
                    <a class="hc-btn hc-btn-dark" href="../process/downloadUserManual.php?format=pdf">Download full manual</a>
                    <a class="hc-btn hc-btn-light" href="user_manual.php">Preview in system</a>
                    <?php if ($canOpenProfile): ?><a class="hc-btn hc-btn-light" href="profile.php">Review profile</a><?php else: ?><span class="hc-btn hc-btn-disabled">Profile page not assigned</span><?php endif; ?>
                </div>
            </article>
        </section>
    </main>
</div>
<script src="../resources/js/help-center.js" defer></script>
</body>
</html>
