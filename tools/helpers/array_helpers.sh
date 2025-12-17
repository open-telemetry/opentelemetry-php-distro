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



