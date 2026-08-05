<?php

/**
 * The login form.
 *
 * The action is the same URL as the form itself - GET renders, POST checks -
 * so there is one route name per verb and no third URL to keep in step.
 *
 * autocomplete attributes are set deliberately: password managers fill and save
 * credentials properly when told which field is which, and a manager that works
 * is what stops people choosing a password they can retype from memory.
 */

$loginError = $error ?? '';
$loginEmail = $email ?? '';
$loginToken = $csrfToken ?? '';
?>
<?php include $headerPartial ?>
<?php include $navPartial ?>
<section class="page-section" style="padding-top: 10rem;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-8">
                <h2 class="page-section-heading text-center text-uppercase text-secondary mb-0">Log In</h2>
                <!-- Icon Divider-->
                <div class="divider-custom">
                    <div class="divider-custom-line"></div>
                    <div class="divider-custom-icon"><i class="fas fa-lock"></i></div>
                    <div class="divider-custom-line"></div>
                </div>

<?php if ($loginError !== '') : ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-triangle-exclamation fa-fw"></i>
                    <?= htmlspecialchars((string) $loginError, ENT_QUOTES, 'UTF-8') ?>
                </div>
<?php endif; ?>

                <form method="post" action="<?= htmlspecialchars((string) ($loginUrl ?? '/login'), ENT_QUOTES, 'UTF-8') ?>" novalidate>
                    <input type="hidden" name="_token" value="<?= htmlspecialchars((string) $loginToken, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="form-floating mb-3">
                        <input class="form-control" id="email" type="email" name="email" placeholder="you@example.com"
                               value="<?= htmlspecialchars((string) $loginEmail, ENT_QUOTES, 'UTF-8') ?>"
                               autocomplete="username" required autofocus>
                        <label for="email">Email address</label>
                    </div>

                    <div class="form-floating mb-3">
                        <input class="form-control" id="password" type="password" name="password" placeholder="Password"
                               autocomplete="current-password" required>
                        <label for="password">Password</label>
                    </div>

                    <button class="btn btn-primary btn-xl w-100" type="submit">
                        <i class="fas fa-right-to-bracket fa-fw"></i>
                        Log In
                    </button>
                </form>

                <p class="text-center mt-3 mb-0">
                    <a href="<?= htmlspecialchars((string) ($forgotUrl ?? '/password/forgot'), ENT_QUOTES, 'UTF-8') ?>">Forgot your password?</a>
                </p>
                <p class="text-center mt-1 mb-0">
                    No account yet?
                    <a href="<?= htmlspecialchars((string) ($signupUrl ?? '/signup'), ENT_QUOTES, 'UTF-8') ?>">Sign up</a>
                </p>

<?php if (!empty($demoEmail)) : ?>
                <div class="alert alert-secondary mt-4 mb-0 small">
                    <strong>Example account.</strong>
                    <code><?= htmlspecialchars((string) $demoEmail, ENT_QUOTES, 'UTF-8') ?></code> /
                    <code><?= htmlspecialchars((string) ($demoPassword ?? ''), ENT_QUOTES, 'UTF-8') ?></code><br>
                    Seeded by <code>composer db:seed</code>. Public example data in a public
                    repository - never treat it as a secret, and never let it reach anything
                    that matters.
                </div>
<?php endif; ?>
            </div>
        </div>
    </div>
</section>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
<?php include $footerPartial ?>
