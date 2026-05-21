#!/bin/bash

# transforms (x x x x) into a bash array
function get_array() {
    local VAR_ARR=(${*//[()]/})
    echo "${VAR_ARR[@]}"
}


# Get minimum value from array, assumes all values are integers
function get_array_min_value() {
    local VAR_ARR=(${*//[()]/})
    local MIN=${VAR_ARR[0]}
    for VALUE in "${VAR_ARR[@]}"; do
        (( VALUE < MIN )) && MIN=$VALUE
    done
    echo "$MIN"
}

# Get maximum value from array, assumes all values are integers
function get_array_max_value() {
    local VAR_ARR=(${*//[()]/})
    local MAX=${VAR_ARR[0]}
    for VALUE in "${VAR_ARR[@]}"; do
        (( VALUE > MAX )) && MAX=$VALUE
    done
    echo "$MAX"
}

# Get value from array that is one before the maximum in sorted order, assumes all values are integers
function get_one_before_highest_value_from_array() {
    local -a -r _ARR=(${*//[()]/})
    # Sort numerically - by default, sort treats values as strings (e.g., "10" comes before "2").
    # Use the -n flag to sort numbers correctly.
    readarray -t _ARR_SORTED < <(printf '%s\n' "${_ARR[@]}" | sort -n)

    echo "${_ARR_SORTED[${#_ARR_SORTED[@]}-2]}"
}

function is_value_in_array () {
    # The first argument is the element that should be in array
    local value_to_check="${1:?}"
    # The rest of the arguments is the array
    local -a array=("${@:2}")

    for current_value in "${array[@]}"; do
        if [ "${value_to_check}" == "${current_value}" ] ; then
            echo "true"
            return
        fi
    done
    echo "false"
}
