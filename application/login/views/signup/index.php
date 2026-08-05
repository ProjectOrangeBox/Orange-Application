<?php

/**
 * The signup form.
 *
 * The username and address are handed back after a failure so nothing has to be
 * retyped; neither password ever is.
 */

$pageErrors = $errors ?? [];
?>
<?php include $headerPartial ?>
<?php include $navPartial ?>
<section class="page-section" style="padding-top: 10rem;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-8">
                <h2 class="page-section-heading text-center text-uppercase text-secondary mb-0">Sign Up</h2>
                <div class="divider-custom">
                    <div class="divider-custom-line"></div>
                    <div class="divider-custom-icon"><i class="fas fa-user-plus"></i></div>
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

                <form method="post" action="<?= htmlspecialchars((string) ($signupUrl ?? '/signup'), ENT_QUOTES, 'UTF-8') ?>" novalidate>
                    <input type="hidden" name="_token" value="<?= htmlspecialchars((string) ($csrfToken ?? ''), ENT_QUOTES, 'UTF-8') ?>">

                    <div class="form-floating mb-3">
                        <input class="form-control" id="username" type="text" name="username" placeholder="yourname"
                               value="<?= htmlspecialchars((string) ($username ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                               autocomplete="username" required autofocus>
                        <label for="username">Username</label>
                    </div>

                    <div class="form-floating mb-3">
                        <input class="form-control" id="email" type="email" name="email" placeholder="you@example.com"
                               value="<?= htmlspecialchars((string) ($email ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                               autocomplete="email" required>
                        <label for="email">Email address</label>
                    </div>

                    <div class="form-floating mb-3">
                        <input class="form-control" id="password" type="password" name="password"
                               placeholder="Password" autocomplete="new-password" required>
                        <label for="password">Password</label>
                    </div>

                    <div class="form-floating mb-3">
                        <input class="form-control" id="passwordConfirm" type="password" name="passwordConfirm"
                               placeholder="Repeat it" autocomplete="new-password" required>
                        <label for="passwordConfirm">Repeat the password</label>
                    </div>

                    <p class="text-muted small">
                        At least <?= (int) ($minLength ?? 12) ?> characters. Length is the only rule.
                    </p>

                    <button class="btn btn-primary btn-xl w-100" type="submit">
                        <i class="fas fa-user-plus fa-fw"></i>
                        Create the account
                    </button>
                </form>

                <p class="text-center mt-4 mb-0">
                    Already have an account?
                    <a href="<?= htmlspecialchars((string) ($loginUrl ?? '/login'), ENT_QUOTES, 'UTF-8') ?>">Sign in</a>
                </p>
            </div>
        </div>
    </div>
</section>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
<?php include $footerPartial ?>
