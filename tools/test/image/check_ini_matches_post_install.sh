#!/bin/sh
# Runs inside the image test container. Checks that the image's prebuilt ini holds the
# same settings that packaging/scripts/post-install.sh writes on a package install.
# Run it last: post-install.sh overwrites the ini and the links in conf.d.
set -e

ini=/opt/opentelemetry/php/distro/etc/opentelemetry-distro.ini
work_dir=$(mktemp -d)

settings() {
    grep -v -e '^;' -e '^[[:space:]]*$' "$1"
}

settings "${ini}" > "${work_dir}/image"
if ! bash /source/packaging/scripts/post-install.sh configure > "${work_dir}/post-install.log" 2>&1; then
    cat "${work_dir}/post-install.log"
    exit 1
fi
settings "${ini}" > "${work_dir}/post-install"

printf 'Checking the image ini matches post-install.sh: '
if ! diff -u "${work_dir}/image" "${work_dir}/post-install"; then
    echo 'FAILED. Update packaging/image/opentelemetry-distro.ini'
    exit 1
fi
echo OK
