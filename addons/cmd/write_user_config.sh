#!/bin/bash

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/functions.sh"

show_usage() {
    echo "Usage: $0 <fullpath_user_ccd_file> [input_file|- for stdin]"
    echo "Example: $0 /etc/openvpn/server/server/ccd/user1 -"
    exit 1
}

main() {
    # Check permissions
    check_permissions

    # Process arguments
    [[ $# -lt 2 ]] && show_usage

    local ccd_file=$1
    local ccd_dir=$(dirname $ccd_file)

    local input_file=$2

    # Validate CCD directory path
    check_ccd_path "$ccd_dir"

    # Write config
    if [[ "$input_file" == "-" ]]; then
        # Read from stdin
        cat > "$ccd_file"
    else
        # Copy from existing file
        if [ -e "$ccd_file" ]; then
            rm -f "$ccd_file"
            fi
        cp "$input_file" "$ccd_file"
    fi

    chmod 660 "$ccd_file"
    chown ${owner_user}:${owner_group} "$ccd_file"

    log "Config saved to $ccd_file"
    exit 0
}

main "$@"
