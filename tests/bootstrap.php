<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

// Tests never read the local config.php.
$_SERVER['ALOHA_DIRECTORY_CONFIG'] = __DIR__.'/config.test.php';
App\Config\ConfigLoader::load(dirname(__DIR__));

// Deterministic list signing key for the test suite (seed 0x01 × 32; root key seed 0x02 × 32).
$keyDir = dirname(__DIR__).'/var/test-keys';
if (!is_dir($keyDir)) {
    mkdir($keyDir, 0o700, true);
}
$keyFile = $keyDir.'/test-list-a.key';
if (!is_file($keyFile)) {
    file_put_contents($keyFile, App\Crypto\Base64Url::encode(str_repeat("\x01", 32))."\n");
}
chmod($keyFile, 0o600);
