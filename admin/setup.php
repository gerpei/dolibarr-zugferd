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
 * \file    htdocs/custom/zugferd/admin/setup.php
 * \ingroup zugferd
 * \brief   Zugferd setup page.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once dol_buildpath('/zugferd/lib/zugferd.lib.php', 0);

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 * @var Societe $mysoc
 */

$langs->loadLangs(array("admin", "companies", "zugferd@zugferd"));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

$formSetup = new FormSetup($db);

$item = $formSetup->newItem('ZUGFERD_STRICT_MODE');
$item->setAsYesNo();
$item->nameText = $langs->trans('ZugferdStrictMode');
$item->helpText = $langs->trans('ZugferdStrictModeHelp');

$item = $formSetup->newItem('ZUGFERD_REQUIRE_LEITWEG');
$item->setAsYesNo();
$item->nameText = $langs->trans('ZugferdRequireLeitweg');
$item->helpText = $langs->trans('ZugferdRequireLeitwegHelp');

$item = $formSetup->newItem('ZUGFERD_DELIVERY_DATE_IS_INVOICE_DATE');
$item->setAsYesNo();
$item->nameText = $langs->trans('ZugferdDeliveryDateIsInvoiceDate');
$item->helpText = $langs->trans('ZugferdDeliveryDateIsInvoiceDateHelp');

$item = $formSetup->newItem('ZUGFERD_DEFAULT_ZERO_CATEGORY');
$item->setAsSelect(array(
	'' => $langs->trans('ZugferdZeroCategoryNone'),
	'E' => $langs->trans('ZugferdZeroCategoryE'),
	'AE' => $langs->trans('ZugferdZeroCategoryAE'),
	'Z' => $langs->trans('ZugferdZeroCategoryZ'),
));
$item->nameText = $langs->trans('ZugferdDefaultZeroCategory');
$item->helpText = $langs->trans('ZugferdDefaultZeroCategoryHelp');

$item = $formSetup->newItem('ZUGFERD_EXEMPT_REASON');
$item->setAsString();
$item->nameText = $langs->trans('ZugferdExemptReason');
$item->helpText = $langs->trans('ZugferdExemptReasonHelp');

$item = $formSetup->newItem('ZUGFERD_SMALL_BUSINESS_TEXT');
$item->setAsString();
$item->nameText = $langs->trans('ZugferdSmallBusinessText');
$item->helpText = $langs->trans('ZugferdSmallBusinessTextHelp');

$item = $formSetup->newItem('ZUGFERD_SELLER_CONTACT');
$item->setAsString();
$item->nameText = $langs->trans('ZugferdSellerContact');
$item->helpText = $langs->trans('ZugferdSellerContactHelp');

$item = $formSetup->newItem('ZUGFERD_SELLER_TAXNUMBER_FIELD');
$profOptions = array();
for ($k = 1; $k <= 6; $k++) {
	$label = $langs->transcountry('ProfId'.$k, $mysoc->country_code);
	$profOptions['idprof'.$k] = $label.' (idprof'.$k.')';
}
$item->setAsSelect($profOptions);
$item->nameText = $langs->trans('ZugferdSellerTaxNumberField');
$item->helpText = $langs->trans('ZugferdSellerTaxNumberFieldHelp');

include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';


/*
 * View
 */

llxHeader('', $langs->trans('ZugferdSetup'), '', '', 0, 0, '', '', '', 'mod-zugferd page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('ZugferdSetup'), $linkback, 'title_setup');

$head = zugferdAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('ZugferdSetup'), -1, "bill");

print '<span class="opacitymedium">'.$langs->trans('ZugferdSetupPage').'</span><br><br>';

// Check of master data needed for every e-invoice
$checks = array();
$checks[] = array($langs->trans('ZugferdCheckLibrary'), zugferdLoadVendor(), $langs->trans('ZugferdErrorMissingVendor'));
$checks[] = array($langs->trans('CompanyName'), !empty($mysoc->name), '');
$checks[] = array($langs->trans('CompanyAddress'), !empty($mysoc->address) && !empty($mysoc->zip) && !empty($mysoc->town) && !empty($mysoc->country_code), '');
$checks[] = array($langs->trans('VATIntra').' / '.$langs->trans('ZugferdTaxNumber'), !empty($mysoc->tva_intra) || !empty($mysoc->idprof1), '');
$checks[] = array($langs->trans('EMail'), !empty($mysoc->email), $langs->trans('ZugferdErrorMissingSellerEmail'));
$checks[] = array($langs->trans('Phone'), !empty($mysoc->phone), $langs->trans('ZugferdErrorMissingSellerPhone'));
$ibanOk = false;
$sql = "SELECT COUNT(*) as nb FROM ".$db->prefix()."bank_account WHERE clos = 0 AND iban_prefix IS NOT NULL AND iban_prefix <> '' AND entity IN (".getEntity('bank_account').")";
$resql = $db->query($sql);
if ($resql && ($obj = $db->fetch_object($resql))) {
	$ibanOk = ((int) $obj->nb > 0);
}
$checks[] = array('IBAN', $ibanOk, $langs->trans('ZugferdErrorMissingIban'));

print load_fiche_titre($langs->trans('ZugferdCheckTitle'), '', '');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td class="center">'.$langs->trans('Status').'</td><td>'.$langs->trans('Note').'</td></tr>';
foreach ($checks as $check) {
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($check[0]).'</td>';
	print '<td class="center">'.($check[1] ? img_picto($langs->trans('Ok'), 'tick') : img_warning($langs->trans('Missing'))).'</td>';
	print '<td class="opacitymedium">'.($check[1] ? '' : dol_escape_htmltag($check[2])).'</td></tr>';
}
print '</table></div><br>';

if (!empty($formSetup->items)) {
	print $formSetup->generateOutput(true);
}

print dol_get_fiche_end();

llxFooter();
$db->close();
