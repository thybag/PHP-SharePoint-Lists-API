<?php
/**
 * A child of SoapClient with support for ntlm proxy authentication. This class
 * can be run with ntlmaps.
 *
 * @author Meltir <meltir@meltir.com>
 * @see http://php.net/manual/en/soapclient.soapclient.php#97029
 */
class NTLM_SoapClient extends SoapClient {
	/**
	 * Overwritren constructor
	 *
	 * @param	string	$wsdl		WSDL location
	 * @param	array	$options	Options being handled over
	 * @return	void
	 */
	public function __construct ($wsdl, array $options = array()) {
		// Is proxy login (not for your SharePoint server!)
		if (isset($options['proxy_login'])) {
			// Login is set, so use it
			$this->proxy_login = $options['proxy_login'];

			// Is Password set?
			if (isset($options['proxy_password'])) {
				// Also, use it
				$this->proxy_password = $options['proxy_password'];
			}
		}

		// Set proxy host/port, defaults: localhost:8080
		$this->proxy_host = (empty($options['proxy_host']) ? 'localhost' : $options['proxy_host']);
		$this->proxy_port = (empty($options['proxy_port']) ? 8080 : $options['proxy_port']);

		// Call parent constructor
		parent::__construct($wsdl, $options);
	}

	/**
	 * Call a url using curl with ntlm auth
	 *
	 * @param	string	$url	URL of WSDL file
	 * @param	string	$data	HTTP/POST data
	 * @param	string	$action	SOAP action
	 * @param	string	$version	SOAP version
	 * @return	string	$response	Response string in success
	 * @throws	SoapFault if SOAP version is not 1.1 or 1.2
	 * @throws	SoapFault on curl connection error
	 */
	protected function callCurl ($url, $data, $action, $version) {
		// Initialize variable
		$contentType = '';

		// Detect version and choose correct content type
		switch ($version) {
			case SOAP_1_1:
				$contentType = 'text/xml; charset=utf-8';
				break;

			case SOAP_1_2:
				$contentType = 'application/soap+xml; charset=utf-8';
				break;

			default:
				// Throw exception
				throw new SoapFault('Could not detect SOAP version ' . $version, 0);
				break;
		}

		// Get CURL handle
		$handle = curl_init();

		// Other options (including URL)
		curl_setopt($handle, CURLOPT_HEADER        , FALSE);
		curl_setopt($handle, CURLOPT_URL           , $url);
		curl_setopt($handle, CURLOPT_FAILONERROR   , TRUE);
		curl_setopt($handle, CURLOPT_CRLF          , FALSE);
		curl_setopt($handle, CURLOPT_FOLLOWLOCATION, FALSE);
		curl_setopt($handle, CURLOPT_VERBOSE       , FALSE);
		curl_setopt($handle, CURLOPT_FRESH_CONNECT , TRUE);

		// HTTP headers
		curl_setopt($handle, CURLOPT_USERAGENT     , 'PHP SOAP-NTLM Client/1.0');
		curl_setopt($handle, CURLOPT_HTTPHEADER    , array(
			'SOAPAction: ' . trim($action),
			'Content-Type: ' . $contentType,
			'Expect:',
		));

		// Returns transfer as a string
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, TRUE);

		// Set POST data
		curl_setopt($handle, CURLOPT_POST          , TRUE);
		curl_setopt($handle, CURLOPT_POSTFIELDS    , $data);

		if ((!empty($this->proxy_host)) && (!empty($this->proxy_port))) {
			// Set proxy hostname:port
			curl_setopt($handle, CURLOPT_PROXY, $this->proxy_host . ':' . $this->proxy_port);
			curl_setopt($handle, CURLOPT_PROXYAUTH   , CURLAUTH_NTLM);

			// Proxy auth enabled?
			if (!empty($this->proxy_login)) {
				curl_setopt($handle, CURLOPT_PROXYUSERPWD, $this->proxy_login . ':' . $this->proxy_password);
				curl_setopt($handle, CURLOPT_PROXYAUTH   , CURLAUTH_NTLM);
			}
		}

		// Execute the request
		$response = curl_exec($handle);

		// Is the response empty?
		if ($response === FALSE) {
			// Throw exception
			throw new SoapFault('CURL error: ' . curl_error($handle), curl_errno($handle));
		}

		// Free some resources
		curl_close($handle);

		// Return response
		return $response;
	}

	/**
	 * Overwritten method in SoapClient::__doRequest to use CURL now
	 *
	 * Notice: $one_way is unsupported
	 */
	public function __doRequest ($request, $location, $action, $version, $one_way = 0) {
		//* DEBUG: */ print 'action=' . $action . ',version=' . $version . ',one_way[' . gettype($one_way) . ']=' . intval($one_way) . PHP_EOL;
		return $this->callCurl($location, $request, $action, $version);
	}
}
?>
