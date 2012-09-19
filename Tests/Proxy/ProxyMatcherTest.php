<?php
/**
 * @author nfx
 */

namespace JMS\AopBundle\Tests\Proxy;

use JMS\AopBundle\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Compiler\ResolveParameterPlaceHoldersPass;
use JMS\AopBundle\DependencyInjection\JMSAopExtension;
use JMS\AopBundle\DependencyInjection\Compiler\WriteProxiesPass;
use JMS\AopBundle\Tests\Proxy\Fixture\FirstPass;
use JMS\AopBundle\Tests\Proxy\Fixture\AnotherPass;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ProxyMatcherTest extends \PHPUnit_Framework_TestCase
{
    private $cacheDir;
    private $fs;

    public function testProcess()
    {
        $container = $this->getContainer();
        $container->register('test', 'JMS\AopBundle\Tests\Proxy\Fixture\TestService');

        $this->process($container);

        $service = $container->get('test');
        $this->assertInstanceOf('JMS\AopBundle\Tests\Proxy\Fixture\TestService', $service);

        $this->assertEquals(array('__fistPassMethod', '__ultimatelyAnotherMethod'), $service->getThings());
    }

    protected function setUp()
    {
        $this->cacheDir = sys_get_temp_dir() . '/jms_aop_test';
        $this->fs = new Filesystem();

        if (is_dir($this->cacheDir)) {
            $this->fs->remove($this->cacheDir);
        }

        if (false === @mkdir($this->cacheDir, 0777, true)) {
            throw new RuntimeException(sprintf('Could not create cache dir "%s".', $this->cacheDir));
        }
    }

    protected function tearDown()
    {
        $this->fs->remove($this->cacheDir);
    }

    private function getContainer()
    {
        $container = new ContainerBuilder();

        $extension = new JMSAopExtension();
        $extension->load(array(array('cache_dir' => $this->cacheDir)), $container);

        return $container;
    }

    private function process(ContainerBuilder $container)
    {
        $pass = new ResolveParameterPlaceHoldersPass();
        $pass->process($container);

        $pass = new FirstPass();
        $pass->process($container);

        $pass = new AnotherPass();
        $pass->process($container);

        $pass = new WriteProxiesPass();
        $pass->process($container);
    }
}
