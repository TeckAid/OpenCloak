<?php
/**
 * Admin Panel - Domain Management (multi-domain short links)
 */

require_once __DIR__ . '/../includes/bootstrap.php';
boot_app(true);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rules.php';

require_login();

$db = getDB();
$userId = current_user_id();
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_post();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            $message = 'Invalid domain. Use a hostname like s.example.com';
            $messageType = 'error';
        } else {
            $check = $db->prepare("SELECT id FROM domains WHERE domain = ?");
            $check->execute([$domain]);
            if ($check->fetch()) {
                $message = 'Domain already exists.';
                $messageType = 'error';
            } else {
                $db->prepare("INSERT INTO domains (user_id, domain, is_system, is_active) VALUES (?, ?, 0, 1)")
                   ->execute([$userId, $domain]);
                $message = 'Domain added! Point it to this server via CNAME, then create links for it.';
                $messageType = 'success';
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM domains WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        $d = $stmt->fetch();
        if ($d && empty($d['is_system'])) {
            $db->prepare("UPDATE domains SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")
               ->execute([$d['is_active'] ? 0 : 1, $id, $userId]);
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $d = owned_row($db, 'domains', $id, $userId);
        if ($d && empty($d['is_system'])) {
            $error = delete_domain_safely($db, $userId, $id);
            if ($error !== null) {
                $message = $error;
                $messageType = 'error';
            } else {
                $message = 'Domain deleted.';
                $messageType = 'success';
            }
        }
    }
}

$stmt = $db->prepare("SELECT * FROM domains WHERE user_id = ? ORDER BY is_system DESC, created_at ASC");
$stmt->execute([$userId]);
$domains = $stmt->fetchAll();

$primaryHost = (string)(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);

$linkCounts = [];
$stmt = $db->prepare("SELECT domain_id, COUNT(*) AS cnt FROM links WHERE user_id = ? AND domain_id IS NOT NULL GROUP BY domain_id");
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $linkCounts[(int)$row['domain_id']] = (int)$row['cnt'];
}

$activeNav = '/admin/domains.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domains - Cloaking SaaS</title>
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260905">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <div class="container">
        <h1 class="page-title">Domains</h1>
        <p class="page-intro">Every hostname your links live on.</p>
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><h2>Add Custom Domain</h2></div>
            <div class="card-body">
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="domain">Domain (e.g. s.example.com)</label>
                            <div class="input-group">
                                <input type="text" id="domain" name="domain" placeholder="s.example.com" pattern="(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}" required>
                            </div>
                        </div>
                        <div class="form-group" style="align-self:flex-end">
                            <button type="submit" class="btn btn-primary">Add Domain</button>
                        </div>
                    </div>
                </form>
                <h3>Setup steps</h3>
                <ol>
                    <li>Add your domain above.</li>
                    <li>At your DNS provider, add a <strong>CNAME</strong> record pointing to this server's hostname
                        (<code><?= htmlspecialchars($primaryHost) ?></code>) — or an <strong>A record</strong> pointing to its IP.</li>
                    <li>Make sure the web server accepts the new hostname (see the wildcard vhost in <code>nginx.conf</code>).</li>
                    <li>Create links and select this domain — they will resolve as <code>https://s.example.com/slug</code>.</li>
                </ol>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Domains</h2></div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr><th>Domain</th><th>Type</th><th>Status</th><th>Links</th><th>Added</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code><?= htmlspecialchars($primaryHost) ?></code></td>
                            <td><span class="badge badge-info">System</span></td>
                            <td><span class="badge badge-success">Active</span></td>
                            <td><?= 0 ?></td>
                            <td>—</td>
                            <td>—</td>
                        </tr>
                        <?php if (empty($domains)): ?>
                            <tr><td colspan="6" class="text-center">No custom domains yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($domains as $d): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($d['domain']) ?></code></td>
                                    <td><span class="badge badge-info">Custom</span></td>
                                    <td>
                                        <span class="badge <?= $d['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                                            <?= $d['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td><?= $linkCounts[$d['id']] ?? 0 ?></td>
                                    <td><?= date('M j, Y', strtotime($d['created_at'])) ?></td>
                                    <td>
                                        <form method="POST" action="" style="display:inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                            <button type="submit" class="btn btn-sm"><?= $d['is_active'] ? 'Disable' : 'Enable' ?></button>
                                        </form>
                                        <form method="POST" action="" style="display:inline" onsubmit="return confirm('Delete this domain?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
