<?php

// Setup environment
if(PHP_SAPI != 'cli') exit;
chdir(__DIR__); // default is C:\Windows\System32

// Load dependencies
require '../core/controller.php';
require 'win32service.php';
require 'cache.php';

// Construct controller and cache
$controller = new Controller();
$cache = new Cache($controller);

// Setup service
$service = new Win32service([
	'service' => 'hft-app',
	'display' => 'HFT App Aktualisierungsdienst',
	'description' => utf8_decode('Lädt aktuelle Daten von verschiedenen Quellen in den lokalen Cache.'),
	'params' => '"'.__FILE__.'" start',
	'delayed_start' => true,
],[
	'logpath' => '../logs',
	'runner' => [$cache, 'cycle'],
	'interval' => 500000,
	'pause' => 3,
]);

// Handle command line argument
switch($argv[1] ?? NULL) {
	
	// Install service and shielding
	case 'install': {
		$service->install(true);
	} break;
	
	// Uninstall service and shielding
	case 'uninstall': {
		$service->install(false);
	} break;
	
	// Start service
	case 'start': {
		$service->start();
	} break;
	
	// Invalid argument
	default: throw new InvalidArgumentException();
}
