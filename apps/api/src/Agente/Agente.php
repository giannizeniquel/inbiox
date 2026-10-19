<?php

declare(strict_types=1);

namespace App\Agente;

use App\Disponibilidad\Turno;

use App\Administracion\User;
use App\Administracion\Workspace;
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

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'agent')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, unique: true)]
    private ?User $usuario = null;

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

    #[ORM\ManyToMany(targetEntity: Workspace::class, inversedBy: 'agents')]
    #[ORM\JoinTable(name: 'agent_workspace')]
    #[ORM\JoinColumn(name: 'agent_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'workspace_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $espaciosDeTrabajo;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'active_workspace_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Workspace $espacioDeTrabajoActivo = null;

    #[ORM\ManyToMany(targetEntity: Equipo::class, inversedBy: 'agentes')]
    #[ORM\JoinTable(name: 'agent_team')]
    #[ORM\JoinColumn(name: 'agent_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'team_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $equipos;

    public function __construct(string $name)
    {
        $this->nombre = $name;
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

    public function getUsuario(): ?User
    {
        return $this->usuario;
    }

    public function setUsuario(?User $user): self
    {
        $this->usuario = $user;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function isActivo(): bool
    {
        return $this->activo;
    }

    public function setActivo(bool $active): self
    {
        $this->activo = $active;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function isDisponible(): bool
    {
        return $this->disponible;
    }

    public function setDisponible(bool $isAvailable): self
    {
        $this->disponible = $isAvailable;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function getMaxTicketsActivos(): int
    {
        return $this->maxTicketsActivos;
    }

    public function setMaxTicketsActivos(int $maxActiveTickets): self
    {
        if ($maxActiveTickets < 1) {
            throw new \InvalidArgumentException('maxActiveTickets must be at least 1');
        }
        $this->maxTicketsActivos = $maxActiveTickets;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function getZonaHoraria(): string
    {
        return $this->zonaHoraria;
    }

    public function setZonaHoraria(string $timezone): self
    {
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(\sprintf('Invalid timezone: "%s"', $timezone));
        }
        $this->zonaHoraria = $timezone;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function getDisponibilidadManual(): ?bool
    {
        return $this->disponibilidadManual;
    }

    public function setDisponibilidadManual(?bool $isAvailableOverride): self
    {
        $this->disponibilidadManual = $isAvailableOverride;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function getDisponibilidadManualHasta(): ?\DateTimeImmutable
    {
        return $this->disponibilidadManualHasta;
    }

    public function setDisponibilidadManualHasta(?\DateTimeImmutable $overrideUntil): self
    {
        $this->disponibilidadManualHasta = $overrideUntil;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    /** @return Collection<int, Turno> */
    public function getTurnos(): Collection
    {
        return $this->turnos;
    }

    public function addTurno(Turno $shift): self
    {
        if (!$this->turnos->contains($shift)) {
            $this->turnos->add($shift);
            $shift->setAgente($this);
        }
        return $this;
    }

    public function removeTurno(Turno $shift): self
    {
        if ($this->turnos->removeElement($shift)) {
            if ($shift->getAgente() === $this) {
                $shift->setAgente(null);
            }
        }
        return $this;
    }

    /** @return Collection<int, Workspace> */
    public function getEspaciosDeTrabajo(): Collection
    {
        return $this->espaciosDeTrabajo;
    }

    public function addEspacioDeTrabajo(Workspace $workspace): self
    {
        if (!$this->espaciosDeTrabajo->contains($workspace)) {
            $this->espaciosDeTrabajo->add($workspace);
        }
        return $this;
    }

    public function removeEspacioDeTrabajo(Workspace $workspace): self
    {
        $this->espaciosDeTrabajo->removeElement($workspace);
        return $this;
    }

    /** @return Collection<int, Equipo> */
    public function getEquipos(): Collection
    {
        return $this->equipos;
    }

    public function addEquipo(Equipo $team): self
    {
        if (!$this->equipos->contains($team)) {
            $this->equipos->add($team);
        }
        return $this;
    }

    public function removeEquipo(Equipo $team): self
    {
        $this->equipos->removeElement($team);
        return $this;
    }

    public function getFirma(): ?string
    {
        return $this->firma;
    }

    public function setFirma(?string $signature): self
    {
        $this->firma = $signature;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function hasSonidoDeNotificacion(): bool
    {
        return $this->sonidoDeNotificacion;
    }

    public function setSonidoDeNotificacion(bool $notificationSound): self
    {
        $this->sonidoDeNotificacion = $notificationSound;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function hasNotificacionesDeEscritorio(): bool
    {
        return $this->notificacionesDeEscritorio;
    }

    public function setNotificacionesDeEscritorio(bool $desktopNotifications): self
    {
        $this->notificacionesDeEscritorio = $desktopNotifications;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function isRespuestaAutomaticaActiva(): bool
    {
        return $this->respuestaAutomaticaActiva;
    }

    public function setRespuestaAutomaticaActiva(bool $autoReplyEnabled): self
    {
        $this->respuestaAutomaticaActiva = $autoReplyEnabled;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function getMensajeDeRespuestaAutomatica(): ?string
    {
        return $this->mensajeDeRespuestaAutomatica;
    }

    public function setMensajeDeRespuestaAutomatica(?string $autoReplyMessage): self
    {
        $this->mensajeDeRespuestaAutomatica = $autoReplyMessage;
        $this->actualizadoAt = new \DateTimeImmutable();
        return $this;
    }

    public function getEspacioDeTrabajoActivo(): ?Workspace
    {
        return $this->espacioDeTrabajoActivo;
    }

    public function setEspacioDeTrabajoActivo(?Workspace $activeWorkspace): self
    {
        $this->espacioDeTrabajoActivo = $activeWorkspace;
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