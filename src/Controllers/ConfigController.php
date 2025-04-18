<?php
// src/Controllers/ConfigController.php

namespace App\Controllers;

use App\Application;
use App\Entity\MonitorConfig;
use App\Entity\NotificationChannel;
use App\Repository\MonitorConfigRepositoryInterface;
use App\Repository\MonitorOverdueHistoryRepositoryInterface;
use App\Repository\MonitorRepositoryInterface;
use App\Repository\NotificationChannelRepositoryInterface;
use App\Repository\PingRepositoryInterface;
use App\Services\CronIntervalCalculator;
use App\Services\MonitorSchedulerService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Twig\Environment;

class ConfigController
{
    private Environment $twig;
    private Application $app;
    private $container;

    private MonitorRepositoryInterface $monitorRepository;
    private MonitorConfigRepositoryInterface $monitorConfigRepository;
    private MonitorOverdueHistoryRepositoryInterface $overdueHistoryRepository;
    private NotificationChannelRepositoryInterface $notificationChannelRepository;
    private PingRepositoryInterface $pingRepository;
    private \Doctrine\ORM\EntityManagerInterface $entityManager;
    private MonitorSchedulerService $schedulerService;

    public function __construct(Environment $twig, Application $app, $container)
    {
        $this->twig = $twig;
        $this->app = $app;
        $this->container = $container;
        $this->monitorRepository = $container->get(MonitorRepositoryInterface::class);
        $this->monitorConfigRepository = $container->get(MonitorConfigRepositoryInterface::class);
        $this->overdueHistoryRepository = $container->get(MonitorOverdueHistoryRepositoryInterface::class);
        $this->notificationChannelRepository = $container->get(NotificationChannelRepositoryInterface::class);
        $this->pingRepository = $container->get(PingRepositoryInterface::class);
        $this->entityManager = $container->get(\Doctrine\ORM\EntityManagerInterface::class);
        $this->schedulerService = $container->get(MonitorSchedulerService::class);
    }

    public function editMonitorConfig(Request $request, int $id): Response
    {
        $monitor = $this->monitorRepository->findById($id);
        if (!$monitor) {
            return new Response('Monitor not found', 404);
        }

        // Get or create config
        $config = $this->monitorConfigRepository->getOrCreate($monitor);

        // Pobierz ostatni ping, aby sprawdzić czy ma cron_schedule
        $lastPings = $this->pingRepository->findRecentByMonitor($monitor->getId(), 1);
        $lastPing = !empty($lastPings) ? $lastPings[0] : null;
        $cronSchedule = ($lastPing && $lastPing->getCronSchedule()) ? $lastPing->getCronSchedule() : null;

        if ($request->isMethod('POST')) {
            // Dodaj debugowanie
            error_log('POST data: ' . print_r($request->request->all(), true));

            // Pobierz cron_expression z formularza
            $cronExpression = $request->request->get('cron_expression');

            // Jeśli przesłano cron_expression, zapisz go do konfiguracji
            if (!empty($cronExpression)) {
                $config->setCronExpression($cronExpression);

                // Oblicz expectedInterval na podstawie wyrażenia cron
                $expectedInterval = CronIntervalCalculator::calculateExpectedInterval($cronExpression);
                $config->setExpectedInterval($expectedInterval);
            } else {
                // Jeśli nie przesłano cron_expression, spróbuj użyć tej z ostatniego pinga
                if ($cronSchedule) {
                    $config->setCronExpression($cronSchedule);

                    // Oblicz expectedInterval na podstawie wyrażenia cron z pinga
                    $expectedInterval = CronIntervalCalculator::calculateExpectedInterval($cronSchedule);
                    $config->setExpectedInterval($expectedInterval);
                } else {
                    // Jeśli nie ma cron_schedule w pingu, użyj wartości z formularza
                    $expectedInterval = (int)$request->request->get('expected_interval', MonitorConfig::DEFAULT_EXPECTED_INTERVAL);
                    $config->setExpectedInterval($expectedInterval);
                }
            }

            // Ustaw alert_threshold z formularza
            $alertThreshold = (int)$request->request->get('alert_threshold', MonitorConfig::DEFAULT_ALERT_THRESHOLD);
            $config->setAlertThreshold($alertThreshold);

            // Ustaw nowe pola z formularza
            $maxDuration = (int)$request->request->get('max_duration', MonitorConfig::DEFAULT_MAX_DURATION);
            $config->setMaxDuration($maxDuration);

            $failureTolerance = (int)$request->request->get('failure_tolerance', MonitorConfig::DEFAULT_FAILURE_TOLERANCE);
            $config->setFailureTolerance($failureTolerance);

            $gracePeriod = (int)$request->request->get('grace_period', MonitorConfig::DEFAULT_GRACE_PERIOD);
            $config->setGracePeriod($gracePeriod);

            $this->monitorConfigRepository->save($config);

            // Update monitor project
            $monitor->setProjectName($request->request->get('project_name'));
            $this->monitorRepository->save($monitor);

            // Redirect to monitor view
            return new RedirectResponse($this->app->generateUrl('monitor_show', ['id' => $monitor->getId()]));
        }

        // Jeśli mamy cron_schedule, ale nie mamy cron_expression, ustaw domyślnie
        if ($cronSchedule && empty($config->getCronExpression())) {
            $config->setCronExpression($cronSchedule);
        }

        // Użyj MonitorSchedulerService do pobrania czytelnego interwału
        $readableInterval = $this->schedulerService->getReadableSchedule($monitor);

        return new Response($this->twig->render('config/monitor_config.html.twig', [
            'monitor' => $monitor,
            'config' => $config,
            'lastPing' => $lastPing,
            'cronSchedule' => $cronSchedule,
            'readableInterval' => $readableInterval
        ]));
    }

    public function notificationChannels(Request $request): Response
    {
        // Get all notification channels
        $channels = $this->notificationChannelRepository->findAll();

        if (empty($channels)) {
            // Skip decoding if no channels exist
            return new Response($this->twig->render('config/notification_channels.html.twig', [
                'channels' => []
            ]));
        }

        // Decode JSON config for each channel
        foreach ($channels as &$channel) {
            if (is_string($channel->getConfig())) {
                $channel->config_decoded = json_decode($channel->getConfig(), true);
            } else {
                $channel->config_decoded = [];
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
                case 'file':
                    $config['filename'] = $request->request->get('filename', './data/log-adapter.log');
                    break;
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

            // Create and save new channel
            $channel = new NotificationChannel();
            $channel->setName($name);
            $channel->setType($type);
            $channel->setConfig(json_encode($config));

            $this->notificationChannelRepository->save($channel);

            return new RedirectResponse($this->app->generateUrl('notification_channels'));
        }

        return new Response($this->twig->render('config/add_channel.html.twig'));
    }

    public function editChannel(Request $request, int $id): Response
    {
        // Get channel
        $channel = $this->notificationChannelRepository->findById($id);

        if (!$channel) {
            return new Response('Channel not found', 404);
        }

        $config = [];
        if ($channel->getConfig()) {
            $config = json_decode($channel->getConfig(), true);
        }

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $type = $channel->getType(); // Don't change type of existing channel
            $newConfig = [];

            // Update config based on channel type
            switch ($type) {
                case 'file':
                    $newConfig['filename'] = $request->request->get('filename', './data/log-adapter.log');
                    break;
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
            $channel->setName($name);
            $channel->setConfig(json_encode($newConfig));
            $this->notificationChannelRepository->save($channel);

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
        $channels = $this->notificationChannelRepository->findAll();

        // Get channels associated with monitor
        $monitorChannels = $this->notificationChannelRepository->findChannelsForMonitor($monitor->getId());

        if ($request->isMethod('POST')) {
            // Directly use $_POST
            $selectedChannels = isset($_POST['channels']) ? (array)$_POST['channels'] : [];
            $notifyOnFail = isset($_POST['notify_on_fail']) ? (array)$_POST['notify_on_fail'] : [];
            $notifyOnOverdue = isset($_POST['notify_on_overdue']) ? (array)$_POST['notify_on_overdue'] : [];
            $notifyOnResolve = isset($_POST['notify_on_resolve']) ? (array)$_POST['notify_on_resolve'] : [];

            // Remove all existing associations
            $this->notificationChannelRepository->removeAllMonitorNotifications($monitor->getId());

            // Add new associations
            foreach ($selectedChannels as $channelId) {
                $this->notificationChannelRepository->addMonitorNotification(
                    $monitor->getId(),
                    (int)$channelId,
                    in_array($channelId, $notifyOnFail),
                    in_array($channelId, $notifyOnOverdue),
                    in_array($channelId, $notifyOnResolve)
                );
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