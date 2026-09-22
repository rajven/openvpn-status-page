#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#SCRIPT_DIR="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
source "$SCRIPT_DIR/config"
source "$SCRIPT_DIR/functions.sh"

show_usage() {
    echo "Usage: $0 <ipp_file>"
    echo "Example: $0 /etc/openvpn/server/server/ipp.txt"
    exit 1
}

main() {
    # Check permissions
    check_permissions

    # Process arguments
    [[ $# -lt 1 ]] && show_usage

    local ipp_file="$1"

    # Validate path (checks write permissions, which implies existence)
    check_ccd_path "$ipp_file"

    # Explicit check: ensure it's a readable file
    # check_ccd_path does not exit if the file simply doesn't exist
    if [[ ! -f "$ipp_file" || ! -r "$ipp_file" ]]; then
        log "Error: IPP file not found or not readable: $ipp_file"
        exit 1
    fi

    # Check if file is empty to avoid silent success on empty files
    if [[ ! -s "$ipp_file" ]]; then
        log "IPP file is empty: $ipp_file"
        exit 0
    fi

    # Get client IPs (remove trailing commas)
    sed 's/,$//' "$ipp_file"

    exit 0
}

main "$@"
