<?php

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Ładowanie zmiennych środowiskowych
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

echo "Diagnostyka importu logów\n";
echo "=======================\n\n";

// Wyświetlamy konfigurację bazy danych (bez hasła)
echo "Konfiguracja bazy danych:\n";
echo "Host: " . $_ENV['DB_HOST'] . "\n";
echo "Nazwa bazy: " . $_ENV['DB_NAME'] . "\n";
echo "Użytkownik: " . $_ENV['DB_USER'] . "\n";
echo "Hasło: " . (empty($_ENV['DB_PASS']) ? "[puste]" : "[ustawione]") . "\n\n";

// Sprawdzanie połączenia z bazą danych bezpośrednio przez PDO
try {
    $dsn = "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    echo "Próba połączenia z bazą bezpośrednio przez PDO...\n";
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], $options);
    echo "✓ Połączono z bazą danych przez PDO\n";

    // Sprawdzenie wersji MySQL
    $version = $pdo->query("SELECT VERSION() AS version")->fetch();
    echo "Wersja MySQL: " . $version['version'] . "\n";

    // Sprawdzenie istniejących tabel
    echo "\nSprawdzanie istniejących tabel...\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($tables)) {
        echo "Brak tabel w bazie danych!\n";
    } else {
        echo "Znalezione tabele: " . implode(", ", $tables) . "\n";
    }

    // Sprawdzenie czy istnieje tabela monitors
    echo "\nPróba wykonania prostego zapytania...\n";
    try {
        $stmt = $pdo->prepare("SELECT 1");
        $stmt->execute();
        echo "✓ Proste zapytanie wykonane pomyślnie\n";
    } catch (PDOException $e) {
        echo "✗ Błąd podczas wykonywania prostego zapytania: " . $e->getMessage() . "\n";
    }

    // Sprawdzenie tworzenia tabeli
    echo "\nPróba utworzenia tymczasowej tabeli...\n";
    try {
        $pdo->exec("
            CREATE TEMPORARY TABLE IF NOT EXISTS test_table (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL
            )
        ");
        echo "✓ Tymczasowa tabela utworzona\n";

        // Wstawianie danych
        $stmt = $pdo->prepare("INSERT INTO test_table (name) VALUES (:name)");
        $stmt->execute(['name' => 'Test record']);
        echo "✓ Rekord dodany do tymczasowej tabeli\n";

        // Pobieranie danych
        $result = $pdo->query("SELECT * FROM test_table")->fetchAll();
        echo "✓ Pobrano dane z tymczasowej tabeli: " . count($result) . " rekordów\n";
    } catch (PDOException $e) {
        echo "✗ Błąd podczas testu tymczasowej tabeli: " . $e->getMessage() . "\n";
    }

} catch (PDOException $e) {
    echo "✗ Błąd połączenia z bazą danych: " . $e->getMessage() . "\n";
    exit(1);
}

// Sprawdzenie, czy istnieją wymagane pliki
echo "\nSprawdzanie plików logów...\n";
$requiredFiles = [
    'LOG_DIR' => $_ENV['LOG_DIR'] ?? null,
    'HISTORY_LOG' => $_ENV['HISTORY_LOG'] ?? null,
    'API_LOG' => $_ENV['API_LOG'] ?? null
];

foreach ($requiredFiles as $key => $path) {
    if (empty($path)) {
        echo "✗ Brak ścieżki dla: {$key}\n";
        continue;
    }

    if (file_exists($path)) {
        echo "✓ Plik {$key} istnieje: {$path}\n";
        echo "   Rozmiar: " . filesize($path) . " bajtów\n";
        echo "   Uprawnienia: " . substr(sprintf('%o', fileperms($path)), -4) . "\n";
    } else {
        echo "✗ Plik {$key} nie istnieje: {$path}\n";
    }
}

// Sprawdzenie czy mamy dostęp do skryptów aplikacji
echo "\nSprawdzanie skryptów aplikacji...\n";
$requiredPaths = [
    'bin/import.php',
    'src/Models/Ping.php',
    'src/Models/Monitor.php',
    'src/Models/Tag.php',
    'src/Services/LogParser.php',
    'src/Services/ApiLogParser.php'
];

foreach ($requiredPaths as $path) {
    if (file_exists($path)) {
        echo "✓ Skrypt {$path} istnieje\n";
    } else {
        echo "✗ Skrypt {$path} nie istnieje!\n";
    }
}

echo "\n=======================\n";
echo "Diagnostyka zakończona\n";