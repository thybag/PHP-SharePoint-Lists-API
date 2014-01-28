<?php
namespace Thybag;

/**
 * SharepointAPI
 *
 * Simple PHP API for reading/writing and modifying SharePoint list items.
 *
 * @author Carl Saggs
 * @version 0.6.2
 * @licence MIT License
 * @source: http://github.com/thybag/PHP-SharePoint-Lists-API
 *
 * Tested against the sharepoint 2007 API
 *
 * WSDL file needed will be located at: sharepoint.url/subsite/_vti_bin/Lists.asmx?WSDL
 *
 * Usage:
 * $sp = new \Thybag\SharePointAPI('<username>','<password>','<path_to_WSDL>');
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
 * 		array('Title' => 'New item'),
 * 		array('Title' => 'New item 2')
 * 	));
 *
 * Update:
 * $sp->update('<list_name>','<row_id>', array('<col_name>'=>'<new_data>'));
 *
 * UpdateMultiple:
 * $sp->updateMultiple('<list_name>', array(
 * 		array('ID'=>1, 'Title' => 'new name'),
 * 		array('ID'=>2, 'Title' => 'New name 2')
 * 	));
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
	 * Constructor
	 *
	 * @param string $spUsername User account to authenticate with. (Must have read/write/edit permissions to given Lists)
	 * @param string $spPassword Password to use with authenticating account.
	 * @param string $spWsdl WSDL file for this set of lists ( sharepoint.url/subsite/_vti_bin/Lists.asmx?WSDL )
	 * @param Whether to authenticate with NTLM
	 */
	public function __construct ($spUsername, $spPassword, $spWsdl, $mode = 'STANDARD') {
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

		// Is login set?
		if (!empty($this->spUsername)) {
			// Then set login data
			$options['login']    = $this->spUsername;
			$options['password'] = $this->spPassword;
		}

		// Create new SOAP Client
		try {
			if ((isset($options['login'])) && ($mode == 'NTLM')) {
				// If using authentication then use the custom SoapClientAuth class.
				$this->soapClient = new \Thybag\Auth\SoapClientAuth($this->spWsdl, $options);
			} elseif($mode == 'SPONLINE'){
				$this->soapClient = new \Thybag\Auth\SharePointOnlineAuth($this->spWsdl, $options);
			} else {
				$this->soapClient = new \SoapClient($this->spWsdl, $options);
			}
		} catch (\SoapFault $fault) {
			// If we are unable to create a Soap Client display a Fatal error.
			throw new \Exception('Unable to locate WSDL file. faultcode=' . $fault->getCode() . ',faultstring=' . $fault->getMessage());
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
		if (!$this->soapClient instanceof \SoapClient) {
			// Is not set
			throw new \Exception('Variable soapClient is not a SoapClient class, have: ' . gettype($this->soapClient), 0xFF);
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
		} catch (\SoapFault $fault) {
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
	 * @param $ignoreHiddenAttribute TRUE|FALSE Ignores 'Hidden' attribute if it is set to 'TRUE' - DEBUG ONLY!!!
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
			$rawXml = $this->soapClient->GetList(new \SoapVar($CAML, XSD_ANYXML))->GetListResult->any;
		} catch (\SoapFault $fault) {
			$this->onError($fault);
		}

		// Load XML in to DOM document and grab all Fields
		$nodes = $this->getArrayFromElementsByTagName($rawXml, 'Field');

		// Format data in to array or object
		foreach ($nodes as $counter => $node) {
			// Attempt to hide none useful feilds (disable by setting second param to FALSE)
			if ($hideInternal && ($node->getAttribute('Type') == 'Lookup' || $node->getAttribute('Type') == 'Computed' || ($node->getAttribute('Hidden') == 'TRUE' && $ignoreHiddenAttribute === FALSE))) {
				continue;
			}

			// Get Attributes
			foreach ($node->attributes as $attribute => $value) {
				$idx = ($this->lower_case_indexs) ? strtolower($attribute) : $attribute;
				$results[$counter][$idx] = $node->getAttribute($attribute);
			}

			// Make object if needed
			if ($this->returnType === 1) {
				settype($results[$counter], 'object');
			}

			// If hiding internal is enabled and 'id' is not set, remove this element
			if ($hideInternal && !isset($results[$counter]['id'])) {
				// Then it has to be an "internal"
				unset($results[$counter]);
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
	 * @param String $options "XML string of query options."
	 *
	 * @return Array
	 */
	public function read ($list_name, $limit = NULL, $query = NULL, $view = NULL, $sort = NULL, $options = NULL) {
		// Check limit is set
		if ($limit < 1 || is_null($limit)) {
			$limit = $this->MAX_ROWS;
		}

		// Create Query XML is query is being used
		$xml_options = '';
		$xml_query   = '';
		$fields_xml = '';

		// Setup Options
		if ($query instanceof Service\QueryObjectService) {
			$xml_query = $query->getCAML();
			$xml_options = $query->getOptionCAML();
		} else {

			if (!is_null($query)) {
				$xml_query .= $this->whereXML($query); // Build Query
			}
			if (!is_null($sort)) {
				$xml_query .= $this->sortXML($sort);// add sort
			}

			// Add view or fields
			if (!is_null($view)){
				// array, fields have been specified
				if(is_array($view)){
					$xml_options .= $this->viewFieldsXML($view);
				}else{
					$xml_options .= '<viewName>' . $view . '</viewName>';
				}
			}
		}

		// If query is required
		if (!empty($xml_query)) {
			$xml_options .= '<query><Query>' . $xml_query . '</Query></query>';
		}

		/*
		 * Setup basic XML for querying a SharePoint list.
		 * If rowLimit is not provided SharePoint will default to a limit of 100 items.
		 */
		$CAML = '
			<GetListItems xmlns="http://schemas.microsoft.com/sharepoint/soap/">
				<listName>' . $list_name . '</listName>
				<rowLimit>' . $limit . '</rowLimit>
				' . $xml_options . '
				<queryOptions xmlns:SOAPSDK9="http://schemas.microsoft.com/sharepoint/soap/" >
					<QueryOptions>
						' . $options . '
					</QueryOptions>
				</queryOptions>
			</GetListItems>';

		// Ready XML
		$xmlvar = new \SoapVar($CAML, XSD_ANYXML);
		$result = NULL;

		// Attempt to query SharePoint
		try {
			$result = $this->xmlHandler($this->soapClient->GetListItems($xmlvar)->GetListItemsResult->any);
		} catch (\SoapFault $fault) {
			$this->onError($fault);
		}

		// Return a XML as nice clean Array
		return $result;
	}

	/**
	 * ReadFromFolder
	 * Uses raw CAML to query sharepoint data from a folder
	 *
	 * @param String $listName
	 * @param String $folderName
	 * @param bool   $isLibrary
	 * @param String $limit
	 * @param String $query
	 * @param String $view
	 * @param String $sort
	 *
	 * @return Array
	 */
	public function readFromFolder($listName, $folderName = '', $isLibrary = false, $limit = NULL, $query = NULL, $view = NULL, $sort = NULL) {
		return $this->read($listName, $limit, $query, $view, $sort, "<Folder>" . ($isLibrary ? '' : 'Lists/') . $listName . '/' . $folderName . "</Folder>" );
	}

	/**
	 * Write
	 * Create new item in a sharepoint list
	 *
	 * @param String $list_name Name of List
	 * @param Array $data Associative array describing data to store
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
	 * @param Array of arrays Associative array's describing data to store
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
	 * Update/Modify an existing list item.
	 *
	 * @param String $list_name Name of list
	 * @param int $ID ID of item to update
	 * @param Array $data Associative array of data to change.
	 * @return Array
	 */
	public function update ($list_name, $ID, array $data) {
		// Add ID to item
		$data['ID'] = $ID;
		return $this->updateMultiple($list_name, array($data));
	}

	// aliases
	public function edit($list_name, $ID, array $data) { return $this->update ($list_name, $ID, $data); }

	/**
	 * UpdateMultiple
	 * Batch Update/Modify existing list item's.
	 *
	 * @param String $list_name Name of list
	 * @param Array of arrays of assosative array of data to change. Each item MUST include an ID field.
	 * @return Array
	 */
	public function updateMultiple ($list_name, array $items) {
		return $this->modifyList($list_name, $items, 'Update');
	}

	// aliases
	public function editMultiple($list_name, array $items) { return $this->updateMultiple ($list_name, $items); }

	/**
	 * Delete
	 * Delete an existing list item.
	 *
	 * @param String $list_name Name of list
	 * @param int $ID ID of item to delete
	 * @param array $data An array of additional required key/value pairs for the item to delete e.g. FileRef => URL to file.
	 * @return Array
	 */
	public function delete ($list_name, $ID, array $data = array()) {
		return $this->deleteMultiple($list_name, array($ID), array($ID => $data));
	}

	/**
	 * DeleteMultiple
	 * Delete existing multiple list items.
	 *
	 * @param String $list_name Name of list
	 * @param array $IDs IDs of items to delete
	 * @param array $data An array of arrays of additional required key/value pairs for each item to delete e.g. FileRef => URL to file.
	 * @return Array
	 */
	public function deleteMultiple ($list_name, array $IDs, array $data = array()) {
		/*
		 * change input "array(ID1, ID2, ID3)" to "array(array('id' => ID1),
		 * array('id' => ID2), array('id' => ID3))" in order to be compatible
		 * with modifyList.
		 *
		 * For each ID also check if we have any additional data. If so then
		 * add it to the delete data.
		 */
		$deletes = array();
		foreach ($IDs as $ID) {
			$delete = array('ID' => $ID);
			// Add additional data if available
			if (!empty($data[$ID])) {
				foreach ($data[$ID] as $key => $value) {
					$delete[$key] = $value;
				}
			}
			$deletes[] = $delete;
		}

		// Return a XML as nice clean Array
		return $this->modifyList($list_name, $deletes, 'Delete');
	}

	/**
	 * addAttachment
	 * Add an attachment to a SharePoint List
	 *
	 * @param $list_name Name of list
	 * @param $list_item_id ID of record to attach attachment to
	 * @param $file_name path of file to attach
	 * @return Array
	 */
	public function addAttachment ($list_name, $list_item_id, $file_name) {
		// base64 encode file
		$attachment = base64_encode(file_get_contents($file_name));

		// Wrap in CAML
		$CAML = '
		<AddAttachment xmlns="http://schemas.microsoft.com/sharepoint/soap/">
			<listName>' . $list_name . '</listName>
			<listItemID>' . $list_item_id . '</listItemID>
			<fileName>' . $file_name . '</fileName>
			<attachment>' . $attachment . '</attachment>
		</AddAttachment>';

		$xmlvar = new \SoapVar($CAML, XSD_ANYXML);

		// Attempt to run operation
		try {
			$this->soapClient->AddAttachment($xmlvar);
		} catch (\SoapFault $fault) {
			$this->onError($fault);
		}

		// Return true on success
		return true;
	}	

	/**
	 * getAttachment
	 * Return an attachment from a SharePoint list item
	 *
	 * @param $list_name Name of list
	 * @param $list_item_id ID of record item is attached to
	 * @return Array of attachment urls
	 */
	public function getAttachments ($list_name, $list_item_id) {
		// Wrap in CAML
		$CAML = '
		<GetAttachmentCollection xmlns="http://schemas.microsoft.com/sharepoint/soap/">
			<listName>' . $list_name . '</listName>
			<listItemID>' . $list_item_id . '</listItemID>
		</GetAttachmentCollection>';

		$xmlvar = new \SoapVar($CAML, XSD_ANYXML);

		// Attempt to run operation
		try {
			$rawXml = $this->soapClient->GetAttachmentCollection($xmlvar)->GetAttachmentCollectionResult->any;
		} catch (\SoapFault $fault) {
			$this->onError($fault);
		}

		// Load XML in to DOM document and grab all list items.
		$nodes = $this->getArrayFromElementsByTagName($rawXml, 'Attachment');

		$attachments = array();

		// Format data in to array or object
		foreach ($nodes as $counter => $node) {
			$attachments[] = $node->textContent;
		}

		// Return Array of attachment URLs
		return $attachments;
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
	 * Enable or disable automatically lowercasing index's for returned data.
	 * (By default this is enabled to avoid users having to worry about the case attributes are in)
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
	 * Build query's as $sp->query('my_list')->where('score','>',15)->and_where('year','=','9')->get();
	 *
	 * @param List name / GUID number
	 * @return \Thybag\Service\QueryObjectService
	 */
	public function query ($table) {
		return new \Thybag\Service\QueryObjectService($table, $this);
	}

	/**
	 * CRUD
	 * Create a simple Create, Read, Update, Delete Wrapper around a specific list.
	 *
	 * @param $list_name Name of list to provide CRUD for.
	 * @return \Thybag\Service\ListService
	 */
	public function CRUD ($list_name) {
		return new \Thybag\Service\ListService($list_name, $this);
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
		$dom = new \DOMDocument();
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
	 * Transform the XML returned from SOAP in to a useful data structure.
	 * By Default all sharepoint items will be represented as arrays.
	 * Use setReturnType('object') to have them returned as objects.
	 *
	 * @param $rawXml XML DATA returned by SOAP
	 * @return Array( Array ) | Array( Object )
	 */
	private function xmlHandler ($rawXml) {
		// Use DOMDocument to proccess XML
		$results = $this->getArrayFromElementsByTagName($rawXml, '#RowsetSchema', '*');
		$resultArray = array();

		// Proccess Object and return a nice clean associative array of the results
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
	 * "Getter" for sort ascending ("true") or descending ("false") from given value
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
	 * view Field sXML
	 * Generates XML for specifying fields to return
	 *
	 * @param Array $fields to include
	 * @return XML DATA
	 */
	public function viewFieldsXML(array $fields){
		$xml = '';
		// Convert fields to array
		foreach($fields as $field){
			$xml .= '<FieldRef Name="'.$field.'" />';
		} 
		// wrap tags
		return  '<viewFields><ViewFields>'.$xml.'</ViewFields></viewFields>';  
	}

	/**
	 * modifyList
	 * Perform an action on a sharePoint list to either update or add content to it.
	 * This method will use prepBatch to generate the batch xml, then call the SharePoint SOAP API with this data
	 * to apply the changes.
	 *
	 * @param $list_name SharePoint List to update
	 * @param $items Array of new items or item changesets.
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

		$xmlvar = new \SoapVar($CAML, XSD_ANYXML);
		$result = NULL;

		// Attempt to run operation
		try {
			$result = $this->xmlHandler($this->soapClient->UpdateListItems($xmlvar)->UpdateListItemsResult->any);
		} catch (\SoapFault $fault) {
			$this->onError($fault);
		}

		// Return a XML as nice clean Array
		return $result;
	}

	/**
	 * prepBatch
	 * Convert an array of new items or change sets in to XML commands to be run on
	 * the sharepoint SOAP API.
	 *
	 * @param $items array of new items/change sets
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
	 * @throws	\Exception	Puts data from $fault into an other exception
	 */
	private function onError (\SoapFault $fault) {
		$more = '';
		if (isset($fault->detail->errorstring)) {
			$more = 'Detailed: ' . $fault->detail->errorstring;
		}
		throw new \Exception('Error (' . $fault->faultcode . ') ' . $fault->faultstring . ',more=' . $more);
	}

	/**
	 * magicLookup: Helper method
	 *
	 * If you know the name of the item you wish to link to in the lookup field, this helper method
	 * can be used to perform the lookup for you.
	 *
	 * @param $sp Active/current instance of sharepointAPI
	 * @param $name Name of item you wish lookup to reference
	 * @param $list Name of list item lookup is linked to.
	 *
	 * @return "lookup" value sharepoint will accept
	 */
	public function magicLookup ($name, $list) {
		//Perform lookup for specified item on specified list
		$find = $this->read($list, null, array('Title' => $name));
		//If we get a result (and there is only one of them) return it in "Lookup" format
		if (isset($find[0]) && count($find) === 1) {
			settype($find[0], 'array');//Set type to array in case API is in object mode.
			if ($this->lower_case_indexs) {
				return static::lookup($find[0]['id'], $find[0]['title']);
			} else {
				return static::lookup($find[0]['ID'], $find[0]['Title']);
			}
		} else {
			//If we didnt find anything / got to many, throw exception
			throw new \Exception('Unable to perform automated lookup for value in ' . $list . '.');
		}
	}

	/**
	 * dateTime: Helper method
	 * Format date for use by sharepoint
	 * @param $date (Date to be handled by strtotime)
	 * @param $timestamp. If first parameter is a unix timestamp, set this to true
	 *
	 *@return date SharePoint will accept
	 */
	public static function dateTime ($date, $timestamp = FALSE) {
		return ($timestamp) ? date('c',$date) : date('c', strtotime($date));
	}

	/**
	 * lookup: Helper method
	 * Format data to be used in lookup datatype
	 * @param $id ID of item in other table
	 * @param $title Title of item in other table (this is optional as sharepoint doesn't complain if its not provided)
	 * @return string "lookup" value sharepoint will accept
	 */
	public static function lookup ($id, $title = '') {
		return $id . (($title !== '') ? ';#' . $title : '');
	}

	/**
	 * getFieldVersions
	 * Get previous versions of field contents
	 *
	 * @see https://github.com/thybag/PHP-SharePoint-Lists-API/issues/6#issuecomment-13793688 by TimRainey 
	 * @param $list Name or GUID of list
	 * @param $id ID of item to find versions for
	 * @param $field name of column to get versions for
	 * @return array | object
	 */
	public function getFieldVersions ($list, $id, $field) {
	    //Ready XML
	    $CAML = '
	        <GetVersionCollection xmlns="http://schemas.microsoft.com/sharepoint/soap/">
	            <strlistID>'.$list.'</strlistID>
	            <strlistItemID>'.$id.'</strlistItemID>
	            <strFieldName>'.$field.'</strFieldName>
	        </GetVersionCollection>
	    ';

	    // Attempt to query SharePoint
	    try{
	        $rawxml = $this->soapClient->GetVersionCollection(new \SoapVar($CAML, XSD_ANYXML))->GetVersionCollectionResult->any;
	    }catch(\SoapFault $fault){
	        $this->onError($fault);
	    }

	    // Load XML in to DOM document and grab all Fields
        $dom = new \DOMDocument();
        $dom->loadXML($rawxml);
        $nodes = $dom->getElementsByTagName("Version");

        // Parse results
        $results = array();
        // Format data in to array or object
        foreach ($nodes as $counter => $node) {
            //Get Attributes
            foreach ($node->attributes as $attribute => $value) {
                $results[$counter][strtolower($attribute)] = $node->getAttribute($attribute);
            }
            //Make object if needed
            if ($this->returnType === 1) settype($results[$counter], "object");
        }
        // Add error array if stuff goes wrong.
        if (!isset($results)) $results = array('warning' => 'No data returned.');

	    return $results;
	}
    public function getColumnVersions ($list, $id, $field) { return $this->getFieldVersions($list, $id, $field); }
	
	/**
	 * getItemVersions
	 * Get previous versions of an item
	 *
	 * @param $list Name or GUID of list
	 * @param $id ID of item to find versions for
	 * @return array | object
	 */
	public function getItemVersions ($list, $id, $exclude_hidden = true) {
	    $fields = $this->readListMeta($list, $exclude_hidden);
	    
        // Parse results
        $results = array();
        // Format data in to array or object
        foreach ($fields as $counter => $field) {
            // Modified always returns an error
            if($field['name'] == 'Modified') { continue; }
            
            // Get all the fields
            $field_versions = $this->getFieldVersions($list, $id, $field['name']);
            
            // Get the versions for each field
            if(sizeof($field_versions) !== 0) {
                foreach($field_versions as $key => $value) {
                    if($this->lower_case_indexs) {
                        $results[$key][strtolower($field['name'])] = $value[strtolower($field['name'])];
                    } else {
                        $results[$key][$field['name']] = $value[$field['name']];
                    }
                }
                //Make object if needed
                if ($this->returnType === 1) settype($results[$counter], "object");
            }
		}

        // Add error array if stuff goes wrong.
        if (!isset($results)) $results = array('warning' => 'No data returned.');

	    return $results;
	}
	
	/**
	 * getVersions
	 * Get previous versions of an item or field
	 *
	 * @param $list Name or GUID of list
	 * @param $id ID of item to find versions for
	 * @param $field optional name of column to get versions for
	 * @return array | object
	 */
	public function getVersions ($list, $id, $field = null, $exclude_hidden = true) {
	    if($field === null) {
    	    return $this->getItemVersions($list, $id, $exclude_hidden);
	    } else {
	        return $this->getFieldVersions($list, $id, $field);
	    }
	}
}