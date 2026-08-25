<?php

// Environment.
const APP_VERSION = '4.1';
const ROOT_DIR = __DIR__;
set_time_limit(0); // Disable PHP time limit.
ini_set('memory_limit', '256M'); // Override memory limit to be high enough.
error_reporting(E_ALL & ~E_DEPRECATED);
if (ini_get('date.timezone') == '') {
    date_default_timezone_set('America/Detroit');
}

// Autoload.
$autoloader = match (true) {
    isset($GLOBALS['_composer_autoload_path']) => $GLOBALS['_composer_autoload_path'],
    file_exists(__DIR__ . '/../../autoload.php') => __DIR__ . '/../../autoload.php',
    file_exists(__DIR__ . '/../vendor/autoload.php') => __DIR__ . '/../vendor/autoload.php',
    file_exists(__DIR__ . '/vendor/autoload.php') => __DIR__ . '/vendor/autoload.php',
    default => null,
};
require_once($autoloader);
unset($autoloader);

// Load data.
\Porter\Config::getInstance()->set(\Porter\Config::loadFile());
\Porter\Support::getInstance()->set(\Porter\Package::list());
