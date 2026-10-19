<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Agente\Agente;
use App\Contacto\Contacto;
use App\Conversacion\Conversation;
use App\Ticket\Ticket;
use App\Administracion\User;
use App\Reporte\Repository\ReportingRepository;
use App\Reporte\Query\ReportingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for Agent Response Delay Metric & Ticket Participation
 * Report (openspec/changes/agent-response-delay-metric).
 *
 * Covers:
 * - Attribution cutoff detection
 * - Episode-partition scoping (reopened ticket, same conversation)
 * - Burst-based delay sampling + clock start/stop (automatic mid-run)
 * - Unanswered trailing runs
 * - Legacy NULL cutoff filtering (whole-stream exclusion, no poisoning)
 * - Degenerate tie edge case (documented limitation, not "fixed")
 * - Per-ticket participation (multi-agent, burst collapse, unattributed legacy)
 * - Controller: 200 partial_data=false, 404 unknown ticket, 401 unauthenticated
 *
 * Run: docker exec inbiox_php php bin/phpunit tests/Integration/ReportingResponseDelayTest.php
 *
 * Dual-dialect note: this repo's DB service in this environment is MySQL
 * only (no Postgres test connection available — `docker ps` shows no
 * project-scoped Postgres container). Every assertion here therefore
 * exercises the MySQL code path only. `getTimeDiffExpression()` is the only
 * dialect-varying fragment (see ReportingRepository); the CTE/window-function
 * bodies are otherwise byte-identical per design.md, but that portability
 * claim could not be independently re-verified against a live Postgres
 * connection in this environment — flagged as a known verification gap.
 */
class ReportingResponseDelayTest extends WebTestCase
{
    private const TEST_PASSWORD = 'test-password';

    private ?KernelBrowser $client = null;
    private ?EntityManagerInterface $em = null;
    private ?ReportingRepository $reportingRepo = null;
    private ?ReportingService $reportingService = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->reportingRepo = static::getContainer()->get(ReportingRepository::class);
        $this->reportingService = static::getContainer()->get(ReportingService::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
        $conn->executeStatement('DELETE FROM refresh_tokens');
        $conn->executeStatement('DELETE FROM agent_shifts');
        $conn->executeStatement('DELETE FROM agents');
        $conn->executeStatement('DELETE FROM `user`');

        $this->em->clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->client = null;
        $this->em = null;
    }

    // ────────────────────────────────────────────────────────
    //  Fixture helpers
    // ────────────────────────────────────────────────────────

    private function createAgent(string $name): Agente
    {
        $agent = new Agente($name);
        $agent->setMaxTicketsActivos(10);
        $agent->setActivo(true);
        $this->em->persist($agent);
        $this->em->flush();

        return $agent;
    }

    private function createConversation(): Conversation
    {
        $contact = new Contacto();
        $contact->setTelefono('+5491100000000');
        $contact->setNombre('Test Contact');
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $this->em->persist($conversation);
        $this->em->flush();

        return $conversation;
    }

    private function createTicket(Conversation $conversation, string $createdAt): Ticket
    {
        $ticket = new Ticket();
        $ticket->setContacto($conversation->getContact());
        $ticket->setConversacion($conversation);
        $ticket->setCreadoAt(new \DateTimeImmutable($createdAt));
        $this->em->persist($ticket);
        $this->em->flush();

        return $ticket;
    }

    /**
     * Raw SQL insert — Message has no setCreatedAt(), and legacy rows need
     * sender_type = NULL, which the entity's typed setter cannot express.
     */
    private function insertMessage(
        int $conversationId,
        string $direction,
        ?string $senderType,
        ?int $senderAgentId,
        string $createdAt,
        string $content = 'msg',
    ): void {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'INSERT INTO messages (conversation_id, direction, content, created_at, sender_agent_id, sender_type)
             VALUES (:conversationId, :direction, :content, :createdAt, :senderAgentId, :senderType)',
            [
                'conversationId' => $conversationId,
                'direction' => $direction,
                'content' => $content,
                'createdAt' => $createdAt,
                'senderAgentId' => $senderAgentId,
                'senderType' => $senderType,
            ]
        );
    }

    private function inbound(int $conversationId, string $createdAt): void
    {
        $this->insertMessage($conversationId, 'inbound', 'contact', null, $createdAt);
    }

    private function agentReply(int $conversationId, int $agentId, string $createdAt): void
    {
        $this->insertMessage($conversationId, 'outbound', 'agent', $agentId, $createdAt);
    }

    private function automatic(int $conversationId, string $createdAt): void
    {
        $this->insertMessage($conversationId, 'outbound', 'automatic', null, $createdAt);
    }

    private function legacy(int $conversationId, string $direction, string $createdAt): void
    {
        $this->insertMessage($conversationId, $direction, null, null, $createdAt);
    }

    // ────────────────────────────────────────────────────────
    //  Auth helpers (mirrors AuthorizationTest)
    // ────────────────────────────────────────────────────────

    private function createAuthenticatedAgentToken(): string
    {
        $agent = new Agente('Auth Agent');
        $user = new User();
        $user->setEmail('rd-agent@test.local');
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_AGENT']);
        $user->setIsActive(true);
        $agent->setUsuario($user);
        $this->em->persist($agent);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['email' => 'rd-agent@test.local', 'password' => self::TEST_PASSWORD]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);

        return $data['access_token'];
    }

    private function authHeaders(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    // ============================================
    // ATTRIBUTION CUTOFF
    // ============================================

    public function testGetAttributionCutoffReturnsEarliestAttributedMessage(): void
    {
        $conversation = $this->createConversation();
        $cid = $conversation->getId();

        $this->legacy($cid, 'inbound', '2026-06-01 08:00:00');
        $this->agentReply($cid, 1, '2026-06-01 09:00:00');
        $this->automatic($cid, '2026-06-01 08:30:00');
        $this->inbound($cid, '2026-06-01 08:45:00');

        $cutoff = $this->reportingRepo->getAttributionCutoff();

        self::assertNotNull($cutoff);
        self::assertSame('2026-06-01 08:30:00', $cutoff->format('Y-m-d H:i:s'));
    }

    // ============================================
    // EPISODE PARTITION SCOPING
    // ============================================

    public function testEpisodePartitionScopingReopenedTicketNoCrossEpisodePair(): void
    {
        $conversation = $this->createConversation();
        $cid = $conversation->getId();
        $agent = $this->createAgent('Agent One');

        // Ticket A: [09:00, 10:00) — a dangling inbound with no reply in ITS OWN window.
        $this->createTicket($conversation, '2026-06-01 09:00:00');
        $this->inbound($cid, '2026-06-01 09:10:00');

        // Ticket B: [10:00, ∞) — an unprompted agent message, then a real burst+reply.
        $this->createTicket($conversation, '2026-06-01 10:00:00');
        $this->agentReply($cid, $agent->getId(), '2026-06-01 10:05:00');
        $this->inbound($cid, '2026-06-01 10:10:00');
        $this->agentReply($cid, $agent->getId(), '2026-06-01 10:15:00');

        $from = new \DateTimeImmutable('2026-06-01 08:00:00');
        $to = new \DateTimeImmutable('2026-06-01 11:00:00');

        $result = $this->reportingRepo->getResponseDelayMetrics($from, $to);

        // If episodes were NOT scoped per-ticket, Ticket A's 09:10 inbound
        // would wrongly pair with the FIRST agent message in the whole
        // conversation stream (10:05) — that cross-episode pair must never
        // happen.
        self::assertSame(1, $result['aggregate']['sample_count']);
        self::assertSame(300.0, $result['aggregate']['avg_delay_seconds']); // 10:10 -> 10:15 = 5 min
        self::assertSame(1, $result['aggregate']['unanswered_inbound_count']); // Ticket A's dangling inbound

        self::assertCount(1, $result['per_agent']);
        self::assertSame($agent->getId(), $result['per_agent'][0]['agent_id']);
        self::assertSame(1, $result['per_agent'][0]['sample_count']);
        self::assertSame(300.0, $result['per_agent'][0]['avg_delay_seconds']);
    }

    // ============================================
    // BURST-BASED DELAY SAMPLING
    // ============================================

    public function testBurstOfThreeInboundThenOneAgentReplyProducesOneSample(): void
    {
        $conversation = $this->createConversation();
        $cid = $conversation->getId();
        $agent = $this->createAgent('Agent One');
        $this->createTicket($conversation, '2026-06-01 09:00:00');

        $this->inbound($cid, '2026-06-01 09:00:00'); // M1 — burst start
        $this->inbound($cid, '2026-06-01 09:01:00'); // M2
        $this->inbound($cid, '2026-06-01 09:02:00'); // M3
        $this->agentReply($cid, $agent->getId(), '2026-06-01 09:05:00'); // R

        $from = new \DateTimeImmutable('2026-06-01 08:00:00');
        $to = new \DateTimeImmutable('2026-06-01 10:00:00');
        $result = $this->reportingRepo->getResponseDelayMetrics($from, $to);

        // Exactly ONE sample, measured from the run's FIRST message (M1, 09:00) to R (09:05).
        self::assertSame(1, $result['aggregate']['sample_count']);
        self::assertSame(300.0, $result['aggregate']['avg_delay_seconds']);
        self::assertSame(0, $result['aggregate']['unanswered_inbound_count']);
    }

    public function testAutomaticMidRunDoesNotSplitBurstOrStopClock(): void
    {
        // Design.md worked trace: I1,I2,A1,I3,AU1,I4,A2,I5
        // reply_seq = 0,0,1,1,1,1,2,2
        // Bursts: (0)=[I1,I2]->A1, (1)=[I3,I4]->A2, (2)=[I5]->unanswered
        $conversation = $this->createConversation();
        $cid = $conversation->getId();
        $agent = $this->createAgent('Agent One');
        $this->createTicket($conversation, '2026-06-01 09:00:00');

        $this->inbound($cid, '2026-06-01 09:00:00');   // I1
        $this->inbound($cid, '2026-06-01 09:01:00');   // I2
        $this->agentReply($cid, $agent->getId(), '2026-06-01 09:02:00'); // A1
        $this->inbound($cid, '2026-06-01 09:03:00');   // I3
        $this->automatic($cid, '2026-06-01 09:04:00'); // AU1 — must not split burst or stop clock
        $this->inbound($cid, '2026-06-01 09:05:00');   // I4
        $this->agentReply($cid, $agent->getId(), '2026-06-01 09:06:00'); // A2
        $this->inbound($cid, '2026-06-01 09:07:00');   // I5 — trailing, unanswered

        $from = new \DateTimeImmutable('2026-06-01 08:00:00');
        $to = new \DateTimeImmutable('2026-06-01 10:00:00');
        $result = $this->reportingRepo->getResponseDelayMetrics($from, $to);

        // Burst 0 = [I1,I2] -> A1 (09:00 -> 09:02 = 120s)
        // Burst 1 = [I3,I4] -> A2 (09:03 -> 09:06 = 180s); AU1 neither split it nor stopped the clock
        // Burst 2 = [I5] -> unanswered
        self::assertSame(2, $result['aggregate']['sample_count']);
        self::assertSame(150.0, $result['aggregate']['avg_delay_seconds']); // (120+180)/2
        self::assertSame(1, $result['aggregate']['unanswered_inbound_count']);
    }

    public function testUnansweredTrailingRunExcludedFromAvgAndCountedInUnanswered(): void
    {
        $conversation = $this->createConversation();
        $cid = $conversation->getId();
        $this->createTicket($conversation, '2026-06-01 09:00:00');

        $this->inbound($cid, '2026-06-01 09:00:00'); // never answered

        $from = new \DateTimeImmutable('2026-06-01 08:00:00');
        $to = new \DateTimeImmutable('2026-06-01 10:00:00');
        $result = $this->reportingRepo->getResponseDelayMetrics($from, $to);

        self::assertSame(0, $result['aggregate']['sample_count']);
        self::assertNull($result['aggregate']['avg_delay_seconds']); // never-zero rule
        self::assertSame(1, $result['aggregate']['unanswered_inbound_count']);
        self::assertSame([], $result['per_agent']);
    }

    // ============================================
    // LEGACY NULL CUTOFF
    // ============================================

    public function testLegacyNullCutoffExcludedFromWholeStreamAndNeverPoisonsUnanswered(): void
    {
        $conversation = $this->createConversation();
        $cid = $conversation->getId();
        $agent = $this->createAgent('Agent One');
        $this->createTicket($conversation, '2026-06-01 07:00:00');

        // Pre-cutoff legacy rows — sender_type IS NULL. An outbound legacy row
        // has is_agent=0 in naive logic and would look like an "unanswered"
        // inbound run's non-reply if not excluded from the whole stream.
        $this->legacy($cid, 'inbound', '2026-06-01 07:00:00');
        $this->legacy($cid, 'outbound', '2026-06-01 07:05:00');

        // First attributed message establishes the cutoff.
        $this->inbound($cid, '2026-06-01 08:00:00');
        $this->agentReply($cid, $agent->getId(), '2026-06-01 08:05:00');

        $cutoff = $this->reportingRepo->getAttributionCutoff();
        self::assertNotNull($cutoff);
        self::assertSame('2026-06-01 08:00:00', $cutoff->format('Y-m-d H:i:s'));

        $from = new \DateTimeImmutable('2026-06-01 06:00:00');
        $to = new \DateTimeImmutable('2026-06-01 09:00:00');
        $result = $this->reportingRepo->getResponseDelayMetrics($from, $to);

        // The pre-cutoff legacy pair must be entirely excluded — not counted
        // as unanswered, not counted as a sample.
        self::assertSame(1, $result['aggregate']['sample_count']);
        self::assertSame(300.0, $result['aggregate']['avg_delay_seconds']);
        self::assertSame(0, $result['aggregate']['unanswered_inbound_count']);

        // Service-level partial_data: range starts before the cutoff.
        $serviceResult = $this->reportingService->getResponseDelay($from, $to);
        self::assertTrue($serviceResult['partial_data']);
        self::assertSame('2026-06-01 08:00:00', $serviceResult['data_available_from']);

        // Range fully within attributed data (from === cutoff) -> partial_data false.
        $fullyAttributedResult = $this->reportingService->getResponseDelay(
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            $to
        );
        self::assertFalse($fullyAttributedResult['partial_data']);
    }

    // ============================================
    // DEGENERATE TIE (accepted limitation — not "fixed")
    // ============================================

    public function testDegenerateTieSameConversationSameCreatedAtIsADocumentedLimitation(): void
    {
        $conversation = $this->createConversation();
        $cid = $conversation->getId();
        $agent = $this->createAgent('Agent One');

        // Two tickets, same conversation, IDENTICAL created_at.
        $tieTimestamp = '2026-06-01 09:00:00';
        $ticket1 = $this->createTicket($conversation, $tieTimestamp);
        $ticket2 = $this->createTicket($conversation, $tieTimestamp);

        // Tie-break is (created_at, id): ticket1 (lower id) sorts first, so
        // LEAD() gives it episode_end = ticket2's created_at = the SAME
        // timestamp -> a zero-width episode [T, T). No message can ever
        // satisfy `created_at >= T AND created_at < T`, so ticket1 captures
        // NOTHING — every message at T (and after) attributes to ticket2,
        // the open-ended episode. This is the accepted, documented
        // limitation from design.md, not something this test "fixes".
        $this->inbound($cid, $tieTimestamp);
        $this->agentReply($cid, $agent->getId(), '2026-06-01 09:05:00');

        $ticket1Participation = $this->reportingRepo->getTicketParticipation($ticket1->getId());
        $ticket2Participation = $this->reportingRepo->getTicketParticipation($ticket2->getId());

        self::assertNotNull($ticket1Participation);
        self::assertNotNull($ticket2Participation);

        // Ticket1's zero-width episode captures nothing.
        self::assertSame([], $ticket1Participation['pairs']);
        self::assertSame([], $ticket1Participation['participants']);

        // Ticket2 (open-ended episode) captures the pair instead.
        self::assertCount(1, $ticket2Participation['pairs']);
        self::assertSame(300.0, $ticket2Participation['pairs'][0]['delay_seconds']);
    }

    // ============================================
    // TICKET PARTICIPATION
    // ============================================

    public function testGetTicketParticipationMultiAgentAndBurst(): void
    {
        $conversation = $this->createConversation();
        $cid = $conversation->getId();
        $agentX = $this->createAgent('Agent X');
        $agentY = $this->createAgent('Agent Y');
        $ticket = $this->createTicket($conversation, '2026-06-01 09:00:00');

        // Burst of 3 -> agent X
        $this->inbound($cid, '2026-06-01 09:00:00');
        $this->inbound($cid, '2026-06-01 09:01:00');
        $this->inbound($cid, '2026-06-01 09:02:00');
        $this->agentReply($cid, $agentX->getId(), '2026-06-01 09:05:00');

        // Single inbound -> agent Y
        $this->inbound($cid, '2026-06-01 09:10:00');
        $this->agentReply($cid, $agentY->getId(), '2026-06-01 09:12:00');

        $result = $this->reportingRepo->getTicketParticipation($ticket->getId());

        self::assertNotNull($result);
        self::assertCount(2, $result['pairs']); // one entry for the 3-message run, not three
        self::assertCount(2, $result['participants']);

        $byAgentId = [];
        foreach ($result['participants'] as $p) {
            $byAgentId[$p['agent_id']] = $p;
        }

        self::assertSame(1, $byAgentId[$agentX->getId()]['message_count']);
        self::assertSame(1, $byAgentId[$agentX->getId()]['sample_count']);
        self::assertSame(300.0, $byAgentId[$agentX->getId()]['avg_delay_seconds']);

        self::assertSame(1, $byAgentId[$agentY->getId()]['message_count']);
        self::assertSame(1, $byAgentId[$agentY->getId()]['sample_count']);
        self::assertSame(120.0, $byAgentId[$agentY->getId()]['avg_delay_seconds']);
    }

    public function testGetTicketParticipationUnattributedLegacyMessagesExcludedAndPartialDataTrue(): void
    {
        $conversation = $this->createConversation();
        $cid = $conversation->getId();
        $agent = $this->createAgent('Agent One');
        $ticket = $this->createTicket($conversation, '2026-06-01 07:00:00');

        // Legacy row inside this ticket's own episode.
        $this->legacy($cid, 'inbound', '2026-06-01 07:00:00');

        // Attributed pair — also establishes the global cutoff.
        $this->inbound($cid, '2026-06-01 08:00:00');
        $this->agentReply($cid, $agent->getId(), '2026-06-01 08:05:00');

        $result = $this->reportingRepo->getTicketParticipation($ticket->getId());

        self::assertNotNull($result);
        self::assertTrue($result['partial_data']);
        self::assertCount(1, $result['pairs']); // legacy row produces no pair
        self::assertCount(1, $result['participants']);
        self::assertSame(1, $result['participants'][0]['sample_count']);
    }

    public function testGetTicketParticipationReturnsNullForUnknownTicket(): void
    {
        $result = $this->reportingRepo->getTicketParticipation(999999999);

        self::assertNull($result);
    }

    public function testGetTicketParticipationAutomaticMessagesCountedSeparatelyNotAttributedToAgent(): void
    {
        // Mirrors design.md's worked trace (I1,I2,A1,I3,AU1,I4,AU2... here
        // adapted to two automatic messages) but exercised at the per-ticket
        // participation level, not just the aggregate response-delay level.
        $conversation = $this->createConversation();
        $cid = $conversation->getId();
        $agentX = $this->createAgent('Agent X');
        $ticket = $this->createTicket($conversation, '2026-06-01 09:00:00');

        // Burst of 2 -> agent reply.
        $this->inbound($cid, '2026-06-01 09:00:00');   // I1
        $this->inbound($cid, '2026-06-01 09:01:00');   // I2
        $this->agentReply($cid, $agentX->getId(), '2026-06-01 09:02:00'); // A1

        // Automatic reply right after A1 — must not be attributed to agentX,
        // must not appear as a pair, and must not stop/restart the clock.
        $this->automatic($cid, '2026-06-01 09:03:00'); // AU1

        // Next inbound run, another automatic reply mid-run before the real
        // agent reply — must not split the burst or stop the clock either.
        $this->inbound($cid, '2026-06-01 09:04:00');   // I3
        $this->automatic($cid, '2026-06-01 09:05:00'); // AU2
        $this->agentReply($cid, $agentX->getId(), '2026-06-01 09:07:00'); // A2

        $result = $this->reportingRepo->getTicketParticipation($ticket->getId());

        self::assertNotNull($result);

        // Both automatic messages counted as activity, and ONLY as activity.
        self::assertSame(2, $result['automatic_message_count']);

        // Exactly 2 pairs (one per burst) — automatic messages never create
        // or consume a pair entry of their own.
        self::assertCount(2, $result['pairs']);
        foreach ($result['pairs'] as $pair) {
            self::assertNotNull($pair['agent_id']);
        }

        // Delay math is untouched by the automatic messages: burst 0
        // (09:00 -> 09:02 = 120s), burst 1 (09:04 -> 09:07 = 180s).
        self::assertSame(120.0, $result['pairs'][0]['delay_seconds']);
        self::assertSame(180.0, $result['pairs'][1]['delay_seconds']);

        // Only agentX participates; message_count reflects the 2 agent
        // replies only — the 2 automatic messages must not inflate it.
        self::assertCount(1, $result['participants']);
        $participant = $result['participants'][0];
        self::assertSame($agentX->getId(), $participant['agent_id']);
        self::assertSame(2, $participant['message_count']);
        self::assertSame(2, $participant['sample_count']);
        self::assertSame(150.0, $participant['avg_delay_seconds']); // (120+180)/2
        self::assertSame(0, $result['unanswered_inbound_count']);
    }

    // ============================================
    // CONTROLLER (HTTP)
    // ============================================

    public function testHttpResponseDelayReturns200WithPartialDataFalseWhenFullyAttributed(): void
    {
        $conversation = $this->createConversation();
        $cid = $conversation->getId();
        $agent = $this->createAgent('Agent One');
        $this->createTicket($conversation, '2026-06-01 09:00:00');
        $this->inbound($cid, '2026-06-01 09:00:00');
        $this->agentReply($cid, $agent->getId(), '2026-06-01 09:05:00');

        $token = $this->createAuthenticatedAgentToken();

        $this->client->request(
            'GET',
            '/api/reports/response-delay?from=2026-06-01T09:00:00Z&to=2026-06-01T10:00:00Z',
            [],
            [],
            $this->authHeaders($token)
        );

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertArrayHasKey('aggregate', $data);
        self::assertArrayHasKey('per_agent', $data);
        self::assertArrayHasKey('data_available_from', $data);
        self::assertFalse($data['partial_data']);
        self::assertSame(1, $data['aggregate']['sample_count']);
    }

    public function testHttpTicketParticipation404ForUnknownTicket(): void
    {
        $token = $this->createAuthenticatedAgentToken();

        $this->client->request('GET', '/api/reports/tickets/999999999/participation', [], [], $this->authHeaders($token));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testHttpResponseDelayUnauthenticatedReturns401(): void
    {
        $this->client->request('GET', '/api/reports/response-delay?from=2026-06-01T00:00:00Z&to=2026-06-02T00:00:00Z');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testHttpTicketParticipationUnauthenticatedReturns401(): void
    {
        $this->client->request('GET', '/api/reports/tickets/1/participation');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}
