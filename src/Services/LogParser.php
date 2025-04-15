<?php

namespace App\Services;

use App\Entity\Monitor;
use App\Entity\Ping;
use App\Entity\Tag;
use App\Enum\PingState;
use App\Repository\MonitorRepositoryInterface;
use App\Repository\PingRepositoryInterface;
use App\Repository\TagRepositoryInterface;

class LogParser
{
    public const LAST_PROCESSED_FILE = './data/last_processed.txt';

    private string $historyLogPath;
    private ?string $lastProcessedLine = null;
    private MonitorRepositoryInterface $monitorRepository;
    private PingRepositoryInterface $pingRepository;
    private TagRepositoryInterface $tagRepository;

    public function __construct(
        MonitorRepositoryInterface $monitorRepository,
        PingRepositoryInterface $pingRepository,
        TagRepositoryInterface $tagRepository,
        string $historyLogPath = null
    ) {
        $this->monitorRepository = $monitorRepository;
        $this->pingRepository = $pingRepository;
        $this->tagRepository = $tagRepository;
        $this->historyLogPath = $historyLogPath ?? $_ENV['HISTORY_LOG'];

        if (!file_exists(self::LAST_PROCESSED_FILE)) {
            touch(self::LAST_PROCESSED_FILE);
            chmod(self::LAST_PROCESSED_FILE, 0777);
        }
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

    private function processPing(array $pingData): void {
        $monitorName = $pingData['monitor'];
        $projectName = $pingData['project'] ?? null;

        // Find or create monitor
        $monitor = $this->monitorRepository->findByName($monitorName);
        if ($monitor === null) {
            $monitor = new Monitor($monitorName, $projectName);
            $this->monitorRepository->save($monitor);
        } else if ($projectName && $monitor->getProjectName() !== $projectName) {
            // Update project name if it has changed
            $monitor->setProjectName($projectName);
            $this->monitorRepository->save($monitor);
        }


        // Create ping entity
        $ping = new Ping();
        $ping->setMonitor($monitor);
        $ping->setUniqueId($pingData['unique_id'] ?? substr(md5(rand()), 0, 8));
        $ping->setState($pingData['state'] ?? PingState::RUN->value);
        $ping->setDuration($pingData['duration'] ?? null);
        $ping->setExitCode($pingData['exit_code'] ?? null);
        $ping->setHost($pingData['host'] ?? null);
        $ping->setTimestamp($pingData['timestamp'] ?? time());
        $ping->setReceivedAt($pingData['received_at'] ?? time());
        $ping->setIp($pingData['ip'] ?? null);
        $ping->setError($pingData['error'] ?? null);
        $ping->setErrorOutput($pingData['error_output'] ?? null);

        // Dodaj nowe pola
        $ping->setTimezone($pingData['timezone'] ?? null);
        $ping->setRunSource($pingData['run_source'] ?? null);
        $ping->setCronSchedule($pingData['cron_schedule'] ?? null);

        // Process tags
        if (isset($pingData['tags']) && is_array($pingData['tags'])) {
            foreach ($pingData['tags'] as $tagName) {
                if (empty($tagName)) {
                    continue;
                }

                $tag = $this->tagRepository->findByName($tagName);

                if ($tag === null) {
                    $tag = new Tag($tagName);
                    $this->tagRepository->save($tag);
                }

                $ping->addTag($tag);
                $monitor->addTag($tag);
            }
        }

        // Save ping
        $this->pingRepository->save($ping);
    }

    private function getLastProcessedTimestamp(): ?string
    {
        $file = self::LAST_PROCESSED_FILE;

        if (file_exists($file)) {
            return trim(file_get_contents($file));
        }

        return null;
    }

    private function saveLastProcessedTimestamp(string $timestamp): void
    {
        $file = self::LAST_PROCESSED_FILE;
        file_put_contents($file, $timestamp);
    }
}