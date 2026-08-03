<?php

declare(strict_types=1);

use application\orders\models\LineItemDto;
use application\orders\models\OrderDto;

/**
 * Validation tests for the nested order DTOs.
 *
 * These exist because of a bug that reached the database. Dto::process()
 * replays attributes in declaration order and validates and filters in the same
 * pass, so a casting filter declared above a validation rewrites the value that
 * rule then sees. LineItemDto declared #[ToFloat()] above #[IsRequired()], which
 * meant a cleared price arrived as '', became 0.0, and passed as required —
 * while #[Numeric()] and #[GreaterThan(0)] never ran at all, because process()
 * decides "provided" once from the raw input and '' is not provided.
 *
 * The order form cleared a price, got a 200, and wrote 0.00 into a NOT NULL
 * column. Reading it back then failed validation, asArray() dropped the invalid
 * 'lines' property, and the API served a 200 with no lines key at all.
 *
 * Every "cleared field" case below fails without the reordering. Keep the
 * checks above the casts in both DTOs.
 */
final class OrderDtoTest extends unitTestHelper
{
    /**
     * A valid payload, the shape the Vue app posts.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'customer_id' => 1,
            'ordered_on' => '2026-08-01',
            'notes' => 'Leave at the side door.',
            'lines' => [
                ['sku' => 'APL-001', 'description' => 'Apple seeds', 'qty' => 3, 'unit_price' => 4.5, 'line_total' => 13.5],
            ],
        ];
    }

    /**
     * The same payload with one line field replaced.
     *
     * @return array<string, mixed>
     */
    protected function withLineField(string $field, mixed $value): array
    {
        $payload = $this->payload();
        $payload['lines'][0][$field] = $value;

        return $payload;
    }

    public function testValidPayloadValidates(): void
    {
        $order = new OrderDto($this->payload());

        $this->assertTrue($order->isValid());
        $this->assertSame([], $order->errors());
        $this->assertCount(1, $order->lines);
    }

    /**
     * The exact regression: a cleared <input> posts '', not a missing key.
     */
    public function testClearedUnitPriceIsRejected(): void
    {
        $order = new OrderDto($this->withLineField('unit_price', ''));

        $this->assertFalse($order->isValid(), 'a cleared price must not validate');
        $this->assertArrayHasKey('lines', $order->errors());
        $this->assertSame(['unit_price' => ['Unit price is required']], $order->lines[0]->errors());
    }

    /**
     * Never reaches the property either — a rejected value must not be
     * quietly assigned as 0.0 on the way past.
     */
    public function testClearedUnitPriceIsNotStoredAsZero(): void
    {
        $order = new OrderDto($this->withLineField('unit_price', ''));

        $this->assertFalse(isset($order->lines[0]->unit_price));
        $this->assertArrayNotHasKey('unit_price', $order->lines[0]->asColumns(withoutPrimary: true, tablename: 'order_lines'));
    }

    /**
     * '' is what a form sends, null is what JSON.stringify sends for a cleared
     * model, and an absent key is what a client that prunes empties sends.
     * process() folds all three to '' before the rules run, so all three must
     * come back the same way.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function clearedLineFieldProvider(): array
    {
        $cases = [];

        foreach (['unit_price' => 'Unit price', 'qty' => 'Quantity', 'sku' => 'SKU', 'line_total' => 'Line total'] as $field => $label) {
            $cases["$field is ''"] = [$field, ''];
            $cases["$field is null"] = [$field, null];
        }

        return $cases;
    }

    #[PHPUnit\Framework\Attributes\DataProvider('clearedLineFieldProvider')]
    public function testClearedRequiredLineFieldIsRejected(string $field, mixed $value): void
    {
        $order = new OrderDto($this->withLineField($field, $value));

        $this->assertFalse($order->isValid(), "$field cleared must not validate");
        $this->assertArrayHasKey($field, $order->lines[0]->errors());
    }

    /**
     * The parent has the same shape of problem: #[ToInteger()] used to sit
     * above #[IsRequired()], so a cleared customer became customer 0.
     */
    public function testClearedCustomerIdIsRejected(): void
    {
        $order = new OrderDto($this->payload(['customer_id' => '']));

        $this->assertFalse($order->isValid());
        $this->assertSame(['customer_id' => ['Customer is required']], $order->errors());
        $this->assertFalse(isset($order->customer_id));
    }

    /**
     * Reordering must not cost the numeric strings a JSON client actually
     * sends — Integer, Numeric and GreaterThan all read a string fine, which is
     * why the checks can run before the casts at all.
     */
    public function testNumericStringsStillValidateAndCast(): void
    {
        $order = new OrderDto($this->payload(['customer_id' => '7']));
        $order->lines; // force evaluation before asserting on children

        $this->assertTrue($order->isValid(), (string) json_encode($order->errors()));
        $this->assertSame(7, $order->customer_id);

        $line = new LineItemDto(['sku' => 'A-1', 'qty' => ' 3 ', 'unit_price' => '4.567', 'line_total' => '13.70']);

        $this->assertTrue($line->isValid(), (string) json_encode($line->errors()));
        $this->assertSame(3, $line->qty, 'whitespace-padded input still casts');
        $this->assertSame(4.57, $line->unit_price, 'Round(2) still applies after the checks');
    }

    /**
     * A literal 0 is a different failure from a cleared field, and always was —
     * this is the case that did work, kept so the reorder cannot silently
     * swallow it.
     */
    public function testZeroUnitPriceIsRejectedAsOutOfRange(): void
    {
        $order = new OrderDto($this->withLineField('unit_price', 0));

        $this->assertFalse($order->isValid());
        $this->assertSame(['unit_price' => ['Unit price must be greater than 0']], $order->lines[0]->errors());
    }

    public function testOrderWithNoLinesIsRejected(): void
    {
        $order = new OrderDto($this->payload(['lines' => []]));

        $this->assertFalse($order->isValid());
        $this->assertArrayHasKey('lines', $order->errors());
    }
}
