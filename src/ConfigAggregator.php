<?php

/**
 * @see       https://github.com/laminas/laminas-config-aggregator for the canonical source repository
 * @copyright https://github.com/laminas/laminas-config-aggregator/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-config-aggregator/blob/master/LICENSE.md New BSD License
 */

namespace Laminas\ConfigAggregator;

use Closure;
use Generator;
use Laminas\Stdlib\ArrayUtils\MergeRemoveKey;
use Laminas\Stdlib\ArrayUtils\MergeReplaceKeyInterface;
use Webimpress\SafeWriter\Exception\ExceptionInterface as FileWriterException;
use Webimpress\SafeWriter\FileWriter;

use function array_key_exists;
use function class_exists;
use function date;
use function file_exists;
use function get_class;
use function gettype;
use function is_array;
use function is_callable;
use function is_int;
use function is_object;
use function is_string;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function strpos;
use function var_export;

/**
 * Aggregate configuration generated by configuration providers.
 */
class ConfigAggregator
{
    const ENABLE_CACHE = 'config_cache_enabled';

    const CACHE_FILEMODE = 'config_cache_filemode';

    const CACHE_TEMPLATE = <<< 'EOT'
<?php

/**
 * This configuration cache file was generated by %s
 * at %s
 */
return %s;

EOT;

    /**
     * @var array
     */
    private $config;

    /**
     * @param array $providers Array of providers. These may be callables, or
     *     string values representing classes that act as providers. If the
     *     latter, they must be instantiable without constructor arguments.
     * @param null|string $cachedConfigFile Configuration cache file; config is
     *     loaded from this file if present, and written to it if not. null
     *     disables caching.
     * @param array $postProcessors Array of processors. These may be callables, or
     *     string values representing classes that act as processors. If the
     *     latter, they must be instantiable without constructor arguments.
     */
    public function __construct(
        array $providers = [],
        $cachedConfigFile = null,
        array $postProcessors = []
    ) {
        if ($this->loadConfigFromCache($cachedConfigFile)) {
            return;
        }

        $this->config = $this->loadConfigFromProviders($providers);
        $this->config = $this->postProcessConfig($postProcessors, $this->config);
        $this->cacheConfig($this->config, $cachedConfigFile);
    }

    /**
     * @return array
     */
    public function getMergedConfig()
    {
        return $this->config;
    }

    /**
     * Resolve a provider.
     *
     * If the provider is a string class name, instantiates that class and
     * tests if it is callable, returning it if true.
     *
     * If the provider is a callable, returns it verbatim.
     *
     * Raises an exception for any other condition.
     *
     * @param string|callable $provider
     * @return callable
     * @throws InvalidConfigProviderException
     */
    private function resolveProvider($provider)
    {
        if (is_string($provider)) {
            if (! class_exists($provider)) {
                throw InvalidConfigProviderException::fromNamedProvider($provider);
            }
            $provider = new $provider();
        }

        if (! is_callable($provider)) {
            $type = $this->detectVariableType($provider);
            throw InvalidConfigProviderException::fromUnsupportedType($type);
        }

        return $provider;
    }

    /**
     * Resolve a processor.
     *
     * If the processor is a string class name, instantiates that class and
     * tests if it is callable, returning it if true.
     *
     * If the processor is a callable, returns it verbatim.
     *
     * Raises an exception for any other condition.
     *
     * @param string|callable $processor
     * @return callable
     * @throws InvalidConfigProcessorException
     */
    private function resolveProcessor($processor)
    {
        if (is_string($processor)) {
            if (! class_exists($processor)) {
                throw InvalidConfigProcessorException::fromNamedProcessor($processor);
            }
            $processor = new $processor();
        }

        if (! is_callable($processor)) {
            $type = $this->detectVariableType($processor);
            throw InvalidConfigProcessorException::fromUnsupportedType($type);
        }

        return $processor;
    }

    /**
     * Perform a recursive merge of two multi-dimensional arrays.
     *
     * @codingStandardsIgnoreStart
     * Copied from https://github.com/laminas/laminas-stdlib/blob/980ce463c29c1a66c33e0eb67961bba895d0e19e/src/ArrayUtils.php#L269
     * @codingStandardsIgnoreEnd
     *
     * @param array $a
     * @param array $b
     *
     * @return $a
     */
    private function mergeArray(array $a, array $b)
    {
        foreach ($b as $key => $value) {
            if ($value instanceof MergeReplaceKeyInterface) {
                $a[$key] = $value->getData();
            } elseif (isset($a[$key]) || array_key_exists($key, $a)) {
                if ($value instanceof MergeRemoveKey) {
                    unset($a[$key]);
                } elseif (is_int($key)) {
                    $a[] = $value;
                } elseif (is_array($value) && is_array($a[$key])) {
                    $a[$key] = $this->mergeArray($a[$key], $value);
                } else {
                    $a[$key] = $value;
                }
            } else {
                if (! $value instanceof MergeRemoveKey) {
                    $a[$key] = $value;
                }
            }
        }
        return $a;
    }

    /**
     * Merge configuration from a provider with existing configuration.
     *
     * @param array $mergedConfig Passed by reference as a performance/resource
     *     optimization.
     * @param mixed|array $config Configuration generated by the $provider.
     * @param callable $provider Provider responsible for generating $config;
     *     used for exception messages only.
     * @return void
     * @throws InvalidConfigProviderException
     */
    private function mergeConfig(&$mergedConfig, $config, callable $provider)
    {
        if (! is_array($config)) {
            $type = $this->detectVariableType($provider);

            throw new InvalidConfigProviderException(sprintf(
                'Cannot read config from %s; does not return array',
                $type
            ));
        }

        $mergedConfig = $this->mergeArray($mergedConfig, $config);
    }

    /**
     * Iterate providers, merging config from each with the previous.
     *
     * @param array $providers
     * @return array
     */
    private function loadConfigFromProviders(array $providers)
    {
        $mergedConfig = [];
        foreach ($providers as $provider) {
            $provider = $this->resolveProvider($provider);
            $config = $provider();
            if (! $config instanceof Generator) {
                $this->mergeConfig($mergedConfig, $config, $provider);
                continue;
            }

            // Handle generators
            foreach ($config as $cfg) {
                $this->mergeConfig($mergedConfig, $cfg, $provider);
            }
        }
        return $mergedConfig;
    }

    /**
     * Attempt to load the configuration from a cache file.
     *
     * @param null|string $cachedConfigFile
     * @return bool
     */
    private function loadConfigFromCache($cachedConfigFile)
    {
        if (null === $cachedConfigFile) {
            return false;
        }

        if (! file_exists($cachedConfigFile)) {
            return false;
        }

        $this->config = require $cachedConfigFile;
        return true;
    }

    /**
     * Attempt to cache discovered configuration.
     *
     * @param array $config
     * @param null|string $cachedConfigFile
     */
    private function cacheConfig(array $config, $cachedConfigFile)
    {
        if (null === $cachedConfigFile) {
            return;
        }

        if (empty($config[static::ENABLE_CACHE])) {
            return;
        }

        $contents = sprintf(
            self::CACHE_TEMPLATE,
            get_class($this),
            date('c'),
            var_export($config, true)
        );

        $mode = isset($config[self::CACHE_FILEMODE]) ? $config[self::CACHE_FILEMODE] : null;
        $this->writeCache($cachedConfigFile, $contents, $mode);
    }

    /**
     * @return array
     */
    private function postProcessConfig(array $processors, array $config)
    {
        foreach ($processors as $processor) {
            $processor = $this->resolveProcessor($processor);
            $config = $processor($config);
        }

        return $config;
    }

    /**
     * @param Closure|object|callable $variable
     *
     * @return string
     */
    private function detectVariableType($variable)
    {
        if ($variable instanceof Closure) {
            return 'Closure';
        }

        if (is_object($variable)) {
            return get_class($variable);
        }

        if (is_callable($variable)) {
            return is_string($variable) ? $variable : gettype($variable);
        }

        return gettype($variable);
    }

    /**
     * Attempt to cache discovered configuration.
     *
     * @param string $cachedConfigFile
     * @param null|string $contents
     * @param null|int $mode
     */
    private function writeCache($cachedConfigFile, $contents, $mode)
    {
        // Suppresses notice when FileWriter falls back to system temp dir
        // This can be removed if https://github.com/webimpress/safe-writer/pull/6 is merged
        set_error_handler(function ($errno, $errstr) {
            return strpos('tempnam():', $errstr) !== 0;
        }, E_NOTICE);

        try {
            if ($mode !== null) {
                FileWriter::writeFile($cachedConfigFile, $contents, $mode);
            } else {
                FileWriter::writeFile($cachedConfigFile, $contents);
            }
        } catch (FileWriterException $e) {
            // ignore errors writing cache file
        }

        restore_error_handler();
    }
}
