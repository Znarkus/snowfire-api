<?php

class Snowfire
{
	/**
	* @var Snowfire_Gui
	*/
	public $gui;
	
	/**
	* @var Snowfire_Component
	*/
	public $component;
	
	/**
	* @var Snowfire_Storage
	*/
	public $storage;
	
	/**
	* @var Snowfire_Config
	*/
	public $config;
	
	//const OPT_REQUIRE_SNOWFIRE = 1;
	
	private static $_instance;
	
	private function __clone() {}
	private function __construct()
	{
		if (class_exists('Snowfire_Storage')) {
			$this->storage = new Snowfire_Storage();
		}
		
		$this->gui = new Snowfire_Gui();
		
		if (isset($this->storage)) {
			$this->components = new Snowfire_Component($this->storage);
		}
	}
	
	/**
	* config or configData must be set
	* 
	* @param mixed $options
	*/
	public function initialize($options = array())
	{
		$options = Snowfire_Helper::options(array(
			'config' => null,
			'configData' => null
		), $options);
		
		$this->config = new Snowfire_Config(
			isset($options['config']) ? file_get_contents($options['config']) : $options['configData']
		);
	}
	
	public function checkRequest(/*$options = 0*/)
	{
		if (!isset($this->storage)) {
			throw new Exception('No storage defined');
		}
		
		if (isset($_GET['snowfireUserKey'], $_GET['snowfireAppKey'])) {
			try {
				$_SESSION['Snowfire']['domain'] = $this->storage->getAccountDomain($_GET['snowfireAppKey']);
			} catch (Exception $e) {
				die('Invalid Snowfire application key');
			}
			
			$_SESSION['Snowfire']['appKey'] = $_GET['snowfireAppKey'];
			$_SESSION['Snowfire']['userKey'] = $_GET['snowfireUserKey'];
			
			try {
				$jsonData = $this->_getUrl(
					$_SESSION['Snowfire']['domain'] . 'a;applications/application/getContainerData?APP_KEY=' . $_GET['snowfireAppKey'] . '&USER_KEY=' . $_GET['snowfireUserKey']
				);
			} catch (Exception $e) {
				die('Invalid Snowfire user key');
			}
			
			$_SESSION['Snowfire']['containerData'] = json_decode($jsonData);
			
			header('Location: ' . substr($_SERVER['REQUEST_URI'], 0, strpos($_SERVER['REQUEST_URI'], '?')));
			exit;
			
		} else if (/*self::optionPresent(self::OPT_REQUIRE_SNOWFIRE, $options) && */!isset($_SESSION['Snowfire'])) {
			die('Invalid request, no Snowfire credentials found');
		}
	}
	
	/**
	* Get the URL to redirect the user to, to install as an application in Snowfire
	* 
	* @param string $installUrl The URL to get the application description xml
	* @param string $returnUrl An URL to return to when the install has succeded. Defaults to current URL
	* @return string
	*/
	public function getInstallUrl($snowfireDomain, $installUrl, $returnUrl = null, $forceSnowfireLogin = false)
	{
		$snowfireDomain = preg_match('@^https?://@', $snowfireDomain) == 1 ? $snowfireDomain : 'http://' . $snowfireDomain;
		$installUrl = preg_match('@^https?://[^/]+@', $installUrl) == 1 ? $installUrl : 'http://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($installUrl, '/');
		$returnUrl = isset($returnUrl) ? $returnUrl : $_SERVER['REQUEST_URI'];
		$returnUrl = preg_match('@^https?://[^/]+@', $returnUrl) == 1 ? $returnUrl : 'http://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($returnUrl, '/');
		$forceSnowfireLogin = $forceSnowfireLogin ? 'FORCELOGIN&amp;' : '';
		return "{$snowfireDomain}/a;applications/application/verify?{$forceSnowfireLogin}installUrl={$installUrl}&amp;returnUrl={$returnUrl}";
	}
	
	
	/**
	* Get singleton instance
	* @return Snowfire
	*/
	public static function &getInstance()
	{
		if (!isset(self::$_instance)) {
			self::$_instance = new self();
		}
		
		return self::$_instance;
	}
	
	private function _getUrl($url, $post = array(), $get = array())
	{
		if (empty($url) || is_null($url)) {
			throw new Exception('Bad url "' . $url . '"');
		}
		
		$ch = curl_init();
		
		if (!empty($get)) {
			$getPairs = array();
			
			foreach ($get as $key => $value) {
				$getPairs[] = "$key=" . urlencode($value);
			}
			
			$url .= '?' . implode('&', $getPairs);
		}
		
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 3);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Snowfire');
		
		if (!empty($post)) {
			curl_setopt($ch, CURLOPT_POST, count($post));
			
			$httpPost = array();
			foreach ($post as $key => $value) {
				$httpPost[] = "$key=" . urlencode($value);
			}
			
			curl_setopt($ch, CURLOPT_POSTFIELDS, implode('&', $httpPost));
		}
		
		$return = curl_exec($ch);
		
		if (curl_error($ch) != '') {
			Orange::error(curl_error($ch));
		}
		
		$info = curl_getinfo($ch);
		
		switch ($info['http_code']) {
			
			case 200:
				break;
				
			case 302:
				throw new Exception('Url ' . $url . ' tried to redirect (302)');
			
			case 404:
				throw new Exception('Url ' . $url . ' 404:ed');
			
			case 500:
				throw new Exception('Url ' . $url . ' hit an application error');
			
			default:
				throw new Exception('Url ' . $url . ' returned code ' . $info['http_code'] . ' and text ' . $return);
		}
		
		return $return;
	}
}


class Snowfire_Helper
{
	public static function useragentIsSnowfire()
	{
		return stristr($_SERVER['HTTP_USER_AGENT'], 'snowfire');
	}
	
	public static function optionPresent($option, $options)
	{
		return ($options & $option) == $option;
	}
	
	public static function options($default, $set, $required = array())
	{
		if (count(array_diff($required, array_keys($set))) != 0) {
			throw new InvalidArgumentException('Options ' . implode(', ', $required) . ' are required');
		}
		
		$options = $default;
		
		foreach ($set as $key => $value) {
			$options[$key] = $value;
		}
		
		return $options;
	}
}

class Snowfire_Component
{
	/**
	* @var Snowfire_Storage
	*/
	private $_storage;
	private $_components = array();
	
	public function __construct(&$storage)
	{
		$this->_storage = $storage;
		
		if (Snowfire_Helper::useragentIsSnowfire() && $_SERVER['REQUEST_METHOD'] === 'POST') {
			$data = json_decode(file_get_contents('php://input'));
			$this->_storage->saveComponents($data);
			
			header('Content-Type: text/xml');
			echo '<?xml version="1.0" encoding="utf-8"?><response><success>true</success></response>';
			exit;
		}
	}
	
	public function getEditUrl($editableUrl = null, $returnUrl = null)
	{
		$editableUrl = isset($editableUrl) ? $editableUrl : $_SERVER['REQUEST_URI'];
		$editableUrl = preg_match('@^https?://[^/]+@', $editableUrl) == 1 ? $editableUrl : 'http://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($editableUrl, '/');
		$returnUrl = isset($returnUrl) ? $returnUrl : $_SERVER['REQUEST_URI'];
		$returnUrl = preg_match('@^https?://[^/]+@', $returnUrl) == 1 ? $returnUrl : 'http://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($returnUrl, '/');
		return rtrim($this->_storage->getAccountDomain(), '/') . "/a;pages2/api/edit?url={$editableUrl}&amp;returnUrl={$returnUrl}";
	}
	
	public function singlerow($id, $description, $options = array())
	{
		$options['description'] = $description;
		return $this->_insertComponent('singlerow', $id, $options);
	}
	
	public function wysiwyg($id, $description, $options = array())
	{
		$options['description'] = $description;
		return $this->_insertComponent('wysiwyg', $id, $options);
	}
	
	public function image($id, $description, $options = array())
	{
		$options['description'] = $description;
		return $this->_insertComponent('image', $id, $options);
	}
	
	public function link($id, $description, $options = array())
	{
		$options['description'] = $description;
		return $this->_insertComponent('link', $id, $options);
	}
	
	private function _insertComponent($type, $id, $parameters = array())
	{
		if (isset($this->_components[$id])) {
			throw new InvalidArgumentException("Component ID {$id} already exist");
		}
		
		$this->_components[$id] = $type;
		
		if (Snowfire_Helper::useragentIsSnowfire()) {
			$sml = "{ com_{$type} ( id:'{$id}'";
			
			foreach ($parameters as $key => $value) {
				$sml .= ", {$key}:'{$value}'";
			}
			
			return $sml . ' ) }';
			
		} else {
			return $this->_storage->loadComponent($id);
		}
	}
}

class Snowfire_Gui
{
	public function render($html)
	{
		$container = new Snowfire_Gui_View('resources/views/container.phtml');
		$container->data = $_SESSION['Snowfire']['containerData'];
		$container->content = $html;
		echo $container->render();
	}
}

class Snowfire_Gui_View
{
	private $_data = array();
	private $_filename;
	
	public function __construct($filename)
	{
		$this->_filename = $filename;
	}
	
	public function __set($key, $value)
	{
		$this->_data[$key] = $value;
	}
	
	public function __get($key)
	{
		return $this->_data[$key];
	}
	
	public function render($variables = array())
	{
		if (!empty($variables)) {
			$this->_data = $variables;
		}
		
		ob_start();
		require($this->_filename);
		return ob_get_clean();
	}
	
	public function __toString()
	{
		return $this->render();
	}
}

class Snowfire_Config
{
	private $_xml;
	
	public function __construct($configData)
	{
		if (!isset($configData) || empty($configData)) {
			throw new InvalidArgumentException('No config data was set');
		}
		
		$this->_xml = simplexml_load_string($configData);
	}
	
	public function __get($key)
	{
		return (string)$this->_xml->$key;
	}
}

class Snowfire_StorageBase
{
	/**
	* Save component data from Snowfire to local cache
	* 
	* @param array $data Array of stdClass. Attributes are data and id
	*/
	public function saveComponents($data)
	{
		var_dump($data);
		exit('You need to overwrite the Snowfire_StorageBase::saveComponents() method');
	}
	
	/**
	* Load component data
	* 
	* @param string $id
	* @return string Component HTML data
	*/
	public function loadComponent($id)
	{
		var_dump($id);
		exit('You need to overwrite the Snowfire_StorageBase::loadComponent() method');
	}
	
	/**
	* Map an app key to a Snowfire domain
	* 
	* @param string $appKey
	*/
	public function getAccountDomain($appKey)
	{
		var_dump($appKey);
		exit('You need to overwrite the Snowfire_StorageBase::getAccountDomain() method');
	}
}