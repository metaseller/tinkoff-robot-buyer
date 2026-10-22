<?php

// This check works before Composer dependencies are installed.
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=UTF-8');
}

$checks = ['PHP 8.5.12 (8.5.x)' => PHP_VERSION_ID >= 80512 && PHP_VERSION_ID < 80600];
$manifest = json_decode(file_get_contents(__DIR__ . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($manifest['require'] as $requirement => $constraint) {
    if (str_starts_with($requirement, 'ext-')) {
        $extension = substr($requirement, 4);
        $checks[$requirement] = extension_loaded($extension);
    }
}
$grpcVersion = phpversion('grpc') ?: '0';
$checks['gRPC >=1.83.1 <2.0'] = version_compare($grpcVersion, '1.83.1', '>=')
    && version_compare($grpcVersion, '2.0', '<');
foreach ($checks as $name => $passed) {
    echo ($passed ? '[OK] ' : '[FAIL] ') . $name . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
