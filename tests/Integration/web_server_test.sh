#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
MODE="${1:-all}"

declare -a ACTIVE_CONTAINERS=()
ACTIVE_COMPOSE_PROJECT=""
ACTIVE_COMPOSE_RUNTIME=""

cleanup() {
  local container_id
  if [[ ${#ACTIVE_CONTAINERS[@]} -gt 0 ]]; then
    for container_id in "${ACTIVE_CONTAINERS[@]}"; do
      docker rm -f "${container_id}" >/dev/null 2>&1 || true
    done
  fi

  if [[ -n "${ACTIVE_COMPOSE_PROJECT}" ]]; then
    (
      cd "${REPO_DIR}"
      CLOAKING_RUNTIME_PATH="${ACTIVE_COMPOSE_RUNTIME}" CLOAKING_HTTP_PORT=18080 CLOAKING_HTTPS_PORT=18443 docker compose -p "${ACTIVE_COMPOSE_PROJECT}" down -v >/dev/null 2>&1 || true
    )
  fi
  if [[ -n "${ACTIVE_COMPOSE_RUNTIME}" ]]; then
    # The container entrypoint chowns the runtime mount to www-data, so the
    # invoking user may no longer be able to remove it. Fall back to sudo
    # when available (CI runners provide passwordless sudo).
    rm -rf "${ACTIVE_COMPOSE_RUNTIME}" 2>/dev/null \
      || sudo rm -rf "${ACTIVE_COMPOSE_RUNTIME}" 2>/dev/null \
      || true
  fi
}
trap cleanup EXIT

skip_if_docker_unavailable() {
  if ! docker info >/dev/null 2>&1; then
    echo "SKIP: Docker daemon unavailable; skipping ${MODE} integration checks."
    exit 0
  fi
}

build_image() {
  local tag="$1"
  local dockerfile="$2"

  docker build --pull --tag "${tag}" --file "${dockerfile}" "${REPO_DIR}" >/dev/null
}

wait_for_http() {
  local url="$1"
  local curl_args=("${@:2}")
  local attempt

  for attempt in {1..30}; do
    if curl -fsS "${curl_args[@]}" "${url}" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done

  echo "Timed out waiting for ${url}" >&2
  return 1
}

request_status() {
  local url="$1"
  local body_file="$2"
  shift 2
  curl -sS -o "${body_file}" -w '%{http_code}' "$@" "${url}"
}

assert_static_healthz() {
  local label="$1"
  local url="$2"
  shift 2
  local body_file
  local status

  body_file="$(mktemp)"
  status="$(request_status "${url}" "${body_file}" "$@")"

  if [[ "${status}" != "200" ]]; then
    echo "${label}: expected /healthz to return 200, got ${status}" >&2
    cat "${body_file}" >&2
    rm -f "${body_file}"
    exit 1
  fi

  if ! grep -qx 'ok' "${body_file}"; then
    echo "${label}: expected /healthz body to be ok" >&2
    cat "${body_file}" >&2
    rm -f "${body_file}"
    exit 1
  fi

  rm -f "${body_file}"
}

assert_php_executes() {
  local label="$1"
  local url="$2"
  shift 2
  local body_file
  local status

  body_file="$(mktemp)"
  status="$(request_status "${url}" "${body_file}" "$@")"

  if [[ "${status}" != "200" ]]; then
    echo "${label}: expected ${url} to return 200, got ${status}" >&2
    cat "${body_file}" >&2
    rm -f "${body_file}"
    exit 1
  fi

  if grep -q '<?php' "${body_file}"; then
    echo "${label}: ${url} returned raw PHP source" >&2
    cat "${body_file}" >&2
    rm -f "${body_file}"
    exit 1
  fi

  if ! grep -q 'Sign In' "${body_file}"; then
    echo "${label}: ${url} did not render the expected login page" >&2
    cat "${body_file}" >&2
    rm -f "${body_file}"
    exit 1
  fi

  rm -f "${body_file}"
}

assert_blocked() {
  local label="$1"
  local url="$2"
  local forbidden_text="$3"
  shift 3
  local body_file
  local status

  body_file="$(mktemp)"
  status="$(request_status "${url}" "${body_file}" "$@")"

  case "${status}" in
    403|404)
      ;;
    *)
      echo "${label}: expected ${url} to be blocked, got ${status}" >&2
      cat "${body_file}" >&2
      rm -f "${body_file}"
      exit 1
      ;;
  esac

  if grep -Fq "${forbidden_text}" "${body_file}"; then
    echo "${label}: ${url} leaked forbidden content" >&2
    cat "${body_file}" >&2
    rm -f "${body_file}"
    exit 1
  fi

  rm -f "${body_file}"
}

assert_status_only() {
  local label="$1"
  local url="$2"
  local expected_status="$3"
  local forbidden_text="$4"
  shift 4
  local body_file
  local status

  body_file="$(mktemp)"
  status="$(request_status "${url}" "${body_file}" "$@")"

  if [[ "${status}" != "${expected_status}" ]]; then
    echo "${label}: expected ${url} to return ${expected_status}, got ${status}" >&2
    cat "${body_file}" >&2
    rm -f "${body_file}"
    exit 1
  fi

  if grep -Fq "${forbidden_text}" "${body_file}"; then
    echo "${label}: ${url} leaked forbidden content" >&2
    cat "${body_file}" >&2
    rm -f "${body_file}"
    exit 1
  fi

  rm -f "${body_file}"
}

assert_cookie_attribute() {
  local label="$1"
  local url="$2"
  local expected="$3"
  shift 3
  local headers_file

  headers_file="$(mktemp)"
  curl -ksS -D "${headers_file}" -o /dev/null "$@" "${url}"

  if ! grep -i '^Set-Cookie: cloaksess=' "${headers_file}" >/dev/null 2>&1; then
    echo "${label}: expected cloaksess cookie in response headers" >&2
    cat "${headers_file}" >&2
    rm -f "${headers_file}"
    exit 1
  fi

  if [[ "${expected}" == present ]]; then
    if ! grep -i '^Set-Cookie: cloaksess=.*;\s*Secure\b' "${headers_file}" >/dev/null 2>&1; then
      echo "${label}: expected cloaksess cookie to include Secure" >&2
      cat "${headers_file}" >&2
      rm -f "${headers_file}"
      exit 1
    fi
  else
    if grep -i '^Set-Cookie: cloaksess=.*;\s*Secure\b' "${headers_file}" >/dev/null 2>&1; then
      echo "${label}: expected cloaksess cookie to omit Secure" >&2
      cat "${headers_file}" >&2
      rm -f "${headers_file}"
      exit 1
    fi
  fi

  rm -f "${headers_file}"
}

run_fixture_suite() {
  local label="$1"
  local dockerfile="$2"
  local image_tag="$3"
  local container_id
  local host_port

  build_image "${image_tag}" "${dockerfile}"
  container_id="$(docker run -d -P "${image_tag}")"
  ACTIVE_CONTAINERS+=("${container_id}")
  host_port="$(docker port "${container_id}" 80/tcp | awk -F: 'NR==1 {print $NF}')"

  wait_for_http "http://127.0.0.1:${host_port}/healthz"

  assert_static_healthz "${label}" "http://127.0.0.1:${host_port}/healthz"
  assert_php_executes "${label}" "http://127.0.0.1:${host_port}/admin/login.php"
  assert_blocked "${label}" "http://127.0.0.1:${host_port}/config.php" "APP_RUNTIME_DIR"
  assert_blocked "${label}" "http://127.0.0.1:${host_port}/config.local.php" "APP_BASE_URL"
  assert_blocked "${label}" "http://127.0.0.1:${host_port}/install.php" "CLI installer"
  assert_blocked "${label}" "http://127.0.0.1:${host_port}/dev-router.php" "Router for the PHP built-in server"
  assert_blocked "${label}" "http://127.0.0.1:${host_port}/includes/database.php" "Database initialization"
  assert_blocked "${label}" "http://127.0.0.1:${host_port}/data/cloaking.sqlite" "SQLite format"
  assert_blocked "${label}" "http://127.0.0.1:${host_port}/data/cloaking.sqlite-wal" "SQLite"
  assert_blocked "${label}" "http://127.0.0.1:${host_port}/data/cloaking.sqlite-shm" "SQLite"
  assert_blocked "${label}" "http://127.0.0.1:${host_port}/logs/app.log" "log sentinel"
  assert_blocked "${label}" "http://127.0.0.1:${host_port}/README.md" "readme sentinel"
  assert_blocked "${label}" "http://127.0.0.1:${host_port}/CODE_REVIEW.md" "review sentinel"
  assert_blocked "${label}" "http://127.0.0.1:${host_port}/IMPLEMENTATION_PLAN.md" "plan sentinel"
  assert_blocked "${label}" "http://127.0.0.1:${host_port}/Dockerfile" "dockerfile sentinel"
  assert_blocked "${label}" "http://127.0.0.1:${host_port}/docker-compose.yml" "compose sentinel"
  assert_blocked "${label}" "http://127.0.0.1:${host_port}/nginx.conf" "nginx sentinel"
  assert_blocked "${label}" "http://127.0.0.1:${host_port}/docker/Caddyfile" "caddy sentinel"
  assert_blocked "${label}" "http://127.0.0.1:${host_port}/docs/internal.md" "doc sentinel"

  docker rm -f "${container_id}" >/dev/null
  ACTIVE_CONTAINERS=()
}

run_caddy_suite() {
  local web_container_id

  ACTIVE_COMPOSE_PROJECT="cloaking-int-${RANDOM}${RANDOM}"
  ACTIVE_COMPOSE_RUNTIME="$(mktemp -d "${TMPDIR:-/tmp}/cloaking-caddy-runtime.XXXXXX")"

  (
    cd "${REPO_DIR}"
    CLOAKING_RUNTIME_PATH="${ACTIVE_COMPOSE_RUNTIME}" CLOAKING_HTTP_PORT=18080 CLOAKING_HTTPS_PORT=18443 docker compose -p "${ACTIVE_COMPOSE_PROJECT}" up -d --build web edge >/dev/null
  )

  wait_for_http "https://app.localhost:18443/healthz" -k --resolve app.localhost:18443:127.0.0.1
  web_container_id="$(
    cd "${REPO_DIR}" &&
    CLOAKING_RUNTIME_PATH="${ACTIVE_COMPOSE_RUNTIME}" docker compose -p "${ACTIVE_COMPOSE_PROJECT}" ps -q web
  )"

  assert_status_only "caddy-unknown-host" "http://127.0.0.1:18080/healthz" "421" "ok" -H 'Host: bad.localhost'
  assert_static_healthz "caddy-allowed-host" "https://app.localhost:18443/healthz" -k --resolve app.localhost:18443:127.0.0.1
  assert_cookie_attribute "caddy-trusted-https" "https://app.localhost:18443/admin/login.php" present --resolve app.localhost:18443:127.0.0.1
  docker exec "${web_container_id}" curl -sS -D /tmp/cloaking-direct-headers -o /dev/null \
    -H 'Host: app.localhost' \
    -H 'X-Forwarded-Proto: https' \
    http://127.0.0.1/admin/login.php >/dev/null
  if ! docker exec "${web_container_id}" grep -i '^Set-Cookie: cloaksess=' /tmp/cloaking-direct-headers >/dev/null 2>&1; then
    echo "caddy-untrusted-direct: expected cloaksess cookie from direct web request" >&2
    docker exec "${web_container_id}" cat /tmp/cloaking-direct-headers >&2
    exit 1
  fi
  if docker exec "${web_container_id}" grep -i '^Set-Cookie: cloaksess=.*;\s*Secure\b' /tmp/cloaking-direct-headers >/dev/null 2>&1; then
    echo "caddy-untrusted-direct: direct web request incorrectly trusted forwarded https" >&2
    docker exec "${web_container_id}" cat /tmp/cloaking-direct-headers >&2
    exit 1
  fi
  assert_blocked "caddy-allowed-host" "https://app.localhost:18443/install.php" "CLI installer" -k --resolve app.localhost:18443:127.0.0.1
}

skip_if_docker_unavailable

case "${MODE}" in
  apache)
    run_fixture_suite "apache" "${REPO_DIR}/tests/Integration/apache/Dockerfile" "cloaking-apache-test"
    ;;
  nginx)
    run_fixture_suite "nginx" "${REPO_DIR}/tests/Integration/nginx/Dockerfile" "cloaking-nginx-test"
    ;;
  caddy)
    run_caddy_suite
    ;;
  all)
    run_fixture_suite "apache" "${REPO_DIR}/tests/Integration/apache/Dockerfile" "cloaking-apache-test"
    run_fixture_suite "nginx" "${REPO_DIR}/tests/Integration/nginx/Dockerfile" "cloaking-nginx-test"
    run_caddy_suite
    ;;
  *)
    echo "Usage: $0 [apache|nginx|caddy|all]" >&2
    exit 1
    ;;
esac

echo "PASS: ${MODE} integration checks completed."
