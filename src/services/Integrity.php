<?php

namespace dgrigg\canary\services;

use Craft;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use yii\base\Component;

use dgrigg\canary\Canary;

/**
 * Hashes the monitored files and compares them against a stored baseline.
 */
class Integrity extends Component
{
    /**
     * Overridable for testing; defaults to the project root.
     */
    public ?string $rootPath = null;

    /**
     * Overridable for testing; defaults to storage/canary/baseline.json.
     */
    public ?string $baselineFile = null;

    public function init(): void
    {
        parent::init();
        $this->rootPath ??= Craft::getAlias('@root');
        $this->rootPath = rtrim($this->rootPath, '/\\');
        $this->baselineFile ??= Craft::$app->getPath()->getStoragePath() . '/canary/baseline.json';
    }

    /**
     * Hash every monitored file and save the result as the new baseline.
     * Run after every deployment.
     */
    public function createBaseline(): array
    {
        $checksums = $this->scanFiles();

        $baseline = [
            'generated_at' => (new \DateTime())->format(DATE_ATOM),
            'file_count' => count($checksums),
            'checksums' => $checksums,
        ];

        FileHelper::writeToFile($this->baselineFile, Json::encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // The baseline is per-server; keep it out of the project's repo
        $gitignore = dirname($this->baselineFile) . '/.gitignore';
        if (!is_file($gitignore)) {
            FileHelper::writeToFile($gitignore, "*\n");
        }

        return $baseline;
    }

    public function getBaseline(): ?array
    {
        if (!is_file($this->baselineFile)) {
            return null;
        }

        $baseline = Json::decodeIfJson(file_get_contents($this->baselineFile));

        return is_array($baseline) && isset($baseline['checksums']) ? $baseline : null;
    }

    /**
     * Baseline date and file count, without the checksums.
     */
    public function getBaselineInfo(): ?array
    {
        $baseline = $this->getBaseline();

        return $baseline ? [
            'generatedAt' => new \DateTime($baseline['generated_at']),
            'fileCount' => $baseline['file_count'],
            'path' => $this->baselineFile,
        ] : null;
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
