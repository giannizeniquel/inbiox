<?php

namespace App\Automatizacion;

use App\Ticket\Ticket;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'rule_execution_logs')]
#[ORM\UniqueConstraint(columns: ['execution_key'])]
class RegistroDeRegla
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'execution_key', type: 'string', length: 255, unique: true)]
    private string $claveDeEjecucion;

    #[ORM\Column(name: 'rule_name', type: 'string', length: 100)]
    private string $nombreDeRegla;

    #[ORM\Column(name: 'ticket_id', type: 'bigint', nullable: true)]
    private ?int $ticketId = null;

    #[ORM\Column(name: 'message_id', type: 'bigint', nullable: true)]
    private ?int $mensajeId = null;

    #[ORM\Column(name: 'executed_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $ejecutadoAt;

    #[ORM\Column(name: 'result_status', type: 'string', length: 20)]
    private string $estadoDeResultado = 'success';

    #[ORM\Column(name: 'result_data', type: 'json', nullable: true)]
    private ?array $datosDeResultado = null;

    public function __construct(string $executionKey, string $ruleName, ?int $ticketId = null, ?int $messageId = null)
    {
        $this->claveDeEjecucion = $executionKey;
        $this->nombreDeRegla = $ruleName;
        $this->ticketId = $ticketId;
        $this->mensajeId = $messageId;
        $this->ejecutadoAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClaveDeEjecucion(): string
    {
        return $this->claveDeEjecucion;
    }

    public function getNombreDeRegla(): string
    {
        return $this->nombreDeRegla;
    }

    public function getTicketId(): ?int
    {
        return $this->ticketId;
    }

    public function getMensajeId(): ?int
    {
        return $this->mensajeId;
    }

    public function getEjecutadoAt(): \DateTimeImmutable
    {
        return $this->ejecutadoAt;
    }

    public function getEstadoDeResultado(): string
    {
        return $this->estadoDeResultado;
    }

    public function setEstadoDeResultado(string $resultStatus): self
    {
        $this->estadoDeResultado = $resultStatus;
        return $this;
    }

    public function getDatosDeResultado(): ?array
    {
        return $this->datosDeResultado;
    }

    public function setDatosDeResultado(?array $resultData): self
    {
        $this->datosDeResultado = $resultData;
        return $this;
    }
}