#!/bin/bash

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#SCRIPT_DIR="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
source "$SCRIPT_DIR/config"
source "$SCRIPT_DIR/functions.sh"

if [ "$#" -lt 2 ]; then
    echo "Usage: $0 <rsa_dir> <username>"
    exit 1
fi

check_permissions

RSA_DIR="$1"
USERNAME="$2"

# Check that the PKI directory exists
if [ ! -d "$RSA_DIR" ]; then
    log "PKI directory not found: $RSA_DIR"
    exit 1
fi

# Check that easyrsa exists
if [ ! -f "$RSA_DIR/easyrsa" ]; then
    log "easyrsa not found in $RSA_DIR"
    exit 1
fi

# Check whether the user already exists
if [ -f "$RSA_DIR/pki/index.txt" ] && grep -qP "CN=${USERNAME}(\s|$)" "$RSA_DIR/pki/index.txt"; then
    log "User $USERNAME already exists"
    exit 1
fi

# Change to the PKI directory and create the client
cd "$RSA_DIR" || exit 1

# Ensure unique_subject = no (allows reissuing certs with same CN)
ensure_unique_subject "${RSA_DIR}/pki" || exit 1

# Generate client key and certificate in batch mode (no prompts)
if ./easyrsa --batch build-client-full "$USERNAME" nopass; then
    log "User $USERNAME created successfully"

    # Set ownership and permissions only for the created files
    chown "${owner_user}:${owner_group}" "${RSA_DIR}/pki/issued/${USERNAME}.crt"
    chmod 644 "${RSA_DIR}/pki/issued/${USERNAME}.crt"

    chown "${owner_user}:${owner_group}" "${RSA_DIR}/pki/private/${USERNAME}.key"
    chmod 600 "${RSA_DIR}/pki/private/${USERNAME}.key"

    exit 0
else
    log "Failed to create user $USERNAME"
    exit 1
fi
