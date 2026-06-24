#!/usr/bin/env bash
set -Eeuo pipefail

APP_NAME="${APP_NAME:-case-parser}"
DEPLOY_USER="${DEPLOY_USER:-caseparser}"
PROJECT_DIR="${PROJECT_DIR:-/var/www/case-parser}"
DOMAIN="${DOMAIN:-belyash.space}"
APP_SUBPATH="${APP_SUBPATH:-/parser}"
APP_BIND="${APP_BIND:-127.0.0.1:18080}"
DB_NAME="${DB_NAME:-case_parser}"
DB_USER="${DB_USER:-case_parser}"
DB_PASSWORD="${DB_PASSWORD:-}"
APP_URL="${APP_URL:-http://${DOMAIN}${APP_SUBPATH}}"

if [[ "${EUID}" -ne 0 ]]; then
    echo "Run this script as root." >&2
    exit 1
fi

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log() {
    printf '\n==> %s\n' "$1"
}

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        return 1
    fi
}

install_apt_packages() {
    log "Installing system packages"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y ca-certificates curl git nginx mariadb-server rsync openssl ufw

    if ! require_command docker; then
        apt-get install -y docker.io
    fi

    if ! docker compose version >/dev/null 2>&1; then
        apt-get install -y docker-compose-v2 || apt-get install -y docker-compose-plugin
    fi
}

create_deploy_user() {
    log "Creating deploy user ${DEPLOY_USER}"
    if ! id "${DEPLOY_USER}" >/dev/null 2>&1; then
        useradd --create-home --shell /bin/bash "${DEPLOY_USER}"
    fi

    usermod -aG docker "${DEPLOY_USER}"
}

prepare_project_dir() {
    log "Preparing project directory ${PROJECT_DIR}"
    mkdir -p "$(dirname "${PROJECT_DIR}")"

    if [[ "${SOURCE_DIR}" != "${PROJECT_DIR}" ]]; then
        mkdir -p "${PROJECT_DIR}"
        rsync -a --delete \
            --exclude='.env' \
            --exclude='vendor/' \
            --exclude='node_modules/' \
            --exclude='storage/logs/*.log' \
            "${SOURCE_DIR}/" "${PROJECT_DIR}/"
    fi

    mkdir -p "${PROJECT_DIR}/storage" "${PROJECT_DIR}/bootstrap/cache"
    chown -R "${DEPLOY_USER}:${DEPLOY_USER}" "${PROJECT_DIR}"
}

setup_database() {
    log "Creating MariaDB database and user"
    cat > /etc/mysql/mariadb.conf.d/99-case-parser.cnf <<MYSQLCNF
[mysqld]
bind-address=0.0.0.0
MYSQLCNF
    systemctl restart mariadb
    systemctl enable --now mariadb

    if [[ -z "${DB_PASSWORD}" ]]; then
        if [[ -f "${PROJECT_DIR}/.env" ]] && grep -q '^DB_PASSWORD=' "${PROJECT_DIR}/.env"; then
            DB_PASSWORD="$(grep '^DB_PASSWORD=' "${PROJECT_DIR}/.env" | head -n1 | cut -d= -f2- | sed 's/^"//;s/"$//')"
        fi
    fi

    if [[ -z "${DB_PASSWORD}" ]]; then
        DB_PASSWORD="$(openssl rand -base64 32 | tr -d '\n' | tr '/' '_')"
    fi

    mysql --protocol=socket <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
SQL
}

write_env_file() {
    log "Writing production .env"
    local env_file="${PROJECT_DIR}/.env"

    if [[ ! -f "${env_file}" ]]; then
        cp "${PROJECT_DIR}/.env.example" "${env_file}"
    fi

    set_env "APP_NAME" "CaseParser"
    set_env "APP_ENV" "production"
    set_env "APP_DEBUG" "false"
    set_env "APP_URL" "${APP_URL}"
    set_env "ASSET_URL" "${APP_SUBPATH}"
    set_env "APP_HTTP_BIND" "${APP_BIND}"
    set_env "APP_UID" "$(id -u "${DEPLOY_USER}")"
    set_env "APP_GID" "$(id -g "${DEPLOY_USER}")"
    set_env "DB_CONNECTION" "mysql"
    set_env "DB_HOST" "host.docker.internal"
    set_env "DB_PORT" "3306"
    set_env "DB_DATABASE" "${DB_NAME}"
    set_env "DB_USERNAME" "${DB_USER}"
    set_env "DB_PASSWORD" "${DB_PASSWORD}"
    set_env "CACHE_STORE" "database"
    set_env "SESSION_DRIVER" "database"
    set_env "QUEUE_CONNECTION" "database"
    set_env "PARSER_VERIFY_TLS" "false"

    chown "${DEPLOY_USER}:${DEPLOY_USER}" "${env_file}"
    chmod 640 "${env_file}"
}

set_env() {
    local key="$1"
    local value="$2"
    local file="${PROJECT_DIR}/.env"
    local escaped
    escaped="$(printf '%s' "${value}" | sed 's/[&/]/\\&/g')"

    if grep -q "^${key}=" "${file}"; then
        sed -i "s/^${key}=.*/${key}=${escaped}/" "${file}"
    else
        printf '%s=%s\n' "${key}" "${value}" >> "${file}"
    fi
}

configure_nginx() {
    log "Configuring Nginx for ${DOMAIN}${APP_SUBPATH}"
    cat > "/etc/nginx/sites-available/${APP_NAME}" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    client_max_body_size 32m;

    location = ${APP_SUBPATH} {
        return 301 ${APP_SUBPATH}/;
    }

    location ${APP_SUBPATH}/ {
        proxy_pass http://${APP_BIND}/;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_set_header X-Forwarded-Prefix ${APP_SUBPATH};
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
NGINX

    ln -sf "/etc/nginx/sites-available/${APP_NAME}" "/etc/nginx/sites-enabled/${APP_NAME}"
    rm -f /etc/nginx/sites-enabled/default
    nginx -t
    systemctl enable --now nginx
    systemctl reload nginx
}

configure_firewall() {
    log "Configuring firewall"
    ufw allow OpenSSH >/dev/null || true
    ufw allow 'Nginx Full' >/dev/null || true
    ufw allow from 172.16.0.0/12 to any port 3306 proto tcp >/dev/null || true
    ufw --force enable >/dev/null || true
}

run_initial_deploy() {
    log "Running initial deploy"
    chmod +x "${PROJECT_DIR}/deploy.sh"
    runuser -u "${DEPLOY_USER}" -- bash -lc "cd '${PROJECT_DIR}' && ./deploy.sh"
}

install_apt_packages
systemctl enable --now docker
create_deploy_user
prepare_project_dir
setup_database
write_env_file
configure_nginx
configure_firewall
run_initial_deploy

cat <<DONE

Setup complete.

Project: ${PROJECT_DIR}
URL:     ${APP_URL}
DB:      ${DB_NAME}
DB user: ${DB_USER}

Next deploys:
  ssh root@server
  cd ${PROJECT_DIR}
  sudo -u ${DEPLOY_USER} git pull
  sudo -u ${DEPLOY_USER} ./deploy.sh

If you enable HTTPS later, update APP_URL in ${PROJECT_DIR}/.env to https://${DOMAIN}${APP_SUBPATH} and run ./deploy.sh again.
DONE



