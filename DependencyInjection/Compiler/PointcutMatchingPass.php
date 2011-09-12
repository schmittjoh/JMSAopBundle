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
    private $evaluators;
    private $cacheDir;
    private $container;

    public function __construct(array $evaluators = null)
    {
        $this->evaluators = $evaluators;
    }

    public function process(ContainerBuilder $container)
    {
        $this->container = $container;
        $this->cacheDir = $container->getParameter('jms_aop.cache_dir').'/proxies';
        $evaluators = $this->getEvaluators();

        $interceptors = array();
        foreach ($container->getDefinitions() as $id => $definition) {
            $this->processDefinition($definition, $evaluators, $interceptors);

            $this->processInlineDefinitions($evaluators, $interceptors, $definition->getArguments());
            $this->processInlineDefinitions($evaluators, $interceptors, $definition->getMethodCalls());
            $this->processInlineDefinitions($evaluators, $interceptors, $definition->getProperties());
        }

        $container
            ->getDefinition('jms_aop.interceptor_loader')
            ->addArgument($interceptors)
        ;
    }

    private function processInlineDefinitions($evaluators, &$interceptors, array $a) {
        foreach ($a as $k => $v) {
            if ($v instanceof Definition) {
                $this->processDefinition($v, $evaluators, $interceptors);
            } else if (is_array($v)) {
                $this->processInlineDefinitions($evaluators, $interceptors, $v);
            }
        }
    }

    private function processDefinition(Definition $definition, $evaluators, &$interceptors)
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

        $class = new \ReflectionClass($definition->getClass());

        // check if class is matched
        $matchingEvaluators = array();
        foreach ($evaluators as $interceptor => $evaluator) {
            if ($evaluator->matchesClass($class)) {
                $matchingEvaluators[$interceptor] = $evaluator;
            }
        }

        if (empty($matchingEvaluators)) {
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
            foreach ($matchingEvaluators as $interceptor => $evaluator) {
                if ($evaluator->matchesMethod($method)) {
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
        $enhancer = new Enhancer($class, array(), array(
            $generator
        ));
        $enhancer->writeClass($filename = $this->cacheDir.'/'.str_replace('\\', '-', $class->name).'.php');
        $definition->setFile($filename);
        $definition->setClass($enhancer->getClassName($class));
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

    private function getEvaluators()
    {
        if (null !== $this->evaluators) {
            return $this->evaluators;
        }

        $evaluators = $evaluatorReferences = array();

        foreach ($this->container->findTaggedServiceIds('jms_aop.pointcut_evaluator') as $id => $attr) {
            if (!isset($attr[0]['interceptor'])) {
                throw new RuntimeException('You need to set the "interceptor" attribute for the "jms_aop.pointcut_evaluator" tag of service "'.$id.'".');
            }

            $evaluatorReferences[$attr[0]['interceptor']] = new Reference($id);
            $evaluators[$attr[0]['interceptor']] = $this->container->get($id);
        }

        $this->container
            ->getDefinition('jms_aop.pointcut_container')
            ->addArgument($evaluatorReferences)
        ;

        return $evaluators;
    }
}