<?php

namespace App\Entity;

use App\Repository\DoctrineMonitorOverdueHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use DateTime;

#[ORM\Entity(repositoryClass: DoctrineMonitorOverdueHistoryRepository::class)]
#[ORM\Table(name: 'monitor_overdue_history')]
class MonitorOverdueHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Monitor::class, inversedBy: 'overdueHistory')]
    #[ORM\JoinColumn(name: 'monitor_id', referencedColumnName: 'id', nullable: false)]
    private Monitor $monitor;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private DateTime $started_at;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTime $resolved_at = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $duration = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $is_resolved = false;

    public function __construct(Monitor $monitor, DateTime $started_at)
    {
        $this->monitor = $monitor;
        $this->started_at = $started_at;
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

    public function getStartedAt(): DateTime
    {
        return $this->started_at;
    }

    public function setStartedAt(DateTime $started_at): self
    {
        $this->started_at = $started_at;
        return $this;
    }

    public function getResolvedAt(): ?DateTime
    {
        return $this->resolved_at;
    }

    public function setResolvedAt(?DateTime $resolved_at): self
    {
        $this->resolved_at = $resolved_at;
        return $this;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function setDuration(?int $duration): self
    {
        $this->duration = $duration;
        return $this;
    }

    public function isResolved(): bool
    {
        return $this->is_resolved;
    }

    public function setIsResolved(bool $is_resolved): self
    {
        $this->is_resolved = $is_resolved;
        return $this;
    }

    /**
     * Resolve an overdue event
     */
    public function resolve(): bool
    {
        if ($this->is_resolved) {
            return false;
        }

        $now = new DateTime();
        $this->resolved_at = $now;
        $this->is_resolved = true;

        // Calculate duration in seconds
        $this->duration = $now->getTimestamp() - $this->started_at->getTimestamp();

        return true;
    }
}