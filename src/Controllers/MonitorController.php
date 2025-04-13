<?php

namespace App\Controllers;

use App\Application;
use App\Enum\PingState;
use App\Repository\MonitorGroupRepositoryInterface;
use App\Repository\MonitorRepositoryInterface;
use App\Repository\PingRepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class MonitorController
{
    private $container;
    private Environment $twig;
    private Application $app;
    private MonitorRepositoryInterface $monitorRepository;
    private PingRepositoryInterface $pingRepository;
    private MonitorGroupRepositoryInterface $groupRepository;

    public function __construct(Environment $twig, Application $app, $container)
    {
        $this->twig = $twig;
        $this->app = $app;
        $this->container = $container;
        $this->monitorRepository = $container->get(MonitorRepositoryInterface::class);
        $this->pingRepository = $container->get(PingRepositoryInterface::class);
        $this->groupRepository = $container->get(MonitorGroupRepositoryInterface::class);
    }


    public function edit(Request $request, int $id): Response {
        $monitor = $this->monitorRepository->findById($id);
        if (!$monitor) {
            return new Response('Monitor not found', 404);
        }

        // Get all available groups for dropdown
        $groups = $this->groupRepository->findAll();

        if ($request->isMethod('POST')) {
            $monitor->setName($request->request->get('name'));
            $monitor->setProjectName($request->request->get('project_name'));

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
            'groups' => $groups
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
            'completedPings' => $completedPings
        ]));
    }

    public function latestActivity(Request $request, int $id): Response
    {
        $monitor = $this->monitorRepository->findById($id);

        if (!$monitor) {
            return new Response('Monitor not found', 404);
        }

        $limit = $request->query->getInt('limit', 10);
        $pings = $this->pingRepository->findRecentByMonitor($monitor->getId(), $limit);

        return new Response($this->twig->render('monitor/_latest_activity.html.twig', [
            'monitor' => $monitor,
            'pings' => $pings
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