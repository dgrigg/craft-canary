<?php

namespace dgrigg\canary;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\log\MonologTarget;
use craft\services\Utilities;
use Psr\Log\LogLevel;
use yii\base\Event;

use dgrigg\canary\models\Settings;
use dgrigg\canary\services\Integrity;
use dgrigg\canary\services\Reports;
use dgrigg\canary\services\Scanner;
use dgrigg\canary\services\SystemHealth;
use dgrigg\canary\utilities\CanaryUtility;

/**
 * Canary plugin for Craft CMS
 *
 * Daily file integrity and server health checks, with an emailed report.
 *
 * @author    Derrick Grigg
 * @copyright Copyright (c) 2026 DGrigg Development Inc.
 * @link      https://dgrigg.com
 * @package   Canary
 * @since     1.0.0
 *
 * @property-read Integrity $integrity
 * @property-read SystemHealth $systemHealth
 * @property-read Reports $reports
 * @property-read Scanner $scanner
 * @method Settings getSettings()
 */
class Canary extends Plugin
{
    public static Canary $plugin;

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'integrity' => Integrity::class,
                'systemHealth' => SystemHealth::class,
                'reports' => Reports::class,
                'scanner' => Scanner::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        // Everything Canary logs goes to storage/logs/canary-YYYY-MM-DD.log
        Craft::getLogger()->dispatcher->targets['canary'] = new MonologTarget([
            'name' => 'canary',
            'categories' => ['canary'],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => true,
        ]);

        // Utilities → Canary: run a scan from the control panel
        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = CanaryUtility::class;
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('canary/settings', [
            'settings' => $this->getSettings(),
            'baseline' => $this->integrity->getBaselineInfo(),
        ]);
    }
}
