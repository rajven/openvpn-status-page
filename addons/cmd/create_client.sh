#!/bin/bash

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#SCRIPT_DIR="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
source "$SCRIPT_DIR/functions.sh"

if [ "$#" -ne 2 ]; then
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
if [ -f "$RSA_DIR/pki/index.txt" ] && grep -q "CN=$USERNAME" "$RSA_DIR/pki/index.txt"; then
    log "User $USERNAME already exists"
    exit 1
fi

# Change to the PKI directory and create the client
cd "$RSA_DIR" || exit 1

# Generate client key and certificate in batch mode (no prompts)
./easyrsa --batch build-client-full "$USERNAME" nopass

if [ $? -eq 0 ]; then
    log "User $USERNAME created successfully"

    chown ${owner_user}:${owner_group} -R "$RSA_DIR/pki/issued/"
    chmod 660 "${RSA_DIR}/pki/issued/"*.crt

    chown ${owner_user}:${owner_group} -R "$RSA_DIR/pki/private/"
    chmod 660 "${RSA_DIR}/pki/private/"*.key

    exit 0
else
    echo "Failed to create user $USERNAME"
    exit 1
fi

exit 0
