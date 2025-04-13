<?php

namespace App\Entity;

use App\Repository\DoctrineMonitorGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DoctrineMonitorGroupRepository::class)]
#[ORM\Table(name: 'monitor_groups')]
class MonitorGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\OneToMany(mappedBy: 'group', targetEntity: Monitor::class)]
    private Collection $monitors;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTime $created_at;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $updated_at = null;

    public function __construct(string $name = '', ?string $description = null)
    {
        $this->name = $name;
        $this->description = $description;
        $this->monitors = new ArrayCollection();
        $this->created_at = new \DateTime();
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
        $this->updated_at = new \DateTime();
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        $this->updated_at = new \DateTime();
        return $this;
    }

    /**
     * @return Collection<int, Monitor>
     */
    public function getMonitors(): Collection
    {
        return $this->monitors;
    }

    public function addMonitor(Monitor $monitor): self
    {
        if (!$this->monitors->contains($monitor)) {
            $this->monitors->add($monitor);
            $monitor->setGroup($this);
        }

        return $this;
    }

    public function removeMonitor(Monitor $monitor): self
    {
        if ($this->monitors->removeElement($monitor)) {
            // set the owning side to null (unless already changed)
            if ($monitor->getGroup() === $this) {
                $monitor->setGroup(null);
            }
        }

        return $this;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->created_at;
    }

    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updated_at;
    }
}