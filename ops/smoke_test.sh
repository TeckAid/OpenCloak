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

COOKIE_JAR=""
WORK_DIR=""
CLIENT_ROOT=""
CLIENT_PID=""
CLIENT_PORT=""
CAMPAIGN_ID=""
CAMPAIGN_NAME=""
LINK_ID=""
LINK_NAME=""
LINK_SLUG=""
OFFER_URL=""

usage() {
  cat <<'EOF'
Usage:
  bash ops/smoke_test.sh \
    --https-base-url=https://app.example.com \
    --admin-username=owner \
    --admin-password='strong password' \
    --restart-command='docker compose restart web edge' \
    [--http-base-url=http://app.example.com] \
    [--hostile-host=evil.example]

Required checks:
  - HTTPS redirect
  - Secure admin session cookie
  - Auth + CSRF behavior
  - API 401 without Authorization
  - Sensitive-file denial
  - Unknown-host rejection
  - Direct/generated-client parity
  - Campaign persistence
  - Dashboard client-hit visibility
  - Restart persistence

This script writes temporary smoke data through the admin UI and /api/verify,
then deletes that test data before exiting.
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

  if [[ -n "${COOKIE_JAR}" && -n "${CAMPAIGN_ID}" ]]; then
    delete_campaign >/dev/null 2>&1 || true
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

find_free_port() {
  php_eval '
    $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
    if (!is_resource($socket)) {
        fwrite(STDERR, "Unable to reserve free port: " . $errstr . "\n");
        exit(1);
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    $parts = explode(":", (string) $name);
    echo (string) array_pop($parts);
  '
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
    fail "Body leaked forbidden content: ${needle}"
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

  status="$(request_status "${post_headers}" "${post_body}" \
    -b "${COOKIE_JAR}" -c "${COOKIE_JAR}" \
    -H 'Content-Type: application/x-www-form-urlencoded' \
    --data "$(printf '_csrf=%s&username=%s&password=%s' \
      "$(urlencode "${csrf}")" \
      "$(urlencode "${ADMIN_USERNAME}")" \
      "$(urlencode "${ADMIN_PASSWORD}")")" \
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
  local status

  status="$(login_page "${page_headers}" "${page_body}")"
  [[ "${status}" == "200" ]] || fail "CSRF setup login page returned ${status}"

  status="$(request_status "${post_headers}" "${post_body}" \
    -b "${COOKIE_JAR}" -c "${COOKIE_JAR}" \
    -H 'Content-Type: application/x-www-form-urlencoded' \
    --data "$(printf 'username=%s&password=%s' \
      "$(urlencode "${ADMIN_USERNAME}")" \
      "$(urlencode "${ADMIN_PASSWORD}")")" \
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
    headers_file="${WORK_DIR}/sensitive$(echo "${path}" | tr '/.' '__').headers"
    body_file="${WORK_DIR}/sensitive$(echo "${path}" | tr '/.' '__').body"
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
  local form_data="$4"
  local status

  status="$(request_status "${headers_file}" "${body_file}" \
    -b "${COOKIE_JAR}" -c "${COOKIE_JAR}" \
    -H 'Content-Type: application/x-www-form-urlencoded' \
    --data "${form_data}" \
    "${HTTPS_BASE_URL}${path}")"
  [[ "${status}" == "200" || "${status}" == "302" ]] || fail "Unexpected status ${status} for POST ${path}"
}

create_campaign() {
  local page_headers="${WORK_DIR}/campaigns-create-page.headers"
  local page_body="${WORK_DIR}/campaigns-create-page.body"
  local post_headers="${WORK_DIR}/campaigns-create.headers"
  local post_body="${WORK_DIR}/campaigns-create.body"
  local csrf

  CAMPAIGN_NAME="Smoke Campaign $(date -u +%Y%m%dT%H%M%SZ)"
  OFFER_URL="https://offers.example/smoke-$(date -u +%H%M%S)"

  get_authed_page '/admin/campaigns.php' "${page_headers}" "${page_body}"
  csrf="$(extract_csrf "${page_body}")"

  post_authed_form '/admin/campaigns.php' "${post_headers}" "${post_body}" "$(printf '%s' \
    "_csrf=$(urlencode "${csrf}")"\
    "&action=create"\
    "&name=$(urlencode "${CAMPAIGN_NAME}")"\
    "&offer_url=$(urlencode "${OFFER_URL}")"\
    "&white_page=$(urlencode '<h1>Smoke White Page</h1>')"\
    "&reject_mode=white"\
    "&reject_code=403"\
    "&redirect_type=302"\
    "&redirect_delay=0")"

  assert_body_contains "${post_body}" 'Campaign created successfully!'
  CAMPAIGN_ID="$(extract_edit_id_for_name "${post_body}" "${CAMPAIGN_NAME}")"
  [[ -n "${CAMPAIGN_ID}" ]] || fail "Unable to identify created campaign id"
}

activate_campaign() {
  local page_headers="${WORK_DIR}/campaign-edit-page.headers"
  local page_body="${WORK_DIR}/campaign-edit-page.body"
  local post_headers="${WORK_DIR}/campaign-update.headers"
  local post_body="${WORK_DIR}/campaign-update.body"
  local csrf

  get_authed_page "/admin/campaigns.php?edit=${CAMPAIGN_ID}" "${page_headers}" "${page_body}"
  csrf="$(extract_csrf "${page_body}")"

  post_authed_form '/admin/campaigns.php' "${post_headers}" "${post_body}" "$(printf '%s' \
    "_csrf=$(urlencode "${csrf}")"\
    "&action=update"\
    "&id=$(urlencode "${CAMPAIGN_ID}")"\
    "&name=$(urlencode "${CAMPAIGN_NAME}")"\
    "&offer_url=$(urlencode "${OFFER_URL}")"\
    "&white_page=$(urlencode '<h1>Smoke White Page</h1>')"\
    "&reject_mode=white"\
    "&reject_code=403"\
    "&redirect_type=302"\
    "&redirect_delay=0"\
    "&is_active=1")"

  assert_body_contains "${post_body}" 'Campaign updated successfully!'
}

create_link() {
  local page_headers="${WORK_DIR}/links-create-page.headers"
  local page_body="${WORK_DIR}/links-create-page.body"
  local post_headers="${WORK_DIR}/links-create.headers"
  local post_body="${WORK_DIR}/links-create.body"
  local csrf

  LINK_SLUG="smoke-$(date -u +%H%M%S)"
  LINK_NAME="Smoke Link ${LINK_SLUG}"

  get_authed_page '/admin/links.php' "${page_headers}" "${page_body}"
  csrf="$(extract_csrf "${page_body}")"

  post_authed_form '/admin/links.php' "${post_headers}" "${post_body}" "$(printf '%s' \
    "_csrf=$(urlencode "${csrf}")"\
    "&action=create"\
    "&slug=$(urlencode "${LINK_SLUG}")"\
    "&name=$(urlencode "${LINK_NAME}")"\
    "&campaign_id=$(urlencode "${CAMPAIGN_ID}")")"

  LINK_ID="$(extract_edit_id_for_name "${post_body}" "${LINK_NAME}")"
  [[ -n "${LINK_ID}" ]] || fail "Unable to identify created link id"
}

activate_link() {
  local page_headers="${WORK_DIR}/links-edit-page.headers"
  local page_body="${WORK_DIR}/links-edit-page.body"
  local post_headers="${WORK_DIR}/links-update.headers"
  local post_body="${WORK_DIR}/links-update.body"
  local csrf

  get_authed_page "/admin/links.php?edit=${LINK_ID}" "${page_headers}" "${page_body}"
  csrf="$(extract_csrf "${page_body}")"

  post_authed_form '/admin/links.php' "${post_headers}" "${post_body}" "$(printf '%s' \
    "_csrf=$(urlencode "${csrf}")"\
    "&action=update"\
    "&id=$(urlencode "${LINK_ID}")"\
    "&name=$(urlencode "${LINK_NAME}")"\
    "&campaign_id=$(urlencode "${CAMPAIGN_ID}")"\
    "&is_active=1")"
}

assert_campaign_persistence() {
  local page_headers="${WORK_DIR}/campaigns-verify.headers"
  local page_body="${WORK_DIR}/campaigns-verify.body"

  get_authed_page '/admin/campaigns.php' "${page_headers}" "${page_body}"
  assert_body_contains "${page_body}" "${CAMPAIGN_NAME}"
}

generate_client_artifacts() {
  local page_headers="${WORK_DIR}/client-page.headers"
  local page_body="${WORK_DIR}/client-page.body"
  local client_index_path
  local client_router_path

  get_authed_page "/admin/client.php?campaign_id=${CAMPAIGN_ID}" "${page_headers}" "${page_body}"
  CLIENT_ROOT="$(mktemp -d "${WORK_DIR}/client.XXXXXX")"
  client_index_path="${CLIENT_ROOT}/index.php"
  client_router_path="${CLIENT_ROOT}/router.php"

  extract_textarea "${page_body}" 'client-index' > "${client_index_path}"
  cp "${REPO_DIR}/assets/js/tracker.js" "${CLIENT_ROOT}/tracker.min.js"
  printf '%s\n' '<?php return false;' > "${client_router_path}"
}

start_local_client() {
  CLIENT_PORT="$(find_free_port)"
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

  [[ "${direct_location}" == "${client_location}" ]] || fail "Direct/client Location mismatch: ${direct_location} vs ${client_location}"
  [[ "${direct_location}" == "${OFFER_URL}" ]] || fail "Unexpected redirect target: ${direct_location}"
}

assert_dashboard_client_hits() {
  local client_index_path="${CLIENT_ROOT}/index.php"
  local credential
  local verify_headers="${WORK_DIR}/verify.headers"
  local verify_body="${WORK_DIR}/verify.body"
  local dashboard_headers="${WORK_DIR}/dashboard.headers"
  local dashboard_body="${WORK_DIR}/dashboard.body"
  local status
  local payload

  credential="$(extract_defined_value "${client_index_path}" 'CLOAK_CLIENT_CREDENTIAL')"
  payload='{"campaign_id":'"${CAMPAIGN_ID}"',"ip":"198.51.100.10","user_agent":"Mozilla/5.0","referer":"https://facebook.com/ad","language":"en-US","accept":"text/html","host":"landing.example","params":{"utm_source":"fb"},"fingerprint":[],"visitor_token":"","visitor_cookie":""}'

  status="$(request_status "${verify_headers}" "${verify_body}" \
    -H "Authorization: Bearer ${credential}" \
    -H 'Content-Type: application/json' \
    --data "${payload}" \
    "${HTTPS_BASE_URL}/api/verify")"
  [[ "${status}" == "200" ]] || fail "Expected /api/verify client hit to return 200, got ${status}"
  assert_body_contains "${verify_body}" '"allowed":true'

  get_authed_page '/admin/dashboard.php' "${dashboard_headers}" "${dashboard_body}"
  assert_body_contains "${dashboard_body}" "${CAMPAIGN_NAME}"
  assert_body_contains "${dashboard_body}" 'facebook'
}

assert_restart_persistence() {
  local page_headers="${WORK_DIR}/restart-login.headers"
  local page_body="${WORK_DIR}/restart-login.body"
  local post_headers="${WORK_DIR}/restart-login-post.headers"
  local post_body="${WORK_DIR}/restart-login-post.body"

  bash -lc "${RESTART_COMMAND}"

  : > "${COOKIE_JAR}"
  login_admin "${page_headers}" "${page_body}" "${post_headers}" "${post_body}"
  assert_campaign_persistence
  assert_direct_client_parity
}

delete_link() {
  local page_headers="${WORK_DIR}/links-delete-page.headers"
  local page_body="${WORK_DIR}/links-delete-page.body"
  local post_headers="${WORK_DIR}/links-delete.headers"
  local post_body="${WORK_DIR}/links-delete.body"
  local csrf

  get_authed_page '/admin/links.php' "${page_headers}" "${page_body}"
  csrf="$(extract_csrf "${page_body}")"

  post_authed_form '/admin/links.php' "${post_headers}" "${post_body}" "$(printf '%s' \
    "_csrf=$(urlencode "${csrf}")"\
    "&action=delete"\
    "&id=$(urlencode "${LINK_ID}")")"

  LINK_ID=""
}

delete_campaign() {
  local page_headers="${WORK_DIR}/campaigns-delete-page.headers"
  local page_body="${WORK_DIR}/campaigns-delete-page.body"
  local post_headers="${WORK_DIR}/campaigns-delete.headers"
  local post_body="${WORK_DIR}/campaigns-delete.body"
  local csrf

  get_authed_page '/admin/campaigns.php' "${page_headers}" "${page_body}"
  csrf="$(extract_csrf "${page_body}")"

  post_authed_form '/admin/campaigns.php' "${post_headers}" "${post_body}" "$(printf '%s' \
    "_csrf=$(urlencode "${csrf}")"\
    "&action=delete"\
    "&id=$(urlencode "${CAMPAIGN_ID}")")"

  CAMPAIGN_ID=""
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

if [[ -z "${HTTP_BASE_URL}" ]]; then
  HTTP_BASE_URL="${HTTPS_BASE_URL/https:\/\//http://}"
fi

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

log "Checking unauthenticated API response"
assert_api_401

log "Checking sensitive-file denial"
assert_sensitive_file_denial

log "Checking hostile host rejection"
assert_host_rejection

log "Creating temporary smoke campaign and link"
create_campaign
activate_campaign
create_link
activate_link
assert_campaign_persistence

log "Generating local client and checking direct/client parity"
generate_client_artifacts
start_local_client
assert_direct_client_parity

log "Checking dashboard visibility for client verify hits"
assert_dashboard_client_hits

log "Checking restart persistence"
assert_restart_persistence

log "Cleaning up temporary smoke data"
delete_link
delete_campaign

log "PASS: staged smoke checks completed successfully."
