<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Sample rows for the records example, so the list renders with something in it
 * on a fresh install rather than looking broken.
 */
final class RecordsSeeder extends AbstractSeed
{
    public function run(): void
    {
        $this->table('records')->insert([
            ['id' => 1, 'name' => 'Johnny Appleseed', 'phone' => '1231231234', 'in_office' => 0, 'out_until' => null],
            ['id' => 2, 'name' => 'Jenny Appleseed', 'phone' => '4846768511', 'in_office' => 1, 'out_until' => null],
            ['id' => 3, 'name' => 'Doogo', 'phone' => '1008675309', 'in_office' => 0, 'out_until' => '2026-07-29 00:00:00'],
        ])->save();
    }
}
