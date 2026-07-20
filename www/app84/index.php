<?php
$name = basename(dirname(__FILE__));
header('Content-Type: text/plain; charset=utf-8');
echo "PROYECTO: {$name}\n";
echo "PHP_VERSION: " . PHP_VERSION . "\n";
echo "mysqli: " . (extension_loaded('mysqli') ? 'si' : 'no') . "\n";
echo "opcache: " . (extension_loaded('Zend OPcache') ? 'si' : 'no') . "\n";
