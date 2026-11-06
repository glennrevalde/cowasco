<?php
declare(strict_types=1);

/**
 * Compares the two outputs of tests/water/baseline_calc.php (former program on the former database, this system on
 * the migrated database). Amounts must be identical; the account ids are compared through water_coa_map (former
 * AccntID -> accounts.id) when --map is given as a TSV of "legacy_id<TAB>account_id".
 *
 *   php tests/water/compare_baseline.php before.jsonl after.jsonl [--map=coa_map.tsv]
 * Exit code 0 = identical, 1 = differences (listed).
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$args = array_values(array_filter(array_slice($argv, 1), fn($a) => !str_starts_with($a, '--')));
$opts = getopt('', ['map:']);
[$beforeFile, $afterFile] = $args + [null, null];
if (!$beforeFile || !$afterFile) {
    fwrite(STDERR, "usage: compare_baseline.php before.jsonl after.jsonl [--map=file]\n");
    exit(2);
}
$map = [];
if (!empty($opts['map'])) {
    foreach (file((string)$opts['map'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        [$a, $b] = array_pad(explode("\t", $l), 2, '');
        $map[(int)$a] = (int)$b;
    }
}
$load = function (string $f): array {
    $out = [];
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $r = json_decode($l, true);
        $out[$r['account'] . ':' . $r['cons']] = $r;
    }
    return $out;
};
$before = $load($beforeFile);
$after = $load($afterFile);
$diffs = [];
$same = 0;
$errorsBoth = 0;
foreach ($before as $k => $b) {
    $a = $after[$k] ?? null;
    if ($a === null) {
        $diffs[] = "$k: missing after the migration";
        continue;
    }
    if (!$b['ok'] || !$a['ok']) {
        if (!$b['ok'] && !$a['ok']) {
            $errorsBoth++;      // the same configuration problem on both sides (e.g. an incomplete tariff)
            continue;
        }
        $diffs[] = "$k: before " . ($b['ok'] ? 'ok' : 'error ' . $b['error']) . ', after ' . ($a['ok'] ? 'ok' : 'error ' . $a['error']);
        continue;
    }
    foreach (['base', 'discount', 'due', 'billable', 'rc', 'pipe', 'income', 'ar'] as $f) {
        if ($b[$f] !== $a[$f]) {
            $diffs[] = "$k: $f " . json_encode($b[$f]) . ' -> ' . json_encode($a[$f]);
            continue 2;
        }
    }
    if ($map) {
        foreach (['income_acct', 'ar_acct'] as $f) {
            $mapped = array_map(fn($id) => $map[$id] ?? -1, $b[$f]);
            if ($mapped !== $a[$f]) {
                $diffs[] = "$k: $f " . json_encode($b[$f]) . ' (mapped ' . json_encode($mapped) . ') -> ' . json_encode($a[$f]);
                continue 2;
            }
        }
    }
    $same++;
}
$extra = count(array_diff_key($after, $before));
printf("compared %d calculations: %d identical, %d failing the same way on both sides (configuration), %d different, %d only after\n",
    count($before), $same, $errorsBoth, count($diffs), $extra);
foreach (array_slice($diffs, 0, 40) as $d) {
    echo "  DIFF $d\n";
}
exit($diffs ? 1 : 0);
