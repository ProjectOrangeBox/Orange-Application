<?php

/**
 * The signup confirmation email, as plain text.
 */

?>
Hello <?= $username ?? '' ?>,

Thanks for signing up. Confirm this address to activate your account:

<?= $link ?? '' ?>

This link works once and expires in <?= (int) ($hours ?? 24) ?> hours.
Until it is followed the account cannot sign in.

If you did not sign up, ignore this message and nothing further happens.
