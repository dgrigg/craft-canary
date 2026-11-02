<?php

namespace dgrigg\canary\services;

use Craft;
use yii\base\Component;

use dgrigg\canary\Canary;
use dgrigg\canary\errors\BaselineException;
use dgrigg\canary\migrations\Install;

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
     *     baselineId: ?int,
     *     baselineSummary: ?string,
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
            'baselineId' => null,
            'baselineSummary' => null,
            'checkedAt' => new \DateTime(),
        ];

        try {
            $baseline = $plugin->integrity->getBaseline();
            $result['error'] = $baseline ? null : 'No baseline stored. Run: php craft site-canary/scan --baseline';
        } catch (BaselineException $e) {
            $result['error'] = $e->getMessage();
        } catch (\yii\db\Exception $e) {
            if (Craft::$app->getDb()->tableExists(Install::BASELINES, true)) {
                throw $e;
            }

            $result['error'] = 'Site Canary tables are missing. Run: php craft up';
        }

        if ($result['error']) {
            Craft::error($result['error'], 'canary');

            return $result;
        }

        $result = array_merge($result, $plugin->integrity->compare($baseline));
        $result['baselineDate'] = new \DateTime($baseline['generated_at']);
        $result['baselineId'] = $baseline['id'];
        $result['baselineSummary'] = $baseline['info']['summary'];

        if ($plugin->getSettings()->enableSystemChecks) {
            $result['system'] = $plugin->systemHealth->check();
        }

        if (self::issueCount($result) > 0) {
            Craft::error('File integrity check failed: ' . json_encode([
                'added' => $result['added'],
                'modified' => $result['modified'],
                'deleted' => $result['deleted'],
                'system' => $result['system'],
                'baseline_id' => $baseline['id'],
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
