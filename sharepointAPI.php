<?php
/**
 * SharepointAPI
 *
 * Simple PHP API for reading/writing and modifying SharePoint list items.
 * 
 * @author Carl Saggs
 * @version 2012.02.13
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
 *
 * Write: (insert)
 * $sp->write('<list_name>', array('<col_name>' => '<col_value>','<col_name_2>' => '<col_value_2>'));
 * 
 * Update:
 * $sp->update('<list_name>','<row_id>', array('<col_name>'=>'<new_data>'));
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
	 */
	public function __construct($sp_user, $sp_pass, $sp_WSDL)
	{
		$this->spUser = $sp_user;
		$this->spPass = $sp_pass;
		$this->wsdl = $sp_WSDL;
		
		//Create new SOAP Client
		try{
			$this->soapObject = new SoapClient($this->wsdl, array('login'=> $this->spUser ,'password' => $this->spPass));
		}catch(SoapFault $fault){
			//If we are unable to create a Soap Client display a Fatal error.
			die("Fatal Error: Unable to locate WSDL file.");
		}
		
	}
	
	/**
	 * getLists
	 * Return an array containing all avaiable lists within this sharepoint subsite.
	 * use set return type if you wish for this data to be provided as an object.
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
				$results[$counter][strtolower($attribute)] = $node->getAttribute($attribute);
			}
			//Make object if needed
			if($this->returnType === 1) settype($results[$counter], "object");
		}
		//Add error array if stuff goes wrong.
		if(!isset($results)) $results = array('warning' => 'No data returned.');
		
		return $results;
	}

	/**
	 * Read
	 * Use's raw CAML to query data
	 *
	 * @param String $list
	 * @param int $limit
	 * @param Array $query
	 * @return Array
	 */
	public function read($list, $limit = null, $query = null){
		//Check limit is set
		if($limit==0 || $limit == null) $limit = $this->MAX_ROWS;
		//Create Query XML is query is being used
		$queryXML = '';
		//If query is set pass it to the query builder
		if($query != null){
			$queryXML = $this->queryXML($query);
		}
		//Setup basic XML for quering a sharepoint list.
		//If rowLimit is not provided sharepoint will defualt to a limit of 100 items.
		$CAML = '
			<GetListItems xmlns="http://schemas.microsoft.com/sharepoint/soap/">  
			  <listName>'.$list.'</listName> 
			  <rowLimit>'.$limit.'</rowLimit>
			  '.$queryXML.'
			  <queryOptions xmlns:SOAPSDK9="http://schemas.microsoft.com/sharepoint/soap/" > 
				  <QueryOptions/> 
			  </queryOptions> 
			</GetListItems>';
		
		//Ready XML
		$xmlvar = new SoapVar($CAML, XSD_ANYXML);
		$rawXML ='';
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
			
		//Create XML to set values in the new Row Item
		$items = '';
		foreach($data AS $itm => $val){
			$items .= "<Field Name='{$itm}'>{$val}</Field>\n";
		}
		//CAML query (request), add extra Fields as necessary
		$CAML ="
		 <UpdateListItems xmlns='http://schemas.microsoft.com/sharepoint/soap/'>
			 <listName>{$list}</listName>
			 <updates>
				 <Batch ListVersion='1' OnError='Continue'>
					 <Method Cmd='New' ID='1'>
						{$items}
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
			$result = null;
		}
		//Return a XML as nice clean Array
		return $result;
	}
	//Alias
	public function insert($list, $data){
		return $this->write($list, $data); 
	}
	
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
	
		//Build array of colums to update in the selected Row
		$items = '';
		foreach($data AS $itm => $val){
			$items .= "<Field Name='{$itm}'>{$val}</Field>\n";
		}
		
		//CAML query (request), add extra Fields as necessary
		$CAML ="
		 <UpdateListItems xmlns='http://schemas.microsoft.com/sharepoint/soap/'>
			 <listName>{$list}</listName>
			 <updates>
				 <Batch ListVersion='1' OnError='Continue'>
					 <Method Cmd='Update' ID='1'>
						<Field Name='ID'>{$ID}</Field>
						{$items}
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
			$result = null;
		}
		//Return a XML as nice clean Array
		return $result;
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
			foreach($result->attributes as $test => $value){
				// Re-assign all the attributes into an easy to access array
				$resultArray[$i][strtolower(str_replace('ows_','',$test))] = $result->getAttribute($test);
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
	 * QueryXML
	 * Generates XML for a query
	 *
	 * @param Array $q array('<col>' => '<value_to_match_on>')
	 * @return XML DATA
	 */
	private function queryXML($q){
		
		$queryString ='';
		foreach($q as $col => $value){
			$queryString .= '<Eq><FieldRef Name="'.$col.'" /><Value Type="Text">'.$value.'</Value></Eq>';
		}
		//Add "and" when needed to query more than 1 attribute
		if(sizeof($q) > 1) $queryString = "<And>{$queryString}</And>";

		return "<query><Query><Where>{$queryString}</Where></Query></query>";
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
	 */
	private function onError($fault){
		echo 'Fault code: '.$fault->faultcode.'<br/>';
		echo 'Fault string: '.$fault->faultstring.'<br/>';
		//Add additional error info if available
		if(isset($fault->detail->errorstring)) echo 'Details: '.$fault->detail->errorstring;
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
	 * Read
	 * Read items from List
	 *
	 * @param int $limit
	 * @param Array $query
	 * @return Array
	 */
	public function read($limit=0, $query=null){
		return $this->api->read($this->list, $limit, $query);
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
	 * Delete
	 * Delete an existing list item.
	 *
	 * @param int $item_id ID of item to delete
	 * @return Array
	 */
	public function delete($item_id){
		return $this->api->delete($this->list, $item_id);
	}

}