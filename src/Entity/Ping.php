<?php

namespace App\Entity;

use App\Enum\PingState;
use App\Repository\DoctrinePingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DoctrinePingRepository::class)]
#[ORM\Table(name: 'pings')]
#[ORM\HasLifecycleCallbacks]
class Ping
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Monitor::class, inversedBy: 'pings')]
    #[ORM\JoinColumn(name: 'monitor_id', referencedColumnName: 'id', nullable: false)]
    private Monitor $monitor;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $unique_id;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $state;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $duration = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $exit_code = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $host = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $timestamp;

    #[ORM\Column(type: Types::INTEGER)]
    private int $received_at;

    #[ORM\Column(type: Types::STRING, length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'pings')]
    #[ORM\JoinTable(name: 'ping_tags')]
    #[ORM\JoinColumn(name: 'ping_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id')]
    private Collection $tags;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
        $this->unique_id = substr(md5(rand()), 0, 8);
        $this->state = PingState::RUN->value;
        $this->timestamp = time();
        $this->received_at = time();
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

    public function getUniqueId(): string
    {
        return $this->unique_id;
    }

    public function setUniqueId(string $unique_id): self
    {
        $this->unique_id = $unique_id;
        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getPingState(): PingState
    {
        return PingState::fromString($this->state);
    }

    public function setState(string|PingState $state): self
    {
        if ($state instanceof PingState) {
            $this->state = $state->value;
        } else {
            $this->state = $state;
        }
        return $this;
    }

    public function getDuration(): ?float
    {
        return $this->duration;
    }

    public function setDuration(?float $duration): self
    {
        $this->duration = $duration;
        return $this;
    }

    public function getExitCode(): ?int
    {
        return $this->exit_code;
    }

    public function setExitCode(?int $exit_code): self
    {
        $this->exit_code = $exit_code;
        return $this;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function setHost(?string $host): self
    {
        $this->host = $host;
        return $this;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function setTimestamp(int $timestamp): self
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    public function getReceivedAt(): int
    {
        return $this->received_at;
    }

    public function setReceivedAt(int $received_at): self
    {
        $this->received_at = $received_at;
        return $this;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): self
    {
        $this->ip = $ip;
        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): self
    {
        $this->error = $error;
        return $this;
    }

    /**
     * @return Collection<int, Tag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): self
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(Tag $tag): self
    {
        $this->tags->removeElement($tag);
        return $this;
    }

    /**
     * Check for state changes and trigger notifications if needed
     *
     * @ORM\PostPersist
     */
    public function checkForStateChanges(): void
    {
        try {
            $repository = new DoctrinePingRepository(/* EntityManager will be injected */);
            $previousPings = $repository->findRecentByMonitor($this->monitor->getId(), 2);

            // Remove current ping from the list (if present)
            $previousPings = array_filter($previousPings, function($ping) {
                return $ping->getId() !== $this->id;
            });

            $previousPing = reset($previousPings);

            // If this is the first ping for the monitor, no need to check for state change
            if (!$previousPing) {
                return;
            }

            $notificationService = new \App\Services\NotificationService(/* dependencies will be injected */);

            // Check for failure event
            if ($this->state === PingState::FAIL->value && $previousPing->getState() !== PingState::FAIL->value) {
                $notificationService->handleMonitorFail($this->monitor->getId(), $this->error ?? '');
            }

            // Check for recovery event
            if ($this->state === PingState::COMPLETE->value && $previousPing->getState() === PingState::FAIL->value) {
                $notificationService->handleMonitorResolve($this->monitor->getId());
            }
        } catch (\Exception $e) {
            // Log error, but don't disrupt the main process
            error_log("Error checking for state changes: " . $e->getMessage());
        }
    }

    public function getFormattedTimestamp(string $format = 'Y-m-d H:i:s'): string
    {
        $date = new \DateTime();
        $date->setTimestamp($this->timestamp);
        return $date->format($format);
    }

    public function ago() {

        $when = $this->getTimestamp();

        $diff = date("U") - $when;

        // Days
        $day = floor($diff / 86400);
        $diff = $diff - ($day * 86400);

        // Hours
        $hrs = floor($diff / 3600);
        $diff = $diff - ($hrs * 3600);

        // Mins
        $min = floor($diff / 60);
        $diff = $diff - ($min * 60);

        // Secs
        $sec = $diff;

        // Return how long ago this was. eg: 3d 17h 4m 18s ago
        // Skips left fields if they aren't necessary, eg. 16h 0m 27s ago / 10m 7s ago
        $str = sprintf("%s%s%s%s",
            $day != 0 ? $day."d " : "",
            ($day != 0 || $hrs != 0) ? $hrs."h " : "",
            ($day != 0 || $hrs != 0 || $min != 0) ? $min."m " : "",
            $sec."s ago"
        );

        return $str;
    }
}