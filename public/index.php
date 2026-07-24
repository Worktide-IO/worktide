<?php

use App\CacheKernel;
use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    $kernel = new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);

    // Opt-in Symfony reverse-proxy HTTP cache. Off unless HTTP_CACHE is truthy
    // so prod is unchanged until explicitly enabled; local dev turns it on via
    // .ddev/config.yaml web_environment. Only caches explicitly-public
    // responses — authed API traffic passes straight through (see CacheKernel).
    if (filter_var($context['HTTP_CACHE'] ?? false, FILTER_VALIDATE_BOOL)) {
        return new CacheKernel($kernel);
    }

    return $kernel;
};
