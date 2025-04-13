<?php

namespace App\Entity;

use App\Repository\DoctrineNotificationHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DoctrineNotificationHistoryRepository::class)]
#[ORM\Table(name: 'notification_history')]
class NotificationHistory
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

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $event_type;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTime $created_at;

    public function __construct()
    {
        $this->created_at = new \DateTime();
    }

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

    public function getEventType(): string
    {
        return $this->event_type;
    }

    public function setEventType(string $event_type): self
    {
        $this->event_type = $event_type;
        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->created_at;
    }

    public function setCreatedAt(\DateTime $created_at): self
    {
        $this->created_at = $created_at;
        return $this;
    }
}