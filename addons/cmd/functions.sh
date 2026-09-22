#!/bin/bash

# Default values for owner (can be overridden in 'config' before sourcing this file)
: "${owner_user:=nobody}"
: "${owner_group:=nogroup}"

# Name of the current script
script_name="$(basename "$0")"

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

# Validate that the path is a file or directory and is writable.
# If it doesn't exist, validate the parent directory.
check_ccd_path() {
    local path="$1"

    if [[ -d "$path" ]]; then
        if [[ ! -w "$path" ]]; then
            log "Error: No write permission for directory: $path"
            exit 1
        fi
    elif [[ -f "$path" ]]; then
        if [[ ! -w "$path" ]]; then
            log "Error: No write permission for file: $path"
            exit 1
        fi
    else
        # Path does not exist yet, check parent directory
        local parent_dir
        parent_dir="$(dirname "$path")"
        if [[ ! -d "$parent_dir" ]]; then
            log "Error: Parent directory does not exist: $parent_dir"
            exit 1
        fi
        if [[ ! -w "$parent_dir" ]]; then
            log "Error: No write permission for parent directory: $parent_dir"
            exit 1
        fi
    fi
}

validate_pki_dir() {
    local pki_dir="$1"
    if [[ ! -d "${pki_dir}" || ! -f "${pki_dir}/index.txt" ]]; then
        log "Error: Invalid PKI directory - missing index.txt"
        exit 2
    fi
}

find_cert_file() {
    local cn="$1" pki_dir="$2"
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
    local cn="$1" pki_dir="$2" serial="$3"
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

# Ensure that unique_subject = no in pki/index.txt.attr
# This allows EasyRSA to issue/renew certificates with the same CN
# after revoking the previous one.
# Usage: ensure_unique_subject <pki_dir>
ensure_unique_subject() {
    local pki_dir="$1"
    local attr_file="${pki_dir}/index.txt.attr"

    if [[ ! -d "$pki_dir" ]]; then
        log "Error: PKI directory not found: $pki_dir"
        return 1
    fi

    if [[ ! -f "$attr_file" ]]; then
        echo "unique_subject = no" > "$attr_file"
        log "Created $attr_file with unique_subject = no"
    elif ! grep -q "^unique_subject[[:space:]]*=" "$attr_file"; then
        echo "unique_subject = no" >> "$attr_file"
        log "Added unique_subject = no to $attr_file"
    elif ! grep -q "^unique_subject[[:space:]]*=[[:space:]]*no[[:space:]]*$" "$attr_file"; then
        sed -i "s/^unique_subject[[:space:]]*=.*/unique_subject = no/" "$attr_file"
        log "Updated unique_subject to 'no' in $attr_file"
    fi
    # else: already correct, do nothing
}

#mlog "Script called with: $0 $@"
