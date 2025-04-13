<?php
// src/Notifications/NotificationAdapterInterface.php

namespace App\Notifications;

interface NotificationAdapterInterface
{
    /**
     * Send a notification
     *
     * @param string $message The message to send
     * @param array $config Configuration for the notification channel
     * @return bool Whether the notification was sent successfully
     */
    public function send(string $message, array $config): bool;
}