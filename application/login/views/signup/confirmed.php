<?php

/**
 * The address is proven and the account is on.
 *
 * Not signed in: clicking a link in an inbox proves someone reads that inbox,
 * not that they know the password.
 */

?>
<?php include $headerPartial ?>
<?php include $navPartial ?>
<section class="page-section" style="padding-top: 10rem;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-8">
                <h2 class="page-section-heading text-center text-uppercase text-secondary mb-0">Account Confirmed</h2>
                <div class="divider-custom">
                    <div class="divider-custom-line"></div>
                    <div class="divider-custom-icon"><i class="fas fa-circle-check"></i></div>
                    <div class="divider-custom-line"></div>
                </div>

                <div class="alert alert-success" role="alert">
                    <i class="fas fa-check fa-fw"></i>
                    Your address is confirmed and the account is now active.
                </div>

                <p class="text-muted">
                    A new account holds no permissions yet, so the dashboard will show every
                    check answering <strong>No</strong> - which is the point of it. The seeded
                    <code>admin</code> account holds all three for comparison.
                </p>

                <a class="btn btn-primary btn-xl w-100" href="<?= htmlspecialchars((string) ($loginUrl ?? '/login'), ENT_QUOTES, 'UTF-8') ?>">
                    <i class="fas fa-right-to-bracket fa-fw"></i>
                    Sign in
                </a>
            </div>
        </div>
    </div>
</section>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
<?php include $footerPartial ?>
