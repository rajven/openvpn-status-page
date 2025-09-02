#!/bin/bash

# Функция для логирования
log() {
    logger -t "openvpn-revoke" -p user.info "$1"
    echo "$1"  # Также выводим в консоль для обратной связи
}

# Проверка прав
check_permissions() {
    if [[ $EUID -ne 0 ]]; then
        log "Error: This script must be run as root" >&2
        exit 1
    fi
}

if [ $# -ne 3 ]; then
    log "Usage: $0 <service_name> <rsa_dir> <username>"
    exit 1
fi

check_permissions

SRV_NAME="${1}"
RSA_DIR="${2}"
USERNAME="${3}"

log "Starting certificate revocation for $USERNAME by user $ORIGINAL_USER"

# Проверяем существование директории RSA
if [ ! -d "$RSA_DIR" ]; then
    log "Error: RSA directory not found: $RSA_DIR"
    exit 1
fi

# Переходим в директорию RSA
cd "$RSA_DIR" || exit 1

# Проверяем наличие easyrsa
if [ ! -f "./easyrsa" ]; then
    log "Error: easyrsa not found in $RSA_DIR"
    exit 1
fi

# Проверяем существование сертификата
if [ ! -f "./pki/issued/${USERNAME}.crt" ]; then
    log "Error: Certificate for user $USERNAME not found"
    exit 1
fi

# Проверяем, не отозван ли уже сертификат
if grep -q "/CN=${USERNAME}" ./pki/index.txt | grep -q "R"; then
    log "Error: Certificate for $USERNAME is already revoked"
    exit 1
fi

# Отзываем сертификат
log "Revoking certificate for user: $USERNAME"
./easyrsa --batch revoke "$USERNAME"

# Проверяем успешность отзыва
if [ $? -eq 0 ]; then
    log "Successfully revoked certificate for $USERNAME"

    # Генерируем CRL (Certificate Revocation List)
    log "Generating CRL..."
    ./easyrsa --batch gen-crl

    if [ $? -eq 0 ]; then
        log "CRL generated successfully"

        chown nobody:nogroup -R "$RSA_DIR/pki/issued/"
	chown nobody:nogroup -R "$RSA_DIR/pki/crl.pem"
        chmod 640 "${RSA_DIR}"/pki/issued/*.crt

        # Рестартуем сервис
        log "Restarting service: $SRV_NAME"
        systemctl restart "${SRV_NAME}"

        if [ $? -eq 0 ]; then
            log "Service $SRV_NAME restarted successfully"
            log "Certificate revocation completed for $USERNAME"
            exit 0
        else
            log "Error: Failed to restart service $SRV_NAME"
            exit 1
        fi
    else
        log "Error: Failed to generate CRL"
        exit 1
    fi
else
    log "Error: Failed to revoke certificate for $USERNAME"
    exit 1
fi

exit 0