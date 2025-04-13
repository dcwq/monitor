<?php

namespace App\Controllers;

use App\Application;
use App\Repository\MonitorRepositoryInterface;
use App\Repository\TagRepositoryInterface;
use App\Services\ApiLogParser;
use App\Services\LogParser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class DashboardController
{
    private Environment $twig;
    private Application $app;
    private $container;
    private MonitorRepositoryInterface $monitorRepository;
    private TagRepositoryInterface $tagRepository;

    public function __construct(Environment $twig, Application $app, $container)
    {
        $this->twig = $twig;
        $this->app = $app;
        $this->container = $container;
        $this->monitorRepository = $container->get(MonitorRepositoryInterface::class);
        $this->tagRepository = $container->get(TagRepositoryInterface::class);
    }

    public function index(Request $request): Response {
        $selectedTag = $request->query->get('tag');
        $selectedProject = $request->query->get('project');

        $monitors = $this->monitorRepository->findAll();
        $tags = $this->tagRepository->findAll();
        $projectNames = $this->monitorRepository->getAllProjectNames();

        $monitorStats = [];
        foreach ($monitors as $monitor) {
            $lastPing = $monitor->getLastPing();
            $recentPings = $monitor->getPings();
            $monitorTags = $monitor->getTags();

            // Filter by tag, if provided
            if ($selectedTag && !$this->monitorHasTag($monitorTags, $selectedTag)) {
                continue;
            }

            // Filter by project, if provided
            if ($selectedProject && $monitor->getProjectName() !== $selectedProject) {
                continue;
            }

            $completedPings = $recentPings->filter(function ($ping) {
                return $ping->getState() === 'complete';
            });
            $failedPings = $recentPings->filter(function ($ping) {
                return $ping->getState() === 'fail';
            });
            $healthyCount = count($completedPings);
            $failingCount = count($failedPings);
            $totalCount = count($recentPings);

            $monitorStats[$monitor->getId()] = [
                'monitor' => $monitor,
                'lastPing' => $lastPing,
                'healthyCount' => $healthyCount,
                'failingCount' => $failingCount,
                'healthPercentage' => $totalCount > 0 ? ($healthyCount / $totalCount) * 100 : 0,
                'tags' => $monitorTags,
                'expectedNextRun' => $this->calculateExpectedNextRun($recentPings->toArray())
            ];
        }

        return new Response($this->twig->render('dashboard/index.html.twig', [
            'monitors' => $monitorStats,
            'tags' => $tags,
            'selectedTag' => $selectedTag,
            'projectNames' => $projectNames,
            'selectedProject' => $selectedProject,
            'totalMonitors' => count($monitors)
        ]));
    }


    public function sync(Request $request): Response
    {
        $historyParser = $this->container->get(LogParser::class);
        $apiParser = $this->container->get(ApiLogParser::class);

        $historyImportCount = $historyParser->parse(true);
        $apiImportCount = $apiParser->parse(true);
        $totalImportCount = $historyImportCount + $apiImportCount;

        return new Response($this->twig->render('dashboard/sync.html.twig', [
            'historyImportCount' => $historyImportCount,
            'apiImportCount' => $apiImportCount,
            'totalImportCount' => $totalImportCount
        ]));
    }

    private function monitorHasTag(iterable $monitorTags, string $tagName): bool
    {
        foreach ($monitorTags as $tag) {
            if ($tag->getName() === $tagName) {
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
            return $a->getTimestamp() - $b->getTimestamp();
        });

        foreach ($pings as $ping) {
            if ($ping->getState() === 'run' && $lastTimestamp !== null) {
                $interval = $ping->getTimestamp() - $lastTimestamp;

                if ($interval > 0) {
                    $intervals[] = $interval;
                }
            }

            if ($ping->getState() === 'run') {
                $lastTimestamp = $ping->getTimestamp();
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

        $lastRun = end($pings)->getTimestamp();
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