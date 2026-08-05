<?php

/**
 * The password changed.
 *
 * No session is started here. Setting a password proves someone reads the
 * address; signing in proves they know the password, which is a different claim
 * and is made on the next page.
 */

?>
<?php include $headerPartial ?>
<?php include $navPartial ?>
<section class="page-section" style="padding-top: 10rem;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-8">
                <h2 class="page-section-heading text-center text-uppercase text-secondary mb-0">Password Changed</h2>
                <div class="divider-custom">
                    <div class="divider-custom-line"></div>
                    <div class="divider-custom-icon"><i class="fas fa-circle-check"></i></div>
                    <div class="divider-custom-line"></div>
                </div>

                <div class="alert alert-success" role="alert">
                    <i class="fas fa-check fa-fw"></i>
                    Your password has been changed, and any other outstanding reset links for
                    this account have stopped working.
                </div>

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
