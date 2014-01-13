<?php
namespace Thybag\Service;

/**
 * SP Query Object
 * Used to store and then run complex queries against a sharepoint list.
 *
 * Note: Querys are executed strictly from left to right and do not currently support nesting.
 */
class QueryObjectService {

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
	 * @param string $list_name List to Query
	 * @param \Thybag\SharePointAPI $api Reference to SP API
	 */
	public function __construct ($list_name, \Thybag\SharePointAPI $api) {
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
	 * @throws	\Exception	Thrown if $test is unreconized
	 */
	private function addQueryLine ($rel, $col, $test, $value) {
		// Check tests are usable
		if (!in_array($test, array('!=', '>=', '<=', '<', '>', '='))) {
			throw new \Exception('Unreconized query parameter. Please use <,>,=,>=,<= or !=');
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