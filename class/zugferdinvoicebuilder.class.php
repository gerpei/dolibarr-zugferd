<?php
/* Copyright (C) 2026 ITSS - Gerhard Peilstöcker
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/zugferd/class/zugferdinvoicebuilder.class.php
 * \ingroup zugferd
 * \brief   Map a Dolibarr customer invoice (Facture) to XRechnung 3 / ZUGFeRD EN 16931 (UN/CEFACT CII).
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once dol_buildpath('/zugferd/lib/zugferd.lib.php', 0);

use horstoeko\zugferd\codelists\ZugferdElectronicAddressScheme;
use horstoeko\zugferd\codelists\ZugferdUnitCodes;
use horstoeko\zugferd\codelists\ZugferdVatTypeCodes;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdDocumentPdfMerger;
use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\ZugferdSettings;

/**
 * Builds CII XML and ZUGFeRD PDFs from a customer invoice.
 *
 * Principles:
 * - No invented data. Missing mandatory data stops the export with a message.
 * - Amounts are taken from Dolibarr. If they cannot be represented consistently
 *   (EN 16931 sum rules), the export stops instead of silently changing amounts.
 */
class ZugferdInvoiceBuilder
{
	/** VAT categories (UNTDID 5305) supported by this module */
	const VAT_CATEGORIES = array('S', 'Z', 'E', 'AE', 'K', 'G', 'O');

	/** Invoice type codes allowed by XRechnung 3 (BR-DE-17) */
	const XRECHNUNG_TYPE_CODES = array('326', '380', '384', '389', '381', '875', '876', '877');

	/** @var DoliDB */
	public $db;

	/** @var string[] Translation keys (optionally followed by "|" and parameters separated by "|") */
	public $errors = array();

	/** @var string[] */
	public $warnings = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Build XRechnung 3 XML (CII syntax).
	 *
	 * @param	Facture	$invoice	Invoice
	 * @return	string|false		XML or false
	 */
	public function buildXRechnungXml(Facture $invoice)
	{
		$builder = $this->buildDocument($invoice, true);
		if ($builder === false) {
			return false;
		}
		return $builder->getContent();
	}

	/**
	 * Build a ZUGFeRD 2 / Factur-X PDF (profile EN 16931) from an existing invoice PDF.
	 *
	 * @param	Facture	$invoice	Invoice
	 * @param	string	$pdfPath	Path to the visual PDF
	 * @return	string|false		PDF binary or false
	 */
	public function buildZugferdPdf(Facture $invoice, $pdfPath)
	{
		$builder = $this->buildDocument($invoice, false);
		if ($builder === false) {
			return false;
		}
		if (!is_readable($pdfPath)) {
			$this->errors[] = 'ZugferdErrorNoPdf';
			return false;
		}

		$xml = $builder->getContent();
		try {
			$merger = new ZugferdDocumentPdfMerger($xml, $pdfPath);
			$merger->generateDocument();
			$pdf = $merger->downloadString();
		} catch (Throwable $e) {
			$this->errors[] = 'ZugferdErrorBuildFailed';
			dol_syslog(__METHOD__.' '.get_class($e).': '.$e->getMessage(), LOG_ERR);
			return false;
		}

		if (!is_string($pdf) || $pdf === '') {
			$this->errors[] = 'ZugferdErrorBuildFailed';
			return false;
		}

		return $pdf;
	}

	/**
	 * @param	Facture	$invoice		Invoice
	 * @param	bool	$isXRechnung	true = XRechnung 3, false = ZUGFeRD EN 16931
	 * @return	ZugferdDocumentBuilder|false
	 */
	protected function buildDocument(Facture $invoice, $isXRechnung)
	{
		$this->errors = array();
		$this->warnings = array();

		if (!zugferdLoadVendor()) {
			$this->errors[] = 'ZugferdErrorMissingVendor';
			return false;
		}

		$this->prepareInvoice($invoice);
		$context = $this->collectContext($invoice, $isXRechnung);
		if (!empty($this->errors)) {
			return false;
		}
		$this->validate($context, $isXRechnung);
		if (!empty($this->errors)) {
			return false;
		}

		try {
			$profile = $isXRechnung ? ZugferdProfiles::PROFILE_XRECHNUNG_3 : ZugferdProfiles::PROFILE_EN16931;
			$document = ZugferdDocumentBuilder::createNew($profile);
			$this->fillDocument($document, $invoice, $context);
			return $document;
		} catch (Throwable $e) {
			$this->errors[] = 'ZugferdErrorBuildFailed';
			dol_syslog(__METHOD__.' '.get_class($e).': '.$e->getMessage(), LOG_ERR);
			return false;
		}
	}

	/**
	 * @param	Facture	$invoice	Invoice
	 * @return	void
	 */
	protected function prepareInvoice(Facture $invoice)
	{
		if (empty($invoice->lines)) {
			$invoice->fetch_lines();
		}
		if (empty($invoice->thirdparty) || empty($invoice->thirdparty->id)) {
			$invoice->fetch_thirdparty();
		}
		if (method_exists($invoice, 'fetch_optionals')) {
			$invoice->fetch_optionals();
		}
		if (!empty($invoice->thirdparty) && method_exists($invoice->thirdparty, 'fetch_optionals')) {
			$invoice->thirdparty->fetch_optionals();
		}
	}

	/**
	 * Collect and normalise all data needed for the e-invoice.
	 *
	 * @param	Facture	$invoice		Invoice
	 * @param	bool	$isXRechnung	XRechnung rules
	 * @return	array<string,mixed>
	 */
	protected function collectContext(Facture $invoice, $isXRechnung)
	{
		global $mysoc, $conf;

		$thirdparty = $invoice->thirdparty;
		$bank = $this->loadBankAccount($invoice);

		$sellerCountry = !empty($mysoc->country_code) ? $mysoc->country_code : '';
		$buyerCountry = !empty($thirdparty->country_code) ? $thirdparty->country_code : '';
		$sellerVatLiable = !isset($mysoc->tva_assuj) || (string) $mysoc->tva_assuj === '' || !empty($mysoc->tva_assuj);

		// Currency: only the company currency is supported (amounts are taken from the base currency fields).
		$currency = !empty($conf->currency) ? $conf->currency : 'EUR';
		if (!empty($invoice->multicurrency_code) && $invoice->multicurrency_code !== $currency) {
			$this->errors[] = 'ZugferdErrorForeignCurrency|'.$invoice->multicurrency_code;
		}

		$leitweg = '';
		if (!empty($thirdparty->array_options['options_zugferd_leitweg'])) {
			$leitweg = trim($thirdparty->array_options['options_zugferd_leitweg']);
		}
		$endpoint = $this->resolveBuyerEndpoint($thirdparty, $leitweg);

		// BT-10 Buyer reference: Leitweg-ID, otherwise customer code
		$buyerRef = $leitweg;
		if ($buyerRef === '' && !empty($thirdparty->code_client)) {
			$buyerRef = $thirdparty->code_client;
		}
		if ($buyerRef === '' && !empty($thirdparty->id)) {
			$buyerRef = (string) $thirdparty->id;
		}

		$sellerVat = !empty($mysoc->tva_intra) ? strtoupper(preg_replace('/\s+/', '', $mysoc->tva_intra)) : '';
		$sellerTaxNumber = $this->resolveSellerTaxNumber($mysoc);
		$sellerLegalId = '';
		if ($sellerCountry === 'DE' && !empty($mysoc->idprof3)) {
			$sellerLegalId = trim((string) $mysoc->idprof3);	// Handelsregisternummer
		}

		$isCreditNote = ((int) $invoice->type === Facture::TYPE_CREDIT_NOTE);
		$sign = $isCreditNote ? -1.0 : 1.0;	// Credit notes (381) carry positive amounts in CII
		$buyerInEU = $this->isInEU($buyerCountry);
		$buyerVat = !empty($thirdparty->tva_intra) ? strtoupper(preg_replace('/\s+/', '', $thirdparty->tva_intra)) : '';

		$lines = array();
		$taxGroups = array();
		$periodStart = null;
		$periodEnd = null;
		$linesWithoutPeriod = 0;
		if (!empty($invoice->lines)) {
			foreach ($invoice->lines as $line) {
				if (!$this->isExportableLine($line)) {
					continue;
				}
				$qty = $sign * (float) $line->qty;
				$lineTotal = round($sign * (float) $line->total_ht, 2);
				$vatAmount = round($sign * (float) $line->total_tva, 2);
				if ($qty == 0.0) {
					$qty = ($lineTotal < 0) ? -1.0 : 1.0;
				}
				$unitPrice = $lineTotal / $qty;
				// BR-27: the item net price must not be negative -> negative quantity instead
				if ($unitPrice < 0) {
					$qty = -$qty;
					$unitPrice = -$unitPrice;
				}

				$vatRate = round((float) $line->tva_tx, 2);
				$category = $this->resolveVatCategory($line, $vatRate, $sellerVatLiable, $sellerCountry, $buyerCountry, $buyerInEU);
				if ($category === '') {
					$this->errors[] = 'ZugferdErrorZeroVatCategory|'.$this->lineLabel($line);
					continue;
				}
				if (in_array($category, array('E', 'AE', 'K', 'G', 'O', 'Z'), true) && $vatRate != 0.0) {
					$this->errors[] = 'ZugferdErrorCategoryNeedsZeroRate|'.$category.'|'.$this->lineLabel($line);
					continue;
				}
				if ($category === 'S' && $vatRate <= 0.0) {
					$this->errors[] = 'ZugferdErrorZeroVatCategory|'.$this->lineLabel($line);
					continue;
				}

				$key = $category.'|'.number_format($vatRate, 2, '.', '');
				if (!isset($taxGroups[$key])) {
					$taxGroups[$key] = array('category' => $category, 'rate' => $vatRate, 'base' => 0.0, 'tax' => 0.0);
				}
				$taxGroups[$key]['base'] += $lineTotal;
				$taxGroups[$key]['tax'] += $vatAmount;

				list($name, $description) = $this->lineTexts($line);

				$start = !empty($line->date_start) ? (int) $line->date_start : 0;
				$end = !empty($line->date_end) ? (int) $line->date_end : 0;
				if ($start && !$end) {
					$end = $start;
				}
				if ($end && !$start) {
					$start = $end;
				}
				if ($start && $end) {
					$periodStart = ($periodStart === null) ? $start : min($periodStart, $start);
					$periodEnd = ($periodEnd === null) ? $end : max($periodEnd, $end);
				} else {
					$linesWithoutPeriod++;
				}

				$lines[] = array(
					'qty' => $qty,
					'unit_price' => $unitPrice,
					'line_total' => $lineTotal,
					'vat_rate' => $vatRate,
					'vat_category' => $category,
					'name' => $name,
					'description' => $description,
					'ref' => !empty($line->product_ref) ? (string) $line->product_ref : '',
					'unit' => $this->mapUnitCode(isset($line->fk_unit) ? $line->fk_unit : 0),
					'period_start' => $start ? $this->toDateTime($start) : null,
					'period_end' => $end ? $this->toDateTime($end) : null,
				);
			}
		}

		// Smallest number of decimals for unit price / quantity so that quantity x net price
		// reproduces every line amount (PEPPOL-EN16931-R120, receiver side recalculation).
		$priceDecimals = 2;
		$qtyDecimals = 2;
		foreach ($lines as $l) {
			while ($qtyDecimals < 4 && abs(round($l['qty'], $qtyDecimals) - $l['qty']) > 1e-9) {
				$qtyDecimals++;
			}
		}
		foreach ($lines as $l) {
			while ($priceDecimals < 6 && abs(round(round($l['qty'], $qtyDecimals) * round($l['unit_price'], $priceDecimals), 2) - $l['line_total']) > 0.0049) {
				$priceDecimals++;
			}
		}
		foreach ($lines as $i => $l) {
			$lines[$i]['qty'] = round($l['qty'], $qtyDecimals);
			$lines[$i]['unit_price'] = round($l['unit_price'], $priceDecimals);
		}

		// Round tax groups and check the EN 16931 calculation rules
		$lineTotalSum = 0.0;
		$taxTotal = 0.0;
		foreach ($taxGroups as $key => $group) {
			$taxGroups[$key]['base'] = round($group['base'], 2);
			$taxGroups[$key]['tax'] = round($group['tax'], 2);
			$lineTotalSum += $taxGroups[$key]['base'];
			$taxTotal += $taxGroups[$key]['tax'];
			$expected = round($taxGroups[$key]['base'] * $group['rate'] / 100, 2);
			if (abs($expected - $taxGroups[$key]['tax']) > 0.01 + 1e-9) {
				// BR-CO-17: VAT per rate must equal basis x rate (tolerance 1 cent)
				$this->errors[] = 'ZugferdErrorVatRounding|'.price($group['rate']).'|'.price($taxGroups[$key]['tax']).'|'.price($expected);
			}
			$taxGroups[$key]['reason'] = '';
			$taxGroups[$key]['reason_code'] = '';
			if ($group['category'] !== 'S' && $group['category'] !== 'Z') {
				list($taxGroups[$key]['reason'], $taxGroups[$key]['reason_code']) = $this->exemptionReason($group['category'], $sellerVatLiable);
			}
		}
		$lineTotalSum = round($lineTotalSum, 2);
		$taxTotal = round($taxTotal, 2);
		$grandTotal = round($lineTotalSum + $taxTotal, 2);

		$dolTotalHt = round($sign * (float) $invoice->total_ht, 2);
		$dolTotalTtc = round($sign * (float) $invoice->total_ttc, 2);
		if (!empty($lines) && (abs($dolTotalHt - $lineTotalSum) > 0.005 || abs($dolTotalTtc - $grandTotal) > 0.005)) {
			$this->errors[] = 'ZugferdErrorTotalsMismatch|'.price($dolTotalTtc).'|'.price($grandTotal);
		}

		// Already paid: payments, used credit notes and used deposits (BT-113)
		$paid = 0.0;
		if (method_exists($invoice, 'getSommePaiement')) {
			$paid += (float) $invoice->getSommePaiement();
		}
		if (method_exists($invoice, 'getSumCreditNotesUsed')) {
			$paid += (float) $invoice->getSumCreditNotesUsed();
		}
		if (method_exists($invoice, 'getSumDepositsUsed')) {
			$paid += (float) $invoice->getSumDepositsUsed();
		}
		$paid = round(abs($paid), 2);
		if ($paid > $grandTotal) {
			$paid = $grandTotal;
		}
		$due = round($grandTotal - $paid, 2);

		$precedingRef = '';
		$precedingDate = null;
		if (!empty($invoice->fk_facture_source)) {
			$source = new Facture($this->db);
			if ($source->fetch((int) $invoice->fk_facture_source) > 0 && !empty($source->ref)) {
				$precedingRef = $source->ref;
				if (!empty($source->date)) {
					$precedingDate = $this->toDateTime($source->date);
				}
			}
		}

		$issueDate = $this->toDateTime(!empty($invoice->date) ? $invoice->date : dol_now());
		$dueDate = !empty($invoice->date_lim_reglement) ? $this->toDateTime($invoice->date_lim_reglement) : null;

		// BT-72 Actual delivery date / BG-14 Invoicing period
		$deliveryDate = null;
		$invoicePeriodStart = null;
		$invoicePeriodEnd = null;
		$deliveryTs = 0;
		if (!empty($invoice->delivery_date)) {
			$deliveryTs = (int) $invoice->delivery_date;
		} elseif (!empty($invoice->date_livraison)) {
			$deliveryTs = (int) $invoice->date_livraison;
		}
		if ($periodStart !== null) {
			$invoicePeriodStart = $this->toDateTime($periodStart);
			$invoicePeriodEnd = $this->toDateTime($periodEnd);
		}
		if ($deliveryTs) {
			$deliveryDate = $this->toDateTime($deliveryTs);
		} elseif ($periodStart === null || $linesWithoutPeriod > 0) {
			if (zugferdGetConstInt('ZUGFERD_DELIVERY_DATE_IS_INVOICE_DATE', 1)) {
				$deliveryDate = $issueDate;
			}
		}

		$paymentTerms = '';
		if (!empty($invoice->cond_reglement_doc)) {
			$paymentTerms = $invoice->cond_reglement_doc;
		} elseif (!empty($invoice->cond_reglement)) {
			$paymentTerms = $invoice->cond_reglement;
		}
		$paymentTerms = $this->plainText($paymentTerms);

		$note = $this->plainText(!empty($invoice->note_public) ? $invoice->note_public : '');

		list($sellerLine1, $sellerLine2) = $this->splitAddress(!empty($mysoc->address) ? $mysoc->address : '');
		list($buyerLine1, $buyerLine2) = $this->splitAddress(!empty($thirdparty->address) ? $thirdparty->address : '');
		$shipTo = $this->resolveShipTo($invoice);

		return array(
			'seller_name' => !empty($mysoc->name) ? trim($mysoc->name) : '',
			'seller_address1' => $sellerLine1,
			'seller_address2' => $sellerLine2,
			'seller_zip' => !empty($mysoc->zip) ? trim($mysoc->zip) : '',
			'seller_town' => !empty($mysoc->town) ? trim($mysoc->town) : '',
			'seller_country' => $sellerCountry,
			'seller_vat' => $sellerVat,
			'seller_tax_number' => $sellerTaxNumber,
			'seller_legal_id' => $sellerLegalId,
			'seller_vat_liable' => $sellerVatLiable,
			'seller_email' => !empty($mysoc->email) ? trim($mysoc->email) : '',
			'seller_phone' => !empty($mysoc->phone) ? trim($mysoc->phone) : '',
			'seller_contact' => $this->resolveSellerContact($mysoc),
			'buyer_name' => !empty($thirdparty->name) ? trim($thirdparty->name) : '',
			'buyer_id' => !empty($thirdparty->code_client) ? $thirdparty->code_client : '',
			'buyer_address1' => $buyerLine1,
			'buyer_address2' => $buyerLine2,
			'buyer_zip' => !empty($thirdparty->zip) ? trim($thirdparty->zip) : '',
			'buyer_town' => !empty($thirdparty->town) ? trim($thirdparty->town) : '',
			'buyer_country' => $buyerCountry,
			'buyer_vat' => $buyerVat,
			'buyer_endpoint' => $endpoint['value'],
			'buyer_endpoint_scheme' => $endpoint['scheme'],
			'buyer_ref' => $buyerRef,
			'leitweg' => $leitweg,
			'ship_to' => $shipTo,
			'iban' => $bank ? strtoupper(preg_replace('/\s+/', '', (string) $bank->iban)) : '',
			'bic' => ($bank && !empty($bank->bic)) ? strtoupper(preg_replace('/\s+/', '', (string) $bank->bic)) : '',
			'account_owner' => ($bank && !empty($bank->proprio)) ? trim((string) $bank->proprio) : '',
			'lines' => $lines,
			'price_decimals' => $priceDecimals,
			'qty_decimals' => $qtyDecimals,
			'currency' => $currency,
			'type_code' => $this->resolveTypeCode($invoice, $isXRechnung),
			'is_credit_note' => $isCreditNote,
			'issue_date' => $issueDate,
			'due_date' => $dueDate,
			'delivery_date' => $deliveryDate,
			'period_start' => $invoicePeriodStart,
			'period_end' => $invoicePeriodEnd,
			'payment_terms' => $paymentTerms,
			'note' => $note,
			'ref_client' => !empty($invoice->ref_client) ? trim($invoice->ref_client) : '',
			'preceding_invoice_ref' => $precedingRef,
			'preceding_invoice_date' => $precedingDate,
			'line_total' => $lineTotalSum,
			'tax_total' => $taxTotal,
			'grand_total' => $grandTotal,
			'paid' => $paid,
			'due' => $due,
			'tax_groups' => $taxGroups,
			'is_xrechnung' => $isXRechnung,
		);
	}

	/**
	 * Check mandatory data. No data is invented: missing data stops the export.
	 *
	 * @param	array<string,mixed>	$ctx			Context
	 * @param	bool				$isXRechnung	XRechnung
	 * @return	void
	 */
	protected function validate($ctx, $isXRechnung)
	{
		$strict = zugferdGetConstInt('ZUGFERD_STRICT_MODE', 1);
		$requireLeitweg = zugferdGetConstInt('ZUGFERD_REQUIRE_LEITWEG', 0);
		$fullContact = ($isXRechnung || $strict);

		// Seller (BG-4, BG-5)
		if ($ctx['seller_name'] === '') {
			$this->errors[] = 'ZugferdErrorMissingSellerName';
		}
		if ($ctx['seller_address1'] === '' || $ctx['seller_zip'] === '' || $ctx['seller_town'] === '' || $ctx['seller_country'] === '') {
			$this->errors[] = 'ZugferdErrorMissingSellerAddress';
		}
		if ($ctx['seller_vat'] === '' && $ctx['seller_tax_number'] === '') {
			$this->errors[] = 'ZugferdErrorMissingSellerTax';
		}
		// Seller contact (BG-6, XRechnung BR-DE-2/5/6/7) and electronic address (BT-34)
		if ($fullContact) {
			if ($ctx['seller_email'] === '') {
				$this->errors[] = 'ZugferdErrorMissingSellerEmail';
			}
			if ($ctx['seller_phone'] === '') {
				$this->errors[] = 'ZugferdErrorMissingSellerPhone';
			}
		}

		// Buyer (BG-7, BG-8)
		if ($ctx['buyer_name'] === '') {
			$this->errors[] = 'ZugferdErrorMissingBuyerName';
		}
		if ($ctx['buyer_country'] === '') {
			$this->errors[] = 'ZugferdErrorMissingBuyerCountry';
		}
		if ($fullContact && ($ctx['buyer_address1'] === '' || $ctx['buyer_zip'] === '' || $ctx['buyer_town'] === '')) {
			$this->errors[] = 'ZugferdErrorMissingBuyerAddress';
		}
		if ($isXRechnung && $ctx['buyer_endpoint'] === '') {
			$this->errors[] = 'ZugferdErrorMissingBuyerEmail';
		}
		if ($requireLeitweg && $ctx['leitweg'] === '') {
			$this->errors[] = 'ZugferdErrorMissingLeitweg';
		}
		if ($ctx['leitweg'] !== '' && !preg_match('/^[0-9]{2,12}(-[0-9A-Za-z]{1,30})?-[0-9]{2}$/', $ctx['leitweg'])) {
			$this->errors[] = 'ZugferdErrorInvalidLeitweg|'.$ctx['leitweg'];
		}

		// Lines and payment
		if (empty($ctx['lines'])) {
			$this->errors[] = 'ZugferdErrorNoLines';
		}
		if ($ctx['iban'] === '') {
			$this->errors[] = 'ZugferdErrorMissingIban';
		}
		if ($ctx['due'] > 0 && empty($ctx['due_date']) && $ctx['payment_terms'] === '') {
			$this->errors[] = 'ZugferdErrorMissingPaymentTerms';	// BR-CO-25
		}

		// VAT category specific rules (BR-AE, BR-IC, BR-G, BR-O)
		$categories = array();
		foreach ($ctx['tax_groups'] as $group) {
			$categories[$group['category']] = true;
		}
		if ((isset($categories['AE']) || isset($categories['K'])) && $ctx['buyer_vat'] === '') {
			$this->errors[] = 'ZugferdErrorMissingBuyerVat';
		}
		if ((isset($categories['AE']) || isset($categories['K']) || isset($categories['G'])) && $ctx['seller_vat'] === '' && $ctx['seller_tax_number'] === '') {
			$this->errors[] = 'ZugferdErrorMissingSellerTax';
		}
		if (isset($categories['K']) && $ctx['seller_vat'] === '') {
			$this->errors[] = 'ZugferdErrorMissingSellerVatForIC';
		}
		if (isset($categories['O']) && count($categories) > 1) {
			$this->errors[] = 'ZugferdErrorMixedNotSubjectToVat';	// BR-O-11..14
		}
		if (isset($categories['O']) && $ctx['seller_legal_id'] === '' && $ctx['seller_tax_number'] === '') {
			$this->errors[] = 'ZugferdErrorMissingSellerLegalId';	// BR-CO-26 without VAT id (BR-O-02)
		}

		$this->errors = array_values(array_unique($this->errors));
	}

	/**
	 * @param	ZugferdDocumentBuilder	$document	Builder
	 * @param	Facture					$invoice	Invoice
	 * @param	array<string,mixed>		$ctx		Context
	 * @return	void
	 */
	protected function fillDocument(ZugferdDocumentBuilder $document, Facture $invoice, $ctx)
	{
		$categoriesUsed = array();
		foreach ($ctx['tax_groups'] as $group) {
			$categoriesUsed[$group['category']] = true;
		}
		$notSubjectToVat = (count($categoriesUsed) === 1 && isset($categoriesUsed['O']));

		ZugferdSettings::setAmountDecimals(2);
		ZugferdSettings::setQuantityDecimals($ctx['qty_decimals']);
		ZugferdSettings::setUnitAmountDecimals($ctx['price_decimals']);

		// BT-1, BT-3, BT-2, BT-5
		$document->setDocumentInformation($invoice->ref, $ctx['type_code'], $ctx['issue_date'], $ctx['currency']);

		// BT-22 Invoice note
		if ($ctx['note'] !== '') {
			$document->addDocumentNote($ctx['note']);
		}

		// BT-10 Buyer reference (XRechnung: Leitweg-ID)
		if ($ctx['buyer_ref'] !== '') {
			$document->setDocumentBuyerReference($ctx['buyer_ref']);
		}
		// BT-13 Purchase order reference
		if ($ctx['ref_client'] !== '') {
			$document->setDocumentBuyerOrderReferencedDocument($ctx['ref_client']);
		}
		// BG-3 Preceding invoice reference
		if ($ctx['preceding_invoice_ref'] !== '') {
			$document->setDocumentInvoiceReferencedDocument($ctx['preceding_invoice_ref'], null, $ctx['preceding_invoice_date']);
		}

		// BG-4 Seller
		// BT-29: for "not subject to VAT" the VAT id must be omitted (BR-O-02); the seller is then
		// identified by its tax number if there is no trade register number (BR-CO-26).
		$sellerId = null;
		if ($notSubjectToVat && $ctx['seller_legal_id'] === '' && $ctx['seller_tax_number'] !== '') {
			$sellerId = $ctx['seller_tax_number'];
		}
		$document->setDocumentSeller($ctx['seller_name'], $sellerId);
		$document->setDocumentSellerAddress(
			$ctx['seller_address1'],
			$ctx['seller_address2'] !== '' ? $ctx['seller_address2'] : null,
			null,
			$ctx['seller_zip'],
			$ctx['seller_town'],
			$ctx['seller_country']
		);
		if ($ctx['seller_legal_id'] !== '') {
			$document->setDocumentSellerLegalOrganisation($ctx['seller_legal_id'], null, null);	// BT-30
		}
		if ($ctx['seller_vat'] !== '' && !$notSubjectToVat) {
			$document->addDocumentSellerVATRegistrationNumber($ctx['seller_vat']);	// BT-31
		}
		if ($ctx['seller_tax_number'] !== '') {
			$document->addDocumentSellerTaxNumber($ctx['seller_tax_number']);	// BT-32
		}
		if ($ctx['seller_email'] !== '') {
			$document->setDocumentSellerCommunication(ZugferdElectronicAddressScheme::UNECE3155_EM, $ctx['seller_email']);	// BT-34
		}
		if ($ctx['seller_contact'] !== '' || $ctx['seller_phone'] !== '' || $ctx['seller_email'] !== '') {
			$document->setDocumentSellerContact(	// BG-6
				$ctx['seller_contact'] !== '' ? $ctx['seller_contact'] : null,
				null,
				$ctx['seller_phone'] !== '' ? $ctx['seller_phone'] : null,
				null,
				$ctx['seller_email'] !== '' ? $ctx['seller_email'] : null
			);
		}

		// BG-7 Buyer
		$document->setDocumentBuyer($ctx['buyer_name'], $ctx['buyer_id'] !== '' ? $ctx['buyer_id'] : null);
		$document->setDocumentBuyerAddress(
			$ctx['buyer_address1'] !== '' ? $ctx['buyer_address1'] : null,
			$ctx['buyer_address2'] !== '' ? $ctx['buyer_address2'] : null,
			null,
			$ctx['buyer_zip'] !== '' ? $ctx['buyer_zip'] : null,
			$ctx['buyer_town'] !== '' ? $ctx['buyer_town'] : null,
			$ctx['buyer_country']
		);
		if ($ctx['buyer_vat'] !== '' && !$notSubjectToVat) {
			$document->addDocumentBuyerVATRegistrationNumber($ctx['buyer_vat']);	// BT-48
		}
		if ($ctx['buyer_endpoint'] !== '') {
			$document->setDocumentBuyerCommunication($ctx['buyer_endpoint_scheme'], $ctx['buyer_endpoint']);	// BT-49
		}

		// BG-13 Delivery information: shipping contact of the invoice; for intra-community
		// supplies (BR-IC-12) the deliver-to country is mandatory, the buyer address is used then.
		$shipTo = $ctx['ship_to'];
		if (empty($shipTo) && isset($categoriesUsed['K'])) {
			$shipTo = array(
				'name' => $ctx['buyer_name'],
				'address1' => $ctx['buyer_address1'],
				'address2' => $ctx['buyer_address2'],
				'zip' => $ctx['buyer_zip'],
				'town' => $ctx['buyer_town'],
				'country' => $ctx['buyer_country'],
			);
		}
		if (!empty($shipTo) && $shipTo['country'] !== '') {
			$document->setDocumentShipTo($shipTo['name'] !== '' ? $shipTo['name'] : null);
			$document->setDocumentShipToAddress(
				$shipTo['address1'] !== '' ? $shipTo['address1'] : null,
				$shipTo['address2'] !== '' ? $shipTo['address2'] : null,
				null,
				$shipTo['zip'] !== '' ? $shipTo['zip'] : null,
				$shipTo['town'] !== '' ? $shipTo['town'] : null,
				$shipTo['country']
			);
		}

		// BT-72 / BG-14 Delivery date or invoicing period
		if (!empty($ctx['delivery_date'])) {
			$document->setDocumentSupplyChainEvent($ctx['delivery_date']);
		}
		if (!empty($ctx['period_start']) && !empty($ctx['period_end'])) {
			$document->setDocumentBillingPeriod($ctx['period_start'], $ctx['period_end'], null);
		}

		// BG-16 Payment instructions (SEPA credit transfer, code 58)
		$document->addDocumentPaymentMeanToCreditTransfer(
			$ctx['iban'],
			$ctx['account_owner'] !== '' ? $ctx['account_owner'] : $ctx['seller_name'],
			null,
			$ctx['bic'] !== '' ? $ctx['bic'] : null,
			$invoice->ref
		);
		// BT-20 / BT-9
		if ($ctx['payment_terms'] !== '' || !empty($ctx['due_date'])) {
			$document->addDocumentPaymentTerm($ctx['payment_terms'] !== '' ? $ctx['payment_terms'] : null, $ctx['due_date']);
		}

		// BG-25 Invoice lines
		$pos = 1;
		foreach ($ctx['lines'] as $line) {
			$document->addNewPosition((string) $pos);
			$document->setDocumentPositionProductDetails(
				$line['name'],
				$line['description'] !== '' ? $line['description'] : null,
				$line['ref'] !== '' ? $line['ref'] : null
			);
			$document->setDocumentPositionNetPrice($line['unit_price']);
			$document->setDocumentPositionQuantity($line['qty'], $line['unit']);
			if (!empty($line['period_start']) && !empty($line['period_end'])) {
				$document->setDocumentPositionBillingPeriod($line['period_start'], $line['period_end']);
			}
			$document->addDocumentPositionTax(
				$line['vat_category'],
				ZugferdVatTypeCodes::VALUE_ADDED_TAX,
				$line['vat_category'] === 'O' ? null : $line['vat_rate']
			);
			$document->setDocumentPositionLineSummation($line['line_total']);
			$pos++;
		}

		// BG-23 VAT breakdown
		foreach ($ctx['tax_groups'] as $group) {
			$document->addDocumentTax(
				$group['category'],
				ZugferdVatTypeCodes::VALUE_ADDED_TAX,
				$group['base'],
				$group['tax'],
				$group['rate'],	// XRechnung BR-DE-14 requires BT-119 also for category O (0)
				$group['reason'] !== '' ? $group['reason'] : null,
				$group['reason_code'] !== '' ? $group['reason_code'] : null
			);
		}

		// BG-22 Document totals
		$document->setDocumentSummation(
			$ctx['grand_total'],	// BT-112
			$ctx['due'],			// BT-115
			$ctx['line_total'],		// BT-106
			0.0,					// BT-108
			0.0,					// BT-107
			$ctx['line_total'],		// BT-109
			$ctx['tax_total'],		// BT-110
			null,					// BT-114
			$ctx['paid']			// BT-113
		);
	}

	/**
	 * Determine the VAT category (UNTDID 5305) of an invoice line.
	 *
	 * Order: VAT code of the Dolibarr VAT dictionary (vat_src_code, if it is a category letter),
	 * small business (seller not subject to VAT), rate > 0 = S, then 0 % by buyer location.
	 *
	 * @param	object	$line				Invoice line
	 * @param	float	$vatRate			VAT rate
	 * @param	bool	$sellerVatLiable	Seller subject to VAT
	 * @param	string	$sellerCountry		ISO code
	 * @param	string	$buyerCountry		ISO code
	 * @param	bool	$buyerInEU			Buyer in EU
	 * @return	string						Category or '' if undetermined
	 */
	protected function resolveVatCategory($line, $vatRate, $sellerVatLiable, $sellerCountry, $buyerCountry, $buyerInEU)
	{
		$src = isset($line->vat_src_code) ? strtoupper(trim((string) $line->vat_src_code)) : '';
		if ($src !== '' && in_array($src, self::VAT_CATEGORIES, true)) {
			return $src;
		}
		if (!$sellerVatLiable) {
			return 'E';
		}
		if ($vatRate > 0) {
			return 'S';
		}
		$isService = (isset($line->product_type) && (int) $line->product_type === 1);
		if ($buyerCountry !== '' && $buyerCountry !== $sellerCountry) {
			if ($buyerInEU) {
				// EU: goods = exempt intra-community supply, services = reverse charge
				return $isService ? 'AE' : 'K';
			}
			// Third countries: goods = exempt export, services = not taxable in the seller's country
			return $isService ? 'O' : 'G';
		}
		$default = strtoupper(zugferdGetConstString('ZUGFERD_DEFAULT_ZERO_CATEGORY', ''));
		if (in_array($default, array('E', 'AE', 'Z'), true)) {
			return $default;
		}
		return '';
	}

	/**
	 * Exemption reason text (BT-120) and code (BT-121) for a VAT category.
	 *
	 * @param	string	$category			Category
	 * @param	bool	$sellerVatLiable	Seller subject to VAT
	 * @return	array{0:string,1:string}
	 */
	protected function exemptionReason($category, $sellerVatLiable)
	{
		global $langs;
		$langs->load('zugferd@zugferd');

		switch ($category) {
			case 'E':
				if (!$sellerVatLiable) {
					$text = zugferdGetConstString('ZUGFERD_SMALL_BUSINESS_TEXT', '');
					return array($text !== '' ? $text : $langs->transnoentitiesnoconv('ZugferdReasonSmallBusiness'), '');
				}
				$text = zugferdGetConstString('ZUGFERD_EXEMPT_REASON', '');
				return array($text !== '' ? $text : $langs->transnoentitiesnoconv('ZugferdReasonExempt'), '');
			case 'AE':
				return array($langs->transnoentitiesnoconv('ZugferdReasonReverseCharge'), 'VATEX-EU-AE');
			case 'K':
				return array($langs->transnoentitiesnoconv('ZugferdReasonIntraCommunity'), 'VATEX-EU-IC');
			case 'G':
				return array($langs->transnoentitiesnoconv('ZugferdReasonExport'), 'VATEX-EU-G');
			case 'O':
				return array($langs->transnoentitiesnoconv('ZugferdReasonNotSubject'), 'VATEX-EU-O');
		}
		return array('', '');
	}

	/**
	 * BT-3 Invoice type code.
	 *
	 * @param	Facture	$invoice		Invoice
	 * @param	bool	$isXRechnung	XRechnung
	 * @return	string
	 */
	protected function resolveTypeCode(Facture $invoice, $isXRechnung)
	{
		switch ((int) $invoice->type) {
			case Facture::TYPE_CREDIT_NOTE:
				return '381';
			case Facture::TYPE_REPLACEMENT:
				return '384';
			case Facture::TYPE_DEPOSIT:
				// 386 (prepayment invoice) is not allowed in XRechnung (BR-DE-17)
				return $isXRechnung ? '380' : '386';
		}
		return '380';
	}

	/**
	 * @param	Facture	$invoice	Invoice
	 * @return	Account|null
	 */
	protected function loadBankAccount(Facture $invoice)
	{
		$candidates = array();
		if (!empty($invoice->fk_account)) {
			$candidates[] = (int) $invoice->fk_account;
		}
		if (!empty($invoice->fk_bank)) {
			$candidates[] = (int) $invoice->fk_bank;
		}
		$defaultRib = zugferdGetConstInt('FACTURE_RIB_NUMBER', 0);
		if ($defaultRib > 0) {
			$candidates[] = $defaultRib;
		}

		foreach (array_unique($candidates) as $fkAccount) {
			$account = new Account($this->db);
			if ($fkAccount > 0 && $account->fetch($fkAccount) > 0 && $this->accountHasIban($account)) {
				return $account;
			}
		}

		$sql = "SELECT rowid FROM ".$this->db->prefix()."bank_account";
		$sql .= " WHERE clos = 0";
		$sql .= " AND iban_prefix IS NOT NULL AND iban_prefix <> ''";
		$sql .= " AND entity IN (".getEntity('bank_account').")";
		$sql .= " ORDER BY courant ASC, rowid ASC";
		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			$account = new Account($this->db);
			if ($obj && $account->fetch((int) $obj->rowid) > 0 && $this->accountHasIban($account)) {
				return $account;
			}
		}

		return null;
	}

	/**
	 * @param	Account	$account	Bank account
	 * @return	bool
	 */
	protected function accountHasIban($account)
	{
		$iban = isset($account->iban) ? trim((string) $account->iban) : '';
		if ($iban === '' && isset($account->iban_prefix)) {
			$account->iban = trim((string) $account->iban_prefix);
			$iban = $account->iban;
		}
		return $iban !== '';
	}

	/**
	 * BT-49 Buyer electronic address: extra field, customer e-mail, first contact e-mail, Leitweg-ID.
	 *
	 * @param	Societe	$thirdparty	Customer
	 * @param	string	$leitweg	Leitweg-ID
	 * @return	array{scheme:string,value:string}
	 */
	protected function resolveBuyerEndpoint($thirdparty, $leitweg)
	{
		$extra = '';
		if (!empty($thirdparty->array_options['options_zugferd_endpoint'])) {
			$extra = trim($thirdparty->array_options['options_zugferd_endpoint']);
		}
		if ($extra !== '') {
			if (strpos($extra, '@') !== false) {
				return array('scheme' => ZugferdElectronicAddressScheme::UNECE3155_EM, 'value' => $extra);
			}
			return array('scheme' => ZugferdElectronicAddressScheme::ICD_0204, 'value' => $extra);
		}
		if (!empty($thirdparty->email)) {
			return array('scheme' => ZugferdElectronicAddressScheme::UNECE3155_EM, 'value' => trim($thirdparty->email));
		}
		$contactEmail = $this->findContactEmail($thirdparty);
		if ($contactEmail !== '') {
			return array('scheme' => ZugferdElectronicAddressScheme::UNECE3155_EM, 'value' => $contactEmail);
		}
		if ($leitweg !== '') {
			return array('scheme' => ZugferdElectronicAddressScheme::ICD_0204, 'value' => $leitweg);
		}
		return array('scheme' => '', 'value' => '');
	}

	/**
	 * BG-13/BG-15 Deliver-to address from the external contact of type "SHIPPING" of the invoice.
	 *
	 * @param	Facture	$invoice	Invoice
	 * @return	array<string,string>|null
	 */
	protected function resolveShipTo(Facture $invoice)
	{
		if (!method_exists($invoice, 'getIdContact')) {
			return null;
		}
		$ids = $invoice->getIdContact('external', 'SHIPPING');
		if (empty($ids) || !is_array($ids)) {
			return null;
		}
		require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
		$contact = new Contact($this->db);
		if ($contact->fetch((int) reset($ids)) <= 0) {
			return null;
		}
		list($line1, $line2) = $this->splitAddress((string) $contact->address);
		$name = trim(method_exists($contact, 'getFullName') ? $contact->getFullName($GLOBALS['langs']) : $contact->lastname);
		if (!empty($contact->socname) && $contact->socname !== $name) {
			$name = trim($contact->socname.', '.$name, ', ');
		}
		return array(
			'name' => $name,
			'address1' => $line1,
			'address2' => $line2,
			'zip' => trim((string) $contact->zip),
			'town' => trim((string) $contact->town),
			'country' => trim((string) $contact->country_code),
		);
	}

	/**
	 * BT-32 Seller tax number. Germany: professional id 1 ("Steuernummer" in Dolibarr), if it looks like one.
	 *
	 * @param	Societe	$mysoc	Company
	 * @return	string
	 */
	protected function resolveSellerTaxNumber($mysoc)
	{
		$field = zugferdGetConstString('ZUGFERD_SELLER_TAXNUMBER_FIELD', 'idprof1');
		if (!in_array($field, array('idprof1', 'idprof2', 'idprof3', 'idprof4', 'idprof5', 'idprof6'), true)) {
			$field = 'idprof1';
		}
		$value = !empty($mysoc->$field) ? trim((string) $mysoc->$field) : '';
		// A tax number consists of digits and separators; avoid exporting e.g. a court name.
		if ($value !== '' && preg_match('/^[0-9][0-9 \/.\-]{5,}[0-9]$/', $value)) {
			return $value;
		}
		return '';
	}

	/**
	 * BT-41 seller contact name.
	 *
	 * @param	Societe	$mysoc	Company
	 * @return	string
	 */
	protected function resolveSellerContact($mysoc)
	{
		$configured = zugferdGetConstString('ZUGFERD_SELLER_CONTACT', '');
		if ($configured !== '') {
			return $configured;
		}
		foreach (array('managers', 'name') as $field) {
			if (!empty($mysoc->$field)) {
				$value = trim(preg_replace('/\s+/', ' ', (string) $mysoc->$field));
				if ($value !== '') {
					return $value;
				}
			}
		}
		return '';
	}

	/**
	 * @param	Societe	$thirdparty	Customer
	 * @return	string
	 */
	protected function findContactEmail($thirdparty)
	{
		if (empty($thirdparty->id)) {
			return '';
		}
		$sql = "SELECT email FROM ".$this->db->prefix()."socpeople";
		$sql .= " WHERE fk_soc = ".((int) $thirdparty->id);
		$sql .= " AND statut = 1 AND email IS NOT NULL AND email <> ''";
		$sql .= " ORDER BY rowid ASC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return '';
		}
		$obj = $this->db->fetch_object($resql);
		return ($obj && !empty($obj->email)) ? trim($obj->email) : '';
	}

	/**
	 * Lines that do not belong in the e-invoice: titles, subtotals, free text lines.
	 *
	 * @param	object	$line	Invoice line
	 * @return	bool
	 */
	protected function isExportableLine($line)
	{
		if (isset($line->product_type) && (int) $line->product_type === 9) {
			return false;
		}
		if (!empty($line->special_code) && (int) $line->special_code === 9) {
			return false;
		}
		if ((float) $line->qty == 0.0 && (float) $line->total_ht == 0.0) {
			return false;
		}
		return true;
	}

	/**
	 * BT-153 item name and BT-154 item description.
	 *
	 * @param	object	$line	Invoice line
	 * @return	array{0:string,1:string}
	 */
	protected function lineTexts($line)
	{
		$label = !empty($line->product_label) ? $line->product_label : (!empty($line->label) ? $line->label : '');
		$desc = $this->plainText(!empty($line->desc) ? $line->desc : (!empty($line->description) ? $line->description : ''));
		$label = $this->plainText($label);

		if ($label === '') {
			$parts = preg_split('/\R/u', $desc, 2);
			$label = trim($parts[0]);
			$desc = isset($parts[1]) ? trim($parts[1]) : '';
		}
		if ($desc === $label) {
			$desc = '';
		}
		if ($label === '') {
			$label = !empty($line->product_ref) ? (string) $line->product_ref : '-';
		}
		return array(dol_trunc($label, 250, 'right', 'UTF-8', 1), $desc);
	}

	/**
	 * @param	object	$line	Invoice line
	 * @return	string			Short label for messages
	 */
	protected function lineLabel($line)
	{
		list($name) = $this->lineTexts($line);
		return dol_trunc($name, 40);
	}

	/**
	 * Convert HTML/rich text to plain text.
	 *
	 * @param	string	$text	Text
	 * @return	string
	 */
	protected function plainText($text)
	{
		$text = (string) $text;
		if ($text === '') {
			return '';
		}
		$text = preg_replace('/<br\s*\/?>/i', "\n", $text);
		$text = preg_replace('/<\/(p|div|li)>/i', "\n", $text);
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace("/[ \t]+/u", ' ', $text);
		$text = preg_replace("/\n\s*\n+/u", "\n", $text);
		return trim($text);
	}

	/**
	 * Split a Dolibarr multi-line address into BT-35/BT-36 (BT-50/BT-51).
	 *
	 * @param	string	$address	Address
	 * @return	array{0:string,1:string}
	 */
	protected function splitAddress($address)
	{
		$lines = preg_split('/\R/u', trim((string) $address));
		$lines = array_values(array_filter(array_map('trim', $lines), 'strlen'));
		$first = isset($lines[0]) ? $lines[0] : '';
		$rest = count($lines) > 1 ? implode(', ', array_slice($lines, 1)) : '';
		return array($first, $rest);
	}

	/**
	 * @param	string	$countryCode	ISO 3166-1 alpha-2
	 * @return	bool
	 */
	protected function isInEU($countryCode)
	{
		$eu = array('AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK');
		return in_array(strtoupper((string) $countryCode), $eu, true);
	}

	/**
	 * Map a Dolibarr unit (llx_c_units) to UN/ECE Recommendation 20.
	 *
	 * @param	int	$fkUnit	Dolibarr unit id
	 * @return	string		UNECE rec 20 code
	 */
	protected function mapUnitCode($fkUnit)
	{
		$default = ZugferdUnitCodes::REC20_PIECE;	// H87
		if (empty($fkUnit)) {
			return $default;
		}
		$code = '';
		$sql = "SELECT code, short_label FROM ".$this->db->prefix()."c_units WHERE rowid = ".((int) $fkUnit);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$code = strtoupper(trim((string) $obj->code));
			$short = strtolower(trim((string) $obj->short_label));
		} else {
			return $default;
		}
		$map = array(
			'P' => 'H87', 'PCE' => 'H87', 'PIECE' => 'H87', 'C62' => 'C62',
			'KG' => 'KGM', 'G' => 'GRM', 'MG' => 'MGM', 'T' => 'TNE',
			'M' => 'MTR', 'CM' => 'CMT', 'MM' => 'MMT', 'KM' => 'KMT',
			'M2' => 'MTK', 'CM2' => 'CMK', 'M3' => 'MTQ', 'L' => 'LTR', 'ML' => 'MLT',
			'H' => 'HUR', 'MI' => 'MIN', 'MIN' => 'MIN', 'S' => 'SEC', 'D' => 'DAY', 'DAY' => 'DAY',
			'W' => 'WEE', 'MO' => 'MON', 'Y' => 'ANN',
		);
		if (isset($map[$code])) {
			return $map[$code];
		}
		if ($this->isValidUnitCode($code)) {
			return $code;
		}
		$shortMap = array('h' => 'HUR', 'kg' => 'KGM', 'g' => 'GRM', 'm' => 'MTR', 'l' => 'LTR', 'm²' => 'MTK', 'm³' => 'MTQ', 'd' => 'DAY', 'min' => 'MIN');
		if (isset($shortMap[$short])) {
			return $shortMap[$short];
		}
		return $default;
	}

	/**
	 * @param	string	$code	Unit code
	 * @return	bool			true if the code is in UN/ECE Rec. 20/21 (as known by horstoeko/zugferd)
	 */
	protected function isValidUnitCode($code)
	{
		static $codes = null;
		if ($codes === null) {
			$codes = array();
			try {
				$reflection = new ReflectionClass(ZugferdUnitCodes::class);
				foreach ($reflection->getConstants() as $value) {
					$codes[(string) $value] = true;
				}
			} catch (Throwable $e) {
				$codes = array();
			}
		}
		return $code !== '' && isset($codes[$code]);
	}

	/**
	 * @param	int	$timestamp	Unix timestamp (Dolibarr date)
	 * @return	DateTime
	 */
	protected function toDateTime($timestamp)
	{
		$dt = new DateTime('@'.((int) $timestamp));
		$dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
		return $dt;
	}
}
