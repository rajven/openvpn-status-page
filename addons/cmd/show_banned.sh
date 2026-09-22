#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#SCRIPT_DIR="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
source "$SCRIPT_DIR/config"
source "$SCRIPT_DIR/functions.sh"

show_usage() {
    echo "Usage: $0 <ccd_dir>"
    echo "Example: $0 /etc/openvpn/server/server/ccd"
    exit 1
}

main() {
    # Check permissions
    check_permissions

    # Process arguments
    [[ $# -lt 1 ]] && show_usage

    local ccd_dir="$1"

    # Validate CCD directory path
    check_ccd_path "$ccd_dir"

    # Get banned users
    # 1. grep -E replaces deprecated egrep
    # 2. grep -l outputs only the filenames of matching files (e.g., /path/to/ccd/user1)
    # 3. 2>/dev/null hides errors if the directory is empty
    # 4. awk -F/ '{print $NF}' extracts just the filename (basename)
    # 5. || true prevents 'set -e' from killing the script if grep finds no matches (exit code 1)
    local banned_list
    banned_list=$(grep -E -l "^disable$" "${ccd_dir}"/* 2>/dev/null | awk -F/ '{print $NF}' || true)

    if [[ -z "$banned_list" ]]; then
        log "No banned users found in ${ccd_dir}"
    else
        echo "$banned_list"
    fi

    exit 0
}

main "$@"
