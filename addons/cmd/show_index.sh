#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/config"
source "$SCRIPT_DIR/functions.sh"

show_usage() {
    echo "Usage: $0 <path_to_index.txt>"
    echo "Example: $0 /etc/openvpn/server/server/rsa/pki/index.txt"
    exit 1
}

main() {
    # Аргумент обязателен, проверяем что передан ровно 1 аргумент
    [[ $# -ne 1 ]] && show_usage

    check_permissions

    local index_txt="$1"
    local PKI_DIR

    # Получаем директорию из пути к файлу
    PKI_DIR=$(dirname "${index_txt}")

    # validate_pki_dir проверит, что директория существует и в ней есть index.txt
    # Если файла нет, функция сама сделает exit с кодом 2
    validate_pki_dir "${PKI_DIR}"

    # Выводим содержимое (файл гарантированно существует благодаря validate_pki_dir)
    cat "${index_txt}"
}

main "$@"
