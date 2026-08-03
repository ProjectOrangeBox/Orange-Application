<?php

declare(strict_types=1);

use application\orders\models\OrderDto;
use application\orders\models\OrderModel;
use orange\framework\exceptions\InvalidValue;

/**
 * The orders model against in-memory SQLite — the write/read round trip across
 * two tables, and what happens when a stored row cannot be read back.
 *
 * hydrate() used to log a WARNING and hand back the invalid DTO. Because
 * asArray() drops invalid properties, the controller then served a 200 whose
 * 'lines' key was simply absent: no error, no status code, nothing a client
 * could branch on. The Vue app read order.lines of undefined and rendered an
 * empty page, which read as "the API deleted my line items".
 *
 * It throws now. This is the read path for a row the write path should never
 * have permitted, so it is a 500 — a bug in this application, not a bad
 * request — and the tests below pin both halves of that: a corrupt row must
 * throw, and a healthy one must still come back whole.
 */
final class OrderModelTest extends unitTestHelper
{
    protected OrderModel $model;
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE customers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL DEFAULT '',
                email TEXT NOT NULL DEFAULT '',
                phone TEXT NOT NULL DEFAULT ''
            )
            SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id INTEGER NOT NULL,
                ordered_on TEXT NOT NULL,
                notes TEXT NOT NULL DEFAULT ''
            )
            SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE order_lines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER NOT NULL,
                sku TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                qty INTEGER NOT NULL,
                unit_price REAL NOT NULL,
                line_total REAL NOT NULL
            )
            SQL);

        $this->pdo->exec("INSERT INTO customers (id, name, email) VALUES (1, 'Ada', 'ada@example.com')");
        $this->pdo->exec("INSERT INTO orders (id, customer_id, ordered_on, notes) VALUES (1, 1, '2026-08-01', 'first')");
        $this->pdo->exec(<<<'SQL'
            INSERT INTO order_lines (order_id, sku, description, qty, unit_price, line_total) VALUES
                (1, 'APL-001', 'Apple seeds', 3, 4.5, 13.5),
                (1, 'SPD-014', 'Garden spade', 1, 22.0, 22.0)
            SQL);

        $this->model = OrderModel::getInstance($this->pdo);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function dto(array $overrides = []): OrderDto
    {
        return new OrderDto($overrides + [
            'customer_id' => 1,
            'ordered_on' => '2026-08-02',
            'notes' => 'second',
            'lines' => [
                ['sku' => 'NEW-001', 'description' => 'A thing', 'qty' => 2, 'unit_price' => 5.0, 'line_total' => 10.0],
            ],
        ]);
    }

    public function testReadReturnsOrderWithItsLines(): void
    {
        $order = $this->model->read(1);

        $this->assertInstanceOf(OrderDto::class, $order);
        $this->assertSame(1, $order->id);
        $this->assertCount(2, $order->lines);
        $this->assertSame('APL-001', $order->lines[0]->sku);
    }

    public function testReadReturnsNullForMissingId(): void
    {
        $this->assertNull($this->model->read(9999));
    }

    /**
     * The whole point of the read path fix: 'lines' is present and populated,
     * so a client can rely on the key existing.
     */
    public function testReadOutputCarriesTheLinesKey(): void
    {
        $array = $this->model->read(1)?->asArray() ?? [];

        $this->assertArrayHasKey('lines', $array);
        $this->assertCount(2, $array['lines']);
    }

    public function testCreateInsertsOrderAndLinesInOneGo(): void
    {
        $id = $this->model->create($this->dto());

        $order = $this->model->read($id);

        $this->assertInstanceOf(OrderDto::class, $order);
        $this->assertSame('second', $order->notes);
        $this->assertCount(1, $order->lines);
        $this->assertSame('NEW-001', $order->lines[0]->sku);
    }

    public function testUpdateReplacesTheLineSet(): void
    {
        $this->model->update($this->dto([
            'id' => 1,
            'lines' => [
                ['sku' => 'ONE-001', 'description' => 'only line', 'qty' => 1, 'unit_price' => 9.99, 'line_total' => 9.99],
            ],
        ]));

        $order = $this->model->read(1);

        $this->assertInstanceOf(OrderDto::class, $order);
        $this->assertCount(1, $order->lines, 'the old two lines are replaced, not appended to');
        $this->assertSame('ONE-001', $order->lines[0]->sku);
    }

    /**
     * A price of 0 is exactly what the old validation bug wrote into the
     * column. Reading it back must be loud.
     */
    public function testReadThrowsOnAStoredRowThatCannotBeValidated(): void
    {
        $this->pdo->exec('UPDATE order_lines SET unit_price = 0 WHERE order_id = 1');

        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessageMatches('/Order 1 is stored in a state it cannot be read back from/');

        $this->model->read(1);
    }

    /**
     * The message has to name the column, or the 500 says only that something
     * somewhere in the order is wrong — which is the same uselessness the
     * rolled-up 'lines' error has, one layer up.
     */
    public function testTheThrownMessageNamesTheOffendingColumn(): void
    {
        $this->pdo->exec('UPDATE order_lines SET unit_price = 0 WHERE order_id = 1');

        try {
            $this->model->read(1);
            $this->fail('expected ' . InvalidValue::class);
        } catch (InvalidValue $e) {
            $this->assertStringContainsString('unit_price', $e->getMessage());
            $this->assertStringContainsString('Unit price must be greater than 0', $e->getMessage());
        }
    }

    /**
     * index() shares hydrate(), so one bad row must not be quietly dropped
     * from the list either.
     */
    public function testIndexThrowsRatherThanOmittingACorruptOrder(): void
    {
        $this->pdo->exec('UPDATE order_lines SET qty = 0 WHERE order_id = 1');

        $this->expectException(InvalidValue::class);

        $this->model->index();
    }

    public function testDeleteRemovesTheOrder(): void
    {
        $this->assertTrue($this->model->delete(1));
        $this->assertFalse($this->model->exists(1));
        $this->assertFalse($this->model->delete(1), 'a second delete reports nothing removed');
    }
}
