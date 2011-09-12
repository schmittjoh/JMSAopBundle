========
Overview
========

This bundle adds AOP capabilities to Symfony2.

If you haven't heard of AOP yet, it basically allows you to separate a
cross-cutting concern (for example, security checks) into a dedicated class,
and not having to repeat that code in all places where it is needed.

In other words, this allows you to execute custom code before, and after the
invocation of certain methods in your service layer, or your controllers. You
can also choose to skip the invocation of the original method, or throw exceptions.

Installation
------------
Checkout a copy of the code::

    git submodule add https://github.com/schmittjoh/JMSAopBundle.git src/JMS/AopBundle

Then register the bundle with your kernel::

    // in AppKernel::registerBundles()
    $bundles = array(
        // ...
        new JMS\AopBundle\JMSAopBundle(),
        // ...
    );

This bundle also requires the CG library for code generation::

    git submodule add https://github.com/schmittjoh/cg-library.git vendor/cg-library

Make sure that you also register the namespaces with the autoloader::

    // app/autoload.php
    $loader->registerNamespaces(array(
        // ...
        'JMS'              => __DIR__.'/../vendor/bundles',
        'CG'               => __DIR__.'/../vendor/cg-library/src',
        // ...
    ));    


Configuration
-------------
::

    jms_aop:
        cache_dir: %kernel.cache_dir%/jms_aop


Usage
-----
In order to execute custom code, you need two classes. First, you need a so-called
pointcut. The purpose of this class is to make a decision whether a method call 
should be intercepted by a certain interceptor. This decision has to be made
statically only on the basis of the method signature itself.

The second class is the interceptor. This class is being called instead
of the original method. It contains the custom code that you would like to
execute. At this point, you have access to the object on which the method is 
called, and all the arguments which were passed to that method.

Example
-------

As an example, we will be implementing logging for all methods that contain 
"delete".

Pointcut
~~~~~~~~

::

    <?php
    
    use JMS\AopBundle\Aop\PointcutInterface;
    
    class LoggingPointcut implements PointcutInterface
    {
        public function matches(\ReflectionMethod $method)
        {
            return false !== strpos($method->name, 'delete');
        }
    }

::
    
    # services.yml
    services:
        my_logging_pointcut_evaluator:
            class: LoggingPointcutEvaluator
            tags:
                - { name: jms_aop.pointcut_evaluator, interceptor: logging_interceptor }


LoggingInterceptor
~~~~~~~~~~~~~~~~~~

::

    <?php
    
    use CG\Proxy\MethodInterceptorInterface;
    use CG\Proxy\MethodInvocation;
    use Symfony\Component\HttpKernel\Log\LoggerInterface;
    use Symfony\Component\Security\Core\SecurityContextInterface;
    
    class LoggingInterceptor implements MethodInterceptorInterface
    {
        private $context;
        private $logger;
    
        public function __construct(SecurityContextInterface $context,
                                    LoggerInterface $logger)
        {
            $this->context = $context;
            $this->logger = $logger;
        }
    
        public function intercept(MethodInvocation $invocation)
        {
            $user = $this->context->getToken()->getUsername();
            $this->logger->info(sprintf('User "%s" invoked method "%s".', $user, $invocation->reflection->name));
            
            // make sure to proceed with the invocation otherwise the original
            // method will never be called
            return $invocation->proceed();
        }
    }
    
::

    # services.yml
    services:
        logging_interceptor:
            class: LoggingInterceptor
            arguments: [@security.context, @logger]
            
