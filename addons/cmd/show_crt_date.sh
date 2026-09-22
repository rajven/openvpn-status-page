#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/config"
source "$SCRIPT_DIR/functions.sh"

show_usage() {
    echo "Usage: $0 <login> [pki_dir]"
    echo "Default pki_dir: /etc/openvpn/server/server/rsa/pki"
    exit 1
}

main() {
    [[ $# -lt 1 ]] && show_usage

    check_permissions

    # 1. Кавычки для защиты от word splitting
    local CN="$1"
    local PKI_DIR="${2:-/etc/openvpn/server/server/rsa/pki}"

    validate_pki_dir "${PKI_DIR}"

    local CERT_FILE
    CERT_FILE=$(find_cert_file "${CN}" "${PKI_DIR}") || {
        echo "${CN};NOT_FOUND;NOT_FOUND;ERROR;0"
        exit 3
    }
    
    # 2. Проверка, что файл действительно является валидным сертификатом
    if ! openssl x509 -in "${CERT_FILE}" -noout 2>/dev/null; then
        echo "${CN};ERROR;ERROR;INVALID_CERT;0"
        exit 3
    fi
    
    # 3. Получаем обе даты одним вызовом openssl
    # Разделяем объявление переменных и присваивание, чтобы set -e ловил ошибки openssl
    local DATES NOT_BEFORE NOT_AFTER
    DATES=$(openssl x509 -in "${CERT_FILE}" -noout -startdate -enddate)
    
    # Используем cut -f2-, чтобы корректно обработать строку, даже если в дате вдруг будет "="
    NOT_BEFORE=$(echo "$DATES" | grep "^notBefore=" | cut -d= -f2-)
    NOT_AFTER=$(echo "$DATES" | grep "^notAfter=" | cut -d= -f2-)
    
    # 4. Вычисляем статус и дни
    local NOW_EPOCH END_EPOCH DAYS STATUS
    NOW_EPOCH=$(date -u +%s)
    
    # Кроссплатформенное преобразование даты в epoch (GNU date vs BSD/macOS date)
    # Цепочка || гарантирует, что если первый date упадет, попробуется второй.
    # Если оба упадут, выполнится блок с ошибкой.
    END_EPOCH=$(date -u -d "${NOT_AFTER}" +%s 2>/dev/null) || \
    END_EPOCH=$(date -u -j -f "%b %d %T %Y %Z" "${NOT_AFTER}" +%s 2>/dev/null) || {
        echo "${CN};${NOT_BEFORE};${NOT_AFTER};DATE_PARSE_ERROR;0"
        exit 3
    }
    
    # 5. Точное сравнение эпох (исправляет баг с "0 дней")
    if [[ ${END_EPOCH} -lt ${NOW_EPOCH} ]]; then
        STATUS="EXPIRED"
        DAYS=$(( (NOW_EPOCH - END_EPOCH) / 86400 ))
    else
        STATUS="VALID"
        DAYS=$(( (END_EPOCH - NOW_EPOCH) / 86400 ))
    fi
    
    # Выводим в формате CSV
    echo "${CN};${NOT_BEFORE};${NOT_AFTER};${STATUS};${DAYS}"
    
    exit 0
}

main "$@"
