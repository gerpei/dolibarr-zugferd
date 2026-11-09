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
 * \file    htdocs/custom/zugferd/lib/zugferd.lib.php
 * \ingroup zugferd
 * \brief   Library files with common functions for Zugferd
 */

/**
 * Prepare admin pages header
 *
 * @return	array<int,array{0:string,1:string,2:string}>	tabs
 */
function zugferdAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("zugferd@zugferd");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/zugferd/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/zugferd/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'zugferd@zugferd');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'zugferd@zugferd', 'remove');

	return $head;
}

/**
 * Integer setup value with default (works with Dolibarr 16+).
 *
 * @param	string	$name		Constant name
 * @param	int		$default	Default if not set
 * @return	int
 */
function zugferdGetConstInt($name, $default = 0)
{
	global $conf;
	if (!isset($conf->global->$name) || $conf->global->$name === '') {
		return (int) $default;
	}
	return (int) $conf->global->$name;
}

/**
 * String setup value with default (works with Dolibarr 16+).
 *
 * @param	string	$name		Constant name
 * @param	string	$default	Default if not set
 * @return	string
 */
function zugferdGetConstString($name, $default = '')
{
	global $conf;
	if (!isset($conf->global->$name) || trim((string) $conf->global->$name) === '') {
		return (string) $default;
	}
	return trim((string) $conf->global->$name);
}

/**
 * Whether the user may read customer invoices.
 *
 * @param	User	$user	User
 * @return	bool
 */
function zugferdUserCanReadInvoices($user)
{
	if (method_exists($user, 'hasRight')) {
		return (bool) $user->hasRight('facture', 'lire');
	}
	return !empty($user->rights->facture->lire);
}

/**
 * Whether the user may create documents for customer invoices.
 *
 * @param	User	$user	User
 * @return	bool
 */
function zugferdUserCanGenerate($user)
{
	if (method_exists($user, 'hasRight')) {
		return (bool) $user->hasRight('facture', 'creer');
	}
	return !empty($user->rights->facture->creer);
}

/**
 * Load the bundled Composer autoloader (horstoeko/zugferd and dependencies).
 *
 * @return	bool
 */
function zugferdLoadVendor()
{
	static $loaded = null;
	if ($loaded !== null) {
		return $loaded;
	}
	$autoload = dol_buildpath('/zugferd/vendor/autoload.php', 0);
	if (!is_readable($autoload)) {
		$loaded = false;
		return false;
	}
	require_once $autoload;
	$loaded = class_exists('horstoeko\zugferd\ZugferdDocumentBuilder');
	return $loaded;
}

/**
 * Directory of the documents of an invoice.
 *
 * @param	Facture	$object	Invoice
 * @return	string
 */
function zugferdInvoiceDocumentDir($object)
{
	global $conf;

	$ref = dol_sanitizeFileName($object->ref);
	if (!empty($object->entity) && !empty($conf->facture->multidir_output[$object->entity])) {
		return $conf->facture->multidir_output[$object->entity].'/'.$ref;
	}
	if (!empty($conf->facture->dir_output)) {
		return $conf->facture->dir_output.'/'.$ref;
	}

	return '';
}

/**
 * @param	Facture	$object	Invoice
 * @return	string
 */
function zugferdXmlFileName($object)
{
	return dol_sanitizeFileName($object->ref).'_xrechnung.xml';
}

/**
 * @param	Facture	$object	Invoice
 * @return	string
 */
function zugferdPdfFileName($object)
{
	return dol_sanitizeFileName($object->ref).'_zugferd.pdf';
}

/**
 * Write a generated file into the invoice document folder.
 *
 * @param	Facture	$object		Invoice
 * @param	string	$filename	Basename
 * @param	string	$content	File contents
 * @return	string|false		Full path or false
 */
function zugferdSaveInvoiceFile($object, $filename, $content)
{
	require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

	$dir = zugferdInvoiceDocumentDir($object);
	if ($dir === '') {
		return false;
	}
	if (!is_dir($dir)) {
		dol_mkdir($dir);
	}
	if (!is_dir($dir)) {
		return false;
	}

	$path = $dir.'/'.$filename;
	$tmp = $path.'.tmp';
	if (file_put_contents($tmp, $content) === false) {
		return false;
	}
	if (!@rename($tmp, $path)) {
		@unlink($tmp);
		return false;
	}
	if (function_exists('dolChmod')) {
		dolChmod($path);
	}

	return $path;
}

/**
 * (Re)generate the standard invoice PDF as basis for ZUGFeRD.
 *
 * ZUGFeRD requires PDF/A-3. Dolibarr creates PDF/A when PDF_USE_A is set; with standard
 * core fonts (Helvetica) the fonts would otherwise not be embedded. Unless disabled in the
 * module setup, the PDF is generated with PDF_USE_A = 3 for this request only.
 *
 * @param	Facture		$object	Invoice
 * @param	Translate	$langs	Output language
 * @return	string|false		Full path of the PDF or false
 */
function zugferdGenerateVisualPdf($object, $langs)
{
	global $conf;

	$dir = zugferdInvoiceDocumentDir($object);
	if ($dir === '') {
		return false;
	}
	$file = $dir.'/'.dol_sanitizeFileName($object->ref).'.pdf';

	$model = !empty($object->model_pdf) ? $object->model_pdf : getDolGlobalString('FACTURE_ADDON_PDF');
	if ($model === '') {
		return is_readable($file) ? $file : false;
	}

	$forcePdfA = zugferdGetConstInt('ZUGFERD_PDFA_SOURCE', 1);
	$previous = isset($conf->global->PDF_USE_A) ? $conf->global->PDF_USE_A : null;
	if ($forcePdfA) {
		$conf->global->PDF_USE_A = 3;
	}

	$outputlangs = $langs;
	if (getDolGlobalInt('MAIN_MULTILANGS') && !empty($object->thirdparty->default_lang)) {
		$outputlangs = new Translate('', $conf);
		$outputlangs->setDefaultLang($object->thirdparty->default_lang);
		$outputlangs->loadLangs(array('main', 'bills', 'products', 'dict', 'companies'));
	}
	$result = $object->generateDocument($model, $outputlangs);

	if ($forcePdfA) {
		if ($previous === null) {
			unset($conf->global->PDF_USE_A);
		} else {
			$conf->global->PDF_USE_A = $previous;
		}
	}

	if ($result <= 0 || !is_readable($file)) {
		dol_syslog(__FUNCTION__.' generateDocument failed: '.$object->error, LOG_ERR);
		return false;
	}
	return $file;
}

/**
 * Translate a builder message key ("Key|param1|param2").
 *
 * @param	Translate	$langs	Langs
 * @param	string		$msg	Message key with optional parameters
 * @return	string
 */
function zugferdTransMessage($langs, $msg)
{
	$parts = explode('|', $msg);
	$key = array_shift($parts);
	$p = array_pad($parts, 4, '');
	return $langs->trans($key, $p[0], $p[1], $p[2], $p[3]);
}
