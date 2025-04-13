<?php

namespace App\Controllers;

use App\Application;
use App\Entity\MonitorGroup;
use App\Repository\GroupNotificationRepositoryInterface;
use App\Repository\MonitorGroupRepositoryInterface;
use App\Repository\MonitorRepositoryInterface;
use App\Repository\NotificationChannelRepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class GroupController
{
    private Environment $twig;
    private Application $app;
    private $container;
    private MonitorGroupRepositoryInterface $groupRepository;
    private MonitorRepositoryInterface $monitorRepository;
    private NotificationChannelRepositoryInterface $notificationChannelRepository;
    private GroupNotificationRepositoryInterface $groupNotificationRepository;

    public function __construct(
        Environment $twig,
        Application $app,
                    $container
    ) {
        $this->twig = $twig;
        $this->app = $app;
        $this->container = $container;
        $this->groupRepository = $container->get(MonitorGroupRepositoryInterface::class);
        $this->monitorRepository = $container->get(MonitorRepositoryInterface::class);
        $this->notificationChannelRepository = $container->get(NotificationChannelRepositoryInterface::class);
        $this->groupNotificationRepository = $container->get(GroupNotificationRepositoryInterface::class);
    }

    public function index(Request $request): Response
    {
        $groups = $this->groupRepository->findAll();

        return new Response($this->twig->render('group/index.html.twig', [
            'groups' => $groups
        ]));
    }

    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $description = $request->request->get('description');

            $group = new MonitorGroup($name, $description);
            $this->groupRepository->save($group);

            return new RedirectResponse($this->app->generateUrl('groups'));
        }

        return new Response($this->twig->render('group/create.html.twig'));
    }

    public function edit(Request $request, int $id): Response
    {
        $group = $this->groupRepository->findById($id);

        if (!$group) {
            return new Response('Group not found', 404);
        }

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $description = $request->request->get('description');

            $group->setName($name);
            $group->setDescription($description);
            $this->groupRepository->save($group);

            return new RedirectResponse($this->app->generateUrl('groups'));
        }

        return new Response($this->twig->render('group/edit.html.twig', [
            'group' => $group
        ]));
    }

    public function delete(Request $request, int $id): Response
    {
        $group = $this->groupRepository->findById($id);

        if (!$group) {
            return new Response('Group not found', 404);
        }

        // Check if there are any monitors in this group
        if ($group->getMonitors()->count() > 0) {
            // Set group to null for all monitors in this group
            foreach ($group->getMonitors() as $monitor) {
                $monitor->setGroup(null);
                $this->monitorRepository->save($monitor);
            }
        }

        $this->groupRepository->remove($group);

        return new RedirectResponse($this->app->generateUrl('groups'));
    }

    public function show(Request $request, int $id): Response
    {
        $group = $this->groupRepository->findById($id);

        if (!$group) {
            return new Response('Group not found', 404);
        }

        $monitors = $group->getMonitors();

        return new Response($this->twig->render('group/show.html.twig', [
            'group' => $group,
            'monitors' => $monitors
        ]));
    }

    public function manageNotifications(Request $request, int $id): Response
    {
        $group = $this->groupRepository->findById($id);

        if (!$group) {
            return new Response('Group not found', 404);
        }

        // Get all channels
        $channels = $this->notificationChannelRepository->findAll();

        // Get notifications for this group
        $groupNotifications = $this->groupNotificationRepository->findByGroupId($group->getId());

        // Format for template
        $groupChannels = [];
        foreach ($groupNotifications as $notification) {
            $channelId = $notification->getChannel()->getId();
            $groupChannels[$channelId] = [
                'notify_on_fail' => $notification->isNotifyOnFail(),
                'notify_on_overdue' => $notification->isNotifyOnOverdue(),
                'notify_on_resolve' => $notification->isNotifyOnResolve()
            ];
        }

        if ($request->isMethod('POST')) {
            // Directly use $_POST
            $selectedChannels = isset($_POST['channels']) ? (array)$_POST['channels'] : [];
            $notifyOnFail = isset($_POST['notify_on_fail']) ? (array)$_POST['notify_on_fail'] : [];
            $notifyOnOverdue = isset($_POST['notify_on_overdue']) ? (array)$_POST['notify_on_overdue'] : [];
            $notifyOnResolve = isset($_POST['notify_on_resolve']) ? (array)$_POST['notify_on_resolve'] : [];

            // Remove all existing associations
            $this->groupNotificationRepository->removeAllForGroup($group->getId());

            // Add new associations
            foreach ($selectedChannels as $channelId) {
                $channel = $this->notificationChannelRepository->findById((int)$channelId);
                if ($channel) {
                    $notification = new GroupNotification();
                    $notification->setGroup($group);
                    $notification->setChannel($channel);
                    $notification->setNotifyOnFail(in_array($channelId, $notifyOnFail));
                    $notification->setNotifyOnOverdue(in_array($channelId, $notifyOnOverdue));
                    $notification->setNotifyOnResolve(in_array($channelId, $notifyOnResolve));

                    $this->groupNotificationRepository->save($notification);
                }
            }

            return new RedirectResponse($this->app->generateUrl('group_show', ['id' => $group->getId()]));
        }

        return new Response($this->twig->render('group/notifications.html.twig', [
            'group' => $group,
            'channels' => $channels,
            'groupChannels' => $groupChannels
        ]));
    }
}