<?php

namespace App\Entity;

use App\Repository\DoctrineMonitorNotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DoctrineMonitorNotificationRepository::class)]
#[ORM\Table(name: 'monitor_notifications')]
class MonitorNotification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(name: 'monitor_id', referencedColumnName: 'id', nullable: false)]
    private Monitor $monitor;

    #[ORM\ManyToOne(targetEntity: NotificationChannel::class)]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'id', nullable: false)]
    private NotificationChannel $channel;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $notify_on_fail = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $notify_on_overdue = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $notify_on_resolve = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMonitor(): Monitor
    {
        return $this->monitor;
    }

    public function setMonitor(Monitor $monitor): self
    {
        $this->monitor = $monitor;
        return $this;
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function setChannel(NotificationChannel $channel): self
    {
        $this->channel = $channel;
        return $this;
    }

    public function isNotifyOnFail(): bool
    {
        return $this->notify_on_fail;
    }

    public function setNotifyOnFail(bool $notify_on_fail): self
    {
        $this->notify_on_fail = $notify_on_fail;
        return $this;
    }

    public function isNotifyOnOverdue(): bool
    {
        return $this->notify_on_overdue;
    }

    public function setNotifyOnOverdue(bool $notify_on_overdue): self
    {
        $this->notify_on_overdue = $notify_on_overdue;
        return $this;
    }

    public function isNotifyOnResolve(): bool
    {
        return $this->notify_on_resolve;
    }

    public function setNotifyOnResolve(bool $notify_on_resolve): self
    {
        $this->notify_on_resolve = $notify_on_resolve;
        return $this;
    }
}