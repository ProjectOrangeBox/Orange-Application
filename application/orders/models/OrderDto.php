<?php

declare(strict_types=1);

namespace application\orders\models;

use orange\dto\attributes\Column;
use orange\dto\attributes\IsPrimary;
use orange\dto\attributes\Label;
use orange\dto\attributes\Table;
use orange\dto\attributes\filters\CollapseSpaces;
use orange\dto\attributes\filters\DefaultTo;
use orange\dto\attributes\filters\NormalizeDateTime;
use orange\dto\attributes\filters\StripControlChars;
use orange\dto\attributes\filters\ToInteger;
use orange\dto\attributes\filters\Trim;
use orange\dto\attributes\validations\Integer;
use orange\dto\attributes\validations\IsArray;
use orange\dto\attributes\validations\IsRequired;
use orange\dto\attributes\validations\MaxCount;
use orange\dto\attributes\validations\MaxLength;
use orange\dto\attributes\validations\MinCount;
use orange\dto\attributes\validations\ValidDate;
use orange\dto\Dto;

/**
 * An order and the lines it is made of.
 *
 * The point of this example. RecordDto and CalendarEventDto are flat, so they
 * only ever show field validation; an order arrives as one payload containing a
 * variable number of child rows, each validated in its own right.
 *
 * `lines` is the nested part: #[IsArray(LineItemDto::class)] builds a
 * LineItemDto from every element and validates it, so after construction the
 * property holds DTOs rather than raw arrays. Two consequences worth knowing:
 *
 *  - Child failures roll up. errors() reports 'Lines has 1 or more errors' and
 *    nothing more; the per-field detail lives on each child's own errors().
 *    OrderController rebuilds the per-row map from those for the client.
 *  - asArray() drops invalid properties, so a parent with bad lines returns
 *    only its own valid fields. Read the children directly instead of expecting
 *    the parent's array output to carry them.
 *
 * The customer is a foreign key rather than a nested DTO: orange/dto nests
 * through #[IsArray] only, and a one-element array to carry a single object
 * would be a worse lie than a plain id. CustomerDto is its own resource.
 */
class OrderDto extends Dto
{
    #[IsPrimary()]
    #[Integer()]
    #[ToInteger()]
    #[Table('orders')]
    #[Column('id')]
    public protected(set) int $id;

    // Existence of the customer is a database question, not a shape question -
    // the controller answers it, and a missing row comes back as a 422 keyed to
    // this field rather than a foreign key error from MySQL.
    #[ToInteger()]
    #[IsRequired()]
    #[Integer()]
    #[Table('orders')]
    #[Column('customer_id')]
    #[Label('Customer')]
    public protected(set) int $customer_id;

    #[Trim()]
    #[IsRequired()]
    #[ValidDate()]
    #[NormalizeDateTime('Y-m-d')]
    #[Table('orders')]
    #[Column('ordered_on')]
    #[Label('Ordered on')]
    public protected(set) string $ordered_on;

    #[Trim()]
    #[StripControlChars()]
    #[CollapseSpaces()]
    #[DefaultTo('')]
    #[MaxLength(1024)]
    #[Table('orders')]
    #[Column('notes')]
    #[Label('Notes')]
    public protected(set) string $notes = '';

    // MinCount(1) because an order with no lines is not an order. MaxCount is a
    // guard against a runaway client rather than a business rule - without an
    // upper bound one request can make the server build unbounded child DTOs.
    /** @var LineItemDto[] after validation - IsArray swaps the raw elements for child DTOs. */
    #[IsRequired()]
    #[IsArray(LineItemDto::class)]
    #[MinCount(1)]
    #[MaxCount(50)]
    #[Label('Lines')]
    public protected(set) array $lines;
}
