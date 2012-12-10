<?php
/**
 * An example class to bind to SharePoint server through ntlmaps
 *
 * @license		MIT license (as same as thybag's)
 * @author		Roland Haeder<roland mxchange org>
 */
class ExampleSharePointAPI extends SharePointAPI {
	/**
	 * Overwritten constructor
	 *
	 * @param User account to authenticate with. (Must have read/write/edit permissions to given Lists)
	 * @param Password to use with authenticating account.
	 * @param WSDL file for this set of lists  ( sharepoint.url/subsite/_vti_bin/Lists.asmx?WSDL )
	 * @param Whether to authenticate with NTLM
	 */
	public function __construct ($sp_user, $sp_pass, $sp_WSDL, $useNtlm = false) {
		// Set 1.2 version
		$this->soap_version = SOAP_1_2;

		// ntlmaps
		$this->proxyHost = 'localhost';
		$this->proxyPort = 5865;

		// Call parent constructor last (!)
		parent::__construct($sp_user, $sp_pass, $sp_WSDL, $useNtlm);
	}
}
