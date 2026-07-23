<?php

declare(strict_types=1);

use api\models\RecordDto;
use api\models\RecordModel;

/**
 * Direct CRUD tests for the records model against in-memory SQLite —
 * bindings behavior (primary excluded, boolean stored as 0/1), ordering,
 * and how database rows hydrate back through the DTO.
 */
final class RecordModelTest extends UnitTestHelper
{
    protected RecordModel $model;
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL DEFAULT '',
                phone TEXT NOT NULL DEFAULT '',
                in_office INTEGER NOT NULL DEFAULT 0,
                out_until TEXT NULL
            )
            SQL);

        // explicit out-of-sequence ids so index() has an order to prove
        $this->pdo->exec(<<<'SQL'
            INSERT INTO records (id, name, phone, in_office, out_until) VALUES
                (5, 'Jane Doe', '5559876', 0, '2026-01-15 13:30:00'),
                (2, 'Don Myers', '5551234', 1, NULL)
            SQL);

        // fresh instance per test - the suite runs with process isolation so
        // the singleton never outlives a test
        $this->model = RecordModel::getInstance($this->pdo);
    }

    /**
     * A valid DTO built the way the controller builds them.
     */
    protected function dto(array $overrides = []): RecordDto
    {
        return new RecordDto($overrides + [
            'name' => 'New Person',
            'phone' => '5550199',
            'in_office' => false,
            'out_until' => null,
        ]);
    }

    public function testIndexReturnsDtosOrderedById(): void
    {
        $records = $this->model->index();

        $this->assertCount(2, $records);
        $this->assertContainsOnlyInstancesOf(RecordDto::class, $records);
        // rows were inserted 5-then-2; ORDER BY id must flip them
        $this->assertSame([2, 5], array_map(fn ($record) => $record->id, $records));
    }

    public function testReadReturnsTypedDto(): void
    {
        $record = $this->model->read(2);

        $this->assertInstanceOf(RecordDto::class, $record);
        $this->assertSame(2, $record->id);
        $this->assertSame('Don Myers', $record->name);
        $this->assertTrue($record->in_office);
        $this->assertNull($record->out_until);
    }

    public function testReadReturnsNullForMissingId(): void
    {
        $this->assertNull($this->model->read(999));
    }

    public function testExistsReportsSeededAndMissingIds(): void
    {
        $this->assertTrue($this->model->exists(2));
        $this->assertFalse($this->model->exists(999));
    }

    public function testCreateInsertsAndReturnsNewId(): void
    {
        $id = $this->model->create($this->dto());

        $this->assertSame(6, $id); // next after the highest seeded id

        $record = $this->model->read($id);

        $this->assertSame('New Person', $record->name);
        // the regression this guards: a false in_office must reach the
        // integer column as 0, not '' (see RecordModel::bindings())
        $this->assertFalse($record->in_office);
        $this->assertSame(0, (int)$this->pdo->query('SELECT in_office FROM records WHERE id = 6')->fetchColumn());
    }

    public function testCreateNeverWritesThePrimaryColumn(): void
    {
        // even when the DTO carries an id, bindings() drops the #[IsPrimary]
        // column so auto-increment assigns the real one
        $id = $this->model->create($this->dto(['id' => 42]));

        $this->assertSame(6, $id);
    }

    public function testUpdatePersistsChanges(): void
    {
        $this->assertTrue($this->model->update($this->dto([
            'id' => 2,
            'name' => 'Don Updated',
            'in_office' => false,
            'out_until' => '2026-08-01 09:30:00',
        ])));

        $record = $this->model->read(2);

        $this->assertSame('Don Updated', $record->name);
        $this->assertFalse($record->in_office);
        $this->assertSame('2026-08-01 09:30:00', $record->out_until);
    }

    public function testNoOpUpdateStillReportsSuccess(): void
    {
        // resaving identical values must not look like a failure (MySQL's
        // rowCount() reports 0 changed rows for it)
        $unchanged = $this->dto([
            'id' => 2,
            'name' => 'Don Myers',
            'phone' => '5551234',
            'in_office' => true,
            'out_until' => null,
        ]);

        $this->assertTrue($this->model->update($unchanged));
        $this->assertTrue($this->model->update($unchanged));
    }

    public function testDeleteRemovesRowAndReportsMissing(): void
    {
        $this->assertTrue($this->model->delete(5));
        $this->assertNull($this->model->read(5));

        // already gone - nothing to delete
        $this->assertFalse($this->model->delete(5));
        $this->assertFalse($this->model->delete(999));
    }

    public function testInvalidDatabaseRowStillHydrates(): void
    {
        // drifted data (junk phone) hydrates without throwing; the DTO
        // reports the drift instead of hiding it
        $this->pdo->exec("INSERT INTO records (id, name, phone) VALUES (9, 'Drift', 'not-a-phone')");

        $record = $this->model->read(9);

        $this->assertInstanceOf(RecordDto::class, $record);
        $this->assertFalse($record->isValid());
        $this->assertArrayHasKey('phone', $record->errors());

        // and index() survives the bad row too
        $this->assertCount(3, $this->model->index());
    }
}
