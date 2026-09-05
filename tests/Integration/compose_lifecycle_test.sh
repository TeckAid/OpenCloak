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
runtime_dir="${work_dir}/runtime"
backup_dir="${work_dir}/backup"
evidence_dir="${work_dir}/evidence"
config_path="${work_dir}/config.local.php"
project="cloaking-lifecycle-${RANDOM}${RANDOM}"
mkdir -p "${runtime_dir}" "${backup_dir}"

cleanup() {
    (
        cd "${repo_dir}"
        CLOAKING_RUNTIME_PATH="${runtime_dir}" CLOAKING_CONFIG_PATH="${config_path}" \
            docker compose -p "${project}" down -v >/dev/null 2>&1 || true
    )
    rm -rf "${work_dir}"
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
      --migration-target=3 \
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
)

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
