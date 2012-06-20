<?php
/**
 * @author nfx
 */

namespace JMS\AopBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;

class WriteProxiesPass implements CompilerPassInterface
{
    function process(ContainerBuilder $container)
    {
        $matcher = $container->get('jms_aop.proxy_matcher');
        $matcher->writeProxyFiles();
    }
}
