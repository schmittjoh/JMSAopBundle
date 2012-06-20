<?php
/**
 * @author nfx
 */

namespace JMS\AopBundle\Tests\Proxy\Fixture;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use JMS\AopBundle\Tests\Proxy\Fixture\SomeGenerator;

class FirstPass implements CompilerPassInterface
{
    function process(ContainerBuilder $container)
    {
        $methodName = '__fistPassMethod';
        $matcher = $container->get('jms_aop.proxy_matcher');
        $generator = new SomeGenerator($methodName);

        $definition = $container->getDefinition('test');

        $proxy = $matcher->getEnhanced($definition);
        $proxy->addGenerator($generator);
        $definition->addMethodCall($methodName);
    }
}