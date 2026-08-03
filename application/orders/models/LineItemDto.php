<?php

declare(strict_types=1);

namespace application\orders\models;

use orange\dto\attributes\Column;
use orange\dto\attributes\DbCast;
use orange\dto\attributes\IsPrimary;
use orange\dto\attributes\Label;
use orange\dto\attributes\Table;
use orange\dto\attributes\filters\CollapseSpaces;
use orange\dto\attributes\filters\DefaultTo;
use orange\dto\attributes\filters\Round;
use orange\dto\attributes\filters\StripControlChars;
use orange\dto\attributes\filters\ToFloat;
use orange\dto\attributes\filters\ToInteger;
use orange\dto\attributes\filters\ToUpper;
use orange\dto\attributes\filters\Trim;
use orange\dto\attributes\validations\GreaterThan;
use orange\dto\attributes\validations\Integer;
use orange\dto\attributes\validations\IsRequired;
use orange\dto\attributes\validations\MaxLength;
use orange\dto\attributes\validations\Numeric;
use orange\dto\Dto;

/**
 * One line of an order.
 *
 * This is the child of OrderDto's #[IsArray(LineItemDto::class)] property, so
 * it is never posted on its own - the parent builds one of these per element of
 * the incoming `lines` array and validates each independently.
 *
 * The important consequence is where the errors end up. A child failure surfaces
 * on the parent as a single rolled-up message ('Lines has 1 or more errors');
 * the detail stays on the child. OrderController::extractLineErrors() walks the
 * children and rebuilds a per-row map so the client can put each message under
 * the row that caused it, rather than showing one useless message for the whole
 * table.
 *
 * ---
 *
 * **Attribute order is load-bearing on the numeric fields: checks first, then
 * the casting filters.** Dto::process() replays attributes in declaration order
 * and validates and filters in the same pass, so a filter declared above a
 * validation rewrites the value that rule then sees. These fields used to read
 *
 *     #[ToFloat()] #[Round(2)] #[IsRequired()] #[Numeric()] #[GreaterThan(0)]
 *
 * which silently accepted a cleared price: ToFloat turned '' into 0.0 before
 * IsRequired looked at it, and isFilled(0.0) is true, so the field passed as
 * required. Numeric and GreaterThan never ran at all - process() decides
 * "provided" once, from the raw input, and '' is not provided. The order form
 * cleared a price, got a 200, and wrote 0.00 into the row.
 *
 * Reversing it costs nothing: Integer, Numeric and GreaterThan all accept
 * numeric strings, so they are happy to judge "4.50" before ToFloat runs. Trim
 * leads because it is the one filter that cannot mask an empty value - trim('')
 * is still '' - and it keeps whitespace-padded input working the way the casts
 * used to handle it.
 */
class LineItemDto extends Dto
{
    #[IsPrimary()]
    #[Integer()]
    #[ToInteger()]
    #[Table('order_lines')]
    #[Column('id')]
    public protected(set) int $id;

    // SKUs are stored and compared upper case, so normalize before the length
    // check rather than after - 'apl-001' and 'APL-001' are the same product.
    #[Trim()]
    #[ToUpper()]
    #[IsRequired()]
    #[MaxLength(32)]
    #[Table('order_lines')]
    #[Column('sku')]
    #[Label('SKU')]
    public protected(set) string $sku;

    #[Trim()]
    #[StripControlChars()]
    #[CollapseSpaces()]
    #[DefaultTo('')]
    #[MaxLength(128)]
    #[Table('order_lines')]
    #[Column('description')]
    #[Label('Description')]
    public protected(set) string $description = '';

    // GreaterThan(0) rather than IsNatural: a zero-quantity line is not a
    // rounding artifact, it is a line nobody meant to add.
    #[Trim()]
    #[IsRequired()]
    #[Integer()]
    #[GreaterThan(0)]
    #[ToInteger()]
    #[Table('order_lines')]
    #[Column('qty')]
    #[Label('Quantity')]
    public protected(set) int $qty;

    // Numeric, not Decimal. Decimal insists on a literal decimal point
    // (^[+-]?[0-9]+\.[0-9]+$), and JavaScript has no way to send one for a
    // whole number - JSON.stringify(45.00) is "45". A price of exactly 45
    // would otherwise be rejected as "must contain a decimal number", which is
    // a 422 the client cannot fix. Round(2) then holds it to cents, matching
    // the DECIMAL(10,2) column.
    #[Trim()]
    #[IsRequired()]
    #[Numeric()]
    #[GreaterThan(0)]
    #[ToFloat()]
    #[Round(2)]
    #[DbCast('float')]
    #[Table('order_lines')]
    #[Column('unit_price')]
    #[Label('Unit price')]
    public protected(set) float $unit_price;

    // Sent by the client and stored as sent. Nothing checks it against
    // qty x unit_price - the comment here used to claim OrderController did,
    // and it never has, so a client is free to send a total that does not match
    // its own line. Left as a known gap rather than quietly recomputed: telling
    // a drifted client it is wrong is more useful than replacing its arithmetic
    // behind its back, and that needs a rule this Dto cannot express alone.
    #[Trim()]
    #[IsRequired()]
    #[Numeric()]
    #[ToFloat()]
    #[Round(2)]
    #[DbCast('float')]
    #[Table('order_lines')]
    #[Column('line_total')]
    #[Label('Line total')]
    public protected(set) float $line_total;
}
