#!/bin/bash

set -o pipefail

show_usage() {
    echo "Usage: $0 [path_to_index.txt]"
    echo "Default index_txt: /etc/openvpn/server/server/rsa/pki/index.txt"
    exit 1
}

log() {
    logger -t "openvpn-www" -p user.info "$1"
    echo "$1"  # Также выводим в консоль для обратной связи
}

# Проверка прав
check_permissions() {
    if [[ $EUID -ne 0 ]]; then
        log "Error: This script must be run as root" >&2
        exit 1
    fi
}

validate_pki_dir() {
    local pki_dir=$1
    if [[ ! -d "${pki_dir}" || ! -f "${pki_dir}/index.txt" ]]; then
        log "Error: Invalid PKI directory - missing index.txt"
        exit 2
    fi
}

main() {
    # Argument handling
    [[ $# -lt 1 ]] && show_usage

    check_permissions

    PKI_DIR=$(dirname "${1}")

    validate_pki_dir "${PKI_DIR}"

    index_txt="${PKI_DIR}/index.txt"

    [ -e "${index_txt}" ] && cat "${index_txt}" || exit 1

}

main "$@"
