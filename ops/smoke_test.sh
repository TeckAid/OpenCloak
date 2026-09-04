#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

HTTPS_BASE_URL=""
HTTP_BASE_URL=""
ADMIN_USERNAME=""
ADMIN_PASSWORD=""
HOSTILE_HOST="evil.example"
RESTART_COMMAND=""
EXPLICIT_CLIENT_INDEX_PATH=""
EXPLICIT_ASSIGNED_CAMPAIGN_ID=""
EXPLICIT_OTHER_CAMPAIGN_ID=""

COOKIE_JAR=""
WORK_DIR=""
CLIENT_ROOT=""
CLIENT_PID=""
CLIENT_PORT=""
DOMAIN_ID=""
DOMAIN_NAME=""
ASSIGNED_CAMPAIGN_ID=""
ASSIGNED_CAMPAIGN_NAME=""
OTHER_CAMPAIGN_ID=""
OTHER_CAMPAIGN_NAME=""
LINK_ID=""
LINK_NAME=""
LINK_SLUG=""
OFFER_URL=""
ADMIN_API_KEY=""
GENERATED_CLIENT_INDEX_PATH=""
SCOPE_CLIENT_INDEX_PATH=""
SCOPE_ASSIGNED_CAMPAIGN_ID=""
SCOPE_OTHER_CAMPAIGN_ID=""
FIXTURE_SUFFIX="$(date -u +%Y%m%dT%H%M%SZ)"

usage() {
  cat <<'EOF'
Usage:
  bash ops/smoke_test.sh \
    --https-base-url=https://app.example.com \
    --admin-username=owner \
    --admin-password='strong password' \
    --restart-command='docker compose restart web edge' \
    [--http-base-url=http://app.example.com] \
    [--hostile-host=evil.example] \
    [--client-index-path=/secure/path/index.php \
      --assigned-campaign-id=123 \
      --other-campaign-id=456]

Required checks:
  - HTTPS redirect
  - Secure admin session cookie
  - Auth + CSRF behavior
  - API 401 without Authorization
  - Sensitive-file denial
  - Unknown-host rejection
  - Direct/generated-client parity
  - Campaign and link write persistence
  - Generated-client scope enforcement
  - Dashboard client-hit visibility
  - Restart persistence

By default the script creates temporary campaigns, a custom domain, a link,
and a generated client through the admin UI, then deletes them on exit.

If you already have a staged generated client export, pass the explicit
`--client-index-path`, `--assigned-campaign-id`, and `--other-campaign-id`
trio together. That artifact is then checked for admin-key leakage and
credential scoping without printing the credential or API key.
EOF
}

log() {
  printf '[smoke] %s\n' "$*"
}

fail() {
  printf '[smoke] FAIL: %s\n' "$*" >&2
  exit 1
}

cleanup() {
  set +e

  if [[ -n "${CLIENT_PID}" ]]; then
    kill "${CLIENT_PID}" >/dev/null 2>&1 || true
    wait "${CLIENT_PID}" >/dev/null 2>&1 || true
  fi

  if [[ -n "${COOKIE_JAR}" && -n "${LINK_ID}" ]]; then
    delete_link >/dev/null 2>&1 || true
  fi

  if [[ -n "${COOKIE_JAR}" && -n "${OTHER_CAMPAIGN_ID}" ]]; then
    delete_campaign_by_id "${OTHER_CAMPAIGN_ID}" >/dev/null 2>&1 || true
  fi

  if [[ -n "${COOKIE_JAR}" && -n "${ASSIGNED_CAMPAIGN_ID}" ]]; then
    delete_campaign_by_id "${ASSIGNED_CAMPAIGN_ID}" >/dev/null 2>&1 || true
  fi

  if [[ -n "${COOKIE_JAR}" && -n "${DOMAIN_ID}" ]]; then
    delete_domain >/dev/null 2>&1 || true
  fi

  if [[ -n "${WORK_DIR}" ]]; then
    rm -rf "${WORK_DIR}"
  fi
}
trap cleanup EXIT

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "Missing required command: $1"
}

php_eval() {
  php -r "$1" "${@:2}"
}

urlencode() {
  php_eval 'echo rawurlencode($argv[1]);' "$1"
}

b64() {
  php_eval 'echo base64_encode($argv[1]);' "$1"
}

build_form_payload() {
  local output_file="$1"
  shift

  : > "${output_file}"
  while (($# >= 2)); do
    if [[ -s "${output_file}" ]]; then
      printf '&' >> "${output_file}"
    fi
    printf '%s=%s' "$(urlencode "$1")" "$(urlencode "$2")" >> "${output_file}"
    shift 2
  done
}

append_form_spec() {
  local file="$1"
  local kind="$2"
  local name="$3"
  local expected="$4"

  printf '%s|%s|%s\n' "${kind}" "${name}" "$(b64 "${expected}")" >> "${file}"
}

request_status() {
  local headers_file="$1"
  local body_file="$2"
  shift 2
  curl -ksS -D "${headers_file}" -o "${body_file}" -w '%{http_code}' "$@"
}

header_value() {
  local headers_file="$1"
  local header_name="$2"

  php_eval '
    $lines = file($argv[1], FILE_IGNORE_NEW_LINES);
    $needle = strtolower($argv[2]) . ":";
    if (!is_array($lines)) {
        exit(1);
    }
    foreach ($lines as $line) {
        if (strtolower(substr($line, 0, strlen($needle))) === $needle) {
            echo trim(substr($line, strlen($needle)));
        }
    }
  ' "${headers_file}" "${header_name}"
}

assert_header_contains() {
  local headers_file="$1"
  local header_name="$2"
  local expected="$3"
  local value

  value="$(header_value "${headers_file}" "${header_name}")"
  [[ "${value}" == *"${expected}"* ]] || fail "Expected ${header_name} to contain ${expected}, got: ${value:-<missing>}"
}

assert_status_in() {
  local actual="$1"
  shift
  local expected
  for expected in "$@"; do
    if [[ "${actual}" == "${expected}" ]]; then
      return 0
    fi
  done
  fail "Unexpected status ${actual}; expected one of: $*"
}

assert_body_contains() {
  local body_file="$1"
  local needle="$2"
  grep -Fq "${needle}" "${body_file}" || fail "Expected body to contain: ${needle}"
}

assert_body_excludes() {
  local body_file="$1"
  local needle="$2"
  if grep -Fq "${needle}" "${body_file}"; then
    fail "Body leaked forbidden content."
  fi
}

wait_for_http() {
  local url="$1"
  local max_attempts="${2:-30}"
  local attempt

  for attempt in $(seq 1 "${max_attempts}"); do
    if curl -sS -o /dev/null "${url}" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done

  fail "Timed out waiting for ${url}"
}

extract_csrf() {
  php_eval '
    $body = file_get_contents($argv[1]);
    if (!is_string($body) || !preg_match("/name=[\"\x27]_csrf[\"\x27]\s+value=[\"\x27]([^\"\x27]+)[\"\x27]/i", $body, $m)) {
        fwrite(STDERR, "Unable to extract CSRF token\n");
        exit(1);
    }
    echo html_entity_decode($m[1], ENT_QUOTES);
  ' "$1"
}

extract_textarea() {
  php_eval '
    $html = file_get_contents($argv[1]);
    $id = $argv[2];
    if (!is_string($html)) {
        fwrite(STDERR, "Unable to read textarea source\n");
        exit(1);
    }
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML($html);
    $xpath = new DOMXPath($doc);
    $nodes = $xpath->query("//textarea[@id=\"" . $id . "\"]");
    if ($nodes === false || $nodes->length < 1) {
        fwrite(STDERR, "Textarea not found: " . $id . "\n");
        exit(1);
    }
    echo html_entity_decode($nodes->item(0)->textContent ?? "", ENT_QUOTES);
  ' "$1" "$2"
}

extract_text_by_id() {
  php_eval '
    $html = file_get_contents($argv[1]);
    $id = $argv[2];
    if (!is_string($html)) {
        fwrite(STDERR, "Unable to read HTML for id lookup\n");
        exit(1);
    }
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML($html);
    $xpath = new DOMXPath($doc);
    $nodes = $xpath->query("//*[@id=\"" . $id . "\"]");
    if ($nodes === false || $nodes->length < 1) {
        fwrite(STDERR, "Element not found: " . $id . "\n");
        exit(1);
    }
    echo trim(html_entity_decode($nodes->item(0)->textContent ?? "", ENT_QUOTES));
  ' "$1" "$2"
}

extract_edit_id_for_name() {
  php_eval '
    $html = file_get_contents($argv[1]);
    $needle = $argv[2];
    if (!is_string($html)) {
        fwrite(STDERR, "Unable to read HTML for edit id lookup\n");
        exit(1);
    }
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML($html);
    $xpath = new DOMXPath($doc);
    foreach ($xpath->query("//tr") as $row) {
        $text = trim(preg_replace("/\s+/", " ", $row->textContent ?? ""));
        if ($text === "" || strpos($text, $needle) === false) {
            continue;
        }
        foreach ($xpath->query(".//a[contains(@href, \"?edit=\")]", $row) as $link) {
            $href = $link->getAttribute("href");
            if (preg_match("/[?&]edit=(\d+)/", $href, $m)) {
                echo $m[1];
                exit(0);
            }
        }
    }
    fwrite(STDERR, "Unable to locate edit id for " . $needle . "\n");
    exit(1);
  ' "$1" "$2"
}

extract_hidden_id_for_row_text() {
  php_eval '
    $html = file_get_contents($argv[1]);
    $needle = $argv[2];
    if (!is_string($html)) {
        fwrite(STDERR, "Unable to read HTML for row id lookup\n");
        exit(1);
    }
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML($html);
    $xpath = new DOMXPath($doc);
    foreach ($xpath->query("//tr") as $row) {
        $text = trim(preg_replace("/\s+/", " ", $row->textContent ?? ""));
        if ($text === "" || strpos($text, $needle) === false) {
            continue;
        }
        foreach ($xpath->query(".//input[@type=\"hidden\" and @name=\"id\"]", $row) as $input) {
            $value = trim($input->getAttribute("value"));
            if ($value !== "") {
                echo $value;
                exit(0);
            }
        }
    }
    fwrite(STDERR, "Unable to locate row id for " . $needle . "\n");
    exit(1);
  ' "$1" "$2"
}

extract_defined_value() {
  php_eval '
    $php = file_get_contents($argv[1]);
    $constant = $argv[2];
    if (!is_string($php)) {
        fwrite(STDERR, "Unable to read PHP source for constant extraction\n");
        exit(1);
    }
    $pattern = "/define\\(\x27" . preg_quote($constant, "/") . "\x27, \x27([^\x27]*)\x27\\);/";
    if (!preg_match($pattern, $php, $m)) {
        fwrite(STDERR, "Unable to locate constant " . $constant . "\n");
        exit(1);
    }
    echo $m[1];
  ' "$1" "$2"
}

assert_form_spec_file() {
  local html_file="$1"
  local spec_file="$2"

  php_eval '
    $html = file_get_contents($argv[1]);
    $specLines = file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_string($html) || !is_array($specLines)) {
        fwrite(STDERR, "Unable to load form expectation inputs\n");
        exit(1);
    }

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML($html);
    $xpath = new DOMXPath($doc);

    $normalize = static function (string $value): string {
        return str_replace("\r\n", "\n", $value);
    };

    foreach ($specLines as $line) {
        [$kind, $name, $expectedB64] = array_pad(explode("|", $line, 3), 3, "");
        $expected = base64_decode($expectedB64, true);
        if ($expected === false) {
            fwrite(STDERR, "Invalid base64 expectation for {$name}\n");
            exit(1);
        }

        $nodes = $xpath->query("//*[@name=\"" . $name . "\"]");
        if ($nodes === false || $nodes->length < 1) {
            fwrite(STDERR, "Form field not found: {$name}\n");
            exit(1);
        }
        $node = $nodes->item(0);
        $actual = "";

        if ($kind === "checkbox") {
            $actual = $node instanceof DOMElement && $node->hasAttribute("checked") ? "1" : "0";
        } elseif ($kind === "textarea") {
            $actual = $normalize(html_entity_decode($node->textContent ?? "", ENT_QUOTES));
        } elseif ($kind === "select") {
            $actual = "";
            if ($node instanceof DOMElement) {
                $options = $node->getElementsByTagName("option");
                foreach ($options as $option) {
                    if ($option->hasAttribute("selected")) {
                        $actual = $option->getAttribute("value");
                        break;
                    }
                }
                if ($actual === "" && $options->length > 0) {
                    $actual = $options->item(0)?->getAttribute("value") ?? "";
                }
            }
        } else {
            $actual = $node instanceof DOMElement ? html_entity_decode($node->getAttribute("value"), ENT_QUOTES) : "";
        }

        if ($normalize($actual) !== $normalize($expected)) {
            fwrite(STDERR, sprintf(
                "Form field mismatch for %s: expected %s, got %s\n",
                $name,
                var_export($expected, true),
                var_export($actual, true)
            ));
            exit(1);
        }
    }
  ' "${html_file}" "${spec_file}" || fail "Form expectations failed for ${html_file}"
}

get_authed_page() {
  local path="$1"
  local headers_file="$2"
  local body_file="$3"
  local status

  status="$(request_status "${headers_file}" "${body_file}" -b "${COOKIE_JAR}" "${HTTPS_BASE_URL}${path}")"
  [[ "${status}" == "200" ]] || fail "Expected ${path} to return 200, got ${status}"
}

post_authed_form() {
  local path="$1"
  local headers_file="$2"
  local body_file="$3"
  local payload_file="$4"
  local status

  status="$(request_status "${headers_file}" "${body_file}" \
    -b "${COOKIE_JAR}" -c "${COOKIE_JAR}" \
    -H 'Content-Type: application/x-www-form-urlencoded' \
    --data-binary "@${payload_file}" \
    "${HTTPS_BASE_URL}${path}")"
  [[ "${status}" == "200" || "${status}" == "302" ]] || fail "Unexpected status ${status} for POST ${path}"
}

login_page() {
  local headers_file="$1"
  local body_file="$2"
  request_status "${headers_file}" "${body_file}" -c "${COOKIE_JAR}" "${HTTPS_BASE_URL}/admin/login.php"
}

login_admin() {
  local page_headers="$1"
  local page_body="$2"
  local post_headers="$3"
  local post_body="$4"
  local csrf
  local payload_file
  local status

  status="$(login_page "${page_headers}" "${page_body}")"
  [[ "${status}" == "200" ]] || fail "Admin login page returned ${status}"

  assert_header_contains "${page_headers}" "Cache-Control" "no-store"
  assert_body_contains "${page_body}" 'name="_csrf"'

  local set_cookie
  set_cookie="$(grep -i '^Set-Cookie: cloaksess=' "${page_headers}" | tail -n 1)"
  [[ -n "${set_cookie}" ]] || fail "Admin login page did not set cloaksess cookie"
  [[ "${set_cookie}" == *"Secure"* ]] || fail "Admin login cookie missing Secure"
  [[ "${set_cookie}" == *"HttpOnly"* ]] || fail "Admin login cookie missing HttpOnly"
  [[ "${set_cookie}" == *"SameSite=Lax"* ]] || fail "Admin login cookie missing SameSite=Lax"

  csrf="$(extract_csrf "${page_body}")"
  payload_file="${WORK_DIR}/login.payload"
  build_form_payload "${payload_file}" \
    "_csrf" "${csrf}" \
    "username" "${ADMIN_USERNAME}" \
    "password" "${ADMIN_PASSWORD}"

  status="$(request_status "${post_headers}" "${post_body}" \
    -b "${COOKIE_JAR}" -c "${COOKIE_JAR}" \
    -H 'Content-Type: application/x-www-form-urlencoded' \
    --data-binary "@${payload_file}" \
    "${HTTPS_BASE_URL}/admin/login.php")"

  [[ "${status}" == "302" ]] || fail "Admin login POST returned ${status}"
  assert_header_contains "${post_headers}" "Location" "/admin/dashboard.php"
}

assert_https_redirect() {
  local headers_file="${WORK_DIR}/http-redirect.headers"
  local body_file="${WORK_DIR}/http-redirect.body"
  local status

  status="$(request_status "${headers_file}" "${body_file}" "${HTTP_BASE_URL}/admin/login.php")"
  assert_status_in "${status}" 301 302 307 308
  assert_header_contains "${headers_file}" "Location" "${HTTPS_BASE_URL}/admin/login.php"
}

assert_missing_csrf_fails() {
  local page_headers="${WORK_DIR}/csrf-page.headers"
  local page_body="${WORK_DIR}/csrf-page.body"
  local post_headers="${WORK_DIR}/csrf-fail.headers"
  local post_body="${WORK_DIR}/csrf-fail.body"
  local payload_file="${WORK_DIR}/csrf-fail.payload"
  local status

  status="$(login_page "${page_headers}" "${page_body}")"
  [[ "${status}" == "200" ]] || fail "CSRF setup login page returned ${status}"

  build_form_payload "${payload_file}" \
    "username" "${ADMIN_USERNAME}" \
    "password" "${ADMIN_PASSWORD}"

  status="$(request_status "${post_headers}" "${post_body}" \
    -b "${COOKIE_JAR}" -c "${COOKIE_JAR}" \
    -H 'Content-Type: application/x-www-form-urlencoded' \
    --data-binary "@${payload_file}" \
    "${HTTPS_BASE_URL}/admin/login.php")"

  [[ "${status}" == "403" ]] || fail "Expected missing CSRF login POST to return 403, got ${status}"
  assert_body_contains "${post_body}" 'Invalid or expired security token.'
}

assert_api_401() {
  local headers_file="${WORK_DIR}/api-401.headers"
  local body_file="${WORK_DIR}/api-401.body"
  local status

  status="$(request_status "${headers_file}" "${body_file}" "${HTTPS_BASE_URL}/api/links")"
  [[ "${status}" == "401" ]] || fail "Expected /api/links without Authorization to return 401, got ${status}"
  assert_header_contains "${headers_file}" "Cache-Control" "no-store"
  assert_body_contains "${body_file}" 'Authorization: Bearer'
}

assert_sensitive_file_denial() {
  local path
  local forbidden
  local headers_file
  local body_file
  local status

  while IFS='|' read -r path forbidden; do
    headers_file="${WORK_DIR}/deny$(echo "${path}" | tr '/.' '__').headers"
    body_file="${WORK_DIR}/deny$(echo "${path}" | tr '/.' '__').body"
    status="$(request_status "${headers_file}" "${body_file}" "${HTTPS_BASE_URL}${path}")"
    assert_status_in "${status}" 403 404
    assert_body_excludes "${body_file}" "${forbidden}"
  done <<'EOF'
/config.php|APP_RUNTIME_DIR
/config.local.php|APP_BASE_URL
/install.php|CLI installer
/dev-router.php|Router for the PHP built-in server
/includes/database.php|Database initialization
/Dockerfile|docker-php-entrypoint
/docker-compose.yml|services:
/README.md|A self-hosted PHP cloaking solution
EOF
}

assert_host_rejection() {
  local headers_file="${WORK_DIR}/host-reject.headers"
  local body_file="${WORK_DIR}/host-reject.body"
  local status

  status="$(request_status "${headers_file}" "${body_file}" -H "Host: ${HOSTILE_HOST}" "${HTTPS_BASE_URL}/admin/login.php")"
  [[ "${status}" == "421" ]] || fail "Expected hostile Host header to return 421, got ${status}"
}

create_domain() {
  local page_headers="${WORK_DIR}/domains-page.headers"
  local page_body="${WORK_DIR}/domains-page.body"
  local post_headers="${WORK_DIR}/domains-add.headers"
  local post_body="${WORK_DIR}/domains-add.body"
  local payload_file="${WORK_DIR}/domains-add.payload"
  local csrf

  DOMAIN_NAME="smoke-${FIXTURE_SUFFIX}.example.com"

  get_authed_page '/admin/domains.php' "${page_headers}" "${page_body}"
  csrf="$(extract_csrf "${page_body}")"
  build_form_payload "${payload_file}" \
    "_csrf" "${csrf}" \
    "action" "add" \
    "domain" "${DOMAIN_NAME}"
  post_authed_form '/admin/domains.php' "${post_headers}" "${post_body}" "${payload_file}"
  assert_body_contains "${post_body}" 'Domain added!'
  DOMAIN_ID="$(extract_hidden_id_for_row_text "${post_body}" "${DOMAIN_NAME}")"
  [[ -n "${DOMAIN_ID}" ]] || fail "Unable to identify added domain id"
}

delete_domain() {
  local page_headers="${WORK_DIR}/domains-delete-page.headers"
  local page_body="${WORK_DIR}/domains-delete-page.body"
  local post_headers="${WORK_DIR}/domains-delete.headers"
  local post_body="${WORK_DIR}/domains-delete.body"
  local payload_file="${WORK_DIR}/domains-delete.payload"
  local csrf

  get_authed_page '/admin/domains.php' "${page_headers}" "${page_body}"
  csrf="$(extract_csrf "${page_body}")"
  build_form_payload "${payload_file}" \
    "_csrf" "${csrf}" \
    "action" "delete" \
    "id" "${DOMAIN_ID}"
  post_authed_form '/admin/domains.php' "${post_headers}" "${post_body}" "${payload_file}"
  DOMAIN_ID=""
}

write_campaign_create_spec() {
  local file="$1"
  : > "${file}"

  append_form_spec "${file}" input name "${ASSIGNED_CAMPAIGN_NAME}"
  append_form_spec "${file}" input offer_url "https://offers.example/assigned-create-${FIXTURE_SUFFIX}"
  append_form_spec "${file}" textarea white_page "<h1>Smoke Assigned Create White</h1>"
  append_form_spec "${file}" select reject_mode "error"
  append_form_spec "${file}" select reject_code "451"
  append_form_spec "${file}" select redirect_type "303"
  append_form_spec "${file}" input redirect_delay "4"
  append_form_spec "${file}" checkbox is_active "0"
  append_form_spec "${file}" checkbox block_bots "0"
  append_form_spec "${file}" checkbox block_datacenters "1"
  append_form_spec "${file}" checkbox block_review_infra "0"
  append_form_spec "${file}" checkbox block_vpn "1"
  append_form_spec "${file}" checkbox block_tor "0"
  append_form_spec "${file}" checkbox block_headless "1"
  append_form_spec "${file}" checkbox block_curl "0"
  append_form_spec "${file}" input allowed_countries "US"
  append_form_spec "${file}" input blocked_countries "CN"
  append_form_spec "${file}" input allowed_clients "facebook"
  append_form_spec "${file}" input blocked_clients "tiktok"
  append_form_spec "${file}" input allowed_devices "desktop"
  append_form_spec "${file}" input blocked_devices "mobile"
  append_form_spec "${file}" input allowed_os "Windows"
  append_form_spec "${file}" input blocked_os "Android"
  append_form_spec "${file}" input os_min_versions "Windows>=11"
  append_form_spec "${file}" input allowed_languages "en"
  append_form_spec "${file}" input blocked_languages "es"
  append_form_spec "${file}" input allowed_referrers "https://google.com/*"
  append_form_spec "${file}" input blocked_referrers "https://evil.example/*"
  append_form_spec "${file}" checkbox allow_empty_referer "1"
  append_form_spec "${file}" input required_url_params "utm_source=*"
  append_form_spec "${file}" input blocked_url_params "utm_bad=*"
  append_form_spec "${file}" input required_url_keywords "cid"
  append_form_spec "${file}" input allowed_resolutions "pc"
  append_form_spec "${file}" input blocked_resolutions "tablet"
  append_form_spec "${file}" checkbox require_screen_info "1"
  append_form_spec "${file}" checkbox single_visit_only "1"
  append_form_spec "${file}" textarea offer_urls $'https://offers.example/assigned-create-a\nhttps://offers.example/assigned-create-b'
  append_form_spec "${file}" select rotation_mode "sequential"
  append_form_spec "${file}" textarea offer_routes $'US=https://offers.example/assigned-create-us\n*=https://offers.example/assigned-create-world'
  append_form_spec "${file}" select offer_method "iframe"
  append_form_spec "${file}" checkbox forward_utms "1"
  append_form_spec "${file}" checkbox no_cache "1"
  append_form_spec "${file}" checkbox fast_mode "0"
  append_form_spec "${file}" input delay_start "11"
  append_form_spec "${file}" checkbox delay_permanent "1"
  append_form_spec "${file}" checkbox allow_geo_override "1"
}

write_campaign_update_spec() {
  local file="$1"
  : > "${file}"

  append_form_spec "${file}" input name "${ASSIGNED_CAMPAIGN_NAME}"
  append_form_spec "${file}" input offer_url "${OFFER_URL}"
  append_form_spec "${file}" textarea white_page "<div>Smoke Assigned Final White</div>"
  append_form_spec "${file}" select reject_mode "white"
  append_form_spec "${file}" select reject_code "403"
  append_form_spec "${file}" select redirect_type "302"
  append_form_spec "${file}" input redirect_delay "0"
  append_form_spec "${file}" checkbox is_active "1"
  append_form_spec "${file}" checkbox block_bots "1"
  append_form_spec "${file}" checkbox block_datacenters "0"
  append_form_spec "${file}" checkbox block_review_infra "1"
  append_form_spec "${file}" checkbox block_vpn "0"
  append_form_spec "${file}" checkbox block_tor "1"
  append_form_spec "${file}" checkbox block_headless "0"
  append_form_spec "${file}" checkbox block_curl "1"
  append_form_spec "${file}" input allowed_countries ""
  append_form_spec "${file}" input blocked_countries "BR"
  append_form_spec "${file}" input allowed_clients ""
  append_form_spec "${file}" input blocked_clients "tiktok"
  append_form_spec "${file}" input allowed_devices ""
  append_form_spec "${file}" input blocked_devices "wearable"
  append_form_spec "${file}" input allowed_os ""
  append_form_spec "${file}" input blocked_os "Symbian"
  append_form_spec "${file}" input os_min_versions "iOS>=14.10"
  append_form_spec "${file}" input allowed_languages ""
  append_form_spec "${file}" input blocked_languages "zz"
  append_form_spec "${file}" input allowed_referrers ""
  append_form_spec "${file}" input blocked_referrers "https://blocked.example/*"
  append_form_spec "${file}" checkbox allow_empty_referer "0"
  append_form_spec "${file}" input required_url_params ""
  append_form_spec "${file}" input blocked_url_params "debug=*"
  append_form_spec "${file}" input required_url_keywords ""
  append_form_spec "${file}" input allowed_resolutions ""
  append_form_spec "${file}" input blocked_resolutions "console"
  append_form_spec "${file}" checkbox require_screen_info "0"
  append_form_spec "${file}" checkbox single_visit_only "0"
  append_form_spec "${file}" textarea offer_urls $'https://offers.example/assigned-final-a\nhttps://offers.example/assigned-final-b'
  append_form_spec "${file}" select rotation_mode "random"
  append_form_spec "${file}" textarea offer_routes $'CA=https://offers.example/assigned-final-ca\n*=https://offers.example/assigned-final-world'
  append_form_spec "${file}" select offer_method "redirect"
  append_form_spec "${file}" checkbox forward_utms "1"
  append_form_spec "${file}" checkbox no_cache "0"
  append_form_spec "${file}" checkbox fast_mode "1"
  append_form_spec "${file}" input delay_start "0"
  append_form_spec "${file}" checkbox delay_permanent "0"
  append_form_spec "${file}" checkbox allow_geo_override "0"
}

create_assigned_campaign() {
  local page_headers="${WORK_DIR}/campaign-page.headers"
  local page_body="${WORK_DIR}/campaign-page.body"
  local post_headers="${WORK_DIR}/campaign-create.headers"
  local post_body="${WORK_DIR}/campaign-create.body"
  local verify_headers="${WORK_DIR}/campaign-create-verify.headers"
  local verify_body="${WORK_DIR}/campaign-create-verify.body"
  local payload_file="${WORK_DIR}/campaign-create.payload"
  local spec_file="${WORK_DIR}/campaign-create.spec"
  local csrf

  ASSIGNED_CAMPAIGN_NAME="Smoke Assigned ${FIXTURE_SUFFIX}"
  OFFER_URL="https://offers.example/assigned-final-${FIXTURE_SUFFIX}"

  get_authed_page '/admin/campaigns.php' "${page_headers}" "${page_body}"
  csrf="$(extract_csrf "${page_body}")"
  build_form_payload "${payload_file}" \
    "_csrf" "${csrf}" \
    "action" "create" \
    "name" "${ASSIGNED_CAMPAIGN_NAME}" \
    "offer_url" "https://offers.example/assigned-create-${FIXTURE_SUFFIX}" \
    "white_page" "<h1>Smoke Assigned Create White</h1>" \
    "reject_mode" "error" \
    "reject_code" "451" \
    "redirect_type" "303" \
    "redirect_delay" "4" \
    "block_datacenters" "1" \
    "block_vpn" "1" \
    "block_headless" "1" \
    "allowed_countries" "US" \
    "blocked_countries" "CN" \
    "allowed_clients" "facebook" \
    "blocked_clients" "tiktok" \
    "allowed_devices" "desktop" \
    "blocked_devices" "mobile" \
    "allowed_os" "Windows" \
    "blocked_os" "Android" \
    "os_min_versions" "Windows>=11" \
    "allowed_languages" "en" \
    "blocked_languages" "es" \
    "allowed_referrers" "https://google.com/*" \
    "blocked_referrers" "https://evil.example/*" \
    "allow_empty_referer" "1" \
    "required_url_params" "utm_source=*" \
    "blocked_url_params" "utm_bad=*" \
    "required_url_keywords" "cid" \
    "allowed_resolutions" "pc" \
    "blocked_resolutions" "tablet" \
    "require_screen_info" "1" \
    "single_visit_only" "1" \
    "offer_urls" $'https://offers.example/assigned-create-a\nhttps://offers.example/assigned-create-b' \
    "rotation_mode" "sequential" \
    "offer_routes" $'US=https://offers.example/assigned-create-us\n*=https://offers.example/assigned-create-world' \
    "offer_method" "iframe" \
    "forward_utms" "1" \
    "no_cache" "1" \
    "delay_start" "11" \
    "delay_permanent" "1" \
    "allow_geo_override" "1"
  post_authed_form '/admin/campaigns.php' "${post_headers}" "${post_body}" "${payload_file}"
  assert_body_contains "${post_body}" 'Campaign created successfully!'

  ASSIGNED_CAMPAIGN_ID="$(extract_edit_id_for_name "${post_body}" "${ASSIGNED_CAMPAIGN_NAME}")"
  [[ -n "${ASSIGNED_CAMPAIGN_ID}" ]] || fail "Unable to identify created assigned campaign id"

  get_authed_page "/admin/campaigns.php?edit=${ASSIGNED_CAMPAIGN_ID}" "${verify_headers}" "${verify_body}"
  write_campaign_create_spec "${spec_file}"
  assert_form_spec_file "${verify_body}" "${spec_file}"
}

update_assigned_campaign() {
  local page_headers="${WORK_DIR}/campaign-edit.headers"
  local page_body="${WORK_DIR}/campaign-edit.body"
  local post_headers="${WORK_DIR}/campaign-update.headers"
  local post_body="${WORK_DIR}/campaign-update.body"
  local verify_headers="${WORK_DIR}/campaign-update-verify.headers"
  local verify_body="${WORK_DIR}/campaign-update-verify.body"
  local payload_file="${WORK_DIR}/campaign-update.payload"
  local spec_file="${WORK_DIR}/campaign-update.spec"
  local csrf

  get_authed_page "/admin/campaigns.php?edit=${ASSIGNED_CAMPAIGN_ID}" "${page_headers}" "${page_body}"
  csrf="$(extract_csrf "${page_body}")"
  build_form_payload "${payload_file}" \
    "_csrf" "${csrf}" \
    "action" "update" \
    "id" "${ASSIGNED_CAMPAIGN_ID}" \
    "name" "${ASSIGNED_CAMPAIGN_NAME}" \
    "offer_url" "${OFFER_URL}" \
    "white_page" "<div>Smoke Assigned Final White</div>" \
    "reject_mode" "white" \
    "reject_code" "403" \
    "redirect_type" "302" \
    "redirect_delay" "0" \
    "is_active" "1" \
    "block_bots" "1" \
    "block_review_infra" "1" \
    "block_tor" "1" \
    "block_curl" "1" \
    "blocked_countries" "BR" \
    "blocked_clients" "tiktok" \
    "blocked_devices" "wearable" \
    "blocked_os" "Symbian" \
    "os_min_versions" "iOS>=14.10" \
    "blocked_languages" "zz" \
    "blocked_referrers" "https://blocked.example/*" \
    "blocked_url_params" "debug=*" \
    "blocked_resolutions" "console" \
    "offer_urls" $'https://offers.example/assigned-final-a\nhttps://offers.example/assigned-final-b' \
    "rotation_mode" "random" \
    "offer_routes" $'CA=https://offers.example/assigned-final-ca\n*=https://offers.example/assigned-final-world' \
    "offer_method" "redirect" \
    "forward_utms" "1" \
    "fast_mode" "1" \
    "delay_start" "0"
  post_authed_form '/admin/campaigns.php' "${post_headers}" "${post_body}" "${payload_file}"
  assert_body_contains "${post_body}" 'Campaign updated successfully!'

  get_authed_page "/admin/campaigns.php?edit=${ASSIGNED_CAMPAIGN_ID}" "${verify_headers}" "${verify_body}"
  write_campaign_update_spec "${spec_file}"
  assert_form_spec_file "${verify_body}" "${spec_file}"
}

create_other_campaign() {
  local page_headers="${WORK_DIR}/other-campaign-page.headers"
  local page_body="${WORK_DIR}/other-campaign-page.body"
  local post_headers="${WORK_DIR}/other-campaign-create.headers"
  local post_body="${WORK_DIR}/other-campaign-create.body"
  local edit_headers="${WORK_DIR}/other-campaign-edit.headers"
  local edit_body="${WORK_DIR}/other-campaign-edit.body"
  local update_headers="${WORK_DIR}/other-campaign-update.headers"
  local update_body="${WORK_DIR}/other-campaign-update.body"
  local payload_file="${WORK_DIR}/other-campaign.payload"
  local update_payload_file="${WORK_DIR}/other-campaign-update.payload"
  local csrf

  OTHER_CAMPAIGN_NAME="Smoke Other ${FIXTURE_SUFFIX}"

  get_authed_page '/admin/campaigns.php' "${page_headers}" "${page_body}"
  csrf="$(extract_csrf "${page_body}")"
  build_form_payload "${payload_file}" \
    "_csrf" "${csrf}" \
    "action" "create" \
    "name" "${OTHER_CAMPAIGN_NAME}" \
    "offer_url" "https://offers.example/other-${FIXTURE_SUFFIX}" \
    "white_page" "<p>Other White</p>" \
    "reject_mode" "white" \
    "reject_code" "403" \
    "redirect_type" "302" \
    "redirect_delay" "0"
  post_authed_form '/admin/campaigns.php' "${post_headers}" "${post_body}" "${payload_file}"
  assert_body_contains "${post_body}" 'Campaign created successfully!'

  OTHER_CAMPAIGN_ID="$(extract_edit_id_for_name "${post_body}" "${OTHER_CAMPAIGN_NAME}")"
  [[ -n "${OTHER_CAMPAIGN_ID}" ]] || fail "Unable to identify created other campaign id"

  get_authed_page "/admin/campaigns.php?edit=${OTHER_CAMPAIGN_ID}" "${edit_headers}" "${edit_body}"
  csrf="$(extract_csrf "${edit_body}")"
  build_form_payload "${update_payload_file}" \
    "_csrf" "${csrf}" \
    "action" "update" \
    "id" "${OTHER_CAMPAIGN_ID}" \
    "name" "${OTHER_CAMPAIGN_NAME}" \
    "offer_url" "https://offers.example/other-${FIXTURE_SUFFIX}" \
    "white_page" "<p>Other White</p>" \
    "reject_mode" "white" \
    "reject_code" "403" \
    "redirect_type" "302" \
    "redirect_delay" "0" \
    "is_active" "1"
  post_authed_form '/admin/campaigns.php' "${update_headers}" "${update_body}" "${update_payload_file}"
}

write_link_create_spec() {
  local file="$1"
  : > "${file}"

  append_form_spec "${file}" input slug "${LINK_SLUG}"
  append_form_spec "${file}" input name "${LINK_NAME}"
  append_form_spec "${file}" select campaign_id ""
  append_form_spec "${file}" select domain_id "${DOMAIN_ID}"
  append_form_spec "${file}" input offer_url "https://offers.example/link-create-${FIXTURE_SUFFIX}"
  append_form_spec "${file}" textarea white_page "<p>Smoke Link Create White</p>"
  append_form_spec "${file}" select redirect_type "303"
  append_form_spec "${file}" input redirect_delay "4"
  append_form_spec "${file}" checkbox is_active "0"
  append_form_spec "${file}" checkbox block_bots "0"
  append_form_spec "${file}" checkbox block_datacenters "1"
  append_form_spec "${file}" checkbox block_review_infra "0"
  append_form_spec "${file}" checkbox block_vpn "1"
  append_form_spec "${file}" checkbox block_tor "1"
  append_form_spec "${file}" checkbox block_headless "0"
  append_form_spec "${file}" checkbox block_curl "1"
  append_form_spec "${file}" input allowed_countries "US"
  append_form_spec "${file}" input blocked_countries "BR"
  append_form_spec "${file}" input allowed_clients "facebook"
  append_form_spec "${file}" input blocked_clients "tiktok"
  append_form_spec "${file}" input allowed_devices "desktop"
  append_form_spec "${file}" input blocked_devices "mobile"
  append_form_spec "${file}" input allowed_os "Windows"
  append_form_spec "${file}" input blocked_os "Android"
  append_form_spec "${file}" input os_min_versions "Windows>=11"
  append_form_spec "${file}" input allowed_languages "en"
  append_form_spec "${file}" input blocked_languages "es"
  append_form_spec "${file}" input allowed_referrers "https://google.com/*"
  append_form_spec "${file}" input blocked_referrers "https://evil.example/*"
  append_form_spec "${file}" checkbox allow_empty_referer "0"
  append_form_spec "${file}" input required_url_params "utm_source=*"
  append_form_spec "${file}" input blocked_url_params "utm_bad=*"
  append_form_spec "${file}" input required_url_keywords "cid"
  append_form_spec "${file}" input allowed_resolutions "pc"
  append_form_spec "${file}" input blocked_resolutions "tablet"
  append_form_spec "${file}" checkbox require_screen_info "0"
  append_form_spec "${file}" checkbox single_visit_only "1"
  append_form_spec "${file}" textarea offer_urls $'https://offers.example/link-create-a\nhttps://offers.example/link-create-b'
  append_form_spec "${file}" select rotation_mode "sequential"
  append_form_spec "${file}" textarea offer_routes $'US=https://offers.example/link-create-us\n*=https://offers.example/link-create-world'
  append_form_spec "${file}" select offer_method "iframe"
  append_form_spec "${file}" checkbox forward_utms "1"
  append_form_spec "${file}" checkbox no_cache "1"
  append_form_spec "${file}" checkbox fast_mode "0"
  append_form_spec "${file}" input delay_start "11"
  append_form_spec "${file}" checkbox delay_permanent "0"
  append_form_spec "${file}" checkbox allow_geo_override "1"
}

write_link_update_spec() {
  local file="$1"
  : > "${file}"

  append_form_spec "${file}" input slug "${LINK_SLUG}"
  append_form_spec "${file}" input name "${LINK_NAME}"
  append_form_spec "${file}" select campaign_id "${ASSIGNED_CAMPAIGN_ID}"
  append_form_spec "${file}" select domain_id ""
  append_form_spec "${file}" input offer_url "https://offers.example/link-update-${FIXTURE_SUFFIX}"
  append_form_spec "${file}" textarea white_page "<div>Smoke Link Final White</div>"
  append_form_spec "${file}" select redirect_type "meta"
  append_form_spec "${file}" input redirect_delay "2"
  append_form_spec "${file}" checkbox is_active "1"
  append_form_spec "${file}" checkbox block_bots "1"
  append_form_spec "${file}" checkbox block_datacenters "0"
  append_form_spec "${file}" checkbox block_review_infra "1"
  append_form_spec "${file}" checkbox block_vpn "0"
  append_form_spec "${file}" checkbox block_tor "0"
  append_form_spec "${file}" checkbox block_headless "1"
  append_form_spec "${file}" checkbox block_curl "0"
  append_form_spec "${file}" input allowed_countries "CA"
  append_form_spec "${file}" input blocked_countries "MX"
  append_form_spec "${file}" input allowed_clients "threads"
  append_form_spec "${file}" input blocked_clients "facebook"
  append_form_spec "${file}" input allowed_devices "tablet"
  append_form_spec "${file}" input blocked_devices "desktop"
  append_form_spec "${file}" input allowed_os "iOS"
  append_form_spec "${file}" input blocked_os "Windows"
  append_form_spec "${file}" input os_min_versions "iOS>=14.10"
  append_form_spec "${file}" input allowed_languages "fr"
  append_form_spec "${file}" input blocked_languages "de"
  append_form_spec "${file}" input allowed_referrers "https://threads.net/*"
  append_form_spec "${file}" input blocked_referrers "https://blocked.example/*"
  append_form_spec "${file}" checkbox allow_empty_referer "1"
  append_form_spec "${file}" input required_url_params "campaign=*"
  append_form_spec "${file}" input blocked_url_params "debug=*"
  append_form_spec "${file}" input required_url_keywords "gclid"
  append_form_spec "${file}" input allowed_resolutions "tablet"
  append_form_spec "${file}" input blocked_resolutions "iphone"
  append_form_spec "${file}" checkbox require_screen_info "1"
  append_form_spec "${file}" checkbox single_visit_only "0"
  append_form_spec "${file}" textarea offer_urls $'https://offers.example/link-update-c\nhttps://offers.example/link-update-d'
  append_form_spec "${file}" select rotation_mode "random"
  append_form_spec "${file}" textarea offer_routes $'CA=https://offers.example/link-update-ca\n*=https://offers.example/link-update-world'
  append_form_spec "${file}" select offer_method "redirect"
  append_form_spec "${file}" checkbox forward_utms "0"
  append_form_spec "${file}" checkbox no_cache "0"
  append_form_spec "${file}" checkbox fast_mode "1"
  append_form_spec "${file}" input delay_start "5"
  append_form_spec "${file}" checkbox delay_permanent "1"
  append_form_spec "${file}" checkbox allow_geo_override "0"
}

create_and_verify_link() {
  local page_headers="${WORK_DIR}/links-page.headers"
  local page_body="${WORK_DIR}/links-page.body"
  local post_headers="${WORK_DIR}/links-create.headers"
  local post_body="${WORK_DIR}/links-create.body"
  local verify_headers="${WORK_DIR}/links-create-verify.headers"
  local verify_body="${WORK_DIR}/links-create-verify.body"
  local payload_file="${WORK_DIR}/links-create.payload"
  local spec_file="${WORK_DIR}/links-create.spec"
  local csrf

  LINK_SLUG="smoke-${FIXTURE_SUFFIX}"
  LINK_NAME="Smoke Link ${FIXTURE_SUFFIX}"

  get_authed_page '/admin/links.php' "${page_headers}" "${page_body}"
  csrf="$(extract_csrf "${page_body}")"
  build_form_payload "${payload_file}" \
    "_csrf" "${csrf}" \
    "action" "create" \
    "slug" "${LINK_SLUG}" \
    "name" "${LINK_NAME}" \
    "domain_id" "${DOMAIN_ID}" \
    "offer_url" "https://offers.example/link-create-${FIXTURE_SUFFIX}" \
    "white_page" "<p>Smoke Link Create White</p>" \
    "redirect_type" "303" \
    "redirect_delay" "4" \
    "block_datacenters" "1" \
    "block_vpn" "1" \
    "block_tor" "1" \
    "block_curl" "1" \
    "allowed_countries" "US" \
    "blocked_countries" "BR" \
    "allowed_clients" "facebook" \
    "blocked_clients" "tiktok" \
    "allowed_devices" "desktop" \
    "blocked_devices" "mobile" \
    "allowed_os" "Windows" \
    "blocked_os" "Android" \
    "os_min_versions" "Windows>=11" \
    "allowed_languages" "en" \
    "blocked_languages" "es" \
    "allowed_referrers" "https://google.com/*" \
    "blocked_referrers" "https://evil.example/*" \
    "required_url_params" "utm_source=*" \
    "blocked_url_params" "utm_bad=*" \
    "required_url_keywords" "cid" \
    "allowed_resolutions" "pc" \
    "blocked_resolutions" "tablet" \
    "single_visit_only" "1" \
    "offer_urls" $'https://offers.example/link-create-a\nhttps://offers.example/link-create-b' \
    "rotation_mode" "sequential" \
    "offer_routes" $'US=https://offers.example/link-create-us\n*=https://offers.example/link-create-world' \
    "offer_method" "iframe" \
    "forward_utms" "1" \
    "no_cache" "1" \
    "delay_start" "11" \
    "allow_geo_override" "1"
  post_authed_form '/admin/links.php' "${post_headers}" "${post_body}" "${payload_file}"

  LINK_ID="$(extract_edit_id_for_name "${post_body}" "${LINK_NAME}")"
  [[ -n "${LINK_ID}" ]] || fail "Unable to identify created link id"

  get_authed_page "/admin/links.php?edit=${LINK_ID}" "${verify_headers}" "${verify_body}"
  write_link_create_spec "${spec_file}"
  assert_form_spec_file "${verify_body}" "${spec_file}"
}

update_and_verify_link() {
  local page_headers="${WORK_DIR}/links-edit-page.headers"
  local page_body="${WORK_DIR}/links-edit-page.body"
  local post_headers="${WORK_DIR}/links-update.headers"
  local post_body="${WORK_DIR}/links-update.body"
  local verify_headers="${WORK_DIR}/links-update-verify.headers"
  local verify_body="${WORK_DIR}/links-update-verify.body"
  local payload_file="${WORK_DIR}/links-update.payload"
  local spec_file="${WORK_DIR}/links-update.spec"
  local csrf

  get_authed_page "/admin/links.php?edit=${LINK_ID}" "${page_headers}" "${page_body}"
  csrf="$(extract_csrf "${page_body}")"
  build_form_payload "${payload_file}" \
    "_csrf" "${csrf}" \
    "action" "update" \
    "id" "${LINK_ID}" \
    "name" "${LINK_NAME}" \
    "campaign_id" "${ASSIGNED_CAMPAIGN_ID}" \
    "offer_url" "https://offers.example/link-update-${FIXTURE_SUFFIX}" \
    "white_page" "<div>Smoke Link Final White</div>" \
    "redirect_type" "meta" \
    "redirect_delay" "2" \
    "is_active" "1" \
    "block_bots" "1" \
    "block_review_infra" "1" \
    "block_headless" "1" \
    "allowed_countries" "CA" \
    "blocked_countries" "MX" \
    "allowed_clients" "threads" \
    "blocked_clients" "facebook" \
    "allowed_devices" "tablet" \
    "blocked_devices" "desktop" \
    "allowed_os" "iOS" \
    "blocked_os" "Windows" \
    "os_min_versions" "iOS>=14.10" \
    "allowed_languages" "fr" \
    "blocked_languages" "de" \
    "allowed_referrers" "https://threads.net/*" \
    "blocked_referrers" "https://blocked.example/*" \
    "allow_empty_referer" "1" \
    "required_url_params" "campaign=*" \
    "blocked_url_params" "debug=*" \
    "required_url_keywords" "gclid" \
    "allowed_resolutions" "tablet" \
    "blocked_resolutions" "iphone" \
    "require_screen_info" "1" \
    "offer_urls" $'https://offers.example/link-update-c\nhttps://offers.example/link-update-d' \
    "rotation_mode" "random" \
    "offer_routes" $'CA=https://offers.example/link-update-ca\n*=https://offers.example/link-update-world' \
    "offer_method" "redirect" \
    "fast_mode" "1" \
    "delay_start" "5" \
    "delay_permanent" "1"
  post_authed_form '/admin/links.php' "${post_headers}" "${post_body}" "${payload_file}"

  get_authed_page "/admin/links.php?edit=${LINK_ID}" "${verify_headers}" "${verify_body}"
  write_link_update_spec "${spec_file}"
  assert_form_spec_file "${verify_body}" "${spec_file}"
}

fetch_admin_api_key() {
  local headers_file="${WORK_DIR}/settings.headers"
  local body_file="${WORK_DIR}/settings.body"

  get_authed_page '/admin/settings.php' "${headers_file}" "${body_file}"
  ADMIN_API_KEY="$(extract_text_by_id "${body_file}" 'api-key')"
  [[ -n "${ADMIN_API_KEY}" ]] || fail "Unable to read administrator API key from settings page"
}

generate_client_artifacts() {
  local headers_file="${WORK_DIR}/client-page.headers"
  local body_file="${WORK_DIR}/client-page.body"
  local client_router_path

  get_authed_page "/admin/client.php?campaign_id=${ASSIGNED_CAMPAIGN_ID}" "${headers_file}" "${body_file}"
  CLIENT_ROOT="$(mktemp -d "${WORK_DIR}/client.XXXXXX")"
  GENERATED_CLIENT_INDEX_PATH="${CLIENT_ROOT}/index.php"
  client_router_path="${CLIENT_ROOT}/router.php"

  extract_textarea "${body_file}" 'client-index' > "${GENERATED_CLIENT_INDEX_PATH}"
  cp "${REPO_DIR}/assets/js/tracker.js" "${CLIENT_ROOT}/tracker.min.js"
  printf '%s\n' '<?php return false;' > "${client_router_path}"
}

configure_scope_artifact_inputs() {
  if [[ -n "${EXPLICIT_CLIENT_INDEX_PATH}" ]]; then
    SCOPE_CLIENT_INDEX_PATH="${EXPLICIT_CLIENT_INDEX_PATH}"
    SCOPE_ASSIGNED_CAMPAIGN_ID="${EXPLICIT_ASSIGNED_CAMPAIGN_ID}"
    SCOPE_OTHER_CAMPAIGN_ID="${EXPLICIT_OTHER_CAMPAIGN_ID}"
  else
    SCOPE_CLIENT_INDEX_PATH="${GENERATED_CLIENT_INDEX_PATH}"
    SCOPE_ASSIGNED_CAMPAIGN_ID="${ASSIGNED_CAMPAIGN_ID}"
    SCOPE_OTHER_CAMPAIGN_ID="${OTHER_CAMPAIGN_ID}"
  fi
}

assert_client_artifact_security_and_scope() {
  local credential
  local assigned_headers="${WORK_DIR}/verify-assigned.headers"
  local assigned_body="${WORK_DIR}/verify-assigned.body"
  local other_headers="${WORK_DIR}/verify-other.headers"
  local other_body="${WORK_DIR}/verify-other.body"
  local management_headers="${WORK_DIR}/verify-management.headers"
  local management_body="${WORK_DIR}/verify-management.body"
  local assigned_status
  local other_status
  local management_status
  local assigned_payload
  local other_payload

  assert_body_excludes "${SCOPE_CLIENT_INDEX_PATH}" "${ADMIN_API_KEY}"
  credential="$(extract_defined_value "${SCOPE_CLIENT_INDEX_PATH}" 'CLOAK_CLIENT_CREDENTIAL')"
  [[ -n "${credential}" ]] || fail "Generated client artifact did not contain a scoped client credential"

  assigned_payload='{"campaign_id":'"${SCOPE_ASSIGNED_CAMPAIGN_ID}"',"ip":"198.51.100.10","user_agent":"Mozilla/5.0","referer":"https://facebook.com/ad","language":"en-US","accept":"text/html","host":"landing.example","params":{"utm_source":"fb"},"fingerprint":[],"visitor_token":"","visitor_cookie":""}'
  other_payload='{"campaign_id":'"${SCOPE_OTHER_CAMPAIGN_ID}"',"ip":"198.51.100.10","user_agent":"Mozilla/5.0","referer":"https://facebook.com/ad","language":"en-US","accept":"text/html","host":"landing.example","params":{"utm_source":"fb"},"fingerprint":[],"visitor_token":"","visitor_cookie":""}'

  assigned_status="$(request_status "${assigned_headers}" "${assigned_body}" \
    -H "Authorization: Bearer ${credential}" \
    -H 'Content-Type: application/json' \
    --data "${assigned_payload}" \
    "${HTTPS_BASE_URL}/api/verify")"
  [[ "${assigned_status}" == "200" ]] || fail "Expected assigned campaign verify to return 200, got ${assigned_status}"
  assert_body_contains "${assigned_body}" '"allowed":true'

  other_status="$(request_status "${other_headers}" "${other_body}" \
    -H "Authorization: Bearer ${credential}" \
    -H 'Content-Type: application/json' \
    --data "${other_payload}" \
    "${HTTPS_BASE_URL}/api/verify")"
  [[ "${other_status}" == "403" ]] || fail "Expected cross-campaign verify to return 403, got ${other_status}"
  assert_body_contains "${other_body}" 'Credential is not allowed to verify this campaign.'

  management_status="$(request_status "${management_headers}" "${management_body}" \
    -H "Authorization: Bearer ${credential}" \
    "${HTTPS_BASE_URL}/api/links")"
  [[ "${management_status}" == "403" ]] || fail "Expected management API call with client credential to return 403, got ${management_status}"
  assert_body_contains "${management_body}" 'Client credentials can only call /api/verify.'
}

start_local_client() {
  CLIENT_PORT="$(php_eval '
    $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
    if (!is_resource($socket)) {
        fwrite(STDERR, "Unable to reserve free port\n");
        exit(1);
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    $parts = explode(":", (string) $name);
    echo (string) array_pop($parts);
  ')"
  php -S "127.0.0.1:${CLIENT_PORT}" -t "${CLIENT_ROOT}" "${CLIENT_ROOT}/router.php" >/dev/null 2>&1 &
  CLIENT_PID="$!"
  wait_for_http "http://127.0.0.1:${CLIENT_PORT}/index.php" 10
}

assert_direct_client_parity() {
  local direct_headers="${WORK_DIR}/direct.headers"
  local direct_body="${WORK_DIR}/direct.body"
  local client_headers="${WORK_DIR}/client.headers"
  local client_body="${WORK_DIR}/client.body"
  local direct_status
  local client_status
  local direct_location
  local client_location

  direct_status="$(request_status "${direct_headers}" "${direct_body}" \
    -H 'User-Agent: Mozilla/5.0' \
    -H 'Accept: text/html' \
    -H 'Accept-Language: en-US' \
    "${HTTPS_BASE_URL}/${LINK_SLUG}")"

  client_status="$(request_status "${client_headers}" "${client_body}" \
    -H 'Host: landing.example' \
    -H 'User-Agent: Mozilla/5.0' \
    -H 'Accept: text/html' \
    -H 'Accept-Language: en-US' \
    "http://127.0.0.1:${CLIENT_PORT}/index.php")"

  [[ "${direct_status}" == "${client_status}" ]] || fail "Direct/client status mismatch: ${direct_status} vs ${client_status}"
  direct_location="$(header_value "${direct_headers}" 'Location')"
  client_location="$(header_value "${client_headers}" 'Location')"
  [[ "${direct_location}" == "${client_location}" ]] || fail "Direct/client Location mismatch"
  [[ "${direct_location}" == "${OFFER_URL}" ]] || fail "Unexpected redirect target."
}

assert_dashboard_client_hits() {
  local credential
  local verify_headers="${WORK_DIR}/dashboard-verify.headers"
  local verify_body="${WORK_DIR}/dashboard-verify.body"
  local dashboard_headers="${WORK_DIR}/dashboard.headers"
  local dashboard_body="${WORK_DIR}/dashboard.body"
  local status
  local payload

  credential="$(extract_defined_value "${GENERATED_CLIENT_INDEX_PATH}" 'CLOAK_CLIENT_CREDENTIAL')"
  payload='{"campaign_id":'"${ASSIGNED_CAMPAIGN_ID}"',"ip":"198.51.100.10","user_agent":"Mozilla/5.0","referer":"https://facebook.com/ad","language":"en-US","accept":"text/html","host":"landing.example","params":{"utm_source":"fb"},"fingerprint":[],"visitor_token":"","visitor_cookie":""}'

  status="$(request_status "${verify_headers}" "${verify_body}" \
    -H "Authorization: Bearer ${credential}" \
    -H 'Content-Type: application/json' \
    --data "${payload}" \
    "${HTTPS_BASE_URL}/api/verify")"
  [[ "${status}" == "200" ]] || fail "Expected /api/verify client hit to return 200, got ${status}"
  assert_body_contains "${verify_body}" '"allowed":true'

  get_authed_page '/admin/dashboard.php' "${dashboard_headers}" "${dashboard_body}"
  assert_body_contains "${dashboard_body}" "${ASSIGNED_CAMPAIGN_NAME}"
  assert_body_contains "${dashboard_body}" 'facebook'
}

assert_restart_persistence() {
  local campaign_spec="${WORK_DIR}/campaign-update.spec"
  local link_spec="${WORK_DIR}/link-update.spec"
  local campaign_headers="${WORK_DIR}/restart-campaign.headers"
  local campaign_body="${WORK_DIR}/restart-campaign.body"
  local link_headers="${WORK_DIR}/restart-link.headers"
  local link_body="${WORK_DIR}/restart-link.body"

  bash -lc "${RESTART_COMMAND}"

  : > "${COOKIE_JAR}"
  login_admin \
    "${WORK_DIR}/restart-login.headers" \
    "${WORK_DIR}/restart-login.body" \
    "${WORK_DIR}/restart-login-post.headers" \
    "${WORK_DIR}/restart-login-post.body"

  get_authed_page "/admin/campaigns.php?edit=${ASSIGNED_CAMPAIGN_ID}" "${campaign_headers}" "${campaign_body}"
  assert_form_spec_file "${campaign_body}" "${campaign_spec}"

  get_authed_page "/admin/links.php?edit=${LINK_ID}" "${link_headers}" "${link_body}"
  assert_form_spec_file "${link_body}" "${link_spec}"

  assert_direct_client_parity
}

delete_link() {
  local page_headers="${WORK_DIR}/links-delete-page.headers"
  local page_body="${WORK_DIR}/links-delete-page.body"
  local post_headers="${WORK_DIR}/links-delete.headers"
  local post_body="${WORK_DIR}/links-delete.body"
  local payload_file="${WORK_DIR}/links-delete.payload"
  local csrf

  get_authed_page '/admin/links.php' "${page_headers}" "${page_body}"
  csrf="$(extract_csrf "${page_body}")"
  build_form_payload "${payload_file}" \
    "_csrf" "${csrf}" \
    "action" "delete" \
    "id" "${LINK_ID}"
  post_authed_form '/admin/links.php' "${post_headers}" "${post_body}" "${payload_file}"
  LINK_ID=""
}

delete_campaign_by_id() {
  local campaign_id="$1"
  local page_headers="${WORK_DIR}/campaigns-delete-page-${campaign_id}.headers"
  local page_body="${WORK_DIR}/campaigns-delete-page-${campaign_id}.body"
  local post_headers="${WORK_DIR}/campaigns-delete-${campaign_id}.headers"
  local post_body="${WORK_DIR}/campaigns-delete-${campaign_id}.body"
  local payload_file="${WORK_DIR}/campaigns-delete-${campaign_id}.payload"
  local csrf

  get_authed_page '/admin/campaigns.php' "${page_headers}" "${page_body}"
  csrf="$(extract_csrf "${page_body}")"
  build_form_payload "${payload_file}" \
    "_csrf" "${csrf}" \
    "action" "delete" \
    "id" "${campaign_id}"
  post_authed_form '/admin/campaigns.php' "${post_headers}" "${post_body}" "${payload_file}"

  if [[ "${campaign_id}" == "${ASSIGNED_CAMPAIGN_ID}" ]]; then
    ASSIGNED_CAMPAIGN_ID=""
  fi
  if [[ "${campaign_id}" == "${OTHER_CAMPAIGN_ID}" ]]; then
    OTHER_CAMPAIGN_ID=""
  fi
}

while (($# > 0)); do
  case "$1" in
    --https-base-url=*)
      HTTPS_BASE_URL="${1#*=}"
      ;;
    --http-base-url=*)
      HTTP_BASE_URL="${1#*=}"
      ;;
    --admin-username=*)
      ADMIN_USERNAME="${1#*=}"
      ;;
    --admin-password=*)
      ADMIN_PASSWORD="${1#*=}"
      ;;
    --hostile-host=*)
      HOSTILE_HOST="${1#*=}"
      ;;
    --restart-command=*)
      RESTART_COMMAND="${1#*=}"
      ;;
    --client-index-path=*)
      EXPLICIT_CLIENT_INDEX_PATH="${1#*=}"
      ;;
    --assigned-campaign-id=*)
      EXPLICIT_ASSIGNED_CAMPAIGN_ID="${1#*=}"
      ;;
    --other-campaign-id=*)
      EXPLICIT_OTHER_CAMPAIGN_ID="${1#*=}"
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      usage
      fail "Unknown argument: $1"
      ;;
  esac
  shift
done

[[ -n "${HTTPS_BASE_URL}" ]] || fail "--https-base-url is required"
[[ -n "${ADMIN_USERNAME}" ]] || fail "--admin-username is required"
[[ -n "${ADMIN_PASSWORD}" ]] || fail "--admin-password is required"
[[ -n "${RESTART_COMMAND}" ]] || fail "--restart-command is required so restart persistence is verified, not assumed"

if [[ -n "${EXPLICIT_CLIENT_INDEX_PATH}" ]]; then
  [[ -n "${EXPLICIT_ASSIGNED_CAMPAIGN_ID}" && -n "${EXPLICIT_OTHER_CAMPAIGN_ID}" ]] \
    || fail "--assigned-campaign-id and --other-campaign-id are required when --client-index-path is supplied"
  [[ -f "${EXPLICIT_CLIENT_INDEX_PATH}" ]] || fail "Explicit client artifact not found: ${EXPLICIT_CLIENT_INDEX_PATH}"
elif [[ -n "${EXPLICIT_ASSIGNED_CAMPAIGN_ID}" || -n "${EXPLICIT_OTHER_CAMPAIGN_ID}" ]]; then
  fail "--client-index-path, --assigned-campaign-id, and --other-campaign-id must be provided together"
fi

if [[ -z "${HTTP_BASE_URL}" ]]; then
  HTTP_BASE_URL="${HTTPS_BASE_URL/https:\/\//http://}"
fi

require_command bash
require_command curl
require_command php
require_command mktemp

WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/cloaking-smoke.XXXXXX")"
COOKIE_JAR="${WORK_DIR}/cookies.txt"
touch "${COOKIE_JAR}"

log "Checking HTTPS redirect"
assert_https_redirect

log "Checking auth and CSRF guardrails"
assert_missing_csrf_fails
login_admin \
  "${WORK_DIR}/login.headers" \
  "${WORK_DIR}/login.body" \
  "${WORK_DIR}/login-post.headers" \
  "${WORK_DIR}/login-post.body"

log "Reading admin API key without printing it"
fetch_admin_api_key

log "Checking unauthenticated API response"
assert_api_401

log "Checking sensitive-file denial"
assert_sensitive_file_denial

log "Checking hostile host rejection"
assert_host_rejection

log "Creating deterministic persistence fixtures"
create_domain
create_assigned_campaign
update_assigned_campaign
create_other_campaign
create_and_verify_link
update_and_verify_link

log "Generating deterministic client artifact"
generate_client_artifacts
configure_scope_artifact_inputs

log "Checking generated-client credential scope and artifact safety"
assert_client_artifact_security_and_scope

log "Checking direct/generated-client parity"
start_local_client
assert_direct_client_parity

log "Checking dashboard visibility for client verify hits"
assert_dashboard_client_hits

log "Checking restart persistence"
assert_restart_persistence

log "Cleaning up temporary smoke data"
delete_link
delete_campaign_by_id "${OTHER_CAMPAIGN_ID}"
delete_campaign_by_id "${ASSIGNED_CAMPAIGN_ID}"
delete_domain

log "PASS: staged smoke checks completed successfully."
