#!/bin/sh
# Runs inside the image test container, on a php:<version>-fpm image.

printf 'Checking if extension is loaded in php-fpm: '
if ! php-fpm -m | grep -q '^opentelemetry_distro$'; then
    echo 'FAILED. opentelemetry_distro not in php-fpm -m'
    exit 1
fi
echo OK
