<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Customers, orders and their line items - the nested-Dto example.
 *
 * Two deliberate asymmetries:
 *
 * Money is DECIMAL(10,2), never a float. 0.1 + 0.2 is not 0.3 in binary
 * floating point, and a line total a cent out is worse than useless.
 *
 * Deleting an order takes its lines with it (CASCADE) because a line has no
 * meaning without its order, while deleting a customer with orders on file is
 * refused (RESTRICT) rather than silently removing history.
 */
final class CreateOrders extends AbstractMigration
{
    public function change(): void
    {
        $this->table('customers', ['signed' => false, 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('name', 'string', ['null' => false, 'limit' => 64])
            ->addColumn('email', 'string', ['null' => false, 'limit' => 128])
            ->addColumn('phone', 'string', ['null' => false, 'limit' => 64, 'default' => ''])
            ->addColumn('created_at', 'timestamp', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['null' => false, 'default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['email'], ['unique' => true, 'name' => 'uq_customers_email'])
            ->create();

        $this->table('orders', ['signed' => false, 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('customer_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('ordered_on', 'date', ['null' => false])
            ->addColumn('notes', 'text', ['null' => false])
            ->addColumn('created_at', 'timestamp', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['null' => false, 'default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['ordered_on'], ['name' => 'idx_orders_ordered_on'])
            ->addForeignKey('customer_id', 'customers', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION', 'constraint' => 'fk_orders_customer'])
            ->create();

        $this->table('order_lines', ['signed' => false, 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('order_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('sku', 'string', ['null' => false, 'limit' => 32])
            ->addColumn('description', 'string', ['null' => false, 'limit' => 128, 'default' => ''])
            ->addColumn('qty', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('unit_price', 'decimal', ['null' => false, 'precision' => 10, 'scale' => 2])
            ->addColumn('line_total', 'decimal', ['null' => false, 'precision' => 10, 'scale' => 2])
            ->addIndex(['order_id'], ['name' => 'idx_order_lines_order'])
            ->addForeignKey('order_id', 'orders', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION', 'constraint' => 'fk_order_lines_order'])
            ->create();
    }
}
