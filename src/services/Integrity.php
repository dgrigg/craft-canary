<?php

namespace dgrigg\canary\services;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use yii\base\Component;

use dgrigg\canary\Canary;
use dgrigg\canary\errors\BaselineException;
use dgrigg\canary\migrations\Install;

/**
 * Hashes the monitored files and compares them against a stored baseline.
 *
 * Baselines live in the database, so the deploy, cron and every web instance
 * see the same one, even on hosts with ephemeral or per-instance disks.
 */
class Integrity extends Component
{
    /**
     * Overridable for testing; defaults to the project root.
     */
    public ?string $rootPath = null;

    /**
     * Baselines kept after each rebuild. The newest is the active one; the rest
     * record who rebuilt it and when.
     */
    public int $baselineHistory = 10;

    public function init(): void
    {
        parent::init();
        $this->rootPath ??= Craft::getAlias('@root');
        $this->rootPath = rtrim($this->rootPath, '/\\');
    }

    /**
     * Hash every monitored file and save the result as the new baseline.
     * Run after every deployment.
     *
     * @param string $source `console`, `cp` or `migration`
     */
    public function createBaseline(string $source = 'console', ?int $userId = null): array
    {
        $checksums = $this->scanFiles();
        $generatedAt = new \DateTime();

        Db::insert(Install::BASELINES, [
            'fileCount' => count($checksums),
            'checksums' => Json::encode($checksums, JSON_UNESCAPED_SLASHES),
            'source' => $source,
            'userId' => $userId,
            'systemUser' => self::systemUser(),
            'hostname' => gethostname() ?: null,
            'dateCreated' => Db::prepareDateForDb($generatedAt),
        ]);

        $id = (int)Craft::$app->getDb()->getLastInsertID(Install::BASELINES);
        $this->pruneBaselines();

        return [
            'id' => $id,
            'generated_at' => $generatedAt->format(DATE_ATOM),
            'file_count' => count($checksums),
            'checksums' => $checksums,
        ];
    }

    /**
     * The active (newest) baseline, or null when none has been stored.
     *
     * @throws BaselineException if the stored checksums can't be decoded
     */
    public function getBaseline(): ?array
    {
        $row = $this->baselineQuery()
            ->addSelect(['checksums'])
            ->one();

        if (!$row) {
            return null;
        }

        $checksums = Json::decodeIfJson($row['checksums']);

        if (!is_array($checksums)) {
            throw new BaselineException("Baseline #{$row['id']} is corrupt; rebuild it.");
        }

        return [
            'id' => (int)$row['id'],
            'generated_at' => DateTimeHelper::toDateTime($row['dateCreated'])->format(DATE_ATOM),
            'file_count' => (int)$row['fileCount'],
            'checksums' => $checksums,
            'info' => $this->formatInfo($row),
        ];
    }

    /**
     * The active baseline's date, file count and origin, without the checksums.
     */
    public function getBaselineInfo(): ?array
    {
        $row = $this->baselineQuery()->one();

        return $row ? $this->formatInfo($row) : null;
    }

    /**
     * Recent baselines, newest first, without the checksums.
     */
    public function getBaselineHistory(int $limit = 10): array
    {
        return array_map(
            fn(array $row) => $this->formatInfo($row),
            $this->baselineQuery()->limit($limit)->all()
        );
    }

    private function baselineQuery(): Query
    {
        return (new Query())
            ->select(['id', 'fileCount', 'source', 'userId', 'systemUser', 'hostname', 'dateCreated'])
            ->from(Install::BASELINES)
            // id breaks ties between two rebuilds in the same second
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);
    }

    private function formatInfo(array $row): array
    {
        $username = $row['userId']
            ? Craft::$app->getUsers()->getUserById((int)$row['userId'])?->username
            : null;

        $info = [
            'id' => (int)$row['id'],
            'generatedAt' => DateTimeHelper::toDateTime($row['dateCreated']),
            'fileCount' => (int)$row['fileCount'],
            'source' => $row['source'],
            'userId' => $row['userId'] ? (int)$row['userId'] : null,
            'username' => $username,
            'systemUser' => $row['systemUser'],
            'hostname' => $row['hostname'],
        ];
        $info['summary'] = self::summarize($info);

        return $info;
    }

    /**
     * How a baseline was made, e.g. "Created from the console by forge on web-1".
     */
    private static function summarize(array $info): string
    {
        $summary = match ($info['source']) {
            'console' => 'Created from the console' . ($info['systemUser'] ? " by {$info['systemUser']}" : ''),
            'cp' => 'Rebuilt in the control panel by ' . ($info['username'] ?? ($info['userId'] ? "user #{$info['userId']}" : 'a deleted user')),
            'migration' => 'Imported from storage/canary/baseline.json when updating to 1.1.0',
            default => "Created by {$info['source']}",
        };

        return $summary . ($info['hostname'] ? " on {$info['hostname']}" : '');
    }

    /**
     * Keep only the newest $baselineHistory rows.
     */
    private function pruneBaselines(): void
    {
        $keep = (new Query())
            ->select(['id'])
            ->from(Install::BASELINES)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(max(1, $this->baselineHistory))
            ->column();

        Db::delete(Install::BASELINES, ['not', ['id' => $keep]]);
    }

    /**
     * The OS account running this process, e.g. `forge` or `www-data`.
     */
    private static function systemUser(): ?string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $name = posix_getpwuid(posix_geteuid())['name'] ?? null;

            if ($name) {
                return $name;
            }
        }

        return get_current_user() ?: null;
    }

    /**
     * Compare the current files against the baseline.
     *
     * @return array{added: string[], modified: string[], deleted: string[], fileCount: int}
     */
    public function compare(array $baseline): array
    {
        $current = $this->scanFiles();
        $result = ['added' => [], 'modified' => [], 'deleted' => [], 'fileCount' => count($current)];

        foreach ($baseline['checksums'] as $file => $hash) {
            if (!isset($current[$file])) {
                $result['deleted'][] = $file;
            } elseif ($current[$file] !== $hash) {
                $result['modified'][] = $file;
            }
        }

        foreach (array_keys(array_diff_key($current, $baseline['checksums'])) as $file) {
            $result['added'][] = $file;
        }

        return $result;
    }

    /**
     * SHA-256 of every monitored file, keyed by path relative to the project root.
     *
     * @return array<string, string>
     */
    public function scanFiles(): array
    {
        $settings = Canary::$plugin->getSettings();
        $checksums = [];

        foreach ($settings->scanPaths as $path) {
            $path = trim($path, '/\\');
            $fullPath = $this->rootPath . '/' . $path;

            if ($path === '' || $this->isExcluded($path, $settings->excludePaths)) {
                continue;
            }

            // Files listed directly are always hashed, whatever their extension
            if (is_file($fullPath)) {
                $checksums[$path] = hash_file('sha256', $fullPath);
                continue;
            }

            if (!is_dir($fullPath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullPath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || !in_array(strtolower($file->getExtension()), $settings->fileExtensions, true)) {
                    continue;
                }

                $relativePath = substr($file->getPathname(), strlen($this->rootPath) + 1);

                if (!$this->isExcluded($relativePath, $settings->excludePaths)) {
                    $checksums[$relativePath] = hash_file('sha256', $file->getPathname());
                }
            }
        }

        ksort($checksums);

        return $checksums;
    }

    private function isExcluded(string $relativePath, array $excludePaths): bool
    {
        foreach ($excludePaths as $pattern) {
            $pattern = trim($pattern, '/\\');

            if ($pattern === '') {
                continue;
            }

            if (str_contains($pattern, '/')) {
                if ($relativePath === $pattern || str_starts_with($relativePath, $pattern . '/')) {
                    return true;
                }
            } elseif (in_array($pattern, explode('/', $relativePath), true)) {
                return true;
            }
        }

        return false;
    }
}
