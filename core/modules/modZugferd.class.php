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
 * \defgroup   zugferd     Module Zugferd
 * \brief      XRechnung and ZUGFeRD export for customer invoices.
 *
 * \file       htdocs/custom/zugferd/core/modules/modZugferd.class.php
 * \ingroup    zugferd
 * \brief      Description and activation file for module Zugferd
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module Zugferd
 */
class modZugferd extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		// Module id. External modules must use an id registered on https://wiki.dolibarr.org/index.php/List_of_modules_id
		$this->numero = 231240; // reserved range 231240-231249 (DoliCare)
		$this->rights_class = 'zugferd';
		$this->family = "financial";
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "ModuleZugferdDesc";
		$this->descriptionlong = "ModuleZugferdDescLong";
		$this->editor_name = 'DoliCare - Gerhard Peilstöcker';
		$this->editor_url = 'https://dolicare.de';
		$this->version = '1.2.1';
		$this->url_last_version = '';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'bill';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array('invoicecard', 'formmail'),
			'moduleforexternal' => 0,
		);

		$this->dirs = array();
		$this->config_page_url = array("setup.php@zugferd");
		$this->hidden = false;
		$this->depends = array('modSociete', 'modFacture');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("zugferd@zugferd");
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(16, 0);
		$this->need_javascript_ajax = 0;
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constants: name, type, value, description, visible, entity ('current' or 'allentities'), delete on unactive
		$this->const = array(
			1 => array('ZUGFERD_REQUIRE_LEITWEG', 'yesno', '0', 'Require Leitweg-ID on the customer', 0, 'current', 0),
			2 => array('ZUGFERD_STRICT_MODE', 'yesno', '1', 'Require full contact data also for ZUGFeRD', 0, 'current', 0),
			3 => array('ZUGFERD_DELIVERY_DATE_IS_INVOICE_DATE', 'yesno', '1', 'Use invoice date as delivery date if no service period is set', 0, 'current', 0),
		);

		if (!isset($conf->zugferd) || !isModEnabled("zugferd")) {
			$conf->zugferd = new stdClass();
			$conf->zugferd->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();
		$this->rights = array();
		$this->menu = array();
	}

	/**
	 * Function called when module is enabled.
	 * The init function adds constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 *
	 * @param	string	$options	Options when enabling module ('', 'noboxes')
	 * @return	int					1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);

		// Extra fields on third parties: Leitweg-ID (BT-10) and electronic address (BT-49)
		$extrafields->addExtraField('zugferd_leitweg', 'ZugferdLeitwegId', 'varchar', 210, '50', 'societe', 0, 0, '', '', 1, '', '1', 'ZugferdLeitwegIdHelp', '', '', 'zugferd@zugferd', 'isModEnabled("zugferd")');
		$extrafields->addExtraField('zugferd_endpoint', 'ZugferdEndpointId', 'varchar', 211, '255', 'societe', 0, 0, '', '', 1, '', '1', 'ZugferdEndpointIdHelp', '', '', 'zugferd@zugferd', 'isModEnabled("zugferd")');

		$this->remove($options);

		return $this->_init(array(), $options);
	}

	/**
	 * Function called when module is disabled.
	 * Data (extra fields, generated files) is kept.
	 *
	 * @param	string	$options	Options when disabling module ('', 'noboxes')
	 * @return	int					1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
