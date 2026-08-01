<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The records table behind the REST + Vue example.
 *
 * Columns mirror application/api/models/RecordDto: in_office is a real boolean
 * to the domain and an int to the database (RecordDto carries #[DbCast('int')]
 * for exactly that reason - Sql binds a PHP false as '' and strict-mode MySQL
 * rejects that for an integer column).
 */
final class CreateRecords extends AbstractMigration
{
    public function change(): void
    {
        $this->table('records', ['signed' => false, 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('name', 'text', ['null' => false])
            ->addColumn('phone', 'text', ['null' => false])
            ->addColumn('in_office', 'boolean', ['null' => false, 'default' => 0, 'signed' => false])
            // nullable on purpose: the client clears the date by sending an
            // explicit null, which has to reach the update as a value
            ->addColumn('out_until', 'datetime', ['null' => true])
            ->create();
    }
}
