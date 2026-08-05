<?php

/**
 * The address-already-in-use notice, as plain text.
 */

?>
Somebody just tried to create an account using this email address, but it
already has one.

If that was you, there is nothing to do - just sign in with your existing
account. If you have forgotten the password, use the "forgot password" link
on the sign-in page:

<?= $loginUrl ?? '' ?>

If it was not you, you can ignore this. No account was created and nothing
about your existing account has changed.
