<?php

use Symfony\Component\HttpFoundation\Request;

if (PHP_SAPI === 'cli-server') {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = __DIR__.$uri;
    if (!is_file($file)) {
        $file = __DIR__.rtrim($uri, '/');
    }

    if (is_file($file)) {
        return false;
    }
}

/**
 * @var Composer\Autoload\ClassLoader
 */
$loader = require __DIR__.'/../app/autoload.php';

$bootstrapCache = __DIR__.'/../var/bootstrap.php.cache';
if (file_exists($bootstrapCache)) {
    include_once $bootstrapCache;
}

$trustedProxiesEnv = getenv('SYMFONY_TRUSTED_PROXIES') ?: getenv('TRUSTED_PROXIES');
if ($trustedProxiesEnv) {
    $proxies = array_filter(array_map('trim', explode(',', $trustedProxiesEnv)));
    if (!empty($proxies)) {
        Request::setTrustedProxies($proxies, Request::HEADER_X_FORWARDED_ALL);
    }
}

$kernel = new AppKernel('prod', false);
$kernel->loadClassCache();
//$kernel = new AppCache($kernel);

// When using the HttpCache, you need to call the method in your front controller instead of relying on the configuration parameter
//Request::enableHttpMethodParameterOverride();
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
