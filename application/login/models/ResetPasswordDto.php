<?php

declare(strict_types=1);

namespace application\login\models;

use orange\dto\attributes\Label;
use orange\dto\attributes\filters\ToString;
use orange\dto\attributes\validations\IsRequired;
use orange\dto\attributes\validations\Matches;
use orange\dto\attributes\validations\MaxLength;
use orange\dto\attributes\validations\MinLength;
use orange\dto\Dto;

/**
 * The new password, on the form behind a reset link.
 *
 * The same two length rules as signup, and for the same reasons - see
 * SignupDto, which owns the constants. Duplicating the numbers here would be
 * two places for one policy to drift.
 *
 * The token is not a field. It rides in the form as a hidden input and is
 * checked against the database, not against a pattern, so validating its shape
 * would prove nothing and its absence is already answered by the token lookup
 * failing.
 */
class ResetPasswordDto extends Dto
{
    #[ToString]
    #[IsRequired]
    #[MinLength(SignupDto::MIN_PASSWORD_LENGTH)]
    #[MaxLength(SignupDto::MAX_PASSWORD_LENGTH)]
    #[Label('New password')]
    public protected(set) string $password;

    #[ToString]
    #[IsRequired]
    #[Matches('password')]
    #[Label('Password confirmation')]
    public protected(set) string $passwordConfirm;
}
