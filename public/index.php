<?php

use App\Kernel;

// All settings come from config.php (see config.sample.php).
require_once dirname(__DIR__).'/src/Config/ConfigLoader.php';
App\Config\ConfigLoader::load(dirname(__DIR__));

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
