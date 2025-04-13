<?php

namespace App;

use App\Models\Monitor;
use App\Models\Ping;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MonitorController
{
    private \Twig\Environment $twig;
    
    public function __construct(\Twig\Environment $twig)
    {
        $this->twig = $twig;
    }

    public function edit(Request $request, int $id): Response {
        $monitor = Monitor::findById($id);
        if (!$monitor) {
            return new Response('Monitor not found', 404);
        }

        if ($request->isMethod('POST')) {
            $monitor->name = $request->request->get('name');
            $monitor->project_name = $request->request->get('project_name');

            if ($monitor->save()) {
                return new RedirectResponse($this->generateUrl('monitor_show', ['id' => $monitor->id]));
            }
        }

        return new Response($this->twig->render('monitor/edit.html.twig', [
            'monitor' => $monitor
        ]));
    }
    
    public function show(Request $request, int $id): Response
    {
        $monitor = Monitor::findById($id);
        
        if (!$monitor) {
            return new Response('Monitor not found', 404);
        }
        
        $days = $request->query->getInt('days', 7);
        $stats = $monitor->getStats($days);
        
        $completedPings = Ping::findRecentByMonitor($monitor->id, 50, 'complete');
        
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
            $date = date('m/d', $ping->timestamp);
            if (!isset($events[$date])) {
                $events[$date] = ['date' => $date, 'executions' => []];
            }
            
            $events[$date]['executions'][] = [
                'time' => date('H:i', $ping->timestamp),
                'duration' => $ping->duration,
                'host' => $ping->host
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
        $monitor = Monitor::findById($id);
        
        if (!$monitor) {
            return new Response('Monitor not found', 404);
        }
        
        $limit = $request->query->getInt('limit', 10);
        $pings = Ping::findRecentByMonitor($monitor->id, $limit);
        
        return new Response($this->twig->render('monitor/_latest_activity.html.twig', [
            'monitor' => $monitor,
            'pings' => $pings
        ]));
    }
    
    public function latestIssues(Request $request, int $id): Response
    {
        $monitor = Monitor::findById($id);
        
        if (!$monitor) {
            return new Response('Monitor not found', 404);
        }
        
        $limit = $request->query->getInt('limit', 10);
        $pings = Ping::findRecentByMonitor($monitor->id, $limit, 'fail');
        
        return new Response($this->twig->render('monitor/_latest_issues.html.twig', [
            'monitor' => $monitor,
            'pings' => $pings
        ]));
    }
}