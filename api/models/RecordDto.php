<?php

declare(strict_types=1);

namespace api\models;

use orange\dto\attributes\FieldName;
use orange\dto\attributes\filters\DefaultTo;
use orange\dto\attributes\filters\NullIfEmpty;
use orange\dto\attributes\filters\RemoveIfHasPrimaryAndEmpty;
use orange\dto\attributes\filters\ToBoolean;
use orange\dto\attributes\filters\ToInteger;
use orange\dto\attributes\IsPrimary;
use orange\dto\attributes\validations\Integer;
use orange\dto\attributes\validations\IsBoolean;
use orange\dto\attributes\validations\IsRequired;
use orange\dto\attributes\validations\MaxLength;
use orange\dto\Dto;

class RecordDto extends Dto
{
    #[Integer()]
    #[ToInteger()]
    #[FieldName('id')]
    public int $id;

    #[IsRequired()]
    #[FieldName('name')]
    #[MaxLength(64)]
    public string $name;

    #[IsRequired()]
    #[FieldName('phone')]
    #[MaxLength(64)]
    public string $phone;

    #[DefaultTo(0)]
    #[IsBoolean()]
    #[ToBoolean()]
    #[FieldName('in_office')]
    public bool $in_office;

    // no removal rule here: the client clears the date by sending an explicit
    // null, which must reach the update as a value rather than being dropped
    #[NullIfEmpty()]
    #[FieldName('out_until')]
    public ?string $out_until = null;
}
