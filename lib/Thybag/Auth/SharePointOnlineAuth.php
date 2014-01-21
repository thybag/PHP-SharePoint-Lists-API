<?php
namespace Thybag\Auth;

class SharePointOnlineAuth extends \SoapClient {

	private $authConfigured = false;

	public function __doRequest($request, $location, $action, $version, $one_way = false) {
		// Auth with SP online
		if(!$this->authConfigured) $this->configureAuthCookies($location);

		$cookie_string = '';
		foreach($this->{'_cookies'} as $name => $c){
			$cookie_string .= trim($name).'='.$c[0].'; ';
		}
		$cookie_string = substr($cookie_string, 0, -2);
		
		$headers = array();
		$headers[] = "Content-Type: text/xml;";

        $curl = curl_init($location);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_POST, TRUE);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $request);
        curl_setopt($curl, CURLOPT_COOKIE, $cookie_string); 
     
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSLVERSION, 3);
        curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

		curl_setopt($curl, CURLOPT_VERBOSE,FALSE);
        curl_setopt($curl, CURLOPT_HEADER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);

        // bit of a hack for now
        if(strpos($request, 'UpdateListItems') !== FALSE){
    		$headers[] =	'SOAPAction: "http://schemas.microsoft.com/sharepoint/soap/UpdateListItems"';
        }

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($curl);
        
        if (curl_errno($curl))
        {
            throw new \Exception(curl_error($curl));
        }

        if($response == ''){
        	 throw new \Exception("No XML returned");
        }

        curl_close($curl);
        
        // Return?
        if (!$one_way)
        {
            return ($response);
        }
	}

	protected function configureAuthCookies($location){

		// Get endpoint
		$location = parse_url($location);
		$endpoint = 'https://'.$location['host'];

		// get auth
		$login = $this->{'_login'};
		$password = $this->{'_password'};

		// send token request
		$xml = $this->getSecurityTokenXml($login, $password, $endpoint);

		$result = $this->authCurl("https://login.microsoftonline.com/extSTS.srf", $xml);

		// Extract security token
		$xml = new \DOMDocument();
	    $xml->loadXML($result);
	    $xpath = new \DOMXPath($xml);
	    $nodelist = $xpath->query("//wsse:BinarySecurityToken");
	    foreach ($nodelist as $n){
	        $token = $n->nodeValue;
	        break;
	    }

	    // Get access cookies
	    $result = $this->authCurl($endpoint."/_forms/default.aspx?wa=wsignin1.0", $token, true);

	    // Extract cookies
	    $authCookies = array();
	    $header_array = explode("\r\n", $result);


	    foreach($header_array as $header) {
	        $loop = explode(":",$header);
	        if($loop[0] == 'Set-Cookie') {
	            $authCookies[] = $loop[1];
	        }
	    }
	    unset($authCookies[0]); // No need for first cookie

	  
	    // Attach cookies
	    foreach(array_values($authCookies) as $payload){

	    	$e = strpos($payload, "=");
	    	// Get name
	    	$name = substr($payload, 0, $e);
	    	// Get token
	    	$content = substr($payload, $e+1);
	    	$content = substr($content, 0, strpos($content, ";"));

	    	static::__setCookie($name, $content);
	    }

	    $this->authConfigured = true;
	}
	protected function authCurl($url, $payload, $header = false){
		$ch = curl_init();
	    curl_setopt($ch,CURLOPT_URL,$url);
	    curl_setopt($ch,CURLOPT_POST,1);
	    curl_setopt($ch,CURLOPT_POSTFIELDS,  $payload);   
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	  	if($header)  curl_setopt($ch, CURLOPT_HEADER, true); 
	  	curl_setopt($ch, CURLOPT_SSLVERSION, 3);
	    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
	    curl_setopt($ch, CURLOPT_USERAGENT,'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Win64; x64; Trident/5.0');

	    $result = curl_exec($ch);

	    // catch error
	    if($result === false) {
	        throw new \Exception('Curl error: ' . curl_error($ch));
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
protected function getSecurityTokenXml($username, $password, $endpoint) {
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