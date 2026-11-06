<?php
declare(strict_types=1);

/**
 * Version 49: Data Import Management (the import of the former water program's Account Master and Billing Master
 * files) is removed, and so is the "Technical prerequisites" step of the water set-up guide (it blocked every
 * business transaction while storage/imports was missing or not writable, a folder only the import used).
 * The pages are checked over HTTP (tests/water/e2e_http.php, section v49).
 */

require_once APP_ROOT . '/water/common/setupReadinessHelpers.php';

test('Version 49: the set-up guide has no Technical prerequisites or Import readiness step, and nothing depends on them', function () {
    $pdo = DB::pdo();
    $catalog = sr_requirement_catalog();
    check(!isset($catalog['technical']) && !isset($catalog['import']), 'no technical or import step');
    foreach ($catalog as $key => $definition) {
        check(!array_intersect(['technical', 'import'], $definition['depends_on']), "$key depends on neither");
        check($definition['destination_page_key'] !== 'import_management', "$key does not lead to Data Import Management");
        foreach ($definition['depends_on'] as $dep) {
            check(isset($catalog[$dep]), "$key depends on $dep, a step that exists");
        }
    }
    $actions = sr_action_requirements();
    check(!isset($actions['import']) && !isset($actions['import_members']), 'no import action group');
    foreach ($actions as $action => $keys) {
        foreach ($keys as $k) {
            check(isset($catalog[$k]), "action $action needs $k, a step that exists");
        }
    }
    // version 50 also takes out Billing readiness (an open billing period): tests/coop/cases_v50.php
    eq(sr_core_keys(), ['administrator', 'institution', 'organization', 'accounts', 'bill_codes', 'tariff'], 'the required steps');
    check(!in_array('import_management', sr_setup_route_allowlist(), true), 'Data Import Management is not a set-up route');
    eq(sr_page_module('import_management'), null, 'no module of the guide starts on the import page');
    $all = sr_evaluate($pdo, true);
    eq(array_keys($all), array_keys($catalog), 'every step evaluated');
    foreach ($all as $key => $item) {
        check(stripos((string)$item['message'], 'storage/imports') === false, "$key says nothing about storage/imports");
    }
    $summary = sr_summary($pdo, 'dashboard', true);
    eq($summary['total_steps'], 6, 'the guide has 6 required steps (8 before version 49)');
    eq($summary['steps'][0]['key'], 'administrator', 'its first step');
});

test('Version 49: Data Import Management is gone from the program; a leftover copy of its page or endpoints is refused', function () {
    check(Access::pageDef('water.import_management') === null, 'not in the page registry (menu, page access)');
    check(!isset(WaterPages::PAGES['import_management']) && !isset(WaterPages::ALIASES['import_management']) && !WaterPages::isRetired('import_management'),
        'not a water page, an alias or a retired page');
    check(access_resolve_key('import_management') === null, 'its key resolves to no page: the page and its endpoints are refused, administrators included');
    foreach (['stageImportCsv', 'stageImportChunk', 'runImportChunk', 'revalidateImportBatch', 'refreshImportReconciliation', 'updateImportBatchOptions',
                 'saveImportMappingRule', 'downloadImportIssues'] as $script) {
        eq(WaterPages::processPage($script), '', "$script is mapped to no page");
        check(!is_file(APP_ROOT . "/water/process/$script.php"), "$script.php is removed");
    }
    check(!is_file(APP_ROOT . '/water/views/import_management.php'), 'the page is removed');
    check(!is_file(APP_ROOT . '/water/common/dataImportHelpers.php') && !is_file(APP_ROOT . '/water/common/dataImportMappingHelpers.php'), 'its helpers are removed');
    check(!is_dir(APP_ROOT . '/water/storage/imports'), 'its upload folder is removed');
    // the database keeps its row and tables (nothing to run): Page Access and Roles offer only the pages the program has
    eq((int)DB::value("SELECT COUNT(*) FROM pages WHERE page_key = 'water.import_management'"), 1, 'the row of the page is still in the database');
    foreach (['modules/admin/page_access.php', 'modules/admin/roles.php'] as $file) {
        check(str_contains((string)file_get_contents(APP_ROOT . '/' . $file), "Access::pageDef((string)\$pg['page_key']) !== null"), "$file offers only the pages the program has");
    }
    check(!array_intersect(['data_import_mapping_profiles', 'data_import_mapping_rules'], array_keys(Provision::COPY)), 'a new company does not get the import mapping lists');
    $settings = (string)file_get_contents(APP_ROOT . '/water/views/settings.php');
    check(!str_contains($settings, 'import_management'), 'Water Settings has no Data Import Management card');
});
