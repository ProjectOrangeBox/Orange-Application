<?php

/**
 * The signup confirmation email, as HTML.
 *
 * The account is inactive until this link is followed, which is what the mail is
 * for: without it, anyone could sign up as anyone.
 */

?>
<div style="font-family: Helvetica, Arial, sans-serif; font-size: 16px; line-height: 1.5; color: #212529; max-width: 560px;">
    <h1 style="font-size: 22px; margin: 0 0 20px;">Confirm your account</h1>
    <p style="margin: 0 0 16px;">Hello <?= htmlspecialchars((string) ($username ?? ""), ENT_QUOTES, 'UTF-8') ?>,</p>
    <p style="margin: 0 0 16px;">Thanks for signing up. Confirm this address to activate your account:</p>
    <p style="margin: 0 0 24px;">
        <a href="<?= htmlspecialchars((string) ($link ?? ""), ENT_QUOTES, 'UTF-8') ?>"
           style="background: #1abc9c; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 4px; display: inline-block;">Confirm my account</a>
    </p>
    <p style="margin: 0 0 16px; font-size: 14px; color: #6c757d;">
        If the button does not work, copy this into your browser:<br>
        <span style="word-break: break-all;"><?= htmlspecialchars((string) ($link ?? ""), ENT_QUOTES, 'UTF-8') ?></span>
    </p>
    <p style="margin: 24px 0 0; font-size: 14px; color: #6c757d;">This link works once and expires in <?= (int) ($hours ?? 24) ?> hours. Until it is followed the account cannot sign in. If you did not sign up, ignore this message and nothing further happens.</p>
</div>
