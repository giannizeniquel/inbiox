<?php

declare(strict_types=1);

namespace App\Compartido\Security;

use App\Administracion\Usuario;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Determina el alcance de espacio de trabajo del usuario autenticado actual.
 *
 * Devuelve el phoneNumberId del espacio de trabajo activo para usuarios ROLE_AGENT
 * con un Agent vinculado que tenga un espacio de trabajo activo.
 * Devuelve null para SUPERVISOR, ADMIN o AGENT sin espacio de trabajo activo.
 *
 * Se usa a nivel de QUERY para agregar cláusulas WHERE, filtrando
 * tickets/conversaciones/contactos por el phone number ID del espacio de trabajo activo.
 */
final class AlcanceDeEspacioDeTrabajo
{
    public function __construct(
        private readonly Security $security,
    ) {}

    /**
     * Obtiene el phoneNumberId que acota al usuario actual.
     *
     * TODOS los roles (ADMIN, SUPERVISOR, AGENT) siguen el mismo camino:
     * usuario → agente → espacio de trabajo activo → phoneNumberId.
     *
     * - Usuario con un Agent vinculado que tiene un espacio de trabajo activo: el phoneNumberId de ese espacio de trabajo
     * - Usuario sin Agent o sin espacio de trabajo activo: null (sin alcance)
     * - Sin usuario autenticado: null
     *
     * El selector de espacio de trabajo se encarga de fijar el espacio de trabajo activo
     * mediante PUT /api/me/workspace. Admin/Supervisor pueden cambiar a cualquier espacio de trabajo;
     * Agent solo puede cambiar a los espacios de trabajo asignados.
     *
     * @see MiEspacioDeTrabajoController::setEspacioDeTrabajoActivo()
     * @see AsegurarAgenteDeUsuarioUseCase — crea automáticamente el Agent de admin/supervisor
     */
    public function getPhoneNumberIdActivo(): ?string
    {
        $usuario = $this->security->getUser();

        if (!$usuario instanceof Usuario) {
            return null;
        }

        $agente = $usuario->getAgente();

        if (null === $agente) {
            return null;
        }

        $espacioDeTrabajoActivo = $agente->getEspacioDeTrabajoActivo();

        if (null === $espacioDeTrabajoActivo) {
            return null;
        }

        return $espacioDeTrabajoActivo->getPhoneNumberId();
    }
}
