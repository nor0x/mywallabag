<?php

namespace Application\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Wallabag\CoreBundle\Doctrine\WallabagMigration;

/**
 * Added index on wallabag_entry.uid.
 */
class Version20161214094403 extends WallabagMigration
{
    private $indexName = 'IDX_entry_uid';

    /**
     * @param Schema $schema
     */
    public function up(Schema $schema)
    {
        $entryTable = $schema->getTable($this->getTable('entry'));
        $this->skipIf($entryTable->hasIndex($this->indexName), 'It seems that you already played this migration.');

        $entryTable->addIndex(['uid'], $this->indexName);
    }

    /**
     * @param Schema $schema
     */
    public function down(Schema $schema)
    {
        $entryTable = $schema->getTable($this->getTable('entry'));
        $this->skipIf(false === $entryTable->hasIndex($this->indexName), 'It seems that you already played this migration.');

        $entryTable->dropIndex($this->indexName);
    }
}
