#!/bin/sh
# Build the installable Dolibarr package dist/module_zugferd-<version>.zip
# Requires: composer, zip. Run from the module root directory.
set -e
cd "$(dirname "$0")/.."
VERSION=$(sed -n "s/.*->version = '\([^']*\)'.*/\1/p" core/modules/modZugferd.class.php)
composer install --no-dev --optimize-autoloader --no-interaction
sh build/prepare-vendor.sh
TMP=$(mktemp -d)
mkdir "$TMP/zugferd"
tar --exclude=./.git --exclude=./dist --exclude=./.gitignore -cf - . | (cd "$TMP/zugferd" && tar -xf -)
mkdir -p dist
rm -f "dist/module_zugferd-$VERSION.zip"
(cd "$TMP" && zip -qr -9 -X - zugferd) > "dist/module_zugferd-$VERSION.zip"
rm -rf "$TMP"
ls -l "dist/module_zugferd-$VERSION.zip"
