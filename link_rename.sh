#!/bin/bash

DIR=$1
OLD_PATTERN=$2
NEW_PATTERN=$3

find "$DIR" -type l | while read -r line; do
    echo "Processing: $line"
    CUR_LINK_PATH="$(readlink "$line")"
    if [ -z "$CUR_LINK_PATH" ]; then
        echo "Error: Unable to read link target for $line"
        continue
    fi
    NEW_LINK_PATH="${CUR_LINK_PATH/$OLD_PATTERN/$NEW_PATTERN}"
    if [ "$CUR_LINK_PATH" != "$NEW_LINK_PATH" ]; then
        rm "$line"
        if [ $? -ne 0 ]; then
            echo "Error: Failed to remove $line"
            continue
        fi
        ln -s "$NEW_LINK_PATH" "$line"
        if [ $? -ne 0 ]; then
            echo "Error: Failed to create symbolic link $line -> $NEW_LINK_PATH"
            continue
        fi
        echo "Updated: $line -> $NEW_LINK_PATH"
    else
        echo "No change needed for: $line"
    fi
done