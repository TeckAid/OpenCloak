<?php
/**
 * Admin Panel - Link traffic detail
 */

require_once __DIR__ . '/../includes/bootstrap.php';
boot_app(true);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rules.php';

require_login();

$db = getDB();
$userId = current_user_id();

$linkId = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("
    SELECT l.*, c.name AS campaign_name, c.is_active AS campaign_active, d.domain AS domain_name
    FROM links l
    LEFT JOIN campaigns c ON c.id = l.campaign_id
    LEFT JOIN domains d ON d.id = l.domain_id
    WHERE l.id = ? AND l.user_id = ?");
$stmt->execute([$linkId, $userId]);
$link = $stmt->fetch();

if (!$link) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;text-align:center;padding:4rem">'
       . '<h1>404</h1><p>Link not found. <a href="/admin/links.php">Back to links</a></p></body></html>';
    exit;
}

// ---- Filters -------------------------------------------------------------------
$days = (int)($_GET['days'] ?? 7);            // 1, 7, 30, 0 = all
$days = in_array($days, [1, 7, 30, 0], true) ? $days : 7;
$verdict = (string)($_GET['verdict'] ?? '');
$verdict = in_array($verdict, ['offer', 'white'], true) ? $verdict : '';
$source = trim((string)($_GET['source'] ?? ''));
$reason = trim((string)($_GET['reason'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$where = ['link_id = ?'];
$params = [$linkId];
if ($days > 0) {
    $where[] = "created_at >= datetime('now', ?)";
    $params[] = "-{$days} days";
}
if ($verdict !== '') {
    $where[] = 'shown_page = ?';
    $params[] = $verdict;
}
if ($source !== '') {
    $where[] = 'source = ?';
    $params[] = $source;
}
if ($reason !== '') {
    $where[] = 'reject_reason LIKE ?';
    $params[] = '%' . $reason . '%';
}
$whereSql = implode(' AND ', $where);

// ---- Period stats ----------------------------------------------------------------
$stmt = $db->prepare("SELECT
    COUNT(*) AS hits,
    SUM(CASE WHEN shown_page = 'offer' THEN 1 ELSE 0 END) AS offers,
    SUM(CASE WHEN shown_page = 'white' THEN 1 ELSE 0 END) AS safe
    FROM hit_log WHERE {$whereSql}");
$stmt->execute($params);
$period = $stmt->fetch();

$stmt = $db->prepare("SELECT COUNT(*) FROM hit_log WHERE {$whereSql}");
$stmt->execute($params);
$totalFiltered = (int)$stmt->fetchColumn();

// ---- Daily chart ------------------------------------------------------------------
$chartWhere = $where;
$chartWhere[] = "created_at >= datetime('now', '-7 days')";
$chartParams = $params;
$stmt = $db->prepare("
    SELECT DATE(created_at) AS day,
           SUM(CASE WHEN shown_page = 'offer' THEN 1 ELSE 0 END) AS offers,
           SUM(CASE WHEN shown_page = 'white' THEN 1 ELSE 0 END) AS safe
    FROM hit_log
    WHERE " . implode(' AND ', $chartWhere) . "
    GROUP BY DATE(created_at) ORDER BY day");
$stmt->execute($chartParams);
$daily = $stmt->fetchAll();

// ---- Top reasons / sources ----------------------------------------------------------
$stmt = $db->prepare("
    SELECT reject_reason, COUNT(*) AS cnt
    FROM hit_log WHERE {$whereSql} AND reject_reason IS NOT NULL AND reject_reason != ''
    GROUP BY reject_reason ORDER BY cnt DESC LIMIT 8");
$stmt->execute($params);
$topReasons = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT source, COUNT(*) AS cnt,
           SUM(CASE WHEN shown_page = 'offer' THEN 1 ELSE 0 END) AS offers
    FROM hit_log WHERE {$whereSql}
    GROUP BY source ORDER BY cnt DESC LIMIT 10");
$stmt->execute($params);
$topSources = $stmt->fetchAll();

// ---- Hits table ----------------------------------------------------------------------
$stmt = $db->prepare("SELECT * FROM hit_log WHERE {$whereSql} ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$perPage, ($page - 1) * $perPage]));
$hits = $stmt->fetchAll();

$baseUrl = app_base_url();
$primaryHost = (string) (parse_url($baseUrl, PHP_URL_HOST) ?: '127.0.0.1');
$linkDomain = $link['domain_name'] ?: $primaryHost;
$linkUrl = $link['domain_name']
    ? 'https://' . $linkDomain . '/' . rawurlencode($link['slug'])
    : $baseUrl . '/' . rawurlencode($link['slug']);

$activeNav = '/admin/links.php';

$filterQuery = static function (array $overrides) use ($days, $verdict, $source, $reason): string {
    $params = [
        'days' => $overrides['days'] ?? $days,
        'verdict' => $overrides['verdict'] ?? $verdict,
        'source' => $overrides['source'] ?? $source,
        'reason' => $overrides['reason'] ?? $reason,
    ];
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== 7);
    if (($overrides['days'] ?? $days) !== 7) {
        $params['days'] = $overrides['days'] ?? $days;
    }
    return http_build_query($params);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($link['name'] ?: $link['slug']) ?> — Traffic - Cloaking SaaS</title>
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260906">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <div class="container">
        <div class="detail-header">
            <div>
                <a class="back" href="/admin/links.php">&larr; All links</a>
                <h1 class="page-title"><?= htmlspecialchars($link['name'] ?: $link['slug']) ?></h1>
                <p class="page-intro">
                    <code class="link-url" data-copy="<?= htmlspecialchars($linkUrl) ?>"><?= htmlspecialchars($linkUrl) ?></code>
                    <?php if ($link['campaign_name']): ?>
                        &nbsp;·&nbsp; <?= htmlspecialchars($link['campaign_name']) ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="detail-actions">
                <a href="/admin/links.php?edit=<?= (int)$link['id'] ?>" class="btn btn-sm">Edit</a>
                <form method="POST" action="/admin/diagnostics.php" target="_blank">
                    <?= csrf_field() ?>
                    <input type="hidden" name="link_id" value="<?= (int) $link['id'] ?>">
                    <button type="submit" class="btn btn-sm">Diagnostics</button>
                </form>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Hits</div>
                <div class="stat-value"><?= number_format((int)$period['hits']) ?></div>
                <div class="stat-sub"><?= $days > 0 ? "last {$days}d" : 'all time' ?></div>
            </div>
            <div class="stat-card stat-success">
                <div class="stat-label">Offer shown</div>
                <div class="stat-value"><?= number_format((int)$period['offers']) ?></div>
                <div class="stat-sub"><?= (int)$period['hits'] > 0 ? round((int)$period['offers'] / (int)$period['hits'] * 100, 1) : 0 ?>% of traffic</div>
            </div>
            <div class="stat-card stat-warning">
                <div class="stat-label">Safe page shown</div>
                <div class="stat-value"><?= number_format((int)$period['safe']) ?></div>
                <div class="stat-sub"><?= (int)$period['hits'] > 0 ? round((int)$period['safe'] / (int)$period['hits'] * 100, 1) : 0 ?>% of traffic</div>
            </div>
        </div>

        <?php if (!empty($daily)): ?>
            <div class="card">
                <div class="card-header"><h2>Last 7 days</h2></div>
                <div class="card-body">
                    <div class="bar-chart">
                        <?php
                        $max = max(array_map(static fn($r) => (int)$r['offers'] + (int)$r['safe'], $daily));
                        foreach ($daily as $row):
                            $total = (int)$row['offers'] + (int)$row['safe'];
                            $offers = (int)$row['offers'];
                            $safe = (int)$row['safe'];
                            $height = $max > 0 ? max(4, (int) round($total / $max * 120)) : 4;
                            $safeHeight = $total > 0 ? (int) round($safe / $total * $height) : 0;
                            $offerHeight = $height - $safeHeight;
                        ?>
                        <div class="bar-col" title="<?= htmlspecialchars($row['day']) ?>: <?= $offers ?> offer / <?= $safe ?> safe">
                            <?php if ($offerHeight > 0): ?><div class="bar-offer" style="height:<?= $offerHeight ?>px"></div><?php endif; ?>
                            <?php if ($safeHeight > 0): ?><div class="bar-blocked" style="height:<?= $safeHeight ?>px"></div><?php endif; ?>
                            <div class="bar-label"><?= date('M j', strtotime($row['day'])) ?></div>
                            <div class="bar-rate"><?= $total ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2>Traffic</h2>
                <div class="segmented">
                    <?php foreach ([1 => '24h', 7 => '7d', 30 => '30d', 0 => 'All'] as $d => $label): ?>
                        <a class="<?= $days === $d ? 'is-current' : '' ?>" href="?id=<?= (int)$linkId ?>&<?= htmlspecialchars($filterQuery(['days' => $d])) ?>"><?= $label ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-body">
                <form method="GET" action="/admin/link.php" class="traffic-filters">
                    <input type="hidden" name="id" value="<?= (int)$linkId ?>">
                    <?php if ($days !== 7): ?><input type="hidden" name="days" value="<?= $days ?>"><?php endif; ?>
                    <div class="form-group">
                        <label>Verdict</label>
                        <select name="verdict" onchange="this.form.submit()">
                            <option value="">All</option>
                            <option value="offer" <?= $verdict === 'offer' ? 'selected' : '' ?>>Offer</option>
                            <option value="white" <?= $verdict === 'white' ? 'selected' : '' ?>>Safe page</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Source</label>
                        <input type="text" name="source" value="<?= htmlspecialchars($source) ?>" placeholder="tiktok, google…">
                    </div>
                    <div class="form-group">
                        <label>Reason</label>
                        <input type="text" name="reason" value="<?= htmlspecialchars($reason) ?>" placeholder="datacenter_ip…">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-sm">Filter</button>
                        <a href="/admin/link.php?id=<?= (int)$linkId ?>" class="btn btn-sm">Reset</a>
                    </div>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Time</th><th>Verdict</th><th>Source</th><th>Country</th>
                            <th>Device</th><th>Browser</th><th>OS</th><th>Client</th><th>Reason</th><th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($hits)): ?>
                            <tr><td colspan="10" class="text-center">No traffic matches these filters.</td></tr>
                        <?php else: ?>
                            <?php foreach ($hits as $hit): ?>
                                <tr>
                                    <td><?= date('M j, H:i', strtotime($hit['created_at'])) ?></td>
                                    <td>
                                        <span class="badge <?= $hit['shown_page'] === 'offer' ? 'badge-success' : 'badge-warning' ?>">
                                            <?= $hit['shown_page'] === 'offer' ? 'Offer' : 'Safe' ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($hit['source'] ?: '—') ?></td>
                                    <td><?= htmlspecialchars($hit['country'] ?: '—') ?></td>
                                    <td><?= htmlspecialchars($hit['device_type'] ?: '—') ?></td>
                                    <td><?= htmlspecialchars($hit['browser'] ?: '—') ?></td>
                                    <td><?= htmlspecialchars(trim(($hit['os_name'] ?? '') . ' ' . ($hit['os_version'] ?? ''))) ?></td>
                                    <td><?= htmlspecialchars($hit['client_type'] ?: 'browser') ?></td>
                                    <td><?= htmlspecialchars($hit['reject_reason'] ?: '—') ?></td>
                                    <td><code><?= htmlspecialchars($hit['ip']) ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalFiltered > $perPage): ?>
                <div class="pagination">
                    <span><?= $totalFiltered ?> visits</span>
                    <span>
                        <?php if ($page > 1): ?>
                            <a class="btn btn-sm" href="?id=<?= (int)$linkId ?>&<?= htmlspecialchars($filterQuery([])) ?>&page=<?= $page - 1 ?>">Newer</a>
                        <?php endif; ?>
                        page <?= $page ?>
                        <?php if ($page * $perPage < $totalFiltered): ?>
                            <a class="btn btn-sm" href="?id=<?= (int)$linkId ?>&<?= htmlspecialchars($filterQuery([])) ?>&page=<?= $page + 1 ?>">Older</a>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <div class="stats-grid" style="grid-template-columns:1fr 1fr">
            <div class="card" style="margin-bottom:0">
                <div class="card-header"><h2>Reasons</h2></div>
                <div class="card-body">
                    <?php if (empty($topReasons)): ?>
                        <p class="text-secondary">No denials in this period.</p>
                    <?php else: ?>
                        <div class="reason-chips">
                            <?php foreach ($topReasons as $r): ?>
                                <a class="badge badge-danger" href="?id=<?= (int)$linkId ?>&<?= htmlspecialchars($filterQuery(['reason' => $r['reject_reason']])) ?>">
                                    <?= htmlspecialchars($r['reject_reason']) ?> (<?= (int)$r['cnt'] ?>)
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card" style="margin-bottom:0">
                <div class="card-header"><h2>Sources</h2></div>
                <div class="card-body">
                    <?php if (empty($topSources)): ?>
                        <p class="text-secondary">No traffic in this period.</p>
                    <?php else: ?>
                        <div class="reason-chips">
                            <?php foreach ($topSources as $s): ?>
                                <a class="badge <?= (int)$s['offers'] > 0 ? 'badge-success' : 'badge-secondary' ?>"
                                   href="?id=<?= (int)$linkId ?>&<?= htmlspecialchars($filterQuery(['source' => $s['source']])) ?>">
                                    <?= htmlspecialchars($s['source']) ?> (<?= (int)$s['cnt'] ?>)
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="/assets/js/admin.js?v=20260906"></script>
</body>
</html>
