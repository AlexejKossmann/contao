<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Migration\Version403;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;

/**
 * @internal
 */
class Version430Update extends AbstractMigration
{
    /**
     * @var Connection
     */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function getName(): string
    {
        return 'Contao 4.3.0 Update';
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->getSchemaManager();

        if (!$schemaManager->tablesExist(['tl_layout'])) {
            return false;
        }

        $columns = $schemaManager->listTableColumns('tl_layout');

        return !isset($columns['combinescripts']);
    }

    public function run(): MigrationResult
    {
        $this->connection->query('
            ALTER TABLE
                tl_layout
            CHANGE
                sections sections blob NULL
        ');

        $statement = $this->connection->query("
            SELECT
                id, sections, sPosition
            FROM
                tl_layout
            WHERE
                sections != ''
        ");

        while (false !== ($layout = $statement->fetch(\PDO::FETCH_OBJ))) {
            $sections = StringUtil::trimsplit(',', $layout->sections);

            if (!empty($sections) && \is_array($sections)) {
                $set = [];

                foreach ($sections as $section) {
                    $set[$section] = [
                        'title' => $section,
                        'id' => $section,
                        'template' => 'block_section',
                        'position' => $layout->sPosition,
                    ];
                }

                $stmt = $this->connection->prepare('
                    UPDATE
                        tl_layout
                    SET
                        sections = :sections
                    WHERE
                        id = :id
                ');

                $stmt->execute([':sections' => serialize(array_values($set)), ':id' => $layout->id]);
            }
        }

        $this->connection->query("
            ALTER TABLE
                tl_layout
            ADD
                combineScripts char(1) NOT NULL default ''
        ");

        $this->connection->query("
            UPDATE
                tl_layout
            SET
                combineScripts = '1'
        ");

        return $this->createResult(true);
    }
}
