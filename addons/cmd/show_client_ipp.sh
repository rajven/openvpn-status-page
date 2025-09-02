#!/bin/bash

set -o pipefail

show_usage() {
    echo "Usage: $0 <ipp_file>"
    echo "Example: $0 /etc/openvpn/server/server/ipp.txt"
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
    local ipp_file=$1
    local expected_path="/etc/openvpn/server"

    # Проверяем что путь начинается с /etc/openvpn/server/
    if [[ ! "$ipp_file" =~ ^$expected_path/ ]]; then
        log "Error: IPP must be located under $expected_path/"
        log "Provided path: $ipp_file"
        exit 1
    fi

    # Дополнительная проверка: каталог должен существовать
    if [[ ! -e "$ipp_file" ]]; then
        log "Error: file does not exist: $ipp_file"
        exit 1
    fi
}

main() {
    # Проверка прав
    check_permissions

    # Обработка аргументов
    [[ $# -lt 1 ]] && show_usage

    local ipp_file=$1
    
    # Проверка пути CCD файла
    check_ccd_path "$ipp_file"

    #get client ips
    cat "${ipp_file}" | sed 's/,$//'

    exit 0
}

main "$@"
