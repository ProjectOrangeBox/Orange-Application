<?php

declare(strict_types=1);

namespace api\models;

use orange\dto\attributes\Column;
use orange\dto\attributes\filters\CollapseSpaces;
use orange\dto\attributes\filters\NormalizeDateTime;
use orange\dto\attributes\filters\StripControlChars;
use orange\dto\attributes\filters\ToInteger;
use orange\dto\attributes\filters\Trim;
use orange\dto\attributes\IsPrimary;
use orange\dto\attributes\validations\Integer;
use orange\dto\attributes\validations\IsRequired;
use orange\dto\attributes\validations\MaxLength;
use orange\dto\attributes\validations\ValidDate;
use orange\dto\Dto;

class CalendarEventDto extends Dto
{
    #[IsPrimary()]
    #[Integer()]
    #[ToInteger()]
    public protected(set) int $id;

    #[Trim()]
    #[StripControlChars()]
    #[CollapseSpaces()]
    #[IsRequired()]
    #[MaxLength(128)]
    public protected(set) string $title;

    #[Trim()]
    #[StripControlChars()]
    public protected(set) string $description = '';

    #[Trim()]
    #[IsRequired()]
    #[ValidDate()]
    #[NormalizeDateTime('Y-m-d')]
    #[Column('event_date')]
    public protected(set) string $date;
}
