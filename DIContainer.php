<?php


namespace glx\DI\Symfony;


use glx\DI\Symfony\E\ContainerLoadingFailed;
use glx\DI\Symfony\E\DIContainerLoadingException;
use glx\DI\Symfony\E\InvalidParameter;
use glx\DI\Symfony\E\WrongInfrastructure;
use Psr\Container\ContainerInterface;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Dotenv\Exception\FormatException;
use Symfony\Component\Dotenv\Exception\PathException;

class DIContainer
{
    private static string $defaultConfigFile = __DIR__ . '/config/defaults.yaml';
    private static int $defaultConfigPriority = -100;
    private static string $cacheFolderName = 'di';

    /**
     * @param array $configFiles [optional] List of config files. Example: ['/path/to/config1.xml', '/path/to/config2.yaml'] or
     * ['/path/to/config1.xml' => 10, '/path/to/config2.yaml' => 20] where value is loading priority (higher will be loaded later).
     * @param string|null $projectRoot [optional] Project root location. Default is parent of modules location.
     * @param string|null $dotEnvLocation [optional] .env file location. Default is project root.
     * @param string|null $cacheDir [optional] Main cache directory. Default is {project root}/cache.
     * @param bool $useDefaults [optional] if true default module config will be included with negative priority. Default is true.
     *
     * @return ContainerInterface
     *
     * @throws DIContainerLoadingException
     */
    public static function load(
        array $configFiles = [],
        string $projectRoot = null,
        string $dotEnvLocation = null,
        string $cacheDir = null,
        bool $useDefaults = true,

    ): ContainerInterface
    {
        // load .env if location specified, it may contain other required information
        if ($dotEnvLocation) {
            self::loadEnvironment($dotEnvLocation);
        }

        // fetching project root
        $projectRoot = $projectRoot ?? (string)getenv('PROJECT_ROOT');
        if (!$projectRoot || !is_dir($projectRoot)) {
            $projectRoot = static::detectDotEnvLocation() ?? dirname(__DIR__, 5);
        }
        $projectRoot = realpath($projectRoot);
        if (!$projectRoot) {
            throw new WrongInfrastructure("Project root directory can't be located");
        }
        putenv("PROJECT_ROOT={$projectRoot}");


        // default .env location is project root
        if (!$dotEnvLocation) {
            $dotEnvLocation = $projectRoot;
            self::loadEnvironment($dotEnvLocation);
        }
        $dotEnvLocation = realpath($dotEnvLocation);

        if (!getenv('SHARED_KERNEL_LOCATION')) {
            putenv('SHARED_KERNEL_LOCATION=' . dirname(__DIR__, 4));
        }


        // include module default config
        if ($useDefaults) {
            $configFiles[self::$defaultConfigFile] = self::$defaultConfigPriority;
        }

        // normalize configs list with considering priority order
        $configs = [];
        foreach ($configFiles as $key => $value) {
            if (is_int($key)) {
                if (is_string($value)) {
                    $file = $value;
                } elseif (is_array($value) && is_string($value['file'])) {
                    $file = $value['file'];
                    $priority = $value['priority'];
                } else {
                    continue;
                }
            } elseif (is_string($key)) {
                $file = $key;
                if (is_int($value)) {
                    $priority = $value;
                } elseif (is_array($value)) {
                    $priority = $value['priority'];
                } else {
                    continue;
                }
            } else {
                continue;
            }
            $configs[$file] = $priority ?? 0;
        }
        asort($configs);
        $configFiles = array_keys($configs);



        // fetching main cache directory
        $cacheDir = $cacheDir ?? (string)getenv('CACHE_DIR');
        if (!$cacheDir) {
            $cacheDir = $projectRoot . '/cache';
        }
        if (!is_dir($cacheDir)) {
            throw new WrongInfrastructure("Project cache directory does not exist at '{$cacheDir}'");
        }

        putenv("CACHE_DIR={$cacheDir}");

        // fetching container by key based on normalized input parameters
        return self::loadContainer(self::generateCacheKey([
            $projectRoot,
            $configFiles,
            $dotEnvLocation,
        ]), $configFiles);
    }


    /**
     * @throws ContainerLoadingFailed
     */
    private static function loadContainer(string $cacheKey, array $configFiles): ContainerInterface
    {
        $className = 'container_' . $cacheKey;
        $cacheDir = getenv('CACHE_DIR') . DIRECTORY_SEPARATOR . self::$cacheFolderName;
        $filePath = $cacheDir . DIRECTORY_SEPARATOR . $className . '.php';
        $namespace = "glx\Cache\DI";
        $qualified = "{$namespace}\\{$className}";

        $cache = new ConfigCache($filePath, self::needRefresh($cacheKey));
        if (!$cache->isFresh()) {
            try {
                if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755) && !is_dir($cacheDir)) {
                    throw new ContainerLoadingFailed("Can't create container cache directory '{$cacheDir}'");
                }

                $container = new ContainerBuilder();

                // set required defaults
                $container->setParameter('project.root', getenv('PROJECT_ROOT'));
                $container->setParameter('project.cache_dir', getenv('CACHE_DIR'));

                // autodetect config format
                $loader = new DelegatingLoader(new LoaderResolver([
                    new YamlFileLoader($container, new FileLocator()),
                    new XmlFileLoader($container, new FileLocator())
                ]));

                // loading configs
                foreach ($configFiles as $file) {
                    $loader->load($file);
                }

                // compile
                $container->compile();

                // write container to cache
                $dumper = new PhpDumper($container);
                $cache->write(
                    $dumper->dump(['class' => $className, 'namespace' => $namespace]),
                    $container->getResources()
                );
            } catch (\Exception $e) {
                throw new ContainerLoadingFailed('Symfony container compilation failed: ' . $e->getMessage(), 0, $e);
            }
        }
        // include cached container file
        require_once $filePath;
        // create container object
        return new $qualified;
    }

    /**
     * May be used for automatic cache resetting at production.
     * @param string $cacheKey
     * @return bool
     */
    protected static function needRefresh(string $cacheKey): bool
    {
        return getenv('ENV_MODE') === 'dev';
    }

    /**
     * @throws InvalidParameter
     * @throws WrongInfrastructure
     */
    protected static function loadEnvironment(string $dotEnvLocation): void
    {
        try {
            (new Dotenv())->loadEnv("{$dotEnvLocation}/.env", 'ENV_MODE', 'dev');
        } catch (FormatException $e) {
            throw new WrongInfrastructure('Invalid .env file format');
        } catch (PathException $e) {
            throw new InvalidParameter('Invalid .env file location');
        }
    }

    /**
     * @throws InvalidParameter
     */
    protected static function generateCacheKey(array $data): string
    {
        try {
            return md5(json_encode($data, JSON_THROW_ON_ERROR));
        } catch (\JsonException $e) {
            throw new InvalidParameter("Can't generate container cache key because of invalid input data");
        }
    }

    protected static function detectDotEnvLocation(): ?string
    {
        $path = __DIR__ . '/../../';
        while(is_dir($path) && ($real = realpath($path)) !== '/') {
            if (is_file($path . '/.env')) {
                return $real;
            }
            $path .= '../';
        }
        return null;
    }
}
