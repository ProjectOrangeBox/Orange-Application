<?php

/**
 * The shared navbar.
 *
 * Every value is read with a `?? default` so a page that never called
 * WebController::chrome() still renders a working nav rather than a stack of
 * undefined-variable notices. Views are excluded from PHPStan (they are handed
 * extract()ed data, which static analysis cannot see), so these defaults are the
 * only thing between a missing key and a broken page.
 *
 * Logging out is a form, not a link: it changes state, so it answers to POST and
 * carries the CSRF token like every other state change. A plain <a href> could be
 * fired by any <img> tag on any site on the internet.
 */

$navIsLoggedIn = $isLoggedIn ?? false;
$navUsername = $currentUser->username ?? '';
$navLoginUrl = $loginUrl ?? '/login';
$navSignupUrl = $signupUrl ?? '/signup';
$navLogoutUrl = $logoutUrl ?? '/logout';
$navDashboardUrl = $dashboardUrl ?? '/dashboard';
$navToken = $csrfToken ?? '';
?>
    <!-- Navigation-->
    <nav class="navbar navbar-expand-lg bg-secondary text-uppercase fixed-top" id="mainNav">
        <div class="container">
            <a class="navbar-brand" href="#page-top"><?= htmlspecialchars((string) ($name ?? ''), ENT_QUOTES, 'UTF-8') ?></a>
            <button class="navbar-toggler text-uppercase font-weight-bold bg-primary text-white rounded" type="button" data-bs-toggle="collapse" data-bs-target="#navbarResponsive" aria-controls="navbarResponsive" aria-expanded="false" aria-label="Toggle navigation">
                Menu
                <i class="fas fa-bars"></i>
            </button>
            <div class="collapse navbar-collapse" id="navbarResponsive">
                <ul class="navbar-nav ms-auto align-items-lg-center">
                    <li class="nav-item mx-0 mx-lg-1"><a class="nav-link py-3 px-0 px-lg-3 rounded" href="/#portfolio">Examples</a></li>
                    <li class="nav-item mx-0 mx-lg-1"><a class="nav-link py-3 px-0 px-lg-3 rounded" href="/#about">About</a></li>
<?php if ($navIsLoggedIn) : ?>
                    <li class="nav-item mx-0 mx-lg-1"><a class="nav-link py-3 px-0 px-lg-3 rounded" href="<?= htmlspecialchars($navDashboardUrl, ENT_QUOTES, 'UTF-8') ?>">Dashboard</a></li>
                    <li class="nav-item mx-0 mx-lg-1">
                        <span class="nav-link py-3 px-0 px-lg-3 text-white-50">
                            <i class="fas fa-user fa-fw"></i>
                            <?= htmlspecialchars((string) $navUsername, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </li>
                    <li class="nav-item mx-0 mx-lg-1">
                        <form method="post" action="<?= htmlspecialchars($navLogoutUrl, ENT_QUOTES, 'UTF-8') ?>" class="m-0">
                            <input type="hidden" name="_token" value="<?= htmlspecialchars((string) $navToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="nav-link py-3 px-0 px-lg-3 rounded btn btn-link text-decoration-none">Log Out</button>
                        </form>
                    </li>
<?php else : ?>
                    <li class="nav-item mx-0 mx-lg-1"><a class="nav-link py-3 px-0 px-lg-3 rounded" href="<?= htmlspecialchars($navLoginUrl, ENT_QUOTES, 'UTF-8') ?>">Log In</a></li>
                    <li class="nav-item mx-0 mx-lg-1"><a class="nav-link py-3 px-0 px-lg-3 rounded" href="<?= htmlspecialchars($navSignupUrl, ENT_QUOTES, 'UTF-8') ?>">Sign Up</a></li>
<?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
