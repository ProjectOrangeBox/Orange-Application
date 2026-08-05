<?php

/**
 * The password-reset email, as HTML.
 *
 * Inline styles only, and nothing clever: mail clients strip <style> blocks,
 * ignore external stylesheets, and none of them have this site's CSS. The
 * plain-text alternative in password-reset-text.php is not optional decoration -
 * it is what a text client and a screen reader actually read.
 */

?>
<div style="font-family: Helvetica, Arial, sans-serif; font-size: 16px; line-height: 1.5; color: #212529; max-width: 560px;">
    <h1 style="font-size: 22px; margin: 0 0 20px;">Reset your password</h1>
    <p style="margin: 0 0 16px;">Hello <?= htmlspecialchars((string) ($username ?? ""), ENT_QUOTES, 'UTF-8') ?>,</p>
    <p style="margin: 0 0 16px;">Someone asked to reset the password on your account. If that was you, choose a new one here:</p>
    <p style="margin: 0 0 24px;">
        <a href="<?= htmlspecialchars((string) ($link ?? ""), ENT_QUOTES, 'UTF-8') ?>"
           style="background: #1abc9c; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 4px; display: inline-block;">Choose a new password</a>
    </p>
    <p style="margin: 0 0 16px; font-size: 14px; color: #6c757d;">
        If the button does not work, copy this into your browser:<br>
        <span style="word-break: break-all;"><?= htmlspecialchars((string) ($link ?? ""), ENT_QUOTES, 'UTF-8') ?></span>
    </p>
    <p style="margin: 24px 0 0; font-size: 14px; color: #6c757d;">This link works once and expires in <?= (int) ($minutes ?? 60) ?> minutes. If you did not ask for it you can ignore this message - your password has not changed, and nothing happens until the link is followed.</p>
</div>
