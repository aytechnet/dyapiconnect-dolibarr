<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2023 François Pons <fpons@aytechnet.fr>
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
 * \file    dyapiconnect/admin/setup.php
 * \ingroup dyapiconnect
 * \brief   DyaPiConnect setup page.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--; $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

global $db, $langs, $user;

// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';

dol_include_once('/dyapiconnect/lib/dyapiconnect.lib.php');

// Translations
$langs->loadLangs(array("admin", "dyapiconnect@dyapiconnect"));

// Initialize technical object to manage hooks of page. Note that conf->hooks_modules contains array of hook context
$hookmanager->initHooks(array('dyapiconnectsetup', 'globalsetup'));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$modulepart = GETPOST('modulepart', 'aZ09');	// Used by actions_setmoduleoptions.inc.php

$value = GETPOST('value', 'alpha');
$label = GETPOST('label', 'alpha');
$scandir = GETPOST('scan_dir', 'alpha');
$type = 'myobject';

$error = 0;

$dyapiuser_login = dyapiconnectUserLogin();
$dyapiuser = dyapiconnectUser($dyapiuser_login);

$dyapiconnect_modules = [
	'societe' => [ 'module' => 'modSociete', 'const' => 'MAIN_MODULE_SOCIETE', 'propale' => 1, 'invoice' => 1, 'ecommerce' => 1 ],
	'propale' => [ 'module' => 'modPropale', 'const' => 'MAIN_MODULE_PROPALE', 'propale' => 1 ],
	'commande' => [ 'module' => 'modCommande', 'const' => 'MAIN_MODULE_COMMANDE', 'ecommerce' => 1 ],
	'expedition' => [ 'module' => 'modExpedition', 'const' => 'MAIN_MODULE_EXPEDITION', 'ecommerce' => 1 ],
	'facture' => [ 'module' => 'modFacture', 'const' => 'MAIN_MODULE_FACTURE', 'invoice' => 1, 'ecommerce' => 1 ],
	'banque' => [ 'module' => 'modBanque', 'const' => 'MAIN_MODULE_BANQUE', 'invoice' => 1, 'ecommerce' => 1 ],
	'produit' => [ 'module' => 'modProduct', 'const' => 'MAIN_MODULE_PRODUCT', 'ecommerce' => 1 ],
	'stock' => [ 'module' => 'modStock', 'const' => 'MAIN_MODULE_STOCK', 'ecommerce' => 1 ],
];

$dyapiconnect_mode = [
	'propale' => 1,
	'invoice' => 1,
	'ecommerce' => 1,
];

// Retrieve activated modules status and mode
foreach ($dyapiconnect_modules as &$m) {
	$res = dolibarr_get_const($db, $m['const'], $conf->entity);

	foreach ($dyapiconnect_mode as $mode => &$checked) {
		if (!empty($m[$mode]) && empty($res))
			$checked = 0;
	}

	$m['activated'] = !empty($res);
}


/*
 * Actions
 */

include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';

if ($action == 'connect') {
	//$url = dyapiconnectUrl();
	// Retrieve mode
	foreach ($dyapiconnect_mode as $mode => &$checked) {
		$dyapiconnect_mode_checked = GETPOST('dyapiconnect_'.$mode, 'int');

		$checked = $dyapiconnect_mode_checked == 1;
	}

	$forceaddrights = empty($dyapiuser);
	if (empty($dyapiuser))
		$dyapiuser = dyapiconnectCreateUser();

	if (empty($dyapiuser->api_key)) {
		dyapiconnectGenerateApiKey($dyapiuser);

		$forceaddrights = true;
	}

	// backfill / repair the avatar: (re)apply when the photo is missing, or when it is set but the
	// thumbnail Dolibarr renders is absent (installs made before addThumbs() was generated).
	if (!empty($dyapiuser)) {
		$needphoto = empty($dyapiuser->photo);
		if (!$needphoto) {
			$smallthumb = $conf->user->dir_output.'/'.$dyapiuser->id.'/photos/thumbs/'
				.preg_replace('/(\.[^.]+)$/', '_small$1', $dyapiuser->photo);
			$needphoto = !file_exists($smallthumb);
		}
		if ($needphoto)
			dyapiconnectSetUserPhoto($dyapiuser);
	}

	foreach ($dyapiconnect_modules as $modrights => &$m) {
		if (!$m['activated']) {
			// Check if module need to be activated
			foreach ($dyapiconnect_mode as $mode => &$checked) {
				if ($checked && !empty($m[$mode])) {
					$m['activated'] = 1;
				}
			}

			// in case of module activation, enable all rights for dyapi user
			if ($m['activated']) {
				activateModule($m['module']);
				$dyapiuser->addrights(0, $modrights, '', 0, 1);
			}
		} else if ($forceaddrights) {
			$dyapiuser->addrights(0, $modrights, '', 0, 1);
		}
	}

	// register this Dolibarr installation to DyaPi (creates/updates the licence and stores uuid+secret)
	$result = dyapiconnectCallRegister($dyapiuser->api_key);
	if (empty($result))
		$error++;

	if (!$error) {
		// hand off to DyaPi via the signed claim link so the user signs in (or creates an account) and
		// confirms linking this licence. The secret stays here; only the signature travels. We pass this
		// setup page as the return URL so DyaPi can offer a "Return to your software" button afterwards.
		$claim_url = dyapiconnectClaimUrl(dol_buildpath('/dyapiconnect/admin/setup.php', 2));
		if (!empty($claim_url)) {
			header("Location: ".$claim_url);

			exit;
		}

		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}


/*
 * View
 */

$help_url = '';
$page_name = "DyaPiConnectSetup";

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = dyapiconnectAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($page_name), -1, "dyapiconnect@dyapiconnect");

// Intro + button adapt to whether this installation is already registered with DyaPi (UUID present):
//  - not yet connected -> invite to link the account (first-time wording);
//  - already connected  -> invite to manage the connection.
// The per-account nuance (linked by you or not) is handled on the DyaPi claim page, not here. The
// company name / base URL are intentionally not shown — that identity belongs in DyaPi.
dyapiconnectGetConst();
$dyapiconnect_connected = !empty($conf->dyapiconnect->options->uuid);

echo '<div class="info opacitymedium" style="margin-bottom:1em">'.$langs->trans($dyapiconnect_connected ? "DyaPiConnectSetupPageConnected" : "DyaPiConnectSetupPage").'</div>';


print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="connect">';

print '<table class="noborder centpercent">';

// Let the user choose which pre-configuration should be made : minimalist (only dyapi user created + REST API activated with DOLAPIKEY generated) or standard (minimalist + standard modules activated)
print '<tr class="liste_titre">';
print '<td class="titlefield">'.$langs->trans('OptionDyaPiConnectMode').'</td><td>'.$langs->trans('Description').'</td>';
print "</tr>\n";
// Minimalist : dyapi user created + REST API activated with DOLAPIKEY generated
print '<tr class="oddeven"><td><input type="checkbox" name="dyapiconnect_minimalist" value="1" checked disabled> '.$langs->trans('OptionDyaPiConnectModeMinimalist').'</td>';
print '<td>'.nl2br($langs->trans('OptionDyaPiConnectModeMinimalistDesc', $dyapiuser_login));
print "</td></tr>\n";
// Propale : activate modules : thirdparties, propale
print '<tr class="oddeven"><td><input type="checkbox" name="dyapiconnect_propale" value="1"'.($dyapiconnect_mode['propale'] ? ' checked' : '').'> '.$langs->trans('OptionDyaPiConnectModePropale').'</td>';
print '<td>'.nl2br($langs->trans('OptionDyaPiConnectModePropaleDesc'))."</td></tr>\n";
// Invoice : activate modules : thirdparties, invoices, banks
print '<tr class="oddeven"><td><input type="checkbox" name="dyapiconnect_invoice" value="1"'.($dyapiconnect_mode['invoice'] ? ' checked' : '').'> '.$langs->trans('OptionDyaPiConnectModeInvoice').'</td>';
print '<td>'.nl2br($langs->trans('OptionDyaPiConnectModeInvoiceDesc'))."</td></tr>\n";
// e-Commerce : activate modules : orders, products, thirdparties, expeditions, invoices, banks, stocks
print '<tr class="oddeven"><td><input type="checkbox" name="dyapiconnect_ecommerce" value="1"'.($dyapiconnect_mode['ecommerce'] ? ' checked' : '').'> '.$langs->trans('OptionDyaPiConnectModeECommerce').'</td>';
print '<td>'.nl2br($langs->trans('OptionDyaPiConnectModeECommerceDesc'))."</td></tr>\n";

print "</table>\n";

print '<div class="center">';
print '<input class="button button-connect" type="submit" value="'.$langs->trans($dyapiconnect_connected ? "ButtonManageDyaPiConnection" : "ButtonConnectDyaPi").'">';
print '</div>';

print '</form>';

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
