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
$stmt = $db->prepare("SELECT * FROM campaigns WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$userId]);
$campaigns = $stmt->fetchAll();

$linkCounts = [];
$stmt = $db->prepare("SELECT campaign_id, COUNT(*) AS cnt FROM links WHERE user_id = ? AND campaign_id IS NOT NULL GROUP BY campaign_id");
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $linkCounts[(int)$row['campaign_id']] = (int)$row['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campaigns - Cloaking SaaS</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <div class="container">
        <h1 class="page-title">Campaigns</h1>
        <p class="page-intro">One rule set, every link that shares it.</p>
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2><?= $editing ? 'Edit Campaign' : 'New Campaign' ?></h2>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="campaign-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
                    <?php if ($editing): ?>
                        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="name">Campaign Name</label>
                            <input type="text" id="name" name="name" required value="<?= htmlspecialchars($r['name'] ?? '') ?>" placeholder="My Campaign">
                        </div>
                        <div class="form-group">
                            <label for="preset">Apply Preset</label>
                            <select id="preset">
                                <option value="">— none —</option>
                                <?php foreach (CAMPAIGN_PRESETS as $key => $preset): ?>
                                    <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($preset['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <h3>Actions</h3>
                    <div class="form-group">
                        <label>Offer URL (pass action - where real users go)</label>
                        <input type="url" name="offer_url" value="<?= htmlspecialchars($r['offer_url'] ?? '') ?>" placeholder="https://example.com/offer">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Offer Pool (A/B rotation - one URL per line)</label>
                            <textarea name="offer_urls" rows="3" placeholder="https://example.com/offer-a&#10;https://example.com/offer-b"><?= htmlspecialchars($r['offer_urls'] ?? '') ?></textarea>
                            <p class="form-hint">When set, this pool is used instead of the single Offer URL.</p>
                        </div>
                        <div class="form-group">
                            <label>Rotation Mode</label>
                            <select name="rotation_mode">
                                <option value="single" <?= ($r['rotation_mode'] ?? 'single') === 'single' ? 'selected' : '' ?>>Single offer</option>
                                <option value="random" <?= ($r['rotation_mode'] ?? '') === 'random' ? 'selected' : '' ?>>Random (A/B split)</option>
                                <option value="sequential" <?= ($r['rotation_mode'] ?? '') === 'sequential' ? 'selected' : '' ?>>Sequential (round-robin)</option>
                            </select>
                            <div class="form-group" style="margin-top:0.75rem">
                                <label>Country Offer Routes (multi-geo)</label>
                                <textarea name="offer_routes" rows="4" placeholder="US=https://example.com/us&#10;GB=https://example.com/uk&#10;*=https://example.com/world"><?= htmlspecialchars($r['offer_routes'] ?? '') ?></textarea>
                                <p class="form-hint">Format: <code>CC=url</code> per line. <code>*</code> is the fallback. Takes priority over the offer pool.</p>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Reject Action</label>
                            <select name="reject_mode">
                                <option value="white" <?= ($r['reject_mode'] ?? 'white') === 'white' ? 'selected' : '' ?>>Show white page</option>
                                <option value="error" <?= ($r['reject_mode'] ?? '') === 'error' ? 'selected' : '' ?>>Return HTTP error</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Reject Error Code</label>
                            <select name="reject_code">
                                <?php foreach ([403, 404, 410, 429, 451] as $code): ?>
                                    <option value="<?= $code ?>" <?= (int)($r['reject_code'] ?? 403) === $code ? 'selected' : '' ?>><?= $code ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Redirect Type</label>
                            <select name="redirect_type">
                                <option value="302" <?= ($r['redirect_type'] ?? '302') === '302' ? 'selected' : '' ?>>302 (Temporary)</option>
                                <option value="301" <?= ($r['redirect_type'] ?? '') === '301' ? 'selected' : '' ?>>301 (Permanent)</option>
                                <option value="303" <?= ($r['redirect_type'] ?? '') === '303' ? 'selected' : '' ?>>303 (See Other)</option>
                                <option value="meta" <?= ($r['redirect_type'] ?? '') === 'meta' ? 'selected' : '' ?>>Meta Refresh</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Redirect Delay (seconds)</label>
                            <input type="number" name="redirect_delay" min="0" max="30" value="<?= (int)($r['redirect_delay'] ?? 0) ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>White Page HTML (leave empty for default)</label>
                        <textarea name="white_page" rows="6" placeholder="Leave empty for default safe page"><?= htmlspecialchars($r['white_page'] ?? '') ?></textarea>
                    </div>

                    <?php include __DIR__ . '/../includes/rule_fields.php'; ?>

                    <?php if ($editing): ?>
                        <label class="checkbox" style="margin-top:1rem">
                            <input type="checkbox" name="is_active" value="1" <?= !empty($r['is_active']) ? 'checked' : '' ?>> Active
                        </label>
                    <?php endif; ?>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><?= $editing ? 'Update Campaign' : 'Create Campaign' ?></button>
                        <?php if ($editing): ?>
                            <a href="/admin/campaigns.php" class="btn">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Campaigns</h2></div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th><th>Status</th><th>Links</th><th>Hits</th><th>Offer</th><th>White</th><th>Created</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($campaigns)): ?>
                            <tr><td colspan="8" class="text-center">No campaigns yet. Start with a preset and tune from there.</td></tr>
                        <?php else: ?>
                            <?php foreach ($campaigns as $c): ?>
                                <tr>
                                    <td><?= htmlspecialchars($c['name']) ?></td>
                                    <td>
                                        <span class="badge <?= $c['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                                            <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td><?= $linkCounts[$c['id']] ?? 0 ?></td>
                                    <td><?= number_format((int)$c['total_hits']) ?></td>
                                    <td><?= number_format((int)$c['offer_shows']) ?></td>
                                    <td><?= number_format((int)$c['white_shows']) ?></td>
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
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

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
