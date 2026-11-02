<?php

namespace dgrigg\canary\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Creates the baseline table on a fresh install, and drops it on uninstall.
 */
class Install extends Migration
{
    public const BASELINES = '{{%sitecanary_baselines}}';

    public function safeUp(): bool
    {
        self::createBaselineTable($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::BASELINES);

        return true;
    }

    /**
     * Shared with the 1.1.0 update migration, so the two can't drift.
     */
    public static function createBaselineTable(Migration $m): void
    {
        if ($m->db->tableExists(self::BASELINES)) {
            return;
        }

        $m->createTable(self::BASELINES, [
            'id' => $m->primaryKey(),
            'fileCount' => $m->integer()->notNull(),
            // JSON {path: sha256}. text() is 64 KB on MySQL, which a few hundred files fills
            'checksums' => $m->mediumText()->notNull(),
            'source' => $m->string(20)->notNull(),
            'userId' => $m->integer(),
            'systemUser' => $m->string(),
            'hostname' => $m->string(),
            'dateCreated' => $m->dateTime()->notNull(),
            'dateUpdated' => $m->dateTime()->notNull(),
            'uid' => $m->uid(),
        ]);

        $m->createIndex(null, self::BASELINES, ['dateCreated']);
        $m->addForeignKey(null, self::BASELINES, ['userId'], Table::USERS, ['id'], 'SET NULL');
    }
}
