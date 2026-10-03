<?php

/**
 * Loads composer autoload, Yii class and the application .env (htdocs/app_env/.env).
 * The Yii application is not created, so log messages are not sent anywhere.
 */

use Dotenv\Dotenv;

$appRoot = getenv('D3IMAP_APP_ROOT') ?: dirname(__DIR__, 4);

$loader = require $appRoot . '/vendor/autoload.php';
$loader->addPsr4('d3yii2\\d3imap\\tests\\', __DIR__);

require_once $appRoot . '/vendor/yiisoft/yii2/Yii.php';

if (is_file($appRoot . '/app_env/.env')) {
    Dotenv::createUnsafeImmutable($appRoot . '/app_env')->safeLoad();
}
