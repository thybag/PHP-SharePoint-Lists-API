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
 * $sp->read('<list_name>', 500); //Return 500 records
 * $sp->read('<list_name>', null, array('<col_name>'=>'<col_value>'); // Filter on col_name = col_value
 * $sp->read('<list_name>', null, null, '{FAKE-GUID00-0000-000}'); 	//Return list items with view (specified via GUID)
 * $sp->read('<list_name>', null, null, null, array('col_name'=>'asc|desc'));
 *
 * Query:
 * $sp->query('<list_name>')->where('type','=','dog')->and_where('age','>','5')->limit(10)->sort('age','ASC')->get();
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

class sharepointAPI{

	private $spUser;
	private $spPass;
	private $wsdl;
	private $returnType = 0;
	private $lower_case_indexs = true;

	//Maximum rows to return from a List 
	private $MAX_ROWS = 10000;
	//Place holder for soapObject/SOAP client
	private $soapObject = null;

	/**
	 * Constructor
	 *
	 * @param User account to authenticate with. (Must have read/write/edit permissions to given Lists)
	 * @param Password to use with authenticating account.
	 * @param WSDL file for this set of lists  ( sharepoint.url/subsite/_vti_bin/Lists.asmx?WSDL )
	 * @param Whether to authenticate with NTLM
	 */
	public function __construct($sp_user, $sp_pass, $sp_WSDL, $useNtlm = false) {
		$this->spUser = $sp_user;
		$this->spPass = $sp_pass;
		$this->wsdl = $sp_WSDL;
		
		//Create new SOAP Client
		try {
			// NTLM authentication or regular SOAP client?
			if ($useNtml === true) {
				// Use NTLM authentication client
				$this->soapObject = new NTLM_SoapClient($this->wsdl, array('proxy_login'=> $this->spUser, 'proxy_password' => $this->spPass));
			} else {
				// Use regular client (for basic/digest auth)
				$this->soapObject = new SoapClient($this->wsdl, array('login'=> $this->spUser, 'password' => $this->spPass));
			}
		} catch(SoapFault $fault){
			//If we are unable to create a Soap Client display a Fatal error.
			throw new Exception("Unable to locate WSDL file. faultcode=" . $fault->faultcode . ",faulstring=" . $fault->faulstring);
		}
	}
	
	/**
	 * Get Lists
	 * Return an array containing all avaiable lists within a given sharepoint subsite.
	 * Use "set return type" if you wish for this data to be provided as an object.
	 *
	 * @return array (array) | array (object)
	 */
	public function getLists(){
		//Query Sharepoint for full listing of it's lists.
		try{
			$rawxml = $this->soapObject->GetListCollection()->GetListCollectionResult->any;
		}catch(SoapFault $fault){
			$this->onError($fault);
		}
		//Load XML in to DOM document and grab all list items.
		$dom = new DOMDocument();
		$dom->loadXML($rawxml);
		$nodes = $dom->getElementsByTagName("List");
		//Format data in to array or object
		foreach($nodes as $counter => $node){
			foreach($node->attributes as $attribute => $value){
				$idx = ($this->lower_case_indexs) ? strtolower($attribute) : $attribute;
				$results[$counter][$idx] = $node->getAttribute($attribute);
			}
			//Make object if needed
			if($this->returnType === 1) settype($results[$counter], "object");
		}
		//Add error array if stuff goes wrong.
		if(!isset($results)) $results = array('warning' => 'No data returned.');
		
		return $results;
	}
	
	/**
	* Read List MetaData (Column configurtion)
	* Return a full listing of columns and their configurtion options for a given sharepoint list.
	*
	* @param $list Name or GUID of list to return metaData from.
	* @param $hideInternal true|false Attempt to hide none useful columns (internal data etc)
	*
	* @return Array
	*/
	public function readListMeta($list, $hideInternal = true){
		//Ready XML
		$CAML = '
			<GetList xmlns="http://schemas.microsoft.com/sharepoint/soap/">  
			  <listName>'.$list.'</listName> 
			</GetList>
		';
		$rawxml ='';
		//Attempt to query Sharepoint
		try{
			$rawxml = $this->soapObject->GetList(new SoapVar($CAML, XSD_ANYXML))->GetListResult->any;
		}catch(SoapFault $fault){
			$this->onError($fault);
		}
		//Load XML in to DOM document and grab all Fields
		$dom = new DOMDocument();
		$dom->loadXML($rawxml);
		$nodes = $dom->getElementsByTagName("Field");
		
		//Format data in to array or object
		foreach($nodes as $counter => $node){
			//Empty inner_xml
			$inner_xml ='';
		
			//Attempt to hide none useful feilds (disable by setting second param to false)
			if($hideInternal) if($node->getAttribute('Type') == 'Lookup' || $node->getAttribute('Type') == 'Computed' || $node->getAttribute('Hidden')=='TRUE') {continue;}
			
			//Get Attributes
			foreach($node->attributes as $attribute => $value){
				$idx = ($this->lower_case_indexs) ? strtolower($attribute) : $attribute;
				$results[$counter][$idx] = $node->getAttribute($attribute);
			}
			//Get contents (Raw xml)
			foreach($node->childNodes as $childNode){
				$inner_xml .= $node->ownerDocument->saveXml($childNode);
			}
			$results[$counter]['value'] = $inner_xml;
			
			//Make object if needed
			if($this->returnType === 1) settype($results[$counter], "object");
		}
		//Add error array if stuff goes wrong.
		if(!isset($results)) $results = array('warning' => 'No data returned.');
		
		//Return a XML as nice clean Array or Object
		return $results;
	}
	
	/**
	 * Read
	 * Use's raw CAML to query sharepoint data
	 *
	 * @param String $list
	 * @param int $limit
	 * @param Array $query
	 * @param String (GUID) $view "View to display results with."
	 * @param Array $sort
	 *
	 * @return Array
	 */
	public function read($list, $limit = null, $query = null, $view = null, $sort = null){
		//Check limit is set
		if($limit<1 || $limit == null) $limit = $this->MAX_ROWS;
		//Create Query XML is query is being used
		$xml_options= ''; $xml_query='';
		//Setup Options
		if(gettype($query)=='object' && get_class($query)=='SPQueryObj'){
			$xml_query = $query->getCAML();
		}else{
			if($view != null) 	$xml_options .= "<viewName>{$view}</viewName>";
			if($query != null)	$xml_query .= $this->whereXML($query);//Build Query
			if($sort != null)	$xml_query .= $this->sortXML($sort);
		}
		//If query is required
		if($xml_query!='') $xml_options .= "<query><Query>{$xml_query}</Query></query>";
		//Setup basic XML for quering a sharepoint list.
		//If rowLimit is not provided sharepoint will defualt to a limit of 100 items.
		$CAML = '
			<GetListItems xmlns="http://schemas.microsoft.com/sharepoint/soap/">  
			  <listName>'.$list.'</listName> 
			  <rowLimit>'.$limit.'</rowLimit>
			   '.$xml_options.'
			  <queryOptions xmlns:SOAPSDK9="http://schemas.microsoft.com/sharepoint/soap/" > 
				  <QueryOptions/> 
			  </queryOptions> 
			</GetListItems>';
		//Ready XML
		$xmlvar = new SoapVar($CAML, XSD_ANYXML);
		//Attempt to query Sharepoint
		try{
			$result = $this->xmlHandler($this->soapObject->GetListItems($xmlvar)->GetListItemsResult->any);
		}catch(SoapFault $fault){
			$this->onError($fault);
			$result = null;
		}
		//Return a XML as nice clean Array
		return $result;

	}
	
	/**
	 * Write
	 * Create new item in a sharepoint list
	 *
	 * @param String $list Name of List
	 * @param Array $data Assosative array describing data to store
	 * @return Array
	 */
	public function write($list, $data){
		return $this->writeMultiple($list, array($data));
	}
	//Alias (Identical to above)
	public function add($list, $data){return $this->write($list, $data);}
	public function insert($list, $data){return $this->write($list, $data);}

	/**
	 * WriteMultiple
	 * Batch create new items in a sharepoint list
	 *
	 * @param String $list Name of List
	 * @param Array of arrays Assosative array's describing data to store
	 * @return Array
	 */
	public function writeMultiple($list, $items){
		return $this->modifyList($list, $items, 'New');
	}
	//Alias (Identical to above)
	public function addMultiple($list, $items){return $this->writeMultiple($list, $items);}
	public function insertMultiple($list, $items){return $this->writeMultiple($list, $items);}
	
	/**
	 * Update
	 * Update/Modifiy an existing list item.
	 *
	 * @param String $list Name of list
	 * @param int $ID ID of item to update
	 * @param Array $data Assosative array of data to change.
	 * @return Array
	 */
	public function update($list, $ID, $data){
		//Add ID to item
		$data['ID'] = $ID;
		return $this->updateMultiple($list, array($data));
	}

	/**
	 * UpdateMultiple
	 * Batch Update/Modifiy existing list item's.
	 *
	 * @param String $list Name of list
	 * @param Array of arrays of assosative array of data to change. Each item MUST include an ID field.
	 * @return Array
	 */
	public function updateMultiple($list, $items){
		return $this->modifyList($list, $items, 'Update');
	}
	
	/**
	 * Delete
	 * Delete an existing list item.
	 *
	 * @param String $list Name of list
	 * @param int $ID ID of item to delete
	 * @return Array
	 */
	public function delete($list, $ID){

		//CAML query (request), add extra Fields as necessary
		$CAML ="
		 <UpdateListItems xmlns='http://schemas.microsoft.com/sharepoint/soap/'>
			 <listName>{$list}</listName>
			 <updates>
				 <Batch ListVersion='1' OnError='Continue'>
					 <Method Cmd='Delete' ID='1'>
						<Field Name='ID'>{$ID}</Field>
					 </Method>
				 </Batch>
			 </updates>
		 </UpdateListItems>";
		 
		$xmlvar = new SoapVar($CAML, XSD_ANYXML);
		//Attempt to run operation
		try{
			$result = $this->xmlHandler($this->soapObject->UpdateListItems($xmlvar)->UpdateListItemsResult->any);	
		}catch(SoapFault $fault){
			$this->onError($fault);
		}
		//Return a XML as nice clean Array
		return $result;
	}
	
	/**
	 * setReturnType
	 * Change the dataType used to store List items.
	 * Array or Object.
	 *
	 * @param $type
	 */
	public function setReturnType($type){
		if(trim(strtolower($type)) == 'object'){
			$this->returnType = 1;
		}else{
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
	public function lowercaseIndexs($enable){
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
	public function query($table){
		return new SPQueryObj($table, $this);
	}
	
	/**
	 * CRUD
	 * Create a simple Create, Read, Update, Delete Wrapper around a specific list.
	 *
	 * @param $list Name of list to provide CRUD for.
	 * @return ListCRUD Object
	 */
	public function CRUD($list){
		return new ListCRUD($list, $this);
	}
	
	/**
	 * xmlHandler
	 * Transform the XML returned from SOAP in to a useful datastructure.
	 * By Defualt all sharepoint items will be represented as arrays.
	 * Use setReturnType('object') to have them returned as objects.
	 *
	 * @param $rawXML XML DATA returned by SOAP
	 * @return Array( Array ) | Array( Object )
	 */
	private function xmlHandler($rawXML){
		//Use DOMDocument to proccess XML
		$dom = new DOMDocument();
		$dom->loadXML($rawXML);
		$results = $dom->getElementsByTagNameNS("#RowsetSchema", "*");
		
		//Proccess Object and return a nice clean assoaitive array of the results
		foreach($results as $i => $result){
			$resultArray[$i] = array();
			foreach($result->attributes as $attribute=> $value){
				$idx = ($this->lower_case_indexs) ? strtolower($attribute) : $attribute;
				// Re-assign all the attributes into an easy to access array
				$resultArray[$i][str_replace('ows_','',$idx)] = $result->getAttribute($attribute);
			}
			//ReturnType 1 = Object. 
			//If set, change array in to an object.
			//
			//Feature based on implementation by dcarbone  (See: https://github.com/dcarbone/ )
			if($this->returnType === 1) settype($resultArray[$i], "object");
			
		}
		//Check some values were actually returned
		if(!isset($resultArray)) $resultArray = array('warning' => 'No data returned.');
		
		return $resultArray;
	}
	
	/**
	 * Query XML
	 * Generates XML for WHERE Query
	 *
	 * @param Array $q array('<col>' => '<value_to_match_on>')
	 * @return XML DATA
	 */
	private function whereXML($q){
		
		$queryString ='';
		$counter = 0;
		foreach($q as $col => $value){
			$counter++;
			$queryString .= '<Eq><FieldRef Name="'.$col.'" /><Value Type="Text">'.htmlspecialchars($value).'</Value></Eq>';
			//Add additional "and"s if there are multiple query levels needed.
			if($counter>=2) $queryString = "<And>{$queryString}</And>";
		}
		
		return "<Where>{$queryString}</Where>";
	}
	
	/**
	 * Sort XML
	 * Generates XML for sort
	 *
	 * @param Array $sort array('<col>' => 'asc | desc')
	 * @return XML DATA
	 */
	private function sortXML($sort){
		if($sort == null || !is_array($sort)){ return ''; }
		
		$queryString ='';
		foreach($sort as $col => $value){
			$s = 'false';
			if($value == 'ASC' || $value == 'asc' || $value == 'true' || $value == 'ascending') $s = 'true';
			$queryString .= '<FieldRef Name="'.$col.'" Ascending="'.$s.'" />';
		}
		return "<OrderBy>{$queryString}</OrderBy>";
	}

	/**
	 * modifyList
	 * Perform an action on a sharePoint list to either update or add content to it.
	 * This method will use prepBatch to generate the batch xml, then call the sharepoint SOAP API with this data
	 * to apply the changes.
	 *
	 * @param $list SharePoint List to update
	 * @param $items Arrary of new items or item changesets.
	 * @param $method New/Update
	 * @return Array|Object
	 */
	public function modifyList($list, $items, $method){

		//Get batch XML
		$commands = $this->prepBatch($items, $method);
		//Wrap in CAML
		$CAML ="
		 <UpdateListItems xmlns='http://schemas.microsoft.com/sharepoint/soap/'>
			 <listName>{$list}</listName>
			 <updates>
				 <Batch ListVersion='1' OnError='Continue'>
					 {$commands}
				 </Batch>
			 </updates>
		 </UpdateListItems>";
		$xmlvar = new SoapVar($CAML, XSD_ANYXML);
		//Attempt to run operation
		try{
			$result = $this->xmlHandler($this->soapObject->UpdateListItems($xmlvar)->UpdateListItemsResult->any);	
		}catch(SoapFault $fault){
			$this->onError($fault);
			$result = null;
		}
		//Return a XML as nice clean Array
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
	public function prepBatch($items, $method){
		//Get var's needed
		$batch = '';
		$counter = 1;
		$method = ($method == 'Update') ? 'Update' : 'New';
		//Foreach item to be converted in to a SharePoint Soap Command
		foreach($items as $data){
			//Wrap item in command for given method
			$batch .= "<Method Cmd='{$method}' ID='{$counter}'>";
			//Add required attributes
			foreach($data AS $itm => $val){
				$val = htmlspecialchars($val);
				$batch .= "<Field Name='{$itm}'>{$val}</Field>\n";
			}
			$batch .= "</Method>";
			//Inc counter
			$counter++;
		}
		//Return XML data.
		return $batch;
	}
	
	/**
	 * Create Soap Object
	 * Creates and returns a new SOAPClient Object
	 *
	 * @return Object SoapClient
	 * @depricated (this should no longer be used)
	 */
	private function createSoapObject(){
			try{
				return new SoapClient($this->wsdl, array('login'=> $this->spUser ,'password' => $this->spPass));
			}catch(SoapFault $fault){
				//If we are unable to create a Soap Client display a Fatal error.
				die("Fatal Error: Unable to locate WSDL file.");
			}
	}
	
	/**
	 * onError
	 * This is called when sharepoint throws an error and displays basic debug info.
	 *
	 * @param $fault Error Information
	 * @throws Exception
	 */
	private function onError($fault){
		$more = '';
		if(isset($fault->detail->errorstring))$more = $fault->detail->errorstring;
		throw new Exception("Error ({$fault->faultcode}) {$fault->faultstring} {$more}");
	}
}

/**
 * ListCRUD
 * A simple Create, Read, Update, Delete Wrapper for a specific list.
 * Useful for when you want to perform multiple actions on a specific list since it provides
 * shorter methods.
 */
class ListCRUD {

	//Require info
	private $list = '';
	private $api = null;
	
	/**
	 * Construct
	 * Setup the new CRUD object
	 *
	 * @param $list_name Name of List to use
	 * @param $api Reference to SharePoint API object.
	 */
	public function __construct($list_name, $api)
	{
		$this->api = $api;
		$this->list = $list_name;
	}
	
	/**
	 * Create
	 * Create new item in the List
	 *
	 * @param Array $data Assosative array describing data to store
	 * @return Array
	 */
	public function create($data){
		return $this->api->write($this->list, $data);
	}

	/**
	 * createMultiple
	 * Batch add items to the List
	 *
	 * @param Array of arrays of assosative array of data to change. Each item MUST include an ID field.
	 * @return Array
	 */
	public function createMultiple($data){
		return $this->api->writeMultiple($this->list, $data);
	}
	
	/**
	 * Read
	 * Read items from List
	 *
	 * @param int $limit
	 * @param Array $query
	 * @return Array
	 */
	public function read($limit=0, $query=null){
		return $this->api->read($this->list, $limit, $query, $view, $sort);
	}
	
	/**
	 * Update
	 * Update/Modifiy an existing list item.
	 *
	 * @param int $ID ID of item to update
	 * @param Array $data Assosative array of data to change.
	 * @return Array
	 */
	public function update($item_id, $data){
		return $this->api->update($this->list, $item_id, $data);
	}

	/**
	 * UpdateMultiple
	 * Batch Update/Modifiy existing list item's.
	 *
	 * @param Array of arrays of assosative array of data to change. Each item MUST include an ID field.
	 * @return Array
	 */
	public function updateMultiple($data){
		return $this->api->updateMultiple($this->list, $data);
	}
	
	/**
	 * Delete
	 * Delete an existing list item.
	 *
	 * @param int $item_id ID of item to delete
	 * @return Array
	 */
	public function delete($item_id){
		return $this->api->delete($this->list, $item_id);
	}

	/**
	 * Query
	 * Create a query against a list in sharepoint
	 *
	 * @return SP List Item
	 */
	public function query(){
		return new SPQueryObj($this->list, $this->api);
	}
}

/**
 * SP Query Object
 * Used to store and then run complex queries against a sharepoint list.
 *
 * Note: Querys are executed strictly from left to right and do not currently support nesting.
 */
Class SPQueryObj {

	//Internal data
	private $table;	//Table to query
	private $api; 	//Ref to API obj
	private $where_caml = '';//CAML for where query
	private $sort_caml ='';//CAML for sort
	private $limit = null;
	private $view = null;
	
	/**
	 * Construct
	 * Setup new query Object
	 *
	 * @param $list List to Query
	 * @param $api Reference to SP API
	 */
	public function __construct($list, $api){
		$this->table = $list;
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
	public function where($col,$test,$val){
		return $this->addQueryLine('where',$col,$test,$val);
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
	public function and_where($col,$test,$val){
		return $this->addQueryLine('and',$col,$test,$val);
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
	public function or_where($col,$test,$val){
		return $this->addQueryLine('or',$col,$test,$val);
	}

	/**
	 * Limit
	 * Specify maxium amount of items to return. (if not set, default is used.)
	 *
	 * @param $limit number of items to return
	 * @return Ref to self
	 */
	public function limit($limit){
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
	public function using($view){
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
	public function sort($sort_on, $order = 'DESC'){
		$s = 'false';
		if($order == 'ASC' || $order == 'asc' || $order == 'true' || $order == 'ascending') $s = 'true';
		
		$queryString = '<FieldRef Name="'.$sort_on.'" Ascending="'.$s.'" />';
		$this->sort_caml = "<OrderBy>{$queryString}</OrderBy>";
		
		return $this;
	}

	/**
	 * get
	 * Runs the specified query and returns a useable result.
	 * @return Array: Sharepoint List Data 
	 *
	 */
	public function get(){
		return $this->api->read($this->table, $this->limit, $this, $this->view);
	}


	/**
	 * addQueryLine
	 * Generate CAML for where statements
	 *
	 * @param $rel Relation AND/OR etc
	 * @param $col column to test
	 * @param $test comparsion type (=,!+,<,>)
	 * @param $value to test with
	 * @return Ref to self
	 */
	private function addQueryLine($rel, $col, $test, $value){
		//Check tests are usable
		if(!in_array($test,array('!=','>=', '<=', '<','>','='))) die("Unrecognised query paramiter. Please use <,>,= or !=");
		$test = str_replace(array('!=','>=', '<=', '<','>','='), array('Neq','Geq', 'Leq','Lt','Gt','Eq'), $test);
		//Create caml
		$caml = $this->where_caml;
		$content = '<FieldRef Name="'.$col.'" /><Value Type="Text">'. htmlspecialchars($value).'</Value>'."\n";
		$caml .= "<{$test}>{$content}</{$test}>";
		//Attach relations
		if($rel=='and'){
			$this->where_caml = "<And>{$caml}</And>";
		}else if($rel == 'or'){
			$this->where_caml = "<Or>{$caml}</Or>";
		}else if($rel = 'where'){
			$this->where_caml = $caml;
		}
		//return self
		return $this;
	}
	
	/**
	 * getCAML
	 * Combine and return the raw CAML data for the query operation that has been specified.
	 * @return CAML Code (XML)
	 */
	public function getCAML(){
		$xml = $this->where_caml;
		$sort =	$this->sort_caml;
		//Add where
		if($xml != '') $xml = "<Where>{$this->where_caml}</Where>";
		//add sort
		if($sort != '') $xml .= $sort;
		
		return $xml;
	}
}

/**
 * A child of SoapClient with support for ntlm proxy authentication
 *
 * @author Meltir <meltir@meltir.com>
 * @see http://php.net/manual/en/soapclient.soapclient.php#97029
 */
class NTLM_SoapClient extends SoapClient {
	/**
	 * Overwritren constructor
	 *
	 * @return	void
	 */
	public function __construct($wsdl, $options = array()) {
		if (empty($options['proxy_login']) || empty($options['proxy_password'])) {
			throw new Exception('Login and password required for NTLM authentication!');
		}
		$this->proxy_login = $options['proxy_login'];
		$this->proxy_password = $options['proxy_password'];
		$this->proxy_host = (empty($options['proxy_host']) ? 'localhost' : $options['proxy_host']);
		$this->proxy_port = (empty($options['proxy_port']) ? 8080 : $options['proxy_port']);
		parent::__construct($wsdl, $options);
	}

	/**
	 * Call a url using curl with ntlm auth
	 *
	 * @param string $url
	 * @param string $data
	 * @return string
	 * @throws SoapFault on curl connection error
	 */
	protected function callCurl($url, $data) {
		$handle= curl_init();
		curl_setopt($handle, CURLOPT_HEADER, false);
		curl_setopt($handle, CURLOPT_URL, $url);
		curl_setopt($handle, CURLOPT_FAILONERROR, true);
		curl_setopt($handle, CURLOPT_HTTPHEADER, Array("PHP SOAP-NTLM Client") );
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
		curl_setopt($handle, CURLOPT_PROXYUSERPWD,$this->proxy_login.':'.$this->proxy_password);
		curl_setopt($handle, CURLOPT_PROXY, $this->proxy_host.':'.$this->proxy_port);
		curl_setopt($handle, CURLOPT_PROXYAUTH, CURLAUTH_NTLM);
		$response = curl_exec($handle);
		if (empty($response)) {
			throw new SoapFault('CURL error: '.curl_error($handle),curl_errno($handle));
		}
		curl_close($handle);
		return $response;
	}

	/**
	 * Overwritten method in SoapClient::__doRequest to use CURL now
	 *
	 * Notice: $action, $version and $one_way are unsupported
	 */
	public function __doRequest($request, $location, $action, $version, $one_way = 0) {
		return $this->callCurl($location, $request);
	}
}
