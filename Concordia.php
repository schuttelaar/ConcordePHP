<?php

/*
 * Concordia 1.0
 * ------------------------------------------------
 *  ___|                                |_)    \   
 * |      _ \  __ \   __|  _ \   __| _` | |   _ \  
 * |     (   | |   | (    (   | |   (   | |  ___ \ 
 *\____|\___/ _|  _|\___|\___/ _|  \__,_|_|_/    _\
 * ------------------------------------------------
 * 
 * Written by:
 *					G.J.G.T de Mooij
 *					J.J. Schuttelaar 
 * 					J. Hoogstrate
 * 
 * Licensed:		
 *					New BSD License
 * 
 **/
class Concordia {

	/**
	 * Contains parameters from GET request.
	 * @var array 
	 */
	protected $urlParams;
	
	/**
	 * Constructor
	 * @param array $urlData 
	 */
	public function __construct($urlData) {
		$this->urlParams = $urlData;
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
	 * @return array $mappingResult Result of routing action.
	 */
	public static function route($map) {
		
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
				throw new Exception('Invalid Array structure, expected array but got: '.$value);
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
			$key = str_replace('/', '\/', $key);
            $key = '^' . $key . '\/?$'; 
			if (preg_match('/'.$key.'/i',$domain)) {
				if (isset($value[$requestMethod])) {
					$routes[] = $value[$requestMethod];
				}
			}
		}
		
		$base = array(); 
		foreach($routes as $routeSet) {
			$base = self::cascadeRoutes($base,$routeSet);
		}
		
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
		if (!$flagFoundMatch) throw new Exception('No route has been found for URL: '.$url);
		
		
		//Starts with a /, then redirect
		if (strpos($route,'/')===0) {
			header('Location: '.$route);
			exit();
		}
		
		//Obtain call information
		$callInfo = explode('->', $route);
		if (count($callInfo)!==2) throw new Exception('Invalid Call information: '.$route);
		list($className, $methodName) = $callInfo;
		
		//Can we find the class derived from the route call information?
		if (!class_exists($className)) throw new Exception('Class not found: '.$className);
		
		//Create an instance of this class
		try {
			$instance = new $className($matches);
		}
		catch(Exception $e) {
			throw new Exception('Unable to create instance of class: '.$className);
		}
		
		//Can we access this method
		if (!method_exists($instance,$methodName)) throw new Exception('Method does not exist: '.$className.' -> '.$methodName);
		
		//Call the action
		$result = $instance->$methodName();
		
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
	 * Obtains a parameter from the request URI.
	 *
	 * @param integer $paramNo
	 * @param mixed   $defaultValue
	 * @return mixed
	 */
	public function getUrlParam($paramNo, $defaultValue = null) {
		if (!isset($this->urlParams[$paramNo])) return $defaultValue;
		return $this->urlParams[$paramNo];
	}

	/**
	 * Returns a parameter from POST array or returns default.
	 * 
	 * @param string $key
	 * @param mixed  $default 
	 */
	public function getParam($key, $default=null) {
		if (!isset($_POST[$key])) return $default;
		return $_POST[$key];
	}
	

}

