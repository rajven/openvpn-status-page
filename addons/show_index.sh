#!/bin/bash

set -o errexit
set -o nounset
set -o pipefail

show_usage() {
    echo "Usage: $0 [path_to_index.txt]"
    exit 1
}

# Argument handling
[[ $# -lt 1 ]] && show_usage

[ -e "${1}" ] && cat "${1}"

exit 0
