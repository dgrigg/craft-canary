<?php

namespace dgrigg\canary\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Canary settings. Every value can also be set in config/canary.php,
 * which takes precedence over the control panel.
 */
class Settings extends Model
{
    /**
     * Who receives the report. Comma-separated; accepts an env var such as $CANARY_ALERT_EMAIL.
     */
    public string $alertEmail = '$CANARY_ALERT_EMAIL';

    /**
     * Send the report on clean runs too (the daily "all clear").
     * When off, email only goes out when something is wrong.
     */
    public bool $sendAllClear = true;

    /**
     * Directories and files to hash, relative to the project root.
     * Files listed directly are hashed regardless of extension.
     */
    public array $scanPaths = [
        'bootstrap.php',
        'craft',
        'config',
        'modules',
        'templates',
        'web',
    ];

    /**
     * Extensions hashed when walking a directory.
     */
    public array $fileExtensions = ['php', 'twig'];

    /**
     * Paths skipped while scanning. A plain name ("node_modules") matches any
     * path segment; a name containing a slash ("web/cpresources") matches a path prefix.
     */
    public array $excludePaths = [
        'node_modules',
        '.git',
    ];

    /**
     * Run the server-level checks (processes, /tmp, CPU, outbound
     * connections, Redis, crontab). These expect a Linux server.
     */
    public bool $enableSystemChecks = true;

    /**
     * CPU percentage above which a running process is reported.
     */
    public int $cpuThreshold = 80;

    /**
     * Remote ports commonly used by crypto-mining pools.
     */
    public array $miningPorts = [3333, 4444, 10300, 14444, 45700];

    /**
     * Inspect a crontab for unexpected entries. Turn off when the scan runs as
     * a user that can't read the crontab worth checking.
     */
    public bool $enableCrontabCheck = true;

    /**
     * Whose crontab to inspect. Leave empty for the user running the scan.
     */
    public string $cronUser = '';

    /**
     * Crontab lines containing any of these strings are expected.
     */
    public array $allowedCronPatterns = ['craft', 'artisan', 'composer', 'apt-get'];

    /**
     * List settings arrive from the settings form as newline-separated text.
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        foreach (['scanPaths', 'fileExtensions', 'excludePaths', 'miningPorts', 'allowedCronPatterns'] as $key) {
            if (isset($values[$key]) && is_string($values[$key])) {
                $values[$key] = self::splitList($values[$key]);
            }
        }

        if (isset($values['miningPorts'])) {
            $values['miningPorts'] = array_values(array_map('intval', $values['miningPorts']));
        }

        if (isset($values['fileExtensions'])) {
            $values['fileExtensions'] = array_values(array_map(fn($ext) => strtolower(ltrim($ext, '.')), $values['fileExtensions']));
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * @return string[]
     */
    public function getAlertEmails(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) App::parseEnv($this->alertEmail)))));
    }

    protected function defineRules(): array
    {
        return [
            [['scanPaths', 'fileExtensions'], 'required'],
            [['cpuThreshold'], 'integer', 'min' => 1, 'max' => 100],
            [['alertEmail'], function($attribute) {
                foreach ($this->getAlertEmails() as $email) {
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $this->addError($attribute, "“{$email}” is not a valid email address.");
                    }
                }
            }],
        ];
    }

    private static function splitList(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value))));
    }
}
