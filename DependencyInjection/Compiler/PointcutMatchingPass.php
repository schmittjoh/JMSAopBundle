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

use gossi\codegen\generator\CodeFileGenerator;
use gossi\codegen\model\PhpClass;
use gossi\codegen\model\PhpMethod;
use gossi\codegen\model\PhpParameter;
use gossi\codegen\model\PhpProperty;
use gossi\codegen\utils\ReflectionUtils;
use JMS\AopBundle\Exception\RuntimeException;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use JMS\AopBundle\Aop\PointcutInterface;

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
    private $cacheDir;
    private $generator;
    /**
     * @var ContainerBuilder
     */
    private $container;

    /**
     * @param array<PointcutInterface> $pointcuts
     */
    public function __construct(array $pointcuts = null)
    {
        $this->pointcuts = $pointcuts;
        $this->generator = new CodeFileGenerator();
    }

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
     * @param array<PointcutInterface> $pointcuts
     * @param array<string,string> $interceptors
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
     * @param array<PointcutInterface> $pointcuts
     * @param array<string,string> $interceptors
     */
    private function processDefinition(Definition $definition, $pointcuts, &$interceptors)
    {
        if ($definition->isSynthetic()) {
            return;
        }

        // Symfony 2.6 getFactory method
        // TODO: Use only getFactory when bumping require to Symfony >= 2.6
        if (method_exists($definition, 'getFactory') && $definition->getFactory()) {
            return;
        }
        if (!method_exists($definition, 'getFactory') && ($definition->getFactoryService() || $definition->getFactoryClass())) {
            return;
        }

        if ($originalFilename = $definition->getFile()) {
            require_once $originalFilename;
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

        $phpClass = $this->getProxyClass($class);
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


            $phpClass->setMethod($this->getProxyMethod($method));

            $classAdvices[$method->name] = $advices;
        }

        if (empty($classAdvices)) {
            return;
        }

        $interceptors[$this->getUserClass($class->name)] = $classAdvices;

        $proxyFilename = $this->cacheDir.'/'.str_replace('\\', '-', $class->name).'.php';

        if ($originalFilename) {
            $relativeOriginalFilename = $this->relativizePath($proxyFilename, $originalFilename);
            if ($relativeOriginalFilename[0] === '.') {
                $phpClass->addRequiredFile($this->cacheDir . '/' . $relativeOriginalFilename);
            } else {
                $phpClass->addRequiredFile($relativeOriginalFilename);
            }
        }

        $phpClass->setNamespace($this->getProxyNamespace($class));
        $this->writeProxyClass($proxyFilename, $this->generator->generate($phpClass));

        $definition->setFile($proxyFilename);
        $definition->setClass($phpClass->getName());
        $definition->addMethodCall('__CGInterception__setLoader', array(
            new Reference('jms_aop.interceptor_loader')
        ));
    }

    private function writeProxyClass($proxyFilename, $proxyClassCode)
    {
        if (!is_dir($dir = dirname($proxyFilename))) {
            if (false === @mkdir($dir, 0777, true)) {
                throw new \RuntimeException(sprintf('Could not create directory "%s".', $dir));
            }
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException(sprintf('The directory "%s" is not writable.', $dir));
        }
        file_put_contents($proxyFilename, $proxyClassCode);
    }

    /**
     * @param \ReflectionMethod $method
     * @return PhpMethod
     */
    private function getProxyMethod(\ReflectionMethod $method)
    {
        $genMethod = new PhpMethod($method->name);
        $parameters = $method->getParameters();
        $paramList = array();
        foreach ($parameters as $parameter) {
            $phpParam = PhpParameter::fromReflection($parameter);
            $paramList[] = '$' . $parameter->name;
            $genMethod->addParameter($phpParam);
        }
        if (method_exists($method, 'getReturnType')) {
            $genMethod->setType($method->getReturnType());
        }
        $className = var_export($this->getUserClass($method->getDeclaringClass()->name), true);
        $methodName = var_export($method->name, true);
        $interceptorCode =
            "\$ref = new \\ReflectionMethod($className, $methodName);\n"
            .'$interceptors = $this->__CGInterception__loader->loadInterceptors($ref, $this, array(%s));'."\n"
            .'$invocation = new \JMS\AopBundle\Aop\Proxy\MethodInvocation($ref, $this, array(' . implode(', ', $paramList) . '), $interceptors);'."\n\n"
            .'return $invocation->proceed();'
        ;
        $genMethod->setBody($interceptorCode);

        return $genMethod;

    }

    /**
     * @param \ReflectionClass $class
     * @return PhpClass
     */
    private function getProxyClass(\ReflectionClass $class)
    {
        $phpClass = new PhpClass($class->name);
        $loaderProp = new PhpProperty('__CGInterception__loader');
        $loaderProp->setVisibility(PhpProperty::VISIBILITY_PRIVATE);
        $phpClass->setProperty($loaderProp);
        $loaderSetter = new PhpMethod('__CGInterception__setLoader');
        $loaderParam = new PhpParameter('loader');
        $loaderParam->setType('JMS\AopBundle\Aop\InterceptorLoaderInterface');
        $loaderSetter->addParameter($loaderParam);
        $loaderSetter->setBody('$this->__CGInterception__loader = $loader;');
        $phpClass->setMethod($loaderSetter);
        return $phpClass;

    }

    private function getProxyNamespace(\ReflectionClass $class)
    {
        $cacheDir = $this->container->getParameter('jms_aop.cache_dir');
        $prefix = 'EnhancedProxy'.substr(md5($cacheDir), 0, 8).'_';
        $classPart = sha1($class->name).'\\';
        $separator = '__CG__\\';
        $userClass = $this->getUserClass($class->name);

        return $prefix . $classPart . $separator . $userClass;

    }
    private function getUserClass($className)
    {
        if (false === $pos = strrpos($className, '\\'.NamingStrategyInterface::SEPARATOR.'\\')) {
            return $className;
        }

        return substr($className, $pos + NamingStrategyInterface::SEPARATOR_LENGTH + 2);
    }

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
