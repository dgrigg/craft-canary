<?php
/**
 * Canary config.
 *
 * Copy this file to your project's config/ folder as site-canary.php. Anything set
 * here overrides the plugin settings in the control panel. Multi-environment
 * arrays ('*' => [...], 'production' => [...]) are supported.
 */

return [
    // Who receives the report. Comma-separated; env vars allowed.
    'alertEmail' => '$CANARY_ALERT_EMAIL',

    // Email clean runs too (the daily "all clear").
    'sendAllClear' => true,

    // Directories and files to hash, relative to the project root.
    'scanPaths' => ['bootstrap.php', 'craft', 'config', 'modules', 'templates', 'web'],

    // Extensions hashed when walking a directory.
    'fileExtensions' => ['php', 'twig'],

    // Plain names match any path segment; names with a slash match a path prefix.
    'excludePaths' => ['node_modules', '.git'],

    // Server-level checks (Linux).
    'enableSystemChecks' => true,
    'cpuThreshold' => 80,
    'miningPorts' => [3333, 4444, 10300, 14444, 45700],
    'enableCrontabCheck' => true,
    'cronUser' => '',
    'allowedCronPatterns' => ['craft', 'artisan', 'composer', 'apt-get'],
];
