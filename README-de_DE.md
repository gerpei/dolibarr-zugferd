# ZUGFeRD / XRechnung für Dolibarr

Erzeugt aus **validierten Kundenrechnungen**:

- **XRechnung 3.0** – XML-Datei in CII-Syntax (`IN2611-0001_xrechnung.xml`)
- **ZUGFeRD 2 / Factur-X, Profil EN 16931** – PDF/A-3 mit eingebetteter `factur-x.xml` (`IN2611-0001_zugferd.pdf`)

Beide Dateien werden bei den Dokumenten der Rechnung abgelegt. Beim Versand per E-Mail aus Dolibarr wird die E-Rechnung als Anhang vorausgewählt.

Voraussetzungen: Dolibarr **16 oder neuer**, PHP **7.4 oder neuer** mit den Erweiterungen gd, zlib, mbstring, fileinfo und simplexml.

## Installation

1. **Start → Einstellungen → Module/Anwendungen → Externes Modul bereitstellen/installieren**
2. Datei `module_zugferd-1.2.1.zip` unverändert hochladen (der Dateiname muss so bleiben).
3. Modul **ZUGFeRD / XRechnung** aktivieren.
4. Einstellungsseite des Moduls öffnen und die **Prüfung der Stammdaten** kontrollieren.

Das Paket ist knapp 2 MB groß. Ist das Upload-Limit von PHP kleiner, den Ordner `zugferd/` aus dem ZIP per FTP/SSH nach `htdocs/custom/` kopieren und das Modul danach aktivieren.

Bei einem Update: neue Version hochladen, Modul einmal deaktivieren und wieder aktivieren.

## Stammdaten

| Wo | Was | Pflicht für |
| --- | --- | --- |
| Firma/Organisation | Name, Straße, PLZ, Ort, Land | immer |
| Firma/Organisation | USt-IdNr. oder Steuernummer (Feld „Steuernummer“) | immer |
| Firma/Organisation | E-Mail und Telefon | XRechnung (BT-34, BT-42, BT-43) |
| Bank/Kasse | Konto mit IBAN, als Standardkonto unter Einstellungen → Rechnungen | immer |
| Kunde | Name, Straße, PLZ, Ort, Land | XRechnung |
| Kunde | E-Mail oder Extrafeld „Elektronische Empfängeradresse“ | XRechnung (BT-49) |
| Kunde | Extrafeld „Leitweg-ID“ | öffentliche Auftraggeber (BT-10) |
| Kunde | USt-IdNr. | EU-Kunden (Reverse Charge, innergemeinschaftliche Lieferung) |

Fehlt etwas, wird **keine Datei** erzeugt und die Rechnungskarte zeigt, was fehlt. Das Modul erfindet keine Daten.

## Steuerkategorien

| Fall | Kategorie | Befreiungsgrund |
| --- | --- | --- |
| Steuersatz > 0 % | S | – |
| Firma ist Kleinunternehmer (nicht umsatzsteuerpflichtig) | E | § 19 UStG |
| 0 %, Kunde im EU-Ausland, Ware | K | VATEX-EU-IC, Lieferland = Kundenland |
| 0 %, Kunde im EU-Ausland, Dienstleistung | AE | VATEX-EU-AE |
| 0 %, Kunde im Drittland, Ware | G | VATEX-EU-G |
| 0 %, Kunde im Drittland, Dienstleistung | O | VATEX-EU-O (ohne USt-IdNr.) |
| 0 %, Kunde im Inland | Einstellung „Steuerkategorie für 0-%-Positionen“ | Text aus dem Setup |

Ein Code im MwSt-Wörterbuch (Feld „Code“, z. B. `AE`, `E`, `Z`) hat immer Vorrang.

## Rechnung erzeugen

1. Rechnung anlegen und **validieren**.
2. Auf der Rechnungskarte **XRechnung erzeugen** oder **ZUGFeRD erzeugen** klicken.
3. Die Datei erscheint unter den Dokumenten der Rechnung.

Was übertragen wird:

- Rechnungsnummer, Datum, Fälligkeit, Zahlungsbedingung, IBAN/BIC (SEPA-Überweisung, Code 58)
- Positionen mit Menge, Einheit (UN/ECE Rec. 20), Nettopreis, Leistungszeitraum
- Rabatt- und Minuspositionen als negative Menge (Nettopreis bleibt positiv, BR-27)
- Bereits gezahlte Beträge, verrechnete Gutschriften und Anzahlungen (BT-113)
- Gutschrift (381) mit Bezug auf die Ursprungsrechnung, Ersatzrechnung (384), Anzahlungsrechnung (ZUGFeRD 386, XRechnung 380)
- Ihr Zeichen/Bestellnummer des Kunden (BT-13), öffentliche Notiz (BT-22), Lieferanschrift aus dem Kontakt „Lieferadresse“
- Leistungsdatum: Zeitraum der Positionen, sonst das Rechnungsdatum (abschaltbar)

Für ZUGFeRD erzeugt das Modul das Standard-PDF der Rechnung neu als PDF/A-3 (Dolibarr-Option `PDF_USE_A`, nur für diesen Vorgang) und bettet danach die XML ein.

## Prüfung

Die Ausgabe wurde mit Dolibarr 22.0.4 und PHP 8.3 geprüft:

- XRechnung-XML: KoSIT-Validator 1.6.3 mit Konfiguration XRechnung 3.0.2 (2026-08-31) – alle Testrechnungen „acceptable“
- ZUGFeRD-PDF: Mustang 2.26.0 (veraPDF PDF/A-3b und EN-16931-Schematron) – alle Testrechnungen „valid“

Vor dem ersten produktiven Versand empfiehlt sich trotzdem eine Prüfung mit echten Daten, z. B. über den Validator des Empfängerportals.

## Grenzen

- Nur Kundenrechnungen, kein Import von Eingangsrechnungen, kein Versand über PEPPOL
- Nur die Hauptwährung der Firma; Fremdwährungsrechnungen werden abgelehnt
- Zahlungsart immer SEPA-Überweisung (Lastschrift wird nicht abgebildet)
- Situationsrechnungen werden wie normale Rechnungen (380) behandelt

## Hinweis für Entwickler

`vendor/` stammt aus `composer install --no-dev` und wurde anschließend mit `build/prepare-vendor.sh` angepasst: Die mitgelieferte FPDF-Klasse heißt `ZugferdVendorFPDF`, weil Dolibarr (TCPDI) bereits eine globale Klasse `FPDF` definiert. Ohne diese Anpassung bricht die ZUGFeRD-Erzeugung mit einem Fatal Error ab. Nach jedem `composer install/update` das Skript erneut ausführen.

## Lizenz

Modul: GPL-3.0-or-later (siehe `COPYING`). Bibliotheken in `vendor/`: jeweilige Lizenzen, u. a. `horstoeko/zugferd` (MIT), FPDF/FPDI (MIT), Symfony (MIT).
