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
use CG\Core\DefaultNamingStrategy;
use CG\Generator\RelativePath;
use JMS\AopBundle\Exception\RuntimeException;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Reference;
use CG\Proxy\Enhancer;
use CG\Proxy\InterceptionGenerator;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use JMS\AopBundle\Aop\PointcutInterface;
use CG\Core\ReflectionUtils;
use Symfony\Component\HttpKernel\Kernel;

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
    /**
     * @var array<PointcutInterface>
     */
    private $pointcuts;

    /**
     * @var string
     */
    private $cacheDir;

    /**
     * @var ContainerBuilder
     */
    private $container;

    /**
     * Constructor.
     *
     * @param PointcutInterface[] $pointcuts
     */
    public function __construct(array $pointcuts = null)
    {
        $this->pointcuts = $pointcuts;
    }

    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        $this->container = $container;
        $this->cacheDir = $container->getParameter('jms_aop.cache_dir').'/proxies';
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

    /**
     * @param PointcutInterface[] $pointcuts
     * @param string[]            $interceptors
     * @param array               $a
     */
    private function processInlineDefinitions($pointcuts, &$interceptors, array $a)
    {
        foreach ($a as $k => $v) {
            if ($v instanceof Definition) {
                $this->processDefinition($v, $pointcuts, $interceptors);
            } elseif (is_array($v)) {
                $this->processInlineDefinitions($pointcuts, $interceptors, $v);
            }
        }
    }

    /**
     * @param Definition          $definition
     * @param PointcutInterface[] $pointcuts
     * @param string[]            $interceptors
     */
    private function processDefinition(Definition $definition, $pointcuts, &$interceptors)
    {
        if ($definition->isSynthetic()) {
            return;
        }

        if (Kernel::VERSION_ID < 20600) {
            if ($definition->getFactory()) {
                return;
            }
        } else {
            if ($definition->getFactoryService() || $definition->getFactoryClass()) {
                return;
            }
        }

        if ($originalFilename = $definition->getFile()) {
            require_once $originalFilename;
        }

        if (!class_exists($definition->getClass())) {
            return;
        }

        $class = new \ReflectionClass($definition->getClass());

        // check if class is matched
        /** @var PointcutInterface[] $matchingPointcuts */
        $matchingPointcuts = array();
        foreach ($pointcuts as $interceptor => $pointcut) {
            if ($pointcut->matchesClass($class)) {
                $matchingPointcuts[$interceptor] = $pointcut;
            }
        }

        if (empty($matchingPointcuts)) {
            return;
        }

        $this->addResources($class);

        if ($class->isFinal()) {
            return;
        }

        $classAdvices = array();
        foreach (ReflectionUtils::getOverrideableMethods($class) as $method) {

            if ('__construct' === $method->name) {
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

        $proxyFilename = $this->cacheDir.'/'.str_replace('\\', '-', $class->name).'.php';

        $generator = new InterceptionGenerator();
        $generator->setFilter(function(\ReflectionMethod $method) use ($classAdvices) {
            return isset($classAdvices[$method->name]);
        });

        if ($originalFilename) {
            $relativeOriginalFilename = $this->relativizePath($proxyFilename, $originalFilename);
            if ($relativeOriginalFilename[0] === '.') {
                $generator->setRequiredFile(new RelativePath($relativeOriginalFilename));
            } else {
                $generator->setRequiredFile($relativeOriginalFilename);
            }
        }
        $enhancer = new Enhancer($class, array(), array(
            $generator
        ));
        $enhancer->setNamingStrategy(new DefaultNamingStrategy('EnhancedProxy'.substr(md5($this->container->getParameter('jms_aop.cache_dir')), 0, 8)));
        $enhancer->writeClass($proxyFilename);
        $definition->setFile($proxyFilename);
        $definition->setClass($enhancer->getClassName($class));
        $definition->addMethodCall('__CGInterception__setLoader', array(
            new Reference('jms_aop.interceptor_loader')
        ));
    }

    /**
     * @param string $targetPath
     * @param string $path
     *
     * @return string
     */
    private function relativizePath($targetPath, $path)
    {
        $commonPath = dirname($targetPath);

        $level = 0;
        while ( ! empty($commonPath)) {
            if (0 === strpos($path, $commonPath)) {
                $relativePath = str_repeat('../', $level).substr($path, strlen($commonPath) + 1);

                return $relativePath;
            }

            $commonPath = dirname($commonPath);
            $level += 1;
        }

        return $path;
    }

    /**
     * @param \ReflectionClass $class
     */
    private function addResources(\ReflectionClass $class)
    {
        do {
            $this->container->addResource(new FileResource($class->getFilename()));
        } while (($class = $class->getParentClass()) && $class->getFilename());
    }

    /**
     * @return PointcutInterface[]
     */
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
