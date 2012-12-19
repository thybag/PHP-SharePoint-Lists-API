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
	 * @param string $spUsername User account to authenticate with. (Must have read/write/edit permissions to given Lists)
	 * @param string $spPassword Password to use with authenticating account.
	 * @param string $spWsdl WSDL file for this set of lists  ( sharepoint.url/subsite/_vti_bin/Lists.asmx?WSDL )
	 * @param boolean $useNtlm Whether to authenticate with NTLM
	 * @return void
	 */
	public function __construct ($spUsername, $spPassword, $spWsdl, $useNtlm = FALSE) {
		// Set 1.2 version
		$this->soap_version = SOAP_1_2;

		// ntlmaps
		$this->proxyHost = 'localhost';
		$this->proxyPort = 5865;

		/*
		 * Call parent constructor, but ignore username/password as the ntlmaps
		 * proxy doesn't require authentication data as it is IP-based.
		 */
		parent::__construct('', '', $spWsdl, $useNtlm);
	}
}
?>
