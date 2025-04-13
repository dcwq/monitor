<?php
// src/Models/MonitorConfig.php

namespace App\Models;

use App\Connection;
use PDO;

class MonitorConfig
{
    public ?int $id = null;
    public int $monitor_id;
    public int $expected_interval;
    public int $alert_threshold;

    public function __construct(int $monitor_id, int $expected_interval = 3600, int $alert_threshold = 0)
    {
        $this->monitor_id = $monitor_id;
        $this->expected_interval = $expected_interval;
        $this->alert_threshold = $alert_threshold;
    }

    public function save(): bool
    {
        $db = Connection::getInstance();

        if ($this->id === null) {
            // Sprawdź czy już istnieje konfiguracja dla tego monitora
            $checkStmt = $db->prepare('SELECT id FROM monitor_configs WHERE monitor_id = :monitor_id');
            $checkStmt->execute(['monitor_id' => $this->monitor_id]);
            $existing = $checkStmt->fetch();

            if ($existing) {
                $this->id = $existing['id'];
                $stmt = $db->prepare('
                    UPDATE monitor_configs 
                    SET expected_interval = :expected_interval, 
                        alert_threshold = :alert_threshold 
                    WHERE id = :id
                ');
                return $stmt->execute([
                    'id' => $this->id,
                    'expected_interval' => $this->expected_interval,
                    'alert_threshold' => $this->alert_threshold
                ]);
            } else {
                $stmt = $db->prepare('
                    INSERT INTO monitor_configs 
                    (monitor_id, expected_interval, alert_threshold) 
                    VALUES (:monitor_id, :expected_interval, :alert_threshold)
                ');
                $result = $stmt->execute([
                    'monitor_id' => $this->monitor_id,
                    'expected_interval' => $this->expected_interval,
                    'alert_threshold' => $this->alert_threshold
                ]);

                if ($result) {
                    $this->id = (int)$db->lastInsertId();
                }
                return $result;
            }
        } else {
            $stmt = $db->prepare('
                UPDATE monitor_configs 
                SET expected_interval = :expected_interval, 
                    alert_threshold = :alert_threshold 
                WHERE id = :id
            ');
            return $stmt->execute([
                'id' => $this->id,
                'expected_interval' => $this->expected_interval,
                'alert_threshold' => $this->alert_threshold
            ]);
        }
    }

    public static function findByMonitorId(int $monitorId): ?self
    {
        $db = Connection::getInstance();
        $stmt = $db->prepare('
            SELECT id, monitor_id, expected_interval, alert_threshold 
            FROM monitor_configs 
            WHERE monitor_id = :monitor_id
        ');
        $stmt->execute(['monitor_id' => $monitorId]);
        $data = $stmt->fetch();

        if (!$data) {
            return null;
        }

        $config = new self(
            (int)$data['monitor_id'],
            (int)$data['expected_interval'],
            (int)$data['alert_threshold']
        );
        $config->id = (int)$data['id'];
        return $config;
    }

    public static function getOrCreate(int $monitorId): self
    {
        $config = self::findByMonitorId($monitorId);
        if ($config === null) {
            $config = new self($monitorId);
            $config->save();
        }
        return $config;
    }
}