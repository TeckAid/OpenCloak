<?php
/**
 * Shared admin form partial: renders the rule inputs for a link/campaign.
 * Expects $r (rule array) in scope. Reuses constants from includes/rules.php.
 */

if (!defined('DEVICE_TYPES')) {
    require_once __DIR__ . '/rules.php';
}
?>
<details open>
    <summary><h3 style="display:inline">Bot & Network Filters</h3></summary>
    <div class="form-row checkboxes">
        <label class="checkbox"><input type="checkbox" name="block_bots" value="1" <?= !empty($r['block_bots']) ? 'checked' : '' ?>> Block Bots</label>
        <label class="checkbox"><input type="checkbox" name="block_datacenters" value="1" <?= !empty($r['block_datacenters']) ? 'checked' : '' ?>> Block Datacenters</label>
        <label class="checkbox"><input type="checkbox" name="block_review_infra" value="1" <?= ($r['block_review_infra'] ?? 1) ? 'checked' : '' ?>> Block Platform Review Infra</label>
        <label class="checkbox"><input type="checkbox" name="block_vpn" value="1" <?= !empty($r['block_vpn']) ? 'checked' : '' ?>> Block VPN</label>
        <label class="checkbox"><input type="checkbox" name="block_tor" value="1" <?= !empty($r['block_tor']) ? 'checked' : '' ?>> Block Tor</label>
        <label class="checkbox"><input type="checkbox" name="block_headless" value="1" <?= !empty($r['block_headless']) ? 'checked' : '' ?>> Block Headless Browsers</label>
        <label class="checkbox"><input type="checkbox" name="block_curl" value="1" <?= !empty($r['block_curl']) ? 'checked' : '' ?>> Block Curl/HTTP Clients</label>
    </div>
    <p class="form-hint">"Block Platform Review Infra" denies traffic from known ad-platform networks
    (Meta AS32934, Google AS15169/36040, ByteDance AS396986, Microsoft, Apple, X, Pinterest, Yahoo).</p>
</details>

<details>
    <summary><h3 style="display:inline">Country Filters</h3></summary>
    <div class="form-row">
        <div class="form-group">
            <label>Allowed Countries (comma-separated: US,GB)</label>
            <input type="text" name="allowed_countries" value="<?= htmlspecialchars($r['allowed_countries'] ?? '') ?>" placeholder="US,CA,GB">
        </div>
        <div class="form-group">
            <label>Blocked Countries</label>
            <input type="text" name="blocked_countries" value="<?= htmlspecialchars($r['blocked_countries'] ?? '') ?>" placeholder="CN,RU">
        </div>
    </div>
</details>

<details>
    <summary><h3 style="display:inline">Client (in-app browser) Rules</h3></summary>
    <p class="form-hint">Values: <?= htmlspecialchars(implode(', ', array_keys(CLIENT_TYPES))) ?>. Empty = any client.</p>
    <div class="form-row">
        <div class="form-group">
            <label>Allowed Clients</label>
            <input type="text" name="allowed_clients" value="<?= htmlspecialchars($r['allowed_clients'] ?? '') ?>" placeholder="facebook,instagram,threads">
        </div>
        <div class="form-group">
            <label>Blocked Clients</label>
            <input type="text" name="blocked_clients" value="<?= htmlspecialchars($r['blocked_clients'] ?? '') ?>" placeholder="wechat">
        </div>
    </div>
</details>

<details>
    <summary><h3 style="display:inline">Device & OS Rules</h3></summary>
    <p class="form-hint">Devices: <?= htmlspecialchars(implode(', ', array_keys(DEVICE_TYPES))) ?>. OS: Windows, macOS, iOS, Android, Linux, Chrome OS.</p>
    <div class="form-row">
        <div class="form-group">
            <label>Allowed Devices</label>
            <input type="text" name="allowed_devices" value="<?= htmlspecialchars($r['allowed_devices'] ?? '') ?>" placeholder="mobile">
        </div>
        <div class="form-group">
            <label>Blocked Devices</label>
            <input type="text" name="blocked_devices" value="<?= htmlspecialchars($r['blocked_devices'] ?? '') ?>" placeholder="smarttv,console">
        </div>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label>Allowed OS</label>
            <input type="text" name="allowed_os" value="<?= htmlspecialchars($r['allowed_os'] ?? '') ?>" placeholder="iOS,Android">
        </div>
        <div class="form-group">
            <label>Blocked OS</label>
            <input type="text" name="blocked_os" value="<?= htmlspecialchars($r['blocked_os'] ?? '') ?>" placeholder="Linux">
        </div>
    </div>
    <div class="form-group">
        <label>Minimum OS Versions</label>
        <input type="text" name="os_min_versions" value="<?= htmlspecialchars($r['os_min_versions'] ?? '') ?>" placeholder="iOS>=14.0,Android>=10,Windows>=10">
    </div>
</details>

<details>
    <summary><h3 style="display:inline">Language Rules</h3></summary>
    <div class="form-row">
        <div class="form-group">
            <label>Allowed Languages (2-letter: en,fr,ja)</label>
            <input type="text" name="allowed_languages" value="<?= htmlspecialchars($r['allowed_languages'] ?? '') ?>" placeholder="en,es">
        </div>
        <div class="form-group">
            <label>Blocked Languages</label>
            <input type="text" name="blocked_languages" value="<?= htmlspecialchars($r['blocked_languages'] ?? '') ?>" placeholder="ru,cn">
        </div>
    </div>
</details>

<details>
    <summary><h3 style="display:inline">Referrer Rules</h3></summary>
    <p class="form-hint">Supports <code>*</code> wildcards: <code>facebook.com,*.facebook.com</code></p>
    <div class="form-row">
        <div class="form-group">
            <label>Allowed Referrers</label>
            <input type="text" name="allowed_referrers" value="<?= htmlspecialchars($r['allowed_referrers'] ?? '') ?>" placeholder="facebook.com,*.facebook.com">
        </div>
        <div class="form-group">
            <label>Blocked Referrers</label>
            <input type="text" name="blocked_referrers" value="<?= htmlspecialchars($r['blocked_referrers'] ?? '') ?>" placeholder="example.com">
        </div>
    </div>
    <label class="checkbox">
        <input type="checkbox" name="allow_empty_referer" value="1" <?= ($r['allow_empty_referer'] ?? 1) ? 'checked' : '' ?>>
        Allow empty referrer
    </label>
</details>

<details>
    <summary><h3 style="display:inline">URL Parameter Rules</h3></summary>
    <div class="form-group">
        <label>Required URL Parameters</label>
        <input type="text" name="required_url_params" value="<?= htmlspecialchars($r['required_url_params'] ?? '') ?>" placeholder="utm_source=*,campaign=fb*,click_id=*">
        <p class="form-hint">Format: <code>name</code> or <code>name=value</code>, comma-separated. Values support <code>*</code>.</p>
    </div>
</details>

<details>
    <summary><h3 style="display:inline">Delivery, Delay & UTM</h3></summary>
    <div class="form-row">
        <div class="form-group">
            <label>Offer Display Method</label>
            <select name="offer_method">
                <option value="redirect" <?= ($r['offer_method'] ?? 'redirect') === 'redirect' ? 'selected' : '' ?>>Redirect (302)</option>
                <option value="iframe" <?= ($r['offer_method'] ?? '') === 'iframe' ? 'selected' : '' ?>>Full-page iframe</option>
            </select>
        </div>
        <div class="form-group">
            <label>Delay Start (block first N unique IPs, 0 = off)</label>
            <input type="number" name="delay_start" min="0" max="100000" value="<?= (int)($r['delay_start'] ?? 0) ?>">
        </div>
    </div>
    <div class="form-row checkboxes">
        <label class="checkbox">
            <input type="checkbox" name="forward_utms" value="1" <?= !empty($r['forward_utms']) ? 'checked' : '' ?>>
            Forward UTM params to offer
        </label>
        <label class="checkbox">
            <input type="checkbox" name="no_cache" value="1" <?= !empty($r['no_cache']) ? 'checked' : '' ?>>
            No-cache headers
        </label>
        <label class="checkbox">
            <input type="checkbox" name="fast_mode" value="1" <?= !empty($r['fast_mode']) ? 'checked' : '' ?>>
            Fast mode (skip IP network checks)
        </label>
        <label class="checkbox">
            <input type="checkbox" name="delay_permanent" value="1" <?= !empty($r['delay_permanent']) ? 'checked' : '' ?>>
            Delay permanent (first N IPs always blocked)
        </label>
        <label class="checkbox">
            <input type="checkbox" name="allow_geo_override" value="1" <?= !empty($r['allow_geo_override']) ? 'checked' : '' ?>>
            Allow utm_allow_geo override
        </label>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label>Blocked URL Parameters</label>
            <input type="text" name="blocked_url_params" value="<?= htmlspecialchars($r['blocked_url_params'] ?? '') ?>" placeholder="utm_city=Paris,utm_source=spy*">
        </div>
        <div class="form-group">
            <label>Required Keywords (any, comma-separated)</label>
            <input type="text" name="required_url_keywords" value="<?= htmlspecialchars($r['required_url_keywords'] ?? '') ?>" placeholder="poker,betting">
            <p class="form-hint">The query string must contain at least one keyword.</p>
        </div>
    </div>
</details>

<details>
    <summary><h3 style="display:inline">Fingerprint & Visit Rules</h3></summary>
    <p class="form-hint">Tiers: <?= htmlspecialchars(implode(', ', array_keys(RESOLUTION_TIERS))) ?>. These rules enable the JS fingerprint interstitial.</p>
    <div class="form-row">
        <div class="form-group">
            <label>Allowed Screen Tiers</label>
            <input type="text" name="allowed_resolutions" value="<?= htmlspecialchars($r['allowed_resolutions'] ?? '') ?>" placeholder="iphone,android_s">
        </div>
        <div class="form-group">
            <label>Blocked Screen Tiers</label>
            <input type="text" name="blocked_resolutions" value="<?= htmlspecialchars($r['blocked_resolutions'] ?? '') ?>" placeholder="pc">
        </div>
    </div>
    <div class="form-row checkboxes">
        <label class="checkbox">
            <input type="checkbox" name="require_screen_info" value="1" <?= !empty($r['require_screen_info']) ? 'checked' : '' ?>>
            Require screen info (deny if missing)
        </label>
        <label class="checkbox">
            <input type="checkbox" name="single_visit_only" value="1" <?= !empty($r['single_visit_only']) ? 'checked' : '' ?>>
            Single visit per device (block repeats)
        </label>
    </div>
</details>
