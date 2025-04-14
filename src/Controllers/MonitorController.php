<?php

namespace App\Controllers;

use App\Application;
use App\Enum\PingState;
use App\Repository\MonitorConfigRepositoryInterface;
use App\Repository\MonitorGroupRepositoryInterface;
use App\Repository\MonitorRepositoryInterface;
use App\Repository\PingRepositoryInterface;
use App\Services\MonitorSchedulerService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class MonitorController
{
    private Environment $twig;
    private Application $app;
    private $container;
    private MonitorRepositoryInterface $monitorRepository;
    private PingRepositoryInterface $pingRepository;
    private MonitorGroupRepositoryInterface $groupRepository;
    private MonitorConfigRepositoryInterface $monitorConfigRepository;
    private MonitorSchedulerService $schedulerService;

    public function __construct(Environment $twig, Application $app, $container)
    {
        $this->twig = $twig;
        $this->app = $app;
        $this->container = $container;
        $this->monitorRepository = $container->get(MonitorRepositoryInterface::class);
        $this->pingRepository = $container->get(PingRepositoryInterface::class);
        $this->groupRepository = $container->get(MonitorGroupRepositoryInterface::class);
        $this->monitorConfigRepository = $container->get(MonitorConfigRepositoryInterface::class);
        $this->schedulerService = $container->get(MonitorSchedulerService::class);
    }


    public function edit(Request $request, int $id): Response {
        $monitor = $this->monitorRepository->findById($id);
        if (!$monitor) {
            return new Response('Monitor not found', 404);
        }

        // Get all available groups for dropdown
        $groups = $this->groupRepository->findAll();

        // Get configuration
        $config = $this->monitorConfigRepository->findByMonitor($monitor);

        if ($request->isMethod('POST')) {
            $monitor->setName($request->request->get('name'));
            $monitor->setProjectName($request->request->get('project_name'));

            // Handle cron expression
            $cronExpression = $request->request->get('cron_expression');
            if ($config && !empty($cronExpression)) {
                $config->setCronExpression($cronExpression);
                $this->monitorConfigRepository->save($config);
            }

            // Handle group assignment
            $groupId = $request->request->get('group_id');
            if (!empty($groupId)) {
                $group = $this->groupRepository->findById((int)$groupId);
                $monitor->setGroup($group);
            } else {
                $monitor->setGroup(null);
            }

            $this->monitorRepository->save($monitor);
            return new RedirectResponse($this->app->generateUrl('monitor_show', ['id' => $monitor->getId()]));
        }

        return new Response($this->twig->render('monitor/edit.html.twig', [
            'monitor' => $monitor,
            'groups' => $groups,
            'config' => $config
        ]));
    }

    public function show(Request $request, int $id): Response
    {
        $monitor = $this->monitorRepository->findById($id);

        if (!$monitor) {
            return new Response('Monitor not found', 404);
        }

        $days = $request->query->getInt('days', 7);
        $stats = $this->pingRepository->getMonitorStats($monitor->getId(), $days);

        $completedPings = $this->pingRepository->findRecentByMonitor($monitor->getId(), 50, PingState::COMPLETE->value);

        // Użyj MonitorSchedulerService do pobrania informacji o harmonogramie
        $cronSchedule = $this->schedulerService->getCronExpression($monitor);
        $readableInterval = $this->schedulerService->getReadableSchedule($monitor);
        $expectedNextRun = $this->schedulerService->getExpectedNextRun($monitor);

        // Pobierz źródło uruchomienia z ostatniego pinga
        $lastPing = $monitor->getLastPing();
        $runSource = $lastPing ? $lastPing->getRunSource() : null;

        $executionTimes = [];
        foreach ($stats['time_data'] as $timePoint) {
            $executionTimes[] = [
                'hour' => $timePoint['hour'],
                'duration' => $timePoint['avg_duration'],
                'count' => $timePoint['count']
            ];
        }

        $events = [];
        foreach ($completedPings as $ping) {
            $date = date('m/d', $ping->getTimestamp());
            if (!isset($events[$date])) {
                $events[$date] = ['date' => $date, 'executions' => []];
            }

            $events[$date]['executions'][] = [
                'time' => date('H:i', $ping->getTimestamp()),
                'duration' => $ping->getDuration(),
                'host' => $ping->getHost()
            ];
        }

        return new Response($this->twig->render('monitor/show.html.twig', [
            'monitor' => $monitor,
            'stats' => $stats,
            'executionTimes' => $executionTimes,
            'events' => array_values($events),
            'days' => $days,
            'completedPings' => $completedPings,
            'cronSchedule' => $cronSchedule,
            'readableInterval' => $readableInterval,
            'expectedNextRun' => $expectedNextRun,
            'runSource' => $runSource
        ]));
    }

    public function latestActivity(Request $request, int $id): Response
    {
        $monitor = $this->monitorRepository->findById($id);

        if (!$monitor) {
            return new Response('Monitor not found', 404);
        }

        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 10);
        $offset = ($page - 1) * $limit;

        // Pobierz całkowitą liczbę pingów
        $totalPings = $this->pingRepository->countByMonitor($id);

        // Pobierz tylko pingi dla bieżącej strony
        $pings = $this->pingRepository->findRecentByMonitorWithPagination($monitor->getId(), $limit, $offset);

        return new Response($this->twig->render('monitor/_latest_activity.html.twig', [
            'monitor' => $monitor,
            'pings' => $pings,
            'page' => $page,
            'limit' => $limit,
            'total' => $totalPings,
            'maxPage' => ceil($totalPings / $limit)
        ]));
    }

    public function latestIssues(Request $request, int $id): Response
    {
        $monitor = $this->monitorRepository->findById($id);

        if (!$monitor) {
            return new Response('Monitor not found', 404);
        }

        $limit = $request->query->getInt('limit', 10);
        $pings = $this->pingRepository->findRecentByMonitor($monitor->getId(), $limit, PingState::FAIL->value);

        return new Response($this->twig->render('monitor/_latest_issues.html.twig', [
            'monitor' => $monitor,
            'pings' => $pings
        ]));
    }
}