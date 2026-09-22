#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#SCRIPT_DIR="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
source "$SCRIPT_DIR/config"
source "$SCRIPT_DIR/functions.sh"

show_usage() {
    echo "Usage: $0 <ccd_file>"
    echo "Example: $0 /etc/openvpn/server/server/ccd/login"
    exit 1
}

main() {
    # Check permissions
    check_permissions

    # Process arguments
    [[ $# -lt 1 ]] && show_usage

    local ccd_file="$1"

    # Validate CCD file path
    check_ccd_path "$ccd_file"

    # Check if file exists before removal
    if [[ ! -f "$ccd_file" ]]; then
        log "CCD file not found (nothing to remove): $ccd_file"
        exit 0
    fi

    log "Removing CCD file: $ccd_file"
    rm -f "${ccd_file}"
    log "CCD file removed successfully: $ccd_file"
}

main "$@"
