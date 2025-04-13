<?php

namespace App\Entity;

use App\Repository\DoctrineMonitorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DoctrineMonitorRepository::class)]
#[ORM\Table(name: 'monitors')]
class Monitor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $project_name = null;

    #[ORM\OneToMany(mappedBy: 'monitor', targetEntity: Ping::class, cascade: ['persist'])]
    private Collection $pings;

    #[ORM\OneToOne(mappedBy: 'monitor', targetEntity: MonitorConfig::class, cascade: ['persist', 'remove'])]
    private ?MonitorConfig $config = null;

    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'monitors')]
    #[ORM\JoinTable(name: 'monitor_tags')]
    #[ORM\JoinColumn(name: 'monitor_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id')]
    private Collection $tags;

    #[ORM\OneToMany(mappedBy: 'monitor', targetEntity: MonitorOverdueHistory::class, cascade: ['persist'])]
    private Collection $overdueHistory;

    public function __construct(string $name = '', ?string $project_name = null)
    {
        $this->name = $name;
        $this->project_name = $project_name;
        $this->pings = new ArrayCollection();
        $this->tags = new ArrayCollection();
        $this->overdueHistory = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getProjectName(): ?string
    {
        return $this->project_name;
    }

    public function setProjectName(?string $project_name): self
    {
        $this->project_name = $project_name;
        return $this;
    }

    /**
     * @return Collection<int, Ping>
     */
    public function getPings(): Collection
    {
        return $this->pings;
    }

    public function addPing(Ping $ping): self
    {
        if (!$this->pings->contains($ping)) {
            $this->pings->add($ping);
            $ping->setMonitor($this);
        }

        return $this;
    }

    public function removePing(Ping $ping): self
    {
        if ($this->pings->removeElement($ping)) {
            // set the owning side to null (unless already changed)
            if ($ping->getMonitor() === $this) {
                $ping->setMonitor(null);
            }
        }

        return $this;
    }

    public function getConfig(): ?MonitorConfig
    {
        return $this->config;
    }

    public function setConfig(?MonitorConfig $config): self
    {
        $this->config = $config;

        // set (or unset) the owning side of the relation if necessary
        $newMonitor = null === $config ? null : $this;
        if ($config !== null && $config->getMonitor() !== $newMonitor) {
            $config->setMonitor($newMonitor);
        }

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
            $tag->addMonitor($this);
        }

        return $this;
    }

    public function removeTag(Tag $tag): self
    {
        if ($this->tags->removeElement($tag)) {
            $tag->removeMonitor($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, MonitorOverdueHistory>
     */
    public function getOverdueHistory(): Collection
    {
        return $this->overdueHistory;
    }

    public function addOverdueHistory(MonitorOverdueHistory $history): self
    {
        if (!$this->overdueHistory->contains($history)) {
            $this->overdueHistory->add($history);
            $history->setMonitor($this);
        }

        return $this;
    }

    public function removeOverdueHistory(MonitorOverdueHistory $history): self
    {
        if ($this->overdueHistory->removeElement($history)) {
            // set the owning side to null (unless already changed)
            if ($history->getMonitor() === $this) {
                $history->setMonitor(null);
            }
        }

        return $this;
    }

    /**
     * Get last ping for this monitor
     */
    public function getLastPing(): ?Ping
    {
        if ($this->pings->isEmpty()) {
            return null;
        }

        $iterator = $this->pings->getIterator();
        $iterator->uasort(function (Ping $a, Ping $b) {
            return $b->getTimestamp() <=> $a->getTimestamp();
        });

        return $iterator->current();
    }
}