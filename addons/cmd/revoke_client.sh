#!/bin/bash

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#SCRIPT_DIR="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
source "$SCRIPT_DIR/config"
source "$SCRIPT_DIR/functions.sh"

if [ "$#" -ne 3 ]; then
    echo "Usage: $0 <service_name> <rsa_dir> <username>"
    exit 1
fi

check_permissions

SRV_NAME="$1"
RSA_DIR="$2"
USERNAME="$3"

log "Starting certificate revocation for $USERNAME by user ${SUDO_USER:-$USER}"

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
if grep -qP "^R.*CN=${USERNAME}(\s|$)" ./pki/index.txt; then
    log "Error: Certificate for $USERNAME is already revoked"
    exit 1
fi

# Ensure unique_subject = no (allows reissuing certs with same CN after revocation)
ensure_unique_subject "${RSA_DIR}/pki" || exit 1

# Revoke the certificate
log "Revoking certificate for user: $USERNAME"
if ! ./easyrsa --batch revoke-issued "$USERNAME"; then
    log "Error: Failed to revoke certificate for $USERNAME"
    exit 1
fi
log "Successfully revoked certificate for $USERNAME"

# Generate CRL (Certificate Revocation List)
log "Generating CRL..."
if ! ./easyrsa --batch gen-crl; then
    log "Error: Failed to generate CRL"
    exit 1
fi
log "CRL generated successfully"

# Set ownership and permissions for CRL
chown "${owner_user}:${owner_group}" "$RSA_DIR/pki/crl.pem"
chmod 644 "$RSA_DIR/pki/crl.pem"

# Restart the service
log "Restarting service: $SRV_NAME"
if ! systemctl restart "${SRV_NAME}"; then
    log "Error: Failed to restart service $SRV_NAME"
    exit 1
fi
log "Service $SRV_NAME restarted successfully"

log "Certificate revocation completed for $USERNAME"
exit 0
