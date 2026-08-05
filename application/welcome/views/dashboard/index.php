<?php

/**
 * The protected page.
 *
 * Everything shown here was decided in DashboardController, by asking
 * orange/acl. The view renders the answers and makes none of its own - a
 * template that decides who may see what is a template that has to be audited
 * like a controller, and templates rarely are.
 */

$dashPermissions = $permissions ?? [];
$dashMayDelete = $mayDelete ?? false;
?>
<?php include $headerPartial ?>
<?php include $navPartial ?>
<section class="page-section" style="padding-top: 10rem;">
    <div class="container">
        <h2 class="page-section-heading text-center text-uppercase text-secondary mb-0">Dashboard</h2>
        <div class="divider-custom">
            <div class="divider-custom-line"></div>
            <div class="divider-custom-icon"><i class="fas fa-shield-halved"></i></div>
            <div class="divider-custom-line"></div>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                <p class="lead text-center">
                    Signed in as
                    <strong><?= htmlspecialchars((string) ($currentUser->username ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                    (user #<?= (int) ($currentUser->id ?? 0) ?>).
                </p>
                <p class="text-center text-muted">
                    A guest never reaches this page - the controller sends them to the login
                    form first, and remembers this address so logging in lands back here.
                </p>

                <h4 class="text-uppercase mt-5">What this account may do</h4>
                <p class="text-muted small mb-3">
                    Each row is a live <code>$user-&gt;can('...')</code> against orange/acl - the
                    same call the orders API makes before it will accept a write. The page and
                    the endpoint cannot disagree, because neither of them decides it.
                </p>

                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th scope="col">Permission</th>
                                <th scope="col">Description</th>
                                <th scope="col" class="text-end">Granted</th>
                            </tr>
                        </thead>
                        <tbody>
<?php foreach ($dashPermissions as $key => $permission) : ?>
                            <tr>
                                <td><code><?= htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') ?></code></td>
                                <td><?= htmlspecialchars((string) ($permission['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-end">
    <?php if (!empty($permission['granted'])) : ?>
                                    <span class="badge bg-success"><i class="fas fa-check fa-fw"></i> Yes</span>
    <?php else : ?>
                                    <span class="badge bg-secondary"><i class="fas fa-xmark fa-fw"></i> No</span>
    <?php endif; ?>
                                </td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

<?php if ($dashMayDelete) : ?>
                <div class="alert alert-warning mt-4">
                    <h5 class="text-uppercase"><i class="fas fa-trash fa-fw"></i> Danger zone</h5>
                    <p class="mb-0">
                        This panel is rendered only for an account holding
                        <code>orders.delete</code>. Hiding it from everyone else is courtesy,
                        not security - <code>DELETE /api/orders/1</code> asks orange/acl again
                        on its own, and refuses on its own.
                    </p>
                </div>
<?php endif; ?>
            </div>
        </div>
    </div>
</section>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
<?php include $footerPartial ?>
