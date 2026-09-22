#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#SCRIPT_DIR="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
source "$SCRIPT_DIR/config"
source "$SCRIPT_DIR/functions.sh"

show_usage() {
    echo "Usage: $0 <login> [pki_dir]"
    echo "Default pki_dir: /etc/openvpn/server/server/rsa/pki"
    exit 1
}

main() {
    # Process arguments
    [[ $# -lt 1 ]] && show_usage

    check_permissions

    # Используем кавычки для защиты от пробелов и спецсимволов
    local CN="$1"
    local PKI_DIR="${2:-/etc/openvpn/server/server/rsa/pki}"

    # Validate PKI directory
    validate_pki_dir "${PKI_DIR}"

    # Find certificate file
    local CERT_FILE
    CERT_FILE=$(find_cert_file "${CN}" "${PKI_DIR}") || {
        log "Error: Certificate for CN=${CN} not found"
        exit 3
    }

    # Validate that the found file is actually a valid certificate
    # Это также гарантирует, что следующий вызов для извлечения серийника не упадёт
    if ! openssl x509 -in "${CERT_FILE}" -noout 2>/dev/null; then
        log "Error: File found is not a valid certificate: ${CERT_FILE}"
        exit 3
    fi

    # Extract serial number for key lookup
    # Используем 2>/dev/null, чтобы подавить возможные предупреждения в stderr
    local SERIAL
    SERIAL=$(openssl x509 -in "${CERT_FILE}" -noout -serial 2>/dev/null | cut -d= -f2) || {
        log "Error: Failed to extract serial number from certificate ${CERT_FILE}"
        exit 3
    }

    # Find private key file
    local KEY_FILE
    KEY_FILE=$(find_key_file "${CN}" "${PKI_DIR}" "${SERIAL}") || {
        log "Error: Private key for CN=${CN} not found"
        exit 4
    }

    # Additional safety check: ensure the key file is readable
    if [[ ! -r "${KEY_FILE}" ]]; then
        log "Error: Private key file is not readable: ${KEY_FILE}"
        exit 4
    fi

    # Output results in XML-like format
    echo "<cert>"
    # openssl нормализует вывод, убирая возможный текстовый мусор
    openssl x509 -in "${CERT_FILE}"
    echo "</cert>"
    echo
    echo "<key>"
    cat "${KEY_FILE}"
    echo "</key>"

    exit 0
}

main "$@"
