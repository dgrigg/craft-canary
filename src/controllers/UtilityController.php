<?php

namespace dgrigg\canary\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;

use dgrigg\canary\Canary;
use dgrigg\canary\services\Scanner;

/**
 * Runs a scan from Utilities → Canary.
 */
class UtilityController extends Controller
{
    public function actionScan(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('utility:canary');

        $mutex = Craft::$app->getMutex();
        $lockName = 'canary-scan';

        if (!$mutex->acquire($lockName)) {
            return $this->asFailure('Another Canary scan is already running.');
        }

        try {
            $result = Canary::$plugin->scanner->run();
        } finally {
            $mutex->release($lockName);
        }

        $issueCount = Scanner::issueCount($result);
        $recipients = [];
        $emailError = null;

        // A manual run always sends the report, even when "all clear" emails are off
        try {
            $recipients = Canary::$plugin->reports->send($result, true);
        } catch (\Throwable $e) {
            $emailError = $e->getMessage();
            Craft::error('Report email failed: ' . $emailError, 'canary');
        }

        $html = $this->getView()->renderTemplate('canary/_utility/results', [
            'result' => $result,
            'issueCount' => $issueCount,
            'systemChecks' => Canary::$plugin->getSettings()->enableSystemChecks,
            'recipients' => $recipients,
            'emailError' => $emailError,
        ]);

        if ($result['error']) {
            $message = 'Scan failed.';
        } elseif ($issueCount > 0) {
            $message = "Canary found {$issueCount} issue" . ($issueCount === 1 ? '' : 's') . '.';
        } else {
            $message = 'All clear.';
        }

        return $this->asJson([
            'message' => $message,
            'clean' => !$result['error'] && $issueCount === 0,
            'html' => $html,
        ]);
    }

    /**
     * Rebuilding accepts every file as it is now, so it needs an elevated
     * session and is logged with who did it.
     */
    public function actionRebuildBaseline(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('utility:canary');
        $this->requireElevatedSession();

        $mutex = Craft::$app->getMutex();
        $lockName = 'canary-scan';

        if (!$mutex->acquire($lockName)) {
            return $this->asFailure('A Canary scan is running. Try again when it finishes.');
        }

        try {
            $baseline = Canary::$plugin->integrity->createBaseline();
        } finally {
            $mutex->release($lockName);
        }

        $user = Craft::$app->getUser()->getIdentity();
        Craft::warning("Baseline rebuilt from the control panel by {$user->username} (ID {$user->id}): {$baseline['file_count']} files.", 'canary');

        return $this->asJson([
            'message' => "Baseline rebuilt with {$baseline['file_count']} files.",
            'baselineHtml' => $this->getView()->renderTemplate('canary/_utility/baseline', [
                'baseline' => Canary::$plugin->integrity->getBaselineInfo(),
            ]),
        ]);
    }
}
