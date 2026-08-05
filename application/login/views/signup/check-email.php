<?php

/**
 * Shown after any signup submission that got past validation - whether the
 * address was free or already had an account.
 *
 * Conditional wording again, for the same reason as password/sent.php: a page
 * that confirmed the account had been created would be answering whether the
 * address was already registered.
 */

?>
<?php include $headerPartial ?>
<?php include $navPartial ?>
<section class="page-section" style="padding-top: 10rem;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-8">
                <h2 class="page-section-heading text-center text-uppercase text-secondary mb-0">Check Your Email</h2>
                <div class="divider-custom">
                    <div class="divider-custom-line"></div>
                    <div class="divider-custom-icon"><i class="fas fa-envelope"></i></div>
                    <div class="divider-custom-line"></div>
                </div>

                <div class="alert alert-info" role="alert">
                    <i class="fas fa-circle-info fa-fw"></i>
                    <?= htmlspecialchars((string) ($message ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </div>

                <p class="text-muted">
                    The account cannot be used until that link is followed - which is what makes
                    the address on it worth anything.
                </p>

                <p class="text-center mt-4 mb-0">
                    <a href="<?= htmlspecialchars((string) ($loginUrl ?? '/login'), ENT_QUOTES, 'UTF-8') ?>">Back to sign in</a>
                </p>
            </div>
        </div>
    </div>
</section>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
<?php include $footerPartial ?>
