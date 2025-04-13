<?php
// src/Models/MonitorOverdueHistory.php

namespace App\Models;

use App\Connection;
use PDO;
use DateTime;

class MonitorOverdueHistory
{
    public ?int $id = null;
    public int $monitor_id;
    public string $started_at;
    public ?string $resolved_at = null;
    public ?int $duration = null;
    public bool $is_resolved = false;

    public function __construct(int $monitor_id, string $started_at)
    {
        $this->monitor_id = $monitor_id;
        $this->started_at = $started_at;
    }

    public function save(): bool
    {
        $db = Connection::getInstance();

        if ($this->id === null) {
            $stmt = $db->prepare('
                INSERT INTO monitor_overdue_history 
                (monitor_id, started_at, resolved_at, duration, is_resolved) 
                VALUES (:monitor_id, :started_at, :resolved_at, :duration, :is_resolved)
            ');
            $result = $stmt->execute([
                'monitor_id' => $this->monitor_id,
                'started_at' => $this->started_at,
                'resolved_at' => $this->resolved_at,
                'duration' => $this->duration,
                'is_resolved' => $this->is_resolved ? 1 : 0
            ]);

            if ($result) {
                $this->id = (int)$db->lastInsertId();
            }
            return $result;
        } else {
            $stmt = $db->prepare('
                UPDATE monitor_overdue_history 
                SET resolved_at = :resolved_at, 
                    duration = :duration, 
                    is_resolved = :is_resolved 
                WHERE id = :id
            ');
            return $stmt->execute([
                'id' => $this->id,
                'resolved_at' => $this->resolved_at,
                'duration' => $this->duration,
                'is_resolved' => $this->is_resolved ? 1 : 0
            ]);
        }
    }

    public function resolve(): bool
    {
        if ($this->is_resolved) {
            return false;
        }

        $now = new DateTime();
        $this->resolved_at = $now->format('Y-m-d H:i:s');
        $this->is_resolved = true;

        // Calculate duration in seconds
        $started = new DateTime($this->started_at);
        $this->duration = $now->getTimestamp() - $started->getTimestamp();

        return $this->save();
    }

    public static function findUnresolvedByMonitorId(int $monitorId): ?self
    {
        $db = Connection::getInstance();
        $stmt = $db->prepare('
            SELECT id, monitor_id, started_at, resolved_at, duration, is_resolved 
            FROM monitor_overdue_history 
            WHERE monitor_id = :monitor_id AND is_resolved = 0
            ORDER BY started_at DESC
            LIMIT 1
        ');
        $stmt->execute(['monitor_id' => $monitorId]);
        $data = $stmt->fetch();

        if (!$data) {
            return null;
        }

        $history = new self((int)$data['monitor_id'], $data['started_at']);
        $history->id = (int)$data['id'];
        $history->resolved_at = $data['resolved_at'];
        $history->duration = $data['duration'] !== null ? (int)$data['duration'] : null;
        $history->is_resolved = (bool)$data['is_resolved'];
        return $history;
    }

    public static function findByMonitorId(int $monitorId, int $limit = 10): array
    {
        $db = Connection::getInstance();
        $stmt = $db->prepare('
            SELECT id, monitor_id, started_at, resolved_at, duration, is_resolved 
            FROM monitor_overdue_history 
            WHERE monitor_id = :monitor_id 
            ORDER BY started_at DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':monitor_id', $monitorId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $history = [];
        while ($data = $stmt->fetch()) {
            $item = new self((int)$data['monitor_id'], $data['started_at']);
            $item->id = (int)$data['id'];
            $item->resolved_at = $data['resolved_at'];
            $item->duration = $data['duration'] !== null ? (int)$data['duration'] : null;
            $item->is_resolved = (bool)$data['is_resolved'];
            $history[] = $item;
        }

        return $history;
    }
}
