<?php
/* Copyright (C) 2023 François Pons <fpons@aytechnet.fr>
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
 * \file    dyapiconnect/lib/dyapiconnect.lib.php
 * \ingroup dyapiconnect
 * \brief   Library files with common functions for DyaPiConnect
 */

define("DYAPICONNECT_VERSION", "1.0.0");

require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";

/**
 * Prepare admin pages header
 *
 * @return array
 */
function dyapiconnectAdminPrepareHead() {
	global $langs, $conf;

	$langs->load("dyapiconnect@dyapiconnect");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/dyapiconnect/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/dyapiconnect/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	return $head;
}

/**
 * Get dyapi user login
 *
 * @return string
 */
function dyapiconnectUserLogin() {
	global $db, $conf;

	// only user login is associated to entity 0 as user is global to all entities
	$login = dolibarr_get_const($db, 'DYAPICONNECT_USER_LOGIN', 0);
	if (empty($login))
		$login = 'dyapi';

	return $login;
}

/**
 * Search for dyapi user
 *
 * @return User
 */
function dyapiconnectUser($login = 'dyapi') {
	global $db, $conf;

	$dyapiuser = new User($db);
	if ($dyapiuser->fetch('', $login) > 0)
		return $dyapiuser;

	return false;
}

function dyapiconnectCreateUser($login = 'dyapi') {
	global $db;

	$createuser=new User($db);
    $createuser->id = 0;
    $createuser->admin = 1;

	$newuser = new User($db);
    $newuser->admin = 1;
	$newuser->entity = 0;
	$newuser->lastname = "DyaPi";
	$newuser->firstname = "";
	$newuser->login = $login;
	$newuser->email = "";
	$newuser->api_key = getRandomPassword(true);

	$result = $newuser->create($createuser,1);
	if ($result > 0) {
		dyapiconnectSetUserPhoto($newuser); // module logo as avatar (nicer in the user admin)
		return $newuser;
	}
	if ($newuser->error == 'ErrorLoginAlreadyExists') {
		return $newuser;
	}

	return false;
}

/**
 * Give the dyapi technical user the module logo as avatar — nicer in Dolibarr's user admin. Copies
 * img/object_dyapiconnect.png into the user's photo directory and sets User::photo. Best-effort:
 * never blocks user creation/setup.
 *
 * @param	User	$dyapiuser	the just-created or fetched dyapi user (must have an id)
 * @return	int					1 on success, 0 otherwise
 */
function dyapiconnectSetUserPhoto(&$dyapiuser) {
	global $db, $conf;

	if (empty($dyapiuser) || empty($dyapiuser->id))
		return 0;

	require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

	$src = dol_buildpath('/dyapiconnect/img/object_dyapiconnect.png', 0);
	if (!dol_is_file($src))
		return 0;

	$filename = 'dyapi.png';
	$dir = $conf->user->dir_output.'/'.$dyapiuser->id.'/photos';
	dol_mkdir($dir);

	if (dol_copy($src, $dir.'/'.$filename, '0', 1) > 0) {
		// generate the _small / _mini thumbnails Dolibarr actually renders for the avatar (user card,
		// user list, top menu). Without them the photo field is set but no logo shows — mirrors what
		// user/card.php does after a photo upload ($object->addThumbs($newfile)).
		$dyapiuser->addThumbs($dir.'/'.$filename);
		$dyapiuser->photo = $filename;
		$dyapiuser->update($dyapiuser, 1, 1); // notrigger, nosyncmember

		return 1;
	}

	return 0;
}

function dyapiconnectGenerateApiKey(&$dyapiuser)
{
	if (!empty($dyapiuser)) {
		global $db;

		$updateuser = new User($db);
		$updateuser->api_key = getRandomPassword(true);

		if ($dyapiuser->update($updateuser, 1, 1) >= 0)
			return $dyapiuser;
	}

	return false;
}

function dyapiconnectGetConst() {
	global $db, $conf;

	if ( empty( $conf->dyapiconnect->options ) )
		$conf->dyapiconnect->options = json_decode(dolibarr_get_const($db, 'DYAPICONNECT_OPTIONS', $conf->entity));

	if ( empty( $conf->dyapiconnect->options ) )
		$conf->dyapiconnect->options = (object)[];

	if ( empty( $conf->dyapiconnect->options->api ) )
		$conf->dyapiconnect->options->api = "https://api.dyapi.io";

	if ( empty( $conf->dyapiconnect->options->web ) )
		$conf->dyapiconnect->options->web = "https://dyapi.io"; // website host (claim/onboarding), distinct from the API host
}

/**
 * Build the signed "link to my account" URL for this licence. The signature proves possession of the
 * secret without ever exposing it: sig = HMAC-SHA256(key = secret, message = "uuid|exp"), matching
 * DyaPi's claim.go (licenceClaimSig). The link is valid 24h: security rests on the HMAC (possession
 * of the secret), while exp only bounds replay — the window must survive the first-time sign-up +
 * email-verification detour before the user returns to the claim link, plus a modest clock skew
 * between this server and DyaPi. Returns '' if not yet registered.
 */
function dyapiconnectClaimUrl($return = '') {
	global $conf, $langs;

	dyapiconnectGetConst();
	$o = $conf->dyapiconnect->options;

	if ( empty( $o->uuid ) || empty( $o->secret ) )
		return '';

	$exp = time() + 86400; // 24h — survives the sign-up/email-verification detour and clock skew (see above)
	$sig = hash_hmac('sha256', $o->uuid.'|'.$exp, $o->secret);
	$lang = ( strncmp( (string)$langs->defaultlang, 'fr', 2 ) === 0 ) ? 'fr' : 'en';

	$query = [
		'uuid' => $o->uuid,
		'exp'  => $exp,
		'sig'  => $sig,
		'lang' => $lang,
	];

	// optional "return to your software" URL: DyaPi accepts it only if it is on the same host as this
	// licence's registered base URL, so it does not need to be part of the HMAC signature.
	if ( !empty( $return ) )
		$query['return'] = $return;

	return $o->web.'/licence/claim?'.http_build_query($query);
}

/**
 * Call DyaPi API
 *
 * @param string 		$url		End-point URL
 * @param Object	 	$input		Input object which will be converted to json
 * @param int 			$timeout 	Timeout in second
 * @return null|Object	API Response as object, or null on error
 */
function dyapiconnectCall($url, $input = null, $timeout = 5) {
	global $conf;

	if (!empty($conf->dyapiconnect)) {
		dyapiconnectGetConst();

		$curl = curl_init();

		if ( $timeout > 0 ) {
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $timeout);
			curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
			curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $timeout);
		} else {
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, 0);
			curl_setopt($curl, CURLOPT_HEADER, 0);
		}

		curl_setopt($curl, CURLOPT_URL, $conf->dyapiconnect->options->api . $url);
		curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($curl, CURLOPT_POST, true);

		$data = empty( $input ) ? "" : json_encode( $input );
		$header = [
			'Content-Type: application/json',
			'Content-Length: '.strlen($data)
		];

		if ( !empty( $conf->dyapiconnect->options->auth ) )
			$header[] = 'DYAPI_AUTH: '.$conf->dyapiconnect->options->auth;

		curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

		$resp = curl_exec($curl);

		curl_close($curl);

		return json_decode($resp);
	}

	return null;
}

/**
 * Build the Factur-X seller identity + bank accounts from the company (mysociété), shared by the
 * register payload and the change-detection fingerprint so both stay in sync. The seller is null when
 * the company is not configured. See dyapi/facturx-erp-sourced-seller.md.
 *
 * @return	array	['seller' => array|null, 'bank_accounts' => object[]]
 */
function dyapiconnectSellerData() {
	global $db, $conf, $mysoc, $langs;

	$country_code = $mysoc->country_code;
	$name = $conf->global->MAIN_INFO_SOCIETE_NOM;

	$bank_accounts = [];
	$default_iban = '';
	$resql = $db->query('SELECT b.rowid, b.ref, b.label, c.code, b.currency_code, b.iban_prefix, b.bic FROM '.MAIN_DB_PREFIX.'bank_account b
						 LEFT JOIN '.MAIN_DB_PREFIX.'c_country c ON c.rowid = b.fk_pays WHERE b.clos = 0 AND b.entity = '.$conf->entity);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$iban = preg_replace('/\s+/', '', (string)$obj->iban_prefix); // BT-84
			if ($default_iban === '' && $iban !== '') $default_iban = $iban;
			$bank_accounts[] = (object)[
				'id' => $obj->rowid, 'reference' => $obj->ref, 'name' => html_entity_decode($obj->label),
				'iban' => $iban, 'bic' => (string)$obj->bic,
				'country_code' => $obj->code, 'currency' => $obj->currency_code,
			];
		}
	}

	// BT-22 legal mentions. Primary source: the invoice free text (INVOICE_FREE_TEXT), where the
	// merchant keeps RCS / share capital / late-payment penalties / discount terms. Resolve the
	// company-level substitution variables, turn block/br HTML into line breaks, strip the remaining
	// tags, and split on line breaks so each line becomes a distinct Factur-X note (BT-22). The auto
	// "Capital social" line is only a fallback when the free text is empty, to avoid listing the
	// capital twice when the merchant already put it in the free text. Part of the seller fingerprint
	// (dyapiconnectSellerFingerprint), so a change to the free text triggers a re-register.
	$legal_mentions = [];
	$free = isset($conf->global->INVOICE_FREE_TEXT) ? (string) $conf->global->INVOICE_FREE_TEXT : '';
	if (trim($free) !== '') {
		$free = make_substitutions($free, getCommonSubstitutionArray($langs));
		$free = preg_replace('/<\s*br\s*\/?>|<\/(p|div|li|tr)\s*>/i', "\n", $free); // block/br → newline
		$free = dol_string_nohtmltag($free, 0); // strip remaining tags, keep line breaks
		$free = html_entity_decode($free, ENT_QUOTES | ENT_HTML5);
		foreach (preg_split('/\R/u', $free) as $line) {
			$line = trim($line);
			if ($line !== '') $legal_mentions[] = $line;
		}
	}
	if (empty($legal_mentions) && !empty($mysoc->capital))
		$legal_mentions[] = 'Capital social : '.$mysoc->capital.' '.$conf->currency;

	$seller = empty($name) ? null : [
		'name' => $name,
		'siren' => (string)$mysoc->idprof1,
		'siret' => (string)$mysoc->idprof2,
		'vat_number' => (string)$mysoc->tva_intra,
		'street' => (string)$mysoc->address,
		'postcode' => (string)$mysoc->zip,
		'city' => (string)$mysoc->town,
		'country_code' => $country_code,
		'iban' => $default_iban,
		'legal_mentions' => $legal_mentions,
	];

	return ['seller' => $seller, 'bank_accounts' => $bank_accounts];
}

/**
 * sha256 of the seller + bank data, used to detect a change since the last register
 * (stored as DYAPICONNECT_SELLER_HASH).
 *
 * @param	array|null	$data	pre-built dyapiconnectSellerData() result (recomputed if null)
 * @return	string
 */
function dyapiconnectSellerFingerprint($data = null) {
	if ($data === null) $data = dyapiconnectSellerData();
	return hash('sha256', json_encode($data));
}

function dyapiconnectCallRegister($api_key, $backtopage = '', $state = '') {
	global $db, $conf, $mysoc, $langs;

	if (!empty($conf->dyapiconnect) && !empty($api_key)) {
		dyapiconnectGetConst();

		$url = DOL_MAIN_URL_ROOT.'/api/index.php';

		if (empty($conf->dyapiconnect->options->secret)) {
			$conf->dyapiconnect->options->secret = str_shuffle(md5('dyapiconnect-dolibarr/'.DOL_VERSION.'; '.$url.'; '.microtime()));

			dolibarr_set_const($db, 'DYAPICONNECT_OPTIONS', json_encode($conf->dyapiconnect->options), 'chaine', 0, '', $conf->entity);
		}

		$country_code = $mysoc->country_code;
		$name = $conf->global->MAIN_INFO_SOCIETE_NOM;
		$payment_types = [];
		$bank_accounts = [];

		// extract payment_types and do not use API as translations are not available
		$resql = $db->query("SELECT id, code, libelle, type FROM ".MAIN_DB_PREFIX."c_paiement WHERE active = 1 AND entity = ".$conf->entity);
        if ($resql) {
			$langs->load("bills");
			$num = $db->num_rows($resql);
			if ($num) {
				$i = 0;
				while ($i < $num) {
					$obj = $db->fetch_object($resql);
					$key = $langs->trans("PaymentType".strtoupper($obj->code));
					$libelle = ($obj->code && $key != "PaymentType".strtoupper($obj->code) ? $key : $obj->libelle);
					if ($obj->type == 0 || $obj->type == 2)
						$payment_types[] = (object)[ 'id' => $obj->id, 'reference' => $obj->code, 'name' => html_entity_decode($libelle) ];
					$i++;
				}
			}
		}
		// TODO: do the same for supplier_payment_types as well...

		// seller identity + bank accounts, shared with the change-detection fingerprint (so what is
		// sent and what is hashed never diverge)
		$sellerData = dyapiconnectSellerData();
		$bank_accounts = $sellerData['bank_accounts'];

		$options = [];
		if ( isModEnabled( 'adherent' ) ) {
			$options['member'] = [ 'taxes' => 0 ];

			if ( !empty( $conf->global->ADHERENT_PRODUCT_ID_FOR_SUBSCRIPTIONS ) )
				$options['member']['product_id'] = $conf->global->ADHERENT_PRODUCT_ID_FOR_SUBSCRIPTIONS;
			if ( isset( $conf->global->ADHERENT_VAT_FOR_SUBSCRIPTIONS ) && $conf->global->ADHERENT_VAT_FOR_SUBSCRIPTIONS == 'defaultforfoundationcountry' )
				$options['member']['taxes'] = get_default_tva($mysoc, $mysoc, !empty( $options['member']['product_id'] ) ? $options['member']['product_id'] : 0 );
		}

		$register = [
			'secret' => $conf->dyapiconnect->options->secret,
			'version' => DOL_APPLICATION_TITLE . '/'. DOL_VERSION . '; DyaPiConnect/' . DYAPICONNECT_VERSION,
			'protocol' => 'dolibarr',
			'url' => $url,
			'dolapikey' => $api_key,
			'entity' => (int)$conf->entity,
			'name' => $name,
			'country_code' => $country_code,
			'payment_types' => $payment_types,
			'bank_accounts' => $bank_accounts,
		];

		if ( !empty( $sellerData['seller'] ) )
			$register['seller'] = $sellerData['seller'];

		if ( !empty( $options ) )
			$register['options'] = $options;

		if (!empty($conf->dyapiconnect->options->uuid))
			$register['uuid'] = $conf->dyapiconnect->options->uuid;

		if (!empty($backtopage)) {
			$register['redirect_uri'] = $backtopage;
			if (!empty($state))
				$register['state'] = $state;
		}

		$result = dyapiconnectCall("/v1/register", $register);

		if (!empty($result)) {
			dolibarr_set_const($db, 'DYAPICONNECT_OPTIONS', json_encode($conf->dyapiconnect->options = $result), 'chaine', 0, '', $conf->entity);
			// remember what we just sent, so dyapiconnectReRegisterIfChanged() only re-registers on a change
			dolibarr_set_const($db, 'DYAPICONNECT_SELLER_HASH', dyapiconnectSellerFingerprint($sellerData), 'chaine', 0, '', $conf->entity);
		}

		return $result;
	}

	return null;
}

/**
 * Re-register with DyaPi to push the current company / bank-account / Factur-X seller snapshot, so
 * DyaPi stays in sync when a key element changes (IBAN/bank accounts, and — best effort — company
 * identity). Called from the triggers class. No-op when not registered yet or no dyapi api key.
 *
 * @return	object|null		the /v1/register result, or null
 */
function dyapiconnectReRegister() {
	global $conf;

	if (empty($conf->dyapiconnect) || empty($conf->dyapiconnect->enabled))
		return null;

	if (empty($conf->dyapiconnect->options))
		dyapiconnectGetConst();
	if (empty($conf->dyapiconnect->options) || empty($conf->dyapiconnect->options->uuid))
		return null; // not registered yet

	$dyapiuser = dyapiconnectUser();
	if (empty($dyapiuser) || empty($dyapiuser->api_key))
		return null;

	return dyapiconnectCallRegister($dyapiuser->api_key);
}

/**
 * Re-register ONLY when the Factur-X seller / bank data has changed since the last register, detected
 * by comparing the current fingerprint to DYAPICONNECT_SELLER_HASH. Cheap and idempotent: safe to call
 * on every invoice generation (afterPDFCreation) and from a cron, without spamming /v1/register.
 * See dyapi/facturx-erp-sourced-seller.md §2.1.
 *
 * @return	object|null		the /v1/register result when a re-register happened, else null
 */
function dyapiconnectReRegisterIfChanged() {
	global $conf;

	if (empty($conf->dyapiconnect) || empty($conf->dyapiconnect->enabled))
		return null;

	if (empty($conf->dyapiconnect->options))
		dyapiconnectGetConst();
	if (empty($conf->dyapiconnect->options) || empty($conf->dyapiconnect->options->uuid))
		return null; // not registered yet

	$current = dyapiconnectSellerFingerprint();
	$stored = !empty($conf->global->DYAPICONNECT_SELLER_HASH) ? $conf->global->DYAPICONNECT_SELLER_HASH : '';
	if ($current === $stored)
		return null; // unchanged

	return dyapiconnectReRegister();
}

