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
    public const DEFAULT_MAX_DURATION = 5;         // 5 seconds
    public const DEFAULT_FAILURE_TOLERANCE = 0;    // 0 failures (no tolerance)
    public const DEFAULT_GRACE_PERIOD = 60;        // 1 minute (60 seconds)

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

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $cron_expression = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $max_duration = self::DEFAULT_MAX_DURATION;

    #[ORM\Column(type: Types::INTEGER)]
    private int $failure_tolerance = self::DEFAULT_FAILURE_TOLERANCE;

    #[ORM\Column(type: Types::INTEGER)]
    private int $grace_period = self::DEFAULT_GRACE_PERIOD;

    public function getCronExpression(): ?string
    {
        return $this->cron_expression;
    }

    public function setCronExpression(?string $cron_expression): self
    {
        $this->cron_expression = $cron_expression;
        return $this;
    }

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

    public function getMaxDuration(): int
    {
        return $this->max_duration;
    }

    public function setMaxDuration(int $max_duration): self
    {
        $this->max_duration = $max_duration;
        return $this;
    }

    public function getFailureTolerance(): int
    {
        return $this->failure_tolerance;
    }

    public function setFailureTolerance(int $failure_tolerance): self
    {
        $this->failure_tolerance = $failure_tolerance;
        return $this;
    }

    public function getGracePeriod(): int
    {
        return $this->grace_period;
    }

    public function setGracePeriod(int $grace_period): self
    {
        $this->grace_period = $grace_period;
        return $this;
    }
}