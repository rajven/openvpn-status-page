#!/bin/bash

log() {
    logger -t "openvpn-create" -p user.info "$1"
    echo "$1"  # Также выводим в консоль для обратной связи
}

# Проверка прав
check_permissions() {
    if [[ $EUID -ne 0 ]]; then
        log "Error: This script must be run as root" >&2
        exit 1
    fi
}

if [ $# -ne 2 ]; then
    echo "Usage: $0 <rsa_dir> <username>"
    exit 1
fi

check_permissions

RSA_DIR="$1"
USERNAME="$2"

# Проверяем существование директории PKI
if [ ! -d "$RSA_DIR" ]; then
    log "PKI directory not found: $RSA_DIR"
    exit 1
fi

# Проверяем наличие easyrsa
if [ ! -f "$RSA_DIR/easyrsa" ]; then
    log "easyrsa not found in $RSA_DIR"
    exit 1
fi

# Проверяем, не существует ли уже пользователь
if [ -f "$RSA_DIR/pki/index.txt" ] && grep -q "CN=$USERNAME" "$RSA_DIR/pki/index.txt"; then
    log "User $USERNAME already exists"
    exit 1
fi

# Переходим в директорию PKI и создаем клиента
cd "$RSA_DIR" || exit 1

# Генерируем клиентский ключ и сертификат в batch mode (без подтверждений)
./easyrsa --batch build-client-full "$USERNAME" nopass

if [ $? -eq 0 ]; then
    log "User $USERNAME created successfully"
    chown nobody:nogroup -R "$RSA_DIR/pki/issued/"
    chmod 640 "${RSA_DIR}"/pki/issued/*.crt
    chown nobody:nogroup -R "$RSA_DIR/pki/private/"
    chmod 640 "${RSA_DIR}"/pki/private/*.key
    exit 0
else
    echo "Failed to create user $USERNAME"
    exit 1
fi
