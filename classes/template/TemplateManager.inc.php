<?php

/**
 * TemplateManager.inc.php
 *
 * Copyright (c) 2003-2004 The Public Knowledge Project
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @package template
 *
 * Class for accessing the underlying template engine.
 * Currently integrated with Smarty (from http://smarty.php.net/).
 *
 * $Id$
 */

/* This definition is required by Smarty */
define('SMARTY_DIR', Core::getBaseDir() . '/lib/smarty/');

require_once('smarty/Smarty.class.php');
		
class TemplateManager extends Smarty {

	/**
	 * Constructor.
	 * Initialize template engine and assign basic template variables.
	 */
	function TemplateManager() {
		parent::Smarty();
		
		// Set up Smarty configuration
		$baseDir = dirname(dirname(dirname(__FILE__)));
		$this->template_dir = $baseDir . '/templates/';
		$this->compile_dir = $baseDir . '/templates/t_compile/';
		$this->config_dir = $baseDir . '/templates/t_config/';
		$this->cache_dir = $baseDir . '/templates/t_cache/';
		
		// TODO: Investigate caching behaviour and if OJS can take advantage of it
		//$this->caching = true;
		//$this->compile_check = true;
		
		// Assign common variables
		$this->assign('baseUrl', Request::getBaseUrl());
		$this->assign('pageTitle', 'common.openJournalSystems');
		$this->assign('indexUrl', Request::getIndexUrl());
		$this->assign('pageUrl', Request::getPageUrl());
		$this->assign('currentUrl',  Request::getRequestUrl());
		$this->assign('currentJournal',  Request::getJournal());
		
		if (!defined('SESSION_DISABLE_INIT')) {
			$sessionManager = &SessionManager::getManager();
			$session = &$sessionManager->getUserSession();
			$this->assign('isUserLoggedIn', Validation::isLoggedIn());
			$this->assign('loggedInUsername', $session->getSessionVar('username'));
		}
		
		$this->register_function('translate', array(&$this, 'smartyTranslate'));
		$this->register_function('html_options_translate', array(&$this, 'smartyHtmlOptionsTranslate'));
	}
	
	/**
	 * Return an instance of the template manager.
	 * @return TemplateManager the template manager object
	 */
	function &getManager() {
		static $instance;
		
		if (!isset($instance)) {
			$instance = new TemplateManager();
		}
		return $instance;
	}
	
	
	//
	// Custom template functions
	//
	
	/**
	 * Smarty usage: {translate key="localization.key.name" [paramName="paramValue" ...]}
	 *
	 * Custom Smarty function for handling translation of strings.
	 * Substitution works by replacing tokens like "{$foo}" with the value of the parameter named "foo" (if supplied).
	 * @params $params array associative array, must contain "key" parameter for string to translate plus zero or more named parameters for substitution
	 * @params $smarty Smarty
	 * @return string the localized string, including any parameter substitutions
	 */
	function smartyTranslate($params, &$smarty) {
		if (isset($params) && !empty($params)) {
			$message = isset($params['key']) ? Locale::translate($params['key']) : '';
			
			if (isset($params['key'])) {
				unset($params['key']);
			}
			
			if (!empty($params)) {
				// Substitute custom parameters
				foreach ($params as $key => $value) {
					$message = str_replace("{\$$key}", $value, $message);
				}
			}
			
			return $message;
		}
	}
	
	/**
	 * Smarty usage: {html_options_translate ...}
	 * For parameter usage, see http://smarty.php.net/manual/en/language.function.html.options.php
	 *
	 * Identical to Smarty's "html_options" function except option values are translated from i18n keys.
	 * @params $params array 
	 * @params $smarty Smarty
	 */
	function smartyHtmlOptionsTranslate($params, &$smarty) {
		if (isset($params['options'])) {
			$params['options'] = array_map(array('Locale', 'translate'), $params['options']);
		} else if (isset($params['output'])) {
			$params['output'] = array_map(array('Locale', 'translate'), $params['output']);
		}
		require_once($this->_get_plugin_filepath('function','html_options'));
		return smarty_function_html_options($params, $smarty);
	}
	
	/* Deprecated. Old gettext localization function.
	function smartyTranslateOld($params, $content, &$smarty) {
		if (isset($content) && !empty($content)) {
			$content = Locale::translate($content);
			
			if (empty($params)) {
				return $content;
				
			} else {
				return call_user_func_array('sprintf', array_merge(array($content), $params));
			}
		}
	}
	*/
	
}

?>
