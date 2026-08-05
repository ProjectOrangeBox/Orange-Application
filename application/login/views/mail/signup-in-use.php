<?php

/**
 * Sent when somebody tries to sign up with an address that already has an
 * account.
 *
 * Deliberately carries no token and no action link. The signup form answers a
 * taken address exactly as it answers a free one, so whoever submitted it learns
 * nothing - and this message goes to the person who actually owns the address,
 * who is the only one who should hear about the attempt. A link that acted on
 * the account would hand the prober a lever.
 */

?>
<div style="font-family: Helvetica, Arial, sans-serif; font-size: 16px; line-height: 1.5; color: #212529; max-width: 560px;">
    <h1 style="font-size: 22px; margin: 0 0 20px;">Someone tried to sign up with your address</h1>
    <p style="margin: 0 0 16px;">Somebody just tried to create an account using this email address, but it already has one.</p>
    <p style="margin: 0 0 16px;">If that was you, there is nothing to do - just sign in with your existing account. If you have forgotten the password, use the "forgot password" link on the sign-in page.</p>
    <p style="margin: 0 0 16px;">If it was not you, you can ignore this. No account was created and nothing about your existing account has changed.</p>
    <p style="margin: 0 0 24px;">
        <a href="<?= htmlspecialchars((string) ($loginUrl ?? ""), ENT_QUOTES, 'UTF-8') ?>"
           style="background: #1abc9c; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 4px; display: inline-block;">Go to sign in</a>
    </p>
    <p style="margin: 0 0 16px; font-size: 14px; color: #6c757d;">
        If the button does not work, copy this into your browser:<br>
        <span style="word-break: break-all;"><?= htmlspecialchars((string) ($loginUrl ?? ""), ENT_QUOTES, 'UTF-8') ?></span>
    </p>
</div>
