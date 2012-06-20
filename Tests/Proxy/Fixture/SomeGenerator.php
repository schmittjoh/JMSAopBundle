<?php
/**
 * @author nfx
 */

namespace JMS\AopBundle\Tests\Proxy\Fixture;

use CG\Proxy\GeneratorInterface;
use CG\Generator\PhpClass;
use CG\Generator\PhpMethod;

class SomeGenerator implements GeneratorInterface
{
    public $methodName;

    public function __construct($methodName)
    {
        $this->methodName = $methodName;
    }

    /**
     * Generates the necessary changes in the class.
     *
     * @param \ReflectionClass $originalClass
     * @param PhpClass $generatedClass The generated class
     * @return void
     */
    function generate(\ReflectionClass $originalClass, PhpClass $generatedClass)
    {
        $method = PhpMethod::create($this->methodName)
            ->setBody("\$this->things[] = '{$this->methodName}';");
        $generatedClass->setMethod($method);
    }
}
