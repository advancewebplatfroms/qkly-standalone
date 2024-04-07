<?php
namespace Qkly;

class Application
{
    public static function start($app_dir)
    {
        define('CLI', PHP_SAPI === 'cli');
        define('DS', DIRECTORY_SEPARATOR);
        define("APP_DIR", $app_dir . DS);
        $dotenv = \Dotenv\Dotenv::createImmutable(APP_DIR);
        $dotenv->load();
        if (CLI) {
            if (isset($argv)) {
                new Command($argv);
            }
        } else {
            Router::dispatch();
        }
    }
}