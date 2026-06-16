<?php

declare(strict_types=1);

namespace App\Application\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class MercureJwtService
{
    private const DEFAULT_TTL = 3600;
    private const TOPIC_TICKETS = 'tickets';
    private const TOPIC_AGENT_INBOX = 'agent/%d/inbox';

    /** Agent availability changes — e.g., agent/5/availability */
    public const TOPIC_AGENT_AVAILABILITY = 'agent/%d/availability';
    /** Agent shift create/delete — e.g., agent/5/shifts */
    public const TOPIC_AGENT_SHIFTS = 'agent/%d/shifts';
    /** Broadcast topic for capacity bar (Sprint 10.5) */
    public const TOPIC_AGENTS_BROADCAST = 'agents';

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
    ) {}

    public function generateToken(int $agentId, array $assignedTicketIds): string
    {
        if ($agentId < 0) {
            throw new InvalidArgumentException('Invalid agent ID');
        }
        // Allow system publisher (agentId = 0) with wildcard topic
        if ($agentId === 0) {
            return $this->generateSystemPublisherToken();
        }
        if (empty($assignedTicketIds)) {
            throw new InvalidArgumentException('Agent must have at least one assigned ticket');
        }

        $secret = $this->getSecret();
        $ttl = $this->getTtl();
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify("+{$ttl} seconds");

        // Build topics array — single topic for all ticket events, per-agent inbox,
        // per-agent availability, per-agent shifts, and agents broadcast
        $topics = [self::TOPIC_TICKETS];
        $topics[] = sprintf(self::TOPIC_AGENT_INBOX, $agentId);
        $topics[] = sprintf(self::TOPIC_AGENT_AVAILABILITY, $agentId);
        $topics[] = sprintf(self::TOPIC_AGENT_SHIFTS, $agentId);
        $topics[] = self::TOPIC_AGENTS_BROADCAST;

        // Create JWT using Lcobucci v4+ Configuration API
        $config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($secret)
        );

        $token = $config->builder()
            ->identifiedBy((string) $agentId, true)
            ->issuedBy('inbiox-api')
            ->issuedAt($now)
            ->expiresAt($expiresAt)
            ->withClaim('agent_id', $agentId)
            ->withClaim('mercure', ['subscribe' => $topics])
            ->getToken($config->signer(), $config->signingKey());

        return $token->toString();
    }

    /**
     * Generate a system publisher token with wildcard publish permission.
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

        // System publisher with full publish access
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

    public function extractAgentId(Token $token): ?int
    {
        $claims = $token->claims();
        if (!$claims->has('agent_id')) return null;
        $agentId = $claims->get('agent_id');
        return is_int($agentId) ? $agentId : null;
    }

    public function extractTopics(Token $token): ?array
    {
        $claims = $token->claims();
        if (!$claims->has('mercure')) return null;
        $mercure = $claims->get('mercure');
        if (!is_array($mercure) || !isset($mercure['subscribe'])) return null;
        return $mercure['subscribe'];
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