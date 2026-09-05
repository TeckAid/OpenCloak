<?php
/**
 * Admin Panel - Link Management
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
                $existing = owned_row($db, 'links', $id, $userId) ?: [];
                if ($existing === []) {
                    throw new BadRequestException('Link not found.', 404);
                }
            }

            $parsed = parse_link_input($_POST, $existing, ['source' => 'form']);
            if ($parsed['campaign_id'] !== null && owned_row($db, 'campaigns', $parsed['campaign_id'], $userId) === null) {
                throw new BadRequestException('Selected campaign not found.', 400);
            }
            if ($parsed['domain_id'] !== null && owned_row($db, 'domains', $parsed['domain_id'], $userId) === null) {
                throw new BadRequestException('Selected domain not found.', 400);
            }

            if ($action === 'create') {
                $check = $db->prepare("SELECT id FROM links WHERE slug = ?");
                $check->execute([$parsed['slug']]);
                if ($check->fetch()) {
                    throw new BadRequestException('Slug already exists.', 409);
                }

                $columns = implode(',', array_merge(['user_id', 'slug'], LINK_MUTABLE_COLUMNS));
                $values = array_merge([$userId, $parsed['slug']], array_map(static fn(string $column) => $parsed[$column], LINK_MUTABLE_COLUMNS));
                $db->prepare("INSERT INTO links ({$columns}) VALUES (" . implode(',', array_fill(0, count($values), '?')) . ")")
                   ->execute($values);
                $message = 'Link created successfully!';
            } else {
                $values = array_map(static fn(string $column) => $parsed[$column], LINK_MUTABLE_COLUMNS);
                $set = implode(', ', array_map(static fn(string $column): string => "{$column} = ?", LINK_MUTABLE_COLUMNS));
                $db->prepare("UPDATE links SET {$set}, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")
                   ->execute(array_merge($values, [$id, $userId]));
                $message = 'Link updated successfully!';
            }

            $messageType = 'success';
        } catch (BadRequestException $e) {
            http_response_code($e->getCode() >= 400 ? $e->getCode() : 400);
            $message = $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM links WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
        $message = 'Link deleted.';
        $messageType = 'success';
    }
}

// ---- Editing --------------------------------------------------------------------
$editingLink = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM links WHERE id = ? AND user_id = ?");
    $stmt->execute([(int)$_GET['edit'], $userId]);
    $editingLink = $stmt->fetch() ?: null;
}

// ---- Lists --------------------------------------------------------------------------
$stmt = $db->prepare("
    SELECT l.*, c.name AS campaign_name, d.domain AS domain_name
    FROM links l
    LEFT JOIN campaigns c ON c.id = l.campaign_id
    LEFT JOIN domains d ON d.id = l.domain_id
    WHERE l.user_id = ? ORDER BY l.is_active DESC, l.created_at DESC");
$stmt->execute([$userId]);
$links = $stmt->fetchAll();

// Traffic in the last 24h per link
$todayStats = [];
$stmt = $db->prepare("
    SELECT h.link_id,
           SUM(CASE WHEN h.shown_page = 'offer' THEN 1 ELSE 0 END) AS offers,
           SUM(CASE WHEN h.shown_page = 'white' THEN 1 ELSE 0 END) AS safe
    FROM hit_log h
    JOIN links l ON l.id = h.link_id
    WHERE l.user_id = ? AND h.created_at >= datetime('now', '-1 day')
    GROUP BY h.link_id");
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $todayStats[(int)$row['link_id']] = $row;
}

$stmt = $db->prepare("SELECT id, name FROM campaigns WHERE user_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$userId]);
$campaigns = $stmt->fetchAll();

$stmt = $db->prepare("SELECT id, domain, is_system FROM domains WHERE user_id = ? AND is_active = 1 ORDER BY is_system DESC, domain");
$stmt->execute([$userId]);
$domains = $stmt->fetchAll();

$baseUrl = app_base_url();
$primaryHost = (string) (parse_url($baseUrl, PHP_URL_HOST) ?: '127.0.0.1');

$r = $editingLink ?: ['is_active' => 0, 'allow_empty_referer' => 0, 'block_review_infra' => 0];
$boundCampaign = $editingLink && !empty($editingLink['campaign_id']);

$activeLinks = array_values(array_filter($links, static fn(array $l): bool => (int)$l['is_active'] === 1));
$inactiveLinks = array_values(array_filter($links, static fn(array $l): bool => (int)$l['is_active'] !== 1));

$activeNav = '/admin/links.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Links - Cloaking SaaS</title>
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260905">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <div class="container">
        <div class="detail-header">
            <div>
                <h1 class="page-title">Links</h1>
                <p class="page-intro">Short, cloaked routes to your offers.</p>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" data-open-modal="#link-modal">New link</button>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($links === []): ?>
            <div class="card">
                <div class="list-empty">
                    No links yet. <a href="#" data-open-modal="#link-modal">Create your first</a> — it takes one offer URL.
                </div>
            </div>
        <?php else: ?>

            <div class="section-title">
                <h2>Active</h2>
                <span class="count"><?= count($activeLinks) ?> of <?= count($links) ?></span>
            </div>
            <div class="card">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Link</th><th>URL</th><th>Campaign</th><th>Today</th><th>Offer / safe</th><th>Hits</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($activeLinks === []): ?>
                                <tr><td colspan="7" class="text-center">No active links.</td></tr>
                            <?php else: ?>
                                <?php foreach ($activeLinks as $link): ?>
                                    <?php
                                    $linkDomain = $link['domain_name'] ?: $primaryHost;
                                    $linkUrl = $link['domain_name']
                                        ? 'https://' . $linkDomain . '/' . rawurlencode($link['slug'])
                                        : $baseUrl . '/' . rawurlencode($link['slug']);
                                    $today = $todayStats[(int)$link['id']] ?? ['offers' => 0, 'safe' => 0];
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($link['name'] ?: $link['slug']) ?></td>
                                        <td>
                                            <code class="link-url" data-copy="<?= htmlspecialchars($linkUrl) ?>"><?= htmlspecialchars($linkUrl) ?></code>
                                            <div class="link-sub"><?= htmlspecialchars($linkDomain) ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($link['campaign_name'] ?: '—') ?></td>
                                        <td><?= (int)$today['offers'] + (int)$today['safe'] ?></td>
                                        <td>
                                            <span class="badge badge-success"><?= (int)$today['offers'] ?> offer</span>
                                            <span class="badge badge-warning"><?= (int)$today['safe'] ?> safe</span>
                                        </td>
                                        <td><?= number_format((int)$link['total_hits']) ?></td>
                                        <td>
                                            <a href="/admin/link.php?id=<?= (int)$link['id'] ?>" class="btn btn-sm">Traffic</a>
                                            <a href="?edit=<?= (int)$link['id'] ?>" class="btn btn-sm">Edit</a>
                                            <form method="POST" action="/admin/diagnostics.php" target="_blank" style="display:inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="link_id" value="<?= (int) $link['id'] ?>">
                                                <button type="submit" class="btn btn-sm">Diagnostics</button>
                                            </form>
                                            <form method="POST" action="" style="display:inline" onsubmit="return confirm('Delete this link?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$link['id'] ?>">
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

            <?php if ($inactiveLinks !== []): ?>
                <div class="section-title">
                    <h2>Paused</h2>
                    <span class="count"><?= count($inactiveLinks) ?></span>
                </div>
                <div class="card">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Link</th><th>URL</th><th>Campaign</th><th>Hits</th><th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inactiveLinks as $link): ?>
                                    <?php
                                    $linkDomain = $link['domain_name'] ?: $primaryHost;
                                    $linkUrl = $link['domain_name']
                                        ? 'https://' . $linkDomain . '/' . rawurlencode($link['slug'])
                                        : $baseUrl . '/' . rawurlencode($link['slug']);
                                    ?>
                                    <tr class="row-inactive">
                                        <td><?= htmlspecialchars($link['name'] ?: $link['slug']) ?></td>
                                        <td><code class="link-url" data-copy="<?= htmlspecialchars($linkUrl) ?>"><?= htmlspecialchars($linkUrl) ?></code></td>
                                        <td><?= htmlspecialchars($link['campaign_name'] ?: '—') ?></td>
                                        <td><?= number_format((int)$link['total_hits']) ?></td>
                                        <td>
                                            <a href="/admin/link.php?id=<?= (int)$link['id'] ?>" class="btn btn-sm">Traffic</a>
                                            <a href="?edit=<?= (int)$link['id'] ?>" class="btn btn-sm">Edit</a>
                                            <form method="POST" action="" style="display:inline" onsubmit="return confirm('Delete this link?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$link['id'] ?>">
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
        <?php endif; ?>
    </div>

    <!-- Create / edit wizard -->
    <div class="modal-backdrop" id="link-modal" data-open="<?= $editingLink ? '1' : '0' ?>">
        <div class="modal">
            <div class="modal-header">
                <h2><?= $editingLink ? 'Edit link' : 'New link' ?></h2>
                <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" data-wizard id="link-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $editingLink ? 'update' : 'create' ?>">
                    <?php if ($editingLink): ?>
                        <input type="hidden" name="id" value="<?= (int)$editingLink['id'] ?>">
                    <?php endif; ?>

                    <div class="wizard-indicator">
                        <span class="wizard-dot"></span><span class="wizard-dot"></span><span class="wizard-dot"></span>
                    </div>

                    <!-- Step 1: basics -->
                    <div class="wizard-step" data-step="1">
                        <h3>Where does it live?</h3>
                        <div class="form-group">
                            <label for="name">Name</label>
                            <input type="text" id="name" name="name" value="<?= htmlspecialchars($editingLink['name'] ?? '') ?>" placeholder="TikTok US — nutra">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="slug">Slug</label>
                                <div class="input-group">
                                    <span class="input-prefix">/</span>
                                    <input type="text" id="slug" name="slug"
                                           value="<?= htmlspecialchars($editingLink['slug'] ?? '') ?>"
                                           <?= $editingLink ? 'readonly' : '' ?>
                                           pattern="[a-zA-Z0-9_-]{1,64}" placeholder="tiktok-us">
                                </div>
                                <p class="form-hint">Leave empty to generate one. Your link becomes <code>…/slug</code>.</p>
                            </div>
                            <div class="form-group">
                                <label for="domain_id">Domain</label>
                                <select id="domain_id" name="domain_id">
                                    <option value=""><?= htmlspecialchars($primaryHost) ?> (system)</option>
                                    <?php foreach ($domains as $d): ?>
                                        <option value="<?= (int)$d['id'] ?>" <?= (int)($editingLink['domain_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($d['domain']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="campaign_id">Campaign rules</label>
                            <select id="campaign_id" name="campaign_id" onchange="toggleCampaignFields()">
                                <option value="">None — this link carries its own rules</option>
                                <?php foreach ($campaigns as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= (int)($editingLink['campaign_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-hint">With a campaign bound, rules, offer URL and white page come from the campaign.</p>
                        </div>
                    </div>

                    <!-- Step 2: destination -->
                    <div class="wizard-step" data-step="2">
                        <h3>Where do real visitors go?</h3>
                        <div id="link-local-fields">
                            <div class="form-group">
                                <label for="offer_url">Offer URL</label>
                                <input type="url" id="offer_url" name="offer_url"
                                       value="<?= htmlspecialchars($editingLink['offer_url'] ?? '') ?>"
                                       placeholder="https://example.com/offer">
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Offer pool (A/B — one URL per line)</label>
                                    <textarea name="offer_urls" rows="3" placeholder="https://example.com/a&#10;https://example.com/b"><?= htmlspecialchars($editingLink['offer_urls'] ?? '') ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Rotation</label>
                                    <select name="rotation_mode">
                                        <option value="single" <?= ($editingLink['rotation_mode'] ?? 'single') === 'single' ? 'selected' : '' ?>>Single</option>
                                        <option value="random" <?= ($editingLink['rotation_mode'] ?? '') === 'random' ? 'selected' : '' ?>>Random (A/B)</option>
                                        <option value="sequential" <?= ($editingLink['rotation_mode'] ?? '') === 'sequential' ? 'selected' : '' ?>>Round-robin</option>
                                    </select>
                                    <div class="form-group" style="margin-top:0.75rem">
                                        <label for="redirect_type">Redirect type</label>
                                        <select id="redirect_type" name="redirect_type">
                                            <option value="302" <?= ($editingLink['redirect_type'] ?? '302') === '302' ? 'selected' : '' ?>>302 (temporary)</option>
                                            <option value="301" <?= ($editingLink['redirect_type'] ?? '') === '301' ? 'selected' : '' ?>>301 (permanent)</option>
                                            <option value="303" <?= ($editingLink['redirect_type'] ?? '') === '303' ? 'selected' : '' ?>>303 (see other)</option>
                                            <option value="meta" <?= ($editingLink['redirect_type'] ?? '') === 'meta' ? 'selected' : '' ?>>Meta refresh</option>
                                        </select>
                                    </div>
                                    <div class="form-group" style="margin-top:0.75rem">
                                        <label for="redirect_delay">Delay (seconds)</label>
                                        <input type="number" id="redirect_delay" name="redirect_delay" min="0" max="30"
                                               value="<?= (int)($editingLink['redirect_delay'] ?? 0) ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Country routes (multi-geo, optional)</label>
                                <textarea name="offer_routes" rows="3" placeholder="US=https://example.com/us&#10;*=https://example.com/world"><?= htmlspecialchars($editingLink['offer_routes'] ?? '') ?></textarea>
                                <p class="form-hint">Format: <code>CC=url</code> per line, <code>*</code> = everyone else.</p>
                            </div>

                            <div class="form-group">
                                <label>Safe page HTML (what reviewers see — leave empty for the default)</label>
                                <textarea name="white_page" rows="4" placeholder="Leave empty for default safe page"><?= htmlspecialchars($editingLink['white_page'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: filters -->
                    <div class="wizard-step" data-step="3">
                        <h3>Who gets through?</h3>
                        <div id="link-rule-fields">
                            <?php include __DIR__ . '/../includes/rule_fields.php'; ?>
                        </div>
                        <?php if ($editingLink): ?>
                            <label class="checkbox" style="margin-top:0.4rem">
                                <input type="checkbox" name="is_active" value="1" <?= !empty($r['is_active']) ? 'checked' : '' ?>> Active
                            </label>
                        <?php endif; ?>
                    </div>

                    <div class="modal-footer" style="margin:0 -1.3rem -1.3rem;">
                        <button type="button" class="btn" data-wizard-back>Back</button>
                        <span class="spacer"></span>
                        <button type="button" class="btn" data-wizard-next>Next</button>
                        <button type="submit" class="btn btn-primary" data-wizard-submit><?= $editingLink ? 'Save changes' : 'Create link' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="/assets/js/admin.js?v=20260905"></script>
    <script>
    function toggleCampaignFields() {
        var sel = document.getElementById('campaign_id');
        var local = document.getElementById('link-local-fields');
        var offer = document.getElementById('offer_url');
        if (sel.value) {
            local.style.display = 'none';
            if (offer) offer.required = false;
        } else {
            local.style.display = '';
            if (offer) offer.required = true;
        }
    }
    toggleCampaignFields();
    </script>
</body>
</html>
