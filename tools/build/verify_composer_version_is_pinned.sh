#!/usr/bin/env bash
#
# Verifies that the Composer release used by the build and test tooling is specified in exactly one
# place: composer_version in project.properties.
#
# This exists because an unpinned Composer broke every job in this repository without a single
# commit touching PHP dependencies: Composer 2.11.0 changed how the lock content hash is computed,
# so the newest release started rejecting the committed lock files with "The lock file is not up to
# date with the latest changes in composer.json". Pinning fixes that only for as long as the pin
# stays singular -- a second copy of the version, or one acquisition site that slips back to
# whatever is current, reintroduces the same failure silently.
#
# Run from the repository root.

set -euo pipefail

this_script_name="$(basename "${BASH_SOURCE[0]}")"
failures=0

function fail () {
    echo "ERROR: $1" >&2
    shift
    while [ $# -gt 0 ]; do
        echo "       $1" >&2
        shift
    done
    failures=$((failures + 1))
}

# Files that legitimately contain Composer version strings that are not the pin: the generated lock
# files record the versions of the packages they lock, and this script documents the pin in prose.
function tooling_files () {
    git ls-files -- \
        'tools/**' 'docker/**' '.github/**' \
        ':(exclude)tools/build/'"${this_script_name}" \
        ':(exclude)generated_composer_lock_files/**'
}

echo "Verifying that the Composer version is pinned in exactly one place..."

# 1. The pin exists and is a concrete release.
source "$(git rev-parse --show-toplevel)/tools/read_properties.sh"
read_properties "$(git rev-parse --show-toplevel)/project.properties" _PROJECT_PROPERTIES
composer_version="${_PROJECT_PROPERTIES_COMPOSER_VERSION:-}"

if [[ -z "${composer_version}" ]]; then
    fail "composer_version is not set in project.properties."
elif [[ ! "${composer_version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    fail "composer_version in project.properties is '${composer_version}'," \
         "which is not a concrete release such as 2.8.12."
else
    echo "  composer_version in project.properties: ${composer_version}"
fi

# 2. No other build or test file repeats the version. This is the property the whole thing rests
#    on: one place to change, so a bump cannot half-land.
if [[ -n "${composer_version}" ]]; then
    duplicates="$(tooling_files | xargs -r grep -Fn -- "${composer_version}" || true)"
    if [[ -n "${duplicates}" ]]; then
        fail "The Composer version is repeated outside project.properties:" "${duplicates}" \
             "Read it from project.properties instead (see read_properties.sh)."
    fi
fi

# 3. Every Dockerfile takes the version as a required build arg. A default would be a second place
#    to keep in sync, and it would silently win whenever a caller forgot to pass the value.
defaults="$(tooling_files | xargs -r grep -n 'ARG[[:space:]]\+COMPOSER_VERSION[[:space:]]*=' || true)"
if [[ -n "${defaults}" ]]; then
    fail "COMPOSER_VERSION must not have a default value:" "${defaults}" \
         "Declare it as a bare 'ARG COMPOSER_VERSION' and pass it with --build-arg."
fi

# 4. No image reference pins Composer by a literal tag, which would bypass the property entirely.
#    composer:latest is how the component test images silently tracked the newest release.
image_literals="$(tooling_files | xargs -r grep -nE 'composer:(latest|[0-9])' || true)"
if [[ -n "${image_literals}" ]]; then
    fail "Composer image references must use \${COMPOSER_VERSION}:" "${image_literals}"
fi

# 5. Every use of the official installer passes --version. Without it the installer takes whatever
#    release is current, which is the behaviour this check exists to prevent.
while IFS= read -r file; do
    if grep -q 'getcomposer\.org/installer' "${file}" && ! grep -q -- '--version=' "${file}"; then
        fail "${file} downloads the Composer installer without passing --version."
    fi
done < <(tooling_files)

# 6. Composer is never taken from a distribution package, which tracks the newest release. Fedora's
#    package, for one, currently resolves to 2.11.1. Covers both the single-line form and a bare
#    'composer' entry in a multi-line package list.
pkg_installs="$(tooling_files | xargs -r grep -nE \
    '(dnf|yum|apk|apt-get)([^|;]*)\binstall\b([^|;]*)\bcomposer\b' || true)"
pkg_list_entries="$(git ls-files -- '**/Dockerfile*' | xargs -r grep -nE '^[[:space:]]*composer[[:space:]]*\\?[[:space:]]*$' || true)"
if [[ -n "${pkg_installs}" || -n "${pkg_list_entries}" ]]; then
    fail "Composer must not be installed from a distribution package:" \
         "${pkg_installs}${pkg_list_entries}" \
         "Use the official installer with --version=\${COMPOSER_VERSION}."
fi

if [[ "${failures}" -ne 0 ]]; then
    echo >&2
    echo "${failures} check(s) failed." >&2
    exit 1
fi

echo "OK: the Composer version is specified only by composer_version in project.properties."
