<?php

/**
 * ConcordePHP 1.5 | No-nonsense PHP Controller and Micro Framework
 * 
 * Written by:
 *	G. de Mooij
 *	J. Schuttelaar 
 * 
 * Licensed:		
 *	New BSD License
 * 
 **/
class Concorde {

	/**
	* Factory closure; used to create instances
	* of controller classes.
	* @var closure
	*/
	protected static $factory = null;
	
	/**
	* List of services available through Concordia.
	* @var array
	*/
	protected static $services = array();

	/**
	* List of configuration variables available through Concordia.
	* @var array
	*/
	protected static $vars;

	/**
	* Dictionary array for i18n.
	* @var array
	*/
	protected static $dict;
	
	/**
	 * Contains parameters from GET request.
	 * @var array 
	 */
	protected $urlParams;
	
	/**
	 * Route table
	 * @var array 
	 */
	protected static $routes = array();

	/**
	* Contains the flat array of routes.
	* @var array
	*/	
	protected static $cascadedRoutes = array();
	
	/**
	* Simulates a request. Call this method before calling route() to
	* fake a request.
	* 
	* Example:
	* Concordia::sim('GET:/show/results'); -- simulates GET request to /show/results
	*
	* Example with POST data:
	* Concordia::sim('POST:/send/data', array('data'=>123));
	*
	* @param string $request
	* @param array  $postData
	*/
	public static function sim($request,$postData = array()) {
		list($_SERVER['REQUEST_METHOD'],$_SERVER['REQUEST_URI']) = explode(':',$request);
		$_POST = $postData;
	} 

	/**
	* Tunnels a request not supported by current browser implementations.
	* 
	* Example:
	* Concordia::tunnel('PUT,DELETE','_method'); 
	* -- allows you to tunnel PUT and DELETE requests through $_POST[_method]
	*
	* @param string $methods comma separated list of methods
	* @param string $mapping POST field used for tunneling
	*/
	public static function tunnel($methods,$mapping) {
		if (isset($_REQUEST[$mapping])) {
			$tunnel = $_REQUEST[$mapping];
			if (in_array($tunnel,explode(',',$methods))){
				$_SERVER['REQUEST_METHOD'] = $tunnel;
			}
		}
	}

	/**
	 * Router accepts a domain cascaded array.
	 * Each entry in the array represents a domain. Each key in the domain
	 * array should match a request method. Each value in the domain array
	 * should be an array containing mappings for URLs. Both URLs and Domains
	 * are matches using regex.
	 * 
	 * Usage (example):
	 * 
	 * Concordia::route(array(
	 *	'.*' => array(
	 *		'GET'=>array(
	 *			'/page/(\w+)'=>('Pagecontroller->title'),
	 *		),
	 *		'POST'=>array(
	 *			'/myform/(\d+)'=>'Test->myform',
	 *		)
	 *	),
	 * '(\w+).com'=>array(
	 *		'GET'=>array(
	 *			'/page/(\w+)'=>('PagecontrollerCom->title')
	 *		),
	 *	)		
	 *));
	 *
	 * 
	 * Tip: you can use Concordia as your Main Controller, in this case
	 * Concordia becomes your mini framework.
	 * 
	 * @param string $domain        The domain to be used for routing
	 * @param string $requestMethod You could pass: $_SERVER['REQUEST_METHOD']
	 * @param array  $map           Mapping configuration.
	 * 
	 */
	public static function route($map) {
		
		//Store the routing table
		self::$routes = $map;
		
		//Obtain variables
		$domain = $_SERVER['HTTP_HOST'];
		$url = $_SERVER['REQUEST_URI'];

		//keep query string (may start with ? or &)
		$pos = strpos($url,'?');
		if($pos === false) $pos = strpos($url,'&');
		if ($pos !== false) { 
			$url = substr($url,0,$pos);
		}

		$requestMethod = $_SERVER['REQUEST_METHOD'];
		
		$routes = array();
		$keys = array();
		
		//Scan array structure
		foreach($map as $key=>$value) {
			if (!is_array($value)) {
				throw new CException('Invalid Array structure, expected array but got: '.$value);
			}
			$firstElement = reset($value);
			if (!is_array($firstElement)) {
				$keys[] = $key;
			}
		}
		
		if (count($keys)) {
			$defaultDomain = array();
			foreach($keys as $key) {
				$defaultDomain[$key] = $map[$key];
				unset($map[$key]);
			}
		}
		
		//First add default domain routes, they can be overidden.
		if (isset($defaultDomain) && isset($defaultDomain[$requestMethod])) {
			$routes[] = $defaultDomain[$requestMethod];
		}


		//Find all domains that match
		foreach($map as $key=>$value) {
			if (self::matchHost($domain, $key)) {
				if (isset($value[$requestMethod])) {
					$routes[] = $value[$requestMethod];
				}
			}
		}
		$base = array(); 
		foreach($routes as $routeSet) {
			$base = self::cascadeRoutes($base,$routeSet);
		}
		
		self::$cascadedRoutes = $base;

		//Find a match
		$flagFoundMatch = false;
		foreach($base as $key=>$route) { 
			$key = str_replace('/', '\/', $key);
         		$key = '^' . $key . '\/?$';
			$matches = array();
			if ($flagFoundMatch = preg_match('/'.$key.'/i',$url,$matches)) {
				break;
			}
		}
		
		//Found a match
		if (!$flagFoundMatch) {
			$x = new CRouteNotFound('No route has been found for URL: '.$url.'.');
			$x->url = $url;
			throw $x;
		}

		//Starts with a /, then redirect
		if (strpos($route,'/')===0) {
			header('Location: '.$route);
			exit();
		}
		
		//Obtain call information
		$callInfo = explode('->', $route);
		if (count($callInfo)!==2) throw new CException('Invalid Call information: '.$route);
		list($className, $methodName) = $callInfo;
		
		//Can we find the class derived from the route call information?
		if (!class_exists($className)) throw new CException('Class not found: '.$className);
		
		//Create an instance of this class
		try {
			$instance = (self::$factory) ? self::$factory($className) : new $className; 
		}
		catch(Exception $e) {
			throw new CException('Unable to create instance of class: '.$className.':'.$e->getMessage());
		}
		
		//Can we access this method
		if (!method_exists($instance,$methodName)) throw new CException('Method does not exist: '.$className.' -> '.$methodName);
		
		//Call the action
		if (count($matches)>1) array_shift($matches);
		$result = call_user_func_array(array($instance,$methodName),$matches);
		
	}

	/**
	 * Cascade Array function. Flattens arrays $a and $b using array $a as
	 * its base.
	 * 
	 * @param array $a reference array
	 * @param array $b array with overriding entries for $a
	 * 
	 * @return array $flattenedArray array 
	 */
	public static function cascadeRoutes($a, $b) {
        if (!is_array($b)) return $b;
        foreach ($b as $key => $value) {
                if (isset($a[$key]) && is_array($a[$key])) {
                        if (is_array($b[$key]) && count($b[$key]) == 0) {
                                unset($a[$key]);
                        } else {
                                $a[$key] = self::cascadeRoutes($a[$key], $b[$key]);
                        }
                } else {
                        if (is_string($b[$key]) && trim($b[$key]) == '') {
                                unset($a[$key]);
                        } else {
                                $a[$key] = $b[$key];
                        }
                }
        }
        
        return $a;
	}
		
	/**
	 * Reverse routing. Returns the URL that belongs to a dispatch ID.
	 * Reverse URLs cannot be inferred from regular expressions in the
	 * route data so we simply add another section to the route map:
	 *
	 * "~" => array(
	 *		'preview-page' => 'preview/%s'	
	 * )
	 * 
	 * You can obtain the URL and fill in the parameter slot %s by
	 * invoking this method like:
	 *
	 * Concordia::getUrl('preview-page', array('homepage'));
	 * -- returns: /preview/homepage
	 *
	 * @param string $dispatchID
	 * @param array  $params
	 *
	 * @return string
	 */
	public static function getUrl($dispatchID, $params = array()) {
		$routes = self::$routes;
		if (isset($routes['~'][$dispatchID])) {
			return vsprintf($routes['_reverse'][$dispatchID],$params);
		}
		throw new CException('Undefined reverse route.');
	}

	/**
	* Sets the dictionary array to be used for i18n.
	* 
	* @param array $dict
	*/
	public static function setDictionary($dict) {
		self::$dict = $dict;
	}
	
	/**
	* Returns the text for a language key.
	* For instance if your dictionary (see setDictionary) contains
	* an entry like:
	*
	* array( ..
	*     'error.page-not-found' => 'Oops; page "%s" could not be found!'
	* ..)
	*
	* You can obtain the proper language string like this:
	*
	* Concordia::translate('error.page-not-found',array('news'));
	* -- returns: 'Oops; page "news" could not be found!'
	* 
	* Uses printf-notation.
	*
	* @param string $word
	* @param array  $params
	*/
	public static function translate($word,$params = array()) {
		if (isset(self::$dict[$word])) $word = self::$dict[$word];
		return vsprintf($word,$params);
	}
	
	/**
	* Loads a configuration array into Concordia.
	*
	* @param array $vars
	*/
	public static function loadConf($vars) {
		self::$vars = $vars;
	}

	/**
	* Looks up a configuration value for you.
	* The configuration array needs to be set by loadConf().
	* Supports dot notation:
	*
	* Concordia::conf('database.production.dbname');
	* 
	* will return the value of:
	* ['database']['production']['dbname']; 
	*
	* @param string $path 
	* @param mixed  $def  (optional default value)
	*/
	public static function conf($path,$def = null) {
		$a = self::$vars;
		$p = explode('.',$path);
		while($k = array_shift($p)) {
			if (!is_array($a)) return $def; 
			elseif(!isset($a[$k])) return $def;
			else $a = $a[$k];
		}
		return $a;
	}
	/**
	 * Check if a host name matches an expression.
	 *
	 * @param $hostname   Name of a host, or domain
	 * @param $expression Expression which is also a host name or domain 
	 *                    but can contain wild card characters.
    	*
	 * @return boolean TRUE if the host name matches, FALSE when not.
	 */
	public static function matchHost($hostname, $expression) {
		return (boolean)preg_match('/^' .str_replace('/', '\/', $expression).'\/?$/i', $hostname);
	}

	/**
	 * Returns a parameter from POST array or returns default.
	 * 
	 * @param string $key
	 * @param mixed  $default
	 *
	 * @return mixed 
	 */
	public static function post($key, $default = null) {
		return (isset($_POST[$key])) ? $_POST[$key] : $default;
	}

	/**
	* Returns a parameter from the GET array or returns the default.
	*
	* @param string $key
	* @param mixed $default
	*
	* @return mixed
	*/
	public static function get($key, $default = null) {
		return (isset($_GET[$key])) ? $_GET[$key] : $default; 
	}

	/**
	* Gets or sets a value in the session; starts session automatically
	* if necessary.
	*
	* @param string $key
	* @param string $value (optional, passing this parameter will set the value)
	*
	* @return mixed
	*/
	public static function session($key, $value = null) {
		if (!isset($_SESSION)) session_start();
		if (is_null($value)) {
			return (isset($_SESSION[$key])) ? $_SESSION[$key] : null;
		}
		$_SESSION[$key] = $value;
	}
	
	/**
	* If you like to work with instance methods rather than static
	* methods you can use this method to obtain a proxy instance.
	*
	* Example:
	* $obj = Concordia::instance();
	* $obj->post('var'); -- calls Concordia::post('var');
	*
	* @return ConcordiaProxy
	*/
	public static function instance() {
			return new ConcordiaProxy;
	}

	/**
	* Extends the Concordia framework with a new function; called a service.
	* Example:
	*
	* Concordia::service('log', function($message){ ... });
	* Concordia::log('Hello!'); -- calls the function defined on the previous line
	*
	* @param string  $service
	* @param closure $func
	*/
	public static function service($service,$func) {
		self::$services[$service] = $func;
	}

	/**
	* Call static implementation - for service() method.
	*
	* @param string $method
	* @param array  $args
	*
	* @return mixed
	*/
	public static function __callStatic($method,$args) {
		$info = array(
			'method' => $method,
			'arguments' => $args
		);
		$result = call_user_func_array(self::$services[$method],$args);
		return $result;
	}
	
	/**
	* Flash message. A Flash message is a session variable (_flash)
	* that gets set for one request and then dissapears.
	* Optionally you can pass a redirect url.
	* 
	* Example: 
	* Concordia::flash('Account Created!', '/login');
	*
	* -- stores the message 'Account Created!' in the session and
	* redirects to /login.
	* when the flash method gets called again:
	*
	* $message = Concordia::flash();
	* -- $message will contain the string 'Account Created!' and
	* the session variable will be unset to prevent the message from
	* re-occurring.
	*
	* @param string $info     (optional, otherwise gets and resets flash)
	* @param string $redirect (optional)
	*
	* @return string|null
	*/	
	public static function flash($info = null, $redirect = null) {
		if (!isset($_SESSION)) session_start();
		if (!is_null($info)) {
			if (!is_array($info)) $info = array($info);
			$_SESSION['_flash'] = $info; 
			if (!is_null($redirect)) {
				header('Location: '.$redirect);
				session_write_close();
				exit;
			}
		}else {
			if (isset($_SESSION['_flash'])) {
				$message = $_SESSION['_flash'];
				unset($_SESSION['_flash']);
			}
			else {
				$message = null;
			}
			return $message;
		}
	}
	
	/**
	* Redirects to $url.
	*
	* @param string $url
	*/
	public static function redirect($url) {
		header('Location: '.$url);
		exit;
	}
	
	/**
	* Sets the factory function to be used to create
	* instances of Controllers.
	* When calling route() the router will create a new
	* instance of a controller by using the default 'new' operator.
	* If you want to control this process you can register a
	* factory method instead.
	*
	* @param closure $factory
	*/
	public static function factory($factory) {
		self::$factory = $factory;
	}

	
}

/**
* Proxy version of Concordia.
*/
class ConcordeProxy {
	public function __call($method,$args) {
		return call_user_func_array(array('Concordia',$method),$args);	
	}
}

/**
* No-nonsense view class.
*/
class ConcordeView {

	protected $layout = null;

	/**
	* Escape strings.
	*
	* @param string $str string to escape.
	*
	* @return string
	*/
	public function escape($str) {
		$str = htmlspecialchars( $str, ENT_QUOTES, 'UTF-8' );
		//for old MSIE backtick XSS hack
		$str = str_replace( '`', '&#96;', $str );
		return $str;	
	}

	/**
	 * Set layout template.
	 * Render method will place content in $content variable of template.
	 *
	 * @param $file Path to layout template file.
	*/
	public function setLayout($file) {
		$this->layout = $file;
	}

	/**
	* Returns the rendered template.
	*
	* @param string $file filename
	*
	* @return string
	*/
	public function render($file) {
		ob_start();
		require($file);
		
		if(isset($this->layout)) {
			$content = ob_get_contents();
			ob_end_clean();
			ob_start();
			require($this->layout);
		}
		
		return ob_get_clean();
	}
}

/**
* General ConcordePHP exception.
*/
class CException extends Exception {}
/**
* Route not found exception.
*/
class CRouteNotFound extends CException {}

//Alias for backward compat.
class Concordia extends Concorde {}
