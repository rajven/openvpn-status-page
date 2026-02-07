#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#SCRIPT_DIR="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
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

    local CN=$1
    local PKI_DIR=${2:-/etc/openvpn/server/server/rsa/pki}

    # Validate PKI directory
    validate_pki_dir "${PKI_DIR}"

    # Find certificate file
    local CERT_FILE
    CERT_FILE=$(find_cert_file "${CN}" "${PKI_DIR}") || {
        log "Error: Certificate for CN=${CN} not found"
        exit 3
    }

    # Extract serial number for key lookup
    local SERIAL
    SERIAL=$(openssl x509 -in "${CERT_FILE}" -noout -serial | cut -d= -f2)

    # Find private key file
    local KEY_FILE
    KEY_FILE=$(find_key_file "${CN}" "${PKI_DIR}" "${SERIAL}") || {
        log "Error: Private key for CN=${CN} not found"
        exit 4
    }

    # Output results in XML-like format
    echo "<cert>"
    openssl x509 -in "${CERT_FILE}"
    echo "</cert>"
    echo
    echo "<key>"
    cat "${KEY_FILE}"
    echo "</key>"

    exit 0
}

main "$@"
