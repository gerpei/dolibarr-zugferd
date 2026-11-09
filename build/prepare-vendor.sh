#!/bin/sh
# Prepare vendor/ after "composer install --no-dev" for use inside Dolibarr.
#
# 1. Dolibarr's TCPDI library (htdocs/includes/tcpdi/tcpdi.php) declares a global
#    class "FPDF extends TCPDF". setasign/fpdi needs the real FPDF class with the
#    same global name, which leads to a fatal error as soon as both are loaded in
#    one request. We therefore rename the bundled FPDF class to "ZugferdVendorFPDF".
# 2. Remove tests, docs, tutorials and the XSD/Schematron files of horstoeko/zugferd
#    (only used by its own validator classes, not by this module) to keep the
#    package below the usual PHP upload limit of 2 MB.
#
# Usage: sh build/prepare-vendor.sh   (run from the module root directory)

set -e
cd "$(dirname "$0")/.."
V=vendor

if [ ! -f "$V/setasign/fpdf/fpdf.php" ]; then
	echo "vendor/setasign/fpdf/fpdf.php not found - run composer install first" >&2
	exit 1
fi

# --- 1. Scope the global FPDF class --------------------------------------------------
sed -i.bak \
	-e "s/^define('FPDF_VERSION','\([0-9.]*\)');/if (!defined('FPDF_VERSION')) { define('FPDF_VERSION','\1'); }/" \
	-e 's/^class FPDF$/class ZugferdVendorFPDF/' \
	"$V/setasign/fpdf/fpdf.php"
sed -i.bak -e 's/extends \\FPDF$/extends \\ZugferdVendorFPDF/' "$V/setasign/fpdi/src/FpdfTpl.php"
sed -i.bak -e "s/'FPDF' => /'ZugferdVendorFPDF' => /" "$V/composer/autoload_classmap.php" "$V/composer/autoload_static.php"
find "$V" -name '*.bak' -delete

grep -q '^class ZugferdVendorFPDF$' "$V/setasign/fpdf/fpdf.php"
grep -q 'extends \\ZugferdVendorFPDF$' "$V/setasign/fpdi/src/FpdfTpl.php"
grep -q "'ZugferdVendorFPDF' => " "$V/composer/autoload_static.php"

# --- 2. Remove files not needed at runtime ------------------------------------------
for d in setasign/fpdf/tutorial setasign/fpdf/doc jms/serializer/doc doctrine/instantiator/docs \
	goetas-webservices/xsd2php-runtime/tests horstoeko/stringmanagement/tests horstoeko/mimedb/tests \
	horstoeko/stringmanagement/.github horstoeko/mimedb/.github symfony/translation-contracts/Test \
	symfony/validator/Test \
	horstoeko/zugferd/src/schema horstoeko/zugferd/src/validation; do
	rm -rf "$V/$d"
done
rm -rf "$V/bin"
# Validator message translations (only English is kept)
find "$V/symfony/validator/Resources/translations" -type f ! -name '*.en.xlf' -delete 2>/dev/null || true
find "$V" \( -name '.travis.yml' -o -name '.scrutinizer.yml' -o -name 'phpunit.xml.dist' -o -name 'phpstan.neon.dist' \
	-o -name 'phpbench.json' -o -name 'rector.php' -o -name 'psalm.xml' -o -name '.gitignore' -o -name '.gitattributes' \
	-o -name '.doctrine-project.json' -o -name '.DS_Store' \) -delete

echo "vendor prepared"
