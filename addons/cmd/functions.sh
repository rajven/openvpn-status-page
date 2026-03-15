#!/bin/bash

owner_user=nobody
owner_group=nogroup

# Name of the current script (without path)
script_name="$(basename "${BASH_SOURCE[0]}")"

log() {
    logger -t "$script_name" -p user.info "$1"
    echo "$1"
}

mlog() {
    logger -t "$script_name" -p user.info "$1"
}

# Check permissions (must be root)
check_permissions() {
    if [[ $EUID -ne 0 ]]; then
        log "Error: This script must be run as root"
        exit 1
    fi
}

# Validate that the path is a file or directory and is writable
check_ccd_path() {
    local path="$1"

    if [[ -d "$path" ]]; then
        # It's a directory — check write permission
        if [[ ! -w "$path" ]]; then
            log "Error: No write permission for directory: $path"
            exit 1
        fi
    elif [[ -f "$path" ]]; then
        # It's a file — check write permission
        if [[ ! -w "$path" ]]; then
            log "Error: No write permission for file: $path"
            exit 1
        fi
    fi
}

validate_pki_dir() {
    local pki_dir=$1
    if [[ ! -d "${pki_dir}" || ! -f "${pki_dir}/index.txt" ]]; then
        log "Error: Invalid PKI directory - missing index.txt"
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

#mlog "Script called with: $0 $@"
