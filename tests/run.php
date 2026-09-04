<?php

require_once __DIR__ . '/bootstrap.php';

$files = [];
foreach (['Unit', 'Integration'] as $subdir) {
    foreach (glob(__DIR__ . '/' . $subdir . '/*.php') as $file) {
        $files[] = $file;
    }
}
sort($files, SORT_STRING);

foreach ($files as $file) {
    require_once $file;
}

$classes = array_values(array_filter(
    get_declared_classes(),
    static fn (string $class): bool => $class !== TestCase::class && is_subclass_of($class, TestCase::class)
));
sort($classes, SORT_STRING);

$total = 0;
$failed = 0;

foreach ($classes as $class) {
    $case = new $class();
    foreach ($case->run() as $result) {
        $total++;
        $status = $result['passed'] ? 'PASS' : 'FAIL';
        $line = sprintf('%s::%s %s (%.2fms)', $class, $result['name'], $status, $result['duration_ms']);
        if (!$result['passed']) {
            $line .= ' - ' . $result['message'];
            $failed++;
        }
        echo $line . PHP_EOL;
    }
}

if ($total === 0) {
    fwrite(STDERR, "No tests were discovered.\n");
    exit(1);
}

exit($failed > 0 ? 1 : 0);
