<?php

/**
 * Doctrine ORM bridge
 * Copyright (C) 2013 Tristan Lins
 *
 * PHP version 5
 *
 * @copyright  bit3 UG 2013
 * @author     Tristan Lins <tristan.lins@bit3.de>
 * @package    doctrine-orm
 * @license    LGPL
 * @filesource
 */

namespace Contao\Doctrine\ORM;

use Contao\Doctrine\ORM\Event\DuplicateEntity;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Proxy\Proxy;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Repository extends EntityRepository
{
    public function find($id, $lockMode = LockMode::NONE, $lockVersion = null)
    {
        if (is_scalar($id)) {
            $className = $this->getClassName();
            $class     = new \ReflectionClass($className);

            if ($class->isSubclassOf('Contao\Doctrine\ORM\EntityInterface')) {
                $keys = $class
                    ->getMethod('entityPrimaryKeyNames')
                    ->invoke(null);
            } else {
                $keys = array('id');
            }

            if (count($keys) > 1) {
                $ids = explode('|', $id);
                $id  = array_combine($keys, $ids);
            }
        }
        return parent::find($id, $lockMode, $lockVersion);
    }
}
