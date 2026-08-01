<?php

declare(strict_types=1);

namespace application\orders\models;

use orange\framework\base\Singleton;
use orange\model\Sql;
use PDO;

/**
 * CRUD for orders and the lines they own.
 *
 * Two tables rather than one, so this model differs from RecordModel in the
 * only way that matters: a write is never a single statement. An order and its
 * lines are one thing to the caller, so every mutation runs in a transaction -
 * a parent inserted without its children, or half its children, is worse than
 * an outright failure because nothing reports it.
 *
 * Sql is scoped to one table by construction, so there is an instance per
 * table. They share the PDO handle, which is what makes a transaction spanning
 * both possible.
 */
class OrderModel extends Singleton
{
    protected Sql $orders;
    protected Sql $lines;

    /** @var string[] */
    protected array $orderColumns = ['id', 'customer_id', 'ordered_on', 'notes'];

    /** @var string[] */
    protected array $lineColumns = ['id', 'order_id', 'sku', 'description', 'qty', 'unit_price', 'line_total'];

    public function __construct(protected PDO $pdo)
    {
        $this->orders = new Sql([
            'tablename' => 'orders',
            'primaryColumn' => 'id',
            'throwException' => true,
        ], $this->pdo);

        $this->lines = new Sql([
            'tablename' => 'order_lines',
            'primaryColumn' => 'id',
            'throwException' => true,
        ], $this->pdo);
    }

    /**
     * Every order, newest first, each with its lines.
     *
     * @return OrderDto[]
     */
    public function index(): array
    {
        $rows = $this->orders->select($this->orderColumns)
            ->orderBy('ordered_on', 'DESC')
            ->orderBy('id', 'DESC')
            ->execute()
            ->rows();

        if ($rows === []) {
            return [];
        }

        // One query for all the lines rather than one per order. The N+1 is not
        // a performance nicety here - the list is the endpoint the front end
        // hits most, and it would issue a query per row on every page load.
        $grouped = $this->linesFor(array_map(static fn(array $row): int => (int) $row['id'], $rows));

        $hydrated = [];

        foreach ($rows as $row) {
            $hydrated[] = $this->hydrate($row, $grouped[(int) $row['id']] ?? []);
        }

        return $hydrated;
    }

    /**
     * One order with its lines, or null when there is no such order.
     */
    public function read(int $id): ?OrderDto
    {
        $row = $this->orders->select($this->orderColumns)->wherePrimary($id)->limit(1)->execute()->row();

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row, $this->linesFor([$id])[$id] ?? []);
    }

    public function exists(int $id): bool
    {
        return $this->orders->select('id')->wherePrimary($id)->limit(1)->execute()->column() !== false;
    }

    /**
     * Does this customer exist?
     *
     * Asked before writing so a bad customer_id comes back as a 422 keyed to
     * that field, rather than as a foreign key violation the client cannot read.
     */
    public function customerExists(int $id): bool
    {
        $sql = new Sql(['tablename' => 'customers', 'primaryColumn' => 'id', 'throwException' => true], $this->pdo);

        return $sql->select('id')->wherePrimary($id)->limit(1)->execute()->column() !== false;
    }

    /**
     * Insert an order and its lines, returning the new order id.
     */
    public function create(OrderDto $order): int
    {
        $this->pdo->beginTransaction();

        try {
            $id = (int) $this->orders->insert()->set($order->asColumns(withoutPrimary: true, tablename: 'orders'))->execute()->lastInsertId();

            $this->writeLines($id, $order->lines);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $id;
    }

    /**
     * Replace an order and its lines.
     *
     * The lines are deleted and rewritten rather than diffed. They have no
     * identity of their own outside the order - the client sends the list it
     * wants to end up with, and matching that by hand would mean guessing which
     * incoming row corresponds to which stored one.
     */
    public function update(OrderDto $order): bool
    {
        $id = (int) $order->primaryValue();

        $this->pdo->beginTransaction();

        try {
            $this->orders->update()->set($order->asColumns(withoutPrimary: true, tablename: 'orders'))->wherePrimary($id)->execute();

            $this->lines->delete()->whereEqual('order_id', $id)->execute();

            $this->writeLines($id, $order->lines);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return true;
    }

    /**
     * Delete an order. The lines go with it by ON DELETE CASCADE.
     */
    public function delete(int $id): bool
    {
        return $this->orders->delete()->wherePrimary($id)->execute()->rowCount() > 0;
    }

    /**
     * @param LineItemDto[] $lines
     */
    protected function writeLines(int $orderId, array $lines): void
    {
        foreach ($lines as $line) {
            $columns = $line->asColumns(withoutPrimary: true, tablename: 'order_lines');
            $columns['order_id'] = $orderId;

            $this->lines->insert()->set($columns)->execute();
        }
    }

    /**
     * Lines for the given order ids, grouped by order id.
     *
     * @param int[] $orderIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    protected function linesFor(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = $this->lines->select($this->lineColumns)
            ->whereIn('order_id', $orderIds)
            ->orderBy('id')
            ->execute()
            ->rows();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['order_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * Build an OrderDto from a database row and its line rows.
     *
     * The lines go in as plain arrays: #[IsArray(LineItemDto::class)] is what
     * turns them into child DTOs, so handing it objects would defeat the
     * validation this exists to run.
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $lineRows
     */
    protected function hydrate(array $row, array $lineRows): OrderDto
    {
        $row['lines'] = $lineRows;

        $order = new OrderDto($row);

        if (!$order->isValid()) {
            logMsg('WARNING', __METHOD__ . ' database row failed dto validation', [
                'id' => $row['id'] ?? null,
                'errors' => $order->errors(),
            ]);
        }

        return $order;
    }
}
