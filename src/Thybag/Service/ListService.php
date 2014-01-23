<?php
namespace Thybag\Service;

/**
 * ListCRUD
 *
 * A simple Create, Read, Update, Delete Wrapper for a specific list.
 * Useful for when you want to perform multiple actions on a specific list since it provides
 * shorter methods.
 */
class ListService {

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
	 * @param string $list_name Name of List to use
	 * @param \Thybag\SharePointAPI $api Reference to SharePoint API object.
	 */
	public function __construct ($list_name, \Thybag\SharePointAPI $api) {
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
	public function read ($limit = 0, $query = NULL, $view = NULL, $sort = NULL, $options = NULL) {
		return $this->api->read($this->list_name, $limit, $query, $view, $sort, $options);
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
	public function delete ($item_id, array $data = array()) {
		return $this->api->delete($this->list_name, $item_id, $data);
	}

	/**
	 * Query
	 * Create a query against a list in sharepoint
	 *
	 * @return \Thybag\Service\QueryObjectService
	 */
	public function query () {
		return new \Thybag\Service\QueryObjectService($this->list_name, $this->api);
	}

}