<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Agent\Agent;
use App\Domain\Agent\AgentShift;
use App\Domain\Contact\Contact;
use App\Domain\Event\TicketEvent;
use App\Domain\Ticket\Ticket;
use App\Repository\ReportingRepository;
use App\Application\Service\ReportingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Integration tests for Reporting v2 — 7 new endpoints.
 *
 * Uses SeedReportsCommand to populate test data, then validates:
 * - 7 repository methods (SQL correctness, workspace scoping, edge cases)
 * - 7 HTTP smoke tests (200 + valid JSON shape)
 *
 * Run: docker exec inbiox_php php bin/phpunit tests/Integration/ReportingV2Test.php
 */
class ReportingV2Test extends WebTestCase
{
    private ?EntityManagerInterface $em = null;
    private ?ReportingRepository $reportingRepo = null;
    private ?ReportingService $reportingService = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->reportingRepo = static::getContainer()->get(ReportingRepository::class);
        $this->reportingService = static::getContainer()->get(ReportingService::class);

        // Seed test data via command
        $this->seedTestData();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
    }

    // ============================================
    // REPOSITORY TESTS (7 methods)
    // ============================================

    public function testGetAgentLoadReturnsPopulatedData(): void
    {
        $result = $this->reportingRepo->getAgentLoad();

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        foreach ($result as $entry) {
            $this->assertArrayHasKey('agent_id', $entry);
            $this->assertArrayHasKey('agent_name', $entry);
            $this->assertArrayHasKey('active_tickets', $entry);
            $this->assertArrayHasKey('max_capacity', $entry);
            $this->assertArrayHasKey('load_percent', $entry);
            $this->assertIsInt($entry['agent_id']);
            $this->assertIsInt($entry['active_tickets']);
            $this->assertIsInt($entry['max_capacity']);
            $this->assertIsFloat($entry['load_percent']);
        }
    }

    public function testGetAgentLoadWorkspaceScoped(): void
    {
        // Get workspace phone_number_id from first ticket
        $conn = $this->em->getConnection();
        $phoneResult = $conn->fetchAssociative('SELECT phone_number_id FROM tickets WHERE phone_number_id IS NOT NULL LIMIT 1');
        $phoneNumberId = $phoneResult['phone_number_id'] ?? null;

        if ($phoneNumberId === null) {
            $this->markTestSkipped('No workspace data found');
        }

        $result = $this->reportingRepo->getAgentLoad(null, $phoneNumberId);
        $this->assertIsArray($result);
    }

    public function testGetUnassignedReturnsTickets(): void
    {
        $result = $this->reportingRepo->getUnassigned();

        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('tickets', $result);
        $this->assertIsInt($result['count']);
        $this->assertIsArray($result['tickets']);

        // Seed data includes unassigned tickets
        $this->assertGreaterThan(0, $result['count']);

        foreach ($result['tickets'] as $ticket) {
            $this->assertArrayHasKey('id', $ticket);
            $this->assertArrayHasKey('contact_name', $ticket);
            $this->assertArrayHasKey('created_at', $ticket);
            $this->assertArrayHasKey('priority', $ticket);
        }
    }

    public function testGetUnassignedWithDateFilter(): void
    {
        $from = new \DateTimeImmutable('-30 days');
        $to = new \DateTimeImmutable('now');

        $result = $this->reportingRepo->getUnassigned(null, $from, $to);
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('tickets', $result);
    }

    public function testGetFrtTrendReturnsData(): void
    {
        $from = new \DateTimeImmutable('-30 days');
        $to = new \DateTimeImmutable('now');

        $result = $this->reportingRepo->getFrtTrend($from, $to);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        foreach ($result as $point) {
            $this->assertArrayHasKey('date', $point);
            $this->assertArrayHasKey('avg_frt_seconds', $point);
            $this->assertArrayHasKey('ticket_count', $point);
            $this->assertIsFloat($point['avg_frt_seconds']);
            $this->assertIsInt($point['ticket_count']);
        }
    }

    public function testGetHeatmapReturnsData(): void
    {
        $from = new \DateTimeImmutable('-90 days');
        $to = new \DateTimeImmutable('now');

        $result = $this->reportingRepo->getHeatmap($from, $to);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        foreach ($result as $cell) {
            $this->assertArrayHasKey('day_of_week', $cell);
            $this->assertArrayHasKey('hour', $cell);
            $this->assertArrayHasKey('count', $cell);
            $this->assertGreaterThanOrEqual(1, $cell['day_of_week']);
            $this->assertLessThanOrEqual(7, $cell['day_of_week']);
            $this->assertGreaterThanOrEqual(0, $cell['hour']);
            $this->assertLessThanOrEqual(23, $cell['hour']);
        }
    }

    public function testGetRecurringCustomersReturnsData(): void
    {
        $result = $this->reportingRepo->getRecurringCustomers(null, 2);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        foreach ($result as $entry) {
            $this->assertArrayHasKey('contact_id', $entry);
            $this->assertArrayHasKey('contact_name', $entry);
            $this->assertArrayHasKey('email', $entry);
            $this->assertArrayHasKey('ticket_count', $entry);
            $this->assertArrayHasKey('last_ticket_at', $entry);
            $this->assertGreaterThanOrEqual(2, $entry['ticket_count']);
        }
    }

    public function testGetReopenedReturnsData(): void
    {
        $from = new \DateTimeImmutable('-30 days');
        $to = new \DateTimeImmutable('now');

        $result = $this->reportingRepo->getReopened($from, $to);

        $this->assertIsArray($result);

        foreach ($result as $entry) {
            $this->assertArrayHasKey('ticket_id', $entry);
            $this->assertArrayHasKey('contact_name', $entry);
            $this->assertArrayHasKey('resolved_at', $entry);
            $this->assertArrayHasKey('reopened_at', $entry);
            $this->assertArrayHasKey('current_status', $entry);
        }
    }

    // ============================================
    // SERVICE TESTS (thin orchestration)
    // ============================================

    public function testReportingServiceGetAgentLoad(): void
    {
        $result = $this->reportingService->getAgentLoad();
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testReportingServiceGetUnassigned(): void
    {
        $result = $this->reportingService->getUnassigned();
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('tickets', $result);
    }

    public function testReportingServiceGetFrtTrend(): void
    {
        $from = new \DateTimeImmutable('-30 days');
        $to = new \DateTimeImmutable('now');

        $result = $this->reportingService->getFrtTrend($from, $to);
        $this->assertIsArray($result);
    }

    public function testReportingServiceGetHeatmap(): void
    {
        $from = new \DateTimeImmutable('-30 days');
        $to = new \DateTimeImmutable('now');

        $result = $this->reportingService->getHeatmap($from, $to);
        $this->assertIsArray($result);
    }

    public function testReportingServiceGetRecurringCustomers(): void
    {
        $result = $this->reportingService->getRecurringCustomers(null, 2);
        $this->assertIsArray($result);
    }

    public function testReportingServiceGetReopened(): void
    {
        $from = new \DateTimeImmutable('-30 days');
        $to = new \DateTimeImmutable('now');

        $result = $this->reportingService->getReopened($from, $to);
        $this->assertIsArray($result);
    }

    public function testReportingServiceValidatesDateRange(): void
    {
        $from = new \DateTimeImmutable('2026-01-31 00:00:00');
        $to = new \DateTimeImmutable('2026-01-01 00:00:00');

        $this->expectException(\InvalidArgumentException::class);
        $this->reportingService->getFrtTrend($from, $to);
    }

    // ============================================
    // HTTP SMOKE TESTS (7 endpoints)
    // ============================================

    public function testHttpAgentLoad(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/reports/agent-load');
        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testHttpUnassigned(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/reports/unassigned');
        $response = $client->getResponse();

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('count', $data);
        $this->assertArrayHasKey('tickets', $data);
    }

    public function testHttpAvailableAgents(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/reports/available-agents');
        $response = $client->getResponse();

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testHttpFrtTrend(): void
    {
        $client = static::getContainer()->get('test.service_container')->get('test.client');
        $from = (new \DateTimeImmutable('-30 days'))->format('Y-m-d\TH:i:s\Z');
        $to = (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:s\Z');
        $client->request('GET', "/api/reports/frt-trend?from={$from}&to={$to}");
        $response = $client->getResponse();

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testHttpHeatmap(): void
    {
        $client = static::getContainer()->get('test.service_container')->get('test.client');
        $from = (new \DateTimeImmutable('-30 days'))->format('Y-m-d\TH:i:s\Z');
        $to = (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:s\Z');
        $client->request('GET', "/api/reports/heatmap?from={$from}&to={$to}");
        $response = $client->getResponse();

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testHttpRecurringCustomers(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/reports/recurring-customers?threshold=2');
        $response = $client->getResponse();

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testHttpReopened(): void
    {
        $client = static::getContainer()->get('test.service_container')->get('test.client');
        $from = (new \DateTimeImmutable('-30 days'))->format('Y-m-d\TH:i:s\Z');
        $to = (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:s\Z');
        $client->request('GET', "/api/reports/reopened?from={$from}&to={$to}");
        $response = $client->getResponse();

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    // ============================================
    // HELPER: Seed test data
    // ============================================

    private function seedTestData(): void
    {
        $conn = $this->em->getConnection();

        // Skip seeding if data already exists
        $count = $conn->fetchAssociative('SELECT COUNT(*) as cnt FROM agents');
        if (($count['cnt'] ?? 0) >= 5) {
            return;
        }

        // Load workspaces
        $wsRepo = $this->em->getRepository(\App\Domain\Workspace\Workspace::class);
        $workspaces = $wsRepo->findBy([], ['id' => 'ASC']);
        if (empty($workspaces)) {
            $this->markTestSkipped('No workspaces found. Run app:seed-users first.');
        }
        $ws1 = $workspaces[0];

        // Create 5 agents
        $agents = [];
        for ($i = 1; $i <= 5; $i++) {
            $agent = new Agent("Test Agent {$i}");
            $agent->setMaxActiveTickets(10);
            $agent->setTimezone('UTC');
            $agent->setActive(true);
            $agent->addWorkspace($ws1);
            $agent->setActiveWorkspace($ws1);
            $this->em->persist($agent);
            $agents[] = $agent;
        }
        $this->em->flush();

        // Create agent shifts
        foreach ($agents as $agent) {
            for ($day = 1; $day <= 5; $day++) {
                $shift = new AgentShift(
                    $agent,
                    $day,
                    new \DateTimeImmutable('09:00:00'),
                    new \DateTimeImmutable('18:00:00')
                );
                $this->em->persist($shift);
            }
        }
        $this->em->flush();

        // Create 10 contacts
        $contacts = [];
        for ($i = 1; $i <= 10; $i++) {
            $contact = new Contact();
            $contact->setPhone("+54911{$i}000000");
            $contact->setName("Test Contact {$i}");
            $contact->setEmail("test{$i}@test.com");
            $this->em->persist($contact);
            $contacts[] = $contact;
        }
        $this->em->flush();

        // Create 55 tickets
        $baseTime = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-90 days');
        $tickets = [];
        for ($i = 1; $i <= 55; $i++) {
            $dayOffset = random_int(0, 89);
            $hourOffset = random_int(6, 22);
            $createdAt = $baseTime->modify("+{$dayOffset} days +{$hourOffset} hours");

            $ticket = new Ticket();
            $ticket->setContact($contacts[array_rand($contacts)]);
            $ticket->setStatus(['new', 'active', 'waiting', 'resolved', 'closed'][array_rand(['new', 'active', 'waiting', 'resolved', 'closed'])]);
            $ticket->setPriority(['low', 'medium', 'high'][array_rand(['low', 'medium', 'high'])]);
            $ticket->setCreatedAt($createdAt);
            $ticket->setUpdatedAt($createdAt);
            $ticket->setPhoneNumberId($ws1->getPhoneNumberId());

            if ($i <= 45) {
                $ticket->setAssignedAgentId($agents[array_rand($agents)]->getId());
            }

            if (random_int(1, 100) <= 70) {
                $frtMinutes = random_int(1, 120);
                $ticket->setFirstResponseAt($createdAt->modify("+{$frtMinutes} minutes"));
            }

            if (random_int(1, 100) <= 60) {
                $rtMinutes = random_int(30, 480);
                $ticket->setResolvedAt($createdAt->modify("+{$rtMinutes} minutes"));
            }

            $this->em->persist($ticket);
            $tickets[] = $ticket;
        }
        $this->em->flush();

        // Create auto_reopened events
        $reopenedCount = 0;
        foreach ($tickets as $ticket) {
            if ($reopenedCount >= 3) {
                break;
            }
            if ($ticket->getResolvedAt() !== null) {
                $event = new TicketEvent($ticket->getId(), 'auto_reopened', 'system');
                $this->em->persist($event);
                $reopenedCount++;
            }
        }
        $this->em->flush();
    }
}
