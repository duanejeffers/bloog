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
		if(isset($this->_configArr[$key])) {
			return $this->_configArr[$key];
		}
		return FALSE;
	}

	public function isOpt($key) {
		return array_key_exists($key, $this->_configArr);
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
	protected $_breadcrumbs = TRUE;
	protected $_blog = TRUE;
	protected $_page = FALSE; // by default, we need to specify that it isn't a page.

	public function init() {
		$uri = func_get_arg(0);
		$dir = $this->cfg->get('bloog_content');
		// Check to see if content is available:
		if(($blog = is_file($dir . $uri . CONT_EXT)) ||
		   ($page = is_file($dir . PATH . 'pages' . $uri . CONT_EXT))) {
		   	$this->_blog = $blog;
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

	public function isBlog() {
		return ($this->_blog == TRUE ? TRUE : FALSE);
	}
}

class bView {
	protected $_cfg;
	protected $_content;
	protected $_title = array();

	public function __construct($cfg) {
		$this->_cfg = $cfg;
	}

	public function setContent($content) {
		$this->_content = $content;
		return $this;
	}

	public function setTitle($title) {
		$this->_title[] = $title;
		return $this;
	}

	public function getTitle() {
		return implode($this->_title, $this->_cfg->get('title_separator'));
	}

	public function parse($type, $data = array()) {
		$template_type = 'template_' . $type;
		if($this->_cfg->isOpt($template_type)) {
			return str_replace(array_keys($data), array_values($data), $this->_cfg->get($template_type));
		}
		return NULL;
	}

	public function render() {
		$data = array(
			'%%title%%'       => $this->getTitle(),
			'%%description%%' => $this->_cfg->get('site_description'),
			'%%author%%'	  => $this->_cfg->get('site_author'),
			'%%content%%'	  => $this->_content,
		);
		return $this->parse('layout', $data);
	}
}

class bController {
	protected $view;
	protected $req;
	protected $req_uri;
	protected $req_path;
	protected $cfg;

	public function __construct(bConfig $cfg, $req = NULL) {
		$this->cfg = $cfg;
		$this->req = $req;
		if(is_string($req))
			$this->req_uri = $req;
		elseif($req instanceof bRequest)
			$this->req_uri = $this->req->getServer('REQUEST_URI');

		$this->req_path = $this->cfg->get('bloog_content') . $this->req_uri;

		$this->view = new bView($this->cfg);

		if($this->cfg->get('title_sitename_affix') == 'prefix') {
			$this->view->setTitle($this->cfg->get('title_sitename'));
		}
		return $this;
	}

	public function defaultAction() {
		// Now we need to specify if this is a folder or file.
		if(is_dir($this->req_path)) {
			$this->indexAction();
		} else { // this is not a directory view.
			$this->viewAction();
		}
	}

	public function indexAction() {
		// We need to scan the directory of content for the current url.

	}

	public function viewAction() {
		$req_uri = $this->req->getServer('REQUEST_URI');
		$content = new bContent($this->cfg, $req_uri);
		if(!$content->isBlog() && !$content->isPage())
			return $this->errorAction();

		// This is a content action.


	}

	public function errorAction() {

	}

	public function render() {
		if($this->cfg->get('title_sitename_affix') == 'postfix') {
			$this->view->setTitle($this->cfg->get('title_sitename'));
		}

		return $this->view->render();

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
		$req_uri = $this->req->getServer('REQUEST_URI');
		if(array_key_exists($req_uri, $this->routes)) {
			return call_user_func($this->routes[$req_uri], $this->cfg, $this->req);
		} elseif(array_key_exists('*', $this->routes)) {
			// run the default renderer.
			return call_user_func($this->routes['*'], $this->cfg, $this->req);
		} else {
			// throw an error.
		}
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
		$content_path = $cfg->get('bloog_content');
		logme($cfg, $content_path);
	}

	public function render() {
		$router = new bRouter($this->cfg, new bRequest());

		$router->path('/bloogcacheupdate', function($cfg, $req) {
			// The update functionality is for recreating caches.
			if($cfg->get('cache_enable') == FALSE) {
				$controller = new bController($cfg, $req);
				$controller->errorAction();
				return $controller->render();
			}
		});

		$router->path('/', function($cfg, $req) {
			$controller = new bController($cfg, $req);
			$controller->indexAction();
			return $controller->render();
		});

		$router->path('*', function($cfg, $req) {
			$controller = new bController($cfg, $req);
			$controller->defaultAction();
			return $controller->render();
		});

		if($this->cfg->get('cache_enable') == TRUE) {
			$cache_title = $this->cfg->get('cache_prefix') . $req_uri;
			return bcache($cache_title, array($router, 'render'), $this->cfg->get('cache_ttl'));
		} else
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

$error_display = <<<BOL
<div class="jumbotron">
	<h1>Whoops!</h1>
	<p>It looks like the content you're looking for doesn't exist.</p>
	<p><a class="btn btn-primary btn-lg" onclick="window.history.back();">Go Back</a></p>
</div>
BOL;

$bloog = new bloog(new bConfig(array(
	'bloog_install_path'   => realpath(dirname(__FILE__)),
	'bloog_path' 		   => dirname(__FILE__),
	'bloog_content' 	   => realpath($_SERVER['DOCUMENT_ROOT'] . '/blogcontent'),
	'cache_enable' 		   => FALSE,
	'cache_prefix' 		   => 'bloog',
	'cache_ttl' 		   => 3600,
	'template_layout' 	   => $layout,
	'template_teaser' 	   => $teaser_display,
	'template_list'   	   => $list_display,
	'template_post'   	   => $post_display,
	'template_error'	   => $error_display,
	'title_sitename'  	   => 'bloog v0.1',
	'title_sitename_affix' => 'postfix', // options: prefix, postfix
	'title_separator' 	   => ' :: ',
	'site_description'     => "Simple Blog using bloog",
	'site_author' 		   => 'bloog',
	'post_list_pagecount'  => 5,
	'post_list_order' 	   => 'newest',
	'post_breadcrumbs'     => TRUE,
)));

echo $bloog->render();