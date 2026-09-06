<?php
/**
 * Admin Panel - Campaign Management
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

// ---- Handle form submissions --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_post();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);

        try {
            $existing = [];
            if ($action === 'update') {
                $existing = owned_row($db, 'campaigns', $id, $userId) ?: [];
                if ($existing === []) {
                    throw new BadRequestException('Campaign not found.', 404);
                }
            }

            $parsed = parse_campaign_input($_POST, $existing, ['source' => 'form']);
            $values = array_map(static fn(string $column) => $parsed[$column], CAMPAIGN_MUTABLE_COLUMNS);

            if ($action === 'create') {
                $columns = implode(',', CAMPAIGN_MUTABLE_COLUMNS);
                $placeholders = implode(',', array_fill(0, count(CAMPAIGN_MUTABLE_COLUMNS) + 1, '?'));
                $db->prepare("INSERT INTO campaigns (user_id, {$columns}) VALUES ({$placeholders})")
                   ->execute(array_merge([$userId], $values));
                $message = 'Campaign created successfully!';
            } else {
                $set = implode(', ', array_map(static fn(string $column): string => "{$column} = ?", CAMPAIGN_MUTABLE_COLUMNS));
                $db->prepare("UPDATE campaigns SET {$set}, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")
                   ->execute(array_merge($values, [$id, $userId]));
                $message = 'Campaign updated successfully!';
            }

            $messageType = 'success';
        } catch (BadRequestException $e) {
            http_response_code($e->getCode() >= 400 ? $e->getCode() : 400);
            $message = $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $campaign = owned_row($db, 'campaigns', $id, $userId);
        if ($campaign === null) {
            $message = 'Campaign not found.';
            $messageType = 'error';
        } else {
            $error = delete_campaign_safely($db, $userId, $id);
            if ($error !== null) {
                $message = $error;
                $messageType = 'error';
            } else {
                $message = 'Campaign deleted.';
                $messageType = 'success';
            }
        }
    } elseif ($action === 'restore') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE campaigns SET is_deleted = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
        $message = 'Campaign restored.';
        $messageType = 'success';
    } elseif ($action === 'clone') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        $src = $stmt->fetch();
        if ($src) {
            $params = [];
            foreach (CAMPAIGN_MUTABLE_COLUMNS as $col) {
                $params[] = $col === 'name' ? $src['name'] . ' (copy)' : $src[$col];
            }
            $db->prepare("INSERT INTO campaigns (user_id, " . implode(',', CAMPAIGN_MUTABLE_COLUMNS) . ") VALUES (?, " . rtrim(str_repeat('?, ', count(CAMPAIGN_MUTABLE_COLUMNS)), ', ') . ")")
               ->execute(array_merge([$userId], $params));
            $message = 'Campaign cloned!';
            $messageType = 'success';
        }
    }
}

// ---- Editing --------------------------------------------------------------------
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ? AND user_id = ?");
    $stmt->execute([(int)$_GET['edit'], $userId]);
    $editing = $stmt->fetch() ?: null;
}

$r = $editing ?: ['is_active' => 0, 'allow_empty_referer' => 0, 'block_review_infra' => 0];

// ---- List --------------------------------------------------------------------------
$stmt = $db->prepare("SELECT * FROM campaigns WHERE user_id = ? AND is_deleted = 0 ORDER BY is_active DESC, created_at DESC");
$stmt->execute([$userId]);
$campaigns = $stmt->fetchAll();

$linkCounts = [];
$stmt = $db->prepare("SELECT campaign_id, COUNT(*) AS cnt FROM links WHERE user_id = ? AND campaign_id IS NOT NULL GROUP BY campaign_id");
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $linkCounts[(int)$row['campaign_id']] = (int)$row['cnt'];
}

$todayStats = [];
$stmt = $db->prepare("
    SELECT campaign_id,
           SUM(CASE WHEN shown_page = 'offer' THEN 1 ELSE 0 END) AS offers,
           SUM(CASE WHEN shown_page = 'white' THEN 1 ELSE 0 END) AS safe
    FROM hit_log
    WHERE campaign_id IS NOT NULL AND created_at >= datetime('now', '-1 day')
    GROUP BY campaign_id");
$stmt->execute();
foreach ($stmt->fetchAll() as $row) {
    $todayStats[(int)$row['campaign_id']] = $row;
}

$activeCampaigns = array_values(array_filter($campaigns, static fn(array $c): bool => (int)$c['is_active'] === 1));
$pausedCampaigns = array_values(array_filter($campaigns, static fn(array $c): bool => (int)$c['is_active'] !== 1));

$stmt = $db->prepare("SELECT * FROM campaigns WHERE user_id = ? AND is_deleted = 1 ORDER BY updated_at DESC");
$stmt->execute([$userId]);
$deletedCampaigns = $stmt->fetchAll();

$activeNav = '/admin/campaigns.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campaigns - Cloaking SaaS</title>
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260906">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <div class="container">
        <div class="detail-header">
            <div>
                <h1 class="page-title">Campaigns</h1>
                <p class="page-intro">One rule set, every link that shares it.</p>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" data-open-modal="#campaign-modal">New campaign</button>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($campaigns === []): ?>
            <div class="card">
                <div class="list-empty">
                    No campaigns yet. <a href="#" data-open-modal="#campaign-modal">Start with a preset</a> and tune from there.
                </div>
            </div>
        <?php else: ?>

            <div class="section-title">
                <h2>Active</h2>
                <span class="count"><?= count($activeCampaigns) ?> of <?= count($campaigns) ?></span>
            </div>
            <div class="card">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Campaign</th><th>Links</th><th>Today</th><th>Offer / safe</th><th>Hits</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($activeCampaigns === []): ?>
                                <tr><td colspan="6" class="text-center">No active campaigns.</td></tr>
                            <?php else: ?>
                                <?php foreach ($activeCampaigns as $c): ?>
                                    <?php $today = $todayStats[(int)$c['id']] ?? ['offers' => 0, 'safe' => 0]; ?>
                                    <tr>
                                        <td><?= htmlspecialchars($c['name']) ?></td>
                                        <td><?= $linkCounts[$c['id']] ?? 0 ?></td>
                                        <td><?= (int)$today['offers'] + (int)$today['safe'] ?></td>
                                        <td>
                                            <span class="badge badge-success"><?= (int)$today['offers'] ?> offer</span>
                                            <span class="badge badge-warning"><?= (int)$today['safe'] ?> safe</span>
                                        </td>
                                        <td><?= number_format((int)$c['total_hits']) ?></td>
                                        <td>
                                            <a href="?edit=<?= (int)$c['id'] ?>" class="btn btn-sm">Edit</a>
                                            <form method="POST" action="" style="display:inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="clone">
                                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                                <button type="submit" class="btn btn-sm">Clone</button>
                                            </form>
                                            <form method="POST" action="" style="display:inline" onsubmit="return confirm('Delete this campaign?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
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

            <?php if ($pausedCampaigns !== []): ?>
                <div class="section-title">
                    <h2>Paused</h2>
                    <span class="count"><?= count($pausedCampaigns) ?></span>
                </div>
                <div class="card">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Campaign</th><th>Links</th><th>Hits</th><th>Created</th><th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pausedCampaigns as $c): ?>
                                    <tr class="row-inactive">
                                        <td><?= htmlspecialchars($c['name']) ?></td>
                                        <td><?= $linkCounts[$c['id']] ?? 0 ?></td>
                                        <td><?= number_format((int)$c['total_hits']) ?></td>
                                        <td><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
                                        <td>
                                            <a href="?edit=<?= (int)$c['id'] ?>" class="btn btn-sm">Edit</a>
                                            <form method="POST" action="" style="display:inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="clone">
                                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                                <button type="submit" class="btn btn-sm">Clone</button>
                                            </form>
                                            <form method="POST" action="" style="display:inline" onsubmit="return confirm('Delete this campaign?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($deletedCampaigns !== []): ?>
                <div class="section-title">
                    <h2>Deleted</h2>
                    <span class="count"><?= count($deletedCampaigns) ?></span>
                </div>
                <div class="card">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr><th>Campaign</th><th>Hits</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deletedCampaigns as $c): ?>
                                    <tr class="row-inactive">
                                        <td><?= htmlspecialchars($c['name']) ?></td>
                                        <td><?= number_format((int)$c['total_hits']) ?></td>
                                        <td>
                                            <form method="POST" action="" style="display:inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="restore">
                                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                                <button type="submit" class="btn btn-sm">Restore</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Create / edit wizard -->
    <div class="modal-backdrop" id="campaign-modal" data-open="<?= $editing ? '1' : '0' ?>">
        <div class="modal">
            <div class="modal-header">
                <h2><?= $editing ? 'Edit campaign' : 'New campaign' ?></h2>
                <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" data-wizard id="campaign-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
                    <?php if ($editing): ?>
                        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                    <?php else: ?>
                        <input type="hidden" name="is_active" value="1">
                    <?php endif; ?>

                    <div class="wizard-indicator">
                        <span class="wizard-dot"></span><span class="wizard-dot"></span><span class="wizard-dot"></span>
                    </div>

                    <!-- Step 1: identity + destination -->
                    <div class="wizard-step" data-step="1">
                        <h3>What is it, and where do real visitors go?</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="name">Name</label>
                                <input type="text" id="name" name="name" value="<?= htmlspecialchars($r['name'] ?? '') ?>" placeholder="TikTok US — nutra">
                            </div>
                            <div class="form-group">
                                <label for="preset">Start from a preset</label>
                                <select id="preset">
                                    <option value="">Build from scratch</option>
                                    <?php foreach (CAMPAIGN_PRESETS as $key => $preset): ?>
                                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($preset['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Offer URL</label>
                            <input type="url" name="offer_url" value="<?= htmlspecialchars($r['offer_url'] ?? '') ?>" placeholder="https://example.com/offer">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Offer pool (A/B — one URL per line)</label>
                                <textarea name="offer_urls" rows="3" placeholder="https://example.com/a&#10;https://example.com/b"><?= htmlspecialchars($r['offer_urls'] ?? '') ?></textarea>
                            </div>
                            <div class="form-group">
                                <label>Rotation</label>
                                <select name="rotation_mode">
                                    <option value="single" <?= ($r['rotation_mode'] ?? 'single') === 'single' ? 'selected' : '' ?>>Single</option>
                                    <option value="random" <?= ($r['rotation_mode'] ?? '') === 'random' ? 'selected' : '' ?>>Random (A/B)</option>
                                    <option value="sequential" <?= ($r['rotation_mode'] ?? '') === 'sequential' ? 'selected' : '' ?>>Round-robin</option>
                                </select>
                                <div class="form-group" style="margin-top:0.75rem">
                                    <label>Country routes (multi-geo, optional)</label>
                                    <textarea name="offer_routes" rows="3" placeholder="US=https://example.com/us&#10;*=https://example.com/world"><?= htmlspecialchars($r['offer_routes'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                        <?php if ($editing): ?>
                            <label class="checkbox" style="margin-top:0.4rem">
                                <input type="checkbox" name="is_active" value="1" <?= !empty($r['is_active']) ? 'checked' : '' ?>> Active
                            </label>
                        <?php endif; ?>
                    </div>

                    <!-- Step 2: safe page + reject behavior -->
                    <div class="wizard-step" data-step="2">
                        <h3>What do reviewers see?</h3>
                        <div class="form-group">
                            <label>Safe page HTML (leave empty for the default)</label>
                            <textarea name="white_page" rows="5" placeholder="Leave empty for default safe page"><?= htmlspecialchars($r['white_page'] ?? '') ?></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>When a visitor is denied</label>
                                <select name="reject_mode">
                                    <option value="white" <?= ($r['reject_mode'] ?? 'white') === 'white' ? 'selected' : '' ?>>Show the safe page</option>
                                    <option value="error" <?= ($r['reject_mode'] ?? '') === 'error' ? 'selected' : '' ?>>Return an HTTP error</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Error code</label>
                                <select name="reject_code">
                                    <?php foreach ([403, 404, 410, 429, 451] as $code): ?>
                                        <option value="<?= $code ?>" <?= (int)($r['reject_code'] ?? 403) === $code ? 'selected' : '' ?>><?= $code ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Redirect type</label>
                                <select name="redirect_type">
                                    <option value="302" <?= ($r['redirect_type'] ?? '302') === '302' ? 'selected' : '' ?>>302 (temporary)</option>
                                    <option value="301" <?= ($r['redirect_type'] ?? '') === '301' ? 'selected' : '' ?>>301 (permanent)</option>
                                    <option value="303" <?= ($r['redirect_type'] ?? '') === '303' ? 'selected' : '' ?>>303 (see other)</option>
                                    <option value="meta" <?= ($r['redirect_type'] ?? '') === 'meta' ? 'selected' : '' ?>>Meta refresh</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Delay (seconds)</label>
                                <input type="number" name="redirect_delay" min="0" max="30" value="<?= (int)($r['redirect_delay'] ?? 0) ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: filters -->
                    <div class="wizard-step" data-step="3">
                        <h3>Who gets through?</h3>
                        <?php include __DIR__ . '/../includes/rule_fields.php'; ?>
                    </div>

                    <div class="modal-footer" style="margin:0 -1.3rem -1.3rem;">
                        <button type="button" class="btn" data-wizard-back>Back</button>
                        <span class="spacer"></span>
                        <button type="button" class="btn" data-wizard-next>Next</button>
                        <button type="submit" class="btn btn-primary" data-wizard-submit><?= $editing ? 'Save changes' : 'Create campaign' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="/assets/js/admin.js?v=20260906"></script>
    <script>
    var presets = <?= json_encode(CAMPAIGN_PRESETS) ?>;
    document.getElementById('preset').addEventListener('change', function () {
        var p = presets[this.value];
        if (!p) return;
        var r = p.rules;
        var f = document.getElementById('campaign-form');
        var setVal = function (name, value) {
            var el = f.elements[name];
            if (!el) return;
            if (el.type === 'checkbox') { el.checked = value ? true : false; }
            else { el.value = value; }
        };
        for (var k in r) {
            if (k === 'allow_empty_referer') { setVal(k, r[k] ? 1 : 0); }
            else { setVal(k, r[k]); }
        }
    });
    </script>
</body>
</html>
