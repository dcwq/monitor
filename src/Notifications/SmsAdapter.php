<?php
// src/Notifications/SmsAdapter.php

namespace App\Notifications;

class SmsAdapter implements NotificationAdapterInterface
{
    public function send(string $message, array $config): bool
    {
        // To jest tylko przykład - w rzeczywistości użylibyśmy usługi takiej jak Twilio, Vonage itp.
        if (!isset($config['phone_number']) || !isset($config['api_key'])) {
            error_log('SMS phone number or API key is missing');
            return false;
        }

        // Tutaj byłaby implementacja wysyłania SMS przez konkretnego dostawcę
        // Na potrzeby przykładu po prostu logujemy wiadomość
        error_log("SMS would be sent to {$config['phone_number']}: $message");

        // Symulujemy udane wysłanie
        return true;
    }
}