<?php

/**
 * SharepointAPI
 *
 * Simple PHP API for reading/writing and modifying SharePoint list items.
 *
 * @version 0.6.1
 * @licence MIT License
 * @source: http://github.com/thybag/PHP-SharePoint-Lists-API
 *
 *
 * Add backwards compatability for none composer users:
 * Include this file and add `use Thybag\SharePointAPI;` below in order the PHP SP API as before.
 */

// PSR-0 Autoloader
// see: http://zaemis.blogspot.fr/2012/05/writing-minimal-psr-0-autoloader.html
spl_autoload_register(function ($classname) {
	$classname = ltrim($classname, "\\");
	preg_match('/^(.+)?([^\\\\]+)$/U', $classname, $match);
	$classname = 'src/'.str_replace("\\", "/", $match[1])
		. str_replace(array("\\", "_"), "/", $match[2])
		. ".php";
	include_once $classname;
});
