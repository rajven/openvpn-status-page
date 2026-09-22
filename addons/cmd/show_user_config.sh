#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#SCRIPT_DIR="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
source "$SCRIPT_DIR/config"
source "$SCRIPT_DIR/functions.sh"

show_usage() {
    echo "Usage: $0 <fullpath_user_ccd_file>"
    echo "Example: $0 /etc/openvpn/server/server/ccd/user1"
    exit 1
}

main() {
    # Check permissions
    check_permissions

    # Process arguments (ровно один аргумент)
    [[ $# -ne 1 ]] && show_usage

    # Кавычки обязательны для защиты от пробелов в путях
    local ccd_file="$1"
    local ccd_dir
    ccd_dir=$(dirname "$ccd_file")

    # Validate CCD directory path
    check_ccd_path "$ccd_dir"

    # Проверяем, что это именно файл
    if [[ -f "$ccd_file" ]]; then
        cat "${ccd_file}"
    fi

    exit 0
}

main "$@"
