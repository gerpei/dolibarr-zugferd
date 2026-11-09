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
 * \file    htdocs/custom/zugferd/admin/about.php
 * \ingroup zugferd
 * \brief   About page of module Zugferd.
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once dol_buildpath('/zugferd/lib/zugferd.lib.php', 0);
require_once dol_buildpath('/zugferd/core/modules/modZugferd.class.php', 0);

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "zugferd@zugferd"));

if (!$user->admin) {
	accessforbidden();
}

$module = new modZugferd($db);

llxHeader('', $langs->trans('ZugferdSetup'), '', '', 0, 0, '', '', '', 'mod-zugferd page-admin-about');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('ZugferdSetup'), $linkback, 'title_setup');

$head = zugferdAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $langs->trans('ZugferdSetup'), -1, 'bill');

print '<div class="fichecenter">';
print $module->getDescLong();
print '<br><table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('Version').'</td><td>'.dol_escape_htmltag($module->getVersion()).'</td></tr>';
print '<tr><td>'.$langs->trans('Publisher').'</td><td>'.dol_escape_htmltag($module->editor_name).'</td></tr>';
print '<tr><td>'.$langs->trans('License').'</td><td>GPL-3.0-or-later</td></tr>';
print '</table></div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
