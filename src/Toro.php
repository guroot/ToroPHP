<?php

/**
 * Modified by Jonathan Fleury
 * 
 * Revision 1 : handle custom function defined by a @
 * Revision 2 : output json if controller return an array
 * Revision 3 : handle different function depending on the request method.
 * 
 */
class Toro {

    public static function serve($routes) {
        ToroHook::fire('before_request', compact('routes'));

        $request_method = strtolower($_SERVER['REQUEST_METHOD']);
        $defined_request_method = null;

        $path_info = '/';
        if (!empty($_SERVER['PATH_INFO'])) {
            $path_info = $_SERVER['PATH_INFO'];
        } else if (!empty($_SERVER['ORIG_PATH_INFO']) && $_SERVER['ORIG_PATH_INFO'] !== '/index.php') {
            $path_info = $_SERVER['ORIG_PATH_INFO'];
        } else {
            if (!empty($_SERVER['REQUEST_URI'])) {
                $path_info = (strpos($_SERVER['REQUEST_URI'], '?') > 0) ? strstr($_SERVER['REQUEST_URI'], '?', true) : $_SERVER['REQUEST_URI'];
            }
        }

        $discovered_handler = null;
        $regex_matches = array();



        if (isset($routes[$path_info])) {
            $discovered_handler = $routes[$path_info];
        } else if ($routes) {
            $tokens = array(
                ':string' => '([a-zA-Z]+)',
                ':number' => '([0-9]+)',
                ':alpha' => '([a-zA-Z0-9-_]+)'
            );
            foreach ($routes as $pattern => $handler_name) {
                /* jfleury ******************** */                
                $str = substr($pattern, 0, 6);
                foreach (array('GET', 'POST', 'PUT', 'DELETE', 'PATCH') as $method) {
                    if (strpos($str, strtoupper($method)) !== false) {                                                
                        if (strtoupper($request_method) === $method) {                              
                            $pattern = substr($pattern,strlen($method)+1);                             
                            break;
                        }
                    }
                }      
                /******************************* */
                
                $pattern = strtr($pattern, $tokens);

                if (preg_match('#^/?' . $pattern . '/?$#', $path_info, $matches)) {                                        
                    $discovered_handler = $handler_name;
                    $regex_matches = $matches;                    
                    break;
                }
               
            }
        }

        /*
         * Permet de spécifier la méthode avec un @ plutôt qu'utiliser
         * la méthode par défaut : $_SERVER['REQUEST_METHOD']
         */        
        $arrayRoute = explode('\\', $discovered_handler);
        if (strpos(end($arrayRoute), '@')) {
            $className = end($arrayRoute);
            $arrayClass = explode('@', $className);
            $request_method = end($arrayClass);
            $discovered_handler = substr($discovered_handler, 0, strpos($discovered_handler, '@'));
        }


        $result = null;

        $handler_instance = null;
        if ($discovered_handler) {
            if (is_string($discovered_handler)) {
                $handler_instance = new $discovered_handler();
            } elseif (is_callable($discovered_handler)) {
                $handler_instance = $discovered_handler();
            }
        }

        if ($handler_instance) {
            unset($regex_matches[0]);




            if (self::is_xhr_request() && method_exists($handler_instance, $request_method . '_xhr')) {
                header('Content-type: application/json');
                header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
                header('Cache-Control: no-store, no-cache, must-revalidate');
                header('Cache-Control: post-check=0, pre-check=0', false);
                header('Pragma: no-cache');
                $request_method .= '_xhr';
            }

            if (method_exists($handler_instance, $request_method)) {
                ToroHook::fire('before_handler', compact('routes', 'discovered_handler', 'request_method', 'regex_matches'));
                $result = call_user_func_array(array($handler_instance, $request_method), $regex_matches);
                ToroHook::fire('after_handler', compact('routes', 'discovered_handler', 'request_method', 'regex_matches', 'result'));
            } else {
                ToroHook::fire('404', compact('routes', 'discovered_handler', 'request_method', 'regex_matches'));
            }
        } else {
            ToroHook::fire('404', compact('routes', 'discovered_handler', 'request_method', 'regex_matches'));
        }

        ToroHook::fire('after_request', compact('routes', 'discovered_handler', 'request_method', 'regex_matches', 'result'));

        /* if $result is an array, output json */
        if (is_array($result) || is_object($result)){
            header('Content-Type: application/json');
            echo json_encode($result);
        }
        if ($result === false) {
            header('Pragma: no-cache');
            header("HTTP/1.1 500 ");
            header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Cache-Control: post-check=0, pre-check=0', false);
            header('Pragma: no-cache');
        }
    }

    private static function is_xhr_request() {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    }

}

class ToroHook {

    private static $instance;
    private $hooks = array();

    private function __construct() {
        
    }

    private function __clone() {
        
    }

    public static function add($hook_name, $fn) {
        $instance = self::get_instance();
        $instance->hooks[$hook_name][] = $fn;
    }

    public static function fire($hook_name, $params = null) {
        $instance = self::get_instance();
        if (isset($instance->hooks[$hook_name])) {
            foreach ($instance->hooks[$hook_name] as $fn) {
                call_user_func_array($fn, array(&$params));
            }
        }
    }

    public static function get_instance() {
        if (empty(self::$instance)) {
            self::$instance = new ToroHook();
        }
        return self::$instance;
    }

}
