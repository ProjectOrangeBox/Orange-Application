<?php

/**
 * "What address is on your account" - step one of a reset.
 *
 * Says nothing about which addresses have accounts, before or after submitting.
 * See PasswordController, which answers every case with the same next page.
 */

$pageErrors = ($error ?? '') === '' ? [] : [$error];
?>
<?php include $headerPartial ?>
<?php include $navPartial ?>
<section class="page-section" style="padding-top: 10rem;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-8">
                <h2 class="page-section-heading text-center text-uppercase text-secondary mb-0">Forgot Password</h2>
                <div class="divider-custom">
                    <div class="divider-custom-line"></div>
                    <div class="divider-custom-icon"><i class="fas fa-key"></i></div>
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

                <p class="text-muted text-center">
                    Enter the address on your account and we will send a link for choosing a
                    new password.
                </p>

                <form method="post" action="<?= htmlspecialchars((string) ($forgotUrl ?? '/password/forgot'), ENT_QUOTES, 'UTF-8') ?>" novalidate>
                    <input type="hidden" name="_token" value="<?= htmlspecialchars((string) ($csrfToken ?? ''), ENT_QUOTES, 'UTF-8') ?>">

                    <div class="form-floating mb-3">
                        <input class="form-control" id="email" type="email" name="email" placeholder="you@example.com"
                               autocomplete="username" required autofocus>
                        <label for="email">Email address</label>
                    </div>

                    <button class="btn btn-primary btn-xl w-100" type="submit">
                        <i class="fas fa-paper-plane fa-fw"></i>
                        Send the link
                    </button>
                </form>

                <p class="text-center mt-4 mb-0">
                    <a href="<?= htmlspecialchars((string) ($loginUrl ?? '/login'), ENT_QUOTES, 'UTF-8') ?>">Back to sign in</a>
                </p>
            </div>
        </div>
    </div>
</section>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
<?php include $footerPartial ?>
