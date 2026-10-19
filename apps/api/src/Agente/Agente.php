<?php

declare(strict_types=1);

namespace App\Agente;

use App\Disponibilidad\Turno;

use App\Administracion\Usuario;
use App\Administracion\EspacioDeTrabajo;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'agents')]
#[ORM\Index(name: 'idx_agents_active', columns: ['active', 'is_available'])]
class Agente
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 255)]
    private string $nombre;

    #[ORM\OneToOne(targetEntity: Usuario::class, inversedBy: 'agent')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, unique: true)]
    private ?Usuario $usuario = null;

    #[ORM\Column(name: 'active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $activo = true;

    #[ORM\Column(name: 'is_available', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $disponible = true;

    #[ORM\Column(name: 'max_active_tickets', type: Types::INTEGER, options: ['default' => 10])]
    private int $maxTicketsActivos = 10;

    #[ORM\Column(name: 'timezone', type: Types::STRING, length: 64, options: ['default' => 'UTC'])]
    private string $zonaHoraria = 'UTC';

    #[ORM\Column(name: 'is_available_override', type: Types::BOOLEAN, nullable: true)]
    private ?bool $disponibilidadManual = null;

    #[ORM\Column(name: 'override_until', type: Types::DATETIME_IMMUTABLE, nullable: true, options: ['comment' => '(DC2Type:datetime_immutable)'])]
    private ?\DateTimeImmutable $disponibilidadManualHasta = null;

    #[ORM\Column(name: 'signature', type: Types::TEXT, nullable: true)]
    private ?string $firma = null;

    #[ORM\Column(name: 'notification_sound', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $sonidoDeNotificacion = true;

    #[ORM\Column(name: 'desktop_notifications', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $notificacionesDeEscritorio = true;

    #[ORM\Column(name: 'auto_reply_enabled', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $respuestaAutomaticaActiva = false;

    #[ORM\Column(name: 'auto_reply_message', type: Types::TEXT, nullable: true)]
    private ?string $mensajeDeRespuestaAutomatica = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creadoAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $actualizadoAt;

    #[ORM\OneToMany(targetEntity: Turno::class, mappedBy: 'agente', orphanRemoval: true)]
    private Collection $turnos;

    #[ORM\ManyToMany(targetEntity: EspacioDeTrabajo::class, inversedBy: 'agents')]
    #[ORM\JoinTable(name: 'agent_workspace')]
    #[ORM\JoinColumn(name: 'agent_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'workspace_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $espaciosDeTrabajo;

    #[ORM\ManyToOne(targetEntity: EspacioDeTrabajo::class)]
    #[ORM\JoinColumn(name: 'active_workspace_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?EspacioDeTrabajo $espacioDeTrabajoActivo = null;

    #[ORM\ManyToMany(targetEntity: Equipo::class, inversedBy: 'agentes')]
    #[ORM\JoinTable(name: 'agent_team')]
    #[ORM\JoinColumn(name: 'agent_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'team_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $equipos;

    public function __construct(string $nombre)
    {
        $this->nombre = $nombre;
        $this->turnos = new ArrayCollection();
        $this->espaciosDeTrabajo = new ArrayCollection();
        $this->equipos = new ArrayCollection();
        $this->creadoAt = new \DateTimeImmutable();
        $this->actualizadoAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function getUsuario(): ?Usuario
    {
        return $this->usuario;
    }

    public function setUsuario(?Usuario $usuario): self
    {
        $this->usuario = $usuario;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function isActivo(): bool
    {
        return $this->activo;
    }

    public function setActivo(bool $activo): self
    {
        $this->activo = $activo;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function isDisponible(): bool
    {
        return $this->disponible;
    }

    public function setDisponible(bool $disponible): self
    {
        $this->disponible = $disponible;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function getMaxTicketsActivos(): int
    {
        return $this->maxTicketsActivos;
    }

    public function setMaxTicketsActivos(int $maxTicketsActivos): self
    {
        if ($maxTicketsActivos < 1) {
            throw new \InvalidArgumentException('maxActiveTickets must be at least 1');
        }
        $this->maxTicketsActivos = $maxTicketsActivos;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function getZonaHoraria(): string
    {
        return $this->zonaHoraria;
    }

    public function setZonaHoraria(string $zonaHoraria): self
    {
        try {
            new \DateTimeZone($zonaHoraria);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(\sprintf('Invalid timezone: "%s"', $zonaHoraria));
        }
        $this->zonaHoraria = $zonaHoraria;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function getDisponibilidadManual(): ?bool
    {
        return $this->disponibilidadManual;
    }

    public function setDisponibilidadManual(?bool $disponibilidadManual): self
    {
        $this->disponibilidadManual = $disponibilidadManual;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function getDisponibilidadManualHasta(): ?\DateTimeImmutable
    {
        return $this->disponibilidadManualHasta;
    }

    public function setDisponibilidadManualHasta(?\DateTimeImmutable $disponibilidadManualHasta): self
    {
        $this->disponibilidadManualHasta = $disponibilidadManualHasta;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    /** @return Collection<int, Turno> */
    public function getTurnos(): Collection
    {
        return $this->turnos;
    }

    public function addTurno(Turno $turno): self
    {
        if (!$this->turnos->contains($turno)) {
            $this->turnos->add($turno);
            $turno->setAgente($this);
        }
        return $this;
    }

    public function removeTurno(Turno $turno): self
    {
        if ($this->turnos->removeElement($turno)) {
            if ($turno->getAgente() === $this) {
                $turno->setAgente(null);
            }
        }
        return $this;
    }

    /** @return Collection<int, EspacioDeTrabajo> */
    public function getEspaciosDeTrabajo(): Collection
    {
        return $this->espaciosDeTrabajo;
    }

    public function addEspacioDeTrabajo(EspacioDeTrabajo $espacioDeTrabajo): self
    {
        if (!$this->espaciosDeTrabajo->contains($espacioDeTrabajo)) {
            $this->espaciosDeTrabajo->add($espacioDeTrabajo);
        }
        return $this;
    }

    public function removeEspacioDeTrabajo(EspacioDeTrabajo $espacioDeTrabajo): self
    {
        $this->espaciosDeTrabajo->removeElement($espacioDeTrabajo);
        return $this;
    }

    /** @return Collection<int, Equipo> */
    public function getEquipos(): Collection
    {
        return $this->equipos;
    }

    public function addEquipo(Equipo $equipo): self
    {
        if (!$this->equipos->contains($equipo)) {
            $this->equipos->add($equipo);
        }
        return $this;
    }

    public function removeEquipo(Equipo $equipo): self
    {
        $this->equipos->removeElement($equipo);
        return $this;
    }

    public function getFirma(): ?string
    {
        return $this->firma;
    }

    public function setFirma(?string $firma): self
    {
        $this->firma = $firma;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function hasSonidoDeNotificacion(): bool
    {
        return $this->sonidoDeNotificacion;
    }

    public function setSonidoDeNotificacion(bool $sonidoDeNotificacion): self
    {
        $this->sonidoDeNotificacion = $sonidoDeNotificacion;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function hasNotificacionesDeEscritorio(): bool
    {
        return $this->notificacionesDeEscritorio;
    }

    public function setNotificacionesDeEscritorio(bool $notificacionesDeEscritorio): self
    {
        $this->notificacionesDeEscritorio = $notificacionesDeEscritorio;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function isRespuestaAutomaticaActiva(): bool
    {
        return $this->respuestaAutomaticaActiva;
    }

    public function setRespuestaAutomaticaActiva(bool $respuestaAutomaticaActiva): self
    {
        $this->respuestaAutomaticaActiva = $respuestaAutomaticaActiva;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function getMensajeDeRespuestaAutomatica(): ?string
    {
        return $this->mensajeDeRespuestaAutomatica;
    }

    public function setMensajeDeRespuestaAutomatica(?string $mensajeDeRespuestaAutomatica): self
    {
        $this->mensajeDeRespuestaAutomatica = $mensajeDeRespuestaAutomatica;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function getEspacioDeTrabajoActivo(): ?EspacioDeTrabajo
    {
        return $this->espacioDeTrabajoActivo;
    }

    public function setEspacioDeTrabajoActivo(?EspacioDeTrabajo $espacioDeTrabajoActivo): self
    {
        $this->espacioDeTrabajoActivo = $espacioDeTrabajoActivo;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreadoAt(): \DateTimeImmutable
    {
        return $this->creadoAt;
    }

    public function getActualizadoAt(): \DateTimeImmutable
    {
        return $this->actualizadoAt;
    }
}