<?php

/*
 * Copyright 2011 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\AopBundle\DependencyInjection\Compiler;

use CG\Core\ClassUtils;

use JMS\AopBundle\Exception\RuntimeException;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Reference;
use CG\Proxy\Enhancer;
use CG\Proxy\InterceptionGenerator;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;

/**
 * Matches pointcuts against service methods.
 *
 * This pass will collect the advices that match a certain method, and then
 * generate proxy classes where necessary.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class PointcutMatchingPass implements CompilerPassInterface
{
    private $pointcuts;
    private $container;

    public function __construct(array $pointcuts = null)
    {
        $this->pointcuts = $pointcuts;
    }

    public function process(ContainerBuilder $container)
    {
        $this->container = $container;
        $pointcuts = $this->getPointcuts();

        $interceptors = array();
        foreach ($container->getDefinitions() as $id => $definition) {
            $this->processDefinition($definition, $pointcuts, $interceptors);

            $this->processInlineDefinitions($pointcuts, $interceptors, $definition->getArguments());
            $this->processInlineDefinitions($pointcuts, $interceptors, $definition->getMethodCalls());
            $this->processInlineDefinitions($pointcuts, $interceptors, $definition->getProperties());
        }

        $container
            ->getDefinition('jms_aop.interceptor_loader')
            ->addArgument($interceptors)
        ;
    }

    private function processInlineDefinitions($pointcuts, &$interceptors, array $a) {
        foreach ($a as $k => $v) {
            if ($v instanceof Definition) {
                $this->processDefinition($v, $pointcuts, $interceptors);
            } else if (is_array($v)) {
                $this->processInlineDefinitions($pointcuts, $interceptors, $v);
            }
        }
    }

    private function processDefinition(Definition $definition, $pointcuts, &$interceptors)
    {
        if ($definition->isSynthetic()) {
            return;
        }

        if ($definition->getFactoryService() || $definition->getFactoryClass()) {
            return;
        }

        if ($file = $definition->getFile()) {
            require_once $file;
        }

        if (!class_exists($definition->getClass())) {
            return;
        }

        $class = new \ReflectionClass($definition->getClass());

        // check if class is matched
        $matchingPointcuts = array();
        foreach ($pointcuts as $interceptor => $pointcut) {
            if ($pointcut->matchesClass($class)) {
                $matchingPointcuts[$interceptor] = $pointcut;
            }
        }

        if (empty($matchingPointcuts)) {
            return;
        }

        $this->addResources($class, $this->container);

        if ($class->isFinal()) {
            return;
        }

        $classAdvices = array();
        foreach ($class->getMethods(\ReflectionMethod::IS_PROTECTED | \ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isFinal()) {
                continue;
            }

            $advices = array();
            foreach ($matchingPointcuts as $interceptor => $pointcut) {
                if ($pointcut->matchesMethod($method)) {
                    $advices[] = $interceptor;
                }
            }

            if (empty($advices)) {
                continue;
            }

            $classAdvices[$method->name] = $advices;
        }

        if (empty($classAdvices)) {
            return;
        }

        $interceptors[ClassUtils::getUserClass($class->name)] = $classAdvices;

        $generator = new InterceptionGenerator();
        $generator->setFilter(function(\ReflectionMethod $method) use ($classAdvices) {
            return isset($classAdvices[$method->name]);
        });
        if ($file) {
            $generator->setRequiredFile($file);
        }

        $matcher = $this->container->get('jms_aop.proxy_matcher');
        $proxy = $matcher->getEnhanced($definition);
        $proxy->addGenerator($generator);

        $definition->addMethodCall('__CGInterception__setLoader', array(
            new Reference('jms_aop.interceptor_loader')
        ));
    }

    private function addResources(\ReflectionClass $class)
    {
        do {
            $this->container->addResource(new FileResource($class->getFilename()));
        } while (($class = $class->getParentClass()) && $class->getFilename());
    }

    private function getPointcuts()
    {
        if (null !== $this->pointcuts) {
            return $this->pointcuts;
        }

        $pointcuts = $pointcutReferences = array();

        foreach ($this->container->findTaggedServiceIds('jms_aop.pointcut') as $id => $attr) {
            if (!isset($attr[0]['interceptor'])) {
                throw new RuntimeException('You need to set the "interceptor" attribute for the "jms_aop.pointcut" tag of service "'.$id.'".');
            }

            $pointcutReferences[$attr[0]['interceptor']] = new Reference($id);
            $pointcuts[$attr[0]['interceptor']] = $this->container->get($id);
        }

        $this->container
            ->getDefinition('jms_aop.pointcut_container')
            ->addArgument($pointcutReferences)
        ;

        return $pointcuts;
    }
}
