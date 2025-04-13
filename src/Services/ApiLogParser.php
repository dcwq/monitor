<?php

namespace App\Services;

use App\Models\Monitor;
use App\Models\Ping;
use App\Models\Tag;
use PDO;
use PDOException;
use App\Connection;

class ApiLogParser
{
    private string $apiLogPath;
    private ?string $lastProcessedLine = null;

    public function __construct(string $apiLogPath = null)
    {
        $this->apiLogPath = $apiLogPath ?? $_ENV['API_LOG'];
    }

    public function parse(bool $incrementally = true): int
    {
        if (!file_exists($this->apiLogPath)) {
            throw new \RuntimeException("API log file not found: {$this->apiLogPath}");
        }

        $importCount = 0;
        $fileHandle = fopen($this->apiLogPath, 'r');

        if ($fileHandle === false) {
            throw new \RuntimeException("Could not open log file: {$this->apiLogPath}");
        }

        if ($incrementally) {
            $this->lastProcessedLine = $this->getLastProcessedTimestamp();
        }

        while (($line = fgets($fileHandle)) !== false) {
            $matches = [];
            // Match: [2025-04-12 19:55:13] [INFO] Otrzymano ping 'run' dla monitora 'ping-dc3307' (czas: 0s)
            if (preg_match('/^\[(.*?)\] \[INFO\] Otrzymano ping \'(.*?)\' dla monitora \'(.*?)\'.*?\(czas: ([\d\.]+)s\)(?:\s+\[Tagi: (.*?)\])?$/', $line, $matches)) {
                $timestamp = $matches[1];
                $state = $matches[2];
                $monitorName = $matches[3];
                $duration = floatval($matches[4]);
                $tagsStr = isset($matches[5]) ? $matches[5] : '';

                if ($incrementally && $this->lastProcessedLine !== null && $timestamp <= $this->lastProcessedLine) {
                    continue;
                }

                $tags = [];
                if (!empty($tagsStr)) {
                    $tags = array_map('trim', explode(',', $tagsStr));
                }

                try {
                    $this->processPing($timestamp, $state, $monitorName, $duration, $tags);
                    $importCount++;
                    $this->lastProcessedLine = $timestamp;
                } catch (\Exception $e) {
                    // Zamiast rzucać wyjątek, tylko logujemy błąd
                    error_log("Błąd przetwarzania linii: $line. Błąd: " . $e->getMessage());
                    continue;
                }
            }
        }

        fclose($fileHandle);

        if ($incrementally && $this->lastProcessedLine !== null) {
            $this->saveLastProcessedTimestamp($this->lastProcessedLine);
        }

        return $importCount;
    }

    private function processPing(string $timestamp, string $state, string $monitorName, float $duration, array $tags = []): void
    {
        // Pobieramy lub tworzymy monitor
        try {
            $db = Connection::getInstance();

            // Najpierw sprawdzamy czy monitor istnieje
            $stmtMonitor = $db->prepare('SELECT id, project_name FROM monitors WHERE name = ?');
            $stmtMonitor->execute([$monitorName]);
            $monitorData = $stmtMonitor->fetch(PDO::FETCH_ASSOC);

            if ($monitorData) {
                $monitorId = $monitorData['id'];
            } else {
                // Tworzymy nowy monitor
                $stmtCreate = $db->prepare('INSERT INTO monitors (name) VALUES (?)');
                $stmtCreate->execute([$monitorName]);
                $monitorId = $db->lastInsertId();
            }

            // Generujemy unikalny identyfikator
            $uniqueId = md5($timestamp . $monitorName . $state . rand(1000, 9999));

            // Dodajemy ping
            $stmtPing = $db->prepare('
                INSERT INTO pings (
                    monitor_id, unique_id, state, duration, exit_code, 
                    host, timestamp, received_at, ip
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?
                )
            ');

            $stmtPing->execute([
                $monitorId,
                $uniqueId,
                $state,
                $state === 'complete' ? $duration * 1000 : 0, // Konwersja na milisekundy
                $state === 'fail' ? 1 : 0,
                'api-log',
                strtotime($timestamp),
                strtotime($timestamp),
                '127.0.0.1'
            ]);

            $pingId = $db->lastInsertId();

            // Dodajemy tagi jeśli istnieją
            if (!empty($tags) && $pingId) {
                foreach ($tags as $tagName) {
                    if (empty(trim($tagName))) {
                        continue;
                    }

                    // Szukamy tag
                    $stmtTag = $db->prepare('SELECT id FROM tags WHERE name = ?');
                    $stmtTag->execute([trim($tagName)]);
                    $tagData = $stmtTag->fetch(PDO::FETCH_ASSOC);

                    if ($tagData) {
                        $tagId = $tagData['id'];
                    } else {
                        // Tworzymy tag
                        $stmtCreateTag = $db->prepare('INSERT INTO tags (name) VALUES (?)');
                        $stmtCreateTag->execute([trim($tagName)]);
                        $tagId = $db->lastInsertId();

                        // Przypisujemy tag do monitora
                        $stmtMonitorTag = $db->prepare('INSERT IGNORE INTO monitor_tags (monitor_id, tag_id) VALUES (?, ?)');
                        $stmtMonitorTag->execute([$monitorId, $tagId]);
                    }

                    // Przypisujemy tag do pinga
                    $stmtPingTag = $db->prepare('INSERT IGNORE INTO ping_tags (ping_id, tag_id) VALUES (?, ?)');
                    $stmtPingTag->execute([$pingId, $tagId]);
                }
            }
        } catch (PDOException $e) {
            throw new \RuntimeException("Błąd bazy danych: " . $e->getMessage(), 0, $e);
        }
    }

    private function getLastProcessedTimestamp(): ?string
    {
        $file = sys_get_temp_dir() . '/cronitor_clone_api_last_processed.txt';

        if (file_exists($file)) {
            return trim(file_get_contents($file));
        }

        return null;
    }

    private function saveLastProcessedTimestamp(string $timestamp): void
    {
        $file = sys_get_temp_dir() . '/cronitor_clone_api_last_processed.txt';
        file_put_contents($file, $timestamp);
    }
}