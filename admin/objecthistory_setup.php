<?php
/* <one line to give the program's name and a brief idea of what it does.>
 * Copyright (C) 2015 ATM Consulting <support@atm-consulting.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * 	\file		admin/objecthistory.php
 * 	\ingroup	objecthistory
 * 	\brief		This file is an example module setup page
 * 				Put some comments here
 */
// Dolibarr environment
$res = @include "../../main.inc.php"; // From htdocs directory
if (! $res) {
	$res = @include "../../../main.inc.php"; // From "custom" directory
}

// Libraries
require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";
require_once '../class/objecthistory.class.php';
require_once '../lib/objecthistory.lib.php';
dol_include_once('abricot/includes/lib/admin.lib.php');

// Translations
$langs->loadLangs(array("admin", "objecthistory@objecthistory"));

// Access control
if (! $user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'alpha');

/*
 * Actions
 */
if (preg_match('/set_(.*)/', $action, $reg)) {
	$code=$reg[1];
	$val = GETPOST($code);
	if ($code == 'OBJECTHISTORY_HOOKS_ALLOWED' && !empty($val)) $val = implode(',', $val);

	if (dolibarr_set_const($db, $code, $val, 'chaine', 0, '', $conf->entity) > 0) {
		header("Location: ".$_SERVER["PHP_SELF"]);
		exit;
	} else {
		dol_print_error($db);
	}
}

if (preg_match('/del_(.*)/', $action, $reg)) {
	$code=$reg[1];
	if (dolibarr_del_const($db, $code, 0) > 0) {
		Header("Location: ".$_SERVER["PHP_SELF"]);
		exit;
	} else {
		dol_print_error($db);
	}
}

/*
 * View
 */
$page_name = "ObjectHistorySetup";
llxHeader('', $langs->trans($page_name));

// Subheader
$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php">'
	. $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback);

$notab = -1;
// Configuration header
$head = objecthistoryAdminPrepareHead();
dol_fiche_head(
	$head,
	'settings',
	$langs->trans("Module104089Name"),
	$notab,
	"objecthistory@objecthistory"
);

// Setup page goes here
$form=new Form($db);
$var=false;
print '<table class="noborder" width="100%">';


if (!function_exists('setup_print_title')) {
	print '<div class="error" >'.$langs->trans('AbricotNeedUpdate').' : <a href="http://wiki.atm-consulting.fr/index.php/Accueil#Abricot" target="_blank"><i class="fa fa-info"></i> Wiki</a></div>';
	exit;
}

setup_print_title("Parameters");

$var=!$var;

print '<tr '.$bc[$var].'>';
print '<td>'.$langs->trans('OBJECTHISTORY_HOOKS_ALLOWED').'</td>';
print '<td align="center" width="20">&nbsp;</td>';
print '<td align="right" width="500">';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.$_SESSION['newtoken'].'">';
print '<input type="hidden" name="action" value="set_OBJECTHISTORY_HOOKS_ALLOWED">';
$THook = array();
if (isModEnabled('propal')) $THook['propalcard'] = $langs->trans('objecthistory_propalcard');
if (isModEnabled('commande')) $THook['ordercard'] = $langs->trans('objecthistory_ordercard');
if (isModEnabled('supplier_proposal')) $THook['supplier_proposalcard'] = $langs->trans('objecthistory_supplier_proposalcard');
if (isModEnabled('fournisseur')) $THook['ordersuppliercard'] = $langs->trans('objecthistory_ordersuppliercard');
print $form->multiselectarray('OBJECTHISTORY_HOOKS_ALLOWED', $THook, ObjectHistory::getTHookAllowed());
print '<input type="submit" class="butAction" value="'.$langs->trans("Modify").'">';
print '</form>';
print '</td></tr>';

// Example with a yes / no select
setup_print_on_off('OBJECTHISTORY_AUTO_ARCHIVE');
setup_print_on_off('OBJECTHISTORY_ARCHIVE_ON_MODIFY');
setup_print_on_off('OBJECTHISTORY_SHOW_VERSION_PDF');
setup_print_on_off('OBJECTHISTORY_HIDE_VERSION_ON_TABS');
setup_print_on_off('OBJECTHISTORY_ARCHIVE_PDF_TOO');
setup_print_on_off('OBJECTHISTORY_USE_COMPRESS_ARCHIVE');

// Example with imput
//setup_print_input_form_part('CONSTNAME', 'ParamLabel');

// Example with color
//setup_print_input_form_part('CONSTNAME', 'ParamLabel', 'ParamDesc', array('type'=>'color'),'input','ParamHelp');

// Example with placeholder
//setup_print_input_form_part('CONSTNAME','ParamLabel','ParamDesc',array('placeholder'=>'http://'),'input','ParamHelp');

// Example with textarea
//setup_print_input_form_part('CONSTNAME','ParamLabel','ParamDesc',array(),'textarea');


print '</table>';

dol_fiche_end();

?>
<script type="text/javascript">
	$(function() {
		$('#set_OBJECTHISTORY_AUTO_ARCHIVE').click(function(event) {
			if ($('#del_OBJECTHISTORY_ARCHIVE_ON_MODIFY').css('display') !== 'none') {
				$('#del_OBJECTHISTORY_ARCHIVE_ON_MODIFY').click();
			}
		});

		$('#set_OBJECTHISTORY_ARCHIVE_ON_MODIFY').click(function(event) {
			if ($('#del_OBJECTHISTORY_AUTO_ARCHIVE').css('display') !== 'none') {
				$('#del_OBJECTHISTORY_AUTO_ARCHIVE').click();
			}
		});
	});
</script>
<?php

llxFooter($notab);

$db->close();
