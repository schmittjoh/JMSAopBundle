<?php
/**
 * @author nfx
 */

namespace JMS\AopBundle\Proxy;

use Symfony\Component\DependencyInjection\Definition;
use CG\Proxy\GeneratorInterface;

class ProxyMatcher
{
    protected $cacheDir;

    /**
     * @var \SplObjectStorage
     */
    protected $storage;

    public function __construct($cacheDir)
    {
        $this->cacheDir = $cacheDir;
        $this->storage = new \SplObjectStorage();
    }

    /**
     * @param Definition $definition
     * @return ProxyPromise
     */
    public function getEnhanced(Definition $definition)
    {
        if (isset($this->storage[$definition])) {
            return $this->storage[$definition];
        }

        $promise = new ProxyPromise($this);
        $this->storage[$definition] = $promise;
        return $promise;
    }

    public function writeProxyFiles()
    {
        foreach($this->storage as $definition) {
            $promise = $this->storage[$definition];

            $class = new \ReflectionClass($definition->getClass());
            $enhancer = $promise->getEnhancer($class);

            $filename = $this->cacheDir.'/'.str_replace('\\', '-', $class->name).'.php';
            $proxyClassName = $enhancer->getClassName($class);

            $enhancer->writeClass($filename);
            $definition->setFile($filename);
            $definition->setClass($proxyClassName);
        }
    }
}
