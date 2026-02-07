#!/bin/bash

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#SCRIPT_DIR="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
source "$SCRIPT_DIR/functions.sh"

show_usage() {
    echo "Usage: $0 <fullpath_user_ccd_file>"
    echo "Example: $0 /etc/openvpn/server/server/ccd/user1"
    exit 1
}

main() {
    # Check permissions
    check_permissions

    # Process arguments
    [[ $# -lt 1 ]] && show_usage

    local ccd_file=$1

    # Validate CCD directory path
    check_ccd_path "$ccd_file"

    cat "${ccd_file}"

    exit 0
}

main "$@"
