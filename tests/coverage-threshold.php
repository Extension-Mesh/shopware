<?php declare(strict_types=1);

$path = $argv[1] ?? null;
$minimum = isset($argv[2]) ? (float) $argv[2] : 30.0;
if (!\is_string($path) || !\is_file($path)) {
    \fwrite(\STDERR, "Coverage report is missing.\n");
    exit(2);
}

$report = \simplexml_load_file($path);
if (!$report instanceof \SimpleXMLElement) {
    \fwrite(\STDERR, "Coverage report is not valid XML.\n");
    exit(2);
}
$project = $report->project;
$metrics = $project instanceof \SimpleXMLElement ? $project->metrics : null;
if (!$metrics instanceof \SimpleXMLElement) {
    \fwrite(\STDERR, "Coverage report has no project metrics.\n");
    exit(2);
}

$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
if ($statements < 1) {
    \fwrite(\STDERR, "Coverage report contains no executable statements.\n");
    exit(2);
}

$percentage = 100 * $covered / $statements;
\printf("Line coverage: %.2f%% (%d/%d), required: %.2f%%\n", $percentage, $covered, $statements, $minimum);
if ($percentage + 0.00001 < $minimum) {
    \fwrite(\STDERR, "Coverage threshold was not met.\n");
    exit(1);
}
