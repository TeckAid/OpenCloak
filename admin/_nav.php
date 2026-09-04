<?php
/**
 * Shared admin navigation bar. Expects an $activeNav variable.
 */
if (!function_exists('csrf_field')) {
    exit;
}
$navItems = [
    '/admin/dashboard.php'  => 'Dashboard',
    '/admin/links.php'      => 'Links',
    '/admin/campaigns.php'  => 'Campaigns',
    '/admin/domains.php'    => 'Domains',
    '/admin/client.php'     => 'Client Mode',
    '/admin/settings.php'   => 'Settings',
];
$activeNav = $activeNav ?? '';
?>
<nav class="navbar">
    <div class="nav-brand">Cloak</div>
    <div class="nav-links">
        <?php foreach ($navItems as $href => $label): ?>
            <a href="<?= $href ?>" class="<?= $activeNav === $href ? 'active' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
        <form method="POST" action="/admin/dashboard.php" class="logout-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="btn-logout">Logout</button>
        </form>
    </div>
</nav>
