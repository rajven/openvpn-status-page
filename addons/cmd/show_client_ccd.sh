#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

show_usage() {
    echo "Usage: $0 <ccd_dir>"
    echo "Example: $0 /etc/openvpn/server/server/ccd"
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
        log "Error: CCD must be located under $expected_path/"
        log "Provided path: $ccd_file"
        exit 1
    fi

    # Дополнительная проверка: каталог должен существовать
    if [[ ! -d "$ccd_file" ]]; then
        log "Error: file does not exist: $ccd_file"
        exit 1
    fi
}

main() {
    # Проверка прав
    check_permissions

    # Обработка аргументов
    [[ $# -lt 1 ]] && show_usage

    local ccd_dir=$1
    
    # Проверка пути CCD файла
    check_ccd_path "$ccd_dir"

    #get client ips
    egrep "^ifconfig-push\s+" "${ccd_dir}"/* | sed 's|.*/||; s/:ifconfig-push / /; s/\([^ ]* [^ ]*\).*/\1/'

}

main "$@"
