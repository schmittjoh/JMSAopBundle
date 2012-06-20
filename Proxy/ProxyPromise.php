<?php
/**
 * @author nfx
 */

namespace JMS\AopBundle\Proxy;
use CG\Proxy\GeneratorInterface;
use CG\Proxy\Enhancer;
use ReflectionClass;

class ProxyPromise
{
    /**
     * @var GeneratorInterface[]
     */
    private $generators = array();

    public function addGenerator(GeneratorInterface $generator)
    {
        $this->generators[] = $generator;
    }

    public function getEnhancer(ReflectionClass $class)
    {
        return new Enhancer($class, array(), $this->generators);
    }
}
