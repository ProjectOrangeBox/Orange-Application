<?php

/**
 * 403: logged in, and still no.
 *
 * Deliberately not a redirect to the login form. The visitor has already been
 * through it, and sending them back would say "try again" about something
 * trying again cannot fix.
 */

$deniedPermission = $permission ?? '';
?>
<?php include $headerPartial ?>
<?php include $navPartial ?>
<section class="page-section" style="padding-top: 10rem;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-6 text-center">
                <h2 class="page-section-heading text-uppercase text-secondary mb-0">Not Allowed</h2>
                <div class="divider-custom">
                    <div class="divider-custom-line"></div>
                    <div class="divider-custom-icon"><i class="fas fa-ban"></i></div>
                    <div class="divider-custom-line"></div>
                </div>

                <p class="lead">
                    You are signed in as
                    <strong><?= htmlspecialchars((string) ($currentUser->username ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>,
                    but this account does not have permission to view that page.
                </p>

<?php if ($deniedPermission !== '') : ?>
                <p class="text-muted">
                    Missing permission: <code><?= htmlspecialchars((string) $deniedPermission, ENT_QUOTES, 'UTF-8') ?></code>
                </p>
<?php endif; ?>

                <a class="btn btn-primary btn-xl mt-3" href="<?= htmlspecialchars((string) ($homeUrl ?? '/'), ENT_QUOTES, 'UTF-8') ?>">
                    <i class="fas fa-house fa-fw"></i>
                    Back Home
                </a>
            </div>
        </div>
    </div>
</section>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
<?php include $footerPartial ?>
