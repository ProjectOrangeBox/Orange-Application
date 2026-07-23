<?php

declare(strict_types=1);

namespace api\models;

use orange\framework\base\Singleton;
use orange\model\Sql;
use PDO;

/**
 * CRUD model for the records REST API.
 *
 * The PDO connection is supplied by the container's `pdo` service (see
 * config/services.php — MySQL in production, in-memory SQLite in the unit
 * tests). Database errors throw orange\model\exceptions\Sql rather than
 * failing silently — a swallowed insert error once made create() return 0
 * with no indication why.
 */
class RecordModel extends Singleton
{
    protected Sql $sql;

    public function __construct(PDO $pdo)
    {
        $this->sql = new Sql([
            'tablename' => 'records',
            'primaryColumn' => 'id',
            // surface database errors instead of returning 0/false quietly
            'throwException' => true,
        ], $pdo);
    }

    /**
     * Return all records, oldest first.
     *
     * @return RecordDto[]
     */
    public function index(): array
    {
        $records = [];

        if ($statement = $this->sql->select('*')->orderBy('id')->execute()->pdoStatement) {
            while ($row = $statement->fetch()) {
                $records[] = $this->hydrate($row);
            }
        }

        return $records;
    }

    /**
     * create a new record and return the id
     */
    public function create(RecordDto $record): int
    {
        return $this->sql->insert()->set($record->asColumns(withoutPrimary: true))->execute()->lastInsertId();
    }

    /**
     * read a record based on the id passed
     *
     * @return RecordDto|null null when no record matches the id
     */
    public function read(int $id): ?RecordDto
    {
        $row = $this->sql->select()->wherePrimary($id)->limit(1)->execute()->row();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * report whether a record with this id exists
     *
     * Cheaper than read() for a bare existence check — fetches a single
     * column instead of hydrating (and validating) a full dto.
     */
    public function exists(int $id): bool
    {
        return $this->sql->select('id')->wherePrimary($id)->limit(1)->execute()->column() !== false;
    }

    /**
     * update a record
     *
     * Always true on return: errors throw, and the caller has already
     * 404'd on a missing id. rowCount() is deliberately not consulted —
     * MySQL reports 0 changed rows when a record is resaved with identical
     * values, which made no-op updates look like failures.
     */
    public function update(RecordDto $record): bool
    {
        $this->sql->update()->set($record->asColumns(withoutPrimary: true))->wherePrimary($record->primaryValue())->execute();

        return true;
    }

    /**
     * delete record based on id passed
     */
    public function delete(int $id): bool
    {
        return $this->sql->delete()->wherePrimary($id)->limit(1)->execute()->rowCount() > 0;
    }

    /**
     * Build a RecordDto from a database row.
     *
     * Rows run through the DTO's full validation pipeline; a row that fails
     * silently loses those fields in the JSON output, so data drift is
     * logged instead of disappearing.
     */
    protected function hydrate(array $row): RecordDto
    {
        $record = new RecordDto($row);

        if (!$record->isValid()) {
            logMsg('WARNING', __METHOD__ . ' database row failed dto validation', [
                'id' => $row['id'] ?? null,
                'errors' => $record->errors(),
            ]);
        }

        return $record;
    }
}
