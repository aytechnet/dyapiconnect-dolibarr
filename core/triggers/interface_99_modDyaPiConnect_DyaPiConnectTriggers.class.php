<?php
/* Copyright (C) 2023-2026 François Pons <fpons@aytechnet.fr> (Aytechnet)
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
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    dyapiconnect/core/triggers/interface_99_modDyaPiConnect_DyaPiConnectTriggers.class.php
 * \ingroup dyapiconnect
 * \brief   DyaPiConnect triggers: log Dolibarr CRUD events into the dyapiconnect_journal so
 *          they can be flushed to DyaPi via /v1/notify, and re-sync the Factur-X seller
 *          snapshot on bank-account changes.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

dol_include_once('/dyapiconnect/lib/dyapiconnect.lib.php');

/**
 *  Class of triggers for MyModule module
 */
class InterfaceDyaPiConnectTriggers extends DolibarrTriggers {
	// DyaPi type value which are power of two
	// bits 0-7 associated to a thirdparty (user, member, customer or supplier)
	const TYPE_USER = 1;
	const TYPE_MEMBER = 2;
	const TYPE_THIRDPARTY = 4;
	const TYPE_CONTACT = 8;

	// bits 8-15 associated to base object (product and category)
	const TYPE_PRODUCT = 256;
	const TYPE_CATEGORY = 512;

	// bits 16-31 associated to more complex object like order, shipment, propale, invoice...
	const TYPE_ORDER = 65536;
	const TYPE_SHIPMENT = 131072;
	const TYPE_PROPALE = 262144;
	const TYPE_INVOICE = 524288;
	const TYPE_CONTRACT = 1048576;
	const TYPE_INTERVENTION = 2097152;
	const TYPE_TICKET = 4194304;
	const TYPE_RETURN = 8388608;
	const TYPE_EVENT = 16777216;
	const TYPE_RESSOURCE = 33554432;
	const TYPE_DOCUMENT = 67108864;
	const TYPE_WAREHOUSE = 134217728;

	// bits 32-47 associated to more complex object on supplier part
	const TYPE_SUPPLIER_ORDER = 4294967296;
	const TYPE_SUPPLIER_SHIPMENT = 8589934592;
	const TYPE_SUPPLIER_PROPALE = 17179869184;
	const TYPE_SUPPLIER_INVOICE = 34359738368;

	// bits 48-62 are free, bit 63 should not be used (sign bit)

	private $journal_updated = false;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db) {
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i', '', get_class($this));
        $this->family = 'DyaPi';
        $this->description = 'Triggers of this module log CRUD events in journal to be sent to dyapi.';
		// 'development', 'experimental', 'dolibarr' or version
		$this->version = 'dolibarr';
		$this->picto = 'dyapiconnect@dyapiconnect';

		// FIXME: shutdown function are called before any object destruction so it should work but there are problems in dolibarr fetch mysqli functions that way, destructor is working correctly
		// DONTDO: register_shutdown_function(array($this, 'notifyJournal'));
	}

	public function __destruct() {
		// try notify on object destruction
		$this->notifyJournal();
	}

	/**
	 * Trigger name
	 *
	 * @return string Name of trigger file
	 */
	public function getName() {
		return $this->name;
	}

	/**
	 * Trigger description
	 *
	 * @return string Description of trigger file
	 */
	public function getDesc() {
		return $this->description;
	}


	/**
	 * Send journal to DyaPi
	 *
	 * @return void
	 */
	public function notifyJournal() {
		global $conf;

		if (empty($conf->dyapiconnect) || empty($conf->dyapiconnect->enabled)) {
			return; // If module is not enabled, we do nothing
		}

		if (empty($conf->dyapiconnect->options)) {
			dol_include_once('/dyapiconnect/lib/dyapiconnect.lib.php');

			dyapiconnectGetConst();
		}

		if ($this->journal_updated && !empty($conf->dyapiconnect) && !empty($conf->dyapiconnect->options) && !empty($conf->dyapiconnect->options->auth)) {
			$sql = "SELECT object_id, object_type FROM ".MAIN_DB_PREFIX."dyapiconnect_journal WHERE entity = ".$conf->entity;
			$journal_data = [];

			$this->db->begin();
			$result = $this->db->query($sql);
			if ($result) {
				while ($obj = $this->db->fetch_object($result))
					$journal_data[] = $obj;

				$this->db->commit();
			} else
				$this->db->rollback();

			if (count($journal_data) > 0) {
				$result = dyapiconnectCall('/v1/notify', [ 'journal' => $journal_data ]);

				// a second sql transaction clears the journal and if it fails it is not problematic as it causes a second notify to be made later
				if (!empty($result) && is_array($result) && count($result) > 0) {
					$this->db->begin();

					foreach ( $result as $notified ) {
						$this->db->query("UPDATE ".MAIN_DB_PREFIX."dyapiconnect_journal
							SET object_type = object_type & ".(~$notified->object_type)."
							WHERE entity = ".$conf->entity." AND object_id = ".$notified->object_id);
					}

					$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."dyapiconnect_journal WHERE object_type = 0");

					$this->db->commit();
				}
			}
		}

		$this->journal_updated = false;
	}

	/**
	 * Add event to journal
	 *
	 * @return void
	 */
	private function addToJournal($object_id, $object_type) {
		global $conf, $user;

		if (!empty($conf) && !empty($conf->entity)) {
			$error = 0;
			$this->db->begin();

			// entity is never 0 on this sql query and should never be 0, if an object is modified for all entities, multiple journal entries should be added for each entities
			// FIXME: never tested Dolibarr in multiple entities mode : does it report once with entity = 0 or multiple times with entity set to each possible values ?
			$sql = "INSERT IGNORE INTO ".MAIN_DB_PREFIX."dyapiconnect_journal (entity, object_id, object_type) VALUES (".$conf->entity.", ".$object_id.", ".$object_type.")";
			$sql .= " ON DUPLICATE KEY UPDATE object_type = object_type | ".$object_type;

			if (! $this->db->query($sql) ) {
				dol_syslog(get_class($this)."::create::insert error", LOG_ERR);
				$error++;
			} else {
				dyapiconnectGetConst();

				$this->journal_updated = true;
			}

			if (! $error) {
				dol_syslog(get_class($this)."::create by ".(!empty($user) ? $user->id : 0), LOG_DEBUG);
				$this->db->commit();
				return 1;
			} else {
				$this->error=$this->db->lasterror();
				dol_syslog(get_class($this)."::create ".$this->error, LOG_ERR);
				$this->db->rollback();
				return -1;
			}
		}

		return 0;
    }


	/**
	 * Function called when a Dolibarrr business event is done.
	 * All functions "runTrigger" are triggered if file
	 * is inside directory core/triggers
	 *
	 * @param string 		$action 	Event action code
	 * @param CommonObject 	$object 	Object
	 * @param User 			$user 		Object user
	 * @param Translate 	$langs 		Object langs
	 * @param Conf 			$conf 		Object conf
	 * @return int              		<0 if KO, 0 if no triggered ran, >0 if OK
	 */
	/**
	 * Once an invoice has been transmitted to the PDP (Factur-X e-invoicing), it is legally immutable.
	 * DyaPi's signalLock writeback sets the 'dyapiconnect_transmitted' extrafield; this reads it,
	 * preferring the in-memory value (which survives the row deletion during BILL_DELETE) and falling
	 * back to the database otherwise. Fail-open: a missing extrafield never blocks a normal operation.
	 *
	 * @param	CommonObject	$object		the invoice (element 'facture')
	 * @return	bool						true if the invoice is locked (transmitted to the PDP)
	 */
	private function dyapiconnectInvoiceTransmitted($object)
	{
		if (empty($object) || empty($object->element) || $object->element != 'facture' || empty($object->id)) {
			return false;
		}
		if (isset($object->array_options['options_dyapiconnect_transmitted'])) {
			return !empty($object->array_options['options_dyapiconnect_transmitted']);
		}
		$sql = "SELECT dyapiconnect_transmitted FROM ".MAIN_DB_PREFIX."facture_extrafields WHERE fk_object = ".(int) $object->id;
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return !empty($obj->dyapiconnect_transmitted);
		}
		return false;
	}

	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf) {
		if (empty($user) || $user->login == 'dyapi' || empty($conf->dyapiconnect) || empty($conf->dyapiconnect->enabled)) {
			return 0; // If module is not enabled, we do nothing
		}

		// e-invoicing lock: an invoice transmitted to the PDP is legally immutable. Reject its deletion,
		// its un-validation (the gateway to editing) and any modification. Returning < 0 aborts the action
		// (Dolibarr rolls the transaction back). The 'dyapi' user is excluded above, so DyaPi's own
		// signalLock writeback of the flag is never blocked.
		if (in_array($action, array('BILL_DELETE', 'BILL_UNVALIDATE', 'BILL_MODIFY'), true)
			&& $this->dyapiconnectInvoiceTransmitted($object)) {
			$langs->load("dyapiconnect@dyapiconnect");
			$this->error = $langs->trans('DyaPiConnectErrorInvoiceTransmitted');
			$this->errors[] = $this->error;
			return -1;
		}

		// Data and type of action are stored into $object and $action
		$object_type = 0;
		$object_id = 0;

		switch ($action) {
			// Bank accounts → re-sync the Factur-X seller snapshot (IBAN BT-84 + accounts) with DyaPi.
			// Company identity (mysociété: name/SIREN/VAT/address) has no clean trigger; it is picked
			// up at the next register (periodic / manual re-sync). See dyapi/facturx-erp-sourced-seller.md.
			case 'BANKACCOUNT_CREATE':
			case 'BANKACCOUNT_MODIFY':
			case 'BANKACCOUNT_DELETE':
				dol_include_once('/dyapiconnect/lib/dyapiconnect.lib.php');
				dyapiconnectReRegisterIfChanged();
				return 0;

			// Users
			case 'USER_CREATE':
			case 'USER_MODIFY':
			case 'USER_NEW_PASSWORD':
			case 'USER_ENABLEDISABLE':
			case 'USER_DELETE':
				$object_type = self::TYPE_USER;
				$object_id = $object->id;
				break;

			// Actions (agenda events)
			case 'ACTION_MODIFY':
			case 'ACTION_CREATE':
			case 'ACTION_DELETE':
				$object_type = self::TYPE_EVENT;
				$object_id = $object->id;
				break;

			// Resources
			case 'RESOURCE_CREATE':
			case 'RESOURCE_MODIFY':
			case 'RESOURCE_DELETE':
				$object_type = self::TYPE_RESSOURCE;
				$object_id = $object->id;
				break;

			// Groups
			//case 'USERGROUP_CREATE':
			//case 'USERGROUP_MODIFY':
			//case 'USERGROUP_DELETE':

			// Companies
			case 'COMPANY_CREATE':
			case 'COMPANY_MODIFY':
			case 'COMPANY_DELETE':
				$object_type = self::TYPE_THIRDPARTY;
				$object_id = $object->id;
				break;

			// Contacts
			case 'CONTACT_CREATE':
			case 'CONTACT_MODIFY':
			case 'CONTACT_DELETE':
			case 'CONTACT_ENABLEDISABLE':
				$object_type = self::TYPE_CONTACT;
				$object_id = $object->id;
				break;

			// Products
			case 'PRODUCT_CREATE':
			case 'PRODUCT_MODIFY':
			case 'PRODUCT_DELETE':
			case 'PRODUCT_PRICE_MODIFY':
			case 'PRODUCT_SET_MULTILANGS':
			case 'PRODUCT_DEL_MULTILANGS':
				$object_type = self::TYPE_PRODUCT;
				$object_id = $object->id;
				break;

			//Stock mouvement — the trigger object is the MOVEMENT (not the product): mapping it
			// to TYPE_PRODUCT needs $object->product_id, to wire only when a stock consumer exists
			//case 'STOCK_MOVEMENT':

			// Warehouses
			case 'WAREHOUSE_CREATE':
			case 'WAREHOUSE_MODIFY':
			case 'WAREHOUSE_DELETE':
				$object_type = self::TYPE_WAREHOUSE;
				$object_id = $object->id;
				break;

			//MYECMDIR
			//case 'MYECMDIR_CREATE':
			//case 'MYECMDIR_MODIFY':
			//case 'MYECMDIR_DELETE':

			// Customer orders
			case 'ORDER_CREATE':
			case 'ORDER_MODIFY':
			case 'ORDER_VALIDATE':
			case 'ORDER_DELETE':
			case 'ORDER_CANCEL':
			case 'ORDER_SENTBYMAIL':
			case 'ORDER_CLASSIFY_BILLED':
			case 'ORDER_SETDRAFT':
			//case 'LINEORDER_INSERT':
			//case 'LINEORDER_UPDATE':
			//case 'LINEORDER_DELETE':
				$object_type = self::TYPE_ORDER;
				$object_id = $object->id;
				break;

			// Supplier orders
			case 'ORDER_SUPPLIER_CREATE':
			case 'ORDER_SUPPLIER_MODIFY':
			case 'ORDER_SUPPLIER_VALIDATE':
			case 'ORDER_SUPPLIER_DELETE':
			case 'ORDER_SUPPLIER_APPROVE':
			case 'ORDER_SUPPLIER_REFUSE':
			case 'ORDER_SUPPLIER_CANCEL':
			case 'ORDER_SUPPLIER_SENTBYMAIL':
			case 'ORDER_SUPPLIER_DISPATCH':
				$object_type = self::TYPE_SUPPLIER_ORDER;
				$object_id = $object->id;
				break;
			//case 'LINEORDER_SUPPLIER_DISPATCH':
			//case 'LINEORDER_SUPPLIER_CREATE':
			//case 'LINEORDER_SUPPLIER_UPDATE':
			//case 'LINEORDER_SUPPLIER_DELETE':

			// Proposals
			case 'PROPAL_CREATE':
			case 'PROPAL_MODIFY':
			case 'PROPAL_VALIDATE':
			case 'PROPAL_SENTBYMAIL':
			case 'PROPAL_CLOSE_SIGNED':
			case 'PROPAL_CLOSE_REFUSED':
			case 'PROPAL_DELETE':
				$object_type = self::TYPE_PROPALE;
				$object_id = $object->id;
				break;
			//case 'LINEPROPAL_INSERT':
			//case 'LINEPROPAL_UPDATE':
			//case 'LINEPROPAL_DELETE':

			// SupplierProposal
			case 'SUPPLIER_PROPOSAL_CREATE':
			case 'SUPPLIER_PROPOSAL_MODIFY':
			case 'SUPPLIER_PROPOSAL_VALIDATE':
			case 'SUPPLIER_PROPOSAL_SENTBYMAIL':
			case 'SUPPLIER_PROPOSAL_CLOSE_SIGNED':
			case 'SUPPLIER_PROPOSAL_CLOSE_REFUSED':
			case 'SUPPLIER_PROPOSAL_DELETE':
				$object_type = self::TYPE_SUPPLIER_PROPALE;
				$object_id = $object->id;
				break;
			//case 'LINESUPPLIER_PROPOSAL_INSERT':
			//case 'LINESUPPLIER_PROPOSAL_UPDATE':
			//case 'LINESUPPLIER_PROPOSAL_DELETE':

			// Contracts
			case 'CONTRACT_CREATE':
			case 'CONTRACT_MODIFY':
			case 'CONTRACT_ACTIVATE':
			case 'CONTRACT_CANCEL':
			case 'CONTRACT_CLOSE':
			case 'CONTRACT_DELETE':
				$object_type = self::TYPE_CONTRACT;
				$object_id = $object->id;
				break;
			//case 'LINECONTRACT_INSERT':
			//case 'LINECONTRACT_UPDATE':
			//case 'LINECONTRACT_DELETE':

			// Tickets — signal only (DyaPi acknowledges without effect today; a future
			// consumer is a server-side evolution, no module release)
			case 'TICKET_CREATE':
			case 'TICKET_MODIFY':
			case 'TICKET_ASSIGNED':
			case 'TICKET_CLOSE':
			case 'TICKET_DELETE':
				$object_type = self::TYPE_TICKET;
				$object_id = $object->id;
				break;

			// Bills
			case 'BILL_CREATE':
			case 'BILL_MODIFY':
			case 'BILL_VALIDATE':
			case 'BILL_UNVALIDATE':
			case 'BILL_SENTBYMAIL':
			case 'BILL_CANCEL':
			case 'BILL_DELETE':
			case 'BILL_PAYED':
			//case 'LINEBILL_INSERT':
			//case 'LINEBILL_UPDATE':
			//case 'LINEBILL_DELETE':
				$object_type = self::TYPE_INVOICE;
				$object_id = $object->id;
				break;

			//Supplier Bill — the notification model: the signal only says "this supplier
			// invoice changed", DyaPi loads the object and reacts to its ACTUAL state (validated
			// = the buyer's acceptance -> lifecycle status pushed to the PDP; deleted -> the
			// imported draft becomes transferable again; paid -> reserved for future lifecycle
			// statuses). One module release covers them all.
			case 'BILL_SUPPLIER_VALIDATE':
			case 'BILL_SUPPLIER_UNVALIDATE':
			case 'BILL_SUPPLIER_DELETE':
			case 'BILL_SUPPLIER_PAYED':
			case 'BILL_SUPPLIER_UNPAYED':
				$object_type = self::TYPE_SUPPLIER_INVOICE;
				$object_id = $object->id;
				break;
			//case 'BILL_SUPPLIER_CREATE':
			//case 'BILL_SUPPLIER_UPDATE':
			//case 'LINEBILL_SUPPLIER_CREATE':
			//case 'LINEBILL_SUPPLIER_UPDATE':
			//case 'LINEBILL_SUPPLIER_DELETE':

			// Payments
			//case 'PAYMENT_CUSTOMER_CREATE':
			//case 'PAYMENT_SUPPLIER_CREATE':
			//case 'PAYMENT_ADD_TO_BANK':
			//case 'PAYMENT_DELETE':

			// Online
			//case 'PAYMENT_PAYBOX_OK':
			//case 'PAYMENT_PAYPAL_OK':
			//case 'PAYMENT_STRIPE_OK':

			// Donation
			//case 'DON_CREATE':
			//case 'DON_UPDATE':
			//case 'DON_DELETE':

			// Interventions
			case 'FICHINTER_CREATE':
			case 'FICHINTER_MODIFY':
			case 'FICHINTER_VALIDATE':
			case 'FICHINTER_DELETE':
				$object_type = self::TYPE_INTERVENTION;
				$object_id = $object->id;
				break;
			//case 'LINEFICHINTER_CREATE':
			//case 'LINEFICHINTER_UPDATE':
			//case 'LINEFICHINTER_DELETE':

			// Members — subscription events stay off: their trigger object is the SUBSCRIPTION
			// (its own id), not the member; wire via fk_adherent when a consumer exists
			case 'MEMBER_CREATE':
			case 'MEMBER_VALIDATE':
			case 'MEMBER_MODIFY':
			case 'MEMBER_RESILIATE':
			case 'MEMBER_DELETE':
				$object_type = self::TYPE_MEMBER;
				$object_id = $object->id;
				break;
			//case 'MEMBER_SUBSCRIPTION':
			//case 'MEMBER_NEW_PASSWORD':

			// Categories
			case 'CATEGORY_CREATE':
			case 'CATEGORY_MODIFY':
			case 'CATEGORY_DELETE':
			case 'CATEGORY_SET_MULTILANGS':
				$object_type = self::TYPE_CATEGORY;
				$object_id = $object->id;
				break;

			// Projects
			//case 'PROJECT_CREATE':
			//case 'PROJECT_MODIFY':
			//case 'PROJECT_DELETE':

			// Project tasks
			//case 'TASK_CREATE':
			//case 'TASK_MODIFY':
			//case 'TASK_DELETE':

			// Task time spent
			//case 'TASK_TIMESPENT_CREATE':
			//case 'TASK_TIMESPENT_MODIFY':
			//case 'TASK_TIMESPENT_DELETE':
			//case 'PROJECT_ADD_CONTACT':
			//case 'PROJECT_DELETE_CONTACT':
			//case 'PROJECT_DELETE_RESOURCE':

			// Shipping
			case 'SHIPPING_CREATE':
			case 'SHIPPING_MODIFY':
			case 'SHIPPING_VALIDATE':
			case 'SHIPPING_SENTBYMAIL':
			case 'SHIPPING_BILLED':
			case 'SHIPPING_CLOSED':
			case 'SHIPPING_REOPEN':
			case 'SHIPPING_DELETE':
				$object_type = self::TYPE_SHIPMENT;
				$object_id = $object->id;
				break;

			// Receptions (supplier shipments, Reception module)
			case 'RECEPTION_CREATE':
			case 'RECEPTION_MODIFY':
			case 'RECEPTION_VALIDATE':
			case 'RECEPTION_DELETE':
				$object_type = self::TYPE_SUPPLIER_SHIPMENT;
				$object_id = $object->id;
				break;

			// and more...
		}

		$result = 0;
		if (!empty($object_id) && !empty($object_type))
			$result = $this->addToJournal($object_id, $object_type);

		// special integration for invoice once validated
		if ($action == 'BILL_VALIDATE' && GETPOSTISSET('choruspro_enable')) {
			if (empty($conf->dyapiconnect->options)) {
				dol_include_once('/dyapiconnect/lib/dyapiconnect.lib.php');

				dyapiconnectGetConst();
			}

			if (!empty($conf->dyapiconnect->options->choruspro) && !empty($conf->dyapiconnect->options->choruspro->invoice_meta_enable_field)) {
				$options_choruspro = GETPOST('choruspro_enable', 'aZ09') == 'on';

				$object->array_options['options_'.$conf->dyapiconnect->options->choruspro->invoice_meta_enable_field] = $options_choruspro;
				$object->insertExtraFields('', $user);
				// $object->update($user);
			}
		}

		return $result;
	}
}
