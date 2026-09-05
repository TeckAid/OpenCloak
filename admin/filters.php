<?php
/**
 * Admin Panel - Reusable filter lists (IP / UA / referer / ISP)
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

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $listType = in_array($_POST['list_type'] ?? 'black', ['white', 'black'], true) ? $_POST['list_type'] : 'black';
        if ($name === '' || strlen($name) > 32) {
            $message = 'Name is required (max 32 chars).';
            $messageType = 'error';
        } else {
            $db->prepare("INSERT INTO filter_lists (user_id, name, list_type, list_ips, list_agents, list_providers, list_referers)
                          VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([
                $userId, $name, $listType,
                trim($_POST['list_ips'] ?? ''),
                trim($_POST['list_agents'] ?? ''),
                trim($_POST['list_providers'] ?? ''),
                trim($_POST['list_referers'] ?? ''),
            ]);
            $message = 'Filter list created.';
            $messageType = 'success';
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $exists = owned_row($db, 'filter_lists', $id, $userId);
        if ($exists === null) {
            $message = 'Filter list not found.';
            $messageType = 'error';
        } else {
            $listType = in_array($_POST['list_type'] ?? $exists['list_type'], ['white', 'black'], true) ? ($_POST['list_type'] ?? $exists['list_type']) : $exists['list_type'];
            $db->prepare("UPDATE filter_lists SET name = ?, list_type = ?, list_ips = ?, list_agents = ?, list_providers = ?, list_referers = ?, updated_at = CURRENT_TIMESTAMP
                          WHERE id = ? AND user_id = ?")->execute([
                trim($_POST['name'] ?? $exists['name']),
                $listType,
                trim($_POST['list_ips'] ?? ''),
                trim($_POST['list_agents'] ?? ''),
                trim($_POST['list_providers'] ?? ''),
                trim($_POST['list_referers'] ?? ''),
                $id, $userId,
            ]);
            $message = 'Filter list updated.';
            $messageType = 'success';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE filter_lists SET is_deleted = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")
           ->execute([$id, $userId]);
        $message = 'Filter list deleted.';
        $messageType = 'success';
    } elseif ($action === 'restore') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE filter_lists SET is_deleted = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")
           ->execute([$id, $userId]);
        $message = 'Filter list restored.';
        $messageType = 'success';
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $editing = owned_row($db, 'filter_lists', (int)$_GET['edit'], $userId);
}

$stmt = $db->prepare("SELECT * FROM filter_lists WHERE user_id = ? ORDER BY is_deleted ASC, created_at DESC");
$stmt->execute([$userId]);
$lists = $stmt->fetchAll();

$usage = [];
$stmt = $db->prepare("SELECT filter_id, COUNT(*) AS cnt FROM campaigns WHERE user_id = ? AND filter_id > 0 GROUP BY filter_id");
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $usage[(int)$row['filter_id']] = (int)$row['cnt'];
}
$stmt = $db->prepare("SELECT filter_id, COUNT(*) AS cnt FROM links WHERE user_id = ? AND filter_id > 0 GROUP BY filter_id");
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $usage[(int)$row['filter_id']] = ($usage[(int)$row['filter_id']] ?? 0) + (int)$row['cnt'];
}

$r = $editing ?: ['name' => '', 'list_type' => 'black'];

$activeNav = '/admin/filters.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Filters - Cloaking SaaS</title>
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260906">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <div class="container">
        <div class="detail-header">
            <div>
                <h1 class="page-title">Filters</h1>
                <p class="page-intro">Reusable IP, user-agent, referer, and ISP lists you attach to campaigns and links.</p>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" data-open-modal="#filter-modal">New filter list</button>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($lists === []): ?>
            <div class="card">
                <div class="list-empty">
                    No filter lists yet. <a href="#" data-open-modal="#filter-modal">Create your first</a> — then attach it to any campaign or link.
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th><th>Type</th><th>Entries</th><th>Used by</th><th>Created</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lists as $list): ?>
                                <?php
                                $entryCount = 0;
                                foreach (['list_ips', 'list_agents', 'list_providers', 'list_referers'] as $col) {
                                    $entryCount += count(array_filter(preg_split('/[\r\n,]+/', (string)$list[$col]) ?: [], static fn($e) => trim($e) !== ''));
                                }
                                ?>
                                <tr class="<?= $list['is_deleted'] ? 'row-inactive' : '' ?>">
                                    <td><?= htmlspecialchars($list['name']) ?></td>
                                    <td>
                                        <span class="badge <?= $list['list_type'] === 'white' ? 'badge-success' : 'badge-danger' ?>">
                                            <?= $list['list_type'] === 'white' ? 'Allow' : 'Deny' ?>
                                        </span>
                                    </td>
                                    <td><?= $entryCount ?></td>
                                    <td><?= $usage[(int)$list['id']] ?? 0 ?></td>
                                    <td><?= date('M j, Y', strtotime($list['created_at'])) ?></td>
                                    <td>
                                        <?php if ($list['is_deleted']): ?>
                                            <form method="POST" action="" style="display:inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="restore">
                                                <input type="hidden" name="id" value="<?= (int)$list['id'] ?>">
                                                <button type="submit" class="btn btn-sm">Restore</button>
                                            </form>
                                        <?php else: ?>
                                            <a href="?edit=<?= (int)$list['id'] ?>" class="btn btn-sm">Edit</a>
                                            <form method="POST" action="" style="display:inline" onsubmit="return confirm('Delete this filter list?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$list['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="modal-backdrop" id="filter-modal" data-open="<?= $editing ? '1' : '0' ?>">
        <div class="modal">
            <div class="modal-header">
                <h2><?= $editing ? 'Edit filter list' : 'New filter list' ?></h2>
                <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
                    <?php if ($editing): ?>
                        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="name">Name</label>
                            <input type="text" id="name" name="name" value="<?= htmlspecialchars($r['name']) ?>" placeholder="Spy IPs">
                        </div>
                        <div class="form-group">
                            <label for="list_type">Behavior</label>
                            <select id="list_type" name="list_type">
                                <option value="black" <?= $r['list_type'] === 'black' ? 'selected' : '' ?>>Deny matches (blacklist)</option>
                                <option value="white" <?= $r['list_type'] === 'white' ? 'selected' : '' ?>>Allow only matches (whitelist)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>IPs / CIDRs (one per line)</label>
                        <textarea name="list_ips" rows="4" placeholder="203.0.113.10&#10;198.51.100.0/24"><?= htmlspecialchars($r['list_ips'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>User agents (substring match, one per line)</label>
                        <textarea name="list_agents" rows="3" placeholder="puppeteer&#10;headlesschrome"><?= htmlspecialchars($r['list_agents'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Referers (substring match, one per line)</label>
                        <textarea name="list_referers" rows="3" placeholder="evil.example&#10;*.scraper.io"><?= htmlspecialchars($r['list_referers'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>ISPs (substring match — used when IP intelligence provides ISP data)</label>
                        <textarea name="list_providers" rows="3" placeholder="hostinger&#10;digitalocean"><?= htmlspecialchars($r['list_providers'] ?? '') ?></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Create list' ?></button>
                        <?php if ($editing): ?>
                            <a href="/admin/filters.php" class="btn">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="/assets/js/admin.js?v=20260906"></script>
</body>
</html>
