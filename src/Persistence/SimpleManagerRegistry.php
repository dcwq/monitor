<?php

namespace App\Persistence;

class SimpleManagerRegistry implements \Doctrine\Persistence\ManagerRegistry
{
    private array $managers;
    private string $defaultManager;

    public function __construct($entityManager)
    {
        $this->managers = ['default' => $entityManager];
        $this->defaultManager = 'default';
    }

    public function getDefaultConnectionName()
    {
        return $this->defaultManager;
    }

    public function getConnection($name = null)
    {
        $name = $name ?: $this->defaultManager;
        return $this->managers[$name]->getConnection();
    }

    public function getConnections()
    {
        return array_map(
            fn($manager) => $manager->getConnection(),
            $this->managers
        );
    }

    public function getConnectionNames()
    {
        return array_keys($this->managers);
    }

    public function getDefaultManagerName()
    {
        return $this->defaultManager;
    }

    public function getManager($name = null)
    {
        $name = $name ?: $this->defaultManager;
        return $this->managers[$name];
    }

    public function getManagers()
    {
        return $this->managers;
    }

    public function getManagerNames()
    {
        return array_keys($this->managers);
    }

    public function resetManager($name = null)
    {
        return $this->getManager($name);
    }

    public function getAliasNamespace($alias)
    {
        return null;
    }

    public function getManagerForClass($class)
    {
        return $this->getManager();
    }

    public function getRepository($entityClass, $managerName = null)
    {
        $manager = $this->getManager($managerName);
        return $manager->getRepository($entityClass);
    }
}