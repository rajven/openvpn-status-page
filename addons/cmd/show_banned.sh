#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#SCRIPT_DIR="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
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

    local ccd_dir=$1

    # Validate CCD directory path
    check_ccd_path "$ccd_dir"

    # Get banned users
    egrep -R "^disable$" "${ccd_dir}"/* | sed 's#.*/##; s/:.*//'

    exit 0
}

main "$@"
