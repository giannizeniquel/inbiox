<?php

declare(strict_types=1);

namespace App\Disponibilidad;

use App\Agente\Agent;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(name: 'idx_agent_shifts_agent_dow', columns: ['agent_id', 'day_of_week'])]
#[ORM\UniqueConstraint(name: 'uniq_agent_dow_start_end', columns: ['agent_id', 'day_of_week', 'start_time', 'end_time'])]
#[ORM\Table(
    name: 'agent_shifts',
    options: [
        'checks' => [
            'chk_agent_shifts_dow' => 'day_of_week BETWEEN 1 AND 7',
            'chk_agent_shifts_time' => 'start_time <> end_time',
        ],
    ]
)]
class AgentShift
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Agent::class, inversedBy: 'shifts')]
    #[ORM\JoinColumn(name: 'agent_id', referencedColumnName: 'id', onDelete: 'CASCADE', nullable: false)]
    private ?Agent $agent = null;

    #[ORM\Column(name: 'day_of_week', type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $dayOfWeek;

    #[ORM\Column(name: 'start_time', type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $startTime;

    #[ORM\Column(name: 'end_time', type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $endTime;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE, options: ['comment' => '(DC2Type:datetime_immutable)'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Agent $agent,
        int $dayOfWeek,
        \DateTimeImmutable $startTime,
        \DateTimeImmutable $endTime
    ) {
        $this->agent = $agent;
        $this->dayOfWeek = $dayOfWeek;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAgent(): ?Agent
    {
        return $this->agent;
    }

    public function setAgent(?Agent $agent): self
    {
        $this->agent = $agent;
        return $this;
    }

    public function getDayOfWeek(): int
    {
        return $this->dayOfWeek;
    }

    public function setDayOfWeek(int $dayOfWeek): self
    {
        $this->dayOfWeek = $dayOfWeek;
        return $this;
    }

    public function getStartTime(): \DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(\DateTimeImmutable $startTime): self
    {
        $this->startTime = $startTime;
        return $this;
    }

    public function getEndTime(): \DateTimeImmutable
    {
        return $this->endTime;
    }

    public function setEndTime(\DateTimeImmutable $endTime): self
    {
        $this->endTime = $endTime;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
