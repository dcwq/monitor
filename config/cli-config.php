<?php
// config/cli-config.php

use Doctrine\ORM\Tools\Console\ConsoleRunner;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;

// Get EntityManager from bootstrap file
$entityManager = require_once __DIR__ . '/doctrine-bootstrap.php';

return ConsoleRunner::run(
    new SingleManagerProvider($entityManager)
);
