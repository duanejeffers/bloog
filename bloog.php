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
define('ANCHOR', '<a href="%s" class="%s">%s</a>');
define('DEV_LOG', TRUE);
define('DEV_LOG_LOC', '/var/log/bloog.log');

require_once('vendor/autoload.php');

use \Michelf\MarkdownExtra;

// functions:

function logme() {
	if(!DEV_LOG) { return; }
	$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
	$arr = current($backtrace);
	$log = var_export(func_get_args(), true);
	file_put_contents(DEV_LOG_LOC, 'Line: ' . $arr['line'] . ' ' . $log . "\n\n", FILE_APPEND);
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

function path_check($path) {
	if(($realpath = realpath($path)) !== FALSE) {
		return $realpath;
	}
	return $path;
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
		return FALSE;
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

class bContent {
	private $_fp = NULL;
	protected $cfg;
	protected $_content_path = NULL;
	protected $_title = NULL;
	protected $_author = NULL;
	protected $_publishdate = NULL;
	protected $_publishtimestamp = NULL;
	protected $_published = NULL;
	protected $_comments = TRUE;
	protected $_breadcrumbs = TRUE;
	protected $_listed = FALSE; // If true, content will show in list view.
	protected $_isfile = FALSE;

	private function renderMdStr($string) {
		return MarkdownExtra::defaultTransform($string);
	}

	private function fmtPublishDate() {
		if(!is_null($this->_publishdate)) {
			$time = strtotime($this->_publishdate);
		} elseif(!is_null($this->_publishtimestamp)) {
			$time = $this->_publishtimestamp;
		} else {
			$time = filectime($this->_content_path);
		}

		$this->_publishdate = date($this->cfg->get('date_format'), $time);
		$this->_publishtimestamp = $time;
	}

	public function strBool($opt) {
		if(strtoupper($opt) === 'TRUE' || (is_bool($opt) && $opt)) {
			return TRUE;
		}
		return FALSE;
	}

	public function __construct(bConfig $cfg = NULL, $path) {
		$this->cfg = $cfg;
		$this->_content_path = $path;
		// Check to see if content is available:
		$this->_isfile = is_file($this->_content_path);
		if(($addext = is_file($this->_content_path . CONT_EXT)) ||
			$this->isContent()) {
			$fp = fopen($this->_content_path . ($addext ? CONT_EXT : ''), 'r');
			
			while($line = fgets($fp)) {
				if(strpos($line, '---') !== FALSE) { 
					break;
				}
				$line = trim(trim($line), '#');
				list($key, $setting) = explode(':', $line, 2);
				call_user_func(array($this, 'set'), trim($key), trim($setting));
			}
			$this->_fp = $fp;
			$this->fmtPublishDate();

			if(!$addext) {
				$this->_content_path = str_replace(array(strtoupper(CONT_EXT), strtolower(CONT_EXT)), '', $this->_content_path);
			} else {
				$this->_isfile = $addext;
			}
		} else {
			return FALSE;
		}
	}

	public function get($name) {
		$name = '_' . strtolower($name);
		if(in_array($name, array_keys(get_object_vars($this)))) {
			return $this->renderMdStr($this->$name);
		}
	}

	public function set($name, $value) {
		$name = '_' . strtolower($name);
		if(in_array($name, array_keys(get_object_vars($this)))) {
			$this->$name = $value;
			return TRUE;
		}
		return FALSE;
	}

	public function render($teaser = FALSE) {
		$return = '';
		$teaser_break = $this->cfg->get('teaser_break');
		while($line = fgets($this->_fp)) {
			if(trim($line) == $teaser_break) {
				if($teaser) { break; } else { continue; }
			}
			$return .= $line;
		}
		return $this->renderMdStr($return);
	}

	public function isPublished() {
		if((!is_null($this->_publishdate) && (time() > strtotime($this->_publishdate))) || 
			$this->strBool($this->_published)) {
			return TRUE;
		}
		return FALSE;
	}

	public function isContent() {
		return $this->strBool($this->_isfile);
	}

	public function hasBreadcrumbs() {
		return $this->strBool($this->_breadcrumbs);
	}

	public function hasComments() {
		return $this->strBool($this->_comments);
	}

	public function isListed() {
		return $this->strBool($this->_listed);
	}

	public function getTimestamp() {
		return $this->_publishtimestamp;
	}

	public function getPublishDate() {
		return $this->_publishdate;
	}

	public function getUrl() {
		return str_replace($this->cfg->get('bloog_content'), '', $this->_content_path);
	}

	public function __destruct() {
		fclose($this->_fp);
	}
}

class bView {
	protected $_cfg;
	protected $_content;
	protected $_title = array();
	protected $_js = array();
	protected $_css = array();

	public function __construct($cfg) {
		$this->_cfg = $cfg;
	}

	public function setContent($parser, $content_arr = array()) {
		$this->_content = $this->parse($parser, $content_arr);
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
			$template = $this->_cfg->get($template_type);
			switch(gettype($template)) {
				case 'object':
					return call_user_func_array($template, array($data, self));
					break;
				case 'string':
					$keys = array_map(function($val) { return '%%' . $val . '%%'; }, array_keys($data));
					return str_replace($keys, array_values($data), $this->_cfg->get($template_type));
					break;
			}
		}
		return NULL;
	}

	public function render() {
		$data = array(
			'title'       => $this->getTitle(),
			'description' => $this->_cfg->get('site_description'),
			'author'	  => $this->_cfg->get('site_author'),
			'content'	  => $this->_content,

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

		$this->req_path = path_check($this->cfg->get('bloog_content') . $this->req_uri);

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
		$content_list = rscandir($this->req_path);
		logme($content_list);

		$list = array();

		foreach ($content_list as $content_file) {
			$content = new bContent($this->cfg, $content_file);
			logme($content, $content->isListed(), $content->isPublished());
			if(!$content->isListed() ||
			   !$content->isPublished()) { 
				unset($content); 
				continue; 
			}
			//logme($content);
			$time = $content->getTimestamp();
			$list[$time] = $content;
			unset($content);
		}

		// sorting:
		if($this->cfg->get('post_list_order') == 'oldest') {
			ksort($list);
		} else { //newest is default
			krsort($list);
		}

		$postcount = $this->cfg->get('post_list_count');
		$current_page = $this->req->getReqVar('page');
		if($current_page === FALSE)
			$current_page = 1;
		else
			(int) $current_page;

		$listcount = count($list);
		$list = array_slice($list, (($current_page - 1) * $postcount), ($postcount === 0 ? NULL : $postcount), true);

		$page_list = array();
		foreach ($list as $key => $content) {
			$page_list[] = $this->view->parse('teaser', array(
				'link'  	     => sprintf(ANCHOR,
												$content->getUrl(),
												$this->cfg->get('teaser_link_class'),
												$this->cfg->get('teaser_link_text')),
				'title' 	     => $content->get('title'),
				'author'		 => $content->get('author'),
				'publish_date'   => $content->getPublishDate(),
				'teaser_content' => $content->render(TRUE),
				'url'			 => $content->getUrl(),
			));
		}
		unset($list); //no longer need the list.

		if($listcount > count($page_list)) {
			$next_link = sprintf(ANCHOR,
								 $this->req_uri . '?page=' . $current_page++,
								 $this->cfg->get('pager_next_class'),
								 $this->cfg->get('pager_next_text'));
		} else
			$next_link = NULL;

		if($current_page < 1) {
			$prev_link = sprintf(ANCHOR,
								 $this->req_uri . '?page=' . $current_page--,
								 $this->cfg->get('pager_prev_class'),
								 $this->cfg->get('pager_prev_text'));
		} else
			$prev_link = NULL;

		$this->view->setContent('list', array(
			'teaser_list' => implode($page_list),
			'prev_link'   => $prev_link,
			'next_link'	  => $next_link,
		));
	}

	public function viewAction() {
		$content = new bContent($this->cfg, $this->req_path);
		if(!$content->isContent())
			return $this->errorAction();

		// This is a content action.

		$this->view->setContent('post', array(
			'link'  	     => sprintf(ANCHOR,
											$content->getUrl(),
											$this->cfg->get('teaser_link_class'),
											$this->cfg->get('teaser_link_text')),
			'title' 	     => $content->get('title'),
			'author'		 => $content->get('author'),
			'publish_date'   => $content->getPublishDate(),
			'post_content'   => $content->render(),
			'url'			 => $content->getUrl(),
		));

	}

	public function errorAction() {
		$this->view->setTitle($this->cfg->get('title_error'));

		$this->view->setContent('error');
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
		logme($cfg);
		if(is_file($rootcfg)) {
			$cfg->mergeConfigFile($rootcfg);
		}
		logme($cfg);
		$this->cfg = $cfg;
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
			logme('Calling Root');
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
    <style type="text/css">
    	.container {
    		margin: 10px auto;
    	}
    </style>
  </head>
  <body>
  	<div class="container">
  	%%content%%
  	</div>
  	<script src="//code.jquery.com/jquery.js"></script>
  	<script src="//netdna.bootstrapcdn.com/bootstrap/3.0.0/js/bootstrap.min.js"></script>
  </body>
</html>
BOL;

$post_display = <<<BOL
<div class="row">
	<div class="col-md-12">
		<h1>%%title%%</h1>
		<p class="lead">%%author%%</p>
		<hr>
		%%post_content%%
		<hr>
		<div class="row">
			<div class="col-md-12 published"><p><span class="glyphicon glyphicon-time"></span> Posted on %%publish_date%%</p></div>
		</div>
		<hr>
	</div>
</div>
BOL;

$teaser_display = <<<BOL
<div class="row">
	<div class="col-md-12">
		<h1><a href="%%url%%">%%title%%</a></h1>
		<hr>
		%%teaser_content%%
		<hr>
		<div class="row">
			<div class="col-md-10 published"><span class="glyphicon glyphicon-time"></span> <small>Posted on %%publish_date%%</small></div>
			<div class="col-md-2 readmore pull-right">%%link%%</div>
		</div>
		<hr>
	</div>
</div>
BOL;

$list_display = <<<BOL
<div class="row">
	<div class="col-md-12">
	%%teaser_list%%
	<ul class="pager">
		<li class="previous">%%prev_link%%</li>
		<li class="next">%%next_link%%</li>
	</ul>
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
	'bloog_path' 		   => realpath($_SERVER['DOCUMENT_ROOT']),
	'bloog_content' 	   => realpath($_SERVER['DOCUMENT_ROOT'] . '/blogcontent'),
	'cache_enable' 		   => FALSE,
	'cache_prefix' 		   => 'bloog',
	'cache_ttl' 		   => 3600,
	'date_format'		   => 'l F j, Y \a\t h:i:s a',
	'teaser_break'		   => '[teaser_break]',
	'teaser_link_text'	   => 'Read More <span class="glyphicon glyphicon-chevron-right"></span>',
	'teaser_link_class'	   => 'btn btn-primary',
	'template_layout' 	   => $layout,
	'template_teaser' 	   => $teaser_display,
	'template_list'   	   => $list_display,
	'template_post'   	   => $post_display,
	'template_error'	   => $error_display,
	'title_sitename'  	   => 'bloog v0.1',
	'title_sitename_affix' => 'postfix', // options: prefix, postfix
	'title_separator' 	   => ' :: ',
	'title_error'		   => 'Whoops!',
	'site_description'     => "Simple Blog using bloog",
	'site_author' 		   => 'bloog',
	'post_list_count'      => 5,
	'post_list_order' 	   => 'newest', // options: newest, oldest
	'post_list_readmore'   => 'Read More <span class="glyphicon glyphicon-chevron-right"></span>',
	'post_breadcrumbs'     => TRUE,
	'pager_next_text'	   => 'Older <span class="glyphicon glyphicon-chevron-right"></span>',
	'pager_next_class'	   => '',
	'pager_prev_text'	   => '<span class="glyphicon glyphicon-chevron-left"></span> Newer',
	'pager_prev_class'	   => '',
	'view_js'			   => array(array('src' => '//code.jquery.com/jquery.js'),
									array('src' => '//netdna.bootstrapcdn.com/bootstrap/3.0.0/js/bootstrap.min.js')),
	'view_link'			   => array('//netdna.bootstrapcdn.com/bootstrap/3.0.0/css/bootstrap.min.css'),
	'view_style'		   => array(),
)));

echo $bloog->render();