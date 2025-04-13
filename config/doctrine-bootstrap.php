<?php
// config/doctrine-bootstrap.php

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$paths = [__DIR__ . '/../src/Entity'];
$isDevMode = true;

// Setup Doctrine
$config = ORMSetup::createAttributeMetadataConfiguration(
    $paths,
    $isDevMode,
    null,
    null
);

// Database configuration parameters
$connectionParams = [
    'dbname' => $_ENV['DB_NAME'],
    'user' => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASS'],
    'host' => $_ENV['DB_HOST'],
    'driver' => 'pdo_mysql',
    'charset' => 'utf8mb4'
];

// Getting the EntityManager
$connection = DriverManager::getConnection($connectionParams);

$entityManager = new EntityManager($connection, $config);

return $entityManager;
