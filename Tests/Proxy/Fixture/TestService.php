<?php
/**
 * @author nfx
 */

namespace JMS\AopBundle\Tests\Proxy\Fixture;

class TestService
{
    protected $things = array();

    public function getThings()
    {
        return $this->things;
    }
}
