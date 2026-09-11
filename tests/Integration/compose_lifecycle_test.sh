#!/usr/bin/env bash
set -euo pipefail
umask 077

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_dir="$(cd "${script_dir}/../.." && pwd)"

if ! command -v docker >/dev/null 2>&1 || ! docker info >/dev/null 2>&1; then
    echo "SKIP: Docker daemon unavailable; Compose install/backup/restart/restore lifecycle was not executed."
    exit 0
fi

work_dir="$(mktemp -d "${TMPDIR:-/tmp}/cloaking-compose-lifecycle.XXXXXX")"
migration_target="$(basename "$(ls "${repo_dir}"/migrations/*.sql | sort | tail -1)" | cut -d_ -f1 | sed 's/^0*//')"
runtime_dir="${work_dir}/runtime"
backup_dir="${work_dir}/backup"
evidence_dir="${work_dir}/evidence"
config_path="${work_dir}/config.local.php"
project="cloaking-lifecycle-${RANDOM}${RANDOM}"
image_id=""
mkdir -p "${runtime_dir}" "${backup_dir}"

cleanup() {
    local cleanup_image="${image_id}"
    (
        cd "${repo_dir}"
        CLOAKING_RUNTIME_PATH="${runtime_dir}" CLOAKING_CONFIG_PATH="${config_path}" \
            docker compose -p "${project}" down -v >/dev/null 2>&1 || true
    )
    if [[ -n "${cleanup_image}" ]] && docker image inspect "${cleanup_image}" >/dev/null 2>&1; then
        docker run --rm --entrypoint chown \
            -v "${work_dir}:/cleanup" \
            "${cleanup_image}" \
            --reference=/cleanup -R /cleanup >/dev/null 2>&1 || true
    fi
    chmod -R u+rwX "${work_dir}" >/dev/null 2>&1 || true
    # The container entrypoint chowns the runtime mount to www-data; the
    # invoking user may need sudo to remove it (CI runners provide it).
    rm -rf "${work_dir}" 2>/dev/null \
      || sudo rm -rf "${work_dir}" 2>/dev/null \
      || true
}
trap cleanup EXIT

cat > "${config_path}" <<'PHP'
<?php
define('APP_RUNTIME_DIR', '/srv/cloaking/runtime');
define('DB_PATH', '/srv/cloaking/runtime/cloaking.sqlite');
define('LOG_PATH', '/srv/cloaking/runtime/logs/');
define('APP_BASE_URL', 'http://127.0.0.1');
define('SYSTEM_HOSTS', ['127.0.0.1']);
define('TRUSTED_PROXIES', ['172.23.0.2/32']);
define('IP_INTELLIGENCE_FAILURE_MODE', 'closed');
PHP
chmod 0600 "${config_path}"

(
    cd "${repo_dir}"
    CLOAKING_RUNTIME_PATH="${runtime_dir}" CLOAKING_CONFIG_PATH="${config_path}" \
        docker compose -p "${project}" up -d --build web
    CLOAKING_RUNTIME_PATH="${runtime_dir}" CLOAKING_CONFIG_PATH="${config_path}" \
        docker compose -p "${project}" exec -T web php bin/migrate.php --db=/srv/cloaking/runtime/cloaking.sqlite
    printf '%s\n' 'LifecycleStrong123!' | \
        CLOAKING_RUNTIME_PATH="${runtime_dir}" CLOAKING_CONFIG_PATH="${config_path}" \
        docker compose -p "${project}" exec -T web php install.php --username=lifecycle-owner --password-stdin
    CLOAKING_RUNTIME_PATH="${runtime_dir}" CLOAKING_CONFIG_PATH="${config_path}" \
        docker compose -p "${project}" exec -T web php -r '
            require "/var/www/html/config.php";
            require "/var/www/html/includes/database.php";
            $db = initDatabase();
            $db->prepare("INSERT INTO links (user_id, slug, name, offer_url) VALUES (1, ?, ?, ?)")
               ->execute(["lifecycle", "Lifecycle", "https://offers.example/lifecycle"]);
        '
)

web_id="$(cd "${repo_dir}" && CLOAKING_RUNTIME_PATH="${runtime_dir}" CLOAKING_CONFIG_PATH="${config_path}" docker compose -p "${project}" ps -q web)"
image_id="$(docker inspect --format '{{.Image}}' "${web_id}")"
source_commit="$(git -C "${repo_dir}" rev-parse HEAD)"

docker run --rm --entrypoint bash \
    -v "${repo_dir}:/workspace:ro" \
    -v "${runtime_dir}:/srv/cloaking/runtime" \
    -v "${config_path}:/var/www/html/config.local.php:ro" \
    -v "${backup_dir}:/backup" \
    "${image_id}" \
    /workspace/ops/backup_sqlite.sh \
      --db=/srv/cloaking/runtime/cloaking.sqlite \
      --app-key=/srv/cloaking/runtime/app.key \
      --config=/var/www/html/config.local.php \
      --output=/backup \
      --migration-target="${migration_target}" \
      --source-commit="${source_commit}" \
      --image-digest="${image_id}"

(
    cd "${repo_dir}"
    CLOAKING_RUNTIME_PATH="${runtime_dir}" CLOAKING_CONFIG_PATH="${config_path}" \
        docker compose -p "${project}" restart web
    count="$(CLOAKING_RUNTIME_PATH="${runtime_dir}" CLOAKING_CONFIG_PATH="${config_path}" \
        docker compose -p "${project}" exec -T web php -r '
            require "/var/www/html/config.php";
            require "/var/www/html/includes/database.php";
            echo (int) initDatabase()->query("SELECT COUNT(*) FROM links WHERE slug = \"lifecycle\"")->fetchColumn();
        ')"
    [[ "${count}" == "1" ]] || { echo "Lifecycle data did not survive Compose restart." >&2; exit 1; }

    CLOAKING_RUNTIME_PATH="${runtime_dir}" CLOAKING_CONFIG_PATH="${config_path}" \
        docker compose -p "${project}" exec -T web php -r '
            require "/var/www/html/config.php";
            require "/var/www/html/includes/database.php";
            initDatabase()->prepare("DELETE FROM links WHERE slug = ?")->execute(["lifecycle"]);
        '
    count="$(CLOAKING_RUNTIME_PATH="${runtime_dir}" CLOAKING_CONFIG_PATH="${config_path}" \
        docker compose -p "${project}" exec -T web php -r '
            require "/var/www/html/config.php";
            require "/var/www/html/includes/database.php";
            echo (int) initDatabase()->query("SELECT COUNT(*) FROM links WHERE slug = \"lifecycle\"")->fetchColumn();
        ')"
    [[ "${count}" == "0" ]] || { echo "Lifecycle restore precondition did not remove the seeded record." >&2; exit 1; }
    CLOAKING_RUNTIME_PATH="${runtime_dir}" CLOAKING_CONFIG_PATH="${config_path}" \
        docker compose -p "${project}" stop web
)

docker run --rm --entrypoint bash \
    -v "${runtime_dir}:/srv/cloaking/runtime" \
    -v "${backup_dir}:/backup:ro" \
    "${image_id}" \
    -euo pipefail -c '
        rm -f \
            /srv/cloaking/runtime/cloaking.sqlite \
            /srv/cloaking/runtime/cloaking.sqlite-wal \
            /srv/cloaking/runtime/cloaking.sqlite-shm \
            /srv/cloaking/runtime/app.key
        install -o 33 -g 33 -m 0600 /backup/cloaking.sqlite /srv/cloaking/runtime/cloaking.sqlite
        install -o 33 -g 33 -m 0600 /backup/app.key /srv/cloaking/runtime/app.key
    '

(
    cd "${repo_dir}"
    CLOAKING_RUNTIME_PATH="${runtime_dir}" CLOAKING_CONFIG_PATH="${config_path}" \
        docker compose -p "${project}" start web
    ready=0
    for _ in $(seq 1 30); do
        if CLOAKING_RUNTIME_PATH="${runtime_dir}" CLOAKING_CONFIG_PATH="${config_path}" \
            docker compose -p "${project}" exec -T web curl -fsS http://127.0.0.1/healthz >/dev/null 2>&1; then
            ready=1
            break
        fi
        sleep 1
    done
    [[ "${ready}" == "1" ]] || { echo "Compose web service did not become healthy after restore." >&2; exit 1; }
    count="$(CLOAKING_RUNTIME_PATH="${runtime_dir}" CLOAKING_CONFIG_PATH="${config_path}" \
        docker compose -p "${project}" exec -T web php -r '
            require "/var/www/html/config.php";
            require "/var/www/html/includes/database.php";
            echo (int) initDatabase()->query("SELECT COUNT(*) FROM links WHERE slug = \"lifecycle\"")->fetchColumn();
        ')"
    [[ "${count}" == "1" ]] || { echo "Lifecycle data was not recovered into the canonical Compose runtime." >&2; exit 1; }
)

# The backup container runs as root so it can read the service-owned runtime.
# Return its private output to the invoking user before the host-side rehearsal.
docker run --rm --entrypoint chown \
    -v "${backup_dir}:/backup" \
    "${image_id}" \
    --reference=/backup -R /backup

printf '%s\n' 'LifecycleStrong123!' | bash "${repo_dir}/ops/restore_rehearsal.sh" \
    --app-root="${repo_dir}" \
    --backup="${backup_dir}" \
    --evidence-dir="${evidence_dir}" \
    --admin-username=lifecycle-owner \
    --admin-password-stdin \
    --expected-source-commit="${source_commit}" \
    --expected-image-digest="${image_id}"

php -r '
    $smoke = json_decode((string) file_get_contents($argv[1]), true);
    if (!is_array($smoke) || ($smoke["logical_data"]["admin_users"] ?? 0) < 1 || ($smoke["logical_data"]["active_links"] ?? 0) < 1) {
        fwrite(STDERR, "Restored lifecycle data was not readable.\n");
        exit(1);
    }
' "${evidence_dir}/smoke-results.json"

echo "PASS: Compose fresh install, backup, restart, restore, and read-back lifecycle completed."
