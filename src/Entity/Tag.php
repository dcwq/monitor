<?php

namespace App\Entity;

use App\Repository\DoctrineTagRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DoctrineTagRepository::class)]
#[ORM\Table(name: 'tags')]
class Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    private string $name;

    #[ORM\ManyToMany(targetEntity: Monitor::class, mappedBy: 'tags')]
    private Collection $monitors;

    #[ORM\ManyToMany(targetEntity: Ping::class, mappedBy: 'tags')]
    private Collection $pings;

    public function __construct(string $name = '')
    {
        $this->name = $name;
        $this->monitors = new ArrayCollection();
        $this->pings = new ArrayCollection();
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
            $monitor->addTag($this);
        }

        return $this;
    }

    public function removeMonitor(Monitor $monitor): self
    {
        if ($this->monitors->removeElement($monitor)) {
            $monitor->removeTag($this);
        }

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
            $ping->addTag($this);
        }

        return $this;
    }

    public function removePing(Ping $ping): self
    {
        if ($this->pings->removeElement($ping)) {
            $ping->removeTag($this);
        }

        return $this;
    }
}