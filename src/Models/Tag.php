<?php

namespace App\Models;

use App\Connection;

class Tag
{
    public ?int $id = null;
    public string $name;
    
    public function __construct(string $name = '')
    {
        $this->name = $name;
    }
    
    public function save(): bool
    {
        $db = Connection::getInstance();
        
        if ($this->id === null) {
            $stmt = $db->prepare('
                INSERT INTO tags (name)
                VALUES (:name)
                ON DUPLICATE KEY UPDATE
                    name = :name
            ');
            
            $stmt->execute([
                'name' => $this->name
            ]);
            
            $this->id = (int)$db->lastInsertId();
            
            return $stmt->rowCount() > 0;
        }
        
        return false;
    }
    
    public static function findByName(string $name): ?self
    {
        $db = Connection::getInstance();
        
        $stmt = $db->prepare('
            SELECT id, name
            FROM tags
            WHERE name = :name
        ');
        
        $stmt->execute(['name' => $name]);
        
        $data = $stmt->fetch();
        
        if (!$data) {
            return null;
        }
        
        $tag = new self($data['name']);
        $tag->id = (int)$data['id'];
        
        return $tag;
    }
    
    public static function findAll(): array
    {
        $db = Connection::getInstance();
        
        $stmt = $db->query('
            SELECT id, name
            FROM tags
            ORDER BY name
        ');
        
        $tags = [];
        
        while ($data = $stmt->fetch()) {
            $tag = new self($data['name']);
            $tag->id = (int)$data['id'];
            $tags[] = $tag;
        }
        
        return $tags;
    }
    
    public function assignToMonitor(int $monitorId): bool
    {
        if ($this->id === null) {
            return false;
        }
        
        $db = Connection::getInstance();
        
        $stmt = $db->prepare('
            INSERT IGNORE INTO monitor_tags (monitor_id, tag_id)
            VALUES (:monitor_id, :tag_id)
        ');
        
        return $stmt->execute([
            'monitor_id' => $monitorId,
            'tag_id' => $this->id
        ]);
    }
    
    public function getMonitors(): array
    {
        if ($this->id === null) {
            return [];
        }
        
        $db = Connection::getInstance();
        
        $stmt = $db->prepare('
            SELECT m.*
            FROM monitors m
            JOIN monitor_tags mt ON m.id = mt.monitor_id
            WHERE mt.tag_id = :tag_id
            ORDER BY m.name
        ');
        
        $stmt->execute(['tag_id' => $this->id]);
        
        $monitors = [];
        
        while ($data = $stmt->fetch()) {
            $monitor = new Monitor($data['name']);
            $monitor->id = (int)$data['id'];
            $monitors[] = $monitor;
        }
        
        return $monitors;
    }
}