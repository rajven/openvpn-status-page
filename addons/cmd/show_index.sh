#!/bin/bash

set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#SCRIPT_DIR="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
source "$SCRIPT_DIR/functions.sh"

show_usage() {
    echo "Usage: $0 [path_to_index.txt]"
    echo "Default index.txt: /etc/openvpn/server/server/rsa/pki/index.txt"
    exit 1
}

main() {
    # Process arguments
    [[ $# -lt 1 ]] && show_usage

    check_permissions

    local index_txt="$1"
    local PKI_DIR

    # If a file path was provided, get its directory
    PKI_DIR=$(dirname "${index_txt}")

    # Validate the PKI directory
    validate_pki_dir "${PKI_DIR}"

    # Default to index.txt if needed
    index_txt="${index_txt:-${PKI_DIR}/index.txt}"

    # Check existence and output
    if [ -e "${index_txt}" ]; then
        cat "${index_txt}"
    else
        log "Error: index.txt not found in ${PKI_DIR}"
        exit 1
    fi
}

main "$@"
