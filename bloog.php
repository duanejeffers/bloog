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

abstract class bAbstract {
	protected $cfg;

	public function __construct() {
		$args = func_get_args();
		$this->cfg = array_shift($args);

		return call_user_func_array(array($this, 'init'), $args);
	}

	abstract protected function init();
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

	public function set($key, $value) {

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

	public function getReqUri() {
		$parse = parse_url($this->_server['REQUEST_URI']);

		return $parse["path"];
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
	private $_fp = NULL;
	protected $_content_path = NULL;
	protected $_title = NULL;
	protected $_author = NULL;
	protected $_content = NULL;
	protected $_publishdate = NULL;
	protected $_publishtimestamp = NULL;
	protected $_published = NULL;
	protected $_comments = TRUE;
	protected $_breadcrumbs = TRUE;
	protected $_listed = FALSE; // If true, content will show in list view.
	protected $_isfile = FALSE;
	protected $_content_options = array();

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

	protected function init() {
		$this->_content_path = func_get_arg(0);
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
				$objOpt = $this->set(($key = trim($key)), ($setting = trim($setting)));
				if(!$objOpt) {
					$this->_content_options[$key] = $setting;
				}
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
				$line = $this->cfg->get('teaser_html');
				if($teaser) { break; }
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
		if(is_resource($this->_fp))
			fclose($this->_fp);
	}
}

class bViewHelper extends bAbstract {
	protected $_title = array();
	protected $_link = array();
	protected $_style = array();
	protected $_script = array();

	protected function init() {
		$this->_link = $this->cfg->get('view_link');
		$this->_style = $this->cfg->get('view_style');
		$this->_script = $this->cfg->get('view_script');
	}

	public function addTitle($title) {
		$this->_title[] = $title;
	}

	public function getTitle() {
		return implode($this->_title, $this->cfg->get('title_separator'));
	}

	public function addStyle($style) {
		$this->_style[] = $style;
		return $this;
	}

	public function addLink($link) {
		$this->_link[] = $link;
		return $this;
	}

	public function addScript($src = NULL, $code = NULL) {
		if(!is_null($src)) {
			$this->_script[] = array('src' => $src);
		} elseif (!is_null($code)) {
			$this->_script[] = array('code' => $code);
		}
		return $this;
	}

	public function renderer($type, $format) {
		$return = array();
		foreach ($type as $value) {
			$return[] = sprintf($format, $value);
		}
		return implode("\n\t", $return);
	}

	public function renderLink() {
		return $this->renderer($this->_link, '<link rel="stylesheet" href="%s">');
	}

	public function renderStyle() {
		return $this->renderer($this->_style, "<style type=\"text/css\">\n %s \n\t</style>");
	}

	public function renderScript() {
		$return = array();
		foreach($this->_script as $script) {
			$return[] = sprintf('<script%s>%s</script>',
							  (isset($script['src']) ? ' src="' . $script['src'] . '"' : NULL),
							  (isset($script['code']) ? $script['code'] : NULL));
		}
		return implode("\n\t", $return);
	}

	public function renderBreadcrumbs() {

	}
}

class bView extends bAbstract {
	protected $_content;
	protected $_viewhelper;

	protected function init() {
		$this->_viewhelper = new bViewHelper($this->cfg);
	}

	public function setContent($parser, $content_arr = array()) {
		$this->_content = $this->parse($parser, $content_arr);
		return $this;
	}

	public function parse($type, $data = array()) {
		$template_type = 'template_' . $type;
		if($this->cfg->isOpt($template_type)) {
			$template = $this->cfg->get($template_type);
			switch(gettype($template)) {
				case 'object':
					return call_user_func_array($template, array($data, $this));
					break;
				case 'string':
					$keys = array_map(function($val) { return '%%' . $val . '%%'; }, array_keys($data));
					return str_replace($keys, array_values($data), $this->cfg->get($template_type));
					break;
			}
		}
		return NULL;
	}

	public function render() {
		$data = array(
			'title'       => $this->getHelper()->getTitle(),
			'description' => $this->cfg->get('site_description'),
			'author'	  => $this->cfg->get('site_author'),
			'content'	  => $this->_content,

		);
		return $this->parse('layout', $data);
	}

	public function getHelper() {
		return $this->_viewhelper;
	}
}

class bControllerSimple extends bAbstract {
	protected $view;
	protected $req;
	protected $req_uri;
	protected $req_path;

	protected function init() {
		$this->req = func_get_arg(0);
		if(is_string($this->req))
			$this->req_uri = $req;
		elseif($this->req instanceof bRequest)
			$this->req_uri = $this->req->getReqUri();

		$this->req_path = path_check($this->cfg->get('bloog_content') . $this->req_uri);

		$this->view = new bView($this->cfg);

		if($this->cfg->get('title_sitename_affix') == 'prefix') {
			$this->view->getHelper()->addTitle($this->cfg->get('title_sitename'));
		}
		return $this;
	}

	public function getView() {
		return $this->view;
	}

	public function getReq() {
		return $this->req;
	}

	public function getReqUri() {
		return $this->req_uri;
	}

	public function getReqPath() {
		return $this->req_path;
	}

	public function genPostList() {
		// We need to scan the directory of content for the current url.
		$content_list = rscandir($this->req_path);
		$list = array();

		foreach ($content_list as $content_file) {
			$content = new bContent($this->cfg, $content_file);
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

		return $list;
	}
}

class bController extends bControllerSimple {
	public function defaultAction() {
		// Now we need to specify if this is a folder or file.
		if(is_dir($this->req_path)) {
			$this->indexAction();
		} else { // this is not a directory view.
			$this->viewAction();
		}
	}

	public function indexAction() {
		$list = $this->genPostList();
		$postcount = $this->cfg->get('post_list_count');
		$current_page = $this->req->getReqVar('page');
		if($current_page === FALSE)
			$current_page = 1;
		else
			$current_page = (int) $current_page;

		$listcount = count($list);
		$list = array_slice($list, (($current_page - 1) * $postcount), ($postcount === 0 ? NULL : $postcount), true);

		$page_list = array();
		foreach ($list as $key => $content) {
			$page_list[] = $this->view->parse('teaser', array(
				'link'  	     => sprintf($this->cfg->get('anchor_format'),
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

		if($listcount > count($page_list) && $listcount > (($current_page - 1) * $postcount)) {
			$next_link = sprintf($this->cfg->get('anchor_format'),
								 $this->req_uri . '?page=' . $current_page++,
								 $this->cfg->get('pager_next_class'),
								 $this->cfg->get('pager_next_text'));
		} else
			$next_link = NULL;

		if($current_page > 1) {
			$prev_link = sprintf($this->cfg->get('anchor_format'),
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
		if(!$content->isContent() || !$content->isPublished())
			return $this->errorAction();

		// This is a content action.
		$this->view->setContent('post', array(
			'link'  	     => sprintf($this->cfg->get('anchor_format'),
										$content->getUrl(),
										$this->cfg->get('teaser_link_class'),
										$this->cfg->get('teaser_link_text')),
			'title' 	     => $content->get('title'),
			'author'		 => $content->get('author'),
			'publish_date'   => $content->getPublishDate(),
			'post_content'   => $content->render(),
			'url'			 => $content->getUrl(),
			'comments'		 => $content->hasComments(),
			'breadcrumbs'	 => $content->hasBreadcrumbs(),
		));

	}

	public function rssAction() {


	}

	public function errorAction() {
		$this->view->getHelper()->addTitle($this->cfg->get('title_error'));

		$this->view->setContent('error');
	}

	public function render() {
		if($this->cfg->get('title_sitename_affix') == 'postfix') {
			$this->view->getHelper()->addTitle($this->cfg->get('title_sitename'));
		}

		return $this->view->render();
	}
}

class bRouter extends bAbstract {
	protected $req;
	protected $routes = array();

	public function init() {
		$this->req = func_get_arg(0);

		return $this;
	}

	public function path($path, $callback) {
		$this->routes[$path] = $callback;
		return $this;
	}

	public function render() {
		$req_uri = $this->req->getReqUri();
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

class bloog extends bAbstract {
	protected function init() {
		$rootcfg = $this->cfg->get('bloog_path') . PATH . BLOOG_CFG;
		if(is_file($rootcfg)) {
			$this->cfg->mergeConfigFile($rootcfg);
		}
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

		$router->path('/rss', function($cfg, $req) {
			$controller = new bController($cfg, '/'); // Force use of root.


			return $return;
		});

		$router->path('*', function($cfg, $req) {
			$controller = new bController($cfg, $req);
			$controller->defaultAction();
			return $controller->render();
		});

		// Moving this to the bottom of the stack in case the config overwrites any of the defaults.
		foreach($this->cfg->get('add_paths') as $path => $callback) {
			$router->path($path, $callback);
		}

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
	'anchor_format'		   => '<a href="%s" class="%s">%s</a>',
	'add_paths'			   => array(),
	'bloog_path' 		   => realpath($_SERVER['DOCUMENT_ROOT']),
	'bloog_content' 	   => realpath($_SERVER['DOCUMENT_ROOT'] . '/blogcontent'),
	'bloog_webpath'		   => $_SERVER['SERVER_NAME'],
	'cache_enable' 		   => FALSE,
	'cache_prefix' 		   => 'bloog',
	'cache_ttl' 		   => 3600,
	'date_format'		   => 'l F j, Y \a\t h:i:s a',
	'teaser_break'		   => '[teaser_break]',
	'teaser_link_text'	   => 'Read More <span class="glyphicon glyphicon-chevron-right"></span>',
	'teaser_link_class'	   => 'btn btn-primary',
	'teaser_html'		   => '<hr>', // Teaser html to replace the [teaser_break]
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
	'rss_enable'		   => FALSE,
	'post_list_count'      => 5,
	'post_list_order' 	   => 'newest', // options: newest, oldest
	'post_breadcrumbs'     => TRUE,
	'pager_next_text'	   => 'Older <span class="glyphicon glyphicon-chevron-right"></span>',
	'pager_next_class'	   => '',
	'pager_prev_text'	   => '<span class="glyphicon glyphicon-chevron-left"></span> Newer',
	'pager_prev_class'	   => '',
	'view_script'		   => array(array('src' => '//code.jquery.com/jquery.js'),
									array('src' => '//netdna.bootstrapcdn.com/bootstrap/3.0.0/js/bootstrap.min.js')),
	'view_link'			   => array('//netdna.bootstrapcdn.com/bootstrap/3.0.0/css/bootstrap.min.css'),
	'view_style'		   => array(),
)));

echo $bloog->render();