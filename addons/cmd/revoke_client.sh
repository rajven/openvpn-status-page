#!/bin/bash

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#SCRIPT_DIR="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
source "$SCRIPT_DIR/functions.sh"

if [ "$#" -ne 3 ]; then
    log "Usage: $0 <service_name> <rsa_dir> <username>"
    exit 1
fi

check_permissions

SRV_NAME="${1}"
RSA_DIR="${2}"
USERNAME="${3}"

log "Starting certificate revocation for $USERNAME by user $ORIGINAL_USER"

# Check that the RSA directory exists
if [ ! -d "$RSA_DIR" ]; then
    log "Error: RSA directory not found: $RSA_DIR"
    exit 1
fi

# Change to the RSA directory
cd "$RSA_DIR" || exit 1

# Check that easyrsa exists
if [ ! -f "./easyrsa" ]; then
    log "Error: easyrsa not found in $RSA_DIR"
    exit 1
fi

# Check that the certificate exists
if [ ! -f "./pki/issued/${USERNAME}.crt" ]; then
    log "Error: Certificate for user $USERNAME not found"
    exit 1
fi

# Check whether the certificate is already revoked
if grep -q "/CN=${USERNAME}" ./pki/index.txt | grep -q "R"; then
    log "Error: Certificate for $USERNAME is already revoked"
    exit 1
fi

# Revoke the certificate
log "Revoking certificate for user: $USERNAME"
./easyrsa --batch revoke "$USERNAME"

if [ $? -eq 0 ]; then
    log "Successfully revoked certificate for $USERNAME"

    # Generate CRL (Certificate Revocation List)
    log "Generating CRL..."
    ./easyrsa --batch gen-crl

    if [ $? -eq 0 ]; then
        log "CRL generated successfully"

        chown ${owner_user}:${owner_group} -R "$RSA_DIR/pki/issued/"
        chown ${owner_user}:${owner_group} "$RSA_DIR/pki/crl.pem"
        chmod 660 "${RSA_DIR}/pki/issued/"*.crt

        # Restart the service
        log "Restarting service: $SRV_NAME"
        systemctl restart "${SRV_NAME}"

        if [ $? -eq 0 ]; then
            log "Service $SRV_NAME restarted successfully"
            log "Certificate revocation completed for $USERNAME"
            exit 0
        else
            log "Error: Failed to restart service $SRV_NAME"
            exit 1
        fi
    else
        log "Error: Failed to generate CRL"
        exit 1
    fi
else
    log "Error: Failed to revoke certificate for $USERNAME"
    exit 1
fi

exit 0
