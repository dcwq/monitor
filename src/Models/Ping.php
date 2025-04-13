<?php

namespace App\Models;

use App\Connection;
use PDO;

class Ping
{
    public ?int $id = null;
    public int $monitor_id;
    public string $unique_id;
    public string $state;
    public ?float $duration = null;
    public ?int $exit_code = null;
    public ?string $host = null;
    public int $timestamp;
    public int $received_at;
    public ?string $ip = null;
    public ?string $error = null;
    public array $tags = [];

    public function save(): bool
    {
        $db = Connection::getInstance();

        // Sprawdź czy to jest nowy ping
        if ($this->id === null) {
            $stmt = $db->prepare('
            INSERT INTO pings (
                monitor_id, unique_id, state, duration, exit_code, host, 
                timestamp, received_at, ip, error
            ) VALUES (
                :monitor_id, :unique_id, :state, :duration, :exit_code, :host,
                :timestamp, :received_at, :ip, :error
            )
        ');

            $result = $stmt->execute([
                'monitor_id' => $this->monitor_id,
                'unique_id' => $this->unique_id,
                'state' => $this->state,
                'duration' => $this->duration,
                'exit_code' => $this->exit_code,
                'host' => $this->host,
                'timestamp' => $this->timestamp,
                'received_at' => $this->received_at,
                'ip' => $this->ip,
                'error' => $this->error,
            ]);

            if ($result) {
                $this->id = (int)$db->lastInsertId();

                // Obsłuż tagi
                if (!empty($this->tags)) {
                    $this->saveTags();
                }

                // Sprawdź czy trzeba wysłać powiadomienia
                $this->checkForStateChanges();

                return true;
            }
            return false;
        }

        // Aktualizacja nie jest obsługiwana
        return false;
    }

    private function checkForStateChanges(): void
    {
        try {
            // Pobierz poprzedni ping dla tego monitora
            $previousPings = self::findRecentByMonitor($this->monitor_id, 2);

            // Usuń obecny ping z listy (jeśli jest)
            $previousPings = array_filter($previousPings, function($ping) {
                return $ping->id !== $this->id;
            });

            $previousPing = reset($previousPings); // Pobierz pierwszy element (lub false jeśli pusta tablica)

            // Jeśli jest to pierwszy ping dla monitora, nie ma potrzeby sprawdzania zmiany stanu
            if (!$previousPing) {
                return;
            }

            $notificationService = new \App\Services\NotificationService();

            // Sprawdź, czy wystąpiło zdarzenie niepowodzenia
            if ($this->state === 'fail' && $previousPing->state !== 'fail') {
                $notificationService->handleMonitorFail($this->monitor_id, $this->error ?? '');
            }

            // Sprawdź, czy wystąpiło zdarzenie naprawy
            if ($this->state === 'complete' && $previousPing->state === 'fail') {
                $notificationService->handleMonitorResolve($this->monitor_id);
            }
        } catch (\Exception $e) {
            // Log błędu, ale nie zakłócaj głównego procesu
            error_log("Error checking for state changes: " . $e->getMessage());
        }
    }
    
    private function saveTags(): void
    {
        if ($this->id === null) {
            return;
        }
        
        $db = Connection::getInstance();
        
        foreach ($this->tags as $tagName) {
            $tag = Tag::findByName($tagName);
            
            if ($tag === null) {
                $tag = new Tag($tagName);
                $tag->save();
            }
            
            $stmt = $db->prepare('
                INSERT IGNORE INTO ping_tags (ping_id, tag_id)
                VALUES (:ping_id, :tag_id)
            ');
            
            $stmt->execute([
                'ping_id' => $this->id,
                'tag_id' => $tag->id
            ]);
        }
    }
    
    public function getTags(): array
    {
        if ($this->id === null) {
            return [];
        }
        
        $db = Connection::getInstance();
        
        $stmt = $db->prepare('
            SELECT t.*
            FROM tags t
            JOIN ping_tags pt ON t.id = pt.tag_id
            WHERE pt.ping_id = :ping_id
            ORDER BY t.name
        ');
        
        $stmt->execute(['ping_id' => $this->id]);
        
        $tags = [];
        
        while ($data = $stmt->fetch()) {
            $tag = new Tag($data['name']);
            $tag->id = (int)$data['id'];
            $tags[] = $tag;
        }
        
        return $tags;
    }
    
    public static function findByMonitorAndUniqueId(int $monitorId, string $uniqueId): array
    {
        $db = Connection::getInstance();
        
        $stmt = $db->prepare('
            SELECT *
            FROM pings
            WHERE monitor_id = :monitor_id
              AND unique_id = :unique_id
            ORDER BY timestamp DESC
        ');
        
        $stmt->execute([
            'monitor_id' => $monitorId,
            'unique_id' => $uniqueId
        ]);
        
        $pings = [];
        
        while ($data = $stmt->fetch()) {
            $ping = new self();
            $ping->id = (int)$data['id'];
            $ping->monitor_id = (int)$data['monitor_id'];
            $ping->unique_id = $data['unique_id'];
            $ping->state = $data['state'];
            $ping->duration = $data['duration'] !== null ? (float)$data['duration'] : null;
            $ping->exit_code = $data['exit_code'] !== null ? (int)$data['exit_code'] : null;
            $ping->host = $data['host'];
            $ping->timestamp = (int)$data['timestamp'];
            $ping->received_at = (int)$data['received_at'];
            $ping->ip = $data['ip'];
            $ping->error = $data['error'];
            
            $pings[] = $ping;
        }
        
        return $pings;
    }
    
    public static function findRecentByMonitor(int $monitorId, int $limit = 10, string $state = null): array
    {
        $db = Connection::getInstance();
        
        $sql = '
            SELECT *
            FROM pings
            WHERE monitor_id = :monitor_id
        ';
        
        if ($state !== null) {
            $sql .= ' AND state = :state';
        }
        
        $sql .= '
            ORDER BY timestamp DESC
            LIMIT :limit
        ';
        
        $stmt = $db->prepare($sql);
        
        $stmt->bindValue(':monitor_id', $monitorId, PDO::PARAM_INT);
        
        if ($state !== null) {
            $stmt->bindValue(':state', $state, PDO::PARAM_STR);
        }
        
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $pings = [];
        
        while ($data = $stmt->fetch()) {
            $ping = new self();
            $ping->id = (int)$data['id'];
            $ping->monitor_id = (int)$data['monitor_id'];
            $ping->unique_id = $data['unique_id'];
            $ping->state = $data['state'];
            $ping->duration = $data['duration'] !== null ? (float)$data['duration'] : null;
            $ping->exit_code = $data['exit_code'] !== null ? (int)$data['exit_code'] : null;
            $ping->host = $data['host'];
            $ping->timestamp = (int)$data['timestamp'];
            $ping->received_at = (int)$data['received_at'];
            $ping->ip = $data['ip'];
            $ping->error = $data['error'];
            
            $pings[] = $ping;
        }
        
        return $pings;
    }
}