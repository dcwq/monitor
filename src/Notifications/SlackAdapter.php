<?php
// src/Notifications/SlackAdapter.php

namespace App\Notifications;

class SlackAdapter implements NotificationAdapterInterface
{
    public function send(string $message, array $config): bool
    {
        // Sprawdź, czy mamy wymagane parametry konfiguracyjne
        if (!isset($config['webhook_url'])) {
            error_log('Slack webhook URL is missing');
            return false;
        }

        $webhookUrl = $config['webhook_url'];
        $payload = json_encode([
            'text' => $message,
            'username' => $config['username'] ?? 'Cronitorex',
            'icon_emoji' => $config['icon_emoji'] ?? ':warning:',
            'channel' => $config['channel'] ?? ''
        ]);

        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['payload' => $payload]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode != 200) {
            error_log("Slack notification failed with HTTP code $httpCode: $result");
            return false;
        }

        return true;
    }
}