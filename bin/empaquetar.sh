#!/bin/sh
# Builds the ZIP to upload in Plugins → Añadir nuevo: the plugin in a folder of its own, without tests or tooling.
set -e
cd "$(dirname "$0")/.."
version=$(sed -n 's/^ \* Version: *//p' biblioteca-codex.php)
out="${1:-..}/biblioteca-codex-$version.zip"
tmp=$(mktemp -d)
mkdir "$tmp/biblioteca-codex"
cp -R biblioteca-codex.php inc LICENSE LEEME.md "$tmp/biblioteca-codex/"
rm -f "$out"
( cd "$tmp" && zip -qr - biblioteca-codex -x '*.DS_Store' ) > "$out"
rm -rf "$tmp"
echo "$out"
