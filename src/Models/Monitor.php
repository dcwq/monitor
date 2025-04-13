<?php

namespace App\Models;

use App\Connection;
use PDO;

class Monitor
{
    public ?int $id = null;
    public string $name;
    public ?string $project_name = null;

    public function __construct(string $name, ?string $project_name = null) {
        $this->name = $name;
        $this->project_name = $project_name;
    }

    public function save(): bool {
        $db = Connection::getInstance();

        if ($this->id === null) {
            $stmt = $db->prepare('
                INSERT INTO monitors (name, project_name) 
                VALUES (:name, :project_name) 
                ON DUPLICATE KEY UPDATE name = :name, project_name = :project_name
            ');

            $stmt->execute([
                'name' => $this->name,
                'project_name' => $this->project_name
            ]);

            $this->id = (int)$db->lastInsertId();
            return $stmt->rowCount() > 0;
        } else {
            $stmt = $db->prepare('
                UPDATE monitors 
                SET name = :name, project_name = :project_name 
                WHERE id = :id
            ');

            $stmt->execute([
                'id' => $this->id,
                'name' => $this->name,
                'project_name' => $this->project_name
            ]);

            return $stmt->rowCount() > 0;
        }
    }

    // Zaktualizuj metody wyszukiwania, aby uwzględniały nowe pole
    public static function findByName(string $name): ?self {
        $db = Connection::getInstance();
        $stmt = $db->prepare('
            SELECT id, name, project_name 
            FROM monitors 
            WHERE name = :name
        ');
        $stmt->execute(['name' => $name]);
        $data = $stmt->fetch();

        if (!$data) {
            return null;
        }

        $monitor = new self($data['name'], $data['project_name']);
        $monitor->id = (int)$data['id'];
        return $monitor;
    }

    public static function findById(int $id): ?self {
        $db = Connection::getInstance();
        $stmt = $db->prepare('
            SELECT id, name, project_name 
            FROM monitors 
            WHERE id = :id
        ');
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch();

        if (!$data) {
            return null;
        }

        $monitor = new self($data['name'], $data['project_name']);
        $monitor->id = (int)$data['id'];
        return $monitor;
    }

    public static function findAll(): array {
        $db = Connection::getInstance();
        $stmt = $db->query('
            SELECT id, name, project_name 
            FROM monitors 
            ORDER BY project_name, name
        ');

        $monitors = [];
        while ($data = $stmt->fetch()) {
            $monitor = new self($data['name'], $data['project_name']);
            $monitor->id = (int)$data['id'];
            $monitors[] = $monitor;
        }

        return $monitors;
    }

    // Metoda do wyszukiwania monitorów według nazwy projektu
    public static function findByProjectName(string $projectName): array {
        $db = Connection::getInstance();
        $stmt = $db->prepare('
            SELECT id, name, project_name 
            FROM monitors 
            WHERE project_name = :project_name
            ORDER BY name
        ');
        $stmt->execute(['project_name' => $projectName]);

        $monitors = [];
        while ($data = $stmt->fetch()) {
            $monitor = new self($data['name'], $data['project_name']);
            $monitor->id = (int)$data['id'];
            $monitors[] = $monitor;
        }

        return $monitors;
    }

    // Metoda do pobierania wszystkich unikalnych nazw projektów
    public static function getAllProjectNames(): array {
        $db = Connection::getInstance();
        $stmt = $db->query('
            SELECT DISTINCT project_name 
            FROM monitors 
            WHERE project_name IS NOT NULL 
            ORDER BY project_name
        ');

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    public function getRecentPings(int $limit = 10): array
    {
        $db = Connection::getInstance();
        
        $stmt = $db->prepare('
            SELECT p.*
            FROM pings p
            WHERE p.monitor_id = :monitor_id
            ORDER BY p.timestamp DESC
            LIMIT :limit
        ');
        
        $stmt->bindValue(':monitor_id', $this->id, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $pings = [];
        
        while ($data = $stmt->fetch()) {
            $ping = new Ping();
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
    
    public function getTags(): array
    {
        $db = Connection::getInstance();
        
        $stmt = $db->prepare('
            SELECT t.*
            FROM tags t
            JOIN monitor_tags mt ON t.id = mt.tag_id
            WHERE mt.monitor_id = :monitor_id
            ORDER BY t.name
        ');
        
        $stmt->execute(['monitor_id' => $this->id]);
        
        $tags = [];
        
        while ($data = $stmt->fetch()) {
            $tag = new Tag($data['name']);
            $tag->id = (int)$data['id'];
            $tags[] = $tag;
        }
        
        return $tags;
    }
    
    public function getLastPing(): ?Ping
    {
        $pings = $this->getRecentPings(1);
        return $pings[0] ?? null;
    }
    
    public function getStats(int $days = 7): array
    {
        $db = Connection::getInstance();
        
        $startTime = time() - ($days * 86400);
        
        $stmt = $db->prepare('
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN state = "complete" THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN state = "fail" THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN state = "run" THEN 1 ELSE 0 END) as running,
                AVG(CASE WHEN state = "complete" AND duration IS NOT NULL THEN duration ELSE NULL END) as avg_duration,
                MAX(CASE WHEN state = "complete" AND duration IS NOT NULL THEN duration ELSE NULL END) as max_duration,
                MIN(CASE WHEN state = "complete" AND duration IS NOT NULL THEN duration ELSE NULL END) as min_duration
            FROM pings
            WHERE monitor_id = :monitor_id
              AND timestamp >= :start_time
              AND state IN ("complete", "fail", "run")
        ');
        
        $stmt->execute([
            'monitor_id' => $this->id,
            'start_time' => $startTime
        ]);
        
        $stats = $stmt->fetch();
        
        $timeStmt = $db->prepare('
            SELECT 
                DATE_FORMAT(FROM_UNIXTIME(timestamp), "%Y-%m-%d %H:00:00") as hour,
                AVG(duration) as avg_duration,
                COUNT(*) as count
            FROM pings
            WHERE monitor_id = :monitor_id
              AND timestamp >= :start_time
              AND state = "complete"
            GROUP BY hour
            ORDER BY hour
        ');
        
        $timeStmt->execute([
            'monitor_id' => $this->id,
            'start_time' => $startTime
        ]);
        
        $timeData = $timeStmt->fetchAll();
        
        $stats['time_data'] = $timeData;

        try {
            $stats['success_rate'] = $stats['total'] > 0
                ? ($stats['completed'] / ($stats['completed'] + $stats['failed'])) * 100
                : 0;
        } catch (\Throwable $e) {
            $stats['success_rate'] = 0;
        }
        
        return $stats;
    }
}