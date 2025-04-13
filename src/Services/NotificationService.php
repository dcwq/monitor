<?php
// src/Services/NotificationService.php

namespace App\Services;

use App\Connection;
use App\Models\Monitor;
use App\Models\MonitorConfig;
use App\Models\MonitorOverdueHistory;
use App\Notifications\NotificationAdapterFactory;
use PDO;
use DateTime;

class NotificationService
{
    /**
     * Send notification for a monitor
     *
     * @param int $monitorId Monitor ID
     * @param string $eventType Event type: 'fail', 'overdue', 'resolve'
     * @param string $message Message to send
     * @return array Array of notification results with channel IDs
     */
    public function sendNotifications(int $monitorId, string $eventType, string $message): array
    {
        $db = Connection::getInstance();

        // Pobierz konfiguracje kanałów dla tego monitora
        $stmt = $db->prepare('
            SELECT nc.id, nc.name, nc.type, nc.config, mn.notify_on_fail, mn.notify_on_overdue, mn.notify_on_resolve 
            FROM notification_channels nc
            JOIN monitor_notifications mn ON nc.id = mn.channel_id
            WHERE mn.monitor_id = :monitor_id
        ');
        $stmt->execute(['monitor_id' => $monitorId]);

        $results = [];

        while ($channel = $stmt->fetch()) {
            // Sprawdź czy ten kanał ma być powiadamiany o tym typie zdarzenia
            if (
                ($eventType === 'fail' && !$channel['notify_on_fail']) ||
                ($eventType === 'overdue' && !$channel['notify_on_overdue']) ||
                ($eventType === 'resolve' && !$channel['notify_on_resolve'])
            ) {
                continue;
            }

            try {
                $config = json_decode($channel['config'], true);
                if (!is_array($config)) {
                    error_log("Invalid channel configuration for {$channel['name']}");
                    $results[$channel['id']] = false;
                    continue;
                }

                $adapter = NotificationAdapterFactory::create($channel['type']);
                $success = $adapter->send($message, $config);

                // Zapisz historię powiadomienia
                if ($success) {
                    $this->logNotification($monitorId, $channel['id'], $eventType, $message);
                }

                $results[$channel['id']] = $success;
            } catch (\Exception $e) {
                error_log("Notification error: " . $e->getMessage());
                $results[$channel['id']] = false;
            }
        }

        return $results;
    }

    /**
     * Log a sent notification in the history
     */
    private function logNotification(int $monitorId, int $channelId, string $eventType, string $message): void
    {
        $db = Connection::getInstance();
        $stmt = $db->prepare('
            INSERT INTO notification_history 
            (monitor_id, channel_id, event_type, message) 
            VALUES (:monitor_id, :channel_id, :event_type, :message)
        ');
        $stmt->execute([
            'monitor_id' => $monitorId,
            'channel_id' => $channelId,
            'event_type' => $eventType,
            'message' => $message
        ]);
    }

    /**
     * Check for overdue monitors and send notifications
     *
     * @return int Number of notifications sent
     */
    public function checkOverdueMonitors(): int
    {
        $db = Connection::getInstance();
        $now = time();
        $notificationCount = 0;

        // Pobierz wszystkie monitory
        $monitors = Monitor::findAll();

        foreach ($monitors as $monitor) {
            // Pobierz ostatni ping dla monitora
            $lastPing = $monitor->getLastPing();
            if (!$lastPing) {
                continue; // Brak pingów, nie możemy określić czy jest opóźniony
            }

            // Pobierz konfigurację monitora lub utwórz domyślną
            $config = MonitorConfig::getOrCreate($monitor->id);

            // Jeśli nie mamy ustawionego interwału, przejdź do następnego monitora
            if ($config->expected_interval <= 0) {
                continue;
            }

            // Oblicz kiedy spodziewamy się następnego pinga
            $expectedNextTime = $lastPing->timestamp + $config->expected_interval;

            // Jeśli czas oczekiwania już minął i upłynął czas progu alertu
            if ($now > $expectedNextTime + $config->alert_threshold) {
                // Sprawdź czy już istnieje nierozwiązany rekord overdue dla tego monitora
                $existingOverdue = MonitorOverdueHistory::findUnresolvedByMonitorId($monitor->id);

                if (!$existingOverdue) {
                    // Utwórz nowy rekord historii overdue
                    $overdueTime = new DateTime('@' . $expectedNextTime);
                    $overdueHistory = new MonitorOverdueHistory(
                        $monitor->id,
                        $overdueTime->format('Y-m-d H:i:s')
                    );
                    $overdueHistory->save();

                    // Przygotuj i wyślij powiadomienie
                    $minutesLate = floor(($now - $expectedNextTime) / 60);
                    $message = "Monitor '{$monitor->name}' is overdue by {$minutesLate} minutes.";
                    if ($monitor->project_name) {
                        $message .= " Project: {$monitor->project_name}";
                    }

                    $this->sendNotifications($monitor->id, 'overdue', $message);
                    $notificationCount++;
                }
            } else if ($now <= $expectedNextTime && $lastPing->state !== 'fail') {
                // Jeśli monitor działa w czasie, sprawdź czy nie ma nierozwiązanych zdarzeń overdue
                $existingOverdue = MonitorOverdueHistory::findUnresolvedByMonitorId($monitor->id);

                if ($existingOverdue) {
                    // Rozwiąż zdarzenie overdue
                    $existingOverdue->resolve();

                    // Wyślij powiadomienie o rozwiązaniu
                    $message = "Monitor '{$monitor->name}' is now back on schedule.";
                    if ($monitor->project_name) {
                        $message .= " Project: {$monitor->project_name}";
                    }

                    $this->sendNotifications($monitor->id, 'resolve', $message);
                    $notificationCount++;
                }
            }
        }

        return $notificationCount;
    }

    /**
     * Handle monitor fail event and send notifications if needed
     *
     * @param int $monitorId Monitor ID
     * @param string $errorMessage Error message
     * @return bool Whether notifications were sent
     */
    public function handleMonitorFail(int $monitorId, string $errorMessage): bool
    {
        $monitor = Monitor::findById($monitorId);
        if (!$monitor) {
            return false;
        }

        $message = "Monitor '{$monitor->name}' has failed";
        if ($monitor->project_name) {
            $message .= " (Project: {$monitor->project_name})";
        }

        if ($errorMessage) {
            $message .= ": $errorMessage";
        }

        $results = $this->sendNotifications($monitorId, 'fail', $message);
        return !empty(array_filter($results));
    }

    /**
     * Handle monitor resolution event and send notifications if needed
     *
     * @param int $monitorId Monitor ID
     * @return bool Whether notifications were sent
     */
    public function handleMonitorResolve(int $monitorId): bool
    {
        $monitor = Monitor::findById($monitorId);
        if (!$monitor) {
            return false;
        }

        $message = "Monitor '{$monitor->name}' is now working properly";
        if ($monitor->project_name) {
            $message .= " (Project: {$monitor->project_name})";
        }

        $results = $this->sendNotifications($monitorId, 'resolve', $message);
        return !empty(array_filter($results));
    }
}