<?php
/* Copyright (C) 2025 ATM Consulting
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

if (!class_exists('SeedObject')) {
	/**
	 * Needed if $form->showLinkedObjectBlock() is call
	 */
	if (!defined('INC_FROM_DOLIBARR')) {
		define('INC_FROM_DOLIBARR', true);
	}
	require_once dirname(__FILE__).'/../config.php';
}
/**
 * Class ObjectHistory
 */
class ObjectHistory extends SeedObject
{
	public $table_element = 'objecthistory';

	public $element = 'objecthistory';

	/** @var int $fk_source */
	public $fk_source;
	/** @var string $element_source */
	public $element_source;
	/** @var int $date_version */
	public $date_version;
	/** @var double $total */
	public $total;
	/** @var int $entity */
	public $entity;
	/** @var Propal|Commande|SupplierProposal|CommandeFournisseur|string $serialized_object_source */
	public $serialized_object_source;

	private static $THookAllowed=array();
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		$this->fields=array(
				'fk_source'=>array('type'=>'integer','index'=>true)
				,'element_source'=>array('type'=>'string')
				,'date_version'=>array('type'=>'date') // date, integer, string, float, array, text
				,'total'=>array('type'=>'double')
				,'entity'=>array('type'=>'integer','index'=>true)
				,'serialized_object_source'=>array('type'=>'text')
		);

		$this->init();

		$this->entity = $conf->entity;
	}
	/**
	 * Get allowed hooks
	 *
	 * @return array Array of allowed hooks
	 */
	public static function getTHookAllowed()
	{
		if (empty(self::$THookAllowed)) {
			global $conf;

			self::$THookAllowed = explode(',', getDolGlobalString('OBJECTHISTORY_HOOKS_ALLOWED'));
		}

		return self::$THookAllowed;
	}
	/**
	 * Unserialize object
	 *
	 * @return void
	 */
	public function unserializeObject()
	{
		$code = @gzinflate(base64_decode($this->serialized_object_source));
		if ($code === false) $code = $this->serialized_object_source;

		$code = unserialize($code, ["allowed_classes" =>
			[
				"Propal",
				"PropaleLigne",
				"Commande",
				"OrderLine",
				"CommandeFournisseur",
				"CommandeFournisseurLigne",
				"SupplierProposal",
				"SupplierProposalLine"
			]
		]);
		if ($code === false) $code = unserialize(utf8_decode($code));

		$this->serialized_object_source = $code;
	}
	/**
	 * Serialize object
	 *
	 * @param  object $object Object to serialize
	 * @return void
	 */
	public function serializeObject(&$object)
	{
		global $conf;

		$code = serialize($object);
		if (getDolGlobalString('OBJECTHISTORY_USE_COMPRESS_ARCHIVE')) $code = base64_encode(gzdeflate($code));

		$this->serialized_object_source = $code;
	}

	/**
	 * Get all versions by source ID
	 *
	 * @param  int    $fk_source      Id of source object
	 * @param  string $element_source Type of element (propal, commande...)
	 * @return ObjectHistory[]        Array of objects
	 */
	public static function getAllVersionBySourceId($fk_source, $element_source)
	{
		global $db;

		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'objecthistory WHERE fk_source = '.$fk_source.' AND element_source = \''.$db->escape($element_source).'\'';
		$resql = $db->query($sql);
		if ($resql) {
			$TRes = array();
			while ($obj = $db->fetch_object($resql)) {
				$o = new ObjectHistory($db);
				$o->fetch($obj->rowid);
				$TRes[$o->id] = $o;
			}

			return $TRes;
		} else {
			dol_print_error($db);
			exit;
		}
	}
	/**
	 * Archive object
	 *
	 * @param  object $object Object to archive
	 * @return int            Id of new version
	 */
	public static function archiveObject(&$object)
	{
		global $db,$conf,$user;

		if (getDolGlobalString('OBJECTHISTORY_ARCHIVE_PDF_TOO')) self::archivePDF($object);

		$newVersion = new ObjectHistory($db);
		$newVersion->serializeObject($object);

		$newVersion->fk_source = $object->id;
		$newVersion->element_source = $object->element;
		$newVersion->date_version = dol_now();
		$newVersion->total = $object->total_ht;
		$newVersion->entity = $object->entity;

		return $newVersion->create($user);
	}
	/**
	 * Archive PDF
	 *
	 * @param  object $object Object
	 * @return int|void       0 if error
	 */
	public static function archivePDF(&$object)
	{
		global $db,$conf;

        $nb = self::archiveCount($object->id, $object->element) + 1;
		$ok = 1;
		$filename = dol_sanitizeFileName($object->ref);

		if ($object->element == 'propal') $filedir = $conf->propal->multidir_output[$object->entity] . "/" . $filename;
		elseif ($object->element == 'commande') $filedir = $conf->commande->dir_output . '/' . $filename;
		elseif ($object->element == 'supplier_proposal') $filedir = $conf->supplier_proposal->dir_output . '/' . $filename;
		elseif ($object->element == 'order_supplier') $filedir = $conf->fournisseur->commande->dir_output . '/' . $filename;
		else return 0;

		if (!is_file($filedir.'/'.$filename.'.pdf')) $ok = self::generatePDF($object);

		if ($ok > 0) {
			exec('cp "'.$filedir.'/'.$filename.'.pdf" "'.$filedir.'/'.$filename.'-'.$nb.'.pdf"');
		}
	}
	/**
	 * Count the number of archives existing for a specific object.
	 *
	 * @param  int    $id      Id of the source object (fk_source)
	 * @param  string $element Type of the element (element_source, e.g., 'propal', 'commande')
	 * @return int             Number of archived versions found
	 */
    public static function archiveCount(int $id, string $element) :int
    {
        global $db;
        $sql = " SELECT count(rowid) as nb";
        $sql.= " FROM ".$db->prefix()."objecthistory";
        $sql.= " WHERE fk_source = ".(int) $id;
        $sql.= " AND element_source = '".$db->escape($element)."'";
        $resql = $db->query($sql);
        if ($resql && ($row = $db->fetch_object($resql))) {
           return (int) $row->nb;
        }
       return 0;
    }

	/**
	 * Generate PDF
	 *
	 * @param Propal|Commande|SupplierProposal|CommandeFournisseur $object  Object source
	 * @return bool                                                         True if OK
	 */
	public static function generatePDF(&$object)
	{
		global $conf,$langs;

		if (method_exists($object, 'generateDocument')) {
			global $hidedetails,$hidedesc,$hideref,$moreparams;

			if (empty($hidedetails)) $hidedetails=0;
			if (empty($hidedesc)) $hidedesc=0;
			if (empty($hideref)) $hideref=0;
			if (empty($moreparams)) $moreparams=null;

			// $object->modelpdf ?
			$res = true;
			if ($object->element == 'propal') $res = $object->generateDocument(getDolGlobalString('PROPALE_ADDON_PDF'), $langs, $hidedetails, $hidedesc, $hideref, $moreparams);
			elseif ($object->element == 'commande') $res = $object->generateDocument(getDolGlobalString('COMMANDE_ADDON_PDF'), $langs, $hidedetails, $hidedesc, $hideref, $moreparams);
			elseif ($object->element == 'supplier_proposal') $res = $object->generateDocument(getDolGlobalString('SUPPLIER_PROPOSAL_ADDON_PDF'), $langs, $hidedetails, $hidedesc, $hideref, $moreparams);
			elseif ($object->element == 'supplier_order') $res = $object->generateDocument(getDolGlobalString('COMMANDE_SUPPLIER_ADDON_PDF'), $langs, $hidedetails, $hidedesc, $hideref, $moreparams);

			return $res;
		}

		return false;
	}

	/**
	 * Restore Object
	 *
	 * @param Propal|Commande|SupplierProposal|CommandeFournisseur $object      Object to restore
	 * @param int                                                  $fk_version  Id of version to restore
	 * @return int                                                              1 if OK
	 */
	public static function restoreObject(&$object, $fk_version)
	{
		global $db,$conf,$user;

		$version = new ObjectHistory($db);
		$version->fetch($fk_version);
		$version->unserializeObject();

		$object->statut = 0;
		$object->setDraft($user);
		foreach ($object->lines as $line) {
			if ($object->element == 'commande') $object->deleteline($user, $line->id);
			else $object->deleteline($line->id);
		}

		if ($object->element == 'supplier_proposal') {
			$old_val = getDolGlobalString('SUPPLIER_PROPOSAL_WITH_PREDEFINED_PRICES_ONLY');
			$conf->global->SUPPLIER_PROPOSAL_WITH_PREDEFINED_PRICES_ONLY = 0;
		} elseif ($object->element == 'order_supplier') {
			$old_val = getDolGlobalString('SUPPLIER_ORDER_WITH_PREDEFINED_PRICES_ONLY');
			$conf->global->SUPPLIER_ORDER_WITH_PREDEFINED_PRICES_ONLY = 0;
		}

		foreach ($version->serialized_object_source->lines as $line) {
			if ($object->element == 'propal') $object->addline($line->desc, $line->subprice, $line->qty, $line->tva_tx, $line->localtax1_tx, $line->localtax2_tx, $line->fk_product, $line->remise_percent, 'HT', '', $line->info_bits, $line->product_type, $line->rang, $line->special_code, $line->fk_parent_line, $line->fk_fournprice, $line->pa_ht, $line->label, $line->date_start, $line->date_end, $line->array_options, $line->fk_unit, $line->fk_remise_except);
			elseif ($object->element == 'commande') $object->addline($line->desc, $line->subprice, $line->qty, $line->tva_tx, $line->localtax1_tx, $line->localtax2_tx, $line->fk_product, $line->remise_percent, $line->info_bits, $line->fk_remise_except, 'HT', '', $line->date_start, $line->date_end, $line->product_type, $line->rang, $line->special_code, $line->fk_parent_line, $line->fk_fournprice, $line->pa_ht, $line->label, $line->array_options, $line->fk_unit);
			// elseif ($object->element == 'facture') $object->addline($line->desc, $line->subprice, $line->qty, $line->tva_tx, $line->localtax1_tx, $line->localtax2_tx, $line->fk_product, $line->remise_percent, $line->date_start, $line->date_end, $line->fk_code_ventilation, $line->info_bits, $line->fk_remise_except, 'HT', '', $line->product_type, $line->rang, $line->special_code, $line->fk_parent_line, $line->fk_fournprice, $line->pa_ht, $line->label, $line->array_options, $line->situation_percent, $line->fk_prev_id, $line->fk_unit);
			elseif ($object->element == 'supplier_proposal') $object->addline($line->desc, $line->subprice, $line->qty, $line->tva_tx, $line->localtax1_tx, $line->localtax2_tx, $line->fk_product, $line->remise_percent, 'HT', '', $line->info_bits, $line->product_type, $line->rang, $line->special_code, $line->fk_parent_line, $line->fk_fournprice, $line->pa_ht, $line->label, $line->array_options, $line->ref_fourn, $line->fk_unit);
			elseif ($object->element == 'order_supplier') $object->addline($line->desc, $line->subprice, $line->qty, $line->tva_tx, $line->localtax1_tx, $line->localtax2_tx, $line->fk_product, 0, $line->ref_supplier, $line->remise_percent, 'HT', '', $line->product_type, $line->info_bits, false, $line->date_start, $line->date_end, $line->array_options, $line->fk_unit);
		}

		if ($object->element == 'supplier_proposal') $conf->global->SUPPLIER_PROPOSAL_WITH_PREDEFINED_PRICES_ONLY = $old_val;
		elseif ($object->element == 'order_supplier') $conf->global->SUPPLIER_ORDER_WITH_PREDEFINED_PRICES_ONLY = $old_val;


		if ($object->element == 'order_supplier') $object->setStatus($user, 0);
		else $object->setDraft($user);


		if (method_exists($object, 'set_availability')) $object->set_availability($user, $version->serialized_object_source->availability_id);
		if (method_exists($object, 'set_date')) $object->set_date($user, $version->serialized_object_source->date);
		if (method_exists($object, 'set_date_livraison')) $object->setDeliveryDate($user, $version->serialized_object_source->delivery_date);

		if (method_exists($object, 'set_echeance')) $object->set_echeance($user, $version->serialized_object_source->fin_validite);
		if (method_exists($object, 'set_ref_client')) $object->set_ref_client($user, $version->serialized_object_source->ref_client);

		if (method_exists($object, 'set_demand_reason')) $object->set_demand_reason($user, $version->serialized_object_source->demand_reason_id);
		if (method_exists($object, 'setPaymentMethods')) $object->setPaymentMethods($version->serialized_object_source->mode_reglement_id);
		if (method_exists($object, 'setPaymentTerms')) $object->setPaymentTerms($version->serialized_object_source->cond_reglement_id);
		if (method_exists($object, 'valid')) {
			if ($object->element == 'commande' || $object->element == 'order_supplier') $object->valid($user, 0, 1);
			else $object->valid($user, 1);
		}

		$object->fetch($object->id); //reload for generatePDF
		self::generatePDF($object);

		// TODO handle error
		return 1;
	}
}
require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
/**
 * Class PropalHistory
 */
class PropalHistory extends Propal
{
	/**
	 * Get lines array
	 *
	 * @param  string $sqlforgedfilters Filters
	 * @return null
	 */
	public function getLinesArray($sqlforgedfilters = '')
	{
		return null;
	}
}
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
/**
 * Class CommandeHistory
 */
class CommandeHistory extends Commande
{
	/**
	 * Get lines array
	 *
	 * @return null
	 */
	public function getLinesArray()
	{
		return null;
	}
}
require_once DOL_DOCUMENT_ROOT.'/supplier_proposal/class/supplier_proposal.class.php';
/**
 * Class SupplierProposalHistory
 */
class SupplierProposalHistory extends SupplierProposal
{
	/**
	 * Get lines array
	 *
	 * @return null
	 */
	public function getLinesArray()
	{
		return null;
	}
}
// TODO check le fonctionnement => getLinesArray() NOT FOUND
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
/**
 * Class CommandeFournisseurHistory
 */
class CommandeFournisseurHistory extends CommandeFournisseur
{
	/**
	 * Fetch object
	 *
	 * @param  int    $id   Id of object
	 * @param  string $ref  Ref of object
	 * @return int          <0 if KO, >0 if OK
	 */
	public function fetch($id, $ref = '')
	{
		// Si l'objet n'a jamais été fetch || Si getLinesArray existe, alors elle est potentiellement utilisé dans la card et en théorie rien d'autre à faire
		if (empty($this->id) || method_exists(get_parent_class($this), 'getLinesArray')) {
			return parent::fetch($id, $ref);
		} else {
			// Trick for this object
			$old_lines = $this->lines;
			$res = parent::fetch($id, $ref);
			$this->lines = $old_lines;

			return $res;
		}
	}
	/**
	 * Get lines array
	 *
	 * @return null
	 */
	public function getLinesArray()
	{
		return null;
	}
}
