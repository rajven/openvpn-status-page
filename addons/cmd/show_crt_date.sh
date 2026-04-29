#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/functions.sh"

show_usage() {
    echo "Usage: $0 <login> [pki_dir]"
    echo "Default pki_dir: /etc/openvpn/server/server/rsa/pki"
    exit 1
}

main() {
    [[ $# -lt 1 ]] && show_usage

    check_permissions

    local CN=$1
    local PKI_DIR=${2:-/etc/openvpn/server/server/rsa/pki}

    validate_pki_dir "${PKI_DIR}"

    local CERT_FILE
    CERT_FILE=$(find_cert_file "${CN}" "${PKI_DIR}") || {
        echo "${CN};NOT_FOUND;NOT_FOUND;ERROR;0"
        exit 3
    }
    
    # Получаем даты
    local NOT_BEFORE=$(openssl x509 -in "${CERT_FILE}" -noout -startdate | cut -d= -f2)
    local NOT_AFTER=$(openssl x509 -in "${CERT_FILE}" -noout -enddate | cut -d= -f2)
    
    # Вычисляем статус и дни
    local NOW_EPOCH=$(date -u +%s)
    local END_EPOCH=$(date -u -d "${NOT_AFTER}" +%s 2>/dev/null || date -u -j -f "%b %d %T %Y %Z" "${NOT_AFTER}" +%s 2>/dev/null)
    local DAYS=$(( (END_EPOCH - NOW_EPOCH) / 86400 ))
    
    local STATUS
    if [[ ${DAYS} -lt 0 ]]; then
        STATUS="EXPIRED"
        DAYS=$(( -DAYS ))
    else
        STATUS="VALID"
    fi
    
    # Выводим в формате CSV
    echo "${CN};${NOT_BEFORE};${NOT_AFTER};${STATUS};${DAYS}"
    
    exit 0
}

main "$@"
