<?php

/**
 * The form behind a reset link.
 *
 * The token rides in a hidden field rather than staying in the query string, so
 * submitting it is a POST body and not another URL to end up in a log or a
 * Referer header.
 */

$pageErrors = $errors ?? [];
?>
<?php include $headerPartial ?>
<?php include $navPartial ?>
<section class="page-section" style="padding-top: 10rem;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-8">
                <h2 class="page-section-heading text-center text-uppercase text-secondary mb-0">New Password</h2>
                <div class="divider-custom">
                    <div class="divider-custom-line"></div>
                    <div class="divider-custom-icon"><i class="fas fa-lock"></i></div>
                    <div class="divider-custom-line"></div>
                </div>

<?php if ($pageErrors !== []) : ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-triangle-exclamation fa-fw"></i>
    <?php foreach ($pageErrors as $pageError) : ?>
                    <div><?= htmlspecialchars((string) $pageError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>
                </div>
<?php endif; ?>

                <form method="post" action="<?= htmlspecialchars((string) ($resetUrl ?? '/password/reset'), ENT_QUOTES, 'UTF-8') ?>" novalidate>
                    <input type="hidden" name="_token" value="<?= htmlspecialchars((string) ($csrfToken ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars((string) ($token ?? ''), ENT_QUOTES, 'UTF-8') ?>">

                    <div class="form-floating mb-3">
                        <input class="form-control" id="password" type="password" name="password"
                               placeholder="New password" autocomplete="new-password" required autofocus>
                        <label for="password">New password</label>
                    </div>

                    <div class="form-floating mb-3">
                        <input class="form-control" id="passwordConfirm" type="password" name="passwordConfirm"
                               placeholder="Repeat it" autocomplete="new-password" required>
                        <label for="passwordConfirm">Repeat the new password</label>
                    </div>

                    <p class="text-muted small">
                        At least <?= (int) ($minLength ?? 12) ?> characters. Length is the only rule -
                        a long passphrase you can remember beats a short one full of punctuation.
                    </p>

                    <button class="btn btn-primary btn-xl w-100" type="submit">
                        <i class="fas fa-check fa-fw"></i>
                        Set the password
                    </button>
                </form>
            </div>
        </div>
    </div>
</section>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
<?php include $footerPartial ?>
