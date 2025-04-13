<?php
// src/Services/NotificationService.php

namespace App\Services;

use App\Entity\Monitor;
use App\Entity\MonitorNotification;
use App\Entity\MonitorOverdueHistory;
use App\Entity\NotificationChannel;
use App\Entity\NotificationHistory;
use App\Enum\NotificationEventType;
use App\Notifications\NotificationAdapterFactory;
use App\Repository\GroupNotificationRepositoryInterface;
use App\Repository\MonitorConfigRepositoryInterface;
use App\Repository\MonitorOverdueHistoryRepositoryInterface;
use App\Repository\MonitorRepositoryInterface;
use App\Repository\NotificationChannelRepositoryInterface;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;

class NotificationService
{
    private EntityManagerInterface $entityManager;
    private MonitorRepositoryInterface $monitorRepository;
    private MonitorConfigRepositoryInterface $monitorConfigRepository;
    private MonitorOverdueHistoryRepositoryInterface $overdueHistoryRepository;
    private NotificationChannelRepositoryInterface $notificationChannelRepository;

    private GroupNotificationRepositoryInterface $groupNotificationRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        MonitorRepositoryInterface $monitorRepository,
        MonitorConfigRepositoryInterface $monitorConfigRepository,
        MonitorOverdueHistoryRepositoryInterface $overdueHistoryRepository,
        NotificationChannelRepositoryInterface $notificationChannelRepository,
        GroupNotificationRepositoryInterface $groupNotificationRepository
    ) {
        $this->entityManager = $entityManager;
        $this->monitorRepository = $monitorRepository;
        $this->monitorConfigRepository = $monitorConfigRepository;
        $this->overdueHistoryRepository = $overdueHistoryRepository;
        $this->notificationChannelRepository = $notificationChannelRepository;
        $this->groupNotificationRepository = $groupNotificationRepository;
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
        $monitor = $this->monitorRepository->findById($monitorId);
        if (!$monitor) {
            return [];
        }

        // Get notification configurations for this monitor
        $notificationConfigs = $this->entityManager->createQueryBuilder()
            ->select('mn, c')
            ->from(MonitorNotification::class, 'mn')
            ->join('mn.channel', 'c')
            ->where('mn.monitor = :monitor')
            ->setParameter('monitor', $monitor)
            ->getQuery()
            ->getResult();

        $results = [];

        foreach ($notificationConfigs as $notificationConfig) {
            $channel = $notificationConfig->getChannel();

            // Check if this channel should be notified for this event type
            if (
                ($eventType === NotificationEventType::FAIL && !$notificationConfig->isNotifyOnFail()) ||
                ($eventType === NotificationEventType::OVERDUE && !$notificationConfig->isNotifyOnOverdue()) ||
                ($eventType === NotificationEventType::RESOLVE && !$notificationConfig->isNotifyOnResolve())
            ) {
                continue;
            }

            try {
                $config = json_decode($channel->getConfig(), true);
                if (!is_array($config)) {
                    error_log("Invalid channel configuration for {$channel->getName()}");
                    $results[$channel->getId()] = false;
                    continue;
                }

                $adapter = NotificationAdapterFactory::create($channel->getType());
                $success = $adapter->send($message, $config);

                // Log notification history
                if ($success) {
                    $this->logNotification($monitor, $channel, $eventType->value, $message);
                }

                $results[$channel->getId()] = $success;
            } catch (\Exception $e) {
                error_log("Notification error: " . $e->getMessage());
                $results[$channel->getId()] = false;
            }
        }

        return $results;
    }

    /**
     * Log a sent notification in the history
     */
    private function logNotification(Monitor $monitor, NotificationChannel $channel, string $eventType, string $message): void
    {
        $history = new NotificationHistory();
        $history->setMonitor($monitor);
        $history->setChannel($channel);
        $history->setEventType($eventType);
        $history->setMessage($message);

        $this->entityManager->persist($history);
        $this->entityManager->flush();
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

                    // Add group information if available
                    if ($monitor->getGroup()) {
                        $message .= " Group: {$monitor->getGroup()->getName()}.";
                    }

                    // Add project information if available
                    if ($monitor->getProjectName()) {
                        $message .= " Project: {$monitor->getProjectName()}.";
                    }

                    // Add tag information if available
                    $tags = $monitor->getTags();
                    if (!$tags->isEmpty()) {
                        $tagNames = [];
                        foreach ($tags as $tag) {
                            $tagNames[] = $tag->getName();
                        }
                        $message .= " Tags: " . implode(', ', $tagNames) . ".";
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

                    // Add group information if available
                    if ($monitor->getGroup()) {
                        $message .= " Group: {$monitor->getGroup()->getName()}.";
                    }

                    // Add project information if available
                    if ($monitor->getProjectName()) {
                        $message .= " Project: {$monitor->getProjectName()}.";
                    }

                    // Add tag information if available
                    $tags = $monitor->getTags();
                    if (!$tags->isEmpty()) {
                        $tagNames = [];
                        foreach ($tags as $tag) {
                            $tagNames[] = $tag->getName();
                        }
                        $message .= " Tags: " . implode(', ', $tagNames) . ".";
                    }

                    // Add tag information if available
                    $tags = $monitor->getTags();
                    if (!$tags->isEmpty()) {
                        $tagNames = [];
                        foreach ($tags as $tag) {
                            $tagNames[] = $tag->getName();
                        }
                        $message .= " Tags: " . implode(', ', $tagNames) . ".";
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

        // Add group information if available
        if ($monitor->getGroup()) {
            $message .= " (Group: {$monitor->getGroup()->getName()})";
        }

        // Add project information if available
        if ($monitor->getProjectName()) {
            $message .= " (Project: {$monitor->getProjectName()})";
        }

        // Add tag information if available
        $tags = $monitor->getTags();
        if (!$tags->isEmpty()) {
            $tagNames = [];
            foreach ($tags as $tag) {
                $tagNames[] = $tag->getName();
            }
            $message .= " [Tags: " . implode(', ', $tagNames) . "]";
        }

        if ($errorMessage) {
            $message .= ": $errorMessage";
        }

        // Send monitor-specific notifications
        $results = $this->sendNotifications($monitorId, NotificationEventType::FAIL, $message);

        // Send group-level notifications if group exists
        $groupResults = [];
        if ($monitor->getGroup()) {
            // Add prefix to group message to differentiate
            $groupMessage = "[GROUP NOTIFICATION] " . $message;
            $groupResults = $this->sendGroupNotifications($monitor->getGroup()->getId(), NotificationEventType::FAIL, $groupMessage);
        }

        return !empty(array_filter(array_merge($results, $groupResults)));
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

        // Add group information if available
        if ($monitor->getGroup()) {
            $message .= " (Group: {$monitor->getGroup()->getName()})";
        }

        // Add project information if available
        if ($monitor->getProjectName()) {
            $message .= " (Project: {$monitor->getProjectName()})";
        }

        // Add tag information if available
        $tags = $monitor->getTags();
        if (!$tags->isEmpty()) {
            $tagNames = [];
            foreach ($tags as $tag) {
                $tagNames[] = $tag->getName();
            }
            $message .= " [Tags: " . implode(', ', $tagNames) . "]";
        }

        // Send monitor-specific notifications
        $results = $this->sendNotifications($monitorId, NotificationEventType::RESOLVE, $message);

        // Send group-level notifications if group exists
        $groupResults = [];
        if ($monitor->getGroup()) {
            // Handle group-level notifications (would need a separate implementation)
            // This would be similar to monitor notifications but for groups
            // For now, just assume no group notifications were sent
        }

        return !empty(array_filter(array_merge($results, $groupResults)));
    }

    /**
     * Send notification for a group
     *
     * @param int $groupId Group ID
     * @param NotificationEventType $eventType Event type: 'fail', 'overdue', 'resolve'
     * @param string $message Message to send
     * @return array Array of notification results with channel IDs
     */
    public function sendGroupNotifications(int $groupId, NotificationEventType $eventType, string $message): array
    {
        // Get all notification configurations for this group
        $groupNotifications = $this->groupNotificationRepository->findByGroupId($groupId);

        $results = [];

        foreach ($groupNotifications as $notification) {
            $channel = $notification->getChannel();

            // Check if this channel should be notified for this event type
            if (
                ($eventType === NotificationEventType::FAIL && !$notification->isNotifyOnFail()) ||
                ($eventType === NotificationEventType::OVERDUE && !$notification->isNotifyOnOverdue()) ||
                ($eventType === NotificationEventType::RESOLVE && !$notification->isNotifyOnResolve())
            ) {
                continue;
            }

            try {
                $config = json_decode($channel->getConfig(), true);
                if (!is_array($config)) {
                    error_log("Invalid channel configuration for {$channel->getName()}");
                    $results[$channel->getId()] = false;
                    continue;
                }

                $adapter = NotificationAdapterFactory::create($channel->getType());
                $success = $adapter->send($message, $config);

                $results[$channel->getId()] = $success;
            } catch (\Exception $e) {
                error_log("Group notification error: " . $e->getMessage());
                $results[$channel->getId()] = false;
            }
        }

        return $results;
    }
}