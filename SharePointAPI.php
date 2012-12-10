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
 * $sp->read('<list_name>', null, array('<col_name>'=>'<col_value>'); //  Filter on col_name = col_value
 * $sp->read('<list_name>', null, null, '{FAKE-GUID00-0000-000}'); 	// Return list items with view (specified via GUID)
 * $sp->read('<list_name>', null, null, null, array('col_name'=>'asc|desc'));
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
	private $spUser;

	/**
	 * Password for SP auth
	 */
	private $spPass;

	/**
	 * Location of WSDL
	 * @FIXME Cannot be an URL (http://foo/bar/Lists.asmx?WSDL) if NTLM auth is being used
	 */
	private $spWsdl;

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
	private $lower_case_indexs = true;

	/**
	 * Maximum rows to return from a List 
	 */
	private $MAX_ROWS = 10000;

	/**
	 * Place holder for soapClient/SOAP client
	 */
	private $soapClient = null;

	/**
	 * Whether requests shall be traced
	 * (compare: http://de.php.net/manual/en/soapclient.soapclient.php )
	 */
	protected $soap_trace = true;

	/**
	 * Whether SOAP errors throw exception of type SoapFault
	 */
	protected $soap_exceptions = true;

	/**
	 * Kee-Alive HTTP setting (default: false)
	 */
	protected $soap_keep_alive = false;

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
	 * @param User account to authenticate with. (Must have read/write/edit permissions to given Lists)
	 * @param Password to use with authenticating account.
	 * @param WSDL file for this set of lists  ( sharepoint.url/subsite/_vti_bin/Lists.asmx?WSDL )
	 * @param Whether to authenticate with NTLM
	 */
	public function __construct ($sp_user, $sp_pass, $sp_WSDL, $useNtlm = false) {
		// Check if required class is found
		assert(class_exists('SoapClient'));

		// Set data from parameters in this class
		$this->spUser = $sp_user;
		$this->spPass = $sp_pass;
		$this->spWsdl = $sp_WSDL;

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
		);

		// Auto-detect http(s):// URLs
		if ((substr($this->spWsdl, 0, 7) == 'http://') || (substr($this->spWsdl, 0, 8) == 'https://')) {
			// Add location,uri options and set wsdl=null
			$options['location'] = $this->spWsdl;
			$options['uri']      = $this->spWsdl;
			$this->spWsdl = null;
		}

		// Create new SOAP Client
		try {
			// NTLM authentication or regular SOAP client?
			if ($useNtlm === true) {
				// Load include once
				require_once 'NTLM_SoapClient.php';

				// Use NTLM authentication client
				$this->soapClient = new NTLM_SoapClient($this->spWsdl, array_merge($options, array(
					'login'          => $this->spUser,
					'password'       => $this->spPass,
					'proxy_login'    => $this->proxyLogin,
					'proxy_password' => $this->proxyPassword,
					'proxy_host'     => $this->proxyHost,
					'proxy_port'     => $this->proxyPort
				)));
			} else {
				// Use regular client (for basic/digest auth)
				$this->soapClient = new SoapClient($this->spWsdl, array_merge($options, array(
					'login'    => $this->spUser,
					'password' => $this->spPass
				)));
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
	 * @param	string	$methodParams	Parameters to handle over
	 * @return	mixed	$returned		Returned values
	 */
	public final function __call ($methodName, $methodParams) {
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
	* @param $hideInternal true|false Attempt to hide none useful columns (internal data etc)
	*
	* @return Array
	*/
	public function readListMeta ($list_name, $hideInternal = true) {
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

			// Attempt to hide none useful feilds (disable by setting second param to false)
			if ($hideInternal && ($node->getAttribute('Type') == 'Lookup' || $node->getAttribute('Type') == 'Computed' || $node->getAttribute('Hidden') == 'TRUE')) {
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
	public function read ($list_name, $limit = null, $query = null, $view = null, $sort = null) {
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
		$result = null;

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
	public function write ($list_name, $data) {
		return $this->writeMultiple($list_name, array($data));
	}

	// Alias (Identical to above)
	public function add ($list_name, $data) { return $this->write($list_name, $data); }
	public function insert ($list_name, $data) { return $this->write($list_name, $data); }

	/**
	 * WriteMultiple
	 * Batch create new items in a sharepoint list
	 *
	 * @param String $list_name Name of List
	 * @param Array of arrays Assosative array's describing data to store
	 * @return Array
	 */
	public function writeMultiple ($list_name, $items) {
		return $this->modifyList($list_name, $items, 'New');
	}

	// Alias (Identical to above)
	public function addMultiple ($list_name, $items) { return $this->writeMultiple($list_name, $items); }
	public function insertMultiple ($list_name, $items) { return $this->writeMultiple($list_name, $items); }

	/**
	 * Update
	 * Update/Modifiy an existing list item.
	 *
	 * @param String $list_name Name of list
	 * @param int $ID ID of item to update
	 * @param Array $data Assosative array of data to change.
	 * @return Array
	 */
	public function update ($list_name, $ID, $data) {
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
	public function updateMultiple ($list_name, $items) {
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
		// CAML query (request), add extra Fields as necessary
		$CAML = '
		<UpdateListItems xmlns="http://schemas.microsoft.com/sharepoint/soap/">
			<listName>' . $list_name . '</listName>
			<updates>
				<Batch ListVersion="1" OnError="Continue">
					<Method Cmd="Delete" ID="1">
						<Field Name="ID">' . $ID . '</Field>
					</Method>
				</Batch>
			</updates>
		</UpdateListItems>';

		$xmlvar = new SoapVar($CAML, XSD_ANYXML);
		$result = null;

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
	 * @param $enable true|false
	 */
	public function lowercaseIndexs ($enable) {
		$this->lower_case_indexs = ($enable === true);
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
	private function getArrayFromElementsByTagName ($rawXml, $tag, $namespace = null) {
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
			$resultArray = array('warning' => 'No data returned.');
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
	private function whereXML ($q) {
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
	 * "Getter" for sort ascending (true) or descending (false) from given value
	 *
	 * @param	string	$value	Value to be checked
	 * @return	string	$sort	"true" for ascending, "false" (default) for descending
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
	 * @param $method New/Update
	 * @return Array|Object
	 */
	public function modifyList ($list_name, $items, $method) {
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
		$result = null;

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
	 * @param $method New/Update
	 * @return XML
	 */
	public function prepBatch ($items, $method) {
		// Get var's needed
		$batch = '';
		$counter = 1;
		$method = ($method == 'Update') ? 'Update' : 'New';

		// Foreach item to be converted in to a SharePoint Soap Command
		foreach ($items as $data) {
			// Wrap item in command for given method
			$batch .= '<Method Cmd="' . $method . '" ID="' . $counter . '">';

			// Add required attributes
			foreach ($data as $itm => $val) {
				$val = htmlspecialchars($val);
				$batch .= '<Field Name="' . $itm . '">' . $val . '</Field>' . PHP_EOL;
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
	private $api = null;

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
	public function create ($data) {
		return $this->api->write($this->list_name, $data);
	}

	/**
	 * createMultiple
	 * Batch add items to the List
	 *
	 * @param Array of arrays of assosative array of data to change. Each item MUST include an ID field.
	 * @return Array
	 */
	public function createMultiple ($data) {
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
	public function read ($limit = 0, $query = null) {
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
	public function update ($item_id, $data) {
		return $this->api->update($this->list_name, $item_id, $data);
	}

	/**
	 * UpdateMultiple
	 * Batch Update/Modifiy existing list item's.
	 *
	 * @param Array of arrays of assosative array of data to change. Each item MUST include an ID field.
	 * @return Array
	 */
	public function updateMultiple ($data) {
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
	 * Table to query
	 */
	private $table;

	/**
	 * Ref to API obj
	 */
	private $api;

	/**
	 * CAML for where query
	 */
	private $where_caml = '';

	/**
	 * CAML for sort
	 */
	private $sort_caml = '';

	private $limit = null;
	private $view = null;

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
