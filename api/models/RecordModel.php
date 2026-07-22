<?php

declare(strict_types=1);

namespace api\models;

use api\models\RecordDto;
use orange\framework\base\Singleton;
use orange\model\Sql;
use PDO;

/**
 * SQLite-backed CRUD model for the records REST API.
 *
 * The database file location comes from the .env [db] section's `file` key
 * (default var/records.sqlite, relative paths resolve from __ROOT__). The
 * schema is created automatically on connect and sample records are seeded
 * when the database file is brand new.
 */
class RecordModel extends Singleton
{
    protected Sql $sql;

    public function __construct(protected PDO $pdo)
    {
        $this->sql = new Sql([
            'tablename' => 'records',
            'primaryColumn' => 'id',
        ], $pdo);
    }

    /**
     * Return All Records
     *
     * @return recordDto[]
     */
    public function index(): array
    {
        $records = [];

        if ($statement = $this->sql->select('*')->execute()->pdoStatement) {
            while ($row = $statement->fetch()) {
                $records[] = new RecordDto($row);
            }
        }

        return $records;
    }

    /**
     * create a new record and return the id
     *
     * @param recordDto $record
     */
    public function create(recordDto $record): int
    {
        return $this->sql->insert()->set($this->bindings($record))->execute()->lastInsertId();
    }

    /**
     * read a record based on the id passed
     *
     * @return recordDto|null null when no record matches the id
     */
    public function read(int $id): ?recordDto
    {
        $row = $this->sql->select()->wherePrimary($id)->execute()->row();

        return $row === false ? null : new RecordDto($row);
    }

    /**
     * update a record
     *
     * @param recordDto $record
     */
    public function update(recordDto $record): bool
    {
        return $this->sql->update()->set($this->bindings($record))->wherePrimary($record->id)->execute()->rowCount() > 0;
    }

    /**
     * delete record based on id passed
     */
    public function delete(int $id): bool
    {
        return $this->sql->delete()->wherePrimary($id)->execute()->rowCount() > 0;
    }

    /**
     * Column values for insert/update prepared statements.
     *
     * Sql binds every non-int value as a string, so the DTO's boolean
     * in_office must be converted to a real 0/1 — a PHP false binds as ''
     * which strict-mode MySQL rejects for an integer column (the insert
     * fails silently and lastInsertId() returns 0). The primary key is
     * never a SET column: create() lets auto-increment assign it and
     * update() targets it through wherePrimary().
     *
     * @param recordDto $record
     */
    protected function bindings(recordDto $record): array
    {
        $columns = $record->asColumns();

        unset($columns['id']);

        if (array_key_exists('in_office', $columns)) {
            $columns['in_office'] = (int)$columns['in_office'];
        }

        return $columns;
    }
}
