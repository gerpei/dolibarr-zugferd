# ChangeLog

## 1.2.1 (2026-11-10)

- Module ID changed to 231240 (range 231240-231249 reserved for DoliCare on the Dolibarr wiki), required for publication on DoliStore.
- Publisher: DoliCare - Gerhard Peilstöcker, https://dolicare.de
- Licence and documentation also in `docs/`.

Upgrading from 1.2.0: replace the module files. Settings, extra fields (Leitweg-ID, electronic address) and generated files are kept. If the module page shows the module as disabled, simply enable it again.

## 1.2.0 (2026-11-09)

Fixes
- ZUGFeRD: fatal error "FpdfTplTrait::setPageFormat must be compatible with TCPDF::setPageFormat" inside Dolibarr. The bundled FPDF class is now scoped (`ZugferdVendorFPDF`), see `build/prepare-vendor.sh`.
- ZUGFeRD: the result is now PDF/A-3 compliant (fonts embedded via `PDF_USE_A`, link annotations with /F flag through FPDF 1.8.6).
- XRechnung rejected for invoices with discount or minus lines (BR-27); negative lines now use a negative quantity.
- Partially paid invoices violated BR-CO-16; paid amounts, used credit notes and deposits are now sent as BT-113.
- No more invented data: placeholder addresses ("Kundenstraße 1", "10115 Berlin") and e-mail addresses (`@kunde.example`) are removed. Missing data stops the export with a message.
- 0 % VAT is no longer always category Z: small business (E), EU goods (K), EU services (AE), export goods (G), export services (O), VAT dictionary code has priority; exemption reasons are sent.
- Intra-community supplies carry the deliver-to country (BR-IC-12).
- Unit prices and quantities use as many decimals as needed so that quantity x price equals the line amount (PEPPOL-EN16931-R120).
- XRechnung: seller phone number is required (BR-DE-6); proposal reference is no longer sent as contract reference (BT-12).
- Tax number: only the field "Steuernummer" is used and only if it looks like a tax number (no court names).
- Invoice type codes: replacement invoice 384, deposit invoice 386 (ZUGFeRD) / 380 (XRechnung).

Improvements
- Delivery date (BT-72) or invoicing period (BG-14) from the line service periods, otherwise the invoice date (option).
- Deliver-to address from the invoice contact of type "shipping address".
- Invoice public note as BT-22, multi-line addresses as address line 1/2.
- E-mail form: the ZUGFeRD PDF replaces the standard PDF; without ZUGFeRD the XRechnung is added.
- CSRF token check and permission "create invoices" for the generate buttons.
- Setup page with master data check and new options; about page.
- Package below 2 MB (unused XSD/Schematron files and translations removed from vendor).

## 1.1.0

- Previous version.
