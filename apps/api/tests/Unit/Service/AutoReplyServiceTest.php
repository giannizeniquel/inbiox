<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Disponibilidad\DisponibilidadDeAgenteService;
use App\Automatizacion\RespuestaAutomaticaService;
use App\Compartido\Graph\GraphClient;
use App\Agente\Agente;
use App\Disponibilidad\Turno;
use App\Disponibilidad\Repository\TurnoRepository;
use App\Contacto\Contacto;
use App\Conversacion\Conversation;
use App\Compartido\EventCollector;
use App\Conversacion\ConversationWindowPolicy;
use App\Conversacion\Message;
use App\Conversacion\MessageSenderType;
use App\Compartido\ProcessedEvent;
use App\Ticket\Ticket;
use App\Compartido\Graph\GraphApiException;
use App\Agente\Repository\AgenteRepository;
use App\Ticket\Repository\TicketRepository;
use App\Compartido\Repository\ProcessedEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RespuestaAutomaticaService::enviarSiCorresponde.
 *
 * Coverage:
 * 1. Does NOT send when auto_reply_enabled = false
 * 2. Does NOT send when auto_reply_message is null/empty
 * 3. Does NOT send when agent is available
 * 4. Does NOT send when rate limited
 * 5. Sends when all conditions met
 * 6. GraphClient exception is caught silently
 *
 * Uses a real DisponibilidadDeAgenteService with mocked repository dependencies
 * to avoid PHPUnit's restriction on mocking final classes.
 */
class AutoReplyServiceTest extends TestCase
{
    private AgenteRepository&MockObject $agentRepo;
    private TurnoRepository&MockObject $agentShiftRepo;
    private EntityManagerInterface&MockObject $em;
    private TicketRepository&MockObject $ticketRepo;
    private DisponibilidadDeAgenteService $availabilityService;
    private GraphClient&MockObject $graphClient;
    private ProcessedEventRepository&MockObject $processedEventRepo;
    private EventCollector&MockObject $eventCollector;
    private LoggerInterface&MockObject $logger;
    private ConversationWindowPolicy $windowPolicy;
    private RespuestaAutomaticaService $service;

    protected function setUp(): void
    {
        $this->agentRepo = $this->createMock(AgenteRepository::class);
        $this->agentShiftRepo = $this->createMock(TurnoRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->graphClient = $this->createMock(GraphClient::class);
        $this->processedEventRepo = $this->createMock(ProcessedEventRepository::class);
        $this->eventCollector = $this->createMock(EventCollector::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Create REAL DisponibilidadDeAgenteService with mocked deps
        $this->ticketRepo = $this->createMock(TicketRepository::class);
        $this->availabilityService = new DisponibilidadDeAgenteService(
            $this->agentRepo,
            $this->agentShiftRepo,
            $this->ticketRepo,
        );

        $this->windowPolicy = new ConversationWindowPolicy(safetyMarginMinutes: 5);

        $this->service = new RespuestaAutomaticaService(
            $this->agentRepo,
            $this->availabilityService,
            $this->graphClient,
            $this->processedEventRepo,
            $this->eventCollector,
            $this->logger,
            $this->em,
            $this->windowPolicy,
        );
    }

    // ── Helpers ──

    private function createAgent(
        int $id = 1,
        bool $active = true,
        bool $autoReplyEnabled = true,
        ?string $autoReplyMessage = 'We are away right now.',
    ): Agente {
        $agent = new Agente('Agent-'.$id);
        $agent->setActive($active);
        $agent->setAutoReplyEnabled($autoReplyEnabled);
        $agent->setAutoReplyMessage($autoReplyMessage);

        // Set id via reflection
        $prop = new \ReflectionProperty($agent, 'id');
        $prop->setAccessible(true);
        $prop->setValue($agent, $id);

        return $agent;
    }

    private function createContact(int $id = 1, string $phone = '+5493625123456'): Contacto
    {
        $contact = new Contacto();
        $contact->setTelefono($phone);

        $prop = new \ReflectionProperty($contact, 'id');
        $prop->setAccessible(true);
        $prop->setValue($contact, $id);

        return $contact;
    }

    private function createTicket(
        ?Agente $agent = null,
        ?Contacto $contact = null,
        ?string $phoneNumberId = '123456789',
        ?Conversation $conversation = null,
        ?\DateTimeImmutable $lastInboundMessageAt = new \DateTimeImmutable('-1 hour'),
    ): Ticket {
        $ticket = new Ticket();
        if ($agent !== null) {
            $ticket->setAgenteAsignadoId((int) $agent->getId());
        }
        if ($contact !== null) {
            $ticket->setContacto($contact);
        }
        if ($phoneNumberId !== null) {
            $ticket->setPhoneNumberId($phoneNumberId);
        }
        if ($conversation !== null) {
            $ticket->setConversacion($conversation);
        }
        $ticket->setUltimoMensajeEntranteAt($lastInboundMessageAt);

        return $ticket;
    }

    /**
     * Make the agent "available" by giving it a shift at the current hour
     * AND ensuring capacity is below max.
     *
     * The agent's local time is UTC. A shift on the current DOW that covers
     * the current time will make isAvailable() pass step 3 (schedule).
     */
    private function makeAgentAvailable(Agente $agent): void
    {
        $now = new \DateTimeImmutable('now');
        $dow = (int) $now->format('N');
        $hour = (int) $now->format('H');

        // Create a shift that covers this hour
        $startTime = \DateTimeImmutable::createFromFormat('H:i', \sprintf('%02d:00', \max(0, $hour - 1)));
        $endTime = \DateTimeImmutable::createFromFormat('H:i', \sprintf('%02d:00', \min(23, $hour + 2)));
        \assert($startTime !== false && $endTime !== false);

        $shift = new Turno($agent, $dow, $startTime, $endTime);
        $agent->addShift($shift);
    }

    /**
     * Return a query mock that returns a ticket count.
     */
    private function mockTicketCountQuery(int $count): Query&MockObject
    {
        $query = $this->createMock(Query::class);
        $query->expects(self::once())
            ->method('getSingleScalarResult')
            ->willReturn((string) $count);
        $query->expects(self::exactly(2))
            ->method('setParameter')
            ->willReturnSelf();

        return $query;
    }

    // ────────────────────────────────────────────────────────────
    //  Test 1: Auto-reply disabled
    // ────────────────────────────────────────────────────────────

    public function testDoesNotSendWhenAutoReplyDisabled(): void
    {
        $agent = $this->createAgent(autoReplyEnabled: false);
        $contact = $this->createContact();
        $ticket = $this->createTicket(agent: $agent, contact: $contact);

        $this->agentRepo->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn($agent);

        $this->graphClient->expects(self::never())->method('sendMessage');

        $this->service->enviarSiCorresponde($ticket);
    }

    // ────────────────────────────────────────────────────────────
    //  Test 2: No message
    // ────────────────────────────────────────────────────────────

    public function testDoesNotSendWhenMessageIsNull(): void
    {
        $agent = $this->createAgent(autoReplyMessage: null);
        $contact = $this->createContact();
        $ticket = $this->createTicket(agent: $agent, contact: $contact);

        $this->agentRepo->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn($agent);

        $this->graphClient->expects(self::never())->method('sendMessage');

        $this->service->enviarSiCorresponde($ticket);
    }

    public function testDoesNotSendWhenMessageIsEmpty(): void
    {
        $agent = $this->createAgent(autoReplyMessage: '');
        $contact = $this->createContact();
        $ticket = $this->createTicket(agent: $agent, contact: $contact);

        $this->agentRepo->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn($agent);

        $this->graphClient->expects(self::never())->method('sendMessage');

        $this->service->enviarSiCorresponde($ticket);
    }

    // ────────────────────────────────────────────────────────────
    //  Test 3: Agent is available → no send
    // ────────────────────────────────────────────────────────────

    public function testDoesNotSendWhenAgentIsAvailable(): void
    {
        $agent = $this->createAgent();
        $this->makeAgentAvailable($agent);
        $contact = $this->createContact();
        $ticket = $this->createTicket(agent: $agent, contact: $contact);

        $this->agentRepo->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn($agent);

        // Make the COUNT query return a value below max (capacity check)
        $this->ticketRepo->expects(self::once())
            ->method('countActivosDeAgente')
            ->willReturn(2); // max = 5, count = 2 → available

        $this->graphClient->expects(self::never())->method('sendMessage');

        $this->service->enviarSiCorresponde($ticket);
    }

    // ────────────────────────────────────────────────────────────
    //  Test 4: Rate limited
    // ────────────────────────────────────────────────────────────

    public function testDoesNotSendWhenRateLimited(): void
    {
        $agent = $this->createAgent();
        $contact = $this->createContact();
        $ticket = $this->createTicket(agent: $agent, contact: $contact);

        $this->agentRepo->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn($agent);

        // Simulate existing ProcessedEvent (recent — within 4h window)
        $recentEvent = new ProcessedEvent('auto-reply:1');
        $recentProp = new \ReflectionProperty($recentEvent, 'processedAt');
        $recentProp->setAccessible(true);
        $recentProp->setValue($recentEvent, new \DateTimeImmutable('-1 hour')); // 1 hour ago

        $this->processedEventRepo->expects(self::once())
            ->method('findByEventId')
            ->with('auto-reply:1')
            ->willReturn($recentEvent);

        $this->graphClient->expects(self::never())->method('sendMessage');

        $this->service->enviarSiCorresponde($ticket);
    }

    // ────────────────────────────────────────────────────────────
    //  Test 5: All conditions met → sends
    // ────────────────────────────────────────────────────────────

    public function testSendsWhenAllConditionsMet(): void
    {
        $agent = $this->createAgent();
        $contact = $this->createContact(phone: '+5493625123456');
        $conversation = new Conversation();
        $conversation->setContact($contact);
        $conversation->setStatus('open');
        $ticket = $this->createTicket(agent: $agent, contact: $contact, phoneNumberId: '123456789', conversation: $conversation);

        $this->agentRepo->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn($agent);

        // No recent processed event (called twice: rate-limit check + upsert)
        $this->processedEventRepo->expects(self::exactly(2))
            ->method('findByEventId')
            ->with('auto-reply:1')
            ->willReturn(null);

        // GraphClient called once (any args — we check the call happens)
        $this->graphClient->expects(self::once())
            ->method('sendMessage')
            ->willReturn(['messages' => [['id' => 'wamid.test']]]);

        // Message + EventCollection should be persisted
        $this->em->method('persist');
        $this->em->method('flush');

        $this->service->enviarSiCorresponde($ticket);
    }

    // ────────────────────────────────────────────────────────────
    //  Test 6: GraphClient exception is caught
    // ────────────────────────────────────────────────────────────

    public function testGraphClientExceptionIsCaughtSilently(): void
    {
        $agent = $this->createAgent();
        $contact = $this->createContact(phone: '+5493625123456');
        $ticket = $this->createTicket(agent: $agent, contact: $contact);

        $this->agentRepo->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn($agent);

        $this->processedEventRepo->expects(self::once())
            ->method('findByEventId')
            ->willReturn(null);

        // GraphClient throws
        $this->graphClient->expects(self::once())
            ->method('sendMessage')
            ->willThrowException(new GraphApiException('API error', 400));

        // Logger should get the error
        $this->logger->expects(self::atLeastOnce())
            ->method('error')
            ->with(self::stringContains('AutoReply Graph API error'));

        // ProcessedEvent should NOT be persisted (send failed)
        // Message is also NOT persisted because the send failed
        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');

        // This should not throw
        $this->service->enviarSiCorresponde($ticket);

        // Assertion: we reached here without exception
        $this->assertTrue(true);
    }

    // ────────────────────────────────────────────────────────────
    //  Test 8 (WhatsApp 24h window compliance): expired window → no send
    // ────────────────────────────────────────────────────────────

    public function testDoesNotSendWhenConversationWindowHasExpired(): void
    {
        $agent = $this->createAgent();
        $contact = $this->createContact(phone: '+5493625123456');
        $ticket = $this->createTicket(
            agent: $agent,
            contact: $contact,
            lastInboundMessageAt: new \DateTimeImmutable('-25 hours'),
        );

        $this->agentRepo->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn($agent);

        $this->processedEventRepo->expects(self::once())
            ->method('findByEventId')
            ->willReturn(null);

        // Fire-and-forget: early return, not an error — no send, no persist.
        $this->graphClient->expects(self::never())->method('sendMessage');
        $this->em->expects(self::never())->method('persist');

        $this->logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('window'));

        $this->service->enviarSiCorresponde($ticket);
    }

    // ────────────────────────────────────────────────────────────
    //  Attribution (message-agent-attribution): sender_agent_id + AUTOMATIC
    // ────────────────────────────────────────────────────────────

    public function testSetsAutomaticAttributionOnTheAutoReplyMessage(): void
    {
        $agent = $this->createAgent(id: 7);
        $contact = $this->createContact(phone: '+5493625123456');
        $conversation = new Conversation();
        $conversation->setContact($contact);
        $conversation->setStatus('open');
        $ticket = $this->createTicket(agent: $agent, contact: $contact, phoneNumberId: '123456789', conversation: $conversation);

        $this->agentRepo->expects(self::once())
            ->method('findById')
            ->with(7)
            ->willReturn($agent);

        $this->processedEventRepo->expects(self::exactly(2))
            ->method('findByEventId')
            ->with('auto-reply:1')
            ->willReturn(null);

        $this->graphClient->expects(self::once())
            ->method('sendMessage')
            ->willReturn(['messages' => [['id' => 'wamid.test']]]);

        $persistedMessage = null;
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$persistedMessage) {
            if ($entity instanceof Message) {
                $persistedMessage = $entity;
            }
        });
        $this->em->method('flush');

        $this->service->enviarSiCorresponde($ticket);

        self::assertNotNull($persistedMessage, 'AutoReplyService must persist a Message entity');
        self::assertSame(7, $persistedMessage->getSenderAgentId());
        self::assertSame(MessageSenderType::AUTOMATIC->value, $persistedMessage->getSenderType());
    }

    // ────────────────────────────────────────────────────────────
    //  Test 7: No assigned agent → no send
    // ────────────────────────────────────────────────────────────

    public function testDoesNotSendWhenNoAssignedAgent(): void
    {
        $ticket = $this->createTicket(agent: null, contact: $this->createContact());

        $this->agentRepo->expects(self::never())->method('findById');
        $this->graphClient->expects(self::never())->method('sendMessage');

        $this->service->enviarSiCorresponde($ticket);
    }
}
