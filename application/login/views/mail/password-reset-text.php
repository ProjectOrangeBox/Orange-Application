<?php

/**
 * The password-reset email, as plain text.
 *
 * The URL sits on its own line so mail clients linkify it cleanly and it
 * survives being wrapped.
 */

?>
Hello <?= $username ?? '' ?>,

Someone asked to reset the password on your account. If that was you,
choose a new one here:

<?= $link ?? '' ?>

This link works once and expires in <?= (int) ($minutes ?? 60) ?> minutes.

If you did not ask for it you can ignore this message - your password has
not changed, and nothing happens until the link is followed.
