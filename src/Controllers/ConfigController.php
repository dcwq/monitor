<?php
// src/Controllers/ConfigController.php

namespace App\Controllers;

use App\Application;
use App\Entity\MonitorConfig;
use App\Repository\MonitorConfigRepositoryInterface;
use App\Repository\MonitorOverdueHistoryRepositoryInterface;
use App\Repository\MonitorRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Twig\Environment;

class ConfigController
{
    private Environment $twig;
    private Application $app;
    private $container;

    private $connection;
    private MonitorRepositoryInterface $monitorRepository;
    private MonitorConfigRepositoryInterface $monitorConfigRepository;
    private MonitorOverdueHistoryRepositoryInterface $overdueHistoryRepository;

    public function __construct(Environment $twig, Application $app, $container)
    {
        $this->twig = $twig;
        $this->app = $app;
        $this->container = $container;
        $this->monitorRepository = $container->get(MonitorRepositoryInterface::class);
        $this->monitorConfigRepository = $container->get(MonitorConfigRepositoryInterface::class);
        $this->overdueHistoryRepository = $container->get(MonitorOverdueHistoryRepositoryInterface::class);
        $this->connection = $container->get(\Doctrine\ORM\EntityManagerInterface::class)->getConnection();
    }

    public function editMonitorConfig(Request $request, int $id): Response
    {
        $monitor = $this->monitorRepository->findById($id);
        if (!$monitor) {
            return new Response('Monitor not found', 404);
        }

        // Get or create config
        $config = $this->monitorConfigRepository->getOrCreate($monitor);

        if ($request->isMethod('POST')) {
            // Update config
            $config->setExpectedInterval((int)$request->request->get('expected_interval', MonitorConfig::DEFAULT_EXPECTED_INTERVAL));
            $config->setAlertThreshold((int)$request->request->get('alert_threshold', MonitorConfig::DEFAULT_ALERT_THRESHOLD));
            $this->monitorConfigRepository->save($config);

            // Update monitor project
            $monitor->setProjectName($request->request->get('project_name'));
            $this->monitorRepository->save($monitor);

            // Redirect to monitor view
            return new RedirectResponse($this->app->generateUrl('monitor_show', ['id' => $monitor->getId()]));
        }

        return new Response($this->twig->render('config/monitor_config.html.twig', [
            'monitor' => $monitor,
            'config' => $config
        ]));
    }

    public function notificationChannels(Request $request): Response
    {
        // Get all notification channels
        $stmt = $this->connection->prepare('SELECT * FROM notification_channels ORDER BY name');
        $resultSet = $stmt->executeQuery();
        $channels = $resultSet->fetchAllAssociative();

        // Decode JSON config for each channel
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

            // Build config based on channel type
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

            // Save new channel
            $stmt = $this->connection->prepare('
                INSERT INTO notification_channels (name, type, config) 
                VALUES (:name, :type, :config)
            ');
            $stmt->executeStatement([
                'name' => $name,
                'type' => $type,
                'config' => json_encode($config)
            ]);

            return new RedirectResponse($this->app->generateUrl('notification_channels'));
        }

        return new Response($this->twig->render('config/add_channel.html.twig'));
    }

    public function editChannel(Request $request, int $id): Response
    {
        // Get channel
        $stmt = $this->connection->prepare('SELECT * FROM notification_channels WHERE id = :id');
        $resultSet = $stmt->executeQuery(['id' => $id]);
        $channel = $resultSet->fetchAssociative();

        if (!$channel) {
            return new Response('Channel not found', 404);
        }

        $config = [];
        if (isset($channel['config']) && is_string($channel['config'])) {
            $config = json_decode($channel['config'], true);
        }

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $type = $channel['type']; // Don't change type of existing channel
            $newConfig = [];

            // Update config based on channel type
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

            // Update channel
            $stmt = $this->connection->prepare('
                UPDATE notification_channels 
                SET name = :name, config = :config 
                WHERE id = :id
            ');
            $stmt->executeStatement([
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
        $monitor = $this->monitorRepository->findById($id);
        if (!$monitor) {
            return new Response('Monitor not found', 404);
        }

        // Get all channels
        $stmt = $this->connection->prepare('SELECT * FROM notification_channels ORDER BY name');
        $resultSet = $stmt->executeQuery();
        $channels = $resultSet->fetchAllAssociative();

        // Get channels associated with monitor
        $stmt = $this->connection->prepare('
            SELECT channel_id, notify_on_fail, notify_on_overdue, notify_on_resolve 
            FROM monitor_notifications 
            WHERE monitor_id = :monitor_id
        ');
        $resultSet = $stmt->executeQuery(['monitor_id' => $monitor->getId()]);
        $monitorChannels = [];
        while ($row = $resultSet->fetchAssociative()) {
            $monitorChannels[$row['channel_id']] = $row;
        }

        if ($request->isMethod('POST')) {
            // Directly use $_POST
            $selectedChannels = isset($_POST['channels']) ? (array)$_POST['channels'] : [];
            $notifyOnFail = isset($_POST['notify_on_fail']) ? (array)$_POST['notify_on_fail'] : [];
            $notifyOnOverdue = isset($_POST['notify_on_overdue']) ? (array)$_POST['notify_on_overdue'] : [];
            $notifyOnResolve = isset($_POST['notify_on_resolve']) ? (array)$_POST['notify_on_resolve'] : [];

            // Remove all existing associations
            $stmt = $this->connection->prepare('DELETE FROM monitor_notifications WHERE monitor_id = :monitor_id');
            $stmt->executeStatement(['monitor_id' => $monitor->getId()]);

            // Add new associations
            foreach ($selectedChannels as $channelId) {
                $stmt = $this->connection->prepare('
                    INSERT INTO monitor_notifications 
                    (monitor_id, channel_id, notify_on_fail, notify_on_overdue, notify_on_resolve) 
                    VALUES (:monitor_id, :channel_id, :notify_on_fail, :notify_on_overdue, :notify_on_resolve)
                ');
                $stmt->executeStatement([
                    'monitor_id' => $monitor->getId(),
                    'channel_id' => $channelId,
                    'notify_on_fail' => in_array($channelId, $notifyOnFail) ? 1 : 0,
                    'notify_on_overdue' => in_array($channelId, $notifyOnOverdue) ? 1 : 0,
                    'notify_on_resolve' => in_array($channelId, $notifyOnResolve) ? 1 : 0
                ]);
            }

            return new RedirectResponse($this->app->generateUrl('monitor_show', ['id' => $monitor->getId()]));
        }

        return new Response($this->twig->render('config/monitor_notifications.html.twig', [
            'monitor' => $monitor,
            'channels' => $channels,
            'monitorChannels' => $monitorChannels
        ]));
    }

    public function overdueHistory(Request $request, int $id): Response
    {
        $monitor = $this->monitorRepository->findById($id);
        if (!$monitor) {
            return new Response('Monitor not found', 404);
        }

        $limit = $request->query->getInt('limit', 50);
        $history = $this->overdueHistoryRepository->findByMonitorId($monitor->getId(), $limit);

        return new Response($this->twig->render('monitor/overdue_history.html.twig', [
            'monitor' => $monitor,
            'history' => $history
        ]));
    }
}