<?php

namespace App\Tests\Integration;

use App\Compartido\Realtime\MercureJwtService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Security tests for Sprint 09B - Realtime with Mercure.
 */
class Sprint09BSecurityTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
    }

    private function createJwtService(): MercureJwtService
    {
        $params = static::getContainer()->get(ParameterBagInterface::class);
        return new MercureJwtService($params);
    }

    /**
     * Test: JWT contains correct topic for assigned tickets
     */
    public function test_jwt_contains_assigned_ticket_topics(): void
    {
        $jwtService = $this->createJwtService();
        
        // Generate with int agentId and ticket IDs
        $jwt = $jwtService->generateToken(1, [10, 15]);
        
        // Decode JWT payload
        $parts = explode('.', $jwt);
        $payload = json_decode(base64_decode($parts[1]), true);
        
        $subscribe = $payload['mercure']['subscribe'] ?? [];
        
        // Should contain assigned ticket topics
        $this->assertContains('ticket/10', $subscribe);
        $this->assertContains('ticket/15', $subscribe);
        
        // Should contain inbox topic (agent ID as int)
        $this->assertContains('agentes/1/bandeja', $subscribe);
    }

    /**
     * Test: JWT does NOT contain topics for unassigned tickets
     */
    public function test_jwt_denies_unassigned_tickets(): void
    {
        $jwtService = $this->createJwtService();
        
        // Agent assigned to ticket 20 only
        $jwt = $jwtService->generateToken(2, [20]);
        
        $parts = explode('.', $jwt);
        $payload = json_decode(base64_decode($parts[1]), true);
        
        $subscribe = $payload['mercure']['subscribe'] ?? [];
        
        // Should contain assigned
        $this->assertContains('ticket/20', $subscribe);
        
        // Should NOT contain unassigned
        $this->assertNotContains('ticket/21', $subscribe);
        $this->assertNotContains('ticket/25', $subscribe);
    }

    /**
     * Test: Agent can subscribe to their inbox topic
     */
    public function test_jwt_allows_inbox_topic(): void
    {
        $jwtService = $this->createJwtService();
        
        $jwt = $jwtService->generateToken(3, [30]);
        
        $parts = explode('.', $jwt);
        $payload = json_decode(base64_decode($parts[1]), true);
        
        $subscribe = $payload['mercure']['subscribe'] ?? [];
        
        $this->assertContains('agentes/3/bandeja', $subscribe);
    }

    /**
     * Test: JWT does NOT contain wildcard topics
     */
    public function test_jwt_no_wildcard_topics(): void
    {
        $jwtService = $this->createJwtService();
        
        $jwt = $jwtService->generateToken(4, [40, 41]);
        
        $parts = explode('.', $jwt);
        $payload = json_decode(base64_decode($parts[1]), true);
        
        $subscribe = $payload['mercure']['subscribe'] ?? [];
        
        foreach ($subscribe as $topic) {
            $this->assertStringStartsNotWith('ticket/*', $topic);
            $this->assertStringStartsNotWith('agentes/*', $topic);
        }
    }

    /**
     * Test: JWT expires (has exp claim)
     */
    public function test_jwt_has_expiration(): void
    {
        $jwtService = $this->createJwtService();
        
        $jwt = $jwtService->generateToken(5, [50]);
        
        $parts = explode('.', $jwt);
        $payload = json_decode(base64_decode($parts[1]), true);
        
        $this->assertArrayHasKey('exp', $payload);
        $this->assertGreaterThan(time(), $payload['exp']);
    }
}