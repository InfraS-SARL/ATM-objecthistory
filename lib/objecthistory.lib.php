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
 *	\file		lib/objecthistory.lib.php
 *	\ingroup	objecthistory
 *	\brief		This file is an example module library
 *				Put some comments here
 */

/**
 * Prepare admin pages header
 *
 * @return array  Array of tabs to show
 */
function objecthistoryAdminPrepareHead()
{
	global $db,$langs,$conf, $object;

	$langs->load("objecthistory@objecthistory");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/objecthistory/admin/objecthistory_setup.php", 1);
	$head[$h][1] = $langs->trans("Parameters");
	$head[$h][2] = 'settings';
	$h++;

	$res = dol_include_once('/propalehistory/core/modules/modPropalehistory.class.php');
	if ($res) {
		$langs->load('propalehistory@propalehistory');
		$sql = 'DESC '.MAIN_DB_PREFIX.'propale_history';
		$resql = $db->query($sql);
		if ($resql && ($row = $db->fetch_row($resql))) {
			$head[$h][0] = dol_buildpath("/objecthistory/admin/objecthistory_migrate_propalehistory.php", 1);
			$head[$h][1] = $langs->trans("Module104090Name");
			$head[$h][2] = 'propalehistory';
			$h++;
		}
	}

	$head[$h][0] = dol_buildpath("/objecthistory/admin/objecthistory_about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@objecthistory:/objecthistory/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@objecthistory:/objecthistory/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, $object, $head, $h, 'objecthistory');

	return $head;
}

/**
 * Return array of tabs to used on pages for third parties cards.
 *
 * @param 	ObjectHistory	$object		Object company shown
 * @return 	array				Array of tabs
 */
function objecthistory_prepare_head(ObjectHistory $object)
{
	global $db, $langs, $conf, $user;
	$h = 0;
	$head = array();
	$head[$h][0] = dol_buildpath('/objecthistory/card.php', 1).'?id='.$object->id;
	$head[$h][1] = $langs->trans("ObjectHistoryCard");
	$head[$h][2] = 'card';
	$h++;

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	// $this->tabs = array('entity:+tabname:Title:@objecthistory:/objecthistory/mypage.php?id=__ID__');   to add new tab
	// $this->tabs = array('entity:-tabname:Title:@objecthistory:/objecthistory/mypage.php?id=__ID__');   to remove a tab
	complete_head_from_modules($conf, $langs, $object, $head, $h, 'objecthistory');

	return $head;
}

/**
 * Get form confirm
 *
 * @param Form   $form   Form handler
 * @param object $object Current object
 * @param string $action Current action
 * @return string        HTML Content
 */
function getFormConfirmObjectHistory(&$form, &$object, $action)
{
	global $langs,$user;

	$formconfirm = '';

	if ($action == 'objecthistory_migrate' && !empty($user->admin)) {
		$text = $langs->trans('ConfirmMigrateObjectHistory');
		$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'], $langs->trans('MigrateObjectHistory'), $text, 'confirm_migrate', '', 0, 1);
	} elseif ($action == 'objecthistory_modif') {
		// Cas à part pour les commandes clients où un formconfirm s'affiche pour la ré-ouverture
		if ($object->element == 'commande') {
			$title = $langs->trans('UnvalidateOrder');
			$text = $langs->trans('ConfirmUnvalidateOrder', $object->ref);
		} else {
			$title = $langs->trans('ObjectHistoryModify');
			$text = $langs->trans('ConfirmModifyObject', $object->ref);
		}

		$formquestion = array(
			array(
				'type' => 'checkbox'
				,'name' => 'archive_object'
				,'label' => $langs->trans("ArchiveObjectCheckboxLabel")
				,'value' => 1
			)
		);
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $title, $text, 'objecthistory_confirm_modify', $formquestion, 'yes', 1);
	} elseif ($action == 'view_archive') {
		global $conf;

		$values = array();
		$TVersion = ObjectHistory::getAllVersionBySourceId($object->id, $object->element);

		$i=1;
		foreach ($TVersion as &$objecthistory) {
			$values[$objecthistory->id] = 'Version n° '.$i.' - '.price($objecthistory->total).' '.$langs->getCurrencySymbol($conf->currency, 0).' - '.dol_print_date($objecthistory->date_creation, "dayhour");
			$i++;
		}

		$formquestion = array(
			array(
				'type' => 'select'
				,'name' => 'idVersion'
				,'label' => $langs->trans("ObjectHistorySelectVersion")
				,'values' => $values
			)
		);
		$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . $object->id, $langs->trans('ObjectHistoryViewArchive'), '', 'confirm_view_archive', $formquestion, 0, 1);
	} elseif ($action == 'delete_archive') {
		$formquestion = array(
			array(
				'type' => 'hidden'
				,'name' => 'idVersion'
				,'value' => GETPOST('idVersion', 'int')
			)
		);
		$text = $langs->trans('ObjectHistoryConfirmDelete', $object->ref);
		$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . $object->id, $langs->trans('ObjectHistoryDeleteArchive'), $text, 'confirm_delete_archive', $formquestion, 0, 1);
	}

	return $formconfirm;
}

/**
 * Get HTML list of object history
 *
 * @param Propal|Commande|Facture|SupplierProposal|CommandeFournisseur|FactureFournisseur $object   Current object
 * @param ObjectHistory[]                                                                 $TVersion Array of versions
 * @param string                                                                          $action   Current action
 * @return string                                                                                   HTML Content
 */
function getHtmlListObjectHistory($object, $TVersion, $action)
{
	global $langs;

	$html = '';

	if (!empty($TVersion)) {
		if ($action == 'confirm_view_archive' || $action == 'delete_archive') $html.= '<div class="linkback" style="margin:15px"><a id="returnCurrent" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">'.$langs->trans('ReturnInitialVersion').'</a></div>';

		$idVersion = GETPOST('idVersion', 'int');

		$html.= '<div class="inline-block divButAction"><a id="butViewArchive" class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=view_archive&token='.newToken().'">'.$langs->trans('ObjectHistoryViewArchive').'</a></div>';

		if ($action == 'confirm_view_archive' || $action == 'delete_archive') {
			$html.= '<div class="inline-block divButAction"><a id="butRestaurer" class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=restore_archive&idVersion='.$idVersion.'&token='.newToken().'">'.$langs->trans('ObjectHistoryRestoreArchive').'</a></div>';
			$html.= '<div class="inline-block divButAction"><a id="butSupprimer" class="butActionDelete" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=delete_archive&idVersion='.$idVersion.'&token='.newToken().'">'.$langs->trans('ObjectHistoryDeleteArchive').'</a></div>';
		}
	}

	if ($action != 'confirm_view_archive' && $action != 'delete_archive' && ($object->element != 'order_supplier' && $object->statut == 1 || $object->element == 'order_supplier' && $object->statut == 2)) $html.= '<div class="inline-block divButAction"><a id="butNewVersion" class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=create_archive&token='.newToken().'">'.$langs->trans('ObjectHistoryArchiver').'</a></div>';

	return $html;
}
