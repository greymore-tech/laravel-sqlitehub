<?php

// Fails unless every executable line in src/ is covered.
// Usage: php tests/coverage-check.php coverage.xml   (PHPUnit --coverage-clover output)

$xml = simplexml_load_file($argv[1] ?? 'coverage.xml') ?: exit("Can't read the clover report\n");
$missing = [];
foreach ($xml->xpath('//file') as $file) {
    foreach ($file->line as $line) {
        if ((string) $line['type'] === 'stmt' && (int) $line['count'] === 0) {
            $missing[preg_replace('#^.*?/src/#', 'src/', (string) $file['name'])][] = (int) $line['num'];
        }
    }
}
$metrics = $xml->project->metrics;
printf("Lines covered: %d/%d\n", $metrics['coveredstatements'], $metrics['statements']);
foreach ($missing as $name => $lines) {
    echo "  {$name}: ".implode(',', $lines)."\n";
}
exit($missing === [] ? 0 : 1);
