<?php

/*
 * Unit tests for Concordia
 */

$count = 0;
function asrt($a,$b) {
	global $count;
	$count++;
	if ($a===$b) echo "[$count]"; else die('Fail.');
}
echo "\nTesting Concordia Router and Controller system for PHP....";
echo "\n";
require('Concordia.php');

asrt(class_exists('Concordia'),true);

class Data {
	public static $info = '';
}

class Controller1 extends Concordia {
	public function action1() {
		Data::$info = 'action1';
	}
	public function action2() {
		Data::$info = 'param='.$this->getUrlParam(1);
	}
}

class Controller2 extends Concordia {
	public function action3() {
		Data::$info = 'action3';
	}
	public function action4() {
		Data::$info = 'param='.$this->getParam('test','nothing');
	}
}

class Bug {
	public function __construct() {
		throw new Exception('BUG');
	}
}

function fakeRequest($domain,$method,$url) {
	$_SERVER['REQUEST_URI'] = $url;
	$_SERVER['HTTP_HOST']=$domain;
	$_SERVER['REQUEST_METHOD']=$method;
}

fakeRequest('mywebsite.com','GET','/');




Concordia::route(array(
	'.*' => array(
		'GET' => array(
			'/' => 'Controller1->action1'
		)
	) 
));

asrt(Data::$info,'action1');

fakeRequest('mywebsite.com','GET','/');
Concordia::route(array(
	'.*' => array(
		'GET' => array(
			'/' => 'Controller2->action3'
		)
	) 
));

asrt(Data::$info,'action3');


fakeRequest('mywebsite.com','GET','/page/42');
Concordia::route(array(
	'.*' => array(
		'GET' => array(
			'/' => 'Controller1->action1',
			'/page/(\d+)'=>'Controller1->action2'
		)
	) 
));

asrt(Data::$info,'param=42');



fakeRequest('mywebsite.com','GET','/page/42/'); //with extra slash... no problem
Concordia::route(array(
	'.*' => array(
		'GET' => array(
			'/' => 'Controller1->action1',
			'/page/(\d+)'=>'Controller1->action2'
		)
	) 
));

asrt(Data::$info,'param=42');


fakeRequest('mywebsite.com','GET','/other/42');
try {
Concordia::route(array(
	'.*' => array(
		'GET' => array(
			'/' => 'Controller1->action1',
			'/page/(\d+)'=>'Controller1->action2'
		)
	) 
));
}
catch(Exception $e) {
	$m = $e->getMessage();
}

asrt($m,'No route has been found for URL: /other/42');




fakeRequest('mywebsite.com','POST','/post/'); 
Concordia::route(array(
	'.*' => array(
		'POST' => array(
			'/post'=>'Controller2->action4'
		)
	) 
));

asrt(Data::$info,'param=nothing');


$_POST = array('test'=>'the_post');
fakeRequest('mywebsite.com','POST','/post/'); 
Concordia::route(array(
	'.*' => array(
		'POST' => array(
			'/post'=>'Controller2->action4'
		)
	) 
));

asrt(Data::$info,'param=the_post');

$_POST = array('test'=>'the_post');
fakeRequest('mywebsite.com','POST','/post/'); 
Concordia::route(array(
	'.*' => array(
		'POST' => array(
			'/post'=>'Controller2->action4'
		)
	),
	'.*\.com' => array(
		'POST'=>array(	
			'/post'=>'Controller1->action2'
		)
	)
));
asrt(Data::$info,'param=');

$_POST = array('test'=>'the_post');
fakeRequest('mywebsite.com','POST','/post/something/'); 
Concordia::route(array(
	'.*' => array(
		'POST' => array(
			'/post/(\w+)'=>'Controller2->action4'
		)
	),
	'.*\.com' => array(
		'POST'=>array(	
			'/post/(\w+)'=>'Controller1->action2'
		)
	),
	'mywebsite.com'=>array(
		'POST'=>array(
			'/post/(\w+)'=>'Controller2->action4'
		)
	)
));
asrt(Data::$info,'param=the_post');

$_POST = array('test'=>'the_post');
fakeRequest('mywebsite.com','POST','/post/something/'); 
Concordia::route(array(
	'.*' => array(
		'POST' => array(
			'/post/(\w+)'=>'Controller2->action4'
		)
	),
	'.*\.com' => array(
		'POST'=>array(	
			'/post/(\w+)'=>'Controller1->action2'
		)
	),
	'mywebsite2.com'=>array(
		'POST'=>array(
			'/post/(\w+)'=>'Controller2->action4'
		)
	)
));
asrt(Data::$info,'param=something');


fakeRequest('mywebsite.com','STRANGE','/');
Concordia::route(array(
	'.*' => array(
		'STRANGE' => array(
			'/' => 'Controller1->action1'
		)
	) 
));

asrt(Data::$info,'action1');


fakeRequest('mywebsite.com','GET','/');
try{
Concordia::route(array(
	'.*' => array('GET' => array('/' => 'Controller1->actionX')) 
));
}catch(Exception $e){
	$m = $e->getMessage();	
}
asrt($m,'Method does not exist: Controller1 -> actionX');

try{
Concordia::route(array(
	'.*' => array('GET' => array('/' => 'ControllerX->action1')) 
));
}catch(Exception $e){
	$m = $e->getMessage();	
}
asrt($m,'Class not found: ControllerX');


try{
Concordia::route(array(
	'.*' => array('GET' => array('/'=>'X')) 
));
}catch(Exception $e){
	$m = $e->getMessage();	
}

asrt($m,'Invalid Call information: X');


try{
Concordia::route(array(
	'.*' => array('GET' => array('/'=>'Bug->hasBug')) 
));
}catch(Exception $e){
	$m = $e->getMessage();	
}

asrt($m,'Unable to create instance of class: Bug');
