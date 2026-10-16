#!/usr/bin/env bash
#
# Deploy ancillary repository webpages to user space (html/user).
#

set -e

DEPLOYDIR="/storage/www/cpdnboinc/html/user"
REPODIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

deploy_file() {
    local target_dir="$1"
    local repo_file="$2"
    local deployed_file="$target_dir/$(basename -- "$repo_file")"
    local compare_status

    if [[ ! -f "$repo_file" ]]; then
        printf 'Cannot deploy: repo file is missing or not a regular file: %s\n' "$repo_file" >&2
        return 1
    fi

    if [[ ! -d "$target_dir" ]]; then
        printf 'Creating deployment directory: %s\n' "$target_dir"
        mkdir -p -- "$target_dir"
    fi

    if [[ ! -e "$deployed_file" && ! -L "$deployed_file" ]]; then
        cp -- "$repo_file" "$deployed_file"
        printf 'Deployed new file: %s\n' "$deployed_file"
        return 0
    fi

    if cmp -s -- "$repo_file" "$deployed_file"; then
        printf 'Identical; no copy: %s\n' "$deployed_file"
        return 0
    else
        compare_status=$?
        if (( compare_status > 1 )); then
            printf 'Cannot compare files; no copy: %s and %s\n' "$repo_file" "$deployed_file" >&2
            return "$compare_status"
        fi
    fi

    if [[ "$deployed_file" -nt "$repo_file" ]]; then
        printf 'Deployed copy differs and is newer; no copy: %s\n' "$deployed_file"
    elif [[ "$repo_file" -nt "$deployed_file" ]]; then
        cp -- "$repo_file" "$deployed_file"
        printf 'Updated older deployed copy from repo: %s\n' "$deployed_file"
    else
        printf 'Copies differ but timestamps are equal; no copy: %s\n' "$deployed_file"
    fi
}

echo "Deploying OIFS ancillary repository webpages into position on live server.."

deploy_files=(
    oifs_upload_form.php
    oifs_upload_handler.php
)

for file in "${deploy_files[@]}"
do
    deploy_file "$DEPLOYDIR" "$REPODIR/$file"
done
