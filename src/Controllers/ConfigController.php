<?php
// src/Controllers/ConfigController.php

namespace App\Controllers;

use App\Application;
use App\Models\Monitor;
use App\Models\MonitorConfig;
use App\Models\MonitorOverdueHistory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Twig\Environment;

class ConfigController
{
    private Environment $twig;
    private Application $app;

    public function __construct(Environment $twig, Application $app)
    {
        $this->twig = $twig;
        $this->app = $app;
    }

    public function editMonitorConfig(Request $request, int $id): Response
    {
        $monitor = Monitor::findById($id);
        if (!$monitor) {
            return new Response('Monitor not found', 404);
        }

        // Pobierz lub utwórz konfigurację
        $config = MonitorConfig::getOrCreate($monitor->id);

        if ($request->isMethod('POST')) {
            // Aktualizuj konfigurację
            $config->expected_interval = (int)$request->request->get('expected_interval', 3600);
            $config->alert_threshold = (int)$request->request->get('alert_threshold', 0);
            $config->save();

            // Aktualizuj projekt monitora
            $monitor->project_name = $request->request->get('project_name');
            $monitor->save();

            // Przekieruj do widoku monitora
            return new RedirectResponse($this->app->generateUrl('monitor_show', ['id' => $monitor->id]));
        }

        return new Response($this->twig->render('config/monitor_config.html.twig', [
            'monitor' => $monitor,
            'config' => $config
        ]));
    }

    public function notificationChannels(Request $request): Response
    {
        $db = \App\Connection::getInstance();

        // Pobierz wszystkie kanały powiadomień
        $stmt = $db->query('SELECT * FROM notification_channels ORDER BY name');
        $channels = $stmt->fetchAll();

        // Dekoduj konfigurację JSON dla każdego kanału
        foreach ($channels as &$channel) {
            if (isset($channel['config']) && is_string($channel['config'])) {
                $channel['config_decoded'] = json_decode($channel['config'], true);
            } else {
                $channel['config_decoded'] = [];
            }
        }

        return new Response($this->twig->render('config/notification_channels.html.twig', [
            'channels' => $channels
        ]));
    }

    public function addChannel(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $type = $request->request->get('type');
            $config = [];

            // Buduj konfigurację zależnie od typu kanału
            switch ($type) {
                case 'slack':
                    $config['webhook_url'] = $request->request->get('webhook_url');
                    $config['username'] = $request->request->get('username', 'Cronitorex');
                    $config['icon_emoji'] = $request->request->get('icon_emoji', ':warning:');
                    $config['channel'] = $request->request->get('channel', '');
                    break;
                case 'email':
                    $config['to'] = $request->request->get('to');
                    $config['from'] = $request->request->get('from');
                    $config['subject'] = $request->request->get('subject', 'Cronitorex Alert');
                    $config['cc'] = $request->request->get('cc');
                    $config['bcc'] = $request->request->get('bcc');
                    break;
                case 'sms':
                    $config['phone_number'] = $request->request->get('phone_number');
                    $config['api_key'] = $request->request->get('api_key');
                    break;
                default:
                    return new Response('Invalid channel type', 400);
            }

            // Zapisz nowy kanał
            $db = \App\Connection::getInstance();
            $stmt = $db->prepare('
                INSERT INTO notification_channels (name, type, config) 
                VALUES (:name, :type, :config)
            ');
            $stmt->execute([
                'name' => $name,
                'type' => $type,
                'config' => json_encode($config)
            ]);

            return new RedirectResponse($this->app->generateUrl('notification_channels'));
        }

        return new Response($this->twig->render('config/add_channel.html.twig'));
    }

    // src/Controllers/ConfigController.php - zaktualizuj metodę editChannel

    public function editChannel(Request $request, int $id): Response
    {
        $db = \App\Connection::getInstance();

        // Pobierz kanał
        $stmt = $db->prepare('SELECT * FROM notification_channels WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $channel = $stmt->fetch();

        if (!$channel) {
            return new Response('Channel not found', 404);
        }

        $config = [];
        if (isset($channel['config']) && is_string($channel['config'])) {
            $config = json_decode($channel['config'], true);
        }

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $type = $channel['type']; // Nie zmieniamy typu istniejącego kanału
            $newConfig = [];

            // Aktualizuj konfigurację zależnie od typu kanału
            switch ($type) {
                case 'slack':
                    $newConfig['webhook_url'] = $request->request->get('webhook_url');
                    $newConfig['username'] = $request->request->get('username', 'Cronitorex');
                    $newConfig['icon_emoji'] = $request->request->get('icon_emoji', ':warning:');
                    $newConfig['channel'] = $request->request->get('channel', '');
                    break;
                case 'email':
                    $newConfig['to'] = $request->request->get('to');
                    $newConfig['from'] = $request->request->get('from');
                    $newConfig['subject'] = $request->request->get('subject', 'Cronitorex Alert');
                    $newConfig['cc'] = $request->request->get('cc');
                    $newConfig['bcc'] = $request->request->get('bcc');
                    break;
                case 'sms':
                    $newConfig['phone_number'] = $request->request->get('phone_number');
                    $newConfig['api_key'] = $request->request->get('api_key');
                    break;
            }

            // Aktualizuj kanał
            $stmt = $db->prepare('
            UPDATE notification_channels 
            SET name = :name, config = :config 
            WHERE id = :id
        ');
            $stmt->execute([
                'id' => $id,
                'name' => $name,
                'config' => json_encode($newConfig)
            ]);

            return new RedirectResponse($this->app->generateUrl('notification_channels'));
        }

        return new Response($this->twig->render('config/edit_channel.html.twig', [
            'channel' => $channel,
            'config' => $config
        ]));
    }

    public function monitorNotifications(Request $request, int $id): Response
    {
        $monitor = Monitor::findById($id);
        if (!$monitor) {
            return new Response('Monitor not found', 404);
        }

        $db = \App\Connection::getInstance();

        // Pobierz wszystkie kanały
        $stmt = $db->query('SELECT * FROM notification_channels ORDER BY name');
        $channels = $stmt->fetchAll();

        // Pobierz kanały powiązane z monitorem
        $stmt = $db->prepare('
        SELECT channel_id, notify_on_fail, notify_on_overdue, notify_on_resolve 
        FROM monitor_notifications 
        WHERE monitor_id = :monitor_id
    ');
        $stmt->execute(['monitor_id' => $monitor->id]);
        $monitorChannels = [];
        while ($row = $stmt->fetch()) {
            $monitorChannels[$row['channel_id']] = $row;
        }

        if ($request->isMethod('POST')) {
            // Bezpośrednio użyj $_POST
            $selectedChannels = isset($_POST['channels']) ? (array)$_POST['channels'] : [];
            $notifyOnFail = isset($_POST['notify_on_fail']) ? (array)$_POST['notify_on_fail'] : [];
            $notifyOnOverdue = isset($_POST['notify_on_overdue']) ? (array)$_POST['notify_on_overdue'] : [];
            $notifyOnResolve = isset($_POST['notify_on_resolve']) ? (array)$_POST['notify_on_resolve'] : [];

            // Usuń wszystkie istniejące powiązania
            $stmt = $db->prepare('DELETE FROM monitor_notifications WHERE monitor_id = :monitor_id');
            $stmt->execute(['monitor_id' => $monitor->id]);

            // Dodaj nowe powiązania
            foreach ($selectedChannels as $channelId) {
                $stmt = $db->prepare('
                INSERT INTO monitor_notifications 
                (monitor_id, channel_id, notify_on_fail, notify_on_overdue, notify_on_resolve) 
                VALUES (:monitor_id, :channel_id, :notify_on_fail, :notify_on_overdue, :notify_on_resolve)
            ');
                $stmt->execute([
                    'monitor_id' => $monitor->id,
                    'channel_id' => $channelId,
                    'notify_on_fail' => in_array($channelId, $notifyOnFail) ? 1 : 0,
                    'notify_on_overdue' => in_array($channelId, $notifyOnOverdue) ? 1 : 0,
                    'notify_on_resolve' => in_array($channelId, $notifyOnResolve) ? 1 : 0
                ]);
            }

            return new RedirectResponse($this->app->generateUrl('monitor_show', ['id' => $monitor->id]));
        }

        return new Response($this->twig->render('config/monitor_notifications.html.twig', [
            'monitor' => $monitor,
            'channels' => $channels,
            'monitorChannels' => $monitorChannels
        ]));
    }

    public function overdueHistory(Request $request, int $id): Response
    {
        $monitor = Monitor::findById($id);
        if (!$monitor) {
            return new Response('Monitor not found', 404);
        }

        $limit = $request->query->getInt('limit', 50);
        $history = MonitorOverdueHistory::findByMonitorId($monitor->id, $limit);

        return new Response($this->twig->render('monitor/overdue_history.html.twig', [
            'monitor' => $monitor,
            'history' => $history
        ]));
    }
}