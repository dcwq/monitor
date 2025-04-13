<?php
/**
 * Prosty serwer API dla klonu Cronitor
 *
 * Przyjmuje pingi od klienta Cronitor i zapisuje dane do pliku
 * Wersja 0.2.0 - Dodano obsługę tagów
 */

// Konfiguracja
$config = [
    'log_dir' => __DIR__ . '/../../data/logs',
    'history_file' => __DIR__ . '/../../data/logs/cronitor-history.log',
    'tags_index_file' => __DIR__ . '/../../data/logs/cronitor-tags-index.json', // Nowy plik indeksu tagów
    'enable_auth' => false,
    'api_key' => 'twoj-tajny-klucz-api',
    'allow_origin' => '*', // '*' pozwala na dostęp z dowolnej domeny
];

// Utwórz katalog logów, jeśli nie istnieje
if (!file_exists($config['log_dir'])) {
    if (!mkdir($concurrentDirectory = $config['log_dir'], 0755, true) && !is_dir($concurrentDirectory)) {
        throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
    }
}

// Ustawienia nagłówków CORS
header('Access-Control-Allow-Origin: ' . $config['allow_origin']);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Obsługa żądania OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Funkcja zapisująca dane do pliku historii
 */
function appendToHistory($data) {
    global $config;

    $timestamp = date('Y-m-d H:i:s');
    $formatted_data = json_encode($data, JSON_PRETTY_PRINT);
    $log_entry = "[{$timestamp}] {$formatted_data}\n";

    $result = file_put_contents($config['history_file'], $log_entry, FILE_APPEND);

    // Aktualizuj indeks tagów, jeśli dane zawierają tagi
    if ($result && isset($data['tags']) && is_array($data['tags']) && !empty($data['tags'])) {
        updateTagsIndex($data);
    }

    return $result;
}

/**
 * Funkcja aktualizująca indeks tagów
 */
function updateTagsIndex($data) {
    global $config;

    // Dane, które chcemy zaindeksować
    $monitor = $data['monitor'];
    $tags = $data['tags'];
    $timestamp = $data['received_at'];
    $state = $data['state'];
    $unique_id = $data['unique_id'];

    // Wczytaj obecny indeks
    $index = [];
    if (file_exists($config['tags_index_file'])) {
        $index_content = file_get_contents($config['tags_index_file']);
        if (!empty($index_content)) {
            $index = json_decode($index_content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Jeśli indeks jest uszkodzony, zaczynamy od nowa
                $index = [];
            }
        }
    }

    // Dla każdego taga, dodaj lub aktualizuj wpis w indeksie
    foreach ($tags as $tag) {
        if (!isset($index[$tag])) {
            $index[$tag] = [];
        }

        if (!isset($index[$tag][$monitor])) {
            $index[$tag][$monitor] = [];
        }

        // Dodaj informację o tym pingu
        $index[$tag][$monitor][] = [
            'unique_id' => $unique_id,
            'state' => $state,
            'timestamp' => $timestamp
        ];

        // Ogranicz liczbę zapisanych wpisów dla każdego monitora (zachowaj 20 ostatnich)
        if (count($index[$tag][$monitor]) > 20) {
            $index[$tag][$monitor] = array_slice($index[$tag][$monitor], -20);
        }
    }

    // Zapisz zaktualizowany indeks
    file_put_contents($config['tags_index_file'], json_encode($index, JSON_PRETTY_PRINT));
}

/**
 * Funkcja logująca
 */
function logMessage($level, $message) {
    global $config;

    $timestamp = date('Y-m-d H:i:s');
    $log_file = $config['log_dir'] . '/api.log';
    $log_entry = "[{$timestamp}] [{$level}] {$message}\n";

    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

/**
 * Weryfikacja klucza API (jeśli włączona)
 */
function verifyApiKey() {
    global $config;

    if (!$config['enable_auth']) {
        return true;
    }

    $headers = getallheaders();
    $auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : '';

    if (preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
        $token = $matches[1];
        return $token === $config['api_key'];
    }

    return false;
}

/**
 * Endpoint do testowania połączenia
 */
function handlePingTest() {
    echo json_encode([
        'status' => 'success',
        'message' => 'API działa poprawnie',
        'timestamp' => time()
    ]);
}

/**
 * Endpoint do wyszukiwania monitorów według tagów
 */
function handleTagSearch() {
    global $config;

    // Sprawdź, czy parametr tag został dostarczony
    if (!isset($_GET['tag']) || empty($_GET['tag'])) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Parametr "tag" jest wymagany'
        ]);
        return;
    }

    $tag = $_GET['tag'];

    // Wczytaj indeks tagów
    if (!file_exists($config['tags_index_file'])) {
        echo json_encode([
            'status' => 'success',
            'tag' => $tag,
            'monitors' => []
        ]);
        return;
    }

    $index_content = file_get_contents($config['tags_index_file']);
    $index = json_decode($index_content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Błąd podczas odczytu indeksu tagów'
        ]);
        return;
    }

    // Sprawdź, czy tag istnieje w indeksie
    if (!isset($index[$tag])) {
        echo json_encode([
            'status' => 'success',
            'tag' => $tag,
            'monitors' => []
        ]);
        return;
    }

    // Zwróć listę monitorów z danym tagiem
    echo json_encode([
        'status' => 'success',
        'tag' => $tag,
        'monitors' => $index[$tag]
    ]);
}

/**
 * Endpoint do obsługi pingów monitorów
 */
function handlePing() {
    // Sprawdź, czy otrzymano dane JSON
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Nieprawidłowy format JSON'
        ]);
        logMessage('ERROR', 'Nieprawidłowy format JSON: ' . $input);
        return;
    }

    // Sprawdź wymagane pola
    $required_fields = ['monitor', 'state', 'unique_id'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field])) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => "Brak wymaganego pola: {$field}"
            ]);
            logMessage('ERROR', "Brak wymaganego pola: {$field}");
            return;
        }
    }

    // Upewnij się, że pole tags istnieje i jest tablicą
    if (!isset($data['tags']) || !is_array($data['tags'])) {
        $data['tags'] = []; // Domyślnie pusta tablica, jeśli brak tagów
    }

    // Dodaj dodatkowe informacje
    $data['received_at'] = time();
    $data['ip'] = $_SERVER['REMOTE_ADDR'];

    // Zapisz do historii
    if (appendToHistory($data)) {
        // Log success message
        $message = "Otrzymano ping '{$data['state']}' dla monitora '{$data['monitor']}'";
        if (isset($data['duration'])) {
            $message .= " (czas: {$data['duration']}s)";
        }

        // Dodaj informację o tagach do logu
        if (!empty($data['tags'])) {
            $message .= " [Tagi: " . implode(', ', $data['tags']) . "]";
        }

        logMessage('INFO', $message);

        // Zwróć sukces
        echo json_encode([
            'status' => 'success',
            'message' => 'Ping zapisany pomyślnie',
            'monitor' => $data['monitor'],
            'state' => $data['state'],
            'tags' => $data['tags'],
            'received_at' => $data['received_at']
        ]);
    } else {
        // Obsługa błędu zapisu
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Nie można zapisać danych do pliku historii'
        ]);
        logMessage('ERROR', 'Nie można zapisać danych do pliku historii');
    }
}

// Główna logika przetwarzania żądania
try {
    // Sprawdź klucz API
    if (!verifyApiKey()) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Nieautoryzowany dostęp'
        ]);
        logMessage('ERROR', 'Nieautoryzowany dostęp');
        exit;
    }

    // Routing na podstawie ścieżki
    $request_uri = $_SERVER['REQUEST_URI'];
    $path = parse_url($request_uri, PHP_URL_PATH);

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && (strpos($path, '/cronitor-ping.php') !== false)) {
        // Sprawdź, czy to zapytanie o tagi
        if (isset($_GET['tag'])) {
            handleTagSearch();
        } else {
            // Endpoint testowy
            handlePingTest();
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && (strpos($path, '/cronitor-ping.php') !== false)) {
        // Endpoint do obsługi pingów
        handlePing();
    } else {
        // Nieznany endpoint
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Endpoint nie istnieje'
        ]);
    }
} catch (Exception $e) {
    // Obsługa nieoczekiwanych błędów
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Wewnętrzny błąd serwera'
    ]);
    logMessage('ERROR', 'Wyjątek: ' . $e->getMessage());
}
