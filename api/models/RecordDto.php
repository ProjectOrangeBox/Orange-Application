<?php

declare(strict_types=1);

namespace api\models;

use orange\dto\attributes\DbCast;
use orange\dto\attributes\FieldName;
use orange\dto\attributes\filters\CollapseSpaces;
use orange\dto\attributes\filters\DefaultTo;
use orange\dto\attributes\filters\NormalizeDateTime;
use orange\dto\attributes\filters\NormalizePhone;
use orange\dto\attributes\filters\NullIfEmpty;
use orange\dto\attributes\filters\StripControlChars;
use orange\dto\attributes\filters\ToBoolean;
use orange\dto\attributes\filters\ToInteger;
use orange\dto\attributes\filters\Trim;
use orange\dto\attributes\IsPrimary;
use orange\dto\attributes\validations\Integer;
use orange\dto\attributes\validations\IsBoolean;
use orange\dto\attributes\validations\IsRequired;
use orange\dto\attributes\validations\MaxLength;
use orange\dto\attributes\validations\ValidDate;
use orange\dto\attributes\validations\ValidPhoneNumber;
use orange\dto\Dto;

class RecordDto extends Dto
{
    #[IsPrimary()]
    #[Integer()]
    #[ToInteger()]
    #[FieldName('id')]
    public protected(set) int $id;

    // filters run in declaration order and later rules see the filtered
    // value, so the hygiene filters come first — '   ' fails IsRequired
    #[Trim()]
    #[StripControlChars()]
    #[CollapseSpaces()]
    #[IsRequired()]
    #[MaxLength(64)]
    #[FieldName('name')]
    public protected(set) string $name;

    // NormalizePhone canonicalizes '(555) 123-4567' -> '5551234567' before
    // ValidPhoneNumber checks it (optional '+' then 7-15 digits)
    #[Trim()]
    #[NormalizePhone()]
    #[ValidPhoneNumber()]
    #[IsRequired()]
    #[MaxLength(64)]
    #[FieldName('phone')]
    public protected(set) string $phone;

    // the domain value is a real bool; DbCast emits 0/1 in asColumns() for
    // the prepared statement — Sql binds a PHP false as '' which strict-mode
    // MySQL rejects for an integer column
    #[DefaultTo(0)]
    #[IsBoolean()]
    #[ToBoolean()]
    #[DbCast('int')]
    #[FieldName('in_office')]
    public protected(set) bool $in_office;

    // no removal rule here: the client clears the date by sending an explicit
    // null, which must reach the update as a value rather than being dropped.
    // ValidDate only runs when a value is provided, so null stays valid, and
    // NormalizeDateTime canonicalizes anything strtotime() understands
    #[NullIfEmpty()]
    #[ValidDate()]
    #[NormalizeDateTime()]
    #[FieldName('out_until')]
    public protected(set) ?string $out_until = null;
}
