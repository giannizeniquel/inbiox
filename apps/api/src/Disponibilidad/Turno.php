<?php

declare(strict_types=1);

namespace App\Disponibilidad;

use App\Agente\Agente;

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
class Turno
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Agente::class, inversedBy: 'shifts')]
    #[ORM\JoinColumn(name: 'agent_id', referencedColumnName: 'id', onDelete: 'CASCADE', nullable: false)]
    private ?Agente $agente = null;

    #[ORM\Column(name: 'day_of_week', type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $diaDeLaSemana;

    #[ORM\Column(name: 'start_time', type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $horaDeInicio;

    #[ORM\Column(name: 'end_time', type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $horaDeFin;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE, options: ['comment' => '(DC2Type:datetime_immutable)'])]
    private \DateTimeImmutable $creadoAt;

    public function __construct(
        Agente $agente,
        int $diaDeLaSemana,
        \DateTimeImmutable $horaDeInicio,
        \DateTimeImmutable $horaDeFin
    ) {
        $this->agente = $agente;
        $this->diaDeLaSemana = $diaDeLaSemana;
        $this->horaDeInicio = $horaDeInicio;
        $this->horaDeFin = $horaDeFin;
        $this->creadoAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAgente(): ?Agente
    {
        return $this->agente;
    }

    public function setAgente(?Agente $agente): self
    {
        $this->agente = $agente;
        return $this;
    }

    public function getDiaDeLaSemana(): int
    {
        return $this->diaDeLaSemana;
    }

    public function setDiaDeLaSemana(int $diaDeLaSemana): self
    {
        $this->diaDeLaSemana = $diaDeLaSemana;
        return $this;
    }

    public function getHoraDeInicio(): \DateTimeImmutable
    {
        return $this->horaDeInicio;
    }

    public function setHoraDeInicio(\DateTimeImmutable $horaDeInicio): self
    {
        $this->horaDeInicio = $horaDeInicio;
        return $this;
    }

    public function getHoraDeFin(): \DateTimeImmutable
    {
        return $this->horaDeFin;
    }

    public function setHoraDeFin(\DateTimeImmutable $horaDeFin): self
    {
        $this->horaDeFin = $horaDeFin;
        return $this;
    }

    public function getCreadoAt(): \DateTimeImmutable
    {
        return $this->creadoAt;
    }
}
