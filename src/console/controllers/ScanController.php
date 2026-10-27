<?php

namespace dgrigg\canary\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

use dgrigg\canary\Canary;
use dgrigg\canary\services\Scanner;

/**
 * Check monitored files for unauthorized modifications, additions, or deletions.
 *
 *   php craft canary/scan              Run the check and email the report
 *   php craft canary/scan --baseline   Generate a new baseline (run after deployments)
 *   php craft canary/scan --dry-run    Show results without sending email
 */
class ScanController extends Controller
{
    public $defaultAction = 'index';

    /**
     * @var bool Generate a new baseline (run after deployments)
     */
    public bool $baseline = false;

    /**
     * @var bool Show results without sending email
     */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['baseline', 'dryRun']);
    }

    public function actionIndex(): int
    {
        $mutex = Craft::$app->getMutex();
        $lockName = 'canary-scan';

        if (!$mutex->acquire($lockName)) {
            $this->stderr("Another Canary scan is already running.\n", Console::FG_YELLOW);
            return ExitCode::TEMPFAIL;
        }

        try {
            return $this->baseline ? $this->generateBaseline() : $this->check();
        } finally {
            $mutex->release($lockName);
        }
    }

    private function generateBaseline(): int
    {
        $this->stdout("Generating file integrity baseline...\n");

        $baseline = Canary::$plugin->integrity->createBaseline();

        $this->stdout("Baseline created with {$baseline['file_count']} files.\n", Console::FG_GREEN);
        $this->stdout('Stored at: ' . Canary::$plugin->integrity->baselineFile . "\n");

        return ExitCode::OK;
    }

    private function check(): int
    {
        $result = Canary::$plugin->scanner->run();

        if ($result['error']) {
            $this->stderr($result['error'] . "\n", Console::FG_RED);
            $this->sendReport($result);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $issueCount = Scanner::issueCount($result);

        if ($issueCount === 0) {
            $this->stdout("All {$result['fileCount']} files match baseline. No integrity issues detected.\n", Console::FG_GREEN);
            $this->sendReport($result);
            return ExitCode::OK;
        }

        $this->stderr("\nCANARY: {$issueCount} ISSUE(S) DETECTED\n\n", Console::FG_RED, Console::BOLD);

        $this->listIssues('NEW FILES (potential threat):', $result['added'], '+', Console::FG_RED);
        $this->listIssues('MODIFIED FILES:', $result['modified'], '~', Console::FG_YELLOW);
        $this->listIssues('DELETED FILES:', $result['deleted'], '-', Console::FG_YELLOW);
        $this->listIssues('SYSTEM HEALTH ALERTS:', $result['system'], '!', Console::FG_RED);

        $this->sendReport($result);

        return ExitCode::UNSPECIFIED_ERROR;
    }

    private function listIssues(string $heading, array $items, string $marker, int $color): void
    {
        if (!$items) {
            return;
        }

        $this->stdout($heading . "\n", $color);

        foreach ($items as $item) {
            $this->stdout("  {$marker} {$item}\n");
        }
    }

    private function sendReport(array $result): void
    {
        if ($this->dryRun) {
            return;
        }

        try {
            $recipients = Canary::$plugin->reports->send($result);

            if ($recipients) {
                $this->stdout('Report sent to: ' . implode(', ', $recipients) . "\n");
            } elseif (!Canary::$plugin->getSettings()->getAlertEmails()) {
                $this->stderr("No email configured. Set CANARY_ALERT_EMAIL in .env\n", Console::FG_YELLOW);
            }
        } catch (\Throwable $e) {
            $this->stderr('Failed to send email: ' . $e->getMessage() . "\n", Console::FG_RED);
            Craft::error('Report email failed: ' . $e->getMessage(), 'canary');
        }
    }
}
