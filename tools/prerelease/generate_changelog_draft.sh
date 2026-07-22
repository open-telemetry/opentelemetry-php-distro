#!/bin/bash

show_help() {
    echo "Usage: $0 --previous-release-tag <tag> [--target <branch_or_tag>]"
    echo
    echo "Options:"
    echo "  --previous-release-tag    The previous release tag (e.g., v0.2.0)."
    echo "  --target                  (Optional) Target branch or tag to compare against (default: main)."
    echo "  --github-token            (Optional) GitHub personal access token."
    echo "  -h, --help                Display this help message."
    exit 1
}

parse_args() {
    TARGET="main"  # Default target branch

    if [[ "$#" -lt 2 ]]; then
        show_help
    fi

    while [[ "$#" -gt 0 ]]; do
        case $1 in
            --previous-release-tag)
                if [[ -z "$2" || "$2" == -* ]]; then
                    echo "Error: --previous-release-tag requires a non-empty value."
                    show_help
                fi
                PREVIOUS_TAG="$2"
                shift 2
                ;;
            --target)
                if [[ -z "$2" || "$2" == -* ]]; then
                    echo "Error: --target requires a non-empty value."
                    show_help
                fi
                TARGET="$2"
                shift 2
                ;;
            --github-token)
                if [[ -z "$2" || "$2" == -* ]]; then
                    echo "Error: --github-token requires a non-empty value."
                    show_help
                fi
                GITHUB_TOKEN="$2"
                shift 2
                ;;
            -h|--help)
                show_help
                ;;
            *)
                echo "Unknown option: $1"
                show_help
                ;;
        esac
    done

    if [[ -z "$PREVIOUS_TAG" ]]; then
        echo "Error: --previous-release-tag is required."
        show_help
    fi
}

generate_issue_links() {
    local commit_message="$1"

    echo "$commit_message" | sed -E '
    s/\(#([0-9]+)\)/([#\1](https:\/\/github.com\/open-telemetry\/opentelemetry-php-distro\/issues\/\1))/g
    '
}

fetch_pr_for_commit() {
    local commit_hash="$1"
    local pr_response

    local auth_header=""
    if [[ -n "$GITHUB_TOKEN" ]]; then
        auth_header="-H Authorization: Bearer $GITHUB_TOKEN"
    fi

    pr_response=$(curl -s -H "Accept: application/vnd.github+json" $auth_header \
                        "https://api.github.com/repos/open-telemetry/opentelemetry-php-distro/commits/$commit_hash/pulls")

    echo "$pr_response" | jq -r 'if type == "array" then .[0] | if .html_url then "(PR [#\(.number)](\(.html_url)))" else "" end else "" end'
}

generate_otel_packages_section() {
    local packages=('open-telemetry/api' 'open-telemetry/sdk' 'open-telemetry/context')

    # Get sorted PHP versions
    local php_versions_raw=()
    read -ra php_versions_raw <<< "$(get_array ${_PROJECT_PROPERTIES_SUPPORTED_PHP_VERSIONS})"
    readarray -t php_versions < <(printf '%s\n' "${php_versions_raw[@]}" | sort -n)

    # Compute per-version fingerprint (concatenated package versions for tracked packages)
    local -a fingerprints=()
    for v in "${php_versions[@]}"; do
        local lock_file="$REPO_ROOT/generated_composer_lock_files/prod_${v}.lock"
        if [[ ! -f "$lock_file" ]]; then
            fingerprints+=("MISSING:${v}")
            continue
        fi
        local fp=""
        for pkg in "${packages[@]}"; do
            local ver
            ver=$(jq -r --arg n "$pkg" 'first(.packages[] | select(.name==$n)) | .version // "?"' "$lock_file")
            fp="${fp}:${ver}"
        done
        fingerprints+=("$fp")
    done

    # Group consecutive PHP versions that share the same fingerprint
    local -a group_starts=()
    local -a group_ends=()
    local cur_fp="${fingerprints[0]}"
    local cur_start=0

    for (( i=1; i<${#php_versions[@]}; i++ )); do
        if [[ "${fingerprints[$i]}" != "$cur_fp" ]]; then
            group_starts+=($cur_start)
            group_ends+=($((i-1)))
            cur_start=$i
            cur_fp="${fingerprints[$i]}"
        fi
    done
    group_starts+=($cur_start)
    group_ends+=($((${#php_versions[@]}-1)))

    echo "### This release is based on the following OpenTelemetry PHP packages:"
    echo

    local num_groups=${#group_starts[@]}

    for (( g=0; g<num_groups; g++ )); do
        local si=${group_starts[$g]}
        local ei=${group_ends[$g]}
        local start_v="${php_versions[$si]}"
        local end_v="${php_versions[$ei]}"
        local lock_file="$REPO_ROOT/generated_composer_lock_files/prod_${start_v}.lock"

        # Show PHP version range header only when versions differ across PHP releases
        if [[ $num_groups -gt 1 ]]; then
            local start_fmt="${start_v:0:1}.${start_v:1}"
            local end_fmt="${end_v:0:1}.${end_v:1}"
            if [[ "$start_v" == "$end_v" ]]; then
                echo "**PHP ${start_fmt}:**"
            else
                echo "**PHP ${start_fmt} - PHP ${end_fmt}:**"
            fi
            echo
        fi

        if [[ ! -f "$lock_file" ]]; then
            echo "_Could not find $lock_file — OTel package versions unavailable._"
            echo
            continue
        fi

        for pkg in "${packages[@]}"; do
            local ver
            ver=$(jq -r --arg n "$pkg" 'first(.packages[] | select(.name==$n)) | .version // empty' "$lock_file")
            if [[ -z "$ver" ]]; then
                echo "Warning: package $pkg not found in $lock_file" >&2
                continue
            fi
            echo "- [${pkg} ${ver}](https://packagist.org/packages/${pkg}#${ver})"
        done
        echo
    done
}

generate_changelog() {
    local previous_tag="$1"
    local target_branch_or_tag="$2"

    if [[ -z "$_PROJECT_PROPERTIES_VERSION" ]]; then
        echo "Error: could not read version from project.properties" >&2
        return 1
    fi

    echo "## ${_PROJECT_PROPERTIES_VERSION}"
    echo
    generate_otel_packages_section
    echo "### What's changed"
    echo

    git log "${previous_tag}..${target_branch_or_tag}" --oneline | while read -r line; do
        # Skip lines matching "github-action*"
        if [[ "$line" =~ github-action ]]; then
            continue
        fi

        # Extract commit hash and message
        commit_hash=$(echo "$line" | awk '{print $1}')
        commit_message=$(echo "$line" | cut -d' ' -f2-)

        commit_message_with_links=$(generate_issue_links "$commit_message")

        pr_link=$(fetch_pr_for_commit "$commit_hash")

        if [[ -n "$pr_link" ]]; then
            pr_number=$(echo "$pr_link" | grep -oE '[0-9]+' | head -1)
            commit_message_with_links=$(echo "$commit_message_with_links" | \
                sed "s|(\[#${pr_number}\]([^)]*issues/${pr_number}))||g" | \
                sed 's/  */ /g;s/ $//')
            commit_message_with_links="$commit_message_with_links $pr_link"
        fi

        echo "- $commit_message_with_links"
    done
}

main() {
    parse_args "$@"

    REPO_ROOT=$(git rev-parse --show-toplevel)
    source "$REPO_ROOT/tools/read_properties.sh"
    source "$REPO_ROOT/tools/helpers/array_helpers.sh"
    read_properties "$REPO_ROOT/project.properties" _PROJECT_PROPERTIES

    generate_changelog "$PREVIOUS_TAG" "$TARGET"
}

main "$@"
