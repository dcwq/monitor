<?php

namespace App\Entity;

use App\Repository\DoctrineMonitorConfigRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DoctrineMonitorConfigRepository::class)]
#[ORM\Table(name: 'monitor_configs')]
class MonitorConfig
{
    public const DEFAULT_EXPECTED_INTERVAL = 3600; // 1 hour in seconds
    public const DEFAULT_ALERT_THRESHOLD = 0;      // 0 seconds (immediate)

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'config', targetEntity: Monitor::class)]
    #[ORM\JoinColumn(name: 'monitor_id', referencedColumnName: 'id', nullable: false, unique: true)]
    private Monitor $monitor;

    #[ORM\Column(type: Types::INTEGER)]
    private int $expected_interval;

    #[ORM\Column(type: Types::INTEGER)]
    private int $alert_threshold;

    public function __construct(
        Monitor $monitor,
        int $expected_interval = self::DEFAULT_EXPECTED_INTERVAL,
        int $alert_threshold = self::DEFAULT_ALERT_THRESHOLD
    ) {
        $this->monitor = $monitor;
        $this->expected_interval = $expected_interval;
        $this->alert_threshold = $alert_threshold;
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

    public function getExpectedInterval(): int
    {
        return $this->expected_interval;
    }

    public function setExpectedInterval(int $expected_interval): self
    {
        $this->expected_interval = $expected_interval;
        return $this;
    }

    public function getAlertThreshold(): int
    {
        return $this->alert_threshold;
    }

    public function setAlertThreshold(int $alert_threshold): self
    {
        $this->alert_threshold = $alert_threshold;
        return $this;
    }
}