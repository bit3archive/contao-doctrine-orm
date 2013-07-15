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

use Contao\Doctrine\ORM\Entity;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\EventArgs;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use ORM\Entity\Version;
use Symfony\Component\Serializer\Serializer;

class VersionManager
{
	/**
	 * Calculate a hash from an entity to identify a version.
	 *
	 * @param Entity|array $entity
	 *
	 * @return string
	 * @throws \RuntimeException
	 */
	static public function calculateHash($entity)
	{
		if ($entity instanceof Entity) {
			$entityData = $entity->toArray();
			ksort($entityData);
		}
		else if (is_array($entity)) {
			$entityData = $entity;
			ksort($entityData);
		}
		else if ($entity instanceof \Traversable) {
			$entityData = $entity;
		}
		else {
			throw new \RuntimeException('Illegal argument type ' . gettype(
				$entity
			) . ' for VersionManager::calculateHash');
		}

		$hash = array();
		foreach ($entityData as $value) {
			if (is_array($value)) {
				$hash[] = static::calculateHash($value);
			}
			else if (!is_object($value) || method_exists($value, '__toString')) {
				$hash[] = (string) $value;
			}
			else if ($value instanceof \DateTime) {
				$hash[] = $value->getTimestamp();
			}
			else if ($value instanceof Entity || $value instanceof Collection) {
				// ignore references
			}
			else {
				throw new \RuntimeException('Do not know how to hash object type ' . get_class($value));
			}
		}
		$hash = implode('~', $hash);
		$hash = md5($hash);

		return $hash;
	}

	public function getVersion($versionId)
	{
		$versionRepository = EntityHelper::getRepository('ORM:Version');

		return $versionRepository->find($versionId);
	}

	public function findVersion(Entity $entity, $entityData = null)
	{
		$versionRepository = EntityHelper::getRepository('ORM:Version');

		$entityClassName = Helper::createShortenEntityName($entity);
		$entityId        = $entity->id();
		$entityHash      = static::calculateHash($entityData ? : $entity);

		return $versionRepository->findOneBy(
			array(
				 'entityClass' => $entityClassName,
				 'entityId'    => $entityId,
				 'entityHash'  => $entityHash
			),
			array('createdAt' => 'DESC')
		);
	}

	public function findVersions(Entity $entity)
	{
		$versionRepository = EntityHelper::getRepository('ORM:Version');

		$entityClassName = Helper::createShortenEntityName($entity);
		$entityId        = $entity->id();

		return $versionRepository->findBy(
			array(
				 'entityClass' => $entityClassName,
				 'entityId'    => $entityId,
			),
			array('createdAt' => 'DESC')
		);
	}

	public function getEntityVersion($version)
	{
		if (is_string($version)) {
			$version = $this->getVersion($version);
		}
		if ($version === null) {
			return null;
		}
		if (!$version instanceof Version) {
			throw new \RuntimeException('Version ID or entity is expected for VersionManager::getEntityVersion, got ' . gettype($version));
		}

		/** @var Serializer $serializer */
		$serializer = $GLOBALS['container']['doctrine.orm.entitySerializer'];

		$entityRepository = EntityHelper::getRepository($version->getEntityClass());

		/** @var Entity $entity */
		$entity = $entityRepository->find($version->getEntityId());

		/** @var Entity $entity */
		$previousEntity = $serializer->deserialize(
			$version->getData(),
			$entityRepository->getClassName(),
			'json'
		);

		$targetClass = new \ReflectionClass($entity);
		$sourceClass = new \ReflectionClass($previousEntity);

		foreach ($sourceClass->getProperties() as $sourceProperty) {
			$sourceValue = $sourceProperty->getValue($entity);
			if ($sourceValue instanceof Entity || $sourceValue instanceof Collection) {
				// skip references
			}
			else {
				$targetProperty = $targetClass->getProperty($sourceProperty->getName());
				$sourceProperty->setAccessible(true);
				$targetProperty->setAccessible(true);
				$targetProperty->setValue($entity, $sourceProperty->getValue($previousEntity));
			}
		}

		return $entity;
	}
}