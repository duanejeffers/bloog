<?php

	/** bloog v0.1
		Written By Duane Jeffers <duane@jeffe.rs>

		Uses markdown parsing to display blog posts.
	**/

/* if(version_compare(PHP_VERSION, '5.3.0', '>=')) {
	die('incorrect php version for this script. Must be 5.3 or higher.');
} */

define('BLOOG_CFG', '.bloogconfig.php');
define('PATH', '/');
define('BLOOG_CONTENT', $_SERVER['DOCUMENT_ROOT'] . '/blogcontent');
define('CONT_EXT', '.md');

require_once('vendor/autoload.php');

// functions:
function rscandir($dir) {
	$scan = scandir($dir);
	$result = array();
	foreach($scan as $val) {
		if(substr($val, 0, 1) == '.') { continue; }
		if(is_file($dir . PATH . $val)) { $result[] = $dir . PATH . $val; continue; }
		foreach (rscandir($dir . PATH . $val) as $val) {
			$result[] = $val;
		}
	}

	return $result;
}

abstract class bAbstract {
	public function __get($name) {
		$name = '_' . strtolower($name);
		if(in_array($name, array_keys(get_object_vars($this)))) {
			return $this->$name;
		}
	}

	public function __set($name, $value) {
		$name = '_' . strtolower($name);
		if(in_array($name, array_keys(get_object_vars($this)))) {
			$this->$name = $value;
		}
		return $this;
	}
}

// config class.
class bConfig {
	protected $_configArr = array();

	public function merge($arr1, $arr2) {
		$merge = $arr1;
		foreach($arr2 as $key => $val) {
			if(is_array($val) &&
			   isset($merge[$key]) &&
			   is_array($merge[$key])) {
				$merge[$key] = $this->merge($merge[$key], $val);
			} else {
				$merge[$key] = $val;
			}
		}
		return $merge;
	}

	public function mergeConfigArr($array) {
		$this->_configArr = $this->merge($this->_configArr, $array);
		return $this;
	}

	public function mergeConfigFile($file) {
		$cfgArr = include_once($file);
		return $this->mergeConfigArr($cfgArr);
	}

	public function get($key) {
		if(isset($_configArr[$key])) {
			return $_configArr[$key];
		}
		return FALSE;
	}

	public function __construct($cfg) {
		$this->mergeConfigArr($cfg);
	}
}

class bRequest {

}

class bContent extends bAbstract {
	protected $_title = NULL;
	protected $_content = NULL;
	protected $_contentmd = NULL;
	protected $_author = NULL;
	protected $_publishdate = NULL;
	protected $_comments = TRUE;
	protected $_page = FALSE; // by default, we need to specify that it isn't a page.

	public function __construct($uri) {
		// Check to see if content is available:
		if(($blog = is_file(BLOOG_CONTENT . $uri . CONT_EXT)) ||
		   ($page = is_file(BLOOG_CONTENT . PATH . 'pages' . $uri . CONT_EXT))) {
			$this->_page = $page;

			$this->_content = file_get_contents(BLOOG_CONTENT . ($page ? PATH . 'pages': '') . $uri . CONT_EXT);

			$settings = parse_ini_string(strstr($this->_content, '---', true));
			foreach ($settings as $key => $value) {
				$func = 'set' . ucfirst($key);

				$this->$func($value);
			}

			$this->_content = substr(strstr($this->_content, '---'), 3);
		}
	}
}


class bloog {
	protected $cfg;

	public function __construct(bConfig $cfg) {

	}

	public function render() {

	}
}

$content = new bContent($_SERVER['REQUEST_URI']);
var_dump($content);
