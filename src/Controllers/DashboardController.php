<?php

namespace App\Controllers;

use App\Application;
use App\Entity\Monitor;
use App\Repository\MonitorConfigRepositoryInterface;
use App\Repository\MonitorGroupRepositoryInterface;
use App\Repository\MonitorRepositoryInterface;
use App\Repository\PingRepositoryInterface;
use App\Repository\TagRepositoryInterface;
use App\Services\ApiLogParser;
use App\Services\CronIntervalCalculator;
use App\Services\LogParser;
use App\Services\MonitorSchedulerService;
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
    private MonitorGroupRepositoryInterface $groupRepository;
    private MonitorConfigRepositoryInterface $monitorConfigRepository;
    private PingRepositoryInterface $pingRepository;
    private MonitorSchedulerService $schedulerService;

    public function __construct(Environment $twig, Application $app, $container)
    {
        $this->twig = $twig;
        $this->app = $app;
        $this->container = $container;
        $this->monitorRepository = $container->get(MonitorRepositoryInterface::class);
        $this->tagRepository = $container->get(TagRepositoryInterface::class);
        $this->groupRepository = $container->get(MonitorGroupRepositoryInterface::class);
        $this->monitorConfigRepository = $container->get(MonitorConfigRepositoryInterface::class);
        $this->pingRepository = $container->get(PingRepositoryInterface::class);
        $this->schedulerService = $container->get(MonitorSchedulerService::class);
    }

    public function index(Request $request): Response {
        $selectedTag = $request->query->get('tag');
        $selectedProject = $request->query->get('project');
        $selectedGroup = $request->query->get('group');

        $monitors = $this->monitorRepository->findAll();
        $tags = $this->tagRepository->findAll();
        $projectNames = $this->monitorRepository->getAllProjectNames();
        $groups = $this->groupRepository->findAll();

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

            // Filter by group, if provided
            if ($selectedGroup && (!$monitor->getGroup() || $monitor->getGroup()->getId() != $selectedGroup)) {
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
                'expectedNextRun' => $this->schedulerService->getExpectedNextRun($monitor),
                'readableSchedule' => $this->schedulerService->getReadableSchedule($monitor),
                'lastIssue' => $this->getLastIssueInfo($monitor)
            ];
        }

        return new Response($this->twig->render('dashboard/index.html.twig', [
            'monitors' => $monitorStats,
            'tags' => $tags,
            'selectedTag' => $selectedTag,
            'projectNames' => $projectNames,
            'selectedProject' => $selectedProject,
            'groups' => $groups,
            'selectedGroup' => $selectedGroup,
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

    private function getLastIssueInfo(Monitor $monitor): ?string {
        $failedPings = $this->pingRepository->findRecentByMonitor($monitor->getId(), 1, 'fail');

        if (empty($failedPings)) {
            return null;
        }

        $lastFailedPing = $failedPings[0];
        $diff = time() - $lastFailedPing->getTimestamp();

        if ($diff < 60) {
            return "Just now";
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return "$minutes minute" . ($minutes === 1 ? '' : 's') . " ago";
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return "$hours hour" . ($hours === 1 ? '' : 's') . " ago";
        } else {
            $days = floor($diff / 86400);
            return "$days day" . ($days === 1 ? '' : 's') . " ago";
        }
    }
}