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
 * \file    class/actions_objecthistory.class.php
 * \ingroup objecthistory
 * \brief   This file is an example hook overload class file
 *          Put some comments here
 */

/**
 * Class ActionsObjectHistory
 */
class ActionsObjectHistory
{
	/**
	 * @var DoliDB $db
	 */
	public $db;

	/**
	 * @var array Hook results. Propagated to $hookmanager->resArray for later reuse
	 */
	public $results = array();

	/**
	 * @var string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var array Errors
	 */
	public $errors = array();

	/**
	 * @var array Hooks allowed
	 */
	public $THook = array(
		'propalcard'
		,'ordercard'
		,'supplier_proposalcard'
		,'ordersuppliercard'
	);

	/**
	 * @var string
	 */
	public $old_object_ref;

	/**
	 * Return archive class name from Dolibarr element key.
	 *
	 * @param string $element Object element
	 * @return string
	 */
	protected function getClassFromElement(string $element): string
	{
		if ($element === 'propal') return 'PropalHistory';
		elseif ($element === 'commande') return 'CommandeHistory';
		elseif ($element === 'supplier_proposal') return 'SupplierProposalHistory';
		elseif ($element === 'order_supplier') return 'CommandeFournisseurHistory';

		return '';
	}

	/**
	 * Build a history object instance from an archived snapshot.
	 *
	 * @param CommonObject $object Current object
	 * @param int          $idVersion Archive id
	 * @return CommonObject|null
	 */
	protected function getArchivedObjectInstance(CommonObject $object, int $idVersion): ?CommonObject
	{
		global $langs;

		if (empty($idVersion) || empty($object->id)) return null;

		$id = $object->id;

		$className = $this->getClassFromElement($object->element);
		if (empty($className) || !class_exists($className)) return null;

		$archiveObject = new $className($this->db);

		if ($archiveObject->fetch($id) <= 0) {
			$this->errors[] = $langs->trans('ObjectHistoryArchiveFetchFailed');
			dol_syslog(__METHOD__.' archive fetch failed for class='.$className.' id='.$id, LOG_ERR);
			return null;
		}

		$version = new ObjectHistory($this->db);
		if ($version->fetch((int) $idVersion) <= 0) return null;
		$version->unserializeObject();

		if (empty($version->serialized_object_source) || !is_object($version->serialized_object_source)) {
			$this->errors[] = $langs->trans('ObjectHistoryArchiveSnapshotUnavailable');
			dol_syslog(__METHOD__.' serialized snapshot unavailable for version='.$idVersion, LOG_ERR);
			return null;
		}

		foreach ($version->serialized_object_source as $k => $v) {
			if ($k == 'db') continue;
			$archiveObject->{$k} = $v;
		}

		if (!empty($archiveObject->lines) && is_array($archiveObject->lines)) {
			foreach ($archiveObject->lines as &$line) {
				$line->description = $line->desc;
				$line->db = $this->db;
			}
		}

		return $archiveObject;
	}

	/**
	 * Return visual version number (V1, V2...) from archive id.
	 *
	 * @param CommonObject $object Current object
	 * @param int          $idVersion Archive id
	 * @return int
	 */
	protected function getArchiveVersionNumber(CommonObject $object, int $idVersion): int
	{
		$TVersion = ObjectHistory::getAllVersionBySourceId($object->id, $object->element);
		if (empty($TVersion)) return 0;

		$idx = array_search((int) $idVersion, array_map('intval', array_keys($TVersion)));
		if ($idx === false) return 0;

		return ((int) $idx) + 1;
	}

	/**
	 * Get document output directory for supported objects.
	 *
	 * @param CommonObject $object Current object
	 * @return string
	 */
	protected function getObjectOutputDir(CommonObject $object): string
	{
		global $conf;

		$filename = dol_sanitizeFileName($object->ref);

		if ($object->element == 'propal') {
			if (defined('PROP_OUTPUTDIR')) return PROP_OUTPUTDIR.'/'.$filename;
			if (!empty($conf->propal) && !empty($conf->propal->multidir_output) && isset($conf->propal->multidir_output[$object->entity])) return $conf->propal->multidir_output[$object->entity]."/".$filename;
		}
		elseif ($object->element == 'commande') {
			if (defined('COMMANDE_OUTPUTDIR')) return COMMANDE_OUTPUTDIR.'/'.$filename;
			if (!empty($conf->commande) && !empty($conf->commande->dir_output)) return $conf->commande->dir_output.'/'.$filename;
		}
		elseif ($object->element == 'supplier_proposal') {
			if (defined('SUPPLIER_PROPOSAL_OUTPUTDIR')) return SUPPLIER_PROPOSAL_OUTPUTDIR.'/'.$filename;
			if (!empty($conf->supplier_proposal) && !empty($conf->supplier_proposal->dir_output)) return $conf->supplier_proposal->dir_output.'/'.$filename;
		}
		elseif ($object->element == 'order_supplier') {
			// Older Dolibarr versions generally expose SUPPLIER_OUTPUTDIR for supplier docs/orders.
			if (defined('SUPPLIER_OUTPUTDIR') && !empty($conf->fournisseur) && !empty($conf->fournisseur->commande) && !empty($conf->fournisseur->commande->dir_output)) return $conf->fournisseur->commande->dir_output.'/'.$filename;
			if (!empty($conf->fournisseur) && !empty($conf->fournisseur->commande) && !empty($conf->fournisseur->commande->dir_output)) return $conf->fournisseur->commande->dir_output.'/'.$filename;
		}

		return '';
	}

	/**
	 * Generate/copy REF-Vn.pdf for an archived version while keeping current object version intact.
	 *
	 * @param CommonObject $object Current object (latest)
	 * @param int          $idVersion Archive id
	 * @return int >0 if success
	 */
	protected function buildArchivedPdfVersion(CommonObject $object, int $idVersion): int
	{
		global $langs, $hidedetails, $hidedesc, $hideref;

		$num = $this->getArchiveVersionNumber($object, $idVersion);
		if ($num <= 0) {
			$this->errors[] = $langs->trans('ObjectHistoryArchiveVersionNotFound');
			dol_syslog(__METHOD__.' archive version not found idVersion='.$idVersion.' objectId='.$object->id.' element='.$object->element, LOG_ERR);
			return -1;
		}

		$filename = dol_sanitizeFileName($object->ref);
		$filedir = $this->getObjectOutputDir($object);
		if (empty($filedir)) {
			$this->errors[] = $langs->trans('ObjectHistoryArchiveOutputDirNotFound');
			dol_syslog(__METHOD__.' output directory not found objectId='.$object->id.' element='.$object->element, LOG_ERR);
			return -1;
		}

		$sourceArchivedPdf = $filedir.'/'.$filename.'-'.$num.'.pdf';
		$targetVersionedPdf = $filedir.'/'.$filename.'-V'.$num.'.pdf';

		// Fast path: reuse archived pdf already stored by objecthistory.
		if (dol_is_file($sourceArchivedPdf)) {
			if (@copy($sourceArchivedPdf, $targetVersionedPdf)) return 1;
			$this->errors[] = $langs->trans('ObjectHistoryArchivePdfCopyFailed');
			dol_syslog(__METHOD__.' unable to copy archived PDF src='.$sourceArchivedPdf.' dst='.$targetVersionedPdf, LOG_ERR);
			return -1;
		}

		// Fallback: rebuild from serialized snapshot, then duplicate to REF-Vn.pdf
		$archiveObject = $this->getArchivedObjectInstance($object, $idVersion);
		if (!is_object($archiveObject)) {
			if (empty($this->errors)) $this->errors[] = $langs->trans('ObjectHistoryArchiveSnapshotUnavailable');
			dol_syslog(__METHOD__.' archived snapshot unavailable idVersion='.$idVersion.' objectId='.$object->id, LOG_ERR);
			return -1;
		}

		$currentPdf = $filedir.'/'.$filename.'.pdf';
		$tmpBackup = $filedir.'/'.$filename.'.pdf.objecthistorybak';
		$hadCurrentPdf = dol_is_file($currentPdf);
		if ($hadCurrentPdf) @copy($currentPdf, $tmpBackup);

		$model = GETPOST('model', 'alpha');
		if (empty($model)) $model = !empty($archiveObject->model_pdf) ? $archiveObject->model_pdf : $object->model_pdf;

		$res = $archiveObject->generateDocument($model, $langs, (int) $hidedetails, (int) $hidedesc, (int) $hideref);
		if ($res > 0 && dol_is_file($currentPdf)) {
			$res = @copy($currentPdf, $targetVersionedPdf) ? 1 : -1;
			if ($res <= 0) {
				$this->errors[] = $langs->trans('ObjectHistoryArchivePdfCopyFailed');
				dol_syslog(__METHOD__.' unable to copy generated PDF to versioned PDF dst='.$targetVersionedPdf, LOG_ERR);
			}
		}

		// Restore latest PDF if we temporarily overwrote it.
		if ($hadCurrentPdf && dol_is_file($tmpBackup)) {
			@copy($tmpBackup, $currentPdf);
			@unlink($tmpBackup);
		} elseif (!$hadCurrentPdf && dol_is_file($currentPdf)) {
			// No current PDF existed before archive rebuild: cleanup temporary file to avoid leaving Vn as current PDF.
			@unlink($currentPdf);
		}

		if ($res > 0) return 1;

		$this->errors[] = $langs->trans('ObjectHistoryArchivePdfGenerationFailed');
		dol_syslog(__METHOD__.' archived PDF generation failed idVersion='.$idVersion.' objectId='.$object->id, LOG_ERR);
		return -1;
	}

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs;

		$this->db = $db;

		if (!defined('INC_FROM_DOLIBARR')) define('INC_FROM_DOLIBARR', true);
		dol_include_once('/objecthistory/config.php');
		dol_include_once('/objecthistory/lib/objecthistory.lib.php');
		dol_include_once('/objecthistory/class/objecthistory.class.php');
		$langs->load('objecthistory@objecthistory');
	}

	/**
	 * Overloading the doActions function : replacing the parent's function with the one below
	 *
	 * @param   array         	$parameters     	Hook metadatas (context, etc...)
	 * @param   CommonObject    $object        The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action        Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf,$langs,$user;

		$TContext = explode(':', $parameters['context']);

		$interSect = array_intersect($TContext, ObjectHistory::getTHookAllowed());
		if (!empty($interSect)) {
			$idVersion = GETPOSTINT('idVersion');

			// When user generates PDF while viewing an archive, build REF-Vn.pdf from the archive instead of latest object.
			if ($action == 'builddoc' && $idVersion > 0) {
				$res = $this->buildArchivedPdfVersion($object, $idVersion);
				if ($res > 0) setEventMessages($langs->trans("FileGenerated"), null);
				else {
					dol_syslog(__METHOD__.' archive PDF generation error idVersion='.$idVersion.' objectId='.(int) $object->id, LOG_ERR);
					setEventMessages('', !empty($this->errors) ? $this->errors : array($langs->trans('ObjectHistoryArchivePdfGenerationFailed')), 'errors');
				}

				$action = 'confirm_view_archive';
				$archivedObject = $this->getArchivedObjectInstance($object, $idVersion);
				if (is_object($archivedObject)) $object = $archivedObject;

				return 1;
			}

			if (getDolGlobalString('OBJECTHISTORY_ARCHIVE_ON_MODIFY')) {
				// CommandeFournisseur = reopen
				if ($action == 'modif' || $object->element == 'order_supplier' && $object->statut == 2 && $action == 'reopen') {
					$action = 'objecthistory_modif';
					return 1; // on saute l'action par défaut en retournant 1, puis on affiche la pop-in dans formConfirm()
				}

				// Ask if proposal archive wanted
				if ($action == 'objecthistory_confirm_modify') {
					// New version if wanted
					$archive_object = GETPOST('archive_object', 'alpha');
					if ($archive_object == 'on') {
						$res = ObjectHistory::archiveObjectWithCheck($object);
						if ($res > 0) {
							setEventMessage($langs->trans('ObjectHistoryVersionSuccessfullArchived'));
						} elseif ($res === 0) {
							dol_syslog($this->db->lasterror(), LOG_ERR);
							setEventMessage($langs->trans('ObjectHistoryVersionFailedArchived'), 'errors');
						}
					}

					// CommandeFournisseur = reopen
					// On provoque le repassage-en brouillon avec l'action de base
					if ($object->element == 'order_supplier') $action = 'reopen';
					elseif ($object->element == 'commande') $action = 'confirm_modif'; // spé ici, les commandes clients affiche de base une popin de confirmation pour rouverture
					else $action = 'modif';

					return 0; // Do standard code
				}
			}

			// l'action "delete_archive" affiche une popin de confirmation, donc il faut garder l'arrière plan dans la version précédemment sélectionnée
			if ($action == 'confirm_view_archive' || $action == 'delete_archive') {
				$archivedObject = $this->getArchivedObjectInstance($object, GETPOSTINT('idVersion'));
				if (is_object($archivedObject)) {
					$object = $archivedObject;
					return 1;
				}
			} elseif ($action == 'create_archive') {
				// 1. On crée l'archive en base
				$res = ObjectHistory::archiveObject($object);

				if ($res > 0) {
					// 2. IMPORTANT : On recharge l'objet proprement depuis la base
					// pour être sûr que les compteurs de version dans le hook PDF soient justes
					// et que la référence ne soit pas déjà polluée par un calcul précédent.
					$object->fetch($object->id);

					$hidedetails = (GETPOST('hidedetails', 'int') ? GETPOST('hidedetails', 'int') : (!empty($conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_DETAILS) ? 1 : 0));
					$hidedesc = (GETPOST('hidedesc', 'int') ? GETPOST('hidedesc', 'int') : (!empty($conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_DESC) ? 1 : 0));
					$hideref = (GETPOST('hideref', 'int') ? GETPOST('hideref', 'int') : (!empty($conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_REF) ? 1 : 0));

					$result = $object->generateDocument($object->model_pdf, $langs, $hidedetails, $hidedesc, $hideref);

					if ($result <= 0) {
						dol_syslog("Erreur régénération PDF lors de l'archivage", LOG_ERR);
					}
					setEventMessage($langs->trans('ObjectHistoryVersionSuccessfullArchived'));
				} else {
					setEventMessage($this->db->lasterror(), 'errors');
				}
				header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
				exit;
			} elseif ($action == 'restore_archive') {
				$res = ObjectHistory::restoreObject($object, GETPOST('idVersion'));

				if ($res > 0) setEventMessage($langs->trans('ObjectHistoryVersionSuccessfullRestored'));
				else setEventMessage($this->db->lasterror(), 'errors');

				header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
				exit;
			} elseif ($action == 'confirm_delete_archive') {
				$version = new ObjectHistory($this->db);
				$version->fetch(GETPOST('idVersion'));

				if ($version->delete($user) > 0) setEventMessage($langs->trans('ObjectHistoryVersionSuccessfullDelete'));
				else setEventMessage($this->db->lasterror(), 'errors');

				header('Location: '.$_SERVER['PHP_SELF'].'?id='.GETPOST('id'));
				exit;
			} elseif ($action == 'generate_archives_pdf') {
				$TVersion = ObjectHistory::getAllVersionBySourceId($object->id, $object->element);
				$nbOk = 0;
				$nbKo = 0;

				if (!is_array($TVersion) || empty($TVersion)) {
					dol_syslog(__METHOD__.' no archive to generate PDF for objectId='.(int) $object->id.' element='.$object->element, LOG_INFO);
					setEventMessages($langs->trans('ObjectHistoryNoArchiveFound'), null, 'warnings');
				} else {
					foreach (array_keys($TVersion) as $versionId) {
						$res = $this->buildArchivedPdfVersion($object, (int) $versionId);
						if ($res > 0) $nbOk++;
						else $nbKo++;
					}

					if ($nbOk > 0) setEventMessages($langs->trans('ObjectHistoryArchivePdfGeneratedCount', $nbOk), null, 'mesgs');
					if ($nbKo > 0) {
						dol_syslog(__METHOD__.' archive PDF generation errors count='.$nbKo.' objectId='.(int) $object->id, LOG_ERR);
						setEventMessages($langs->trans('ObjectHistoryArchivePdfErrorCount', $nbKo), !empty($this->errors) ? $this->errors : null, 'errors');
					}
				}

				header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
				exit;
			}
		}

		return 0;
	}
	/**
	 * Add confirmation content
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process
	 * @param   string          $action         Current action
	 * @param   HookManager     $hookmanager    Hook manager
	 * @return  int                             0
	 */
	public function formConfirm($parameters, &$object, &$action, $hookmanager)
	{
		if ($action == 'objecthistory_modif' || $action == 'view_archive' || $action == 'delete_archive') {
			$form = new Form($this->db);
			$formConfirm = getFormConfirmObjectHistory($form, $object, $action);
			$this->results = array();
			$this->resprints = $formConfirm;

			return 1;
		}

		return 0;
	}
	/**
	 * Add more actions buttons
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process
	 * @param   string          $action         Current action
	 * @param   HookManager     $hookmanager    Hook manager
	 * @return  int                             0
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs;

		$TContext = explode(':', $parameters['context']);

		$interSect = array_intersect($TContext, ObjectHistory::getTHookAllowed());
		if (!empty($interSect) && !empty($object->id)) {
			$TVersion = ObjectHistory::getAllVersionBySourceId($object->id, $object->element);
			print getHtmlListObjectHistory($object, $TVersion, $action);

			if (!getDolGlobalString('OBJECTHISTORY_HIDE_VERSION_ON_TABS')) {
				$idVersion = GETPOST('idVersion', 'int');

				if (!empty($idVersion) && isset($TVersion[$idVersion])) {
					$num = array_search($idVersion, array_keys($TVersion)) + 1;
					print '<script type="text/javascript">
							$("#id-right div.tabsElem a:first").append(" / v.'.$num.'");
//							console.log($("#id-right div.tabsElem a:first"));
						</script>';
				} elseif (! empty($TVersion)) {
					$num = count($TVersion) + 1; // TODO voir pour afficher le bon numéro de version si on est en mode visu
					print '<script type="text/javascript">
							$("#id-right div.tabsElem a:first").append(" / v.'.$num.'");
						</script>';
				}
			}

			$idVersion = GETPOSTINT('idVersion');
			if (($action == 'confirm_view_archive' || $action == 'delete_archive') && $idVersion > 0) {
				print '<script type="text/javascript">
					$(function() {
						const $form = $("#builddoc_form");
						if (!$form.length) return;
						if (!$form.find("input[name=\'idVersion\']").length) {
							$form.append(\'<input type="hidden" name="idVersion" value="'.((int) $idVersion).'">\');
						}
					});
				</script>';
			}

			if (($action != 'confirm_view_archive' && $action != 'delete_archive') && !empty($TVersion)) {
				print '<div class="inline-block divButAction"><a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=generate_archives_pdf&token='.newToken().'">'.$langs->trans("GenerateArchivePDF").'</a></div>';
			}

			if ($action == 'confirm_view_archive' || $action == 'delete_archive') return 1;
		}

		return 0;
	}
	/**
	 * Execute action before PDF creation
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process
	 * @param   string          $action         Current action
	 * @param   HookManager     $hookmanager    Hook manager
	 * @return  int                             0
	 */
	public function beforePDFCreation($parameters, &$object, &$action, $hookmanager)
	{
		global $conf;

		if (getDolGlobalString('OBJECTHISTORY_SHOW_VERSION_PDF')) {
			$TContext = explode(':', $parameters['context']);

			$interSect = array_intersect($TContext, ObjectHistory::getTHookAllowed());
			if (!empty($interSect)) {
				$TVersion = ObjectHistory::getAllVersionBySourceId($object->id, $object->element);
				$num = count($TVersion);
				if ($num > 0) {
					$this->old_object_ref = $object->ref;
					$object->ref .='/'.($num+1);
				}
			}
		}

		return 0;
	}
	/**
	 * Execute action after PDF creation
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process
	 * @param   string          $action         Current action
	 * @param   HookManager     $hookmanager    Hook manager
	 * @return  int                             0
	 */
	public function afterPDFCreation($parameters, &$object, &$action, $hookmanager)
	{
		global $conf;

		if (getDolGlobalString('OBJECTHISTORY_SHOW_VERSION_PDF')&& !empty($this->old_object_ref)) {
			$object_src = $parameters['object'];
			if (!empty($object_src)) $object_src->ref = $this->old_object_ref;
			else $object->ref = $this->old_object_ref;
		}

		return 0;
	}
}
