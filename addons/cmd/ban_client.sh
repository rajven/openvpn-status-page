#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

log() {
    logger -t "openvpn-ban" -p user.info "$1"
    echo "$1"  # Также выводим в консоль для обратной связи
}

show_usage() {
    echo "Usage: $0 <ccd_file> <ban|unban>"
    echo "Example: $0 /etc/openvpn/server/server/ccd/login ban"
    exit 1
}

# Проверка прав
check_permissions() {
    if [[ $EUID -ne 0 ]]; then
        log "Error: This script must be run as root" >&2
        exit 1
    fi
}

# Проверка что CCD файл находится в правильном пути
check_ccd_path() {
    local ccd_file=$1
    local expected_path="/etc/openvpn/server"
    
    # Проверяем что путь начинается с /etc/openvpn/server/
    if [[ ! "$ccd_file" =~ ^$expected_path/ ]]; then
        log "Error: CCD file must be located under $expected_path/"
        log "Provided path: $ccd_file"
        exit 1
    fi
    
    # Дополнительная проверка: файл должен существовать
    if [[ ! -f "$ccd_file" ]]; then
        log "Error: CCD file does not exist: $ccd_file"
        exit 1
    fi
    
    # Проверка прав на запись
    if [[ ! -w "$ccd_file" ]]; then
        log "Error: No write permission for CCD file: $ccd_file"
        exit 1
    fi
}

main() {
    # Проверка прав
    check_permissions

    # Обработка аргументов
    [[ $# -lt 2 ]] && show_usage

    local ccd_file=$1
    local action=$2
    
    # Проверка пути CCD файла
    check_ccd_path "$ccd_file"

    local username=$(basename "${ccd_file}")

    touch "${ccd_file}"
    chmod 640 "${ccd_file}"
    chown nobody:nogroup "${ccd_file}"

    if grep -q "^disable$" "$ccd_file"; then
        is_banned="disable"
    else
        is_banned=""
    fi

    case "$action" in
        ban)
            if [[ -z "$is_banned" ]]; then
                log "Ban user: ${username}"
		sed -i '1i\disable' "${ccd_file}"
                log "User ${username} banned successfully"
            else
                log "User ${username} is already banned"
            fi
            ;;
        unban)
            if [[ -n "$is_banned" ]]; then
                log "UnBan user: ${username}"
                sed -i '/^disable$/d' "${ccd_file}"
                log "User ${username} unbanned successfully"
            else
                log "User ${username} is not banned"
            fi
            ;;
        *)
            log "Error: Invalid action. Use 'ban' or 'unban'" >&2
            show_usage
            ;;
    esac

    exit 0
}

main "$@"
