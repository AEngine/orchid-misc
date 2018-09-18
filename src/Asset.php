<?php

namespace AEngine\Orchid\Misc;

use AEngine\Orchid\App;
use AEngine\Orchid\Exception\FileNotFoundException;
use AEngine\Orchid\View;
use DirectoryIterator;

class Asset
{
    /**
     * JS (default)
     */
    const SCRIPT_DEFAULT = 'text/javascript';

    /**
     * ES6 Module
     */
    const SCRIPT_MODULE = 'module';

    /**
     * Resource map
     *
     * @var array
     */
    public static $map = [];

    /**
     * Generates resource string based on the resources map
     *
     * @return null|string
     */
    public static function resource()
    {
        $request = App::getInstance()->request();
        $include = [];

        if (static::$map) {
            $pathname = '/' . ltrim($request->getUri()->getPath(), '/');
            $uri = explode('/', $pathname);
            array_shift($uri); // remove first slash

            // search masks
            foreach (static::$map as $mask => $map) {
                if (is_array($map)) {
                    if ($mask === $pathname) {
                        $include = array_merge($include, static::resourceIterator($map));
                        continue;
                    }

                    /* #\.html$# */
                    if (substr($mask, 0, 1) == '#' && substr($mask, -1) == '#') {
                        if (preg_match($mask, $pathname, $match)) {
                            $include = array_merge($include, static::resourceIterator($map));
                            continue;
                        }
                    }

                    /* /example/* */
                    if (strpos($mask, '*') !== false) {
                        $pattern = '#^' . str_replace('\\*', '(.*)', preg_quote($mask, '#')) . '#';
                        if (preg_match($pattern, $pathname, $match)) {
                            $include = array_merge($include, static::resourceIterator($map));
                            continue;
                        }
                    }

                    /* /example/:id */
                    if (strpos($mask, ':') !== false) {
                        $parts = explode('/', $mask);
                        array_shift($parts);

                        if (count($uri) == count($parts)) {
                            $matched = true;

                            foreach ($parts as $index => $part) {
                                if (':' !== substr($part, 0, 1) && $uri[$index] != $parts[$index]) {
                                    $matched = false;
                                    break;
                                }
                            }

                            if ($matched) {
                                $include = array_merge($include, static::resourceIterator($map));
                                continue;
                            }
                        }
                    }
                }
            }

            // previous checks have failed
            if (empty($include)) {
                $include = static::resourceIterator(static::$map);
            }
        }

        return $include ? implode("\n", $include) : null;
    }

    /**
     * Bypasses the passed array and returns an array of strings to connect resources
     *
     * @param array $list
     *
     * @return array
     */
    protected static function resourceIterator(array $list)
    {
        $include = [];

        foreach ($list as $path => $option) {
            if (!is_array($option)) {
                $path = $option;
                $option = [];
            }
            $ext = $option['extension'] ?? (pathinfo($path)['extension'] ?? '');
            $path = $path . (isset($option['version']) ? '?ver=' . $option['version'] : '');

            switch ($ext) {
                case 'js':
                    $include[] = '<script type="' . ($option['type'] ?? static::SCRIPT_DEFAULT) . '" src="' . $path . '"></script>';
                    break;
                case 'css':
                    $include[] = '<link rel="stylesheet" type="text/css" href="' . $path . '" />';
                    break;
                case 'less':
                    $include[] = '<link rel="stylesheet/less" type="text/css" href="' . $path . '" />';
                    break;
            }
        }

        return $include;
    }

    /**
     * Collect all of the templates from folder 'template' and of all loaded modules
     *
     * @return string
     */
    public static function template()
    {
        $app = App::getInstance();
        $template = [];

        // catalog manually from the templates
        foreach ($app->pathList('template') as $path) {
            $template = array_merge($template, static::templateIterator($app, $path));
        }

        // modules that have templates
        foreach ($app->getModules() as $module) {
            if ($path = $app->path($module . ':template')) {
                $template = array_merge($template, static::templateIterator($app, $path));
            }
        }

        return $template ? implode("\n", $template) : null;
    }

    /**
     * Recursively specified directory and collects templates
     *
     * @param App    $app
     * @param string $dir
     * @param string $initial
     *
     * @return string
     * @throws FileNotFoundException
     */
    protected static function templateIterator(App $app, $dir, $initial = '')
    {
        $dir = realpath($dir);
        $template = [];

        foreach (new DirectoryIterator($dir) as $item) {
            if (!$item->isDot()) {
                if ($item->isDir()) {
                    $template = array_merge(
                        $template,
                        static::templateIterator(
                            $app,
                            $app->path($dir . '/' . $item->getBasename()),
                            $initial ? $initial : $dir
                        )
                    );
                } else {
                    if ($item->isFile()) {
                        $file = realpath($item->getPathname());
                        $ext = pathinfo($file)['extension'];
                        if (in_array($ext, ['tpl', 'ejs'])) {
                            $name = str_replace(
                                ['/', '.tpl', '.ejs'],
                                ['-', '', ''],
                                explode($initial ? $initial : $dir, $file)[1]
                            );

                            switch ($ext) {
                                case 'tpl':
                                    $template[] = '<script id="tpl' . $name . '" type="text/template">' .
                                        View::fetch($file) . '</script>';
                                    break;
                                case 'ejs':
                                    $template[] = '<script id="tpl' . $name . '" type="text/template">' .
                                        file_get_contents($file) . '</script>';
                                    break;
                            }
                        }
                    }
                }
            }
        }

        return $template;
    }
}
