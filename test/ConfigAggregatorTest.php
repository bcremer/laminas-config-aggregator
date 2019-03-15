<?php
/**
 * @see       https://github.com/zendframework/zend-config-aggregator for the canonical source repository
 * @copyright Copyright (c) 2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @copyright Copyright (c) 2015-2016 Mateusz Tymek (http://mateusztymek.pl)
 * @license   https://github.com/zendframework/zend-config-aggregator/blob/master/LICENSE.md New BSD License
 */

namespace ZendTest\ConfigAggregator;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;
use Zend\ConfigAggregator\ConfigAggregator;
use Zend\ConfigAggregator\InvalidConfigProcessorException;
use Zend\ConfigAggregator\InvalidConfigProviderException;
use ZendTest\ConfigAggregator\Resources\BarConfigProvider;
use ZendTest\ConfigAggregator\Resources\FooConfigProvider;
use ZendTest\ConfigAggregator\Resources\FooPostProcessor;

use function file_exists;
use function var_export;

class ConfigAggregatorTest extends TestCase
{
    public function testConfigAggregatorRisesExceptionIfProviderClassDoesNotExist()
    {
        $this->expectException(InvalidConfigProviderException::class);
        new ConfigAggregator(['NonExistentConfigProvider']);
    }

    public function testConfigAggregatorRisesExceptionIfProviderIsNotCallable()
    {
        $this->expectException(InvalidConfigProviderException::class);
        new ConfigAggregator([stdClass::class]);
    }

    public function testConfigAggregatorMergesConfigFromProviders()
    {
        $aggregator = new ConfigAggregator([FooConfigProvider::class, BarConfigProvider::class]);
        $config = $aggregator->getMergedConfig();
        $this->assertEquals(['foo' => 'bar', 'bar' => 'bat'], $config);
    }

    public function testProviderCanBeClosure()
    {
        $aggregator = new ConfigAggregator([
            function () {
                return ['foo' => 'bar'];
            },
        ]);
        $config = $aggregator->getMergedConfig();
        $this->assertEquals(['foo' => 'bar'], $config);
    }

    public function testProviderCanBeGenerator()
    {
        $aggregator = new ConfigAggregator([
            function () {
                yield ['foo' => 'bar'];
                yield ['baz' => 'bat'];
            },
        ]);
        $config = $aggregator->getMergedConfig();
        $this->assertEquals(['foo' => 'bar', 'baz' => 'bat'], $config);
    }

    public function testConfigAggregatorCanCacheConfig()
    {
        vfsStream::setup(__FUNCTION__);
        $cacheFile = vfsStream::url(__FUNCTION__) . '/expressive_config_loader';
        new ConfigAggregator([
            function () {
                return ['foo' => 'bar', ConfigAggregator::ENABLE_CACHE => true];
            }
        ], $cacheFile);
        $this->assertTrue(file_exists($cacheFile));
        $cachedConfig = include $cacheFile;
        $this->assertInternalType('array', $cachedConfig);
        $this->assertEquals(['foo' => 'bar', ConfigAggregator::ENABLE_CACHE => true], $cachedConfig);
    }

    public function testConfigAggregatorSetsDefaultModeOnCache()
    {
        vfsStream::setup(__FUNCTION__);
        $cacheFile = vfsStream::url(__FUNCTION__) . '/expressive_config_loader';
        new ConfigAggregator([
            function () {
                return ['foo' => 'bar', ConfigAggregator::ENABLE_CACHE => true];
            }
        ], $cacheFile);
        $this->assertEquals(0644, fileperms($cacheFile) & 0777);
    }

    public function testConfigAggregatorSetsModeOnCache()
    {
        vfsStream::setup(__FUNCTION__);
        $cacheFile = vfsStream::url(__FUNCTION__) . '/expressive_config_loader';
        new ConfigAggregator([
            function () {
                return ['foo' => 'bar', ConfigAggregator::ENABLE_CACHE => true];
            }
        ], $cacheFile, [], 0600);
        $this->assertEquals(0600, fileperms($cacheFile) & 0777);
    }

    public function testConfigAggregatorSetsHandlesUnwritableCache()
    {
        vfsStream::setup(__FUNCTION__);
        $root = vfsStream::url(__FUNCTION__);
        chmod($root, 0400);
        $cacheFile = $root . '/expressive_config_loader';

        $foo = function () use ($cacheFile) {
            new ConfigAggregator([
                function () {
                    return ['foo' => 'bar', ConfigAggregator::ENABLE_CACHE => true];
                }
            ], $cacheFile, [], 0600);
        };
        @$foo(); // suppress warning

        $errors = error_get_last();
        $this->assertNotNull($errors);
        $this->assertFalse(file_exists($cacheFile));
    }

    public function testConfigAggregatorRespectsCacheLock()
    {
        $expected = [
            'cache' => 'locked',
            ConfigAggregator::ENABLE_CACHE => true,
        ];

        vfsStream::setup(__FUNCTION__);
        $cacheFile = vfsStream::url(__FUNCTION__) . '/expressive_config_loader';

        $fh = fopen($cacheFile, 'c');
        flock($fh, LOCK_EX);
        fputs($fh, '<' . '?php return ' . var_export($expected, true) . ';');

        $method = new ReflectionMethod(ConfigAggregator::class, 'cacheConfig');
        $method->setAccessible(true);
        $method->invoke(
            new ConfigAggregator(),
            ['foo' => 'bar', ConfigAggregator::ENABLE_CACHE => true],
            $cacheFile,
            0644
        );
        flock($fh, LOCK_UN);
        fclose($fh);

        $this->assertEquals($expected, require $cacheFile);
    }

    public function testConfigAggregatorCanLoadConfigFromCache()
    {
        $expected = [
            'foo' => 'bar',
            ConfigAggregator::ENABLE_CACHE => true,
        ];

        $root = vfsStream::setup(__FUNCTION__);
        vfsStream::newFile('expressive_config_loader')
            ->at($root)
            ->setContent('<' . '?php return ' . var_export($expected, true) . ';');
        $cacheFile = vfsStream::url(__FUNCTION__ . '/expressive_config_loader');

        $aggregator = new ConfigAggregator([], $cacheFile);
        $mergedConfig = $aggregator->getMergedConfig();

        $this->assertInternalType('array', $mergedConfig);
        $this->assertEquals($expected, $mergedConfig);
    }

    public function testConfigAggregatorRisesExceptionIfProcessorClassDoesNotExist()
    {
        $this->expectException(InvalidConfigProcessorException::class);
        new ConfigAggregator([], null, ['NonExistentConfigProcessor']);
    }

    public function testConfigAggregatorRisesExceptionIfProcessorIsNotCallable()
    {
        $this->expectException(InvalidConfigProcessorException::class);
        new ConfigAggregator([], null, [stdClass::class]);
    }

    public function testProcessorCanBeClosure()
    {
        $aggregator = new ConfigAggregator([], null, [
            function (array $config) {
                return $config + ['processor' => 'closure'];
            },
        ]);

        $config = $aggregator->getMergedConfig();
        $this->assertEquals(['processor' => 'closure'], $config);
    }

    public function testConfigAggregatorCanPostProcessConfiguration()
    {
        $aggregator = new ConfigAggregator([
            function () {
                return ['foo' => 'bar'];
            },
        ], null, [new FooPostProcessor]);
        $mergedConfig = $aggregator->getMergedConfig();

        $this->assertEquals(['foo' => 'bar', 'post-processed' => true], $mergedConfig);
    }
}
