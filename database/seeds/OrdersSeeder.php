<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Two orders with line items, which is what makes the nested-Dto example show
 * anything: the page is about a parent with a variable number of children, and
 * an empty table demonstrates none of it.
 *
 * Depends on AclSeeder only in the sense that both are run by db:seed; the
 * tables themselves are unrelated.
 */
final class OrdersSeeder extends AbstractSeed
{
    public function run(): void
    {
        // created_at/updated_at are set explicitly rather than left to
        // CURRENT_TIMESTAMP. bin/dbExport dumps these rows into the sandbox's
        // initdb SQL, and a wall-clock default makes that dump differ on every
        // run - which would leave the drift check permanently red.
        $seededAt = '2026-07-28 00:00:00';

        $this->table('customers')->insert([
            ['id' => 1, 'name' => 'Johnny Appleseed', 'email' => 'johnny@example.com', 'phone' => '5551231234', 'created_at' => $seededAt, 'updated_at' => $seededAt],
            ['id' => 2, 'name' => 'Jenny Appleseed', 'email' => 'jenny@example.com', 'phone' => '5554846768', 'created_at' => $seededAt, 'updated_at' => $seededAt],
        ])->save();

        $this->table('orders')->insert([
            ['id' => 1, 'customer_id' => 1, 'ordered_on' => '2026-07-28', 'notes' => 'Leave at the side door.', 'created_at' => $seededAt, 'updated_at' => $seededAt],
            ['id' => 2, 'customer_id' => 2, 'ordered_on' => '2026-07-30', 'notes' => '', 'created_at' => $seededAt, 'updated_at' => $seededAt],
        ])->save();

        // line_total is stored rather than computed, because the API checks the
        // client's arithmetic instead of silently replacing it - see
        // OrderController::validate().
        $this->table('order_lines')->insert([
            ['order_id' => 1, 'sku' => 'APL-001', 'description' => 'Apple seeds, 1lb bag', 'qty' => 3, 'unit_price' => '4.50', 'line_total' => '13.50'],
            ['order_id' => 1, 'sku' => 'SPD-014', 'description' => 'Garden spade', 'qty' => 1, 'unit_price' => '22.00', 'line_total' => '22.00'],
            ['order_id' => 2, 'sku' => 'APL-001', 'description' => 'Apple seeds, 1lb bag', 'qty' => 10, 'unit_price' => '4.50', 'line_total' => '45.00'],
        ])->save();
    }
}
