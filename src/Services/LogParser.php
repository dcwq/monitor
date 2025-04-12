<?php

namespace App\Services;

use App\Models\Monitor;
use App\Models\Ping;
use App\Models\Tag;

class LogParser
{
    private string $historyLogPath;
    private ?string $lastProcessedLine = null;

    public function __construct(string $historyLogPath = null)
    {
        $this->historyLogPath = $historyLogPath ?? $_ENV['HISTORY_LOG'];
    }

    public function parse(bool $incrementally = true): int
    {
        if (!file_exists($this->historyLogPath)) {
            throw new \RuntimeException("History log file not found: {$this->historyLogPath}");
        }

        $importCount = 0;
        $fileHandle = fopen($this->historyLogPath, 'r');

        if ($fileHandle === false) {
            throw new \RuntimeException("Could not open log file: {$this->historyLogPath}");
        }

        if ($incrementally) {
            $this->lastProcessedLine = $this->getLastProcessedTimestamp();
        }

        while (($line = fgets($fileHandle)) !== false) {
            $matches = [];
            if (preg_match('/^\[(.*?)\] (.*)$/', $line, $matches)) {
                $timestamp = $matches[1];
                $jsonData = $matches[2];

                if ($incrementally && $this->lastProcessedLine !== null && $timestamp <= $this->lastProcessedLine) {
                    continue;
                }

                $pingData = json_decode($jsonData, true);

                if ($pingData && isset($pingData['monitor'])) {
                    $this->processPing($pingData);
                    $importCount++;
                    $this->lastProcessedLine = $timestamp;
                }
            }
        }

        fclose($fileHandle);

        if ($incrementally && $this->lastProcessedLine !== null) {
            $this->saveLastProcessedTimestamp($this->lastProcessedLine);
        }

        return $importCount;
    }

    private function processPing(array $pingData): void
    {
        $monitorName = $pingData['monitor'];

        $monitor = Monitor::findByName($monitorName);

        if ($monitor === null) {
            $monitor = new Monitor($monitorName);
            $monitor->save();
        }

        $ping = new Ping();
        $ping->monitor_id = $monitor->id;
        $ping->unique_id = $pingData['unique_id'] ?? substr(md5(rand()), 0, 8);
        $ping->state = $pingData['state'] ?? 'run';
        $ping->duration = $pingData['duration'] ?? null;
        $ping->exit_code = $pingData['exit_code'] ?? null;
        $ping->host = $pingData['host'] ?? null;
        $ping->timestamp = $pingData['timestamp'] ?? time();
        $ping->received_at = $pingData['received_at'] ?? time();
        $ping->ip = $pingData['ip'] ?? null;
        $ping->error = $pingData['error'] ?? null;

        if (isset($pingData['tags']) && is_array($pingData['tags'])) {
            $ping->tags = $pingData['tags'];

            foreach ($pingData['tags'] as $tagName) {
                $tag = Tag::findByName($tagName);

                if ($tag === null) {
                    $tag = new Tag($tagName);
                    $tag->save();
                }

                $tag->assignToMonitor($monitor->id);
            }
        }

        $ping->save();
    }

    private function getLastProcessedTimestamp(): ?string
    {
        $file = sys_get_temp_dir() . '/cronitor_clone_last_processed.txt';

        if (file_exists($file)) {
            return trim(file_get_contents($file));
        }

        return null;
    }

    private function saveLastProcessedTimestamp(string $timestamp): void
    {
        $file = sys_get_temp_dir() . '/cronitor_clone_last_processed.txt';
        file_put_contents($file, $timestamp);
    }
}