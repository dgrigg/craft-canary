<?php

namespace dgrigg\canary\services;

use Craft;
use yii\base\Component;

use dgrigg\canary\Canary;

/**
 * Runs a full Canary scan: file integrity plus system health.
 */
class Scanner extends Component
{
    /**
     * @return array{
     *     error: ?string,
     *     added: string[],
     *     modified: string[],
     *     deleted: string[],
     *     system: string[],
     *     fileCount: int,
     *     baselineDate: ?\DateTime,
     *     checkedAt: \DateTime,
     * }
     */
    public function run(): array
    {
        $plugin = Canary::$plugin;
        $result = [
            'error' => null,
            'added' => [],
            'modified' => [],
            'deleted' => [],
            'system' => [],
            'fileCount' => 0,
            'baselineDate' => null,
            'checkedAt' => new \DateTime(),
        ];

        $baseline = $plugin->integrity->getBaseline();

        if (!$baseline) {
            $result['error'] = 'No baseline file found. Run: php craft site-canary/scan --baseline';
            Craft::error($result['error'], 'canary');

            return $result;
        }

        $result = array_merge($result, $plugin->integrity->compare($baseline));
        $result['baselineDate'] = new \DateTime($baseline['generated_at']);

        if ($plugin->getSettings()->enableSystemChecks) {
            $result['system'] = $plugin->systemHealth->check();
        }

        if (self::issueCount($result) > 0) {
            Craft::error('File integrity check failed: ' . json_encode([
                'added' => $result['added'],
                'modified' => $result['modified'],
                'deleted' => $result['deleted'],
                'system' => $result['system'],
                'baseline_date' => $baseline['generated_at'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 'canary');
        } else {
            Craft::info("All {$result['fileCount']} files match the baseline; system checks passed.", 'canary');
        }

        return $result;
    }

    public static function issueCount(array $result): int
    {
        return count($result['added']) + count($result['modified']) + count($result['deleted']) + count($result['system']);
    }
}
