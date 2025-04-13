<?php
// src/Notifications/EmailAdapter.php

namespace App\Notifications;

class EmailAdapter implements NotificationAdapterInterface
{
    public function send(string $message, array $config): bool
    {
        // Sprawdź, czy mamy wymagane parametry konfiguracyjne
        if (!isset($config['to']) || !isset($config['from'])) {
            error_log('Email "to" or "from" address is missing');
            return false;
        }

        $to = $config['to'];
        $subject = $config['subject'] ?? 'Cronitorex Alert';
        $from = $config['from'];
        $headers = [
            'From' => $from,
            'Content-Type' => 'text/html; charset=UTF-8'
        ];

        // Opcjonalnie dodaj CC i BCC jeśli są w konfiguracji
        if (isset($config['cc'])) {
            $headers['Cc'] = $config['cc'];
        }
        if (isset($config['bcc'])) {
            $headers['Bcc'] = $config['bcc'];
        }

        // Stwórz prosty HTML dla wiadomości
        $htmlMessage = "
        <html>
        <head>
            <title>$subject</title>
        </head>
        <body>
            <p>$message</p>
            <p>--<br>Sent by Cronitorex</p>
        </body>
        </html>
        ";

        $result = mail($to, $subject, $htmlMessage, $headers);

        if (!$result) {
            error_log('Failed to send email notification');
            return false;
        }

        return true;
    }
}