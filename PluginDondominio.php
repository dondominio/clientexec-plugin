<?php
/**
 * DonDominio Domain Registrar Plugin
 *
 * @category 	Plugin
 * @package  	ClientExec
 * @author   	Miky Moya <miki.moya@scip.es>
 * @license  	GPL
 * @version  	1
 * @link	 	http://www.dondominio.com
 */

require_once 'modules/admin/models/RegistrarPlugin.php';
require_once 'modules/domains/models/ICanImportDomains.php';

/**
 * The DonDominio API Client for PHP.
 */
require_once dirname(__FILE__).'/lib/sdk/DonDominioAPI.php';

/**
 * DonDominio Domain Registrar Plugin
 *
 * @category 	Plugin
 * @package  	ClientExec
 * @author   	Miky Moya <miki.moya@scip.es>
 * @license  	GPL
 * @version  	1
 * @link	 	http://www.dondominio.com
 */
class PluginDonDominio extends RegistrarPlugin implements ICanImportDomains
{
	/**
	 * Get plugin configuration parameters.
	 * @return array
	 */
	function getVariables()
	{
		$variables = array(
			lang('Plugin Name') => array (
				'type' => 'hidden',
				'description' => lang('How CE sees this plugin (not to be confused with the Signup Name)'),
				'value' => lang('DonDominio')
			),
			lang('API Username') => array(
				'type' => 'text',
				'description' => lang('Enter your DonDominio API Username.<br/>'),
				'value' => ''
			),
			lang('API Key') => array(
				'type' => 'text',
				'description' => lang('Enter your DonDominio API Key.'),
				'value' => ''
			),
			lang('Owner Override') => array(
				'type' => 'text',
				'description' => lang('Enter a DonDominio Contact ID to override Owner information.'),
				'value' => ''
			),
			lang('Allow Contact edition') => array(
				'type' => 'yesno',
				'description' => lang('When overriding Owner Contact information, allow to update contact information.'),
				'value' => 0
			),
			lang('Admin Override') => array(
				'type' => 'text',
				'description' => lang('Enter a DonDominio Contact ID to override Admin information.'),
				'value' => ''
			),
			lang('Tech Override') => array(
				'type' => 'text',
				'description' => lang('Enter a DonDominio Contact ID to override Tech information.'),
				'value' => ''
			),
			lang('Billing Override') => array(
				'type' => 'text',
				'description' => lang('Enter a DonDominio Contact ID to override Billing information.'),
				'value' => ''
			),
			lang('Supported Features') => array(
				'type' => 'label',
				'description' => '* '.lang('TLD Lookup').'<br>* '.lang('Domain Registration').' <br>* '.lang('Domain Registration with ID Protect').' <br>* '.lang('Existing Domain Importing').' <br>* '.lang('Get / Set Auto Renew Status').' <br>* '.lang('Get / Set Nameserver Records').' <br>* '.lang('Get / Set Contact Information').' <br>* '.lang('Get / Set Registrar Lock').' <br>* '.lang('Initiate Domain Transfer').' <br>* '.lang('Automatically Renew Domain').' <br>',
				'value' => ''
			),
			lang('Actions') => array (
				'type' => 'hidden',
				'description' => lang('Current actions that are active for this plugin (when a domain isn\'t registered)'),
				'value' => 'Register'
			),
			lang('Registered Actions') => array (
				'type' => 'hidden',
				'description' => lang('Current actions that are active for this plugin (when a domain is registered)'),
				'value' => 'Renew (Renew Domain),DomainTransfer (Initiate Transfer),SendTransferKey (Send Auth Info)',
			),
			lang('Registered Actions For Customer') => array (
				'type' => 'hidden',
				'description' => lang('Current actions that are active for this plugin (when a domain is registered)'),
				'value' => 'SendTransferKey (Send Auth Info)'
			)
		);
		
		return $variables;
	}
	
	/**
	 * Check domain availability.
	 * @param array $params Parameters from CE
	 * @return array
	 * @throws CE_Exception on failure
	 */
	function checkDomain($params)
	{
		$dondominio = $this->_init($params);
		$result = array();
		
		//Check if the domain is in the user account
		try{
			$status = $dondominio->domain_list(
				array
				(
					'domain' => strtolower($params['sld'] . '.' . $params['tld'])
				)
			);
		}catch(DonDominioAPI_Error $e){
			CE_Lib::log(4, "Domain Lookup Failed: " . $e->getMessage());
			throw new CE_Exception("Domain Lookup Failed: " . $e->getMessage());
		}
		
		$info = $status->get("queryInfo");
		
		if($info['results'] == 1){
			$result['result'][] = array
			(
				'tld' => $params['tld'],
				'sld' => $params['sld'],
				'domain' => $params['sld'],
				'status' => 1
			);
			
			return $result;
		}
		
		try{
			$check = $dondominio->domain_check(
				strtolower($params['sld'] . '.' . $params['tld'])
			);
			
			$available = ($check->get("available")) ? 1 : 0;
		}catch(DonDominioAPI_Error $e){
			CE_Lib::log(4, "Domain Lookup Failed: " . $e->getMessage());
			throw new CE_Exception("Domain Lookup Failed: " . $e->getMessage());
		}
		
		$result['result'][] = array
		(
			'tld' => $params['tld'],
			'sld' => $params['sld'],
			'domain' => $params['sld'],
			'status' => $available
		);
		
		return $result;
	}
	
	/**
	 * Prepare to renew a domain.
	 * @param array $params Parameters from CE
	 * @return string
	 */
	function doRenew($params)
	{
		$userPackage = new UserPackage($params['userPackageId']);
		
        $orderid = $this->renewDomain($this->buildRenewParams($userPackage,$params));
        
        $userPackage->setCustomField("Registrar Order Id",$userPackage->getCustomField("Registrar").'-'.$orderid);
        
        return $this->user->lang($userPackage->getCustomField('Domain Name') . ' has been renewed.');
	}
	
	/**
	 * Attempt to renew a domain.
	 * @param array $params Parameters from CE
	 * @return integer
	 * @throws CE_Exception on failure
	 */
	function renewDomain($params)
	{
		$dondominio = $this->_init($params);
		
		try{
			$info = $dondominio->domain_getInfo(
				strtolower($params['sld'] . '.' . $params['tld']),
				array(
					'infoType' => 'status'
				)
			);
		}catch(DonDominioAPI_Error $e){
			CE_Lib::log(4, "DonDominio API Call Error: " . $e->getMessage());
			throw new CE_Exception("DonDominio API Call Error: " . $e->getMessage());
		}
		
		try{
			$renew = $dondominio->domain_renew(
				strtolower($params['sld'] . '.' . $params['tld']),
				array(
					'curExpDate' => $info->get("tsExpir"),
					'period' => $params['NumYears']
				)
			);
		}catch(DonDominioAPI_Error $e){
			CE_Lib::log(4, "DonDominio API Call Error: " . $e->getMessage());
			throw new CE_Exception("DonDominio API Call Error: " . $e->getMessage());
		}
		
		return $renew->get("domains")[0]['domainID'];
	}
	
	/**
	 * Prepare to transfer a domain.
	 * @param array $params Parameters from CE
	 * @return string
	 */
	function doDomainTransfer($params)
	{
		$userPackage = new UserPackage($params['userPackageId']);
        
		$transferid = $this->initiateTransfer($this->buildTransferParams($userPackage,$params));
        
        $userPackage->setCustomField("Registrar Order Id",$userPackage->getCustomField("Registrar").'-'.$transferid);
        
        $userPackage->setCustomField('Transfer Status', $transferid);
        
        return $this->user->lang("Transfer of " . $params['sld'] . '.' . $params['tld'] . " has been initiated.");
	}
	
	/**
	 * Transfer a domain.
	 * @param array $params Parameters from CE
	 * @return integer
	 * @throws CE_Exception on failure
	 */
	function initiateTransfer($params)
	{
		$dondominio = $this->_init($params);
		
		//Domain custom fields
		$additionalFields = $this->_getAdditionalFields($params['userPackageId']);
		
		//User custom fields
		$userData = $this->_getUserData($params['RegistrantEmailAddress']);
		
		//Extended attributes built in CE
		$ext = $params['ExtendedAttributes'];
		
		$arguments = array(
			'period' => $params['NumYears'],
			'premium' => false
		);
		
		switch($params['tld']){
		case 'aero':
			$arguments['aeroId'] = $additionalFields['Aero ID'];
			$arguments['aeroPass'] = $additionalFields['Aero Pass'];
			break;
			
		case 'scot':
		case 'quebec':
			$arguments['domainIntendedUse'] = $ext['core_intendeduse'];
			break;
			
		case 'cat':
		case 'pl':
		case 'eus':
		case 'gal':
			$arguments['domainIntendedUse'] = $additionalFields['Intended Use'];
			break;
			
		case 'coop':
			$arguments['coopCVC'] = $additionalFields['CVC'];
			break;
			
		case 'fr':
			$arguments['ownerDateOfBirth'] = $userData['Date of Birth'];
			break;
			
		case 'hk':
			$arguments['ownerDateOfBirth'] = $userData['Date of Birth'];
			break;
			
		case 'it':
			$arguments['ownerDateOfBirth'] = $userData['Date of Birth'];
			$arguments['ownerPlaceOfBirth'] = $additionalFields['Place of Birth'];
			break;
			
		case 'jobs':
			$arguments['jobsOwnerIsAssocMember'] = false;
			$arguments['jobsOwnerWebsite'] = $additionalFields['Registrant Website'];
			$arguments['jobsAdminWebsite'] = $additionalFields['Admin Website'];
			$arguments['jobsTechWebsite'] = $additionalFields['Tech Website'];
			$arguments['jobsBillingWebsite'] = $additionalFields['Billing Website'];
			break;
			
		case 'lawyer':
		case 'attorney':
		case 'dentist':
		case 'airforce':
		case 'army':
		case 'navy':
			$arguments['coreContactInfo'] = $additionalFields['Contact Info'];
			break;
			
		case 'pro':
			$arguments['proProfession'] = $ext['pro_profession'];
			break;
			
		case 'ru':
			$arguments['ownerDateOfBirth'] = $userData['Date of Birth'];
			$arguments['ruIssuer'] = $additionalFields['Issuer'];
			$arguments['ruIssuerDate'] = $additionalFields['Issue Date'];
			break;
			
		case 'travel':
			$arguments['travelUIN'] = $additionalFields['UIN'];
			break;
			
		case 'xxx':
			$arguments['xxxClass'] = $additionalFields['Class'];
			$arguments['xxxName'] = $params['RegistrantFirstName'] . ' ' . $params['RegistrantLastName'];
			$arguments['xxxEmail'] = $params['RegistrantEmailAddress'];
			$arguments['xxxId'] = $additionalFields['ID'];
			break;
		}
		
		$params['RegistrantPhone'] = $this->_parsePhone($params['RegistrantPhone'], $params['RegistrantCountry']);
		
		//Adding the Address2 field
		if(!empty($params['RegistrantAddress2'])){
			$params['RegistrantAddress1'] .= "\r\n\r\n" . $params['RegistrantAddress2'];
		}
		
		/*
		 * Adding contact information.
		 */
		
		if(!empty($params['Owner Override'])){
			$arguments['ownerContactID'] = $params['Owner Override'];
		}else{
			$arguments = array_merge(
				$arguments,
				array
				(
					'ownerContactType' => 'individual',
					'ownerContactFirstName' => $params['RegistrantFirstName'],
					'ownerContactLastName' => $params['RegistrantLastName'],
					'ownerContactEmail' => $params['RegistrantEmailAddress'],
					'ownerContactIdentNumber' => $userData['VAT Number'],
					'ownerContactPhone' => $params['RegistrantPhone'],
					'ownerContactAddress' => $params['RegistrantAddress1'],
					'ownerContactPostalCode' => $params['RegistrantPostalCode'],
					'ownerContactCity' => $params['RegistrantCity'],
					'ownerContactState' => $params['RegistrantStateProvince'],
					'ownerContactCountry' => $params['RegistrantCountry']
				)
			);
		}
		
		if(!empty($params['Admin Override'])){
			$arguments['adminContactID'] = $params['Admin Override'];
		}
		
		if(!empty($params['Tech Override'])){
			$arguments['techContactID'] = $params['Tech Override'];
		}
		
		if(!empty($params['Billing Override'])){
			$arguments['billingContactID'] = $params['Billing Override'];
		}
		
		/* *** */
		 
		try{
			$register = $dondominio->domain_transfer(
				strtolower($params['sld'] . '.' . $params['tld']),
				$arguments
			);
		}catch(DonDominioAPI_Error $e){
			CE_Lib::log(4, "DonDominio API Error: " . $e->getMessage());
			throw new CE_Exception("DonDominio API Error: " . $e->getMessage());
		}
		
		return $register->get("domains")['domainID'];
	}
	
	/**
	 * Check if a domain has been successfully transferred.
	 * @param array $params Parameters from CE
	 * @return string
	 * @throws CE_Exception on failure
	 */
	function getTransferStatus($params)
	{
		$userPackage = new UserPackage($params['userPackageId']);
		
		$dondominio = $this->_init($params);
		
		try{
			$status = $dondominio->domain_getInfo(
				strtolower($params['sld'] . '.' . $params['tld']),
				array
				(
					'infoType' => 'status'
				)
			);
		}catch(DonDominioAPI_Error $e){
			CE_Lib::log(4, "DonDominio Error getting domain information: " . $e->getMessage());
			throw new CE_Exception("DonDominio Error getting domain information: " . $e->getMessage());
		}
		
		$transferStatus = $status->get("status");
		
		if($transferStatus == 'active'){
			$userPackage->setCustomField('Transfer Status', 'Completed');
		}
		
		return $transferStatus;
	}
	
	/**
	 * Start domain registration.
	 * @param array $params Parameters from CE
	 * @return string
	 */
	function doRegister($params)
	{
		$userPackage = new UserPackage($params['userPackageId']);
		
		$this->registerDomain($this->buildRegisterParams($userPackage, $params));
		
		$userPackage->setCustomField('Registrar Order Id', $userPackage->getCustomField("Registrar") . '-' . $params['userPackageId']);
		
		return $userPackage->getCustomField("Domain Name") . " has been registered.";
	}
	
	/**
	 * Register domain.
	 * @param array $params Parameters from CE
	 * @throws CE_Exception if missing VAT Number or failure when registering
	 */
	function registerDomain($params)
	{
		die(print_r($params));
		$dondominio = $this->_init($params);
		
		//Domain custom fields
		$additionalFields = $this->_getAdditionalFields($params['userPackageId']);
		
		//User custom fields
		$userData = $this->_getUserData($params['RegistrantEmailAddress']);
		
		//Extended attributes built in CE
		$ext = $params['ExtendedAttributes'];
		
		$arguments = array(
			'period' => $params['NumYears'],
			'premium' => false
		);
		
		switch($params['tld']){
		case 'aero':
			$arguments['aeroId'] = $additionalFields['Aero ID'];
			$arguments['aeroPass'] = $additionalFields['Aero Pass'];
			break;
			
		case 'scot':
		case 'quebec':
			$arguments['domainIntendedUse'] = $ext['core_intendeduse'];
			break;
			
		case 'cat':
		case 'pl':
		case 'eus':
		case 'gal':
			$arguments['domainIntendedUse'] = $additionalFields['Intended Use'];
			break;
			
		case 'coop':
			$arguments['coopCVC'] = $additionalFields['CVC'];
			break;
			
		case 'fr':
			$arguments['ownerDateOfBirth'] = $userData['Date of Birth'];
			break;
			
		case 'hk':
			$arguments['ownerDateOfBirth'] = $userData['Date of Birth'];
			break;
			
		case 'it':
			$arguments['ownerDateOfBirth'] = $userData['Date of Birth'];
			$arguments['ownerPlaceOfBirth'] = $additionalFields['Place of Birth'];
			break;
			
		case 'jobs':
			$arguments['jobsOwnerIsAssocMember'] = false;
			$arguments['jobsOwnerWebsite'] = $additionalFields['Registrant Website'];
			$arguments['jobsAdminWebsite'] = $additionalFields['Admin Website'];
			$arguments['jobsTechWebsite'] = $additionalFields['Tech Website'];
			$arguments['jobsBillingWebsite'] = $additionalFields['Billing Website'];
			break;
			
		case 'lawyer':
		case 'attorney':
		case 'dentist':
		case 'airforce':
		case 'army':
		case 'navy':
			$arguments['coreContactInfo'] = $additionalFields['Contact Info'];
			break;
			
		case 'pro':
			$arguments['proProfession'] = $ext['pro_profession'];
			break;
			
		case 'ru':
			$arguments['ownerDateOfBirth'] = $userData['Date of Birth'];
			$arguments['ruIssuer'] = $additionalFields['Issuer'];
			$arguments['ruIssuerDate'] = $additionalFields['Issue Date'];
			break;
			
		case 'travel':
			$arguments['travelUIN'] = $additionalFields['UIN'];
			break;
			
		case 'xxx':
			$arguments['xxxClass'] = $additionalFields['Class'];
			$arguments['xxxName'] = $params['RegistrantFirstName'] . ' ' . $params['RegistrantLastName'];
			$arguments['xxxEmail'] = $params['RegistrantEmailAddress'];
			$arguments['xxxId'] = $additionalFields['ID'];
			break;
		}
		
		$params['RegistrantPhone'] = $this->_parsePhone($params['RegistrantPhone'], $params['RegistrantCountry']);
		
		//Adding the Address2 field
		if(!empty($params['RegistrantAddress2'])){
			$params['RegistrantAddress1'] .= "\r\n\r\n" . $params['RegistrantAddress2'];
		}
		
		/*
		 * Adding contact information.
		 */
		
		if(!empty($params['Owner Override'])){
			$arguments['ownerContactID'] = $params['Owner Override'];
		}else{
			$arguments = array_merge(
				$arguments,
				array
				(
					'ownerContactType' => 'individual',
					'ownerContactFirstName' => $params['RegistrantFirstName'],
					'ownerContactLastName' => $params['RegistrantLastName'],
					'ownerContactEmail' => $params['RegistrantEmailAddress'],
					'ownerContactIdentNumber' => $userData['VAT Number'],
					'ownerContactPhone' => $params['RegistrantPhone'],
					'ownerContactAddress' => $params['RegistrantAddress1'],
					'ownerContactPostalCode' => $params['RegistrantPostalCode'],
					'ownerContactCity' => $params['RegistrantCity'],
					'ownerContactState' => $params['RegistrantStateProvince'],
					'ownerContactCountry' => $params['RegistrantCountry']
				)
			);
		}
		
		if(!empty($params['Admin Override'])){
			$arguments['adminContactID'] = $params['Admin Override'];
		}
		
		if(!empty($params['Tech Override'])){
			$arguments['techContactID'] = $params['Tech Override'];
		}
		
		if(!empty($params['Billing Override'])){
			$arguments['billingContactID'] = $params['Billing Override'];
		}
		
		/* *** */
		
		try{
			$register = $dondominio->domain_create(
				strtolower($params['sld'] . '.' . $params['tld']),
				$arguments
			);
		}catch(DonDominioAPI_Error $e){
			CE_Lib::log(4, "DonDominio API Error: " . $e->getMessage());
			throw new CE_Exception("DonDominio API Error: " . $e->getMessage());
		}
		
		return $register->get("domains")['domainID'];
	}
	
	/**
	 * Disable anonymous whois service.
	 * @param array $params Parameters from CE
	 * @return string
	 * @throws CE_Exception on failure
	 */
	function disablePrivateRegistration($params)
	{
		$dondominio = $this->_init($params);
		
		try{
			$dondominio->domain_update(
				strtolower($params['sld'] . '.' . $params['tld']),
				array(
					'updateType' => 'whoisPrivacy',
					'whoisPrivacy' => false
				)
			);
		}catch(DonDominioAPI_error $e){
			CE_Lib::log(4, "DonDominio API Call Error: " . $e->getMessage());
			throw new CE_Exception("DonDominio API Call Error: " . $e->getMessage());
		}
		
		return $this->user->lang("Anonymous Whois successfully disabled");
	}
	
	/**
	 * Set autorenew status.
	 * The DonDominio API does not support this feature.
	 * @param array $params Parameters from CE
	 * @throws CE_Exception always
	 */
	function setAutorenew($params)
	{
		throw new CE_Exception("The DonDominio API does not support this feature.");
	}
	
	/**
	 * Get domain information.
	 * @param array $params Parameters from CE
	 * @return array
	 * @throws CE_Exception on failure
	 */
	function getGeneralInfo($params)
	{
		$dondominio = $this->_init($params);
		
		try{
			$info = $dondominio->domain_getInfo(
				strtolower($params['sld'] . '.' . $params['tld']),
				array
				(
					'infoType' => 'status'
				)
			);
		}catch(DonDominioAPI_Error $e){
			CE_Lib::log(4, "DonDominio API Call Failed: " . $e->getMessage());
			throw new Exception("DonDominio API Call Failed: " . $e->getMessage());
		}
		
		$name = $info->get("name");
		
		//Avoiding breaking the admin panel
		if(empty($name)){
			return null;
		}
		
		//Domain status
		$status = "Processing";
		
		if(in_array($info->get("status"), array("active", "renewed"))){
			$status = "Active";
		}
		
		if(substr($info->get("status"), 0, 6) == "expired"){
			$status = "Expired";
		}
		
		//Expiration date
		$expirationDate = "Not available yet";
		$tsExpir = $info->get("tsExpir");
		
		if(!empty($tsExpir)){
			$expirationDate = date("d/m/Y", strtotime($tsExpir));
		}
		
		//Creation date
		$creationDate = "Registration is processing";
		$tsCreate = $info->get("tsCreate");
		
		if(!empty($tsCreate)){
			$creationDate = "Registered on " . date("d/m/Y", strtotime($tsCreate));
		}
		
		$data = array();
		$data['id'] = -1;
		$data['domain'] = strtolower($params['sld']) . '.' . strtolower($params['tld']);
		$data['expiration'] = $expirationDate;
		$data['registrationstatus'] = $status;
		$data['purchasestatus'] = $creationDate;
		$data['autorenew'] = false;
		
		$data['is_registered'] = 
		(
			!in_array(
				$info->get("status"),
				array
				(
					"expired-renewgrace",
					"expired-redemption",
					"expired-pendingdelete"
				)
			)
		) ? true : false;
		
		$data['is_expired'] = 
		(
			in_array(
				$info->get("status"),
				array
				(
					"expired-renewgrace",
					"expired-redemption",
					"expired-pendingdelete"
				)
			)
		) ? true : false;
		
		return $data;
	}
	
	/**
	 * Function to import domains from OpenSRS.
	 * @param <type> $params
	 */
	function fetchDomains($params)
	{
		$limit = 100;
		$page = intval($params['next']);
		
		$dondominio = $this->_init($params);
		
		$domains = $dondominio->domain_list(
			array
			(
				'pageLength' => $limit,
				'page' => $page,
			)
		);
		
		$domainsList = array();
		$i = 0;
		
		//Looping through domain list
		foreach($domains->get("domains") as $domain){
			list($sld, $tld) = explode('.', $domain['name']);
			
			$data['id'] = ++$i;
			$data['sld'] = $sld;
			$data['tld'] = $tld;
			$data['exp'] = $domain['tsExpir'];
			
			$domainsList[] = $data;
		}
		
		//Metadata for pager
		$metaData = array();
		$metaData['total'] = $domains->get("queryInfo")['total'];
		$metaData['start'] = ($page * $limit) - $limit;
		$metaData['end'] = $page * $limit;
		$metaData['next'] = ++$page;
		$metaData['numPerPage'] = $limit;
		
		return array($domainsList, $metaData);
	}
	
	/**
	 * Get information from all domain contacts.
	 * @param array $params Parameters from CE
	 * @return array
	 * @throws CE_Exception on failure
	 */
	function getContactInformation($params)
	{
		$dondominio = $this->_init($params);
		
		try{
			$contacts = $dondominio->domain_getInfo(
				strtolower($params['sld']) . '.' . strtolower($params['tld']),
				array
				(
					'infoType' => 'contact'
				)
			);
		}catch(DonDominioAPI_Error $e){
			CE_Lib::log("DonDominio API Call Failed: " . $e->getMessage());
			throw new CE_Exception("DonDominio API Call Failed: " . $e->getMessage());
		}
		
		$info = $this->_toContactArray($contacts->get("contactOwner"), "Registrant");
		$info = array_merge($info, $this->_toContactArray($contacts->get("contactAdmin"), "Admin"));
		$info = array_merge($info, $this->_toContactArray($contacts->get("contactTech"), "Tech"));
		$info = array_merge($info, $this->_toContactArray($contacts->get("contactBilling"), "Billing"));
		
		return $info;
	}
	
	/**
	 * Set contact information for a domain.
	 * @param array $params Parameters from CE
	 * @return string
	 * @throws CE_Exception on failure
	 */
	function setContactInformation($params)
	{
		if(!empty($params['Owner Override']) && $params['Allow Contact edition'] == 0){
			CE_Lib::log(4, "DonDominio API Notice: Contact update not allowed.");
			throw new CE_Exception("Contact update is not allowed. Contact support.");
		}
		
		$dondominio = $this->_init($params);
		
		//Adding the "Address2" field
		if(!empty($params['Registrant_Address2'])){
			$params['Registrant_Address1'] .= "\r\n\r\n" . $params['Registrant_Address2'];
		}
		
		$arguments = array(
			'ownerContactType' => 'individual',
			'ownerContactOrgName' => $params['Registrant_OrganizationName'],
			'ownerContactFirstName' => $params['Registrant_FirstName'],
			'ownerContactLastName' => $params['Registrant_LastName'],
			'ownerContactIdentNumber' => $params['Registrant_VATNumber'],
			'ownerContactEmail' => $params['Registrant_EmailAddress'],
			'ownerContactPhone' => $params['Registrant_Phone'],
			'ownerContactAddress' => $params['Registrant_Address1'],
			'ownerContactPostalCode' => $params['Registrant_PostalCode'],
			'ownerContactCity' => $params['Registrant_City'],
			'ownerContactState' => $params['Registrant_StateProv'],
			'ownerContactCountry' => $params['Registrant_Country']
		);
				
		try{
			$contacts = $dondominio->domain_updateContacts(
				strtolower($params['sld'] . '.' . $params['tld']),
				$arguments
			);
		}catch(DonDominioAPI_Error $e){
			CE_Lib::log(4, "DonDominio API Call Failed: " . $e->getMessage());
			throw new CE_Exception("DonDominio API Call Failed: " . $e->getMessage());
		}
		
		return $this->user->lang("Contact Information updated successfully.");
	}
	
	/**
	 * Get domain NameServers.
	 * @param array $params Parameters from CE
	 * @return array
	 * @throws CE_Exception on failure
	 */
	function getNameServers($params)
	{
		$dondominio = $this->_init($params);
		
		try{
			$nameservers = $dondominio->domain_getInfo(
				strtolower($params['sld'] . '.' . $params['tld']),
				array(
					'infoType' => 'nameservers'
				)
			);
			
			$NSArray = $nameservers->get("nameservers");
			
			$result = array();
			
			foreach($NSArray as $server){
				$result[] = $server['name'];
			}			
		}catch(DonDominioAPI_Error $e){
			CE_Lib::log(4, "DonDominio API Call Failed: " . $e->getMessage());
			throw new CE_Exception("DonDominio API Call Failed:" . $e->getMessage());
		}
		
		return $result;
	}
	
	/**
	 * Set NameServer information.
	 * @param array $params Parameters from CE
	 * @return string
	 * @throws CE_Exception on failure
	 */
	function setNameServers($params)
	{
		$dondominio = $this->_init($params);
		
		if($params['default']){
			$ns_option = 'default';
		}else{
			$ns_option = implode(",", $params['ns']);
		}
		
		try{
			$nameservers = $dondominio->domain_updateNameServers(
				strtolower($params['sld'] . '.' . $params['tld']),
				array(
					'nameservers' => $ns_option
				)
			);
		}catch(DonDominioAPI_Error $e){
			CE_Lib::log(4, "DonDominio API Call Failed: " . $e->getMessage());
			throw new CE_Exception("DonDominio API Call Failed: " . $e->getMessage());
		}
	}
	
	/**
	 * Get all NameServers (GlueRecords) from a domain.
	 * @param array $params Parameters from CE
	 * @return string
	 * @throws CE_Exception on failure
	 */
	function checkNSStatus($params)
	{
		$dondominio = $this->_init($params);
		
		try{
			$gluerecords = $dondominio->domain_getGlueRecords(strtolower($params['sld'] . '.' . $params['tld']));
		}catch(DonDominioAPI_Error $e){
			CE_Lib::log(4, "DonDominio API Call Error: " . $e->getMessage());
			throw new CE_Exception("DonDominio API Call Error: " . $e->getMessage());
		}
		
		$glue_array = $gluerecords->get("gluerecords");
		
		$result = "";
		
		if(is_array($glue_array) && count($glue_array) > 0){
			foreach($glue_array as $record){
				$result .= $record['name'] . ' = ' . $record['ipv4'] . '<br />';
			}
		}
		
		return $result;
	}
	
	/**
	 * Create a NameServer (GlueRecord) from a domain.
	 * @param array $params Parameters from CE
	 * @return string
	 * @throws CE_Exception on failure
	 */
	function registerNS($params)
	{
		$dondominio = $this->_init($params);
		
		try{
			$gluerecord = $dondominio->domain_glueRecordCreate(
				strtolower($params['sld'] . '.' . $params['tld']),
				array(
					'name' => $params['nsname'],
					'ipv4' => $params['nsip']
				)
			);
		}catch(DonDominioAPI_Error $e){
			CE_Lib::log(4, "DonDominio API Call Error: " . $e->getMessage());
			throw new CE_Exception("DonDominio API Call Error: " . $e->getMessage());
		}
		
		return $this->user->lang("Name Server registered successfully.");
	}
	
	/**
	 * Update a NameServer (GlueRecord) from a domain.
	 * @param array $params Parameters from CE
	 * @return string
	 * @throws CE_Exception on failure
	 */
	function editNS($params)
	{
		$dondominio = $this->_init($params);
		
		try{
			$gluerecord = $dondominio->domain_glueRecordUpdate(
				strtolower($params['sld'] . '.' . $params['tld']),
				array(
					'name' => $params['nsname'],
					'ipv4' => $params['nsip']
				)
			);
		}catch(DonDominioAPI_Error $e){
			CE_Lib::log(4, "DonDominio API Call Error: " . $e->getMessage());
			throw new CE_Exception("DonDominio API Call Error: " . $e->getMessage());
		}
		
		return $this->user->lang("Name Server updated successfully.");
	}
	
	/**
	 * Delete a NameServer (GlueRecord) from a domain.
	 * @param array $params Parameters from CE
	 * @return string
	 * @throws CE_Exception on failure
	 */
	function deleteNS($params)
	{
		$dondominio = $this->_init($params);
		
		try{
			$gluerecord = $dondominio->domain_glueRecordDelete(
				strtolower($params['sld'] . '.' . $params['tld']),
				array(
					'name' => $params['nsname']
				)
			);
		}catch(DonDominioAPI_Error $e){
			CE_Lib::log(4, "DonDominio API Call Error: " . $e->getMessage());
			throw new CE_Exception("DonDominio API Call Error: " . $e->getMessage());
		}
		
		return $this->user->lang("Name Server deleted successfully.");
	}
	
	/**
	 * Get the flag for locked domains.
	 * @param array $params Parameters from CE
	 * @return boolean
	 * @throws CE_Exception on failure
	 */
	function getRegistrarLock($params)
	{
		$dondominio = $this->_init($params);
		
		try{
			$lock = $dondominio->domain_getInfo(
				strtolower($params['sld'] . '.' . $params['tld']),
				array(
					'infoType' => 'status'
				)
			);
		}catch(DonDominioAPI_Error $e){
			CE_Lib::log(4, "DonDominio API Call Error: " . $e->getMessage());
			throw new CE_Exception("DonDominio API Call Error: " . $e->getMessage());
		}
		
		return $lock->get("transferBlock");
	}
	
	/**
	 * Prepare domain lock status.
	 * @param array $params Parameters from CE
	 * @return string
	 */
	function doSetRegistrarLock($params)
	{
		$userPackage = new UserPackage($params['userPackageId']);
		
		$lock = $this->setRegistrarLock($this->buildLockParams($userPackage,$params));
        
        return "Updated Registrar Lock.";
	}
	
	/**
	 * Set domain lock status.
	 * @param array $params Parameters from CE
	 * @return boolean
	 * @throws CE_Exception on failure
	 */
	function setRegistrarLock($params)
	{
		$dondominio = $this->_init($params);
		
		try{
			$lock = $dondominio->domain_update(
				strtolower($params['sld'] . '.' . $params['tld']),
				array(
					'updateType' => 'transferBlock',
					'transferBlock' => ($params['lock']) ? true : false
				)
			);
		}catch(DonDominioAPI_Error $e){
			CE_Lib::log(4, "An error has occurred: " . $e->getMessage());
			throw new CE_Exception("An error has occurred: " . $e->getMessage());
		}
		
		return true;
	}
	
	/**
	 * Prepare to get AuthCode.
	 * @param array $params Parameters from CE
	 * @return string
	 */
	function doSendTransferKey($params)
	{
		$userPackage = new UserPackage($params['userPackageId']);
		
		$authcode = $this->sendTransferKey($this->buildRegisterParams($userPackage,$params));
		
		return $this->user->lang("Your authcode is " . $authcode);
	}
	
	/**
	 * Get the authcode for a domain.
	 * @param array $params Parameters from CE
	 * @return string
	 * @throws CE_Exception on failure
	 */
	function sendTransferKey($params)
	{
	   $dondominio = $this->_init($params);
	   
	   try{
	   	   $authcode = $dondominio->domain_getAuthCode(strtolower($params['sld'] . '.' . $params['tld']));
	   }catch(DonDominioAPI_Error $e){
	   	   CE_Lib::log(4, "DonDominio API Call Error: " . $e->getMessage());
	   	   throw new CE_Exception("DonDominio API Call Error: " . $e->getMessage());
	   }
	   
	   return $authcode->get("authcode");
	}
	
	/**
	 * Attempt to get DNS Records for a domain.
	 * This functionality is not supported by the DonDominio API at this moment.
	 * @param array $params Parameters from CE
	 * @throws CE_Exception always
	 */
	function getDNS($params)
	{
		throw new CE_Exception("Getting DNS Records is not supported by the DonDominio API.");
	}
	
	/**
	 * Attempt to set DNS Records for a domain.
	 * This functionality is not supported by the DonDominio API at this moment.
	 * @param array $params Parameters from CE
	 * @throws CE_Exception always
	 */
	function setDNS($params)
	{
		throw new CE_Exception("Setting DNS Records is not supported by the DonDominio API.");
	}
	
	/**
	 * Initialize the DonDominio API Client.
	 * @param array $params Parameters from CE
	 * @return DonDominioAPI
	 */
	private function _init($params)
	{
		$dondominio = new DonDominioAPI(
			array
			(
				'port' => 443,
				'apiuser' => $params['API Username'],
				'apipasswd' => $params['API Key'],
				'autoValidate' => true,
				'versionCheck' => true,
				'response' => array
				(
					'throwExceptions' => true
				)
			)
		);
		
		return $dondominio;
	}
	
	/**
	 * Generate contact array.
	 * @param array $data Contact data from API
	 * @param string $type Contact type
	 * @return array
	 */
	private function _toContactArray(array $data = array(), $type = 'Registrant')
	{
		$info[$type]['OrganizationName'] = array('Organization', $data['OrgName']);
		$info[$type]['JobTitle'] = array('Job Title', '');
		$info[$type]['FirstName'] = array('First Name', $data['firstName']);
		$info[$type]['LastName'] = array('Last Name', $data['lastName']);
		$info[$type]['Address1'] = array('Address 1', $data['address']);
		$info[$type]['Address2'] = array('Address 2', '');
		$info[$type]['City'] = array('City', $data['city']);
		$info[$type]['StateProvChoice'] = array('State or Province', '');
		$info[$type]['StateProv'] = array('Province / State', $data['state']);
		$info[$type]['Country'] = array('Country', $data['country']);
		$info[$type]['PostalCode'] = array('Postal Code', $data['postalCode']);
		$info[$type]['EmailAddress'] = array('E-mail', $data['email']);
		$info[$type]['Phone'] = array('Phone', $data['phone']);
		$info[$type]['PhoneExt'] = array('Phone Ext', '');
		$info[$type]['Fax'] = array('Fax', $data['fax']);
		$info[$type]['VATNumber'] = array('VAT Number', $data['identNumber']);
		
		return $info;
	}
	
	/**
	 * Convert a phone string to a DonDominio API valid phone.
	 * @param string $phone Phone to convert
	 * @param string $country Phone country - to add country code
	 * @return string
	 */
	private function _parsePhone($phone, $country)
	{
		// strip all non numerical values
        $phone = preg_replace('/[^\d]/', '', $phone);
        
        $query = "SELECT phone_code FROM country WHERE iso=? AND phone_code != ''";
        $result = $this->db->query($query, $country);
        if (!$row = $result->fetch()) {
            return $phone;
        }
        
        // check if code is already there
        $code = $row['phone_code'];
        $phone = preg_replace("/^($code)(\\d+)/", '+\1.\2', $phone);
        if (isset($phone[0]) && $phone[0] == '+') {
            return $phone;
        }
        
        // if not, prepend it
        return "+$code.$phone";
	}
	
	/**
	 * Get user information from database using an email.
	 * @param string $email User email
	 * @return array
	 */
	private function _getUserData($email)
	{
		$query = "
			SELECT
				CF.name, UCF.value
			FROM user_customuserfields UCF, customuserfields CF, users U
			WHERE
				UCF.customid = CF.id
				AND UCF.userid = U.id
				AND U.email = ?
		";
		
		$result = $this->db->query($query, $email);
		
		$data = array();
		
		while(list($name, $value) = $result->fetch()){
			$data[$name] = $value;
		}
		
		return $data;
	}
	
	/**
	 * Get additional fields from the database.
	 * @param integer $userPackageId Package ID
	 * @return array
	 */
	private function _getAdditionalFields($userPackageId)
	{
		//Getting some information we need from the DB
		$query = "
			SELECT
				CF.name, OCF.value
			FROM object_customField OCF
			LEFT JOIN customField CF ON OCF.customFieldId = CF.id
			WHERE
				OCF.objectid = ?
				AND CF.groupId = 1
		";
		
		$result = $this->db->query($query, $userPackageId);
		
		$additionalFields = array();
		
		while(list($CFName, $CFValue) = $result->fetch()){
			$additionalFields[$CFName] = $CFValue;
		}
		
		return $additionalFields;
	}
}