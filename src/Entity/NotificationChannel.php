<?php

namespace App\Entity;

use App\Repository\DoctrineNotificationChannelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DoctrineNotificationChannelRepository::class)]
#[ORM\Table(name: 'notification_channels')]
class NotificationChannel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $type;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $config = null;

    public $config_decoded;

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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getConfig(): ?string
    {
        return $this->config;
    }

    public function setConfig(?string $config): self
    {
        $this->config = $config;
        return $this;
    }

    public function getConfigDecoded(): ?array
    {
        if ($this->config === null) {
            return null;
        }

        return json_decode($this->config, true);
    }
}