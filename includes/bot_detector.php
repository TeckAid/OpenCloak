<?php
/**
 * Bot Detection Engine + Rule Evaluator
 *
 * Detects bots, datacenter IPs, VPNs, Tor exits, headless browsers,
 * in-app clients, OS/version, extended device types, and language —
 * then evaluates a rule set into allow/deny plus human-readable reasons.
 */

require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/security.php';

class BotDetector
{
    private string $userAgent;
    private string $ip;
    private array $headers;
    private array $context;
    private mixed $ipIntelligenceTransport;
    private ?array $detected = null;
    private ?array $validatedParams = null;
    private array $cfHeaders = ['country' => '', 'asn' => ''];
    private bool $cfAsnClassified = false;

    private array $result = [
        'is_bot'          => false,
        'is_datacenter'   => false,
        'is_vpn'          => false,
        'is_tor'          => false,
        'is_headless'     => false,
        'is_curl'         => false,
        'is_review_infra' => false,
        'review_platform' => '',
        'bot_verified'    => false,
        'bot_claim'       => '',
        'country'         => '',
        'language'        => '',
        'client_type'     => '',
        'os_name'         => '',
        'os_version'      => '',
        'device_type'     => 'desktop',
        'browser'         => '',
        'isp'             => '',
        'reasons'         => [],
        'ip_intelligence_status' => 'not_requested',
    ];

    private static array $botPatterns = [
        'bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'adsbot',
        'feedfetcher', 'facebookexternalhit', 'pinterest', 'linkedin',
        'whatsapp', 'telegram', 'discord', 'applebot', 'bingpreview',
        'yandex', 'baidu', 'sogou', 'exabot', 'ia_archiver', 'semrush',
        'ahrefs', 'mj12bot', 'dotbot', 'petalbot', 'bytespider', 'gptbot',
        'ccbot', 'claudebot', 'anthropic', 'scrapy', 'python-requests',
        'java/', 'perl/', 'ruby/', 'go-http-client', 'php/',
        'curl/', 'wget/', 'libwww', 'httpclient', 'okhttp',
    ];

    private static array $headlessPatterns = [
        'headlesschrome', 'phantomjs', 'slimerjs', 'nightmare', 'puppeteer',
        'playwright', 'selenium', 'webdriver', 'chromedriver', 'devtools',
    ];

    private static array $datacenterASNs = [
        'AS14061', 'AS16509', 'AS14618', 'AS46489',
        'AS20940', 'AS13335', 'AS20473', 'AS16276',
        'AS24940', 'AS29551', 'AS54113', 'AS35415', 'AS63949',
        'AS49981', 'AS58061', 'AS51396', 'AS57043', 'AS200019',
    ];

    /**
     * ASNs of advertising platforms' review/verification infrastructure.
     * Traffic from these networks is always platform review traffic.
     */
    private static array $reviewInfraASNs = [
        'AS32934'  => 'facebook',   // Meta
        'AS15169'  => 'google',     // Google
        'AS19527'  => 'google',
        'AS36040'  => 'google',
        'AS396982' => 'google',
        'AS16550'  => 'google',
        'AS8075'   => 'microsoft',  // Bing review
        'AS23468'  => 'microsoft',
        'AS8068'   => 'microsoft',
        'AS714'    => 'apple',      // Apple search review
        'AS6185'   => 'apple',
        'AS396986' => 'bytedance',  // TikTok / ByteDance review
        'AS13414'  => 'twitter',    // X
        'AS53620'  => 'pinterest',
        'AS36646'  => 'yahoo',
    ];

    /**
     * Reverse-DNS validation domains for well-known crawlers.
     * A claimed crawler UA must resolve to one of these domains to be verified.
     */
    private static array $botVerifyDomains = [
        'googlebot'           => ['googlebot.com', 'google.com'],
        'bingbot'             => ['search.msn.com'],
        'facebookexternalhit' => ['fbsv.net', 'tfbnw.net', 'facebook.com'],
        'applebot'            => ['applebot.apple.com'],
        'pinterest'           => ['pinterest.com'],
        'yandex'              => ['yandex.com', 'yandex.ru', 'yandex.net'],
        'bytespider'          => ['bytedance.com', 'tiktok.com'],
        'ahrefs'              => ['ahrefs.com'],
        'semrush'             => ['semrush.com'],
    ];

    /** UA markers for in-app browsers / apps. */
    private static array $clientPatterns = [
        'facebook'  => ['fban', 'fbav', 'fb_iab', 'fbios', 'fbdv', 'instagram'],
        'instagram' => ['instagram'],
        'threads'   => ['threads'],
        'tiktok'    => ['bytedance', 'aweme', 'tiktok', 'douyin', 'musical_ly'],
        'twitter'   => ['twitter', 'tweetbot', 'fxiob', 'twitterapp'],
        'linkedin'  => ['linkedin'],
        'line'      => ['line'],
        'kakaotalk' => ['kakaotalk', 'kakao'],
        'wechat'    => ['micromessenger', 'wechat'],
        'telegram'  => ['telegram'],
        'snapchat'  => ['snapchat', 'snap'],
    ];

    /**
     * @param array $context Optional environment override for API/client-mode
     *        evaluation: accept, language, referer, params, fingerprint, token_present
     */
    public function __construct(?string $ip = null, ?string $userAgent = null, array $context = [], ?callable $ipIntelligenceTransport = null)
    {
        $this->context = $context;
        $this->ipIntelligenceTransport = $ipIntelligenceTransport;
        $this->ip = $ip ?: app_client_ip();
        $this->userAgent = $userAgent ?: $this->envStr('HTTP_USER_AGENT', '');
        $this->headers = $this->collectHeaders();

        // Cloudflare geo headers: caller-supplied context (client-deployment
        // mode) takes precedence; otherwise only honored when TRUST_CLOUDFLARE
        // is enabled and the request passed through Cloudflare.
        $country = trim((string) ($context['cf_ipcountry'] ?? ''));
        $asn = trim((string) ($context['cf_ipasn'] ?? ''));
        // Normalize a bare numeric ASN (request.cf.asn) to the ASxxxxx form
        if ($asn !== '' && preg_match('/^AS\d{1,10}$/i', $asn) !== 1) {
            if (preg_match('/^\d{1,10}$/', $asn) === 1) {
                $asn = 'AS' . $asn;
            } else {
                $asn = '';
            }
        }
        $asn = strtoupper($asn);
        if ($country === '' && $asn === '' && function_exists('app_cloudflare_headers')) {
            $serverCf = app_cloudflare_headers();
            $country = $serverCf['country'];
            $asn = $serverCf['asn'];
        }
        $this->cfHeaders = ['country' => $country, 'asn' => $asn];
    }

    /**
     * Run detection once. The result is memoized; subsequent calls are free.
     *
     * $needs controls which (potentially slow) network checks run.
     */
    public function detect(array $needs = ['datacenter' => true, 'tor' => true]): array
    {
        if ($this->detected !== null) {
            return $this->detected;
        }

        $this->checkUserAgent();
        $this->checkHeadless();
        $this->checkCurl();
        $this->checkBehavior();
        $this->parseClientInfo();

        // Country-only need: Cloudflare geo headers can satisfy this without
        // any adapter call. Falls back to the adapter when absent.
        if (!empty($needs['geo']) && empty($needs['datacenter'])) {
            $this->applyCloudflareHeaders();
            if ($this->result['country'] === '' && !$this->isPrivateIP($this->ip)) {
                $this->checkDatacenter(false);
            }
        }
        if (!empty($needs['datacenter']) && !$this->isPrivateIP($this->ip)) {
            $this->checkDatacenter(!empty($needs['vpn']));
        }
        if (!empty($needs['tor']) && !$this->isPrivateIP($this->ip)) {
            $this->checkTor();
        }
        if (!empty($needs['dns']) && !$this->isPrivateIP($this->ip)) {
            $this->checkBotVerification();
        }

        return $this->detected = $this->result;
    }

    public function getResult(): ?array
    {
        return $this->detected;
    }

    /**
     * Evaluate a rule set (link or campaign row) against this visitor.
     *
     * @param array $rules Effective rule set (see effective_rules())
     * @param array $fingerprint Optional fingerprint payload (screen info etc.)
     * @param bool  $tokenPresent Whether the visitor token (cookie) was already present
     * @return array{allowed: bool, reasons: string[]}
     */
    public function evaluate(array $rules, array $fingerprint = [], bool $tokenPresent = false): array
    {
        $needs = [
            'datacenter' => !empty($rules['block_datacenters']) || !empty($rules['block_review_infra'])
                || !empty($rules['block_vpn']),
            'vpn' => !empty($rules['block_vpn']),
            'geo' => !empty($rules['allowed_countries']) || !empty($rules['blocked_countries']),
            'tor' => !empty($rules['block_tor']),
            'dns' => !empty($rules['block_bots']) || !empty($rules['block_review_infra']),
        ];
        // Fast mode: skip all network lookups for maximum speed
        if (!empty($rules['fast_mode'])) {
            $needs = ['datacenter' => false, 'vpn' => false, 'geo' => false, 'tor' => false, 'dns' => false];
        }
        $result = $this->detect($needs);
        $reasons = [];

        $deny = function (string $reason) use (&$reasons): void {
            $reasons[] = $reason;
        };

        // IP allowlist: matched IPs always pass, overriding every other rule
        // (used by operators to preview the offer from their own devices).
        if (!empty($rules['ip_allowlist'])) {
            foreach (preg_split('/[,\s]+/', (string) $rules['ip_allowlist']) ?: [] as $entry) {
                if ($entry !== '' && app_ip_matches_cidr($this->ip, $entry)) {
                    return ['allowed' => true, 'reasons' => []];
                }
            }
        }

        // IP blocklist: matched IPs are always denied
        if (!empty($rules['ip_blocklist'])) {
            foreach (preg_split('/[,\s]+/', (string) $rules['ip_blocklist']) ?: [] as $entry) {
                if ($entry !== '' && app_ip_matches_cidr($this->ip, $entry)) {
                    $deny('ip_blocked');
                }
            }
        }

        // IPv6 blocking: ad-platform reviewers often probe over IPv6
        if (!empty($rules['block_ipv6'])
            && filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $deny('ipv6_blocked');
        }

        // Bot / network
        if (!empty($rules['block_bots']) && $result['is_bot'])             $deny('bot_detected');
        if (!empty($rules['block_datacenters']) && $result['is_datacenter']) $deny('datacenter_ip');
        if (!empty($rules['block_review_infra']) && $result['is_review_infra']) $deny('review_infrastructure');
        if (!empty($rules['block_vpn']) && $result['is_vpn'])              $deny('vpn_or_proxy');
        if (!empty($rules['block_tor']) && $result['is_tor'])              $deny('tor_exit_node');
        if (!empty($rules['block_headless']) && $result['is_headless'])    $deny('headless_browser');
        if (!empty($rules['block_curl']) && $result['is_curl'])            $deny('http_client');
        if ((!empty($needs['datacenter']) || !empty($needs['geo']))
            && ($result['ip_intelligence_status'] ?? '') === 'unavailable'
            && (!defined('IP_INTELLIGENCE_FAILURE_MODE') || strtolower((string) IP_INTELLIGENCE_FAILURE_MODE) !== 'open')) {
            $deny('ip_intelligence_unavailable');
        }

        // Country. When allow_geo_override is on, the utm_allow_geo parameter
        // replaces the detected country (reference-script behavior).
        $country = strtoupper((string)($result['country'] ?? ''));
        if (!empty($rules['allow_geo_override'])) {
            $override = $this->queryParam('utm_allow_geo');
            if ($override !== null && preg_match('#^[a-zA-Z]{2}(-|$)#', $override)) {
                $country = strtoupper(substr($override, 0, 2));
            }
        }
        if (!empty($rules['allowed_countries'])) {
            $allowed = array_map('strtoupper', array_map('trim', explode(',', $rules['allowed_countries'])));
            if (!in_array($country, $allowed, true)) $deny('country_not_allowed');
        }
        if (!empty($rules['blocked_countries'])) {
            $blocked = array_map('strtoupper', array_map('trim', explode(',', $rules['blocked_countries'])));
            if (in_array($country, $blocked, true))  $deny('country_blocked');
        }

        // Client (in-app browser)
        $client = strtolower($result['client_type']);
        if (!empty($rules['allowed_clients'])) {
            $allowed = array_map('strtolower', array_map('trim', explode(',', $rules['allowed_clients'])));
            if ($client === '' || !in_array($client, $allowed, true))      $deny('client_not_allowed');
        }
        if (!empty($rules['blocked_clients'])) {
            $blocked = array_map('strtolower', array_map('trim', explode(',', $rules['blocked_clients'])));
            if ($client !== '' && in_array($client, $blocked, true))       $deny('client_blocked');
        }

        // Device
        $device = strtolower($result['device_type']);
        if (!empty($rules['allowed_devices'])) {
            $allowed = array_map('strtolower', array_map('trim', explode(',', $rules['allowed_devices'])));
            if (!in_array($device, $allowed, true))                         $deny('device_not_allowed');
        }
        if (!empty($rules['blocked_devices'])) {
            $blocked = array_map('strtolower', array_map('trim', explode(',', $rules['blocked_devices'])));
            if (in_array($device, $blocked, true))                          $deny('device_blocked');
        }

        // OS
        $os = strtolower($result['os_name']);
        if (!empty($rules['allowed_os'])) {
            $allowed = array_map('strtolower', array_map('trim', explode(',', $rules['allowed_os'])));
            if ($os === '' || !in_array($os, $allowed, true))              $deny('os_not_allowed');
        }
        if (!empty($rules['blocked_os'])) {
            $blocked = array_map('strtolower', array_map('trim', explode(',', $rules['blocked_os'])));
            if ($os !== '' && in_array($os, $blocked, true))               $deny('os_blocked');
        }
        if (!empty($rules['os_min_versions'])) {
            $minVersions = parse_os_min_versions((string)$rules['os_min_versions']);
            if ($os !== '' && isset($minVersions[$os]) && !version_at_least($result['os_version'], $minVersions[$os])) {
                $deny('os_version_too_low');
            }
        }

        // Browser
        $browser = strtolower((string)($result['browser'] ?? ''));
        if (!empty($rules['allowed_browsers'])) {
            $allowed = array_map('strtolower', array_map('trim', explode(',', (string)$rules['allowed_browsers'])));
            if ($browser === '' || !in_array($browser, $allowed, true))      $deny('browser_not_allowed');
        }
        if (!empty($rules['blocked_browsers'])) {
            $blocked = array_map('strtolower', array_map('trim', explode(',', (string)$rules['blocked_browsers'])));
            if ($browser !== '' && in_array($browser, $blocked, true))       $deny('browser_blocked');
        }

        // Language
        $lang = strtolower($result['language']);
        if (!empty($rules['allowed_languages'])) {
            $allowed = array_map('strtolower', array_map('trim', explode(',', $rules['allowed_languages'])));
            if ($lang === '' || !in_array($lang, $allowed, true))          $deny('language_not_allowed');
        }
        if (!empty($rules['blocked_languages'])) {
            $blocked = array_map('strtolower', array_map('trim', explode(',', $rules['blocked_languages'])));
            if ($lang !== '' && in_array($lang, $blocked, true))           $deny('language_blocked');
        }

        // Referrer (wildcard-aware, matched against full URL and host).
        // In-app clients (Meta, TikTok, etc.) often send no Referer — exempt
        // them from the referer_missing denial.
        $referer = $this->envStr('referer', $this->envStr('HTTP_REFERER', ''));
        $refererHost = (string)(parse_url($referer, PHP_URL_HOST) ?: '');
        $isInApp = in_array($client, IN_APP_CLIENTS, true);
        $refMatches = function (string $csvList) use ($referer, $refererHost): bool {
            return wildcard_match_list($csvList, $referer)
                || wildcard_match_list($csvList, $refererHost);
        };
        if (empty($rules['allow_empty_referer']) && $referer === '' && !$isInApp) {
            $deny('referer_missing');
        }
        if (!empty($rules['allowed_referrers']) && !$refMatches((string)$rules['allowed_referrers'])) {
            $deny('referer_not_allowed');
        }
        if (!empty($rules['blocked_referrers']) && $refMatches((string)$rules['blocked_referrers'])) {
            $deny('referer_blocked');
        }

        // URL parameters
        if (!empty($rules['required_url_params'])) {
            foreach (explode(',', (string)$rules['required_url_params']) as $spec) {
                $spec = trim($spec);
                if ($spec === '') continue;
                [$name, $valuePattern] = array_pad(explode('=', $spec, 2), 2, '*');
                $value = $this->queryParam($name);
                if ($value === null) {
                    $deny('param_missing:' . $name);
                } elseif ($valuePattern !== '*' && !wildcard_match_list($valuePattern, (string)$value)) {
                    $deny('param_value_mismatch:' . $name);
                }
            }
        }
        // Blocked URL parameters (reference-script block_utm)
        if (!empty($rules['blocked_url_params'])) {
            foreach (explode(',', (string)$rules['blocked_url_params']) as $spec) {
                $spec = trim($spec);
                if ($spec === '') continue;
                [$name, $valuePattern] = array_pad(explode('=', $spec, 2), 2, '*');
                $value = $this->queryParam($name);
                if ($value !== null && ($valuePattern === '*' || wildcard_match_list($valuePattern, (string)$value))) {
                    $deny('param_blocked:' . $name);
                }
            }
        }
        // Keyword-anywhere requirement (reference-script allow_utm_opt)
        if (!empty($rules['required_url_keywords'])) {
            $qs = $this->queryString();
            $hit = false;
            foreach (explode(',', (string)$rules['required_url_keywords']) as $kw) {
                $kw = trim($kw);
                if ($kw !== '' && stripos($qs, $kw) !== false) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                $deny('keyword_missing');
            }
        }

        // Screen resolution / fingerprint
        $tier = null;
        if (!empty($fingerprint)) {
            $w = (float)($fingerprint['w'] ?? 0);
            $h = (float)($fingerprint['h'] ?? 0);
            $dpr = (float)($fingerprint['dpr'] ?? 1);
            $touch = !empty($fingerprint['touch']);
            $tier = resolution_tier($w, $h, $dpr, $touch);
        }
        if (!empty($rules['require_screen_info']) && $tier === null) {
            $deny('screen_info_missing');
        }
        if (!empty($rules['allowed_resolutions']) && !wildcard_match_list((string)$rules['allowed_resolutions'], $tier ?? 'unknown')) {
            $deny('resolution_not_allowed');
        }
        if (!empty($rules['blocked_resolutions']) && wildcard_match_list((string)$rules['blocked_resolutions'], $tier ?? 'unknown')) {
            $deny('resolution_blocked');
        }

        // Single-visit limit (visitor token)
        if (!empty($rules['single_visit_only']) && $tokenPresent) {
            $deny('already_visited');
        }

        return ['allowed' => empty($reasons), 'reasons' => $reasons];
    }

    /**
     * Backward-compatible convenience wrapper.
     */
    public function shouldShowOffer(array $rules, array $fingerprint = [], bool $tokenPresent = false): bool
    {
        return $this->evaluate($rules, $fingerprint, $tokenPresent)['allowed'];
    }

    // ---- UA parsing --------------------------------------------------------------

    private function parseClientInfo(): void
    {
        $ua = $this->userAgent;

        // Client type
        $this->result['client_type'] = '';
        $low = strtolower($ua);
        foreach (self::$clientPatterns as $client => $markers) {
            foreach ($markers as $marker) {
                if (strpos($low, $marker) !== false) {
                    $this->result['client_type'] = $client;
                    break 2;
                }
            }
        }

        // OS + version
        $osName = '';
        $osVersion = '';
        if (preg_match('/windows nt ([0-9.]+)/i', $ua, $m)) {
            $osName = 'Windows';
            $osVersion = $m[1];
        } elseif (preg_match('/android ([0-9.]+)/i', $ua, $m)) {
            $osName = 'Android';
            $osVersion = $m[1];
        } elseif (preg_match('/iphone os ([0-9_]+)/i', $ua, $m) || preg_match('/\bcpu os ([0-9_]+)/i', $ua, $m)) {
            $osName = 'iOS';
            $osVersion = str_replace('_', '.', $m[1]);
        } elseif (preg_match('/ipad; cpu os ([0-9_]+)/i', $ua, $m)) {
            $osName = 'iOS';
            $osVersion = str_replace('_', '.', $m[1]);
        } elseif (preg_match('/mac os x ([0-9_.]+)/i', $ua, $m)) {
            $osName = 'macOS';
            $osVersion = str_replace('_', '.', $m[1]);
        } elseif (preg_match('/cros ([0-9.]+)/i', $ua, $m) || strpos($low, 'crkey') !== false) {
            $osName = 'Chrome OS';
            $osVersion = $m[1] ?? '';
        } elseif (preg_match('/\blinux\b/i', $ua)) {
            $osName = 'Linux';
        } elseif (preg_match('/tizen/i', $ua)) {
            $osName = 'Tizen';
        }
        $this->result['os_name'] = $osName;
        $this->result['os_version'] = $osVersion;

        // Device type (extended)
        $this->result['device_type'] = $this->detectDeviceType($ua);
        $this->result['browser'] = self::detectBrowser($ua);
        $this->result['language'] = strtolower(substr($this->envStr('language', $this->envStr('HTTP_ACCEPT_LANGUAGE', '')), 0, 2));
    }

    /**
     * Best-effort browser family from the user agent.
     */
    public static function detectBrowser(string $ua): string
    {
        $low = strtolower($ua);
        if (strpos($low, 'edg/') !== false || strpos($low, 'edga/') !== false || strpos($low, 'edgios/') !== false) {
            return 'edge';
        }
        if (strpos($low, 'opr/') !== false || strpos($low, 'opera') !== false || strpos($low, 'opt/') !== false) {
            return 'opera';
        }
        if (strpos($low, 'samsungbrowser') !== false) {
            return 'samsung';
        }
        if (strpos($low, 'firefox') !== false || strpos($low, 'fxios') !== false) {
            return 'firefox';
        }
        if (strpos($low, 'crios') !== false || strpos($low, 'chrome') !== false || strpos($low, 'crmo') !== false) {
            return 'chrome';
        }
        if (strpos($low, 'safari') !== false) {
            return 'safari';
        }
        if (strpos($low, 'ucbrowser') !== false || strpos($low, 'ucweb') !== false) {
            return 'uc';
        }
        if (strpos($low, 'brave') !== false) {
            return 'brave';
        }
        return '';
    }

    private function detectDeviceType(string $ua): string
    {
        $low = strtolower($ua);
        if (preg_match('/(smart-tv|smarttv|apple ?tv|android ?tv|hbbtv|crkey|googletv|netcast|viera|webos ?tv)/i', $ua)) {
            return 'smarttv';
        }
        if (preg_match('/(playstation|nintendo|wii ?u|switch|xbox)/i', $ua)) {
            return 'console';
        }
        if (preg_match('/\bwatch\b|wearable/i', $ua)) {
            return 'wearable';
        }
        if (preg_match('/(android|iphone|ipod|windows phone|blackberry|opera mini|mobile)/i', $ua)) {
            return preg_match('/(ipad|tablet)/i', $ua) ? 'tablet' : 'mobile';
        }
        return 'desktop';
    }

    public function getDeviceType(): string
    {
        return $this->result['device_type'] ?? $this->detectDeviceType($this->userAgent);
    }

    // ---- UA / header checks ---------------------------------------------------------

    private function checkUserAgent(): void
    {
        $ua = strtolower($this->userAgent);
        if ($ua === '') {
            $this->result['is_bot'] = true;
            $this->result['reasons'][] = 'empty_user_agent';
            return;
        }
        foreach (self::$botPatterns as $pattern) {
            if (strpos($ua, $pattern) !== false) {
                $this->result['is_bot'] = true;
                $this->result['reasons'][] = 'bot_pattern:' . $pattern;
                return;
            }
        }
    }

    private function checkHeadless(): void
    {
        $ua = strtolower($this->userAgent);
        foreach (self::$headlessPatterns as $pattern) {
            if (strpos($ua, $pattern) !== false) {
                $this->result['is_headless'] = true;
                $this->result['reasons'][] = 'headless:' . $pattern;
                return;
            }
        }
    }

    private function checkCurl(): void
    {
        $ua = strtolower($this->userAgent);
        foreach (['curl/', 'wget/', 'python-requests', 'go-http-client', 'libwww',
                  'java/', 'perl/', 'ruby/', 'php/', 'httpclient', 'okhttp'] as $pattern) {
            if (strpos($ua, $pattern) !== false) {
                $this->result['is_curl'] = true;
                $this->result['is_bot'] = true;
                $this->result['reasons'][] = 'curl_request:' . $pattern;
                return;
            }
        }
    }

    private function checkBehavior(): void
    {
        $accept = $this->envStr('accept', $this->envStr('HTTP_ACCEPT', ''));
        $uaHasBrowser = stripos($this->userAgent, 'chrome') !== false
            || stripos($this->userAgent, 'firefox') !== false;

        if (($accept === '' || stripos($accept, 'text/html') === false) && $uaHasBrowser) {
            $this->result['is_bot'] = true;
            $this->result['reasons'][] = 'missing_accept_header';
        }

        if ($this->envStr('language', $this->envStr('HTTP_ACCEPT_LANGUAGE', '')) === '' && $this->userAgent !== '') {
            if (preg_match('/(chrome|firefox|safari|edge)/i', $this->userAgent)) {
                $this->result['reasons'][] = 'missing_accept_language';
            }
        }
    }

    // ---- Network checks -----------------------------------------------------------------

    private function checkDatacenter(bool $needProxyFlags = false): void
    {
        // Cloudflare edge headers may satisfy country and/or ASN without an
        // adapter round trip. Proxy/hosting flags still require the adapter.
        $this->applyCloudflareHeaders();

        $needAdapter = $needProxyFlags
            || $this->result['country'] === ''
            || !$this->cfAsnClassified;
        if (!$needAdapter) {
            if ($this->result['ip_intelligence_status'] === 'not_requested') {
                $this->result['ip_intelligence_status'] = 'ok';
            }
            return;
        }

        $fetcher = fn (string $ip): ?array => app_fetch_ip_intelligence($ip, $this->ipIntelligenceTransport);
        $data = $this->ipIntelligenceTransport !== null
            ? $fetcher($this->ip)
            : $this->cachedLookup('ip_intelligence', $this->ip, 86400, $fetcher);

        if ($data === null
            || !is_array($data)
            || ($data['ip'] ?? null) !== $this->ip
            || preg_match('/^AS\d{1,10}$/', (string) ($data['asn'] ?? '')) !== 1
            || preg_match('/^[A-Z]{2}$/', (string) ($data['country_code'] ?? '')) !== 1
            || !is_bool($data['is_proxy'] ?? null)
            || !is_bool($data['is_hosting'] ?? null)) {
            $this->result['ip_intelligence_status'] = 'unavailable';
            return;
        }
        $this->result['ip_intelligence_status'] = 'ok';

        if (!$this->cfAsnClassified) {
            $this->classifyAsn((string) ($data['asn'] ?? ''));
        }
        if ($data['is_hosting'] === true) {
            $this->result['is_datacenter'] = true;
            $this->result['reasons'][] = 'hosting_provider';
        }
        if ($data['is_proxy'] === true) {
            $this->result['is_vpn'] = true;
            $this->result['reasons'][] = 'proxy_detected';
        }
        if ($this->result['country'] === '') {
            $this->result['country'] = (string) ($data['country_code'] ?? '');
        }
        if ($this->result['isp'] === '' && isset($data['isp'])) {
            $this->result['isp'] = (string) $data['isp'];
        }
    }

    /**
     * Apply validated Cloudflare geo headers (CF-IPCountry, X-Client-ASN /
     * CF-IPASN). Caller-supplied context values or TRUST_CLOUDFLARE-gated
     * server headers are read once in the constructor.
     */
    private function applyCloudflareHeaders(): void
    {
        if ($this->result['country'] === '' && $this->cfHeaders['country'] !== '') {
            $this->result['country'] = $this->cfHeaders['country'];
        }
        if (!$this->cfAsnClassified && $this->cfHeaders['asn'] !== '') {
            $this->classifyAsn($this->cfHeaders['asn']);
            $this->cfAsnClassified = true;
        }
    }

    /**
     * Classify an ASN against the platform review-infra and datacenter lists.
     */
    private function classifyAsn(string $asnKey): void
    {
        if (isset(self::$reviewInfraASNs[$asnKey])) {
            $this->result['is_review_infra'] = true;
            $this->result['review_platform'] = self::$reviewInfraASNs[$asnKey];
            $this->result['reasons'][] = 'review_infra_asn:' . $asnKey;
        }
        if (in_array($asnKey, self::$datacenterASNs, true)) {
            $this->result['is_datacenter'] = true;
            $this->result['reasons'][] = 'datacenter_asn:' . $asnKey;
        }
    }

    private function checkTor(): void
    {
        if (!defined('ENABLE_TOR_CHECK') || !ENABLE_TOR_CHECK) {
            return;
        }
        $isTor = $this->cachedLookup('tor', $this->ip, 21600, function (string $ip): ?bool {
            $rev = implode('.', array_reverse(explode('.', $ip)));
            $host = $rev . '.8.8.8.8.80.ip-port.exitlist.torproject.org';
            $resolved = @gethostbyname($host);
            if ($resolved === $host || !filter_var($resolved, FILTER_VALIDATE_IP)) {
                return null;
            }
            return $resolved === '127.0.0.2';
        });
        if ($isTor === true) {
            $this->result['is_tor'] = true;
            $this->result['reasons'][] = 'tor_exit_node';
        }
    }

    /**
     * Reverse-DNS verification for well-known crawler user agents.
     * A claimed Googlebot/facebookexternalhit/… must resolve to the
     * platform's crawler domains; otherwise it is an impersonator.
     */
    private function checkBotVerification(): void
    {
        $ua = strtolower($this->userAgent);
        $claim = '';
        foreach (self::$botVerifyDomains as $botName => $domains) {
            if (strpos($ua, $botName) !== false) {
                $claim = $botName;
                break;
            }
        }
        if ($claim === '') {
            return;
        }
        $this->result['bot_claim'] = $claim;

        $hostname = $this->cachedLookup('rdns', $this->ip, 604800, function (string $ip): ?string {
            $resolved = @gethostbyaddr($ip);
            if ($resolved === $ip || $resolved === '') {
                return null; // no PTR record
            }
            return strtolower($resolved);
        });

        if ($hostname !== null) {
            foreach (self::$botVerifyDomains[$claim] as $domain) {
                if ($hostname === $domain || str_ends_with($hostname, '.' . $domain)) {
                    $this->result['bot_verified'] = true;
                    $this->result['reasons'][] = 'verified_crawler:' . $claim;
                    return;
                }
            }
        }
        $this->result['reasons'][] = 'unverified_crawler_claim:' . $claim;
    }

    // ---- Caching ------------------------------------------------------------------------------

    private function cachedLookup(string $prefix, string $ip, int $ttl, callable $fetcher): mixed
    {
        $cacheFile = sys_get_temp_dir() . '/cloak_' . $prefix . '_' . md5($ip);
        if (is_readable($cacheFile) && (time() - (int)@filemtime($cacheFile)) < $ttl) {
            $raw = @file_get_contents($cacheFile);
            if ($raw !== false) {
                return json_decode($raw, true);
            }
        }
        $value = $fetcher($ip);
        if ($value !== null) {
            @file_put_contents($cacheFile, json_encode($value), LOCK_EX);
        }
        return $value;
    }

    // ---- Environment ----------------------------------------------------------------------------

    /**
     * Get a value from the injected context, or a server/global fallback.
     */
    private function envStr(string $key, string $fallback): string
    {
        if (array_key_exists($key, $this->context)) {
            $v = $this->context[$key];
            return is_string($v) ? $v : $fallback;
        }
        if (strpos($key, 'HTTP_') === 0 || $key === 'REMOTE_ADDR') {
            return (string)($_SERVER[$key] ?? $fallback);
        }
        return $fallback;
    }

    /**
     * Query-string param from context (params array) or $_GET.
     */
    private function queryParam(string $name): ?string
    {
        if (isset($this->context['params']) && is_array($this->context['params'])) {
            return app_array_get_scalar($this->validatedParams(), $name, 4096, "query parameter {$name}");
        }
        return app_query_scalar($name);
    }

    /**
     * The full raw query string (context params or $_SERVER).
     */
    private function queryString(): string
    {
        if (isset($this->context['params']) && is_array($this->context['params'])) {
            return http_build_query($this->validatedParams());
        }
        return (string)($_SERVER['QUERY_STRING'] ?? '');
    }

    /**
     * Resolve the real client IP. Forwarding headers are only honored when
     * the immediate peer (REMOTE_ADDR) is an explicitly trusted proxy.
     */
    private function getClientIP(): string
    {
        $remote = $this->envStr('REMOTE_ADDR', '127.0.0.1');
        $trusted = defined('TRUSTED_PROXIES') && is_array(TRUSTED_PROXIES) ? TRUSTED_PROXIES : [];

        if (!in_array($remote, $trusted, true)) {
            return $remote;
        }

        $xff = $this->envStr('HTTP_X_FORWARDED_FOR', '');
        if ($xff !== '') {
            $ips = array_map('trim', explode(',', $xff));
            for ($i = count($ips) - 1; $i >= 0; $i--) {
                if ($ips[$i] !== '' && !in_array($ips[$i], $trusted, true)
                    && filter_var($ips[$i], FILTER_VALIDATE_IP)) {
                    return $ips[$i];
                }
            }
        }

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $header) {
            $ip = trim($this->envStr($header, ''));
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return $remote;
    }

    private function isPrivateIP(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function collectHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headers[$key] = $value;
            }
        }
        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function validatedParams(): array
    {
        if ($this->validatedParams !== null) {
            return $this->validatedParams;
        }

        return $this->validatedParams = app_validate_scalar_map(
            $this->context['params'],
            4096,
            'query parameter'
        );
    }
}
