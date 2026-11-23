#!/bin/sh
# Builds dist/indexpages-<version>.zip with only the files the plugin ships.
set -eu

cd "$(dirname "$0")"

slug=indexpages
version=$(sed -n 's/^ \* Version: *//p' "$slug/$slug.php" | tr -d '[:space:]')
stable=$(sed -n 's/^Stable tag: *//p' "$slug/readme.txt" | tr -d '[:space:]')

if [ -z "$version" ]; then
	echo "Could not read the version from $slug/$slug.php" >&2
	exit 1
fi
if [ "$version" != "$stable" ]; then
	echo "Version $version in $slug.php does not match Stable tag $stable in readme.txt" >&2
	exit 1
fi
grep -q "define( 'INDEXPAGES_VERSION', '$version' );" "$slug/$slug.php" || {
	echo "INDEXPAGES_VERSION does not match $version" >&2
	exit 1
}

build=$(mktemp -d)
trap 'rm -rf "$build"' EXIT

mkdir -p "$build/$slug"
cp "$slug/$slug.php" "$slug/uninstall.php" "$slug/readme.txt" "$build/$slug/"
cp -R "$slug/includes" "$slug/assets" "$slug/languages" "$build/$slug/"
find "$build" -name '.DS_Store' -delete

mkdir -p dist
out="$PWD/dist/$slug-$version.zip"
rm -f "$out"
(cd "$build" && TZ=UTC zip -qrX "$out" "$slug")

echo "$out"
unzip -l "$out"
