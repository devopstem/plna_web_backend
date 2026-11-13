#!/usr/bin/env bash
# Shared helpers for PLNA Web Backend install/deploy scripts.
# shellcheck shell=bash

set -euo pipefail

PLNA_SCRIPTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLNA_ROOT="$(cd "${PLNA_SCRIPTS_DIR}/.." && pwd)"

log()  { printf '\n\033[1;36m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m✓\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m✗\033[0m %s\n' "$*" >&2; exit 1; }

need_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    die "Запусти от root: sudo $0 $*"
  fi
}

detect_ubuntu() {
  if [[ ! -f /etc/os-release ]]; then
    die "Не удалось определить ОС (/etc/os-release)."
  fi
  # shellcheck disable=SC1091
  . /etc/os-release
  if [[ "${ID:-}" != "ubuntu" ]]; then
    die "Скрипт рассчитан на Ubuntu 22.04/24.04. Сейчас: ${PRETTY_NAME:-unknown}"
  fi
  case "${VERSION_ID:-}" in
    22.04|24.04) ok "ОС: ${PRETTY_NAME}" ;;
    *)
      warn "Непроверенная версия Ubuntu (${VERSION_ID}). Продолжаем на свой риск."
      ;;
  esac
}

rand_secret() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -base64 32 | tr -d '/+=' | head -c 32
  else
    head -c 48 /dev/urandom | base64 | tr -d '/+=' | head -c 32
  fi
}

set_env_kv() {
  local file="$1" key="$2" value="$3"
  local escaped
  escaped="$(printf '%s' "${value}" | sed -e 's/[\\/&|]/\\&/g')"
  if grep -qE "^${key}=" "${file}"; then
    sed -i -E "s|^${key}=.*|${key}=${escaped}|" "${file}"
  else
    printf '%s=%s\n' "${key}" "${value}" >> "${file}"
  fi
}

ensure_php_cli() {
  command -v php >/dev/null 2>&1 || die "php не найден в PATH"
  local ver
  ver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  if [[ "${ver}" != "8.3" && "${ver}" != "8.4" ]]; then
    warn "Ожидался PHP 8.3+, сейчас ${ver}"
  else
    ok "PHP ${ver}"
  fi
}

app_permissions() {
  local app_dir="$1" web_user="${2:-www-data}"
  mkdir -p \
    "${app_dir}/storage/framework/"{cache,sessions,views} \
    "${app_dir}/storage/logs" \
    "${app_dir}/storage/app/private" \
    "${app_dir}/bootstrap/cache"
  chown -R "${web_user}:${web_user}" "${app_dir}/storage" "${app_dir}/bootstrap/cache"
  find "${app_dir}/storage" "${app_dir}/bootstrap/cache" -type d -exec chmod 775 {} \;
  find "${app_dir}/storage" "${app_dir}/bootstrap/cache" -type f -exec chmod 664 {} \; || true
  ok "Права storage/ и bootstrap/cache → ${web_user}"
}

composer_install_prod() {
  local app_dir="$1"
  (
    cd "${app_dir}"
    if [[ -f composer.lock ]]; then
      composer install --no-dev --optimize-autoloader --no-interaction
    else
      composer install --no-dev --optimize-autoloader --no-interaction
    fi
  )
}

frontend_build() {
  local app_dir="$1"
  (
    cd "${app_dir}"
    if [[ -f package-lock.json ]]; then
      npm ci
    else
      npm install
    fi
    npm run build
  )
}

backup_database() {
  local app_dir="$1"
  local reason="${2:-manual}"
  local env_file="${app_dir}/.env"
  local backup_dir="${app_dir}/storage/app/backups"
  local stamp driver db host port user password outfile

  [[ -f "${env_file}" ]] || die "Нет .env для бэкапа: ${env_file}"

  mkdir -p "${backup_dir}"
  stamp="$(date +%Y%m%d-%H%M%S)"
  driver="$(grep -E '^DB_CONNECTION=' "${env_file}" | head -n1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
  db="$(grep -E '^DB_DATABASE=' "${env_file}" | head -n1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
  host="$(grep -E '^DB_HOST=' "${env_file}" | head -n1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
  port="$(grep -E '^DB_PORT=' "${env_file}" | head -n1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
  user="$(grep -E '^DB_USERNAME=' "${env_file}" | head -n1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
  password="$(grep -E '^DB_PASSWORD=' "${env_file}" | head -n1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
  host="${host:-127.0.0.1}"
  driver="${driver:-pgsql}"

  case "${driver}" in
    pgsql|postgres|postgresql)
      command -v pg_dump >/dev/null 2>&1 || die "pg_dump не найден — установите postgresql-client"
      [[ -n "${db}" && -n "${user}" ]] || die "DB_DATABASE / DB_USERNAME пусты в .env"
      port="${port:-5432}"
      outfile="${backup_dir}/plna-${reason}-${stamp}.sql"
      log "Бэкап PostgreSQL → ${outfile}"
      PGPASSWORD="${password}" pg_dump \
        --host="${host}" \
        --port="${port}" \
        --username="${user}" \
        --dbname="${db}" \
        --no-owner \
        --no-acl \
        --format=p \
        --file="${outfile}"
      ;;
    *)
      die "Автобэкап поддерживается только для PostgreSQL (сейчас: ${driver})"
      ;;
  esac

  ok "Бэкап готов: ${outfile}"
}

artisan_optimize() {
  local app_dir="$1"
  (
    cd "${app_dir}"
    php artisan storage:link 2>/dev/null || true
    backup_database "${app_dir}" "install"
    php artisan migrate --force
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache 2>/dev/null || true
  )
}

reload_services() {
  systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php*-fpm 2>/dev/null || true
  systemctl reload nginx 2>/dev/null || true
  if command -v supervisorctl >/dev/null 2>&1; then
    supervisorctl reread >/dev/null 2>&1 || true
    supervisorctl update >/dev/null 2>&1 || true
    supervisorctl restart plna-queue:* 2>/dev/null || true
    supervisorctl restart plna-reverb:* 2>/dev/null || true
  fi
}
