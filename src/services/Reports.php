<?php

namespace dgrigg\canary\services;

use Craft;
use craft\web\View;
use yii\base\Component;

use dgrigg\canary\Canary;

/**
 * Emails the scan report.
 */
class Reports extends Component
{
    /**
     * Send the report for a scan result. Returns the recipients it went to,
     * or an empty array when nothing was sent. With $force, a clean result is
     * sent even when "all clear" emails are turned off.
     *
     * @throws \Throwable if the mailer fails
     */
    public function send(array $result, bool $force = false): array
    {
        $settings = Canary::$plugin->getSettings();
        $recipients = $settings->getAlertEmails();
        $issueCount = Scanner::issueCount($result);

        if (!$recipients) {
            Craft::warning('No report recipients configured. Set CANARY_ALERT_EMAIL in .env.', 'canary');
            return [];
        }

        if (!$force && !$result['error'] && $issueCount === 0 && !$settings->sendAllClear) {
            return [];
        }

        $domain = parse_url(Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? '', PHP_URL_HOST)
            ?: Craft::$app->getSystemName();

        if ($result['error']) {
            $subject = "[SECURITY] Site Canary Error - {$domain}";
        } elseif ($issueCount > 0) {
            $subject = "[SECURITY ALERT] Site Canary Found {$issueCount} Issue" . ($issueCount === 1 ? '' : 's') . " - {$domain}";
        } else {
            $subject = "[Security] Daily Site Canary Report - {$domain}";
        }

        $variables = [
            'result' => $result,
            'issueCount' => $issueCount,
            'systemChecks' => $settings->enableSystemChecks,
            'domain' => $domain,
            'serverName' => gethostname(),
            'systemName' => Craft::$app->getSystemName(),
        ];

        $view = Craft::$app->getView();
        $html = $view->renderTemplate('site-canary/_emails/report', $variables, View::TEMPLATE_MODE_CP);
        $text = $view->renderTemplate('site-canary/_emails/report.txt', $variables, View::TEMPLATE_MODE_CP);

        $sent = Craft::$app->getMailer()->compose()
            ->setTo($recipients)
            ->setSubject($subject)
            ->setHtmlBody($html)
            ->setTextBody($text)
            ->send();

        if (!$sent) {
            throw new \RuntimeException('The mailer did not accept the message.');
        }

        return $recipients;
    }
}
