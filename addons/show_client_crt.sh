#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

show_usage() {
    echo "Usage: $0 <login> [pki_dir]"
    echo "Default pki_dir: /etc/openvpn/server/server1/rsa/pki"
    exit 1
}

validate_pki_dir() {
    local pki_dir=$1
    if [[ ! -d "${pki_dir}" || ! -f "${pki_dir}/index.txt" ]]; then
        echo "Error: Invalid PKI directory - missing index.txt" >&2
        exit 2
    fi
}

find_cert_file() {
    local cn=$1 pki_dir=$2
    local cert_file
    
    # Try standard location first
    cert_file="${pki_dir}/issued/${cn}.crt"
    [[ -f "${cert_file}" ]] && echo "${cert_file}" && return 0
    
    # Fallback to serial-based lookup
    local serial
    serial=$(awk -v cn="${cn}" '$0 ~ "/CN=" cn "/" && $1 == "V" {print $3}' "${pki_dir}/index.txt")
    [[ -z "${serial}" ]] && return 1
    
    cert_file="${pki_dir}/certs_by_serial/${serial}.pem"
    [[ -f "${cert_file}" ]] && echo "${cert_file}" && return 0
    
    return 1
}

find_key_file() {
    local cn=$1 pki_dir=$2 serial=$3
    local key_file
    
    # Try standard locations
    for candidate in "${pki_dir}/private/${cn}.key" "${pki_dir}/private/${serial}.key"; do
        if [[ -f "${candidate}" ]]; then
            echo "${candidate}"
            return 0
        fi
    done
    
    return 1
}

main() {
    # Argument handling
    [[ $# -lt 1 ]] && show_usage
    
    local CN=$1
    local PKI_DIR=${2:-/etc/openvpn/server/server/rsa/pki}
    
    validate_pki_dir "${PKI_DIR}"
    
    # Find certificate
    local CERT_FILE
    CERT_FILE=$(find_cert_file "${CN}" "${PKI_DIR}") || {
        echo "Error: Certificate for CN=${CN} not found" >&2
        exit 3
    }
    
    # Find serial number for key lookup
    local SERIAL
    SERIAL=$(openssl x509 -in "${CERT_FILE}" -noout -serial | cut -d= -f2)
    
    # Find private key
    local KEY_FILE
    KEY_FILE=$(find_key_file "${CN}" "${PKI_DIR}" "${SERIAL}") || {
        echo "Error: Private key for CN=${CN} not found" >&2
        exit 4
    }
    
    # Output results
    echo "<cert>"
    openssl x509 -in "${CERT_FILE}" -notext
    echo "</cert>"
    echo
    echo "<key>"
    cat "${KEY_FILE}"
    echo "</key>"
}

main "$@"
