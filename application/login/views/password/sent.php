<?php

/**
 * Shown to everyone who submits the forgot form - whether the address had an
 * account, had an unconfirmed one, or had none at all.
 *
 * The wording has to stay conditional ("if that address has an account"), which
 * is the whole point: a page that said "we have sent you an email" would be
 * saying the account exists.
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
                    The link is good for one use and expires shortly. If it does not arrive,
                    check the address you typed and whether the mail was filtered - then try
                    again.
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
