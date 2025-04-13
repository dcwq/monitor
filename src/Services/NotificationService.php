<?php
// src/Services/NotificationService.php

namespace App\Services;

use App\Entity\Monitor;
use App\Entity\MonitorOverdueHistory;
use App\Enum\NotificationEventType;
use App\Notifications\NotificationAdapterFactory;
use App\Repository\MonitorConfigRepositoryInterface;
use App\Repository\MonitorOverdueHistoryRepositoryInterface;
use App\Repository\MonitorRepositoryInterface;
use DateTime;
use Doctrine\DBAL\Connection;

class NotificationService
{
    private Connection $connection;
    private MonitorRepositoryInterface $monitorRepository;
    private MonitorConfigRepositoryInterface $monitorConfigRepository;
    private MonitorOverdueHistoryRepositoryInterface $overdueHistoryRepository;

    public function __construct(
        Connection $connection,
        MonitorRepositoryInterface $monitorRepository,
        MonitorConfigRepositoryInterface $monitorConfigRepository,
        MonitorOverdueHistoryRepositoryInterface $overdueHistoryRepository
    ) {
        $this->connection = $connection;
        $this->monitorRepository = $monitorRepository;
        $this->monitorConfigRepository = $monitorConfigRepository;
        $this->overdueHistoryRepository = $overdueHistoryRepository;
    }

    /**
     * Send notification for a monitor
     *
     * @param int $monitorId Monitor ID
     * @param NotificationEventType $eventType Event type: 'fail', 'overdue', 'resolve'
     * @param string $message Message to send
     * @return array Array of notification results with channel IDs
     */
    public function sendNotifications(int $monitorId, NotificationEventType $eventType, string $message): array
    {
        // Get channel configurations for this monitor
        $stmt = $this->connection->prepare('
            SELECT nc.id, nc.name, nc.type, nc.config, mn.notify_on_fail, mn.notify_on_overdue, mn.notify_on_resolve 
            FROM notification_channels nc
            JOIN monitor_notifications mn ON nc.id = mn.channel_id
            WHERE mn.monitor_id = :monitor_id
        ');
        $resultSet = $stmt->executeQuery(['monitor_id' => $monitorId]);
        $channels = $resultSet->fetchAllAssociative();

        $results = [];

        foreach ($channels as $channel) {
            // Check if this channel should be notified for this event type
            if (
                ($eventType === NotificationEventType::FAIL && !$channel['notify_on_fail']) ||
                ($eventType === NotificationEventType::OVERDUE && !$channel['notify_on_overdue']) ||
                ($eventType === NotificationEventType::RESOLVE && !$channel['notify_on_resolve'])
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

                // Log notification history
                if ($success) {
                    $this->logNotification($monitorId, $channel['id'], $eventType->value, $message);
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
        $stmt = $this->connection->prepare('
            INSERT INTO notification_history 
            (monitor_id, channel_id, event_type, message) 
            VALUES (:monitor_id, :channel_id, :event_type, :message)
        ');
        $stmt->executeStatement([
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
        $now = time();
        $notificationCount = 0;

        // Get all monitors
        $monitors = $this->monitorRepository->findAll();

        foreach ($monitors as $monitor) {
            // Get last ping for monitor
            $lastPing = $monitor->getLastPing();
            if (!$lastPing) {
                continue; // No pings, can't determine if overdue
            }

            // Get monitor configuration or create default
            $config = $this->monitorConfigRepository->getOrCreate($monitor);

            // If no interval set, skip to next monitor
            if ($config->getExpectedInterval() <= 0) {
                continue;
            }

            // Calculate when we expect the next ping
            $expectedNextTime = $lastPing->getTimestamp() + $config->getExpectedInterval();

            // If expected time has passed and alert threshold time has also passed
            if ($now > $expectedNextTime + $config->getAlertThreshold()) {
                // Check if there's already an unresolved overdue record for this monitor
                $existingOverdue = $this->overdueHistoryRepository->findUnresolvedByMonitor($monitor);

                if (!$existingOverdue) {
                    // Create new overdue history record
                    $overdueTime = new DateTime('@' . $expectedNextTime);
                    $overdueHistory = new MonitorOverdueHistory($monitor, $overdueTime);
                    $this->overdueHistoryRepository->save($overdueHistory);

                    // Prepare and send notification
                    $minutesLate = floor(($now - $expectedNextTime) / 60);
                    $message = "Monitor '{$monitor->getName()}' is overdue by {$minutesLate} minutes.";
                    if ($monitor->getProjectName()) {
                        $message .= " Project: {$monitor->getProjectName()}";
                    }

                    $this->sendNotifications($monitor->getId(), NotificationEventType::OVERDUE, $message);
                    $notificationCount++;
                }
            } else if ($now <= $expectedNextTime && $lastPing->getState() !== 'fail') {
                // If monitor is on time, check for unresolved overdue events
                $existingOverdue = $this->overdueHistoryRepository->findUnresolvedByMonitor($monitor);

                if ($existingOverdue) {
                    // Resolve the overdue event
                    $existingOverdue->resolve();
                    $this->overdueHistoryRepository->save($existingOverdue);

                    // Send resolution notification
                    $message = "Monitor '{$monitor->getName()}' is now back on schedule.";
                    if ($monitor->getProjectName()) {
                        $message .= " Project: {$monitor->getProjectName()}";
                    }

                    $this->sendNotifications($monitor->getId(), NotificationEventType::RESOLVE, $message);
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
        $monitor = $this->monitorRepository->findById($monitorId);
        if (!$monitor) {
            return false;
        }

        $message = "Monitor '{$monitor->getName()}' has failed";
        if ($monitor->getProjectName()) {
            $message .= " (Project: {$monitor->getProjectName()})";
        }

        if ($errorMessage) {
            $message .= ": $errorMessage";
        }

        $results = $this->sendNotifications($monitorId, NotificationEventType::FAIL, $message);
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
        $monitor = $this->monitorRepository->findById($monitorId);
        if (!$monitor) {
            return false;
        }

        $message = "Monitor '{$monitor->getName()}' is now working properly";
        if ($monitor->getProjectName()) {
            $message .= " (Project: {$monitor->getProjectName()})";
        }

        $results = $this->sendNotifications($monitorId, NotificationEventType::RESOLVE, $message);
        return !empty(array_filter($results));
    }
}