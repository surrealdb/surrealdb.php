<?php

namespace surreal;

use Exception;

defined('SURREAL_PATH') or define('SURREAL_PATH', __DIR__);

class BaseSurreal
{

    public static $classMap = [];
    /**
     * @var array registered path aliases
     * @see getAlias()
     */
    public static $aliases = ['@surreal' => __DIR__];
    public function getVersion()
    {
        return "1.0.1";
    }

    public static function autoload($className)
    {
        if (isset(static::$classMap[$className])) {
            $classFile = static::$classMap[$className];
            if (strncmp($classFile, '@', 1) === 0) {
                $classFile = static::getAlias($classFile);
            }
        } elseif (strpos($className, '\\') !== false) {
            $classFile = static::getAlias('@' . str_replace('\\', '/', $className) . '.php', false);
            if ($classFile === false || !is_file($classFile)) {
                return;
            }
        } else {
            return;
        }

        include $classFile;

        if (!class_exists($className, false) && !interface_exists($className, false) && !trait_exists($className, false)) {
            throw new Exception("Unable to find '$className' in file: $classFile. Namespace missing?");
        }
    }

    public static function getAlias($alias, $throwException = true)
    {
        if (strncmp((string)$alias, '@', 1) !== 0) {
            // not an alias
            return $alias;
        }

        $pos = strpos($alias, '/');
        $root = $pos === false ? $alias : substr($alias, 0, $pos);

        if (isset(static::$aliases[$root])) {
            if (is_string(static::$aliases[$root])) {
                return $pos === false ? static::$aliases[$root] : static::$aliases[$root] . substr($alias, $pos);
            }

            foreach (static::$aliases[$root] as $name => $path) {
                if (strpos($alias . '/', $name . '/') === 0) {
                    return $path . substr($alias, strlen($name));
                }
            }
        }

        if ($throwException) {
            throw new Exception("Invalid path alias: $alias");
        }

        return false;
    }

}