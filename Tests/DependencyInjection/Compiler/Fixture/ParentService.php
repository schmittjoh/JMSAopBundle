<?php
namespace JMS\AopBundle\Tests\DependencyInjection\Compiler\Fixture;


class ParentService
{
    public function parentDelete()
    {
        return true;
    }

    public function overwrittenDelete()
    {
        return false;
    }
}