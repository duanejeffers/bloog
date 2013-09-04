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
define('CONT_EXT', '.md');
define('DEV_LOG', TRUE);
define('DEV_LOG_LOC', '/var/log/bloog.log');

require_once('vendor/autoload.php');

use \Michelf\MarkdownExtra;

// functions:

function logme() {
	if(!DEV_LOG) { return; }
	$log = var_export(func_get_args(), true);
	file_put_contents(DEV_LOG_LOC, $log . "\n", FILE_APPEND);
}

function rscandir($dir, $inc_dir = FALSE) {
	$scan = scandir($dir);
	$result = array();
	foreach($scan as $val) {
		if(substr($val, 0, 1) == '.') { continue; }
		$c_path = $dir . PATH . $val;
		if(is_file($c_path) || (is_dir($c_path) && $inc_dir)) {
			$result[] = $c_path;
			if(is_file($c_path)) 
				continue; 
		}
		foreach (rscandir($c_path, $inc_dir) as $val) {
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

class bRequest {
	protected $_server;
	protected $_get;
	protected $_post;

	public function __construct() {
		$this->_server = $_SERVER;
		$this->_get = $_GET;
		$this->_post = $_POST;
	}

	public function get($type, $key = NULL) {
		if(is_null($key)) { return $type; }
		if(array_key_exists($key, $type)) {
			return $type[$key];
		}
	}

	public function getServer($key = NULL) {
		return $this->get($this->_server, $key);
	}

	public function getReqVar($key = NULL) {
		return $this->get($this->_get, $key);
	}

	public function getPostVar($key = NULL) {
		return $this->get($this->_post, $key);
	}

}

class bContent extends bAbstract {
	protected $_title = NULL;
	protected $_content = NULL;
	protected $_contentmd = NULL;
	protected $_teaser = NULL;
	protected $_teasermd = NULL;
	protected $_author = NULL;
	protected $_publishdate = NULL;
	protected $_comments = TRUE;
	protected $_page = FALSE; // by default, we need to specify that it isn't a page.

	public function init() {
		$uri = func_get_arg(0);
		$dir = $this->cfg->get('bloog_content');
		// Check to see if content is available:
		if(($blog = is_file($dir . $uri . CONT_EXT)) ||
		   ($page = is_file($dir . PATH . 'pages' . $uri . CONT_EXT))) {
			$this->_page = $page;

			$this->_content = file_get_contents($dir . ($page ? PATH . 'pages': '') . $uri . CONT_EXT);

			$settings = parse_ini_string(strstr($this->_content, '---', true));
			foreach ($settings as $key => $value) {
				$func = '_' . strtolower($key);

				$this->$func = $value;
			}

			$this->_content = substr(strstr($this->_content, '---'), 3);

			$this->parseTeaser();
		}
	}

	public function parseTeaser() {
		$this->_teaser = strstr($this->_content, '[teaser_break]');
		$this->_content = str_replace('[teaser_break]', '', $this->_content);
	}

	public function render() {
		$this->_contentmd = MarkdownExtra::defaultTransform($this->_content);
		return $this;
	}

	public function renderTeaser() {
		$this->_teasermd = MarkdownExtra::defaultTransform($this->_teaser);
		return $this;
	}

	public function isPublished() {
		if(!is_null($this->_publishdate) && (time() > strtotime($this->_publishdate))) {
			return TRUE;
		}
		return FALSE;
	}

	public function isPage() {
		return ($this->_page == TRUE ? TRUE : FALSE);
	}
}

class bView {

}

class bController {
	protected $view;
	protected $req;
	protected $cfg;

	public function __construct(bConfig $cfg, $req = NULL) {
		$this->cfg = $cfg;
		$this->req = $req;

		$this->view = new bView($this->cfg);
		return $this;
	}

	public function indexAction() {

	}

	public function viewAction() {

	}

	public function render() {

	}
}

class bRouter {
	protected $cfg;
	protected $req;
	protected $cache;
	protected $routes = array();

	public function __construct(bConfig $cfg, bRequest $req) {
		$this->cfg = $cfg;
		$this->req = $req;
		$this->cache = $this->cfg->get('enable_cache');

		return $this;
	}

	public function path($path, $callback) {
		$this->routes[$path] = $callback;
		return $this;
	}

	public function mainRender($req_uri) {
		if(array_key_exists($req_uri, $this->routes)) {
			return call_user_func($this->routes[$req_uri], $this->cfg, $this->req);
		} else {
			// return 404 error
		}
	}

	public function render() {
		$req_uri = $this->req->getServer('REQUEST_URI');
		// include caching code here.
		
		return $this->mainRender($req_uri);
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

		$router->path('/update', function($cfg, $req) {
			// The update functionality is for recreating caches.
		});

		$router->path('/', function($cfg, $req) {
			$controller = new bController($cfg, $req);
			$controller->indexAction();
			return $controller->render();
		});

		return $router->render();
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
    <link rel="stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.0.0/css/bootstrap.min.css">
  </head>
  <body>
  	<div class="container">
  	%%content%%
  	</div>
  	<script src="//netdna.bootstrapcdn.com/bootstrap/3.0.0/js/bootstrap.min.js"></script>
  </body>
</html>
BOL;

$post_display = <<<BOL

BOL;

$teaser_display = <<<BOL
<div class="row">
	<div class="col-md-12">
		<h1><a href="%%link%%">%%title%%</a></h1>
		<hr>
		%%teaser_content%%
		<hr>
		<div class="row">
			<div class="col-md-6 published"><span class="glyphicon glyphicon-time"></span>Published on %%publish_date%%</div>
			<div class="col-md-6 pull-right"><a class="btn" href="%%link%%>Read More <span class="glyphicon glyphicon-chevron-right"></span></a></div>
		</div>
		<hr>
	</div>
</div>
BOL;

$list_display = <<<BOL
<div class="row">
	<div class="col-md-12">
	%%teasers%%

	</div>
</div>
BOL;

$bloog = new bloog(new bConfig(array(
	'bloog_install_path' => realpath(dirname(__FILE__)),
	'bloog_path' => dirname(__FILE__),
	'bloog_content' => realpath($_SERVER['DOCUMENT_ROOT'] . '/blogcontent'),
	'enable_cache' => FALSE,
	'layout' => $layout,
	'site_title' => 'bloog v0.1',
	'post_list_pagecount' => 5,
	'post_list_order' => 'newest',
)));

$bloog->render();