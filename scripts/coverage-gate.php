#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Reads a Clover report and fails unless every executable line of app/ ran under the tests.
 * "100 %" is only meaningful if nothing is excluded, which the architecture test enforces.
 *
 * Usage: php scripts/coverage-gate.php build/coverage/clover.xml
 */

$report = $argv[1] ?? 'build/coverage/clover.xml';
$clover = @simplexml_load_file($report);

if ($clover === false) {
    fwrite(STDERR, "Coverage gate: cannot read $report. Run composer test:coverage first.\n");
    exit(2);
}

$total = 0;
$covered = 0;
$missed = [];

foreach ($clover->xpath('//file') ?: [] as $file) {
    $name = (string) $file['name'];

    foreach ($file->line as $line) {
        if ((string) $line['type'] !== 'stmt') {
            continue;
        }

        $total++;

        if ((int) $line['count'] > 0) {
            $covered++;
        } else {
            $missed[] = sprintf('%s:%d', $name, (int) $line['num']);
        }
    }
}

if ($total === 0) {
    fwrite(STDERR, "Coverage gate: the report contains no executable lines.\n");
    exit(2);
}

$percent = $covered / $total * 100;

if ($missed !== []) {
    fwrite(STDERR, sprintf("Coverage gate: %.2f%% (%d/%d lines). Uncovered:\n  %s\n", $percent, $covered, $total, implode("\n  ", $missed)));
    exit(1);
}

echo sprintf("Coverage gate: 100%% line coverage (%d/%d lines).\n", $covered, $total);
