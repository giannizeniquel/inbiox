<?php

declare(strict_types=1);

namespace App\Autenticacion\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;

/**
 * Normalizes the login and refresh success response to match the API contract.
 *
 * Lexik returns `{ token: "..." }` but the spec requires:
 *   `{ access_token: "...", token_type: "Bearer", expires_in: 900 }`
 *
 * Runs after Gesdinet's refresh token listener has modified the data.
 */
final class AuthenticationSuccessListener
{
    /**
     * @param int $tokenTtl The JWT TTL in seconds (from `lexik_jwt_authentication.token_ttl`)
     */
    public function __construct(
        private readonly int $tokenTtl,
    ) {}

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $data = $event->getData();

        // Rename token → access_token
        if (\array_key_exists('token', $data)) {
            $data['access_token'] = $data['token'];
            unset($data['token']);
        }

        // Standard OAuth2 fields
        $data['token_type'] = 'Bearer';
        $data['expires_in'] = $this->tokenTtl;

        $event->setData($data);
    }
}
