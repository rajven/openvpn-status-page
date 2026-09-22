#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/config"
source "$SCRIPT_DIR/functions.sh"

show_usage() {
    echo "Usage: $0 <fullpath_user_ccd_file> [input_file|- for stdin]"
    echo "Example: $0 /etc/openvpn/server/server/ccd/user1 -"
    exit 1
}

main() {
    # Check permissions
    check_permissions

    # Process arguments (ровно 2 аргумента)
    [[ $# -ne 2 ]] && show_usage

    # Кавычки для защиты от пробелов в путях
    local ccd_file="$1"
    local ccd_dir
    ccd_dir=$(dirname "$ccd_file")

    local input_file="$2"

    # Validate CCD directory path (проверяет права на запись)
    check_ccd_path "$ccd_dir"

    # Write config
    if [[ "$input_file" == "-" ]]; then
        # Read from stdin
        # cat прервется с ошибкой, если возникнут проблемы с записью, и set -e это поймает
        cat > "$ccd_file"
    else
        # Copy from existing file
        if [[ ! -f "$input_file" ]]; then
            log "Error: Input file not found: $input_file"
            exit 1
        fi
        # rm -f не упадет, если файла нет, поэтому предварительная проверка не нужна
        rm -f "$ccd_file"
        cp "$input_file" "$ccd_file"
    fi

    # Устанавливаем права и владельца
    chmod 660 "$ccd_file"
    chown "${owner_user}:${owner_group}" "$ccd_file"

    log "Config saved to $ccd_file"
    exit 0
}

main "$@"
