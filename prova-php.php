<?php
header('Content-Type: text/plain; charset=utf-8');
echo "PHP " . PHP_VERSION . "\n";
echo "mail(): " . (function_exists('mail') ? 'disponible' : 'NO') . "\n";
echo "sendmail_path: " . ini_get('sendmail_path') . "\n";
echo "pdo_mysql: " . (extension_loaded('pdo_mysql') ? 'si' : 'no') . "\n";
echo "pdo_sqlite: " . (extension_loaded('pdo_sqlite') ? 'si' : 'no') . "\n";
echo "document_root: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
echo "script: " . __FILE__ . "\n";
echo "escriptura carpeta pare: " . (is_writable(dirname(__DIR__)) ? 'si' : 'no') . "\n";
