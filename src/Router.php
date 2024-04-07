<?php
namespace Qkly;

class Router
{
    public static function dispatch()
    {
        $url = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $params = array();
        $query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        if (!empty($query)) {
            $query = str_replace('%5D%5B%5D=', '%5D=', str_replace('=', '%5B%5D=', $query));
            parse_str($query, $params);
        }
        $pathSegments = explode('/', rtrim($path, '/'));

        if (isset($pathSegments[1])) {
            $mode = $pathSegments[1];
            switch ($mode) {
                case 'api':
                    Api::dispatch($pathSegments, $params);
                    break;
                case 'console':
                    Console::dispatcher();
                    break;
                case 'docs':
                    Documentation::dispatcher();
                    break;
                case '_':
                    break;
                default:
                    http_response_code(404);
                    echo 404;
                    break;
            }
        }
    }
}
