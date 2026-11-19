#!/bin/sh
# Runs inside the test container after the distro is installed. Plain sh, for Alpine.

printf 'Checking for "copyright" files existence: '
for file in LICENSE NOTICE; do
    if [ ! -f "/opt/opentelemetry/php/distro/${file}" ]; then
        printf '\033[0;31mFAILED. %s not found\033[0;39m\n' "${file}"
        exit 1
    fi
done
printf '\033[0;32mOK\033[0;39m\n'
