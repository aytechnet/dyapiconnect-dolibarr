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
 * \file    dyapiconnect/class/actions_dyapiconnect.class.php
 * \ingroup dyapiconnect
 * \brief   DyaPiConnect hook overloads: ChorusPro confirmation on invoice validation,
 *          Factur-X enrichment on afterPDFCreation, and daily seller re-sync cron.
 */

/**
 * Class ActionsDyapiconnect
 */
class ActionsDyapiconnect
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var array Errors
	 */
	public $errors = array();


	/**
	 * @var array Hook results. Propagated to $hookmanager->resArray for later reuse
	 */
	public $results = array();

	/**
	 * @var string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var string Report buffer read by the Dolibarr cron module after a scheduled job run
	 */
	public $output;

	/**
	 * @var int		Priority of hook (50 is used if value is not defined)
	 */
	public $priority;


	/**
	 * Constructor
	 *
	 *  @param		DoliDB		$db      Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}


	/**
	 * overloading formConfirm for invoice if ChorusPro is active
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function formConfirm($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		if (empty($conf->dyapiconnect) || empty($conf->dyapiconnect->enabled)) {
			return 0; // If module is not enabled, we do nothing
		}

		$error = 0; // Error counter

		if ($action == 'valid' && in_array($parameters['currentcontext'], array('invoicecard'))) {
			// extract current ChorusPro configuration if needed
			if (empty($conf->dyapiconnect->options)) {
				dol_include_once('/dyapiconnect/lib/dyapiconnect.lib.php');

				dyapiconnectGetConst();
			}

			if (!empty($conf->dyapiconnect->options->choruspro) && !empty($conf->dyapiconnect->options->choruspro->invoice_meta_enable_field)) {
				// an invoice meta enable field is used so check if a thirdparty enable field must be checked, if this is the case we need to fetch thirdparty associated (unless a different billing thirdparty is used : TODO)
				if (!empty($conf->dyapiconnect->options->choruspro->thirdparty_meta_enable_field))
					$object->fetch_thirdparty();

				if (empty($conf->dyapiconnect->options->choruspro->thirdparty_meta_enable_field) || !empty($object->thirdparty) && !empty($object->thirdparty->array_options) && !empty($object->thirdparty->array_options['options_'.$conf->dyapiconnect->options->choruspro->thirdparty_meta_enable_field])) {
					// code taken from facture/card.php for valid action, in order to redo valid formconfirm with additional questions as well
					$objectref = substr($object->ref, 1, 4);
					if ($objectref == 'PROV') {
						// getNextNumRef() needs the invoice thirdparty (numbering masks may use it);
						// in facture/card.php this is the page-level $soc, unavailable in this hook context
						if (empty($object->thirdparty)) {
							$object->fetch_thirdparty();
						}
						$savdate = $object->date;
						if (!empty($conf->global->FAC_FORCE_DATE_VALIDATION)) {
							$object->date = dol_now();
							$object->date_lim_reglement = $object->calculate_date_lim_reglement();
						}
						$numref = $object->getNextNumRef($object->thirdparty);
						// $object->date=$savdate;
					} else {
						$numref = $object->ref;
					}

					$text = $langs->trans('ConfirmValidateBill', $numref);
					if (!empty($conf->notification->enabled)) {
						require_once DOL_DOCUMENT_ROOT.'/core/class/notify.class.php';
						$notify = new Notify($this->db);
						$text .= '<br>';
						$text .= $notify->confirmMessage('BILL_VALIDATE', $object->socid, $object);
					}
					$formquestion = array( array('type' => 'checkbox', 'name' => 'choruspro_enable', 'label' => $langs->trans('DyaPiConnectChorusProConfirmQuestion'), 'value' => 1) );

					if ($object->type != Facture::TYPE_DEPOSIT && !empty($conf->global->STOCK_CALCULATE_ON_BILL)) {
						$qualified_for_stock_change = 0;
						if (empty($conf->global->STOCK_SUPPORTS_SERVICES)) {
							$qualified_for_stock_change = $object->hasProductsOrServices(2);
						} else {
							$qualified_for_stock_change = $object->hasProductsOrServices(1);
						}

						if ($qualified_for_stock_change) {
							$langs->load("stocks");
							require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
							require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
							$formproduct = new FormProduct($this->db);
							$warehouse = new Entrepot($this->db);
							$warehouse_array = $warehouse->list_array();
							if (count($warehouse_array) == 1) {
								$label = $object->type == Facture::TYPE_CREDIT_NOTE ? $langs->trans("WarehouseForStockIncrease", current($warehouse_array)) : $langs->trans("WarehouseForStockDecrease", current($warehouse_array));
								$value = '<input type="hidden" id="idwarehouse" name="idwarehouse" value="'.key($warehouse_array).'">';
							} else {
								$label = $object->type == Facture::TYPE_CREDIT_NOTE ? $langs->trans("SelectWarehouseForStockIncrease") : $langs->trans("SelectWarehouseForStockDecrease");
								$value = $formproduct->selectWarehouses(GETPOST('idwarehouse') ?GETPOST('idwarehouse') : 'ifone', 'idwarehouse', '', 1);
							}
							$formquestion[] = array('type' => 'other', 'name' => 'idwarehouse', 'label' => $label, 'value' => $value);
						}
					}

					if ($object->type != Facture::TYPE_CREDIT_NOTE && $object->total_ttc < 0) { 		// Can happen only if $conf->global->FACTURE_ENABLE_NEGATIVE is on
						$text .= '<br>'.img_warning().' '.$langs->trans("ErrorInvoiceOfThisTypeMustBePositive");
					}

					// mandatoryPeriod
					$nbMandated = 0;
					foreach ($object->lines as $line) {
						$res = $line->fetch_product();
						if ($res  > 0  ) {
							if ($line->product->isService() && $line->product->isMandatoryPeriod() && (empty($line->date_start) || empty($line->date_end) )) {
								$nbMandated++;
								break;
							}
						}
					}
					if ($nbMandated > 0 ) $text .= '<div><span class="clearboth nowraponall warning">'.$langs->trans("mandatoryPeriodNeedTobeSetMsgValidate").'</span></div>';

					$form = new Form($this->db);
					$this->resprints = $form->formconfirm($_SERVER["PHP_SELF"].'?facid='.$object->id, $langs->trans('ValidateBill'), $text, 'confirm_valid', $formquestion, (($object->type != Facture::TYPE_CREDIT_NOTE && $object->total_ttc < 0) ? "no" : "yes"), 2, 250);

					return 1; // replace standard code
				}
			}
		}

		return 0;
	}


	/**
	 * afterPDFCreation hook: once Dolibarr has written a validated customer invoice PDF,
	 * ask DyaPi to enrich it into a conformant Factur-X PDF/A-3 and store it back in the
	 * invoice GED (DyaPi pulls the invoice + this PDF, generates, and re-uploads it).
	 *
	 * This is the correct integration point rather than the BILL_VALIDATE trigger:
	 * Facture::validate() regenerates the PDF *after* the trigger fires
	 * (unless MAIN_DISABLE_PDF_AUTOUPDATE), which would overwrite a document produced at
	 * trigger time. afterPDFCreation runs after the file is written, so it is never clobbered.
	 *
	 * @param   array           $parameters     Hook metadatas (file, object, outputlangs, currentcontext)
	 * @param   CommonObject    $object         The object whose PDF was generated
	 * @param   string          $action         Current action
	 * @param   HookManager     $hookmanager    Hook manager
	 * @return  int                             0 (does not replace standard processing)
	 */
	public function afterPDFCreation($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		if (empty($conf->dyapiconnect) || empty($conf->dyapiconnect->enabled)) {
			return 0; // module disabled
		}

		dol_include_once('/dyapiconnect/lib/dyapiconnect.lib.php');

		$langs->load("dyapiconnect@dyapiconnect");

		// re-entrancy guard: when DyaPi's own callback regenerates the PDF (authenticated
		// as the dyapi user), do not call back again — avoids an infinite loop
		if (!empty($user) && $user->login == dyapiconnectUserLogin()) {
			return 0;
		}

		// only validated or paid/closed customer invoices. In afterPDFCreation the hook's $object is the
		// PDF model (e.g. pdf_sponge); the business object (the invoice) is passed in $parameters['object'].
		// STATUS_CLOSED matters because recording a payment flips the invoice to closed and regenerates
		// the PDF (to stamp the payment) — that fresh plain PDF must be re-enriched, or the Factur-X is lost.
		$invoice = !empty($parameters['object']) ? $parameters['object'] : $object;
		if (empty($invoice) || $invoice->element != 'facture'
			|| ($invoice->statut != Facture::STATUS_VALIDATED && $invoice->statut != Facture::STATUS_CLOSED)) {
			return 0;
		}

		if (empty($conf->dyapiconnect->options)) {
			dyapiconnectGetConst();
		}

		// opt-in: DyaPi advertises 'facturx' in the register response only when this tenant
		// has a seller configuration; stay inert otherwise
		if (empty($conf->dyapiconnect->options) || empty($conf->dyapiconnect->options->auth) || empty($conf->dyapiconnect->options->facturx)) {
			return 0;
		}

		// Keep DyaPi's seller identity in sync BEFORE generating, so the Factur-X uses the current
		// company data (synchronous, conditional — only re-registers on a real change). This is the
		// primary path for company-identity changes (no clean trigger on mysociété). See §2.1.
		dyapiconnectReRegisterIfChanged();

		// DyaPi pulls the invoice and the just-written PDF, generates the Factur-X PDF/A-3
		// and uploads it back into the invoice GED. Longer timeout: PDF round-trip + PDF/A-3
		// conversion on the DyaPi side.
		//
		// Send the invoice and due dates as calendar-day strings (YYYY-MM-DD), resolved here in the
		// server timezone: DyaPi parses them to noon so the day is timezone-safe. Without this it
		// would receive the raw midnight Unix timestamps and shift a day in a positive-offset zone.
		$result = dyapiconnectCall('/v1/invoice/facturx', array(
			'invoice_id'   => (int) $invoice->id,
			'invoice_date' => dol_print_date($invoice->date, 'dayrfc'),
			'due_date'     => (!empty($invoice->date_lim_reglement) ? dol_print_date($invoice->date_lim_reglement, 'dayrfc') : ''),
		), 30);

		// Trace the transformation as an event (ActionComm) on the invoice — only here, i.e. only when a
		// DyaPi call was actually attempted (past the opt-in/validation guards). Best-effort: a failed
		// event never blocks the invoice.
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		$uuid  = (!empty($conf->dyapiconnect->options->uuid) ? $conf->dyapiconnect->options->uuid : '');
		$when  = dol_print_date(dol_now(), 'dayhoursec');

		$event = new ActionComm($this->db);
		$event->type_code   = 'AC_OTH_AUTO';
		$event->code        = 'AC_DYAPICONNECT_FACTURX';
		$event->datep       = dol_now();
		$event->percentage  = -1;
		$event->fk_element  = (int) $invoice->id;
		$event->elementtype = 'facture';
		$event->socid       = $invoice->socid;
		$event->userownerid = (!empty($user) ? $user->id : 0);

		if (!empty($result) && !empty($result->sha256)) {
			$already = !empty($result->already_facturx)
				? $langs->trans('DyaPiConnectFacturxAlreadyYes')
				: $langs->trans('DyaPiConnectFacturxAlreadyNo');
			$event->label = $langs->trans('DyaPiConnectFacturxEventOKLabel');
			$event->note_private = $langs->trans('DyaPiConnectFacturxEventOKHeader')
				."\n".$langs->trans('DyaPiConnectFacturxStatusOK')
				."\n".$langs->trans('DyaPiConnectFacturxConformance', $result->conformance_level)
				."\n".$langs->trans('DyaPiConnectFacturxSha256', $result->sha256)
				."\n".$langs->trans('DyaPiConnectFacturxAlready', $already)
				.($uuid ? "\n".$langs->trans('DyaPiConnectFacturxLicence', $uuid) : '')
				."\n".$langs->trans('DyaPiConnectFacturxAt', $when);
			// syslog stays untranslated on purpose: syslogs are read by ops, not end users
			dol_syslog("DyaPiConnect: Factur-X stored for invoice ".$invoice->ref." (conformance=".$result->conformance_level.", sha256=".$result->sha256.", already=".(!empty($result->already_facturx) ? '1' : '0').")", LOG_INFO);
		} else {
			$err = (!empty($result) && !empty($result->message))
				? $result->message
				: $langs->trans('DyaPiConnectFacturxNoResponse');
			$event->label = $langs->trans('DyaPiConnectFacturxEventKOLabel');
			$event->note_private = $langs->trans('DyaPiConnectFacturxEventKOHeader')
				."\n".$langs->trans('DyaPiConnectFacturxStatusKO')
				."\n".$langs->trans('DyaPiConnectFacturxError', $err)
				.($uuid ? "\n".$langs->trans('DyaPiConnectFacturxLicence', $uuid) : '')
				."\n".$langs->trans('DyaPiConnectFacturxAt', $when);
			dol_syslog("DyaPiConnect: Factur-X generation failed for invoice ".$invoice->ref." : ".$err, LOG_WARNING);
		}
		if (!empty($user) && $event->create($user) <= 0) {
			dol_syslog("DyaPiConnect: failed to create Factur-X event on invoice ".$invoice->ref." : ".$event->error, LOG_WARNING);
		}

		return 0; // never replace standard processing
	}

	/**
	 * Scheduled job (daily): keep DyaPi's Factur-X seller config in sync — re-register only when the
	 * company / bank data changed (fingerprint compare). Safety net for changes made without emitting
	 * an invoice, and for the PDP directory freshness. See dyapi/facturx-erp-sourced-seller.md §2.1.
	 *
	 * @param	string	$parameters		Cron parameters (unused)
	 * @return	int						0 on success
	 */
	public function dyapiconnectSync($parameters = '')
	{
		global $conf;

		$this->output = '';
		$this->error = '';

		if (empty($conf->dyapiconnect) || empty($conf->dyapiconnect->enabled)) {
			return 0;
		}

		dol_include_once('/dyapiconnect/lib/dyapiconnect.lib.php');
		$res = dyapiconnectReRegisterIfChanged();
		$this->output = $res ? 'DyaPiConnect: seller data changed, re-registered with DyaPi.' : 'DyaPiConnect: seller data unchanged.';

		return 0;
	}
}

