#!/bin/bash

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#SCRIPT_DIR="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
source "$SCRIPT_DIR/functions.sh"

if [ "$#" -lt 2 ]; then
    echo "Usage: $0 <rsa_dir> <username> [--force]"
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

FORCE=0
if [ "$3" == "--force" ]; then
    FORCE=1
fi

# Check whether the user already exists
if [ -f "$RSA_DIR/pki/index.txt" ] && grep -q "CN=$USERNAME" "$RSA_DIR/pki/index.txt"; then
    if [ $FORCE -eq 1 ]; then
        log "User $USERNAME exists, revoking and recreating..."
        cd "$RSA_DIR" || exit 1
        ./easyrsa --batch revoke "$USERNAME"
        ./easyrsa --batch gen-crl

        log "Removing old certificate files for $USERNAME..."

        if [ -f "$RSA_DIR/pki/issued/${USERNAME}.crt" ]; then
            rm -f "$RSA_DIR/pki/issued/${USERNAME}.crt"
            log "Removed: $RSA_DIR/pki/issued/${USERNAME}.crt"
        fi

        if [ -f "$RSA_DIR/pki/private/${USERNAME}.key" ]; then
            rm -f "$RSA_DIR/pki/private/${USERNAME}.key"
            log "Removed: $RSA_DIR/pki/private/${USERNAME}.key"
        fi

        if [ -f "$RSA_DIR/pki/reqs/${USERNAME}.req" ]; then
            rm -f "$RSA_DIR/pki/reqs/${USERNAME}.req"
            log "Removed: $RSA_DIR/pki/reqs/${USERNAME}.req"
        fi

        if [ -f "$RSA_DIR/pki/inline/${USERNAME}.inline" ]; then
            rm -f "$RSA_DIR/pki/inline/${USERNAME}.inline"
            log "Removed: $RSA_DIR/pki/inline/${USERNAME}.inline"
        fi
    else
        log "User $USERNAME already exists (use --force to renew)"
        exit 1
    fi
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
