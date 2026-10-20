<?php

declare(strict_types=1);

namespace App\Autenticacion\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;

/**
 * Normaliza la respuesta exitosa de login y refresh para cumplir el contrato de la API.
 *
 * Lexik devuelve `{ token: "..." }`, pero la spec exige:
 *   `{ access_token: "...", token_type: "Bearer", expires_in: 900 }`
 *
 * Se ejecuta después de que el listener de refresh token de Gesdinet modificó los datos.
 */
final class RespuestaDeAutenticacionListener
{
    /**
     * @param int $tokenTtl TTL del JWT en segundos (de `lexik_jwt_authentication.token_ttl`)
     */
    public function __construct(
        private readonly int $tokenTtl,
    ) {}

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $data = $event->getData();

        // Renombra token → access_token
        if (\array_key_exists('token', $data)) {
            $data['access_token'] = $data['token'];
            unset($data['token']);
        }

        // Campos estándar de OAuth2
        $data['token_type'] = 'Bearer';
        $data['expires_in'] = $this->tokenTtl;

        $event->setData($data);
    }
}
