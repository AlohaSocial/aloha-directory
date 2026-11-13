<?php

// Router for PHP's built-in web server during development:
//
//   php -S 127.0.0.1:8000 -t public tools/dev-router.php
//
// Serves the generated static website (index.html in directories) and other files the way nginx
// does in production, and hands everything else to Symfony.

declare(strict_types=1);

$public = dirname(__DIR__).'/public';
$path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
if (!str_contains($path, '..')) {
    if (is_file($public.$path)) {
        return false;
    }
    $index = rtrim($public.$path, '/').'/index.html';
    if (is_file($index)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($index);

        return true;
    }
}

require $public.'/index.php';
