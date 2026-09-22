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

    # We output only the “IP name”, without the mask, without headers
    # We use awk instead of egrep + sed for reliability
    local result
    result=$(awk '
        /^ifconfig-push[[:space:]]+/ {
            n = split(FILENAME, parts, "/")
            username = parts[n]
            print username, $2
        }
    ' "${ccd_dir}"/* 2>/dev/null || true)

    if [[ -n "$result" ]]; then
        echo "$result"
    fi

    exit 0
}

main "$@"
