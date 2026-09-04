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

    if ($action === 'create') {
        $slug = trim($_POST['slug'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $offerUrl = trim($_POST['offer_url'] ?? '');
        $whitePage = $_POST['white_page'] ?? '';
        $redirectType = in_array($_POST['redirect_type'] ?? '302', ['301', '302', 'meta'], true) ? $_POST['redirect_type'] : '302';
        $campaignId = (int)($_POST['campaign_id'] ?? 0) ?: null;
        $domainId = (int)($_POST['domain_id'] ?? 0) ?: null;

        if ($campaignId !== null) {
            $c = $db->prepare("SELECT id FROM campaigns WHERE id = ? AND user_id = ?");
            $c->execute([$campaignId, $userId]);
            if (!$c->fetch()) {
                $message = 'Selected campaign not found.';
                $messageType = 'error';
                $campaignId = null;
            }
        }
        if ($domainId !== null) {
            $d = $db->prepare("SELECT id FROM domains WHERE id = ? AND user_id = ?");
            $d->execute([$domainId, $userId]);
            if (!$d->fetch()) {
                $message = 'Selected domain not found.';
                $messageType = 'error';
                $domainId = null;
            }
        }

        if ($slug === '') $slug = bin2hex(random_bytes(6));

        if (!$message && !is_valid_slug($slug)) {
            $message = 'Slug can only contain letters, numbers, hyphens and underscores (max 64 chars) and cannot be a reserved word.';
            $messageType = 'error';
        } elseif (!$message && $campaignId === null && !is_valid_offer_url($offerUrl)) {
            $message = 'Offer URL must be a valid http(s) URL (or bind a campaign).';
            $messageType = 'error';
        } elseif (!$message) {
            $check = $db->prepare("SELECT id FROM links WHERE slug = ?");
            $check->execute([$slug]);
            if ($check->fetch()) {
                $message = 'Slug already exists.';
                $messageType = 'error';
            } else {
                $flag = fn(string $k): int => isset($_POST[$k]) ? 1 : 0;
                $txt = fn(string $k): string => trim($_POST[$k] ?? '');

                $db->prepare("
                    INSERT INTO links (user_id, slug, name, campaign_id, domain_id, offer_url, white_page, redirect_type, redirect_delay,
                        block_bots, block_datacenters, block_review_infra, block_vpn, block_tor, block_headless, block_curl,
                        allowed_countries, blocked_countries, allowed_clients, blocked_clients,
                        allowed_devices, blocked_devices, allowed_os, blocked_os, os_min_versions,
                        allowed_languages, blocked_languages, allowed_referrers, blocked_referrers, allow_empty_referer,
                        required_url_params, blocked_url_params, required_url_keywords,
                        allowed_resolutions, blocked_resolutions, require_screen_info, single_visit_only,
                        offer_urls, rotation_mode, offer_routes,
                        offer_method, forward_utms, no_cache, fast_mode, delay_start, delay_permanent, allow_geo_override)
                    VALUES (" . implode(',', array_fill(0, 47, '?')) . ")
                ")->execute([
                    $userId, $slug, $name, $campaignId, $domainId,
                    $campaignId === null ? $offerUrl : '',
                    $whitePage, $redirectType, max(0, min(30, (int)($_POST['redirect_delay'] ?? 0))),
                    $flag('block_bots'), $flag('block_datacenters'),
                    $flag('block_review_infra') ? 1 : (isset($_POST['block_review_infra']) ? 0 : 1),
                    $flag('block_vpn'), $flag('block_tor'), $flag('block_headless'), $flag('block_curl'),
                    $txt('allowed_countries'), $txt('blocked_countries'), $txt('allowed_clients'), $txt('blocked_clients'),
                    $txt('allowed_devices'), $txt('blocked_devices'), $txt('allowed_os'), $txt('blocked_os'), $txt('os_min_versions'),
                    $txt('allowed_languages'), $txt('blocked_languages'), $txt('allowed_referrers'), $txt('blocked_referrers'),
                    $flag('allow_empty_referer') ? 1 : (isset($_POST['allow_empty_referer']) ? 0 : 1),
                    $txt('required_url_params'), $txt('blocked_url_params'), $txt('required_url_keywords'),
                    $txt('allowed_resolutions'), $txt('blocked_resolutions'),
                    $flag('require_screen_info'), $flag('single_visit_only'),
                    $txt('offer_urls'),
                    in_array($txt('rotation_mode'), ['single', 'random', 'sequential'], true) ? $txt('rotation_mode') : 'single',
                    $txt('offer_routes'),
                    in_array($txt('offer_method'), ['redirect', 'iframe'], true) ? $txt('offer_method') : 'redirect',
                    $flag('forward_utms'), $flag('no_cache'), $flag('fast_mode'),
                    max(0, min(100000, (int)($_POST['delay_start'] ?? 0))),
                    $flag('delay_permanent'),
                    $flag('allow_geo_override'),
                ]);
                $message = 'Link created successfully!';
                $messageType = 'success';
            }
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $campaignId = (int)($_POST['campaign_id'] ?? 0) ?: null;
        $domainId = (int)($_POST['domain_id'] ?? 0) ?: null;
        $offerUrl = trim($_POST['offer_url'] ?? '');
        $redirectType = in_array($_POST['redirect_type'] ?? '302', ['301', '302', 'meta'], true) ? $_POST['redirect_type'] : '302';

        if ($campaignId === null && !is_valid_offer_url($offerUrl)) {
            $message = 'Offer URL must be a valid http(s) URL (or bind a campaign).';
            $messageType = 'error';
        } else {
            $flag = fn(string $k): int => isset($_POST[$k]) ? 1 : 0;
            $txt = fn(string $k): string => trim($_POST[$k] ?? '');

            $db->prepare("UPDATE links SET
                name = ?, campaign_id = ?, domain_id = ?, offer_url = ?, white_page = ?, redirect_type = ?, redirect_delay = ?,
                is_active = ?,
                block_bots = ?, block_datacenters = ?, block_review_infra = ?, block_vpn = ?, block_tor = ?, block_headless = ?, block_curl = ?,
                allowed_countries = ?, blocked_countries = ?, allowed_clients = ?, blocked_clients = ?,
                allowed_devices = ?, blocked_devices = ?, allowed_os = ?, blocked_os = ?, os_min_versions = ?,
                allowed_languages = ?, blocked_languages = ?, allowed_referrers = ?, blocked_referrers = ?, allow_empty_referer = ?,
                required_url_params = ?, blocked_url_params = ?, required_url_keywords = ?,
                allowed_resolutions = ?, blocked_resolutions = ?,
                require_screen_info = ?, single_visit_only = ?,
                offer_urls = ?, rotation_mode = ?, offer_routes = ?,
                offer_method = ?, forward_utms = ?, no_cache = ?, fast_mode = ?, delay_start = ?, delay_permanent = ?, allow_geo_override = ?,
                updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND user_id = ?")->execute([
                trim($_POST['name'] ?? ''),
                $campaignId, $domainId,
                $campaignId === null ? $offerUrl : '',
                $_POST['white_page'] ?? '',
                $redirectType, max(0, min(30, (int)($_POST['redirect_delay'] ?? 0))),
                $flag('is_active'),
                $flag('block_bots'), $flag('block_datacenters'),
                $flag('block_review_infra') ? 1 : (isset($_POST['block_review_infra']) ? 0 : 1),
                $flag('block_vpn'), $flag('block_tor'), $flag('block_headless'), $flag('block_curl'),
                $txt('allowed_countries'), $txt('blocked_countries'), $txt('allowed_clients'), $txt('blocked_clients'),
                $txt('allowed_devices'), $txt('blocked_devices'), $txt('allowed_os'), $txt('blocked_os'), $txt('os_min_versions'),
                $txt('allowed_languages'), $txt('blocked_languages'), $txt('allowed_referrers'), $txt('blocked_referrers'),
                $flag('allow_empty_referer') ? 1 : (isset($_POST['allow_empty_referer']) ? 0 : 1),
                $txt('required_url_params'), $txt('blocked_url_params'), $txt('required_url_keywords'),
                $txt('allowed_resolutions'), $txt('blocked_resolutions'),
                $flag('require_screen_info'), $flag('single_visit_only'),
                $txt('offer_urls'),
                in_array($txt('rotation_mode'), ['single', 'random', 'sequential'], true) ? $txt('rotation_mode') : 'single',
                $txt('offer_routes'),
                in_array($txt('offer_method'), ['redirect', 'iframe'], true) ? $txt('offer_method') : 'redirect',
                $flag('forward_utms'), $flag('no_cache'), $flag('fast_mode'),
                max(0, min(100000, (int)($_POST['delay_start'] ?? 0))),
                $flag('delay_permanent'),
                $flag('allow_geo_override'),
                $id, $userId,
            ]);
            $message = 'Link updated successfully!';
            $messageType = 'success';
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
    WHERE l.user_id = ? ORDER BY l.created_at DESC");
$stmt->execute([$userId]);
$links = $stmt->fetchAll();

$stmt = $db->prepare("SELECT id, name FROM campaigns WHERE user_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$userId]);
$campaigns = $stmt->fetchAll();

$stmt = $db->prepare("SELECT id, domain, is_system FROM domains WHERE user_id = ? AND is_active = 1 ORDER BY is_system DESC, domain");
$stmt->execute([$userId]);
$domains = $stmt->fetchAll();

$baseUrl = app_base_url();
$primaryHost = (string)(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
$debugToken = defined('DEBUG_TOKEN') ? DEBUG_TOKEN : '';

$r = $editingLink ?: ['allow_empty_referer' => 1];
$boundCampaign = $editingLink && !empty($editingLink['campaign_id']);

$activeNav = '/admin/links.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Links - Cloaking SaaS</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2><?= $editingLink ? 'Edit Link' : 'Create New Link' ?></h2>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $editingLink ? 'update' : 'create' ?>">
                    <?php if ($editingLink): ?>
                        <input type="hidden" name="id" value="<?= (int)$editingLink['id'] ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="slug">Slug (leave empty to auto-generate)</label>
                            <input type="text" id="slug" name="slug"
                                   value="<?= htmlspecialchars($editingLink['slug'] ?? '') ?>"
                                   <?= $editingLink ? 'readonly' : '' ?>
                                   pattern="[a-zA-Z0-9_-]{1,64}" placeholder="my-campaign">
                        </div>
                        <div class="form-group">
                            <label for="name">Link Name</label>
                            <input type="text" id="name" name="name" value="<?= htmlspecialchars($editingLink['name'] ?? '') ?>" placeholder="My Campaign">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="campaign_id">Campaign (rules + actions) — optional</label>
                            <select id="campaign_id" name="campaign_id" onchange="toggleCampaignFields()">
                                <option value="">— none (use link-local rules) —</option>
                                <?php foreach ($campaigns as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= (int)($editingLink['campaign_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-hint">When bound, rules, offer URL and white page are taken from the campaign.
                                Edit them in <a href="/admin/campaigns.php">Campaigns</a>.</p>
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

                    <div id="link-local-fields">
                        <div class="form-group">
                            <label for="offer_url">Offer URL (where real users go)</label>
                            <input type="url" id="offer_url" name="offer_url"
                                   value="<?= htmlspecialchars($editingLink['offer_url'] ?? '') ?>"
                                   placeholder="https://example.com/offer">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Offer Pool (A/B rotation - one URL per line)</label>
                                <textarea name="offer_urls" rows="3" placeholder="https://example.com/a&#10;https://example.com/b"><?= htmlspecialchars($editingLink['offer_urls'] ?? '') ?></textarea>
                                <p class="form-hint">When set, this pool replaces the single Offer URL.</p>
                            </div>
                            <div class="form-group">
                                <label>Rotation Mode</label>
                                <select name="rotation_mode">
                                    <option value="single" <?= ($editingLink['rotation_mode'] ?? 'single') === 'single' ? 'selected' : '' ?>>Single offer</option>
                                    <option value="random" <?= ($editingLink['rotation_mode'] ?? '') === 'random' ? 'selected' : '' ?>>Random (A/B split)</option>
                                    <option value="sequential" <?= ($editingLink['rotation_mode'] ?? '') === 'sequential' ? 'selected' : '' ?>>Sequential (round-robin)</option>
                                </select>
                                <div class="form-group" style="margin-top:0.75rem">
                                    <label>Country Offer Routes (multi-geo)</label>
                                    <textarea name="offer_routes" rows="4" placeholder="US=https://example.com/us&#10;GB=https://example.com/uk&#10;*=https://example.com/world"><?= htmlspecialchars($editingLink['offer_routes'] ?? '') ?></textarea>
                                    <p class="form-hint">Format: <code>CC=url</code> per line, <code>*</code> = fallback.</p>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="redirect_type">Redirect Type</label>
                                <select id="redirect_type" name="redirect_type">
                                    <option value="302" <?= ($editingLink['redirect_type'] ?? '302') === '302' ? 'selected' : '' ?>>302 (Temporary)</option>
                                    <option value="301" <?= ($editingLink['redirect_type'] ?? '') === '301' ? 'selected' : '' ?>>301 (Permanent)</option>
                                    <option value="meta" <?= ($editingLink['redirect_type'] ?? '') === 'meta' ? 'selected' : '' ?>>Meta Refresh</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="redirect_delay">Redirect Delay (seconds, 0 = instant)</label>
                                <input type="number" id="redirect_delay" name="redirect_delay" min="0" max="30"
                                       value="<?= (int)($editingLink['redirect_delay'] ?? 0) ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="white_page">White Page HTML (shown to bots) - leave empty for default</label>
                            <textarea id="white_page" name="white_page" rows="6"
                                      placeholder="Leave empty for default safe page"><?= htmlspecialchars($editingLink['white_page'] ?? '') ?></textarea>
                        </div>

                        <?php include __DIR__ . '/../includes/rule_fields.php'; ?>
                    </div>

                    <?php if ($editingLink): ?>
                        <label class="checkbox" style="margin-top:1rem">
                            <input type="checkbox" name="is_active" value="1" <?= $editingLink['is_active'] ? 'checked' : '' ?>> Active
                        </label>
                    <?php endif; ?>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><?= $editingLink ? 'Update Link' : 'Create Link' ?></button>
                        <?php if ($editingLink): ?>
                            <a href="/admin/links.php" class="btn">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Your Links</h2></div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th><th>URL</th><th>Domain</th><th>Campaign</th><th>Status</th>
                            <th>Hits</th><th>Offer</th><th>White</th><th>Created</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($links)): ?>
                            <tr><td colspan="10" class="text-center">No links yet. Create one above.</td></tr>
                        <?php else: ?>
                            <?php foreach ($links as $link): ?>
                                <?php
                                $linkDomain = $link['domain_name'] ?: $primaryHost;
                                $linkUrl = (app_is_https() ? 'https' : 'http') . '://' . $linkDomain . '/' . rawurlencode($link['slug']);
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($link['name'] ?: '-') ?></td>
                                    <td>
                                        <code class="link-url" title="Click to copy" data-copy="<?= htmlspecialchars($linkUrl) ?>"><?= htmlspecialchars($linkUrl) ?></code>
                                        <?php if ($debugToken): ?>
                                            <div class="link-sub">
                                                <a href="<?= htmlspecialchars($linkUrl) ?>?_debug=<?= htmlspecialchars($debugToken) ?>" target="_blank" rel="noopener">Test</a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($linkDomain) ?></td>
                                    <td><?= htmlspecialchars($link['campaign_name'] ?: '—') ?></td>
                                    <td>
                                        <span class="badge <?= $link['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                                            <?= $link['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td><?= number_format((int)$link['total_hits']) ?></td>
                                    <td><?= number_format((int)$link['offer_shows']) ?></td>
                                    <td><?= number_format((int)$link['white_shows']) ?></td>
                                    <td><?= date('M j, Y', strtotime($link['created_at'])) ?></td>
                                    <td>
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
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

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

    document.querySelectorAll('.link-url').forEach(function (el) {
        el.addEventListener('click', function () {
            var url = this.getAttribute('data-copy');
            navigator.clipboard.writeText(url).then(function () {
                var original = el.textContent;
                el.textContent = 'Copied!';
                setTimeout(function () { el.textContent = original; }, 1500);
            });
        });
    });
    </script>
</body>
</html>
