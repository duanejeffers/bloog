<?php

	/** bloog v0.1
		Written By Duane Jeffers <duane@jeffe.rs>

		Uses markdown parsing to display blog posts.
	**/

if(version_compare(PHP_VERSION, '5.3.0', '>=')) {
	die('incorrect php version for this script. Must be 5.3 or higher.');
}

define('BLOOP_CFG', '.bloopconfig.php');
define('PATH', '/');

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



class bloog {
	protected $cfg;

	public function __construct(bConfig $cfg) {

	}

	public function render() {

	}
}