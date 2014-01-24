<?php
namespace Thybag\Auth;

/**
 * SharePointOnlineAuth
 * Clone of the PHP SoapClient class, modified in order to allow transparent communication with
 * the SharePoint Online Web services.
 *
 * @package Thybag\Auth
 */
class SharePointOnlineAuth extends \SoapClient {

	// Authentication cookies
	private $authCookies = false;

	// Override do request method
	public function __doRequest($request, $location, $action, $version, $one_way = false) {

		// Authenticate with SP online in order to get required authentication cookies
		if (!$this->authCookies) $this->configureAuthCookies($location);

		// Set base headers
		$headers = array();
		$headers[] = "Content-Type: text/xml;";

		$curl = curl_init($location);

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_POST, TRUE);

		// Send request and auth cookies.
		curl_setopt($curl, CURLOPT_POSTFIELDS, $request);
		curl_setopt($curl, CURLOPT_COOKIE, $this->authCookies);

 		// Connection requires CURLOPT_SSLVERSION set to 3
		curl_setopt($curl, CURLOPT_TIMEOUT, 10);
		curl_setopt($curl, CURLOPT_SSLVERSION, 3);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);

		// Useful for debugging
		curl_setopt($curl, CURLOPT_VERBOSE,FALSE);
		curl_setopt($curl, CURLOPT_HEADER, FALSE);

		// SharePoint Online requires the SOAPAction header set for ADD/EDIT and DELETE Operations.
		// Failure to have this will result in a "Security Validation exception"
		// @see http://weblogs.asp.net/jan/archive/2009/05/25/quot-the-security-validation-for-this-page-is-invalid-quot-when-calling-the-sharepoint-web-services.aspx
		if( strpos($request, 'UpdateListItems') !== FALSE ) {
		  $headers[] =	'SOAPAction: "http://schemas.microsoft.com/sharepoint/soap/UpdateListItems"';
		}

		// Add headers
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

		// Init the cURL
		$response = curl_exec($curl);

		// Throw exceptions if there are any issues
		if (curl_errno($curl)) throw new \SoapFault(curl_errno($curl), curl_error($curl));
		if ($response == '') throw new \SoapFault("No XML returned", "No XML returned");

		// Close CURL
		curl_close($curl);

		// Return?
		if (!$one_way) return ($response);
	}

	/**
	 * ConfigureAuthCookies
	 * Authenticate with sharepoint online in order to get valid authentication cookies
	 *
	 * @param $location - Url of sharepoint list
	 *
	 * More info on method:
	 * @see http://allthatjs.com/2012/03/28/remote-authentication-in-sharepoint-online/
	 * @see http://macfoo.wordpress.com/2012/06/23/how-to-log-into-office365-or-sharepoint-online-using-php/
	 */
	protected function configureAuthCookies($location) {

		// Get endpoint "https://somthing.sharepoint.com"
		$location = parse_url($location);
		$endpoint = 'https://'.$location['host'];

		// get username & password
		$login = $this->{'_login'};
		$password = $this->{'_password'};

		// Create XML security token request
		$xml = $this->generateSecurityToken($login, $password, $endpoint);

		// Send request and grab returned xml
		$result = $this->authCurl("https://login.microsoftonline.com/extSTS.srf", $xml);

		// Extract security token from XML
		$xml = new \DOMDocument();
		$xml->loadXML($result);
		$xpath = new \DOMXPath($xml);
		$nodelist = $xpath->query("//wsse:BinarySecurityToken");
		foreach ($nodelist as $n){
			$token = $n->nodeValue;
			break;
		}

		// Send token to SharePoint online in order to gain authentication cookies
		$result = $this->authCurl($endpoint."/_forms/default.aspx?wa=wsignin1.0", $token, true);

		// Extract Authentication cookies from response & set them in to AuthCookies var
		$this->authCookies = $this->extractAuthCookies($result);
	}

	/**
	 * extractAuthCookies
	 * Extract Authentication cookies from SP response & format in to usable cookie string
	 *
	 * @param $result cURL Response
	 * @return $cookie_payload string containing cookie data.
	 */
	protected function extractAuthCookies($result){

		$authCookies = array();
		$cookie_payload = '';

		$header_array = explode("\r\n", $result);

		// Get the two auth cookies
		foreach($header_array as $header) {
			$loop = explode(":",$header);
			if($loop[0] == 'Set-Cookie') {
				$authCookies[] = $loop[1];
			}
		}
		unset($authCookies[0]); // No need for first cookie

		// Extract cookie name & payload and format in to cURL compatible string
		foreach($authCookies as $payload){
			$e = strpos($payload, "=");
			// Get name
			$name = substr($payload, 0, $e);
			// Get token
			$content = substr($payload, $e+1);
			$content = substr($content, 0, strpos($content, ";"));

			// If not first cookie, add cookie seperator
			if($cookie_payload !== '') $cookie_payload .= '; ';

			// Add cookie to string
			$cookie_payload .= $name.'='.$content;
		}

	  	return $cookie_payload;
	}

	/**
	 * authCurl
	 * helper method used to cURL SharePoint Online authentiction webservices
	 *
	 * @param $url URL to cURL
	 * @param $payload value to post to URL
	 * @param $header true|false - Include headers in response
	 * @return $raw Data returned from cURL.
	 */
	protected function authCurl($url, $payload, $header = false){
		$ch = curl_init();
		curl_setopt($ch,CURLOPT_URL,$url);
		curl_setopt($ch,CURLOPT_POST,1);
		curl_setopt($ch,CURLOPT_POSTFIELDS,  $payload);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

	  	curl_setopt($ch, CURLOPT_SSLVERSION, 3);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);

		if($header)  curl_setopt($ch, CURLOPT_HEADER, true);

		$result = curl_exec($ch);

		// catch error
		if($result === false) {
			throw new \SoapFault(curl_errno($ch), 'Curl error: ' . curl_error($ch));
		}

		curl_close($ch);

		return $result;
	}

	/**
	 * Get the XML to request the security token
	 *
	 * @param string $username
	 * @param string $password
	 * @param string $endpoint
	 * @return type string
	 */
	protected function generateSecurityToken($username, $password, $endpoint) {
	return <<<TOKEN
    <s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"
      xmlns:a="http://www.w3.org/2005/08/addressing"
      xmlns:u="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">
  <s:Header>
    <a:Action s:mustUnderstand="1">http://schemas.xmlsoap.org/ws/2005/02/trust/RST/Issue</a:Action>
    <a:ReplyTo>
      <a:Address>http://www.w3.org/2005/08/addressing/anonymous</a:Address>
    </a:ReplyTo>
    <a:To s:mustUnderstand="1">https://login.microsoftonline.com/extSTS.srf</a:To>
    <o:Security s:mustUnderstand="1"
       xmlns:o="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
      <o:UsernameToken>
        <o:Username>$username</o:Username>
        <o:Password>$password</o:Password>
      </o:UsernameToken>
    </o:Security>
  </s:Header>
  <s:Body>
    <t:RequestSecurityToken xmlns:t="http://schemas.xmlsoap.org/ws/2005/02/trust">
      <wsp:AppliesTo xmlns:wsp="http://schemas.xmlsoap.org/ws/2004/09/policy">
        <a:EndpointReference>
          <a:Address>$endpoint</a:Address>
        </a:EndpointReference>
      </wsp:AppliesTo>
      <t:KeyType>http://schemas.xmlsoap.org/ws/2005/05/identity/NoProofKey</t:KeyType>
      <t:RequestType>http://schemas.xmlsoap.org/ws/2005/02/trust/Issue</t:RequestType>
      <t:TokenType>urn:oasis:names:tc:SAML:1.0:assertion</t:TokenType>
    </t:RequestSecurityToken>
  </s:Body>
</s:Envelope>
TOKEN;
	}
}