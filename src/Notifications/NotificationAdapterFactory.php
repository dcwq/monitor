<?php
// src/Notifications/NotificationAdapterFactory.php

namespace App\Notifications;

class NotificationAdapterFactory
{
    /**
     * Create a notification adapter based on the channel type
     *
     * @param string $type Type of notification channel (slack, email, sms)
     * @return NotificationAdapterInterface
     * @throws \InvalidArgumentException If an unsupported channel type is provided
     */
    public static function create(string $type): NotificationAdapterInterface
    {
        switch (strtolower($type)) {
            case 'slack':
                return new SlackAdapter();
            case 'email':
                return new EmailAdapter();
            case 'sms':
                return new SmsAdapter();
            default:
                throw new \InvalidArgumentException("Unsupported notification channel type: $type");
        }
    }
}