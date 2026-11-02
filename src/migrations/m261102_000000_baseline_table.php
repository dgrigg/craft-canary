<?php

namespace dgrigg\canary\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;

/**
 * Moves the baseline from storage/canary/baseline.json into the database.
 */
class m261102_000000_baseline_table extends Migration
{
    public function safeUp(): bool
    {
        Install::createBaselineTable($this);

        $dir = Craft::$app->getPath()->getStoragePath() . '/canary';
        $file = $dir . '/baseline.json';

        // On ephemeral hosts there's no file here; the post-deploy --baseline fills the table
        if (!is_file($file)) {
            return true;
        }

        $contents = @file_get_contents($file);
        $baseline = $contents !== false ? Json::decodeIfJson($contents) : null;

        if (!is_array($baseline) || !isset($baseline['checksums']) || !is_array($baseline['checksums'])) {
            Craft::warning("Couldn't import {$file}; rebuild the baseline.", 'canary');
            return true;
        }

        $date = Db::prepareDateForDb($baseline['generated_at'] ?? new \DateTime());

        Db::insert(Install::BASELINES, [
            'fileCount' => count($baseline['checksums']),
            'checksums' => Json::encode($baseline['checksums'], JSON_UNESCAPED_SLASHES),
            'source' => 'migration',
            'dateCreated' => $date,
            'dateUpdated' => $date,
            'uid' => StringHelper::UUID(),
        ], $this->db);

        // Leave nothing behind that looks authoritative
        @unlink($file);
        @unlink($dir . '/.gitignore');

        if (is_dir($dir) && FileHelper::isDirectoryEmpty($dir)) {
            @rmdir($dir);
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261102_000000_baseline_table cannot be reverted.\n";

        return false;
    }
}
