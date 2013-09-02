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
define('DOC_PATH', realpath($_SERVER['DOCUMENT_ROOT']));
define('BLOOG_CONTENT', $_SERVER['DOCUMENT_ROOT'] . '/blogcontent');
define('CONT_EXT', '.md');
define('DEV_LOG', TRUE);
define('DEV_LOG_LOC', '/var/log/bloog.log');

require_once('vendor/autoload.php');

use \Michelf\MarkdownExtra;

// functions:

function logme() {
	if(!DEV_LOG) { return; }
	if(!isset($log_items)) {
		$log_items = array();
	}

	if(func_num_args() > 0) {
		$log_items = array_merge($log_items, func_get_args());
	} elseif(func_num_args() === 0) {
		$log = var_export($log_items, true);
		file_put_contents(DEV_LOG_LOC, $log . "\n", FILE_APPEND);
	}
}

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

function bcache($cache_name, $callback, $ttl = 3600) {
	if(($data = apc_fetch($cache_name)) === FALSE) {
		$data = call_user_func($callback);
		if(!is_null($data)) {
			apc_add($cache_name, $data, $ttl);
		}
	}

	return $data;
}

function bcache_invalid() {
	foreach (func_get_args() as $cache_name) {
		apc_delete($cache_name);
	}
}

abstract class bAbstract {
	protected $cfg;

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

	abstract public function init();

	public function __construct(bConfig $cfg = NULL) {
		$args = func_get_args();
		$this->cfg = array_shift($args);

		call_user_func_array(array($this, 'init'), $args);

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

class bRequest extends bAbstract {
	protected $_server;
	protected $_get;
	protected $_post;

	public function init() {
		foreach(array('_server', '_get', '_post') AS $val) {
			$global = strtoupper($val);
			$this->$val = $$global;
		}
	}
}

class bContent extends bAbstract {
	protected $_title = NULL;
	protected $_content = NULL;
	protected $_contentmd = NULL;
	protected $_author = NULL;
	protected $_publishdate = NULL;
	protected $_comments = TRUE;
	protected $_page = FALSE; // by default, we need to specify that it isn't a page.

	public function init($uri) {
		// Check to see if content is available:
		if(($blog = is_file(BLOOG_CONTENT . $uri . CONT_EXT)) ||
		   ($page = is_file(BLOOG_CONTENT . PATH . 'pages' . $uri . CONT_EXT))) {
			$this->_page = $page;

			$this->_content = file_get_contents(BLOOG_CONTENT . ($page ? PATH . 'pages': '') . $uri . CONT_EXT);

			$settings = parse_ini_string(strstr($this->_content, '---', true));
			foreach ($settings as $key => $value) {
				$func = '_' . strtolower($key);

				$this->$func = $value;
			}

			$this->_content = substr(strstr($this->_content, '---'), 3);
		}
	}

	public function render() {
		$this->_contentmd = MarkdownExtra::defaultTransform($this->_content);
		return $this;
	}
}

class bView {

}

class bController {
	protected $view;

	public function __construct() {

	}

	public function indexAction() {

	}

	public function viewAction() {

	}
}

class bRouter {
	protected $cfg;
	protected $req;
	protected $routes = array();

	public function __construct(bConfig $cfg, bRequest $req) {
		$this->cfg = $cfg;
		$this->req = $req;

		return $this;
	}

	public function path($path, $callback) {
		$this->routes[$path] = $callback;
		return $this;
	}

	public function render() {
		$req_uri = $this->req->_server['REQUEST_URI'];
		logme($req_uri);
	}
}

class bloog {
	protected $cfg;

	public function __construct(bConfig $cfg) {
		$rootcfg = $cfg->get('bloog_path') . PATH . BLOOG_CFG;
		if(is_file($rootcfg)) {
			$cfg->mergeConfigFile($rootcfg);
		}
		$this->cfg = $cfg;
		logme($cfg);
	}

	public function render() {
		$router = new bRouter($this->cfg, new bRequest());
		return $router->render();

		$router->path('/', function($cfg, $req) {

		});
	}
}

$layout = <<<BOL
<!DOCTYPE html>
<html lang="en">
  <head>
    <title>%%title%%</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="%%description%%">
    <meta name="author" content="%%author%%">
  </head>
  <body>
  	<div class="container">
  	%%content%%
  	</div>
  </body>
</html>
BOL;

$bloog = new bloog(new bConfig(array(
	'bloog_install_path' => realpath(dirname(__FILE__)),
	'bloog_path' => dirname(__FILE__),
	'bloog_content' => realpath($_SERVER['DOCUMENT_ROOT'] . '/blogcontent'),
	'enable_cache' => FALSE,
	'layout' => $layout,
	'site_title' => 'bloog v0.1',
	'site_url' => $_SERVER['']
)));

$bloog->render();

logme();