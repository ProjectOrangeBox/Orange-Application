<?php

/**
 * A confirmation link that is expired, already spent, or was never real - one
 * page for all three, as with password/expired.php.
 */

?>
<?php include $headerPartial ?>
<?php include $navPartial ?>
<section class="page-section" style="padding-top: 10rem;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-8">
                <h2 class="page-section-heading text-center text-uppercase text-secondary mb-0">Link No Longer Valid</h2>
                <div class="divider-custom">
                    <div class="divider-custom-line"></div>
                    <div class="divider-custom-icon"><i class="fas fa-link-slash"></i></div>
                    <div class="divider-custom-line"></div>
                </div>

                <div class="alert alert-warning" role="alert">
                    <i class="fas fa-triangle-exclamation fa-fw"></i>
                    That confirmation link cannot be used. It is good once and expires, so it has
                    probably been followed already or has timed out.
                </div>

                <p class="text-muted">
                    If the account was already confirmed, just sign in. Otherwise sign up again -
                    the address is free again once an unconfirmed account is cleaned up.
                </p>

                <a class="btn btn-primary btn-xl w-100" href="<?= htmlspecialchars((string) ($signupUrl ?? '/signup'), ENT_QUOTES, 'UTF-8') ?>">
                    <i class="fas fa-user-plus fa-fw"></i>
                    Sign up
                </a>
            </div>
        </div>
    </div>
</section>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
<?php include $footerPartial ?>
