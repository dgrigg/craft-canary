<?php

namespace dgrigg\canary\utilities;

use Craft;
use craft\base\Utility;

use dgrigg\canary\Canary;

/**
 * Utilities → Site Canary: run a scan from the control panel.
 */
class CanaryUtility extends Utility
{
    public static function displayName(): string
    {
        return 'Site Canary';
    }

    public static function id(): string
    {
        return 'site-canary';
    }

    public static function icon(): ?string
    {
        return Canary::$plugin->getBasePath() . DIRECTORY_SEPARATOR . 'icon-mask.svg';
    }

    public static function contentHtml(): string
    {
        $plugin = Canary::$plugin;

        return Craft::$app->getView()->renderTemplate('site-canary/_utility/index', [
            'baseline' => $plugin->integrity->getBaselineInfo(),
            'recipients' => $plugin->getSettings()->getAlertEmails(),
            'systemChecks' => $plugin->getSettings()->enableSystemChecks,
        ]);
    }
}
