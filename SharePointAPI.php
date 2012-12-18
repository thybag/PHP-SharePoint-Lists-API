<?php
/**
 * SharepointAPI
 *
 * Simple PHP API for reading/writing and modifying SharePoint list items.
 * 
 * @author Carl Saggs
 * @version 2012.09.02
 * @licence MIT License
 * @source: http://github.com/thybag/PHP-SharePoint-Lists-API
 *
 * Tested against the sharepoint 2007 API
 *
 * WSDL file needed will be located at: sharepoint.url/subsite/_vti_bin/Lists.asmx?WSDL
 *
 * Usage:
 * $sp = new SharePointAPI('<username>','<password>','<path_to_WSDL>');
 *
 * Read:
 * $sp->read('<list_name>');
 * $sp->read('<list_name>', 500); // Return 500 records
 * $sp->read('<list_name>', NULL, array('<col_name>'=>'<col_value>'); //  Filter on col_name = col_value
 * $sp->read('<list_name>', NULL, NULL, '{FAKE-GUID00-0000-000}'); 	// Return list items with view (specified via GUID)
 * $sp->read('<list_name>', NULL, NULL, NULL, array('col_name'=>'asc|desc'));
 *
 * Query:
 * $sp->query('<list_name>')->where('type','=','dog')->and_where('age','>','5')->limit(10)->sort('age','asc')->get();
 *
 * Write: (insert)
 * $sp->write('<list_name>', array('<col_name>' => '<col_value>','<col_name_2>' => '<col_value_2>'));
 *
 * WriteMultiple:
 * $sp->writeMultiple('<list_name>', array(
 * 					array('Title' => 'New item'),
 *					array('Title' => 'New item 2')
 * 				));
 *
 * Update:
 * $sp->update('<list_name>','<row_id>', array('<col_name>'=>'<new_data>'));
 *
 * UpdateMultiple:
 * $sp->updateMultiple('<list_name>', array(
 * 					array('ID'=>1, 'Title' => 'new name'),
 *					array('ID'=>2, 'Title' => 'New name 2')
 * 				));
 *
 * Delete:
 * $sp->delete('<list_name>','<row_id>');
 *
 * CRUD can be used for multiple actions on a single list.
 * $list = $api->CRUD('<list_name>');
 * $list->read(10);
 * $list->create(array('<col_name>' => '<col_value>','<col_name_2>' => '<col_value_2>'));
 */
class SharePointAPI {
	/**
	 * Username for SP auth
	 */
	private $spUsername = '';

	/**
	 * Password for SP auth
	 */
	private $spPassword = '';

	/**
	 * Location of WSDL
	 * @FIXME Cannot be an URL (http://foo/bar/Lists.asmx?WSDL) if NTLM auth is being used
	 */
	private $spWsdl = '';

	/**
	 * Return type (default: 0)
	 *
	 * 0 = Array
	 * 1 = Object
	 */
	private $returnType = 0;

	/**
	 * Make all indexs lower-case
	 */
	private $lower_case_indexs = TRUE;

	/**
	 * Maximum rows to return from a List 
	 */
	private $MAX_ROWS = 10000;

	/**
	 * Place holder for soapClient/SOAP client
	 */
	private $soapClient = NULL;

	/**
	 * Whether requests shall be traced
	 * (compare: http://de.php.net/manual/en/soapclient.soapclient.php )
	 */
	protected $soap_trace = TRUE;

	/**
	 * Whether SOAP errors throw exception of type SoapFault
	 */
	protected $soap_exceptions = TRUE;

	/**
	 * Kee-Alive HTTP setting (default: FALSE)
	 */
	protected $soap_keep_alive = FALSE;

	/**
	 * SOAP version number (default: SOAP_1_1)
	 */
	protected $soap_version = SOAP_1_1;

	/**
	 * Compression
	 * Example: SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP
	 */
	protected $soap_compression = 0;

	/**
	 * Cache behaviour for WSDL content (default: WSDL_CACHE_NONE for better debugging)
	 */
	protected $soap_cache_wsdl = WSDL_CACHE_NONE;

	/**
	 * Internal (!) encoding (not SOAP; default: UTF-8)
	 */
	protected $internal_encoding = 'UTF-8';

	/**
	 * Proxy login (default: EMPTY
	 */
	protected $proxyLogin = '';

	/**
	 * Proxy password (default: EMPTY)
	 */
	protected $proxyPassword = '';

	/**
	 * Proxy hostname (default: EMPTY)
	 */
	protected $proxyHost = '';

	/**
	 * Proxy port (default: 8080)
	 */
	protected $proxyPort = 8080;

	/**
	 * Constructor
	 *
	 * @param string $spUsername User account to authenticate with. (Must have read/write/edit permissions to given Lists)
	 * @param string $spPassword Password to use with authenticating account.
	 * @param string $spWsdl WSDL file for this set of lists  ( sharepoint.url/subsite/_vti_bin/Lists.asmx?WSDL )
	 * @param Whether to authenticate with NTLM
	 */
	public function __construct ($spUsername, $spPassword, $spWsdl, $useNtlm = FALSE) {
		// Check if required class is found
		assert(class_exists('SoapClient'));

		// Set data from parameters in this class
		$this->spUsername = $spUsername;
		$this->spPassword = $spPassword;
		$this->spWsdl     = $spWsdl;

		/*
		 * General options
		 * NOTE: You can set all these parameters, see class ExampleSharePointAPI for an example)
		 */
		$options = array(
			'trace'        => $this->soap_trace,
			'exceptions'   => $this->soap_exceptions,
			'keep_alive'   => $this->soap_keep_alive,
			'soap_version' => $this->soap_version,
			'cache_wsdl'   => $this->soap_cache_wsdl,
			'compression'  => $this->soap_compression,
			'encoding'     => $this->internal_encoding,
		);

		// Auto-detect http(s):// URLs
		if ((substr($this->spWsdl, 0, 7) == 'http://') || (substr($this->spWsdl, 0, 8) == 'https://')) {
			// Add location,uri options and set wsdl=NULL
			// @TODO Is location/uri the same???
			$options['location'] = $this->spWsdl;
			$options['uri']      = $this->spWsdl;
			$this->spWsdl = NULL;
		}

		// Is login set?
		if (!empty($this->spUsername)) {
			// Then set login data
			$options['login']    = $this->spUsername;
			$options['password'] = $this->spPassword;
		}

		// Create new SOAP Client
		try {
			// NTLM authentication or regular SOAP client?
			if ($useNtlm === TRUE) {
				// Load include once
				require_once 'NTLM_SoapClient.php';

				// Use NTLM authentication client
				$this->soapClient = new NTLM_SoapClient($this->spWsdl, array_merge($options, array(
					'proxy_login'    => $this->proxyLogin,
					'proxy_password' => $this->proxyPassword,
					'proxy_host'     => $this->proxyHost,
					'proxy_port'     => $this->proxyPort
				)));
			} else {
				// Use regular client (for basic/digest auth)
				$this->soapClient = new SoapClient($this->spWsdl, $options);
			}
		} catch (SoapFault $fault) {
			// If we are unable to create a Soap Client display a Fatal error.
			throw new Exception('Unable to locate WSDL file. faultcode=' . $fault->getCode() . ',faultstring=' . $fault->getMessage());
		}
	}

	/**
	 * Calls methods on SOAP object
	 *
	 * @param	string	$methodName		Name of method to call
	 * @param	array	$methodParams	Parameters to handle over
	 * @return	mixed	$returned		Returned values
	 */
	public final function __call ($methodName, array $methodParams) {
		/*
		 * Is soapClient set? This check may look double here but in later
		 * developments it might help to trace bugs better and it avoids calls
		 * on wrong classes if $soapClient got set to something not SoapClient.
		 */
		if (!$this->soapClient instanceof SoapClient) {
			// Is not set
			throw new Exception('Variable soapClient is not a SoapClient class, have: ' . gettype($this->soapClient), 0xFF);
		}

		// Is it a "SOAP callback"?
		if (substr($methodName, 0, 2) == '__') {
			// Is SoapClient's method
			$returned = call_user_func_array(array($this->soapClient, $methodName), $methodParams);
		} else {
			// Call it
			$returned = $this->soapClient->__call($methodName, $methodParams);
		}

		// Return any values
		return $returned;
	}

	/**
	 * Returns an array of all lists
	 *
	 * @param	array	$keys		Keys which shall be included in final JSON output
	 * @param	array	$params		Only search for lists with given criteria (default: 'hidden' => 'False')
	 * @param	bool	$isSensetive	Whether to look case-sensetive (default: TRUE)
	 * @return	array	$newLists	An array with given keys from all lists
	 */
	public function getLimitedLists (array $keys, array $params = array('hidden' => 'False'), $isSensetive = TRUE) {
		// Get the full list back
		$lists = $this->getLists();

		// Init new list and look for all matching entries
		$newLists = array();
		foreach ($lists as $entry) {
			// Default is found
			$isFound = TRUE;

			// Search for all criteria
			foreach ($params as $key => $value) {
				// Is it found?
				if ((isset($entry[$key])) && ((($isSensetive === TRUE) && ($value != $entry[$key])) || (strtolower($value) != strtolower($entry[$key])))) {
					// Is not found
					$isFound = FALSE;
					break;
				}
			}

			// Add it?
			if ($isFound === TRUE) {
				// Generate new entry array
				$newEntry = array();
				foreach ($keys as $key) {
					// Add this key
					$newEntry[$key] = $entry[$key];
				}

				// Add this new array
				$newLists[] = $newEntry;
				unset($newEntry);
			}
		}

		// Return finished array
		return $newLists;
	}

	/**
	 * Get Lists
	 * Return an array containing all avaiable lists within a given sharepoint subsite.
	 * Use "set return type" if you wish for this data to be provided as an object.
	 *
	 * @return array (array) | array (object)
	 */
	public function getLists () {
		// Query Sharepoint for full listing of it's lists.
		$rawXml = '';
		try {
			$rawXml = $this->soapClient->GetListCollection()->GetListCollectionResult->any;
		} catch (SoapFault $fault) {
			$this->onError($fault);
		}

		// Load XML in to DOM document and grab all list items.
		$nodes = $this->getArrayFromElementsByTagName($rawXml, 'List');

		// Format data in to array or object
		foreach ($nodes as $counter => $node) {
			foreach ($node->attributes as $attribute => $value) {
				$idx = ($this->lower_case_indexs) ? strtolower($attribute) : $attribute;
				$results[$counter][$idx] = $node->getAttribute($attribute);
			}

			// Make object if needed
			if ($this->returnType === 1) {
				settype($results[$counter], 'object');
			}
		}

		// Add error array if stuff goes wrong.
		if (!isset($results)) {
			$results = array('warning' => 'No data returned.');
		}

		return $results;
	}

	/**
	* Read List MetaData (Column configurtion)
	* Return a full listing of columns and their configurtion options for a given sharepoint list.
	*
	* @param $list_name Name or GUID of list to return metaData from.
	* @param $hideInternal TRUE|FALSE Attempt to hide none useful columns (internal data etc)
	* @param $ignoreHiddenAttribute TRUE|flase Ignores 'Hidden' attribute if it is set to 'TRUE' - DEBUG ONLY!!!
	* @return Array
	*/
	public function readListMeta ($list_name, $hideInternal = TRUE, $ignoreHiddenAttribute = FALSE) {
		// Ready XML
		$CAML = '
			<GetList xmlns="http://schemas.microsoft.com/sharepoint/soap/">
				<listName>' . $list_name . '</listName> 
			</GetList>
		';

		// Attempt to query Sharepoint
		$rawXml = '';
		try {
			$rawXml = $this->soapClient->GetList(new SoapVar($CAML, XSD_ANYXML))->GetListResult->any;
		} catch (SoapFault $fault) {
			$this->onError($fault);
		}

		// Load XML in to DOM document and grab all Fields
		$nodes = $this->getArrayFromElementsByTagName($rawXml, 'Field');

		// Format data in to array or object
		foreach ($nodes as $counter => $node) {
			// Empty inner_xml
			$inner_xml = '';

			// Attempt to hide none useful feilds (disable by setting second param to FALSE)
			if ($hideInternal && ($node->getAttribute('Type') == 'Lookup' || $node->getAttribute('Type') == 'Computed' || ($node->getAttribute('Hidden') == 'TRUE' && $ignoreHiddenAttribute === FALSE))) {
				continue;
			}

			// Get Attributes
			foreach ($node->attributes as $attribute => $value) {
				$idx = ($this->lower_case_indexs) ? strtolower($attribute) : $attribute;
				$results[$counter][$idx] = $node->getAttribute($attribute);
			}

			// Get contents (Raw xml)
			foreach ($node->childNodes as $childNode) {
				$inner_xml .= $node->ownerDocument->saveXml($childNode);
			}
			$results[$counter]['value'] = $inner_xml;

			// Make object if needed
			if ($this->returnType === 1) {
				settype($results[$counter], 'object');
			}
		}

		//  Add error array if stuff goes wrong.
		if (!isset($results)) {
			$results = array('warning' => 'No data returned.');
		}

		// Return a XML as nice clean Array or Object
		return $results;
	}

	/**
	 * Read
	 * Use's raw CAML to query sharepoint data
	 *
	 * @param String $list_name
	 * @param int $limit
	 * @param Array $query
	 * @param String (GUID) $view "View to display results with."
	 * @param Array $sort
	 *
	 * @return Array
	 */
	public function read ($list_name, $limit = NULL, $query = NULL, $view = NULL, $sort = NULL) {
		// Check limit is set
		if ($limit < 1 || is_null($limit)) {
			$limit = $this->MAX_ROWS;
		}

		// Create Query XML is query is being used
		$xml_options = '';
		$xml_query   = '';

		// Setup Options
		if ($query instanceof SPQueryObj) {
			$xml_query = $query->getCAML();
		} else {
			if (!is_null($view)) {
				$xml_options .= '<viewName>' . $view . '</viewName>';
			}
			if (!is_null($query)) {
				$xml_query .= $this->whereXML($query); // Build Query
			}
			if (!is_null($sort)) {
				$xml_query .= $this->sortXML($sort);
			}
		}

		// If query is required
		if (!empty($xml_query)) {
			$xml_options .= '<query><Query>' . $xml_query . '</Query></query>';
		}

		/*
		 * Setup basic XML for quering a sharepoint list.
		 * If rowLimit is not provided sharepoint will defualt to a limit of 100 items.
		 */
		$CAML = '
			<GetListItems xmlns="http://schemas.microsoft.com/sharepoint/soap/">
				<listName>' . $list_name . '</listName>
				<rowLimit>' . $limit . '</rowLimit>
				' . $xml_options . '
				<queryOptions xmlns:SOAPSDK9="http://schemas.microsoft.com/sharepoint/soap/" >
					<QueryOptions/>
				</queryOptions>
			</GetListItems>';

		// Ready XML
		$xmlvar = new SoapVar($CAML, XSD_ANYXML);
		$result = NULL;

		// Attempt to query Sharepoint
		try {
			$result = $this->xmlHandler($this->soapClient->GetListItems($xmlvar)->GetListItemsResult->any);
		} catch (SoapFault $fault) {
			$this->onError($fault);
		}

		// Return a XML as nice clean Array
		return $result;
	}

	/**
	 * Write
	 * Create new item in a sharepoint list
	 *
	 * @param String $list_name Name of List
	 * @param Array $data Assosative array describing data to store
	 * @return Array
	 */
	public function write ($list_name, array $data) {
		return $this->writeMultiple($list_name, array($data));
	}

	// Alias (Identical to above)
	public function add ($list_name, array $data) { return $this->write($list_name, $data); }
	public function insert ($list_name, array $data) { return $this->write($list_name, $data); }

	/**
	 * WriteMultiple
	 * Batch create new items in a sharepoint list
	 *
	 * @param String $list_name Name of List
	 * @param Array of arrays Assosative array's describing data to store
	 * @return Array
	 */
	public function writeMultiple ($list_name, array $items) {
		return $this->modifyList($list_name, $items, 'New');
	}

	// Alias (Identical to above)
	public function addMultiple ($list_name, array $items) { return $this->writeMultiple($list_name, $items); }
	public function insertMultiple ($list_name, array $items) { return $this->writeMultiple($list_name, $items); }

	/**
	 * Update
	 * Update/Modifiy an existing list item.
	 *
	 * @param String $list_name Name of list
	 * @param int $ID ID of item to update
	 * @param Array $data Assosative array of data to change.
	 * @return Array
	 */
	public function update ($list_name, $ID, array $data) {
		// Add ID to item
		$data['ID'] = $ID;
		return $this->updateMultiple($list_name, array($data));
	}

	/**
	 * UpdateMultiple
	 * Batch Update/Modifiy existing list item's.
	 *
	 * @param String $list_name Name of list
	 * @param Array of arrays of assosative array of data to change. Each item MUST include an ID field.
	 * @return Array
	 */
	public function updateMultiple ($list_name, array $items) {
		return $this->modifyList($list_name, $items, 'Update');
	}

	/**
	 * Delete
	 * Delete an existing list item.
	 *
	 * @param String $list_name Name of list
	 * @param int $ID ID of item to delete
	 * @return Array
	 */
	public function delete ($list_name, $ID) {
		return $this->deleteMultiple($list_name, array($ID));
	}

	/**
	 * DeleteMUlti
	 * Delete existing multiple list items.
	 *
	 * @param String $list_name Name of list
	 * @param array $IDs IDs of items to delete
	 * @return Array
	 */
	public function deleteMultiple ($list_name, array $IDs) {
		//change input "array(ID1,ID2,ID3)"" to "array(array('id'=>ID1),array('id'=>ID2),array('id'=>ID3))"
		//In order to be compatable with modifyList
		$ID_list = array();
		foreach($IDs as $ID)$ID_list[] = array('ID'=>$ID);

		// Return a XML as nice clean Array
		return $this->modifyList($list_name, $ID_list, 'Delete');
	}

	/**
	 * setReturnType
	 * Change the dataType used to store List items.
	 * Array or Object.
	 *
	 * @param $type
	 */
	public function setReturnType ($type) {
		if (trim(strtolower($type)) == 'object') {
			$this->returnType = 1;
		} else {
			$this->returnType = 0;
		}
	}

	/**
	 * lowercaseIndexs
	 * Enable or disable automically lowercasing indexs for returned data.
	 * (By defualt this is enabled to avoid users having to worry about the case attributers are in)
	 * Array or Object.
	 *
	 * @param $enable TRUE|FALSE
	 */
	public function lowercaseIndexs ($enable) {
		$this->lower_case_indexs = ($enable === TRUE);
	}

	/**
	 * Query
	 * Create a query against a list in sharepoint
	 *
	 * Build querys as $sp->query('my_list')->where('score','>',15)->and_where('year','=','9')->get();
	 * 
	 * @param List name / GUID number
	 * @return SP List Item
	 */
	public function query ($table) {
		return new SPQueryObj($table, $this);
	}

	/**
	 * CRUD
	 * Create a simple Create, Read, Update, Delete Wrapper around a specific list.
	 *
	 * @param $list_name Name of list to provide CRUD for.
	 * @return ListCRUD Object
	 */
	public function CRUD ($list_name) {
		return new ListCRUD($list_name, $this);
	}

	/**
	 * "Getter" for an array of nodes from given "raw XML" and tag name
	 *
	 * @param	string	$rawXml		"Raw XML" data
	 * @param	string	$tag		Name of tag
	 * @param	string	$namespace	Optional namespace
	 * @return	array	$nodes		An array of XML nodes
	 */
	private function getArrayFromElementsByTagName ($rawXml, $tag, $namespace = NULL) {
		// Get DOM instance and load XML
		$dom = new DOMDocument();
		$dom->loadXML($rawXml);

		// Is namespace set?
		if (!is_null($namespace)) {
			// Use it
			$nodes = $dom->getElementsByTagNameNS($tag, $namespace);
		} else {
			// Get nodes
			$nodes = $dom->getElementsByTagName($tag);
		}

		// Return nodes list
		return $nodes;
	}

	/**
	 * xmlHandler
	 * Transform the XML returned from SOAP in to a useful datastructure.
	 * By Defualt all sharepoint items will be represented as arrays.
	 * Use setReturnType('object') to have them returned as objects.
	 *
	 * @param $rawXml XML DATA returned by SOAP
	 * @return Array( Array ) | Array( Object )
	 */
	private function xmlHandler ($rawXml) {
		// Use DOMDocument to proccess XML
		$results = $this->getArrayFromElementsByTagName($rawXml, '#RowsetSchema', '*');
		$resultArray = array();

		// Proccess Object and return a nice clean assoaitive array of the results
		foreach ($results as $i => $result) {
			$resultArray[$i] = array();
			foreach ($result->attributes as $attribute => $value) {
				$idx = ($this->lower_case_indexs) ? strtolower($attribute) : $attribute;
				//  Re-assign all the attributes into an easy to access array
				$resultArray[$i][str_replace('ows_', '', $idx)] = $result->getAttribute($attribute);
			}

			/*
			 * ReturnType 1 = Object.
			 * If set, change array in to an object.
			 *
			 * Feature based on implementation by dcarbone  (See: https://github.com/dcarbone/ )
			 */
			if ($this->returnType === 1) {
				settype($resultArray[$i], 'object');
			}
		}

		// Check some values were actually returned
		if (count($resultArray) == 0) {
			$resultArray = array(
				'warning' => 'No data returned.',
				'raw_xml' => $rawXml
			);
		}

		return $resultArray;
	}

	/**
	 * Query XML
	 * Generates XML for WHERE Query
	 *
	 * @param Array $q array('<col>' => '<value_to_match_on>')
	 * @return XML DATA
	 */
	private function whereXML (array $q) {
		$queryString = '';
		$counter = 0;

		foreach ($q as $col => $value) {
			$counter++;
			$queryString .= '<Eq><FieldRef Name="' . $col . '" /><Value Type="Text">' . htmlspecialchars($value) . '</Value></Eq>';

			// Add additional "and"s if there are multiple query levels needed.
			if ($counter >= 2) {
				$queryString = '<And>' . $queryString . '</And>';
			}
		}

		return '<Where>' . $queryString . '</Where>';
	}

	/**
	 * "Getter" for sort ascending (TRUE) or descending (FALSE) from given value
	 *
	 * @param	string	$value	Value to be checked
	 * @return	string	$sort	"TRUE" for ascending, "false" (default) for descending
	 */
	public function getSortFromValue ($value) {
		// Make all lower-case
		$value = strtolower($value);

		// Default is descending
		$sort = 'false';

		// Is value set to allow ascending sorting?
		if ($value == 'asc' || $value == 'true' || $value == 'ascending') {
			// Sort ascending
			$sort = 'true';
		}

		// Return it
		return $sort;
	}

	/**
	 * Sort XML
	 * Generates XML for sort
	 *
	 * @param Array $sort array('<col>' => 'asc | desc')
	 * @return XML DATA
	 */
	private function sortXML (array $sort) {
		// On no count, no need to sort
		if (count($sort) == 0) {
			return '';
		}

		$queryString = '';
		foreach ($sort as $col => $value) {
			$queryString .= '<FieldRef Name="' . $col . '" Ascending="' . $this->getSortFromValue($value) . '" />';
		}
		return '<OrderBy>' . $queryString . '</OrderBy>';
	}

	/**
	 * modifyList
	 * Perform an action on a sharePoint list to either update or add content to it.
	 * This method will use prepBatch to generate the batch xml, then call the sharepoint SOAP API with this data
	 * to apply the changes.
	 *
	 * @param $list_name SharePoint List to update
	 * @param $items Arrary of new items or item changesets.
	 * @param $method New/Update/Delete
	 * @return Array|Object
	 */
	public function modifyList ($list_name, array $items, $method) {
		// Get batch XML
		$commands = $this->prepBatch($items, $method);

		// Wrap in CAML
		$CAML = '
		<UpdateListItems xmlns="http://schemas.microsoft.com/sharepoint/soap/">
			<listName>' . $list_name . '</listName>
			<updates>
				<Batch ListVersion="1" OnError="Continue">
					' . $commands . '
				</Batch>
			</updates>
		</UpdateListItems>';

		$xmlvar = new SoapVar($CAML, XSD_ANYXML);
		$result = NULL;

		// Attempt to run operation
		try {
			$result = $this->xmlHandler($this->soapClient->UpdateListItems($xmlvar)->UpdateListItemsResult->any);
		} catch (SoapFault $fault) {
			$this->onError($fault);
		}

		// Return a XML as nice clean Array
		return $result;
	}

	/**
	 * prepBatch
	 * Convert an array of new items or changesets in to XML commands to be run on
	 * the sharepoint SOAP API.
	 *
	 * @param $items array of new items/changesets
	 * @param $method New/Update/Delete
	 * @return XML
	 */
	public function prepBatch (array $items, $method) {
		// Check if method is supported
		assert(in_array($method, array('New', 'Update', 'Delete')));

		// Get var's needed
		$batch = '';
		$counter = 1;

		// Foreach item to be converted in to a SharePoint Soap Command
		foreach ($items as $data) {
			// Wrap item in command for given method
			$batch .= '<Method Cmd="' . $method . '" ID="' . $counter . '">';

			// Add required attributes
			foreach ($data as $itm => $val) {
				// Add entry
				$batch .= '<Field Name="' . $itm . '">' . htmlspecialchars($val) . '</Field>' . PHP_EOL;
			}

			$batch .= '</Method>';

			// Inc counter
			$counter++;
		}

		// Return XML data.
		return $batch;
	}

	/**
	 * onError
	 * This is called when sharepoint throws an error and displays basic debug info.
	 *
	 * @param	$fault		Error Information
	 * @throws	Exception	Puts data from $fault into an other exception
	 */
	private function onError (SoapFault $fault) {
		$more = '';
		if (isset($fault->detail->errorstring)) {
			$more = 'Detailed: ' . $fault->detail->errorstring;
		}
		throw new Exception('Error (' . $fault->faultcode . ') ' . $fault->faultstring . ',more=' . $more);
	}
}

/**
 * ListCRUD
 * A simple Create, Read, Update, Delete Wrapper for a specific list.
 * Useful for when you want to perform multiple actions on a specific list since it provides
 * shorter methods.
 */
class ListCRUD {
	/**
	 * Name of list
	 */
	private $list_name = '';

	/**
	 * API instance
	 */
	private $api = NULL;

	/**
	 * Construct
	 * Setup the new CRUD object
	 *
	 * @param $list_name Name of List to use
	 * @param $api Reference to SharePoint API object.
	 */
	public function __construct ($list_name, SharePointAPI $api) {
		$this->api = $api;
		$this->list_name = $list_name;
	}

	/**
	 * Create
	 * Create new item in the List
	 *
	 * @param Array $data Assosative array describing data to store
	 * @return Array
	 */
	public function create (array $data) {
		return $this->api->write($this->list_name, $data);
	}

	/**
	 * createMultiple
	 * Batch add items to the List
	 *
	 * @param Array of arrays of assosative array of data to change. Each item MUST include an ID field.
	 * @return Array
	 */
	public function createMultiple (array $data) {
		return $this->api->writeMultiple($this->list_name, $data);
	}

	/**
	 * Read
	 * Read items from List
	 *
	 * @param int $limit
	 * @param Array $query
	 * @return Array
	 */
	public function read ($limit = 0, $query = NULL) {
		return $this->api->read($this->list_name, $limit, $query, $view, $sort);
	}

	/**
	 * Update
	 * Update/Modifiy an existing list item.
	 *
	 * @param int $ID ID of item to update
	 * @param Array $data Assosative array of data to change.
	 * @return Array
	 */
	public function update ($item_id, array $data) {
		return $this->api->update($this->list_name, $item_id, $data);
	}

	/**
	 * UpdateMultiple
	 * Batch Update/Modifiy existing list item's.
	 *
	 * @param Array of arrays of assosative array of data to change. Each item MUST include an ID field.
	 * @return Array
	 */
	public function updateMultiple (array $data) {
		return $this->api->updateMultiple($this->list_name, $data);
	}
	
	/**
	 * Delete
	 * Delete an existing list item.
	 *
	 * @param int $item_id ID of item to delete
	 * @return Array
	 */
	public function delete ($item_id) {
		return $this->api->delete($this->list_name, $item_id);
	}

	/**
	 * Query
	 * Create a query against a list in sharepoint
	 *
	 * @return SP List Item
	 */
	public function query () {
		return new SPQueryObj($this->list_name, $this->api);
	}
}

/**
 * SP Query Object
 * Used to store and then run complex queries against a sharepoint list.
 *
 * Note: Querys are executed strictly from left to right and do not currently support nesting.
 */
class SPQueryObj {
	/**
	 * List to query
	 */
	private $list_name = '';

	/**
	 * Ref to API obj
	 */
	private $api = NULL;

	/**
	 * CAML for where query
	 */
	private $where_caml = '';

	/**
	 * CAML for sort
	 */
	private $sort_caml = '';

	/**
	 * Number of items to return
	 */
	private $limit = NULL;

	/**
	 * SharePoint API instance
	 */
	private $view = NULL;

	/**
	 * Construct
	 * Setup new query Object
	 *
	 * @param $list_name List to Query
	 * @param $api Reference to SP API
	 */
	public function __construct ($list_name, SharePointAPI $api) {
		$this->list_name = $list_name;
		$this->api = $api;
	}

	/**
	 * Where
	 * Perform inital where action
	 *
	 * @param $col column to test
	 * @param $test comparsion type (=,!+,<,>)
	 * @param $value to test with
	 * @return Ref to self
	 */
	public function where ($col, $test, $val) {
		return $this->addQueryLine('where', $col, $test, $val);
	}

	/**
	 * And_Where
	 * Perform additional and where actions
	 *
	 * @param $col column to test
	 * @param $test comparsion type (=,!+,<,>)
	 * @param $value to test with
	 * @return Ref to self
	 */
	public function and_where ($col, $test, $val) {
		return $this->addQueryLine('and', $col, $test, $val);
	}

	/**
	 * Or_Where
	 * Perform additional or where actions
	 *
	 * @param $col column to test
	 * @param $test comparsion type (=,!+,<,>)
	 * @param $value to test with
	 * @return Ref to self
	 */
	public function or_where ($col, $test, $val) {
		return $this->addQueryLine('or', $col, $test, $val);
	}

	/**
	 * Limit
	 * Specify maxium amount of items to return. (if not set, default is used.)
	 *
	 * @param $limit number of items to return
	 * @return Ref to self
	 */
	public function limit ($limit) {
		$this->limit = $limit;
		return $this;
	}

	/**
	 * Using
	 * Specify view to use when returning data.
	 *
	 * @param $view Name/GUID
	 * @return Ref to self
	 */
	public function using ($view) {
		$this->view = $view;
		return $this;
	}

	/**
	 * Sort
	 * Specify order data should be returned in.
	 *
	 * @param $sort_on column to sort on
	 * @param $order Sort direction
	 * @return Ref to self
	 */
	public function sort ($sort_on, $order = 'desc') {
		$queryString = '<FieldRef Name="'  .$sort_on . '" Ascending="' . $this->api->getSortFromValue($order) . '" />';
		$this->sort_caml = '<OrderBy>' . $queryString . '</OrderBy>';

		return $this;
	}

	/**
	 * get
	 * Runs the specified query and returns a useable result.
	 * @return Array: Sharepoint List Data 
	 */
	public function get () {
		return $this->api->read($this->list_name, $this->limit, $this, $this->view);
	}

	/**
	 * addQueryLine
	 * Generate CAML for where statements
	 *
	 * @param	$rel	Relation AND/OR etc
	 * @param	$col	column to test
	 * @param	$test	comparsion type (=,!+,<,>)
	 * @param	$value	value to test with
	 * @return	Ref to self
	 * @throws	Exception	Thrown if $test is unreconized
	 */
	private function addQueryLine ($rel, $col, $test, $value) {
		// Check tests are usable
		if (!in_array($test, array('!=', '>=', '<=', '<', '>', '='))) {
			throw new Exception('Unreconized query parameter. Please use <,>,=,>=,<= or !=');
		}

		// Make sure $rel is lower-case
		$rel = strtolower($rel);

		$test = str_replace(array('!=', '>=', '<=', '<', '>', '='), array('Neq', 'Geq', 'Leq', 'Lt', 'Gt', 'Eq'), $test);

		// Create caml
		$caml = $this->where_caml;
		$content = '<FieldRef Name="' . $col . '" /><Value Type="Text">' . htmlspecialchars($value) . '</Value>' . PHP_EOL;
		$caml .= '<' . $test . '>' . $content . '</' . $test . '>';

		// Attach relations
		if ($rel == 'and') {
			$this->where_caml = '<And>' . $caml . '</And>';
		} elseif ($rel == 'or') {
			$this->where_caml = '<Or>' . $caml . '</Or>';
		} elseif ($rel == 'where') {
			$this->where_caml = $caml;
		}

		// return self
		return $this;
	}
	
	/**
	 * getCAML
	 * Combine and return the raw CAML data for the query operation that has been specified.
	 * @return CAML Code (XML)
	 */
	public function getCAML () {
		// Start with empty string
		$xml = '';

		// Add where
		if (!empty($this->where_caml)) {
			$xml = '<Where>' . $this->where_caml . '</Where>';
		}

		// add sort
		if (!empty($this->sort_caml)) {
			$xml .= $this->sort_caml;
		}

		return $xml;
	}
}
?>
