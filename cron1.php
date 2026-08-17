#!/usr/bin/env php
<?php

/**
 * Hostinger Cron Job Helper
 * 
 * This script runs Laravel's schedule runner.
 * In Hostinger hPanel, select the 'PHP' cron job type and set the path to this file.
 */

// Prevent access from the web browser for security
if (php_sapi_name() !== 'cli' && isset($_SERVER['REMOTE_ADDR'])) {
    http_response_code(403);
    die('Forbidden: This script can only be run via the CLI/cron.');
}

$artisanPath = __DIR__ . '/artisan';

// Detect PHP executable path (Hostinger standard is usually /usr/bin/php, or customized paths)
$phpBinary = PHP_BINARY;
if (!is_executable($phpBinary) || str_contains($phpBinary, 'cgi') || str_contains($phpBinary, 'fpm')) {
    $commonPaths = [
        '/opt/alt/php84/usr/bin/php',
        '/usr/bin/php',
        '/usr/local/bin/php',
        'php'
    ];
    foreach ($commonPaths as $path) {
        if (@is_executable($path)) {
            $phpBinary = $path;
            break;
        }
    }
}

$command = escapeshellcmd($phpBinary) . ' ' . escapeshellarg($artisanPath) . ' schedule:run 2>&1';

exec($command, $output, $resultCode);

// Log execution status to laravel.log
if ($resultCode === 0) {
    echo "Cron run completed successfully.\n";
} else {
    echo "Cron run failed with code {$resultCode}.\nOutput:\n" . implode("\n", $output) . "\n";
}
