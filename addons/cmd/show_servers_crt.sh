#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/config"
source "$SCRIPT_DIR/functions.sh"

show_usage() {
    echo "Usage: $0 <index.txt>"
    echo "Example: $0 /etc/openvpn/server/server/rsa/pki/index.txt"
    exit 1
}

main() {
    # Аргумент обязателен
    [[ $# -ne 1 ]] && show_usage

    check_permissions

    local index_txt="$1"
    local PKI_DIR

    PKI_DIR=$(dirname "${index_txt}")

    # Validate PKI directory
    validate_pki_dir "${PKI_DIR}"

    # Find all certificate files in the issued directory
    # Используем || true, чтобы find не прервал скрипт, если директория пуста
    find "${PKI_DIR}/issued/" \( -name "*.crt" -o -name "*.pem" -o -name "*.cer" \) -print0 2>/dev/null \
        | while IFS= read -r -d '' cert; do

        # Extract subject and extensions from certificate
        local openssl_output
        openssl_output=$(openssl x509 -in "$cert" -subject -noout -ext extendedKeyUsage 2>/dev/null || true)

        # Username = filename without extension
        local username
        username=$(basename "${cert}" | sed 's/\.[^.]*$//')

        # Extract CN from subject
        # Используем [[:space:]] вместо \s для POSIX-совместимости
        # || true предотвращает прерывание из-за set -e, если grep ничего не найдёт
        local CN
        CN=$(echo "$openssl_output" | grep 'subject=' | sed 's/.*CN[[:space:]]*=[[:space:]]*//;s/,.*//' || true)

        # Check if certificate has server authentication usage
        # grep -E использует расширенные регулярные выражения, | без экранирования
        if echo "$openssl_output" | grep -qE "TLS Web Server Authentication|serverAuth"; then
            echo "$username"
            # If CN differs from filename and is not empty, also print CN
            if [[ -n "${CN}" && "${username}" != "${CN}" ]]; then
                echo "$CN"
            fi
        fi
    done

    exit 0
}

main "$@"
