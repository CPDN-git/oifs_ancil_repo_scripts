#!/usr/bin/env bash
#
# Deploy ancillary repository webpages to user space (html/user/project).
#

set -e

DEPLOYDIR="/storage/www/cpdnboinc/html/user/project"
PROJECTDIR="/storage/www/cpdnboinc/html/project"
REPODIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

deploy_file() {
    local target_dir="$1"
    local repo_file="$2"
    # Project-specific .inc files belong outside the public webpage directory.
    if [[ "$repo_file" == *.inc ]]; then
        target_dir="$PROJECTDIR"
    fi
    local deployed_file="$target_dir/$(basename -- "$repo_file")"
    local compare_status
    local link_target

    if [[ ! -f "$repo_file" ]]; then
        printf 'Cannot deploy: repo file is missing or not a regular file: %s\n' "$repo_file" >&2
        return 1
    fi

    # Never copy through, replace or change permissions on a destination symlink.
    if [[ -L "$deployed_file" ]]; then
        printf 'Deployment stopped: destination is a symbolic link: %s\n' "$deployed_file" >&2
        if link_target=$(readlink -- "$deployed_file"); then
            printf 'Link target: %s\n' "$link_target" >&2
        else
            printf 'Unable to read the link target; check permissions.\n' >&2
        fi
        if [[ ! -e "$deployed_file" ]]; then
            printf 'The link target is missing or inaccessible.\n' >&2
        fi
        printf 'Link and target left untouched. Resolve the link manually, then rerun deployment.\n' >&2
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

    # Suppress normal difference output, but retain stderr explaining read errors.
    if cmp -- "$repo_file" "$deployed_file" >/dev/null; then
        printf 'Identical; no copy: %s\n' "$deployed_file"
        return 0
    else
        compare_status=$?
        if (( compare_status > 1 )); then
            printf 'Deployment stopped: cannot compare files; no copy: %s and %s\n' "$repo_file" "$deployed_file" >&2
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

project_includes=(
    oifs_ancil_web_common.inc
)

deploy_files=(
    oifs_upload_form.php
    oifs_upload_handler.php
    oifs_search.php
    oifs_ancil_details.php
)

for include_file in "${project_includes[@]}"
do
    deploy_file "$PROJECTDIR" "$REPODIR/$include_file"
done

for file in "${deploy_files[@]}"
do
    deploy_file "$DEPLOYDIR" "$REPODIR/$file"
done
