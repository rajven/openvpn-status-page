#!/bin/bash

if [ $# -ne 2 ]; then
    echo "Usage: $0 <rsa_dir> <username>"
    exit 1
fi

RSA_DIR="$1"
USERNAME="$2"

ORIGINAL_USER="$SUDO_USER"
if [ -z "${ORIGINAL_USER}" ]; then
    ORIGINAL_USER='www-data'
    fi

# Проверяем существование директории PKI
if [ ! -d "$RSA_DIR" ]; then
    echo "PKI directory not found: $RSA_DIR"
    exit 1
fi

# Проверяем наличие easyrsa
if [ ! -f "$RSA_DIR/easyrsa" ]; then
    echo "easyrsa not found in $RSA_DIR"
    exit 1
fi

# Проверяем, не существует ли уже пользователь
if [ -f "$RSA_DIR/pki/index.txt" ] && grep -q "CN=$USERNAME" "$RSA_DIR/pki/index.txt"; then
    echo "User $USERNAME already exists"
    exit 1
fi

# Переходим в директорию PKI и создаем клиента
cd "$RSA_DIR" || exit 1

# Генерируем клиентский ключ и сертификат в batch mode (без подтверждений)
./easyrsa --batch build-client-full "$USERNAME" nopass

if [ $? -eq 0 ]; then
    echo "User $USERNAME created successfully"
    chown nobody:${ORIGINAL_USER} -R "$RSA_DIR/pki/issued/"
    chmod 640 "${RSA_DIR}"/pki/issued/*.crt
    exit 0
else
    echo "Failed to create user $USERNAME"
    exit 1
fi
