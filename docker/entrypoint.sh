#!/usr/bin/env sh
set -eu

APP_ROOT=/var/www/html
STORAGE_DIR="${FILE_STORAGE_PATH:-$APP_ROOT/storage/uploads}"
PEDIMENTOS_TMP_DIR_VALUE="${PEDIMENTOS_TMP_DIR:-/tmp/desembarques-pedimentos}"

mkdir -p "$STORAGE_DIR" "$PEDIMENTOS_TMP_DIR_VALUE"
chown -R www-data:www-data "$APP_ROOT/storage" 2>/dev/null || true
chmod -R ug+rwX "$APP_ROOT/storage" 2>/dev/null || true
chown www-data:www-data "$PEDIMENTOS_TMP_DIR_VALUE" 2>/dev/null || true
chmod 0770 "$PEDIMENTOS_TMP_DIR_VALUE" 2>/dev/null || true

if [ ! -f "$APP_ROOT/vendor/autoload.php" ]; then
    echo "[desembarques] vendor/autoload.php no existe; ejecutando composer install..."
    cd "$APP_ROOT"
    composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --optimize-autoloader
fi

escape_msmtp_value() {
    printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

if [ -n "${SMTP_HOST:-}" ]; then
    SMTP_PORT_VALUE="${SMTP_PORT:-587}"
    SMTP_ENCRYPTION_VALUE=$(printf '%s' "${SMTP_ENCRYPTION:-tls}" | tr '[:upper:]' '[:lower:]')
    SMTP_FROM_VALUE="${MAIL_FROM_ADDRESS:-no-reply@example.com}"

    case "$SMTP_ENCRYPTION_VALUE" in
        ssl|smtps)
            TLS_MODE=on
            TLS_STARTTLS=off
            ;;
        tls|starttls)
            TLS_MODE=on
            TLS_STARTTLS=on
            ;;
        *)
            TLS_MODE=off
            TLS_STARTTLS=off
            ;;
    esac

    {
        echo "defaults"
        echo "timeout 30"
        echo "tls $TLS_MODE"
        if [ "$TLS_MODE" = "on" ]; then
            echo "tls_starttls $TLS_STARTTLS"
            echo "tls_trust_file /etc/ssl/certs/ca-certificates.crt"
        fi
        echo
        echo "account default"
        echo "host $(escape_msmtp_value "$SMTP_HOST")"
        echo "port $(escape_msmtp_value "$SMTP_PORT_VALUE")"
        echo "from $(escape_msmtp_value "$SMTP_FROM_VALUE")"

        if [ -n "${SMTP_USERNAME:-}" ]; then
            echo "auth on"
            echo "user $(escape_msmtp_value "$SMTP_USERNAME")"
            echo "password \"$(escape_msmtp_value "${SMTP_PASSWORD:-}")\""
        else
            echo "auth off"
        fi
    } > /etc/msmtprc

    chown root:www-data /etc/msmtprc
    chmod 640 /etc/msmtprc
    echo "[desembarques] SMTP configurado para ${SMTP_HOST}:${SMTP_PORT_VALUE}."
else
    echo "[desembarques] SMTP no configurado; las funciones de correo permanecerán deshabilitadas."
fi

exec "$@"
