<?php
// config/doctrine-bootstrap.php

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Dotenv\Dotenv;

// Ustawienie domyślnej strefy czasowej dla aplikacji
date_default_timezone_set('Europe/Warsaw');

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
    'charset' => 'utf8mb4',
    'driverOptions' => [
        // Ustaw opcję, aby PDO używało strefę czasową PHP
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+01:00'"
    ]
];

// Getting the EntityManager
$connection = DriverManager::getConnection($connectionParams);

$entityManager = new EntityManager($connection, $config);

return $entityManager;
