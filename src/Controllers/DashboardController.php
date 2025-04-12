<?php

namespace App\Controllers;

use App\Models\Tag;
use App\Services\ApiLogParser;
use App\Services\LogParser;
use App\Models\Monitor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class DashboardController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function index(Request $request): Response
    {
        $selectedTag = $request->query->get('tag');

        $monitors = Monitor::findAll();
        $tags = Tag::findAll();

        $monitorStats = [];

        foreach ($monitors as $monitor) {
            $lastPing = $monitor->getLastPing();
            $recentPings = $monitor->getRecentPings(10);
            $monitorTags = $monitor->getTags();

            if ($selectedTag && !$this->monitorHasTag($monitorTags, $selectedTag)) {
                continue;
            }

            $lastCompletedPings = array_filter($recentPings, static function ($ping) {
                return $ping->state === 'complete';
            });

            $lastFailedPings = array_filter($recentPings, static function ($ping) {
                return $ping->state === 'fail';
            });

            $healthyCount = count($lastCompletedPings);
            $failingCount = count($lastFailedPings);
            $totalCount = count($recentPings);

            $monitorStats[$monitor->id] = [
                'monitor' => $monitor,
                'lastPing' => $lastPing,
                'healthyCount' => $healthyCount,
                'failingCount' => $failingCount,
                'healthPercentage' => $totalCount > 0 ? ($healthyCount / $totalCount) * 100 : 0,
                'tags' => $monitorTags,
                'expectedNextRun' => $this->calculateExpectedNextRun($recentPings)
            ];
        }

        return new Response($this->twig->render('dashboard/index.html.twig', [
            'monitors' => $monitorStats,
            'tags' => $tags,
            'selectedTag' => $selectedTag,
            'totalMonitors' => count($monitors)
        ]));
    }

    public function sync(Request $request): Response
    {
        $historyParser = new LogParser();
        $apiParser = new ApiLogParser();

        $historyImportCount = $historyParser->parse(true);
        $apiImportCount = $apiParser->parse(true);
        $totalImportCount = $historyImportCount + $apiImportCount;

        return new Response($this->twig->render('dashboard/sync.html.twig', [
            'historyImportCount' => $historyImportCount,
            'apiImportCount' => $apiImportCount,
            'totalImportCount' => $totalImportCount
        ]));
    }

    private function monitorHasTag(array $monitorTags, string $tagName): bool
    {
        foreach ($monitorTags as $tag) {
            if ($tag->name === $tagName) {
                return true;
            }
        }

        return false;
    }

    private function calculateExpectedNextRun(array $pings): ?string
    {
        if (empty($pings)) {
            return null;
        }

        $intervals = [];
        $lastTimestamp = null;

        // Sort pings by timestamp ascending
        usort($pings, function ($a, $b) {
            return $a->timestamp - $b->timestamp;
        });

        foreach ($pings as $ping) {
            if ($ping->state === 'run' && $lastTimestamp !== null) {
                $interval = $ping->timestamp - $lastTimestamp;

                if ($interval > 0) {
                    $intervals[] = $interval;
                }
            }

            if ($ping->state === 'run') {
                $lastTimestamp = $ping->timestamp;
            }
        }

        if (empty($intervals)) {
            return null;
        }

        // Calculate median interval
        sort($intervals);
        $count = count($intervals);
        $middle = floor($count / 2);

        $medianInterval = ($count % 2 === 0)
            ? ($intervals[$middle - 1] + $intervals[$middle]) / 2
            : $intervals[$middle];

        $lastRun = end($pings)->timestamp;
        $expectedNext = $lastRun + $medianInterval;

        if ($expectedNext < time()) {
            return 'Overdue';
        }

        $minutesRemaining = ceil(($expectedNext - time()) / 60);

        if ($minutesRemaining < 60) {
            return "In about {$minutesRemaining} minute" . ($minutesRemaining === 1 ? '' : 's');
        }

        $hoursRemaining = ceil($minutesRemaining / 60);
        return "In about {$hoursRemaining} hour" . ($hoursRemaining === 1 ? '' : 's');
    }
}
