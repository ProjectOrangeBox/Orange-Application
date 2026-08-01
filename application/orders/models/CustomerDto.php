<?php

declare(strict_types=1);

namespace application\orders\models;

use orange\dto\attributes\Column;
use orange\dto\attributes\IsPrimary;
use orange\dto\attributes\Label;
use orange\dto\attributes\Table;
use orange\dto\attributes\filters\CollapseSpaces;
use orange\dto\attributes\filters\DefaultTo;
use orange\dto\attributes\filters\NormalizePhone;
use orange\dto\attributes\filters\StripControlChars;
use orange\dto\attributes\filters\ToInteger;
use orange\dto\attributes\filters\ToLower;
use orange\dto\attributes\filters\Trim;
use orange\dto\attributes\validations\Integer;
use orange\dto\attributes\validations\IsRequired;
use orange\dto\attributes\validations\MaxLength;
use orange\dto\attributes\validations\ValidEmail;
use orange\dto\attributes\validations\ValidPhoneNumber;
use orange\dto\Dto;

/**
 * The customer an order belongs to.
 *
 * A resource in its own right rather than something nested inside OrderDto:
 * orange/dto nests through #[IsArray] only, so a single related object is
 * either a foreign key or a dishonest one-element array. Orders carry
 * customer_id and this is served from its own endpoints.
 */
class CustomerDto extends Dto
{
    #[IsPrimary()]
    #[Integer()]
    #[ToInteger()]
    #[Table('customers')]
    #[Column('id')]
    public protected(set) int $id;

    #[Trim()]
    #[StripControlChars()]
    #[CollapseSpaces()]
    #[IsRequired()]
    #[MaxLength(64)]
    #[Table('customers')]
    #[Column('name')]
    #[Label('Name')]
    public protected(set) string $name;

    // Lower-cased before validating, because the column is UNIQUE and MySQL's
    // utf8mb4_unicode_ci would treat 'A@b.com' and 'a@b.com' as the same row
    // anyway - normalizing here means the app agrees with the database.
    #[Trim()]
    #[ToLower()]
    #[IsRequired()]
    #[ValidEmail()]
    #[MaxLength(128)]
    #[Table('customers')]
    #[Column('email')]
    #[Label('Email')]
    public protected(set) string $email;

    #[Trim()]
    #[NormalizePhone()]
    #[DefaultTo('')]
    #[ValidPhoneNumber()]
    #[MaxLength(64)]
    #[Table('customers')]
    #[Column('phone')]
    #[Label('Phone')]
    public protected(set) string $phone = '';
}
