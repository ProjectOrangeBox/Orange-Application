<?php

/**
 * A reset link that is expired, already spent, or was never real.
 *
 * One page for all three - see UserTokenModel::consume(), which returns the same
 * answer for each so nothing here can tell an attacker they guessed a real token.
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
                    That link cannot be used. Reset links are good once and expire, so this one
                    has probably been used already or has simply timed out.
                </div>

                <p class="text-muted">Requesting a new one takes a moment.</p>

                <a class="btn btn-primary btn-xl w-100" href="<?= htmlspecialchars((string) ($forgotUrl ?? '/password/forgot'), ENT_QUOTES, 'UTF-8') ?>">
                    <i class="fas fa-key fa-fw"></i>
                    Request a new link
                </a>
            </div>
        </div>
    </div>
</section>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
<?php include $footerPartial ?>
