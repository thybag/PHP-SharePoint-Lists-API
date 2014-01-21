<?php
// Add backwards compatability for none composer users.
//
//see: http://zaemis.blogspot.fr/2012/05/writing-minimal-psr-0-autoloader.html
spl_autoload_register(function ($classname) {

	echo $classname . ' => ';
    $classname = ltrim($classname, "\\");
    preg_match('/^(.+)?([^\\\\]+)$/U', $classname, $match);
    $classname = 'lib/'.str_replace("\\", "/", $match[1])
        . str_replace(array("\\", "_"), "/", $match[2])
        . ".php";

      //  echo dirname(__FILE__).'/'. $classname;
    include_once $classname;
});
