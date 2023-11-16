#! /bin/bash

DIR=$1
OLD_PATTERN=$2
NEW_PATTERN=$3

while read -r line
do
    echo $line
    CUR_LINK_PATH="$(readlink "$line")"
    NEW_LINK_PATH="$CUR_LINK_PATH"  
    NEW_LINK_PATH="${NEW_LINK_PATH/"$OLD_PATTERN"/"$NEW_PATTERN"}"
    rm "$line"
    ln -s "$NEW_LINK_PATH" "$line"
done <<< $(find "$DIR" -type l)
