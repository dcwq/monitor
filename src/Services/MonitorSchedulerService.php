<?php

namespace App\Services;

use App\Entity\Monitor;
use App\Repository\MonitorConfigRepositoryInterface;
use App\Repository\PingRepositoryInterface;

/**
 * Serwis do zarządzania i pobierania informacji o harmonogramie monitorów
 */
class MonitorSchedulerService
{
    private MonitorConfigRepositoryInterface $monitorConfigRepository;
    private PingRepositoryInterface $pingRepository;

    public function __construct(
        MonitorConfigRepositoryInterface $monitorConfigRepository,
        PingRepositoryInterface $pingRepository
    ) {
        $this->monitorConfigRepository = $monitorConfigRepository;
        $this->pingRepository = $pingRepository;
    }

    /**
     * Pobiera czytelny opis harmonogramu dla monitora
     */
    public function getReadableSchedule(Monitor $monitor): ?string
    {
        // Pobierz konfigurację monitora
        $config = $this->monitorConfigRepository->findByMonitor($monitor);
        if (!$config) {
            return null;
        }

        // Sprawdź, czy monitor ma zdefiniowane wyrażenie cron
        $cronExpression = $config->getCronExpression();

        // Jeśli nie ma expresji cron w konfiguracji, sprawdź czy ostatni ping ma informacje o harmonogramie cron
        $lastPing = $monitor->getLastPing();
        if (!$cronExpression && $lastPing && $lastPing->getCronSchedule()) {
            $cronExpression = $lastPing->getCronSchedule();
        }

        if ($cronExpression) {
            // Zwróć czytelny opis wyrażenia cron
            $interval = CronIntervalCalculator::calculateExpectedInterval($cronExpression);
            return CronIntervalCalculator::getReadableInterval($interval);
        }

        // Jeśli nie mamy wyrażenia cron, użyj czytelnego opisu interwału
        $interval = $config->getExpectedInterval();
        return CronIntervalCalculator::getReadableInterval($interval);
    }

    /**
     * Pobiera wyrażenie CRON dla monitora
     */
    public function getCronExpression(Monitor $monitor): ?string
    {
        // Pobierz konfigurację monitora
        $config = $this->monitorConfigRepository->findByMonitor($monitor);
        if (!$config) {
            return null;
        }

        // Sprawdź, czy monitor ma zdefiniowane wyrażenie cron
        $cronExpression = $config->getCronExpression();

        // Jeśli nie ma expresji cron w konfiguracji, sprawdź czy ostatni ping ma informacje o harmonogramie cron
        $lastPing = $monitor->getLastPing();
        if (!$cronExpression && $lastPing && $lastPing->getCronSchedule()) {
            return $lastPing->getCronSchedule();
        }

        return $cronExpression;
    }

    /**
     * Oblicza oczekiwany interwał dla monitora (w sekundach)
     */
    public function getExpectedInterval(Monitor $monitor): int
    {
        // Pobierz konfigurację monitora
        $config = $this->monitorConfigRepository->findByMonitor($monitor);
        if (!$config) {
            return 3600; // Domyślnie 1 godzina
        }

        // Sprawdź, czy monitor ma zdefiniowane wyrażenie cron
        $cronExpression = $this->getCronExpression($monitor);

        // Jeśli mamy cronExpression i nie mamy niestandardowego interwału, oblicz interwał
        if ($cronExpression && $config->getExpectedInterval() === 3600) { // Domyślna wartość
            return CronIntervalCalculator::calculateExpectedInterval($cronExpression);
        }

        // W przeciwnym razie użyj zapisanego interwału
        return $config->getExpectedInterval();
    }

    /**
     * Oblicza i zwraca czytelny tekst opisujący następne oczekiwane uruchomienie
     */
    public function getExpectedNextRun(Monitor $monitor): ?string
    {
        $lastPing = $monitor->getLastPing();
        if (!$lastPing) {
            return null;
        }

        // Oblicz oczekiwany interwał
        $expectedInterval = $this->getExpectedInterval($monitor);

        // Oblicz oczekiwany czas następnego uruchomienia
        $expectedNext = $lastPing->getTimestamp() + $expectedInterval;

        // Obecny czas
        $now = time();

        if ($expectedNext < $now) {
            return 'Overdue';
        }

        $minutesRemaining = ceil(($expectedNext - $now) / 60);

        if ($minutesRemaining < 60) {
            return "In about {$minutesRemaining} minute" . ($minutesRemaining === 1 ? '' : 's');
        }

        $hoursRemaining = ceil($minutesRemaining / 60);
        return "In about {$hoursRemaining} hour" . ($hoursRemaining === 1 ? '' : 's');
    }
}