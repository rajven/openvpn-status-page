#!/bin/bash

set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#SCRIPT_DIR="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
source "$SCRIPT_DIR/functions.sh"

show_usage() {
    echo "Usage: $0 <index.txt>"
    echo "Default index.txt: /etc/openvpn/server/server/rsa/pki/index.txt"
    exit 1
}

main() {
    # Process arguments
    [[ $# -lt 1 ]] && show_usage

    check_permissions

    local index_txt="$1"
    local PKI_DIR

    PKI_DIR=$(dirname "${index_txt}")

    # Validate PKI directory
    validate_pki_dir "${PKI_DIR}"

    # Find all certificate files in the issued directory
    find "${PKI_DIR}/issued/" \( -name "*.crt" -o -name "*.pem" -o -name "*.cer" \) -print0 \
        | while IFS= read -r -d '' cert; do

        # Extract subject and extensions from certificate
        local openssl_output
        openssl_output=$(openssl x509 -in "$cert" -subject -noout -ext extendedKeyUsage -purpose 2>/dev/null)

        # Username = filename without extension
        local username
        username=$(basename "${cert}" | sed 's/\.[^.]*$//')

        # Extract CN from subject
        local CN
        CN=$(echo "$openssl_output" | grep 'subject=' | sed 's/.*CN\s*=\s*//;s/,.*//')

        # Check if certificate has server authentication usage
        if echo "$openssl_output" | grep -q "TLS Web Server Authentication\|serverAuth"; then
            echo "$username"
            # If CN differs from filename, also print CN
            [ "${username}" != "${CN}" ] && echo "$CN"
        fi
    done

    exit 0
}

main "$@"
