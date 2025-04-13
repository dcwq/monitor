<?php

namespace App;

use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\DriverManager;
use PDO;
use PDOException;

class Connection
{
    private static ?DoctrineConnection $instance = null;

    public static function getInstance(): DoctrineConnection
    {
        if (self::$instance === null) {
            try {
                $connectionParams = [
                    'dbname' => $_ENV['DB_NAME'],
                    'user' => $_ENV['DB_USER'],
                    'password' => $_ENV['DB_PASS'],
                    'host' => $_ENV['DB_HOST'],
                    'driver' => 'pdo_mysql',
                    'charset' => 'utf8mb4'
                ];

                self::$instance = DriverManager::getConnection($connectionParams);
            } catch (PDOException $e) {
                throw new PDOException($e->getMessage(), (int)$e->getCode());
            }
        }

        return self::$instance;
    }
}