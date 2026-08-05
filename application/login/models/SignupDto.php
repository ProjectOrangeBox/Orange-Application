<?php

declare(strict_types=1);

namespace application\login\models;

use orange\dto\attributes\Label;
use orange\dto\attributes\filters\ToString;
use orange\dto\attributes\filters\Trim;
use orange\dto\attributes\validations\IsRequired;
use orange\dto\attributes\validations\Matches;
use orange\dto\attributes\validations\MaxLength;
use orange\dto\attributes\validations\MinLength;
use orange\dto\attributes\validations\ValidEmail;
use orange\dto\Dto;

/**
 * What a signup form has to contain before anything is written.
 *
 * The shape of the request only. Whether the address or the name is already
 * taken is a question for the database, and SignupController asks it after
 * this passes - a Dto that needed a PDO handle to validate would be a model.
 */
class SignupDto extends Dto
{
    /**
     * Long enough to matter, and nothing else.
     *
     * No "one upper, one digit, one symbol" rule: composition rules push people
     * toward Password1! and its cousins, which is exactly the shape a cracking
     * dictionary already covers. Length is the property that actually costs an
     * attacker something, so length is what is required. NIST SP 800-63B says
     * the same thing rather more formally.
     */
    public const int MIN_PASSWORD_LENGTH = 12;

    /**
     * password_hash() with bcrypt silently truncates past 72 bytes, so a longer
     * one would appear to be accepted while the tail did nothing. Refused
     * rather than truncated, so nobody is told a 200-character passphrase is
     * protecting them when 72 characters of it is.
     */
    public const int MAX_PASSWORD_LENGTH = 72;

    #[Trim]
    #[ToString]
    #[IsRequired]
    #[MinLength(3)]
    #[MaxLength(64)]
    #[Label('Username')]
    public protected(set) string $username;

    #[Trim]
    #[ToString]
    #[IsRequired]
    #[ValidEmail]
    #[MaxLength(255)]
    #[Label('Email address')]
    public protected(set) string $email;

    /**
     * Never trimmed. A leading or trailing space is a character the person
     * chose, and stripping it means their password manager and their typing
     * disagree about what the password is.
     */
    #[ToString]
    #[IsRequired]
    #[MinLength(self::MIN_PASSWORD_LENGTH)]
    #[MaxLength(self::MAX_PASSWORD_LENGTH)]
    #[Label('Password')]
    public protected(set) string $password;

    /**
     * The confirmation field, checked against the password.
     *
     * Worth having precisely because the password field is masked: a typo in a
     * password you cannot see locks you out of an account you just made, and
     * the recovery for that is the reset flow you have not proved works yet.
     */
    #[ToString]
    #[IsRequired]
    #[Matches('password')]
    #[Label('Password confirmation')]
    public protected(set) string $passwordConfirm;
}
