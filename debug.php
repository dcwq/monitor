<?php

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Ładowanie zmiennych środowiskowych
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

echo "Informacje o konfiguracji bazy danych:\n";
echo "DB_HOST: " . ($_ENV['DB_HOST'] ?? 'nie ustawiono') . "\n";
echo "DB_NAME: " . ($_ENV['DB_NAME'] ?? 'nie ustawiono') . "\n";
echo "DB_USER: " . ($_ENV['DB_USER'] ?? 'nie ustawiono') . "\n";
echo "DB_PASS: " . (isset($_ENV['DB_PASS']) ? (empty($_ENV['DB_PASS']) ? "[puste]" : "[ustawione]") : 'nie ustawiono') . "\n";

echo "\nSprawdzanie połączenia z bazą danych...\n";

try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? 'localhost',
        $_ENV['DB_NAME'] ?? 'cronitor_clone'
    );

    echo "DSN: $dsn\n";

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO(
        $dsn,
        $_ENV['DB_USER'] ?? 'root',
        $_ENV['DB_PASS'] ?? '',
        $options
    );

    echo "Połączenie z bazą danych udane!\n";

    // Sprawdź czy baza ma wymagane tabele
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Znalezione tabele: " . implode(", ", $tables) . "\n";

    // Sprawdź wersję serwera MySQL
    $version = $pdo->query("SELECT VERSION() as version")->fetch();
    echo "Wersja MySQL: " . $version['version'] . "\n";

} catch (PDOException $e) {
    echo "BŁĄD PDO: " . $e->getMessage() . "\n";
    echo "Kod błędu: " . $e->getCode() . "\n";

    // Dodatkowe informacje do debugowania
    echo "\nInformacje systemowe:\n";
    echo "PHP version: " . phpversion() . "\n";
    echo "Zainstalowane sterowniki PDO:\n";
    foreach (PDO::getAvailableDrivers() as $driver) {
        echo "- $driver\n";
    }
}