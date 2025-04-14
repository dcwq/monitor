<?php

namespace App\Services;

use Lorisleiva\CronTranslator\CronTranslator;

/**
 * Klasa do obliczania oczekiwanego interwału na podstawie wyrażenia CRON
 */
class CronIntervalCalculator
{
    /**
     * Oblicza przybliżony interwał w sekundach na podstawie wyrażenia CRON
     *
     * @param string $cronExpression Wyrażenie CRON w formacie "MIN HOUR DOM MON DOW"
     * @return int Przybliżony interwał w sekundach
     */
    public static function calculateExpectedInterval(string $cronExpression): int
    {
        // Standardowe wyrażenia cron
        $standardExpressions = [
            '@yearly' => 31536000,   // 365 dni
            '@annually' => 31536000, // 365 dni
            '@monthly' => 2592000,   // 30 dni
            '@weekly' => 604800,     // 7 dni
            '@daily' => 86400,       // 24 godziny
            '@midnight' => 86400,    // 24 godziny
            '@hourly' => 3600        // 1 godzina
        ];

        // Sprawdź czy to standardowe wyrażenie
        if (isset($standardExpressions[$cronExpression])) {
            return $standardExpressions[$cronExpression];
        }

        // Dla prostoty obsługujemy tylko typowe przypadki

        // Sprawdź czy to jest wyrażenie typu "@"
        if (strpos($cronExpression, '@') === 0) {
            return 86400; // Domyślnie zakładamy dziennie dla innych typów @expression
        }

        // Rozbij standardowe wyrażenie cron na części
        $parts = preg_split('/\s+/', trim($cronExpression));

        // Sprawdź czy mamy właściwą liczbę części
        if (count($parts) < 5) {
            return 86400; // Domyślnie zakładamy dziennie dla nieprawidłowych wyrażeń
        }

        list($minute, $hour, $dayOfMonth, $month, $dayOfWeek) = $parts;

        // Sprawdź dla minutowego wykonywania (* * * * *)
        if ($minute === '*' && $hour === '*' && $dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*') {
            return 60; // Co minutę
        }

        // Sprawdź dla cyklicznych minut (*/n)
        if (preg_match('/^\*\/(\d+)$/', $minute, $matches)) {
            if ($hour === '*' && $dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*') {
                return intval($matches[1]) * 60; // Co n minut
            }
        }

        // Sprawdź dla cyklicznych godzin (*/n)
        if ($minute === '0' || $minute === '*') {
            if (preg_match('/^\*\/(\d+)$/', $hour, $matches)) {
                if ($dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*') {
                    return intval($matches[1]) * 3600; // Co n godzin
                }
            }
        }

        // Sprawdź wykonywanie co godzinę
        if ($minute === '0' && $hour === '*' && $dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*') {
            return 3600; // Co godzinę
        }

        // Sprawdź wykonywanie co cztery godziny
        if ($minute === '0' && ($hour === '*/4' || $hour === '0,4,8,12,16,20') && $dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*') {
            return 14400; // Co 4 godziny
        }

        // Sprawdź wykonywanie co sześć godzin
        if ($minute === '0' && ($hour === '*/6' || $hour === '0,6,12,18') && $dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*') {
            return 21600; // Co 6 godzin
        }

        // Sprawdź wykonywanie co dwanaście godzin
        if ($minute === '0' && ($hour === '*/12' || $hour === '0,12') && $dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*') {
            return 43200; // Co 12 godzin
        }

        // Sprawdź wykonywanie codziennie
        if (is_numeric($minute) && is_numeric($hour) && $dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*') {
            return 86400; // Codziennie
        }

        // Sprawdź wykonywanie co tydzień
        if (is_numeric($minute) && is_numeric($hour) && $dayOfMonth === '*' && $month === '*' && is_numeric($dayOfWeek)) {
            return 604800; // Co tydzień
        }

        // Sprawdź wykonywanie co miesiąc
        if (is_numeric($minute) && is_numeric($hour) && is_numeric($dayOfMonth) && $month === '*' && $dayOfWeek === '*') {
            return 2592000; // Co miesiąc (30 dni)
        }

        // Jeśli nie rozpoznano wzorca, sprawdź heurystykę
        if ($minute !== '*') {
            if ($hour !== '*') {
                if ($dayOfMonth !== '*' || $dayOfWeek !== '*') {
                    if ($month !== '*') {
                        return 31536000; // Roczne, jeśli wszystkie pola są określone
                    }
                    return 2592000; // Miesięczne, jeśli określony jest dzień
                }
                return 86400; // Codziennie, jeśli określona jest godzina
            }
            return 3600; // Co godzinę, jeśli określona jest tylko minuta
        }

        // Domyślnie zakładamy codzienny interwał
        return 86400;
    }

    /**
     * Zwraca czytelny opis interwału
     *
     * @param int $interval Interwał w sekundach
     * @return string Czytelny opis
     */
    public static function getReadableInterval(int $interval): string
    {
        if ($interval <= 60) {
            return "Co minutę";
        } elseif ($interval < 3600) {
            $minutes = $interval / 60;
            return "Co " . ($minutes == 1 ? "minutę" : "{$minutes} minut");
        } elseif ($interval < 86400) {
            $hours = $interval / 3600;
            return "Co " . ($hours == 1 ? "godzinę" : "{$hours} godzin");
        } elseif ($interval < 604800) {
            $days = $interval / 86400;
            return "Co " . ($days == 1 ? "dzień" : "{$days} dni");
        } elseif ($interval < 2592000) {
            $weeks = $interval / 604800;
            return "Co " . ($weeks == 1 ? "tydzień" : "{$weeks} tygodni");
        } elseif ($interval < 31536000) {
            $months = $interval / 2592000;
            return "Co " . ($months == 1 ? "miesiąc" : "{$months} miesięcy");
        } else {
            $years = $interval / 31536000;
            return "Co " . ($years == 1 ? "rok" : "{$years} lat");
        }
    }

    public static function getReadableCronExpression(string $cronExpression): string
    {
        return CronTranslator::translate($cronExpression, 'en');
    }
}