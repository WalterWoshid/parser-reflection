<?php

declare(strict_types=1);
/*
 * Go! AOP framework
 *
 * @copyright Copyright 2014-2022, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Go\ParserReflection\Instrument;

/**
 * Special class for resolving path for different file systems, wrappers, etc
 *
 * @see  http://stackoverflow.com/questions/4049856/replace-phps-realpath/4050444
 * @see  http://bugs.php.net/bug.php?id=52769
 *
 * @link https://github.com/goaop/framework/blob/master/src/Instrument/PathResolver.php
 */
class PathResolver
{

    /**
     * Custom replacement for realpath() and stream_resolve_include_path()
     *
     * @param array|string $somePath             Path without normalization or array of paths
     * @param bool         $shouldCheckExistence Flag for checking existence of resolved filename
     *
     * @return array|bool|string
     */
    public static function realpath(array|string $somePath, bool $shouldCheckExistence = false): bool|array|string
    {
        // Do not resolve empty string/false/arrays into the current path
        if (!$somePath) {
            return $somePath;
        }

        if (is_array($somePath)) {
            return array_map([__CLASS__, __FUNCTION__], $somePath);
        }
        // Trick to get scheme name and path in one action. If no scheme, then there will be only one part
        $components = explode('://', $somePath, 2);
        [$pathScheme, $path] = isset($components[1]) ? $components : [null, $components[0]];

        // Optimization to bypass complex logic for simple paths (e.g. not in phar archives)
        if (!$pathScheme && ($fastPath = stream_resolve_include_path($somePath))) {
            return $fastPath;
        }

        $isRelative = !$pathScheme && ($path[0] !== '/') && ($path[1] !== ':');
        if ($isRelative) {
            $path = getcwd() . DIRECTORY_SEPARATOR . $path;
        }

        // resolve path parts (single dot, double dot and double delimiters)
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (str_contains($path, '.')) {
            $parts     = explode(DIRECTORY_SEPARATOR, $path);
            $absolutes = [];
            foreach ($parts as $part) {
                if ('.' === $part) {
                    continue;
                }

                if ('..' === $part) {
                    array_pop($absolutes);
                } else {
                    $absolutes[] = $part;
                }
            }
            $path = implode(DIRECTORY_SEPARATOR, $absolutes);
        }

        if ($pathScheme) {
            $path = "$pathScheme://$path";
        }

        if ($shouldCheckExistence && !file_exists($path)) {
            return false;
        }

        return $path;
    }
}
