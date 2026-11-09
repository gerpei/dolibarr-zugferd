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
 * \file    htdocs/custom/zugferd/class/actions_zugferd.class.php
 * \ingroup zugferd
 * \brief   Hooks: buttons on the invoice card, file generation and e-mail attachments.
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once dol_buildpath('/zugferd/lib/zugferd.lib.php', 0);

/**
 * Hook actions for module Zugferd
 */
class ActionsZugferd
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var array<string,mixed> */
	public $results = array();

	/** @var string */
	public $resprints = '';

	/**
	 * @param	DoliDB	$db	Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Add the generate buttons on the customer invoice card.
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters
	 * @param	CommonObject		$object			Invoice
	 * @param	string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		if (!$this->isInvoiceContext($parameters, $object)) {
			return 0;
		}
		if (!zugferdUserCanGenerate($user)) {
			return 0;
		}
		if ($this->invoiceStatus($object) < Facture::STATUS_VALIDATED) {
			return 0;
		}

		$langs->load('zugferd@zugferd');
		$urlBase = $_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&token='.urlencode(newToken());

		print '<a class="butAction" href="'.$urlBase.'&action=zugferd_gen_xml" title="'.dol_escape_htmltag($langs->trans('ZugferdGenerateXmlHelp')).'">'.$langs->trans('ZugferdGenerateXml').'</a>';
		print '<a class="butAction" href="'.$urlBase.'&action=zugferd_gen_pdf" title="'.dol_escape_htmltag($langs->trans('ZugferdGeneratePdfHelp')).'">'.$langs->trans('ZugferdGeneratePdf').'</a>';

		return 0;
	}

	/**
	 * Generate XRechnung / ZUGFeRD files into the invoice documents folder.
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters
	 * @param	CommonObject		$object			Invoice
	 * @param	string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user, $conf;

		if ($action !== 'zugferd_gen_xml' && $action !== 'zugferd_gen_pdf') {
			return 0;
		}
		if (!$this->isInvoiceContext($parameters, $object)) {
			return 0;
		}

		$langs->load('zugferd@zugferd');
		$action = '';

		// CSRF protection (links carry the session token)
		if (function_exists('currentToken') && GETPOST('token', 'alpha') !== currentToken()) {
			setEventMessages($langs->trans('ZugferdErrorBadToken'), null, 'errors');
			return 0;
		}
		if (!zugferdUserCanGenerate($user)) {
			setEventMessages($langs->trans('ZugferdErrorNoPermission'), null, 'errors');
			return 0;
		}
		if (empty($object->ref)) {
			setEventMessages($langs->trans('ZugferdErrorMissingRef'), null, 'errors');
			return 0;
		}
		if ($this->invoiceStatus($object) < Facture::STATUS_VALIDATED) {
			setEventMessages($langs->trans('ZugferdErrorNotValidated'), null, 'errors');
			return 0;
		}

		require_once dol_buildpath('/zugferd/class/zugferdinvoicebuilder.class.php', 0);
		$builder = new ZugferdInvoiceBuilder($this->db);
		$generateXml = (GETPOST('action', 'aZ09') === 'zugferd_gen_xml');

		if ($generateXml) {
			$content = $builder->buildXRechnungXml($object);
			$filename = zugferdXmlFileName($object);
			$okMessage = 'ZugferdXmlGenerated';
		} else {
			$pdfPath = zugferdGenerateVisualPdf($object, $langs);
			if ($pdfPath === false) {
				setEventMessages($langs->trans('ZugferdErrorNoPdf'), null, 'errors');
				return 0;
			}
			$content = $builder->buildZugferdPdf($object, $pdfPath);
			$filename = zugferdPdfFileName($object);
			$okMessage = 'ZugferdPdfGenerated';
		}

		if ($content === false) {
			$this->showBuilderErrors($builder, $langs);
			return 0;
		}
		if (zugferdSaveInvoiceFile($object, $filename, $content) === false) {
			setEventMessages($langs->trans('ZugferdErrorSaveFailed'), null, 'errors');
			return 0;
		}

		setEventMessages($langs->trans($okMessage, $filename), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id));
		exit;
	}

	/**
	 * Preselect the e-invoice as attachment in the "send by e-mail" form of an invoice:
	 * - ZUGFeRD PDF exists: it replaces the standard PDF (it contains the visual invoice and the XML)
	 * - otherwise XRechnung XML exists: it is attached in addition to the standard PDF
	 * The user can still add or remove files in the form.
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters
	 * @param	FormMail			$object			Mail form
	 * @param	string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0
	 */
	public function getFormMail($parameters, &$object, &$action, $hookmanager)
	{
		if (empty($object->param['models']) || $object->param['models'] !== 'facture_send') {
			return 0;
		}
		$id = !empty($object->param['id']) ? (int) $object->param['id'] : 0;
		if ($id <= 0) {
			return 0;
		}

		$invoice = new Facture($this->db);
		if ($invoice->fetch($id) <= 0) {
			return 0;
		}
		$dir = zugferdInvoiceDocumentDir($invoice);
		if ($dir === '') {
			return 0;
		}
		$zugferd = $dir.'/'.zugferdPdfFileName($invoice);
		$xml = $dir.'/'.zugferdXmlFileName($invoice);
		$standard = $dir.'/'.dol_sanitizeFileName($invoice->ref).'.pdf';

		$files = (!empty($object->param['fileinit']) && is_array($object->param['fileinit'])) ? array_values(array_filter($object->param['fileinit'])) : array();
		if (is_readable($zugferd)) {
			$files = array_values(array_filter($files, function ($f) use ($standard) {
				return $f !== $standard;
			}));
			array_unshift($files, $zugferd);
		} elseif (is_readable($xml)) {
			$files[] = $xml;
		} else {
			return 0;
		}
		$object->param['fileinit'] = array_values(array_unique($files));

		return 0;
	}

	/**
	 * @param	array<string,mixed>	$parameters	Hook parameters
	 * @param	CommonObject		$object		Object
	 * @return	bool
	 */
	protected function isInvoiceContext($parameters, $object)
	{
		if (!isModEnabled('zugferd')) {
			return false;
		}
		if (empty($parameters['currentcontext']) || $parameters['currentcontext'] !== 'invoicecard') {
			return false;
		}
		return !empty($object) && !empty($object->id) && $object->element === 'facture';
	}

	/**
	 * @param	CommonObject	$object	Invoice
	 * @return	int
	 */
	protected function invoiceStatus($object)
	{
		return isset($object->status) ? (int) $object->status : (int) $object->statut;
	}

	/**
	 * @param	ZugferdInvoiceBuilder	$builder	Builder
	 * @param	Translate				$langs		Langs
	 * @return	void
	 */
	protected function showBuilderErrors(ZugferdInvoiceBuilder $builder, $langs)
	{
		$messages = array();
		foreach ($builder->errors as $msg) {
			$messages[] = zugferdTransMessage($langs, $msg);
		}
		if (empty($messages)) {
			$messages[] = $langs->trans('ZugferdErrorBuildFailed');
		}
		setEventMessages($langs->trans('ZugferdErrorTitle'), $messages, 'errors');
	}
}
