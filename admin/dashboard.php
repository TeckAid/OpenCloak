<?php
/**
 * Admin Panel - Dashboard
 */

require_once __DIR__ . '/../includes/bootstrap.php';
boot_app(true);
require_once __DIR__ . '/../includes/auth.php';

require_login();

$db = getDB();
$userId = current_user_id();

// Handle logout (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_post();
    if (($_POST['action'] ?? '') === 'logout') {
        $_SESSION = [];
        $cookieParams = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $cookieParams['path'] ?? '/',
            'domain' => $cookieParams['domain'] ?? '',
            'secure' => (bool) ($cookieParams['secure'] ?? false),
            'httponly' => (bool) ($cookieParams['httponly'] ?? true),
            'samesite' => $cookieParams['samesite'] ?? 'Lax',
        ]);
        session_destroy();
        header('Location: /admin/login.php');
        exit;
    }
}

// ---- Filters -------------------------------------------------------------------
$campaignFilter = (int)($_GET['campaign'] ?? 0);
$reasonFilter = trim((string)($_GET['reason'] ?? ''));
$sourceFilter = trim((string)($_GET['source'] ?? ''));
$ownerSql = 'COALESCE(l.user_id, c.user_id) = ?';

// ---- Stats (prepared statements) ------------------------------------------------
$stmt = $db->prepare("SELECT
    COUNT(*) AS total_links,
    COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_links
    FROM links WHERE user_id = ?");
$stmt->execute([$userId]);
$stats = $stmt->fetch();

$stmt = $db->prepare("
    SELECT COUNT(*) AS total_hits,
           COALESCE(SUM(CASE WHEN h.shown_page = 'offer' THEN 1 ELSE 0 END), 0) AS total_offers,
           COALESCE(SUM(CASE WHEN h.shown_page = 'white' THEN 1 ELSE 0 END), 0) AS total_white
    FROM hit_log h
    LEFT JOIN links l ON h.link_id = l.id
    LEFT JOIN campaigns c ON h.campaign_id = c.id
    WHERE {$ownerSql}");
$stmt->execute([$userId]);
$trafficStats = $stmt->fetch();
$stats['total_hits'] = $trafficStats['total_hits'] ?? 0;
$stats['total_offers'] = $trafficStats['total_offers'] ?? 0;
$stats['total_white'] = $trafficStats['total_white'] ?? 0;

$stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM campaigns WHERE user_id = ?");
$stmt->execute([$userId]);
$campaignCount = (int)$stmt->fetch()['cnt'];

$where = [$ownerSql];
$params = [$userId];
if ($campaignFilter > 0) {
    $where[] = "h.campaign_id = ?";
    $params[] = $campaignFilter;
}
if ($reasonFilter !== '') {
    $where[] = "h.reject_reason LIKE ?";
    $params[] = '%' . $reasonFilter . '%';
}
if ($sourceFilter !== '') {
    $where[] = "h.source = ?";
    $params[] = $sourceFilter;
}
$whereSql = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT h.*, l.slug, l.name AS link_name, c.name AS campaign_name
    FROM hit_log h
    LEFT JOIN links l ON h.link_id = l.id
    LEFT JOIN campaigns c ON h.campaign_id = c.id
    WHERE {$whereSql}
    ORDER BY h.created_at DESC
    LIMIT 50");
$stmt->execute($params);
$recentHits = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT COALESCE(SUM(CASE WHEN shown_page = 'offer' THEN 1 ELSE 0 END), 0) AS offers,
           COALESCE(SUM(CASE WHEN shown_page = 'white' THEN 1 ELSE 0 END), 0) AS white
    FROM hit_log h
    LEFT JOIN links l ON h.link_id = l.id
    LEFT JOIN campaigns c ON h.campaign_id = c.id
    WHERE {$whereSql} AND h.created_at >= datetime('now', '-1 day')");
$stmt->execute($params);
$day = $stmt->fetch();
$todayHits = (int)$day['offers'] + (int)$day['white'];

// Block-rate analysis (last 7 days)
$stmt = $db->prepare("
    SELECT DATE(h.created_at) AS day,
           COUNT(*) AS total,
           COALESCE(SUM(CASE WHEN h.shown_page = 'white' THEN 1 ELSE 0 END), 0) AS blocked
    FROM hit_log h
    LEFT JOIN links l ON h.link_id = l.id
    LEFT JOIN campaigns c ON h.campaign_id = c.id
    WHERE {$ownerSql} AND h.created_at >= datetime('now', '-7 days')
    GROUP BY DATE(h.created_at) ORDER BY day");
$stmt->execute([$userId]);
$trend = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT reject_reason, COUNT(*) AS cnt
    FROM hit_log h
    LEFT JOIN links l ON h.link_id = l.id
    LEFT JOIN campaigns c ON h.campaign_id = c.id
    WHERE {$ownerSql} AND h.reject_reason IS NOT NULL AND h.reject_reason != ''
    GROUP BY reject_reason ORDER BY cnt DESC LIMIT 10");
$stmt->execute([$userId]);
$topReasons = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT source,
           COUNT(*) AS total,
           COALESCE(SUM(CASE WHEN shown_page = 'offer' THEN 1 ELSE 0 END), 0) AS offers,
           COALESCE(SUM(CASE WHEN shown_page = 'white' THEN 1 ELSE 0 END), 0) AS white
    FROM hit_log h
    LEFT JOIN links l ON h.link_id = l.id
    LEFT JOIN campaigns c ON h.campaign_id = c.id
    WHERE {$ownerSql} AND h.created_at >= datetime('now', '-7 days')
    GROUP BY source ORDER BY total DESC LIMIT 15");
$stmt->execute([$userId]);
$topSources = $stmt->fetchAll();

$stmt = $db->prepare("SELECT id, name FROM campaigns WHERE user_id = ? ORDER BY name");
$stmt->execute([$userId]);
$campaigns = $stmt->fetchAll();

$user = current_user();

$activeNav = '/admin/dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Cloaking SaaS</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <div class="container">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Links</div>
                <div class="stat-value"><?= (int)$stats['total_links'] ?></div>
                <div class="stat-sub"><?= (int)$stats['active_links'] ?> active</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Campaigns</div>
                <div class="stat-value"><?= $campaignCount ?></div>
                <div class="stat-sub">rule containers</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Hits</div>
                <div class="stat-value"><?= number_format((int)$stats['total_hits']) ?></div>
                <div class="stat-sub">All time</div>
            </div>
            <div class="stat-card stat-success">
                <div class="stat-label">Offer Shows</div>
                <div class="stat-value"><?= number_format((int)$stats['total_offers']) ?></div>
                <div class="stat-sub"><?= (int)$stats['total_hits'] > 0 ? round((int)$stats['total_offers'] / (int)$stats['total_hits'] * 100, 1) : 0 ?>% of total</div>
            </div>
            <div class="stat-card stat-warning">
                <div class="stat-label">White Page Shows</div>
                <div class="stat-value"><?= number_format((int)$stats['total_white']) ?></div>
                <div class="stat-sub"><?= (int)$stats['total_hits'] > 0 ? round((int)$stats['total_white'] / (int)$stats['total_hits'] * 100, 1) : 0 ?>% of total</div>
            </div>
        </div>

        <?php if (!empty($trend)): ?>
        <div class="card">
            <div class="card-header"><h2>Traffic & Block Rate (last 7 days)</h2></div>
            <div class="card-body">
                <div class="bar-chart">
                    <?php
                    $max = max(array_column($trend, 'total'));
                    foreach ($trend as $row):
                        $hits = (int)$row['total'];
                        $blocked = (int)$row['blocked'];
                        $rate = $hits > 0 ? round($blocked / $hits * 100) : 0;
                        $height = $max > 0 ? max(4, round($hits / $max * 120)) : 4;
                    ?>
                    <div class="bar-col" title="<?= htmlspecialchars($row['day']) ?>: <?= $hits ?> hits, <?= $blocked ?> blocked (<?= $rate ?>%)">
                        <div class="bar-blocked" style="height:<?= $height ?>px"></div>
                        <div class="bar-label"><?= date('M j', strtotime($row['day'])) ?></div>
                        <div class="bar-rate"><?= $rate ?>%</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><h2>Top Reject Reasons</h2></div>
            <div class="card-body">
                <?php if (empty($topReasons)): ?>
                    <p class="text-secondary">No rejections recorded yet.</p>
                <?php else: ?>
                    <div class="reason-chips">
                        <?php foreach ($topReasons as $reason): ?>
                            <a class="badge badge-danger" href="/admin/dashboard.php?reason=<?= urlencode($reason['reject_reason']) ?>">
                                <?= htmlspecialchars($reason['reject_reason']) ?> (<?= (int)$reason['cnt'] ?>)
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Traffic Sources (last 7 days)</h2></div>
            <div class="card-body">
                <?php if (empty($topSources)): ?>
                    <p class="text-secondary">No traffic recorded yet.</p>
                <?php else: ?>
                    <div class="reason-chips">
                        <?php foreach ($topSources as $s): ?>
                            <?php $blockRate = (int)$s['total'] > 0 ? round((int)$s['white'] / (int)$s['total'] * 100) : 0; ?>
                            <a class="badge <?= $blockRate > 60 ? 'badge-danger' : 'badge-success' ?>"
                               href="/admin/dashboard.php?source=<?= urlencode($s['source']) ?>"
                               title="offer: <?= (int)$s['offers'] ?> / white: <?= (int)$s['white'] ?>">
                                <?= htmlspecialchars($s['source']) ?>: <?= (int)$s['total'] ?>
                                (<?= $blockRate ?>% blocked)
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Request Log</h2>
            </div>
            <div class="card-body">
                <form method="GET" action="/admin/dashboard.php" class="form-row filter-row">
                    <div class="form-group">
                        <label>Campaign</label>
                        <select name="campaign" onchange="this.form.submit()">
                            <option value="">All</option>
                            <?php foreach ($campaigns as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= $campaignFilter === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Reject reason</label>
                        <input type="text" name="reason" value="<?= htmlspecialchars($reasonFilter) ?>" placeholder="e.g. datacenter_ip">
                    </div>
                    <div class="form-group">
                        <label>Source</label>
                        <input type="text" name="source" value="<?= htmlspecialchars($sourceFilter) ?>" placeholder="e.g. facebook, tiktok, google">
                    </div>
                    <div class="form-group" style="align-self:flex-end">
                        <button type="submit" class="btn btn-sm">Filter</button>
                        <a href="/admin/dashboard.php" class="btn btn-sm">Reset</a>
                    </div>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Time</th><th>Link</th><th>Campaign</th><th>IP</th><th>Source</th><th>Client</th><th>OS</th>
                            <th>Bot</th><th>VPN</th><th>Shown</th><th>Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentHits)): ?>
                            <tr><td colspan="11" class="text-center">No hits yet</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentHits as $hit): ?>
                                <tr>
                                    <td><?= date('M j, H:i', strtotime($hit['created_at'])) ?></td>
                                    <td><?= htmlspecialchars($hit['link_name'] ?: $hit['slug'] ?: '—') ?></td>
                                    <td><?= htmlspecialchars($hit['campaign_name'] ?: '—') ?></td>
                                    <td><code><?= htmlspecialchars($hit['ip']) ?></code></td>
                                    <td><?= htmlspecialchars($hit['source'] ?: '—') ?></td>
                                    <td><?= htmlspecialchars($hit['client_type'] ?: $hit['device_type']) ?></td>
                                    <td><?= htmlspecialchars(trim($hit['os_name'] . ' ' . $hit['os_version'])) ?></td>
                                    <td>
                                        <span class="badge <?= $hit['is_bot'] ? 'badge-danger' : 'badge-success' ?>">
                                            <?= $hit['is_bot'] ? 'Yes' : 'No' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $hit['is_vpn'] ? 'badge-warning' : 'badge-success' ?>">
                                            <?= $hit['is_vpn'] ? 'Yes' : 'No' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $hit['shown_page'] === 'offer' ? 'badge-success' : 'badge-info' ?>">
                                            <?= ucfirst($hit['shown_page']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($hit['reject_reason'] ?: '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>API Access</h2></div>
            <div class="card-body">
                <p>Your API Key:</p>
                <div class="api-key-box">
                    <code id="api-key"><?= htmlspecialchars($user['api_key']) ?></code>
                    <button onclick="copyApiKey()" class="btn btn-sm">Copy</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    function copyApiKey() {
        var el = document.getElementById('api-key');
        navigator.clipboard.writeText(el.textContent).then(function () {
            alert('API key copied!');
        });
    }
    </script>
</body>
</html>
