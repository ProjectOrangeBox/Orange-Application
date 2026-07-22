<?php

declare(strict_types=1);

use api\models\RecordDto;

/**
 * Per-field rule matrix for the records DTO — the validation and
 * normalization contract the REST API enforces on every payload.
 */
final class RecordDtoTest extends UnitTestHelper
{
    /**
     * A fully valid Vue-style payload; override single fields per test.
     */
    protected function validInput(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Don Myers',
            'phone' => '(555) 123-4567',
            'in_office' => false,
            'out_until' => null,
        ];
    }

    public function testValidVueStylePayload(): void
    {
        $dto = new RecordDto($this->validInput());

        $this->assertTrue($dto->isValid());
        $this->assertSame([], $dto->errors());
        // absent id (a create) casts to 0 via ToInteger
        $this->assertSame(0, $dto->id);
        $this->assertSame('Don Myers', $dto->name);
        // NormalizePhone strips the cosmetic formatting
        $this->assertSame('5551234567', $dto->phone);
        $this->assertFalse($dto->in_office);
        $this->assertNull($dto->out_until);
    }

    public function testPrimaryResolvesToIdColumn(): void
    {
        $this->assertSame('id', new RecordDto($this->validInput())->primary());
    }

    public function testIdMustBeAnInteger(): void
    {
        $dto = new RecordDto($this->validInput(['id' => 'abc']));

        $this->assertFalse($dto->isValid());
        $this->assertArrayHasKey('id', $dto->errors());

        // numeric strings (route captures arrive as strings) cast cleanly
        $dto = new RecordDto($this->validInput(['id' => '7']));

        $this->assertTrue($dto->isValid());
        $this->assertSame(7, $dto->id);
    }

    public function testNameIsRequired(): void
    {
        $input = $this->validInput();
        unset($input['name']);

        $this->assertFalse(new RecordDto($input)->isValid());

        // whitespace-only fails required because Trim runs before IsRequired
        $this->assertFalse(new RecordDto($this->validInput(['name' => '   ']))->isValid());
    }

    public function testNameIsCleanedByHygieneFilters(): void
    {
        // leading/trailing space trimmed, zero-width space stripped,
        // internal whitespace runs collapsed
        $dto = new RecordDto($this->validInput(['name' => "  Don\u{200B}   Myers  "]));

        $this->assertTrue($dto->isValid());
        $this->assertSame('Don Myers', $dto->name);
    }

    public function testNameMaxLength(): void
    {
        // MaxLength(64) is strictly less than: 63 passes, 64 fails
        $this->assertTrue(new RecordDto($this->validInput(['name' => str_repeat('a', 63)]))->isValid());

        $dto = new RecordDto($this->validInput(['name' => str_repeat('a', 64)]));

        $this->assertFalse($dto->isValid());
        $this->assertArrayHasKey('name', $dto->errors());
    }

    public function testPhoneIsNormalizedAndValidated(): void
    {
        // a leading + survives normalization
        $dto = new RecordDto($this->validInput(['phone' => '+1 555.123.4567']));

        $this->assertTrue($dto->isValid());
        $this->assertSame('+15551234567', $dto->phone);

        // too few digits after normalization (ValidPhoneNumber wants 7-15)
        $this->assertFalse(new RecordDto($this->validInput(['phone' => '555']))->isValid());

        // not a phone number at all
        $dto = new RecordDto($this->validInput(['phone' => 'abc']));

        $this->assertFalse($dto->isValid());
        $this->assertArrayHasKey('phone', $dto->errors());
    }

    public function testPhoneIsRequired(): void
    {
        $input = $this->validInput();
        unset($input['phone']);

        $dto = new RecordDto($input);

        $this->assertFalse($dto->isValid());
        $this->assertArrayHasKey('phone', $dto->errors());
    }

    public function testInOfficeDefaultsAndValidates(): void
    {
        // absent falls back to DefaultTo(0) -> ToBoolean -> false
        $input = $this->validInput();
        unset($input['in_office']);

        $dto = new RecordDto($input);

        $this->assertTrue($dto->isValid());
        $this->assertFalse($dto->in_office);

        // real JSON booleans pass through
        $dto = new RecordDto($this->validInput(['in_office' => true]));

        $this->assertTrue($dto->isValid());
        $this->assertTrue($dto->in_office);

        // anything else is rejected
        $dto = new RecordDto($this->validInput(['in_office' => 'maybe']));

        $this->assertFalse($dto->isValid());
        $this->assertArrayHasKey('in_office', $dto->errors());
    }

    public function testOutUntilAcceptsNullAndEmpty(): void
    {
        // explicit null - how the client clears the date
        $dto = new RecordDto($this->validInput(['out_until' => null]));

        $this->assertTrue($dto->isValid());
        $this->assertNull($dto->out_until);

        // empty string becomes null via NullIfEmpty
        $dto = new RecordDto($this->validInput(['out_until' => '']));

        $this->assertTrue($dto->isValid());
        $this->assertNull($dto->out_until);
    }

    public function testOutUntilNormalizesParseableDates(): void
    {
        $dto = new RecordDto($this->validInput(['out_until' => 'Aug 1 2026 9:30am']));

        $this->assertTrue($dto->isValid());
        $this->assertSame('2026-08-01 09:30:00', $dto->out_until);
    }

    public function testOutUntilRejectsJunkDates(): void
    {
        $dto = new RecordDto($this->validInput(['out_until' => 'banana']));

        $this->assertFalse($dto->isValid());
        $this->assertArrayHasKey('out_until', $dto->errors());
    }

    public function testDatabaseRowHydratesValid(): void
    {
        // a row as the database returns it: numeric strings and 0/1 flags
        // must survive the same pipeline (see RecordModel::hydrate())
        $dto = new RecordDto([
            'id' => '3',
            'name' => 'Doogo2',
            'phone' => '1008675309',
            'in_office' => '1',
            'out_until' => '2026-07-29 00:00:00',
        ]);

        $this->assertTrue($dto->isValid());
        $this->assertSame(3, $dto->id);
        $this->assertTrue($dto->in_office);
        $this->assertSame('2026-07-29 00:00:00', $dto->out_until);
    }
}
