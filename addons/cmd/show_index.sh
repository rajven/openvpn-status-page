#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

show_usage() {
    echo "Usage: $0 [path_to_index.txt]"
    exit 1
}

# Argument handling
[[ $# -lt 1 ]] && show_usage

index_txt="${1}"

ORIGINAL_USER="$SUDO_USER"
if [ -z "${ORIGINAL_USER}" ]; then
    ORIGINAL_USER='www-data'
    fi

[ -e "${index_txt}" ] && cat "${index_txt}" || exit 1

PKI_DIR=$(dirname "${index_txt}")  # /etc/openvpn/server/server/rsa/pki
RSA_DIR=$(dirname "${PKI_DIR}")    # /etc/openvpn/server/server/rsa

chown nobody:${ORIGINAL_USER} -R "$RSA_DIR/pki/issued/"
chmod 750 "${RSA_DIR}/pki/issued/"
chmod 640 "${RSA_DIR}"/pki/issued/*.crt

exit 0
