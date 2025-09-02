#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

show_usage() {
    echo "Usage: $0 <index.txt>"
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

    find "${PKI_DIR}/issued/" \( -name "*.crt" -o -name "*.pem" -o -name "*.cer" \) -print0 | while IFS= read -r -d '' cert; do
        # Одновременно получаем subject и проверяем расширения
	openssl_output=$(openssl x509 -in "$cert" -subject -noout -ext extendedKeyUsage -purpose 2>/dev/null)
        username=$(basename "${cert}" | sed 's/\.[^.]*$//')
	CN=$(echo "$openssl_output" | grep 'subject=' | sed 's/.*CN=//;s/,.*//')
        # Проверяем расширения из одного вывода openssl
	if echo "$openssl_output" | grep -q "TLS Web Server Authentication\|serverAuth" || 
    	    echo "$openssl_output" | grep -q "SSL server : Yes"; then
            echo "$username"
	    [ "${username}" != "${CN}" ] && echo "$CN"
	    fi
    done
}

main "$@"
