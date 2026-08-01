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
    #[ToInteger()]
    #[IsRequired()]
    #[Integer()]
    #[GreaterThan(0)]
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
    #[ToFloat()]
    #[Round(2)]
    #[IsRequired()]
    #[Numeric()]
    #[GreaterThan(0)]
    #[DbCast('float')]
    #[Table('order_lines')]
    #[Column('unit_price')]
    #[Label('Unit price')]
    public protected(set) float $unit_price;

    // Sent by the client and checked against qty x unit_price in the controller
    // rather than simply recomputed, so a client that has drifted is told so
    // instead of having its arithmetic silently replaced.
    #[ToFloat()]
    #[Round(2)]
    #[IsRequired()]
    #[Numeric()]
    #[DbCast('float')]
    #[Table('order_lines')]
    #[Column('line_total')]
    #[Label('Line total')]
    public protected(set) float $line_total;
}
