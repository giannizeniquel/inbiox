<?php

declare(strict_types=1);

namespace App\Compartido\Realtime;

use DateTimeImmutable;
use InvalidArgumentException;
use App\Agente\Agente;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class MercureJwtService
{
    private const DEFAULT_TTL = 3600;
    private const TOPIC_TICKETS = 'tickets';
    private const TOPIC_BANDEJA_DE_AGENTE = 'agent/%d/inbox';

    /** Cambios de disponibilidad del agente — p. ej., agent/5/availability */
    public const TOPIC_DISPONIBILIDAD_DE_AGENTE = 'agent/%d/availability';
    /** Alta/baja de turnos del agente — p. ej., agent/5/shifts */
    public const TOPIC_TURNOS_DE_AGENTE = 'agent/%d/shifts';
    /** Topic de difusión para la barra de capacidad (Sprint 10.5) */
    public const TOPIC_AGENTES = 'agents';

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
    ) {}

    public function generateToken(int $agenteId, array $ticketIdsAsignados): string
    {
        if ($agenteId < 0) {
            throw new InvalidArgumentException('Invalid agent ID');
        }
        // Permite el publicador del sistema (agentId = 0) con topic comodín
        if ($agenteId === 0) {
            return $this->generateSystemPublisherToken();
        }
        if (empty($ticketIdsAsignados)) {
            throw new InvalidArgumentException('Agent must have at least one assigned ticket');
        }

        $secret = $this->getSecret();
        $ttl = $this->getTtl();
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify("+{$ttl} seconds");

        // Arma el array de topics — un único topic para todos los eventos de tickets, inbox por agente,
        // disponibilidad por agente, turnos por agente y difusión de agentes
        $topics = [self::TOPIC_TICKETS];
        $topics[] = sprintf(self::TOPIC_BANDEJA_DE_AGENTE, $agenteId);
        $topics[] = sprintf(self::TOPIC_DISPONIBILIDAD_DE_AGENTE, $agenteId);
        $topics[] = sprintf(self::TOPIC_TURNOS_DE_AGENTE, $agenteId);
        $topics[] = self::TOPIC_AGENTES;

        // Crea el JWT con la Configuration API de Lcobucci v4+
        $config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($secret)
        );

        $token = $config->builder()
            ->identifiedBy((string) $agenteId, true)
            ->issuedBy('inbiox-api')
            ->issuedAt($now)
            ->expiresAt($expiresAt)
            ->withClaim('agent_id', $agenteId)
            ->withClaim('mercure', ['subscribe' => $topics])
            ->getToken($config->signer(), $config->signingKey());

        return $token->toString();
    }

    /**
     * Genera un token de publicador del sistema con permiso de publicación comodín.
     */
    public function createSubscriberToken(array $topics): Token
    {
        $secret = $this->getSecret();
        $ttl = $this->getTtl();
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify("+{$ttl} seconds");

        $config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($secret)
        );

        $token = $config->builder()
            ->identifiedBy('subscriber-' . bin2hex(random_bytes(8)), true)
            ->issuedBy('inbiox-api')
            ->issuedAt($now)
            ->expiresAt($expiresAt)
            ->withClaim('mercure', ['subscribe' => $topics])
            ->getToken($config->signer(), $config->signingKey());

        return $token;
    }

    public function generateSystemPublisherToken(): string
    {
        $secret = $this->getSecret();
        $ttl = $this->getTtl();
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify("+{$ttl} seconds");

        $config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($secret)
        );

        // Publicador del sistema con acceso completo de publicación
        $token = $config->builder()
            ->identifiedBy('system-publisher', true)
            ->issuedBy('inbiox-api')
            ->issuedAt($now)
            ->expiresAt($expiresAt)
            ->withClaim('mercure', ['publish' => ['*']])
            ->getToken($config->signer(), $config->signingKey());

        return $token->toString();
    }

    public function verifyToken(string $tokenString): ?Token
    {
        try {
            $secret = $this->getSecret();
            $config = Configuration::forSymmetricSigner(
                new Sha256(),
                InMemory::plainText($secret)
            );
            $token = $config->parser()->parse($tokenString);

            $constraints = [
                new SignedWith($config->signer(), $config->signingKey()),
            ];

            $config->validator()->assert($token, ...$constraints);
            return $token;
        } catch (RequiredConstraintsViolated) {
            return null;
        } catch (\Exception) {
            return null;
        }
    }

    public function extractAgenteId(Token $token): ?int
    {
        $claims = $token->claims();
        if (!$claims->has('agent_id')) return null;
        $agenteId = $claims->get('agent_id');
        return is_int($agenteId) ? $agenteId : null;
    }

    public function extractTopics(Token $token): ?array
    {
        $claims = $token->claims();
        if (!$claims->has('mercure')) return null;
        $mercure = $claims->get('mercure');
        if (!is_array($mercure) || !isset($mercure['subscribe'])) return null;
        return $mercure['subscribe'];
    }

    /**
     * Emite un JWT de Mercure para un usuario concreto (base para la autorización de PR3).
     *
     * Los topics se acotarán por rol:
     * - ROLE_AGENT: topics propios del agente + tickets
     * - ROLE_SUPERVISOR/ROLE_ADMIN: todos + comodín
     */
    public function issueParaUsuario(UserInterface $usuario, ?Agente $agente = null): Token
    {
        $roles = $usuario->getRoles();
        $topics = [self::TOPIC_TICKETS, self::TOPIC_AGENTES];

        if (null !== $agente) {
            $topics[] = \sprintf(self::TOPIC_BANDEJA_DE_AGENTE, $agente->getId());
            $topics[] = \sprintf(self::TOPIC_DISPONIBILIDAD_DE_AGENTE, $agente->getId());
            $topics[] = \sprintf(self::TOPIC_TURNOS_DE_AGENTE, $agente->getId());
        }

        // Supervisores y administradores obtienen acceso comodín
        if (\in_array('ROLE_SUPERVISOR', $roles, true) || \in_array('ROLE_ADMIN', $roles, true)) {
            $topics[] = '*';
        }

        return $this->createSubscriberToken($topics);
    }

    private function getSecret(): string
    {
        return $this->parameterBag->get('MERCURE_JWT_SECRET') ?? 'default-secret';
    }

    private function getTtl(): int
    {
        return $this->parameterBag->get('MERCURE_JWT_TTL') ?? self::DEFAULT_TTL;
    }
}