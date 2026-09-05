#!/usr/bin/env bash
#
# Production deploy pipeline: pre-deploy backup -> migration -> build ->
# up -d -> health wait -> smoke test. Aborts on the first failing gate.
#
# Usage:
#   sudo bash ops/deploy_web.sh \
#     --repo-dir=/srv/cloaking/app \
#     --config=/srv/cloaking/config/config.local.php \
#     --backup-dir=/srv/cloaking/backups \
#     --https-base-url=https://app.example.com \
#     --admin-username=owner \
#     --admin-password-stdin
#
# Optional:
#   --db=/srv/cloaking/runtime/cloaking.sqlite   (default: <runtime>/cloaking.sqlite)
#   --app-key=/srv/cloaking/runtime/app.key      (default: <runtime>/app.key)
#   --runtime=/srv/cloaking/runtime              (exported as CLOAKING_RUNTIME_PATH)
#   --ca-bundle=/path/to/private-ca.pem          (forwarded to the smoke test)
#   --http-base-url=http://app.example.com       (forwarded to the smoke test)
#   --skip-build     (image already built/pinned for this commit)
#   --skip-smoke     (skip the staged TLS smoke; only for rehearsals)
#   --dry-run        (print the planned steps without changing anything)
#
# The admin password is read from stdin (never from argv) and stored in a
# private temporary file for the smoke test.

set -euo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

repo_dir=""
config_path=""
backup_dir=""
https_base_url=""
http_base_url=""
admin_username=""
admin_password_stdin="0"
ca_bundle=""
db_path=""
app_key_path=""
runtime_path=""
skip_build="0"
skip_smoke="0"
dry_run="0"

usage() {
  grep '^#' "$0" | sed 's/^# \{0,1\}//' >&2
  exit 1
}

for arg in "$@"; do
  case "$arg" in
    --repo-dir=*) repo_dir="${arg#*=}" ;;
    --config=*) config_path="${arg#*=}" ;;
    --backup-dir=*) backup_dir="${arg#*=}" ;;
    --https-base-url=*) https_base_url="${arg#*=}" ;;
    --http-base-url=*) http_base_url="${arg#*=}" ;;
    --admin-username=*) admin_username="${arg#*=}" ;;
    --admin-password-stdin) admin_password_stdin="1" ;;
    --ca-bundle=*) ca_bundle="${arg#*=}" ;;
    --db=*) db_path="${arg#*=}" ;;
    --app-key=*) app_key_path="${arg#*=}" ;;
    --runtime=*) runtime_path="${arg#*=}" ;;
    --skip-build) skip_build="1" ;;
    --skip-smoke) skip_smoke="1" ;;
    --dry-run) dry_run="1" ;;
    --help|-h) usage ;;
    *) echo "Unknown argument: ${arg}" >&2; usage ;;
  esac
done

[[ -n "${repo_dir}" && -n "${config_path}" && -n "${backup_dir}" ]] \
  || { echo "Error: --repo-dir, --config, and --backup-dir are required." >&2; usage; }
[[ -d "${repo_dir}/.git" ]] || { echo "Error: ${repo_dir} is not a git checkout." >&2; exit 1; }

if [[ "${skip_smoke}" -eq 0 ]]; then
  [[ -n "${https_base_url}" && -n "${admin_username}" && "${admin_password_stdin}" -eq 1 ]] \
    || { echo "Error: smoke requires --https-base-url, --admin-username, and --admin-password-stdin." >&2; usage; }
fi
if [[ "${https_base_url}" != https://* ]]; then
  [[ "${skip_smoke}" -eq 1 ]] || { echo "Error: --https-base-url must use HTTPS." >&2; exit 1; }
fi

cd "${repo_dir}" || { echo "Error: cannot enter ${repo_dir}." >&2; exit 1; }

# ---- Gate 1: clean checkout ---------------------------------------------------
if [[ -n "$(git status --porcelain)" ]]; then
  echo "Error: worktree is dirty. Commit or stash before deploying." >&2
  exit 1
fi
branch="$(git rev-parse --abbrev-ref HEAD)"
[[ "${branch}" == "main" || "${branch}" == "master" ]] \
  || { echo "Error: not on main branch (currently '${branch}')." >&2; exit 1; }
SOURCE_COMMIT="$(git rev-parse HEAD)"
[[ "${SOURCE_COMMIT}" =~ ^[a-f0-9]{40}$ ]] || { echo "Error: cannot resolve commit." >&2; exit 1; }

# Path defaults and compose settings (process-local; safe for dry-run too)
compose=("docker" "compose" "-f" "${repo_dir}/docker-compose.yml" "--project-directory" "${repo_dir}")
export CLOAKING_CONFIG_PATH="${config_path}"
if [[ -n "${runtime_path}" ]]; then
  export CLOAKING_RUNTIME_PATH="${runtime_path}"
  : "${db_path:="${runtime_path}/cloaking.sqlite"}"
  : "${app_key_path:="${runtime_path}/app.key"}"
fi
: "${db_path:="${repo_dir}/runtime/cloaking.sqlite"}"
: "${app_key_path:="$(dirname "${db_path}")/app.key"}"

if [[ "${dry_run}" -eq 1 ]]; then
  echo "[deploy] DRY RUN — no changes will be made."
  echo "[deploy] commit=${SOURCE_COMMIT}"
  echo "[deploy] config=${config_path}"
  echo "[deploy] db=${db_path}"
  echo "[deploy] app-key=${app_key_path}"
  echo "[deploy] steps: backup -> migrate -> build -> up -d -> health wait -> smoke"
  echo "[deploy] (skip-build=${skip_build}, skip-smoke=${skip_smoke})"
  exit 0
fi

if [[ "${EUID}" -ne 0 ]]; then
  echo "Error: run as root (sudo bash ops/deploy_web.sh ...). Runtime and config are root-owned." >&2
  exit 1
fi

# ---- Password (stdin only) --------------------------------------------------
admin_password_file=""
if [[ "${admin_password_stdin}" -eq 1 ]]; then
  admin_password_file="$(mktemp)"
  chmod 600 "${admin_password_file}"
  cat > "${admin_password_file}"
  [[ -s "${admin_password_file}" ]] || { echo "Error: empty admin password on stdin." >&2; exit 1; }
fi
cleanup() {
  if [[ -n "${admin_password_file}" && -f "${admin_password_file}" ]]; then
    rm -f "${admin_password_file}"
  fi
}
trap cleanup EXIT

say() { echo "[deploy] $*"; }

# ---- Gate 2: resolve target image digest --------------------------------------
resolve_digest() {
  local image_id
  image_id="$("${compose[@]}" images -q web 2>/dev/null | tail -1 || true)"
  if [[ -z "${image_id}" ]]; then
    return 1
  fi
  local digest
  digest="$(docker image inspect --format '{{index .RepoDigests 0}}' "${image_id}" 2>/dev/null || true)"
  if [[ "${digest}" != sha256:* ]]; then
    digest="$(docker image inspect --format '{{.Id}}' "${image_id}" 2>/dev/null || true)"
  fi
  [[ "${digest}" =~ ^sha256:[a-f0-9]{64}$ ]] || return 1
  echo "${digest}"
}

IMAGE_DIGEST="$(resolve_digest || true)"
if [[ -z "${IMAGE_DIGEST}" && "${skip_build}" -eq 0 ]]; then
  say "web image not built yet; building to derive the digest (no runtime change)"
  "${compose[@]}" build
  IMAGE_DIGEST="$(resolve_digest || true)"
fi
[[ -n "${IMAGE_DIGEST}" ]] \
  || { echo "Error: cannot resolve web image digest. Build first or drop --skip-build." >&2; exit 1; }

# ---- Gate 3: pre-deploy backup -------------------------------------------------
BACKUP_TS="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP_PATH="${backup_dir%/}/${BACKUP_TS}"
mkdir -p "${BACKUP_PATH}"

say "backup -> ${BACKUP_PATH}"
bash "${SCRIPT_DIR}/backup_sqlite.sh" \
  --output="${BACKUP_PATH}" \
  --config="${config_path}" \
  --db="${db_path}" \
  --app-key="${app_key_path}" \
  --migration-target=3 \
  --source-commit="${SOURCE_COMMIT}" \
  --image-digest="${IMAGE_DIGEST}"
[[ -f "${BACKUP_PATH}/backup-metadata.json" ]] \
  || { echo "Error: backup finished without a manifest." >&2; exit 1; }

# ---- Gate 4: migration -----------------------------------------------------------
say "migration -> schema 3"
php bin/migrate.php \
  --db="${db_path}" \
  --backup-manifest="${BACKUP_PATH}/backup-metadata.json" \
  --app-key="${app_key_path}"

# ---- Gate 5: build ---------------------------------------------------------------
if [[ "${skip_build}" -eq 0 ]]; then
  say "build"
  "${compose[@]}" build
else
  say "build skipped (--skip-build)"
fi

# ---- Gate 6: up + health wait ------------------------------------------------------
say "up -d"
"${compose[@]}" up -d

container_id="$("${compose[@]}" ps -q web)"
[[ -n "${container_id}" ]] || { echo "Error: web container not running after up -d." >&2; exit 1; }

healthy="0"
for _ in $(seq 1 60); do
  status="$(docker inspect --format '{{.State.Health.Status}}' "${container_id}" 2>/dev/null || echo 'none')"
  if [[ "${status}" == "healthy" ]]; then
    healthy="1"
    break
  fi
  sleep 3
done
if [[ "${healthy}" -ne 1 ]]; then
  echo "Error: web container did not become healthy within 180s." >&2
  docker inspect --format '{{json .State.Health}}' "${container_id}" >&2 || true
  exit 1
fi
say "web container healthy"

# ---- Gate 7: smoke ------------------------------------------------------------------
if [[ "${skip_smoke}" -eq 0 ]]; then
  say "smoke -> ${https_base_url}"
  smoke_args=(
    --https-base-url="${https_base_url}"
    --admin-username="${admin_username}"
    --admin-password-stdin
    --restart-command="docker compose -f '${repo_dir}/docker-compose.yml' --project-directory '${repo_dir}' restart web edge"
  )
  [[ -n "${ca_bundle}" ]] && smoke_args+=(--ca-bundle="${ca_bundle}")
  [[ -n "${http_base_url}" ]] && smoke_args+=(--http-base-url="${http_base_url}")
  bash "${SCRIPT_DIR}/smoke_test.sh" "${smoke_args[@]}" < "${admin_password_file}"
else
  say "smoke skipped (--skip-smoke)"
fi

# ---- Summary --------------------------------------------------------------------------
say "DEPLOY COMPLETE"
say "commit=${SOURCE_COMMIT}"
say "image-digest=${IMAGE_DIGEST}"
say "backup=${BACKUP_PATH}"
say "schema=3"
say "Rollback: pin the previous image for the web service, then:"
say "  ${compose[*]} up -d"
