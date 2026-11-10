# ZUGFeRD / XRechnung for Dolibarr

Creates from **validated customer invoices**:

- **XRechnung 3.0** - XML file in CII syntax (`IN2611-0001_xrechnung.xml`)
- **ZUGFeRD 2 / Factur-X, profile EN 16931** - PDF/A-3 with embedded `factur-x.xml` (`IN2611-0001_zugferd.pdf`)

Both files are stored with the invoice documents. When sending the invoice by e-mail from Dolibarr, the e-invoice is preselected as attachment.

Requirements: Dolibarr **16 or newer**, PHP **7.4 or newer** with gd, zlib, mbstring, fileinfo and simplexml.

## Installation

1. **Home - Setup - Modules/Applications - Deploy/install external app/module**
2. Upload `module_zugferd-1.2.1.zip` unchanged (keep the file name).
3. Enable the module **ZUGFeRD / XRechnung**.
4. Open the module setup page and check the **master data check**.

The package is just under 2 MB. If the PHP upload limit is smaller, copy the folder `zugferd/` from the zip to `htdocs/custom/` and enable the module afterwards.

## Master data

Company (name, address, VAT number or tax number, e-mail, phone), a bank account with IBAN, and for XRechnung the full customer address and an electronic address (customer e-mail or extra field). Leitweg-ID for German public authorities. If mandatory data is missing, no file is created and the invoice card shows what is missing.

## VAT categories

S (rate > 0), E (small business, section 19 UStG), K (EU goods), AE (EU services), G (export goods), O (export services). A VAT dictionary code (E, AE, Z, K, G, O) always takes precedence. Domestic 0% lines use the category chosen in the module setup.

## Validation

Tested with Dolibarr 22.0.4 / PHP 8.3: XRechnung XML with KoSIT validator 1.6.3 and configuration XRechnung 3.0.2 (2026-08-31), ZUGFeRD PDF with Mustang 2.26.0 (veraPDF PDF/A-3b and EN 16931 schematron).

## Developer note

`vendor/` comes from `composer install --no-dev` followed by `build/prepare-vendor.sh`, which renames the bundled FPDF class to `ZugferdVendorFPDF` because Dolibarr (TCPDI) already declares a global `FPDF` class.

## License

GPL-3.0-or-later (see `COPYING`). Libraries in `vendor/` keep their own licenses (horstoeko/zugferd, FPDF, FPDI, Symfony: MIT).
