<?php

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Services\LogParser;
use App\Services\ApiLogParser;

// Ładowanie zmiennych środowiskowych
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

echo "Test parsera logów\n";
echo "================\n\n";

// Funkcja pomocnicza do testowania odczytu pliku
function testReadFile($path) {
    echo "Testowanie odczytu pliku: $path\n";
    if (!file_exists($path)) {
        echo "✗ Plik nie istnieje!\n";
        return false;
    }

    try {
        $handle = fopen($path, 'r');
        if (!$handle) {
            echo "✗ Nie można otworzyć pliku!\n";
            return false;
        }

        $lines = 0;
        $firstLine = null;
        $lastLine = null;

        while (($line = fgets($handle)) !== false) {
            $lines++;
            if ($lines === 1) {
                $firstLine = $line;
            }
            $lastLine = $line;

            // Wyświetlmy kilka pierwszych linii
            if ($lines <= 3) {
                echo "Linia $lines: " . substr($line, 0, 100) . (strlen($line) > 100 ? "..." : "") . "\n";
            }
        }

        fclose($handle);

        echo "✓ Odczytano $lines linii z pliku\n";
        return true;
    } catch (Exception $e) {
        echo "✗ Błąd odczytu pliku: " . $e->getMessage() . "\n";
        return false;
    }
}

// Test parsera logów historii
echo "\n--- Test parsera logów historii ---\n";
$historyLogPath = $_ENV['HISTORY_LOG'];

if (testReadFile($historyLogPath)) {
    try {
        // Wyłączamy PDO na chwilę, żeby zobaczyć czy parser działa prawidłowo
        class MockPDO {
            public function prepare($query) {
                return new MockStatement($query);
            }

            public function beginTransaction() {
                return true;
            }

            public function commit() {
                return true;
            }

            public function lastInsertId() {
                return 1;
            }
        }

        class MockStatement {
            private $query;

            public function __construct($query) {
                $this->query = $query;
            }

            public function execute($params = null) {
                echo "Wykonywanie zapytania: " . $this->query . "\n";
                if ($params) {
                    echo "Z parametrami: " . print_r($params, true) . "\n";
                }
                return true;
            }

            public function fetch() {
                return null;
            }
        }

        // Testujemy parser na pojedynczej linii
        echo "\nTestowanie parsowania przykładowej linii z pliku historii...\n";
        $sampleLine = '[2025-04-12 19:55:13] {"monitor":"ping-dc3307","state":"run","unique_id":"e316b227-01db-4ece-a34c-fcbc5790dbd5","duration":0,"exit_code":0,"host":"moon","timestamp":1744487713,"received_at":1744487713,"ip":"10.14.0.1"}';

        if (preg_match('/^\[(.*?)\] (.*)$/', $sampleLine, $matches)) {
            $timestamp = $matches[1];
            $jsonData = $matches[2];

            echo "Wyodrębniono timestamp: $timestamp\n";

            $pingData = json_decode($jsonData, true);
            if ($pingData && isset($pingData['monitor'])) {
                echo "Zdekodowano dane JSON prawidłowo:\n";
                print_r($pingData);
            } else {
                echo "✗ Błąd dekodowania JSON: " . json_last_error_msg() . "\n";
            }
        } else {
            echo "✗ Wyrażenie regularne nie pasuje do linii!\n";
        }

    } catch (Exception $e) {
        echo "✗ Błąd testowania parsera: " . $e->getMessage() . "\n";
    }
}

// Test parsera logów API
echo "\n--- Test parsera logów API ---\n";
$apiLogPath = $_ENV['API_LOG'];

if (testReadFile($apiLogPath)) {
    try {
        // Testujemy parser na pojedynczej linii
        echo "\nTestowanie parsowania przykładowej linii z pliku API...\n";
        $sampleLine = "[2025-04-12 19:55:13] [INFO] Otrzymano ping 'run' dla monitora 'ping-dc3307' (czas: 0s)";

        if (preg_match('/^\[(.*?)\] \[INFO\] Otrzymano ping \'(.*?)\' dla monitora \'(.*?)\'.*?\(czas: ([\d\.]+)s\)(?:\s+\[Tagi: (.*?)\])?$/', $sampleLine, $matches)) {
            echo "Wyrażenie regularne pasuje do linii!\n";
            echo "Wyodrębnione dane:\n";
            echo "Timestamp: " . $matches[1] . "\n";
            echo "Stan: " . $matches[2] . "\n";
            echo "Monitor: " . $matches[3] . "\n";
            echo "Czas: " . $matches[4] . "\n";
            echo "Tagi: " . (isset($matches[5]) ? $matches[5] : "brak") . "\n";
        } else {
            echo "✗ Wyrażenie regularne nie pasuje do linii!\n";
            echo "Przykładowa linia: " . $sampleLine . "\n";

            // Spróbujmy inne wyrażenie
            echo "\nPróba alternatywnego wyrażenia regularnego...\n";
            if (preg_match('/^\[(.*?)\] \[INFO\] Otrzymano ping \'(.*?)\' dla monitora \'(.*?)\'.*?\(czas: (.*?)s\)/', $sampleLine, $matches)) {
                echo "Alternatywne wyrażenie pasuje!\n";
                print_r($matches);
            } else {
                echo "✗ Alternatywne wyrażenie też nie pasuje!\n";
            }
        }

        // Sprawdźmy czy plik zawiera linie zgodne z naszym wzorcem
        echo "\nSprawdzanie wzorca na rzeczywistych liniach...\n";
        $handle = fopen($apiLogPath, 'r');
        $matchedLines = 0;
        $totalLines = 0;

        while (($line = fgets($handle)) !== false) {
            $totalLines++;
            if (preg_match('/^\[(.*?)\] \[INFO\] Otrzymano ping \'(.*?)\' dla monitora \'(.*?)\'.*?\(czas: ([\d\.]+)s\)(?:\s+\[Tagi: (.*?)\])?$/', $line)) {
                $matchedLines++;
            }

            // Sprawdźmy kilka pierwszych linii
            if ($totalLines <= 3) {
                echo "Linia $totalLines: " . trim($line) . "\n";
                echo "  Pasuje do wzorca: " . (preg_match('/^\[(.*?)\] \[INFO\] Otrzymano ping \'(.*?)\' dla monitora \'(.*?)\'.*?\(czas: ([\d\.]+)s\)(?:\s+\[Tagi: (.*?)\])?$/', $line) ? "TAK" : "NIE") . "\n";
            }
        }

        fclose($handle);

        echo "Pasujące linie: $matchedLines / $totalLines\n";

    } catch (Exception $e) {
        echo "✗ Błąd testowania parsera API: " . $e->getMessage() . "\n";
    }
}

echo "\nTest zakończony\n";