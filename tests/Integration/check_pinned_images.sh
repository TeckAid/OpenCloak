#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

require_digest_ref() {
  local file="$1"
  local pattern="$2"
  local label="$3"

  if ! grep -Eq "${pattern}" "${file}"; then
    echo "FAIL: ${label} in ${file} is not pinned to an immutable sha256 digest." >&2
    exit 1
  fi
}

require_digest_ref "${ROOT_DIR}/Dockerfile" '^ARG PHP_APACHE_IMAGE=[^[:space:]]+@sha256:[a-f0-9]{64}$' 'PHP Apache base image'
require_digest_ref "${ROOT_DIR}/docker-compose.yml" 'PHP_APACHE_IMAGE:[[:space:]]+[^[:space:]]+@sha256:[a-f0-9]{64}$' 'Compose PHP Apache build arg'
require_digest_ref "${ROOT_DIR}/docker-compose.yml" 'image:[[:space:]]+[^[:space:]]+@sha256:[a-f0-9]{64}$' 'Caddy edge image'
require_digest_ref "${ROOT_DIR}/tests/Integration/apache/Dockerfile" '^ARG PHP_APACHE_IMAGE=[^[:space:]]+@sha256:[a-f0-9]{64}$' 'Apache test base image'
require_digest_ref "${ROOT_DIR}/tests/Integration/nginx/Dockerfile" '^ARG PHP_FPM_IMAGE=[^[:space:]]+@sha256:[a-f0-9]{64}$' 'Nginx test PHP-FPM base image'

echo "PASS: all container image references are digest-pinned."
