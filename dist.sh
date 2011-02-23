#!/bin/bash

die()
{
    echo "$@" >&2
    exit 1
}

set -e

version="$1"
[[ -z $version ]] && version="$(git describe)"
rev="HEAD"
name="glip-$version"
archive="../$name.tar.gz"
rev="$(git rev-parse "$rev")"

echo "creating $archive from $rev"

[[ ! -e "$archive" ]] || die "$archive exists already, aborting."

git archive --prefix="$name/" "$rev" | gzip -c > "$archive"
tar tzf "$archive"

