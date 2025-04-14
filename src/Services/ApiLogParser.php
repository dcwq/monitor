<?php

namespace App\Services;

use App\Entity\Monitor;
use App\Entity\Ping;
use App\Entity\Tag;
use App\Enum\PingState;
use App\Repository\MonitorRepositoryInterface;
use App\Repository\PingRepositoryInterface;
use App\Repository\TagRepositoryInterface;

class ApiLogParser
{
    private string $apiLogPath;
    private ?string $lastProcessedLine = null;
    private MonitorRepositoryInterface $monitorRepository;
    private PingRepositoryInterface $pingRepository;
    private TagRepositoryInterface $tagRepository;

    public function __construct(
        MonitorRepositoryInterface $monitorRepository,
        PingRepositoryInterface $pingRepository,
        TagRepositoryInterface $tagRepository,
        string $apiLogPath = null
    ) {
        $this->monitorRepository = $monitorRepository;
        $this->pingRepository = $pingRepository;
        $this->tagRepository = $tagRepository;
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
            // Zaktualizuj wyrażenie regularne, aby dopasować nowe informacje w logu
            // Obsługuje teraz: [Źródło: xxx] [Strefa: xxx] [Cron: xxx]
            if (preg_match('/^\[(.*?)\] \[INFO\] Otrzymano ping \'(.*?)\' dla monitora \'(.*?)\'.*?\(czas: ([\d\.]+)s\)(?:\s+\[Tagi: (.*?)\])?(?:\s+\[Źródło: (.*?)\])?(?:\s+\[Strefa: (.*?)\])?(?:\s+\[Cron: (.*?)\])?$/', $line, $matches)) {
                $timestamp = $matches[1];
                $state = $matches[2];
                $monitorName = $matches[3];
                $duration = floatval($matches[4]);
                $tagsStr = isset($matches[5]) ? $matches[5] : '';
                $runSource = isset($matches[6]) ? $matches[6] : null;
                $timezone = isset($matches[7]) ? $matches[7] : null;
                $cronSchedule = isset($matches[8]) ? $matches[8] : null;

                if ($incrementally && $this->lastProcessedLine !== null && $timestamp <= $this->lastProcessedLine) {
                    continue;
                }

                $tagNames = [];
                if (!empty($tagsStr)) {
                    $tagNames = array_map('trim', explode(',', $tagsStr));
                }

                try {
                    $this->processPing($timestamp, $state, $monitorName, $duration, $tagNames, $runSource, $timezone, $cronSchedule);
                    $importCount++;
                    $this->lastProcessedLine = $timestamp;
                } catch (\Exception $e) {
                    // Log error but continue processing
                    error_log("Error processing line: $line. Error: " . $e->getMessage());
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

    private function processPing(
        string $timestamp,
        string $state,
        string $monitorName,
        float $duration,
        array $tagNames = [],
        ?string $runSource = null,
        ?string $timezone = null,
        ?string $cronSchedule = null
    ): void {
        // Find or create monitor
        $monitor = $this->monitorRepository->findByName($monitorName);
        if ($monitor === null) {
            $monitor = new Monitor($monitorName);
            $this->monitorRepository->save($monitor);
        }

        // Create ping
        $ping = new Ping();
        $ping->setMonitor($monitor);
        $ping->setUniqueId(md5($timestamp . $monitorName . $state . rand(1000, 9999)));
        $ping->setState($state);

        // Convert duration to milliseconds for 'complete' state
        if ($state === PingState::COMPLETE->value) {
            $ping->setDuration($duration * 1000);
        }

        $ping->setExitCode($state === PingState::FAIL->value ? 1 : 0);
        $ping->setHost('api-log');
        $ping->setTimestamp(strtotime($timestamp));
        $ping->setReceivedAt(strtotime($timestamp));
        $ping->setIp('127.0.0.1');

        // Ustawienie nowych pól
        $ping->setRunSource($runSource);
        $ping->setTimezone($timezone);
        $ping->setCronSchedule($cronSchedule);

        // Process tags
        foreach ($tagNames as $tagName) {
            if (empty(trim($tagName))) {
                continue;
            }

            $tag = $this->tagRepository->findByName(trim($tagName));
            if ($tag === null) {
                $tag = new Tag(trim($tagName));
                $this->tagRepository->save($tag);
            }

            $ping->addTag($tag);
            $monitor->addTag($tag);
        }

        // Save ping
        $this->pingRepository->save($ping);
    }

    private function getLastProcessedTimestamp(): ?string
    {
        $file = LogParser::LAST_PROCESSED_FILE;

        if (file_exists($file)) {
            return trim(file_get_contents($file));
        }

        return null;
    }

    private function saveLastProcessedTimestamp(string $timestamp): void
    {
        $file = LogParser::LAST_PROCESSED_FILE;
        file_put_contents($file, $timestamp);
    }
}