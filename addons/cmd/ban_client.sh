#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#SCRIPT_DIR="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
source "$SCRIPT_DIR/functions.sh"

show_usage() {
    echo "Usage: $0 <ccd_file> <ban|unban>"
    echo "Example: $0 /etc/openvpn/server/server/ccd/login ban"
    exit 1
}

main() {
    # Check permissions
    check_permissions

    # Process arguments
    [[ $# -lt 2 ]] && show_usage

    local ccd_file=$1
    local action=$2

    # Validate CCD file path
    check_ccd_path "$ccd_file"

    local username
    username=$(basename "${ccd_file}")

    touch "${ccd_file}"
    chmod 660 "${ccd_file}"
    chown ${owner_user}:${owner_group} "${ccd_file}"

    local is_banned=""
    if grep -q "^disable$" "$ccd_file"; then
        is_banned="disable"
    fi

    case "$action" in
        ban)
            if [[ -z "$is_banned" ]]; then
                log "Ban user: ${username}"
                echo -e "disable\n$(cat "$ccd_file")" > "$ccd_file"
                log "User ${username} banned successfully"
            else
                log "User ${username} is already banned"
            fi
            ;;
        unban)
            if [[ -n "$is_banned" ]]; then
                log "Unban user: ${username}"
                sed -i '/^disable$/d' "${ccd_file}"
                log "User ${username} unbanned successfully"
                # if the file is empty or only blank lines, we erase it.
                if [[ ! -s "${ccd_file}" ]] || ! grep -q '[^[:space:]]' "${ccd_file}"; then
                    log "CCD file ${ccd_file} is empty after unban, removing"
                    rm -f "${ccd_file}"
                fi
            else
                log "User ${username} is not banned"
            fi
            ;;
        *)
            log "Error: Invalid action. Use 'ban' or 'unban'"
            show_usage
            ;;
    esac

    exit 0
}

main "$@"
