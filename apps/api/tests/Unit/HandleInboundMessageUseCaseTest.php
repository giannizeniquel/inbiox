<?php

namespace App\Tests\Unit;

use App\Disponibilidad\DisponibilidadDeAgenteService;
use App\Automatizacion\RespuestaAutomaticaService;
use App\Compartido\Outbox\OutboxWriter;
use App\Compartido\Graph\GraphClient;
use App\Conversacion\UseCase\HandleInboundMessageUseCase;
use App\Ticket\UseCase\CambiarEstadoDeTicketUseCase;
use App\Automatizacion\EjecutorDeReglas;
use App\Disponibilidad\Repository\TurnoRepository;
use App\Compartido\EventCollector;
use App\Conversacion\ConversationWindowPolicy;
use App\Agente\Repository\AgenteRepository;
use App\Contacto\Repository\ContactoRepository;
use App\Conversacion\Repository\ConversacionRepository;
use App\Conversacion\Repository\MensajeRepository;
use App\Compartido\Repository\ProcessedEventRepository;
use App\Ticket\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class HandleInboundMessageUseCaseTest extends TestCase
{
    private EntityManagerInterface|MockObject $em;
    private ContactoRepository|MockObject $contactRepo;
    private ConversacionRepository|MockObject $conversationRepo;
    private MensajeRepository|MockObject $messageRepo;
    private TicketRepository|MockObject $ticketRepo;
    private EventDispatcherInterface|MockObject $eventDispatcher;
    private CambiarEstadoDeTicketUseCase|MockObject $transitionUseCase;
    private EjecutorDeReglas|MockObject $ruleExecutor;
    private EventCollector|MockObject $eventCollector;
    private RespuestaAutomaticaService $autoReplyService;
    private LoggerInterface|MockObject $logger;
    private HandleInboundMessageUseCase $useCase;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->contactRepo = $this->createMock(ContactoRepository::class);
        $this->conversationRepo = $this->createMock(ConversacionRepository::class);
        $this->messageRepo = $this->createMock(MensajeRepository::class);
        $this->ticketRepo = $this->createMock(TicketRepository::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->transitionUseCase = $this->createMock(CambiarEstadoDeTicketUseCase::class);
        $this->ruleExecutor = $this->createMock(EjecutorDeReglas::class);
        $this->eventCollector = $this->createMock(EventCollector::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Create a real RespuestaAutomaticaService with mocked deps (can't mock final class)
        $agentRepo = $this->createMock(AgenteRepository::class);
        $agentShiftRepo = $this->createMock(TurnoRepository::class);
        $availabilityService = new DisponibilidadDeAgenteService($agentRepo, $agentShiftRepo, $this->ticketRepo);
        $graphClient = $this->createMock(GraphClient::class);
        $processedEventRepo = $this->createMock(ProcessedEventRepository::class);
        $this->autoReplyService = new RespuestaAutomaticaService(
            $agentRepo,
            $availabilityService,
            $graphClient,
            $processedEventRepo,
            $this->eventCollector,
            $this->logger,
            $this->em,
            new ConversationWindowPolicy(safetyMarginMinutes: 5),
        );

        $this->useCase = new HandleInboundMessageUseCase(
            $this->em,
            $this->contactRepo,
            $this->conversationRepo,
            $this->messageRepo,
            $this->ticketRepo,
            $this->eventDispatcher,
            $this->transitionUseCase,
            $this->ruleExecutor,
            $this->eventCollector,
            new OutboxWriter($this->em),
            $this->autoReplyService,
            $this->logger,
        );
    }

    public function testProcessInboundMessageThrowsForInvalidPhone(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->processInboundMessage('123', 'Test');
    }

    public function testProcessInboundMessageThrowsForEmptyPhone(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->processInboundMessage('', 'Test message');
    }

    public function testProcessInboundMessageThrowsForShortPhone(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->processInboundMessage('+123', 'Test');
    }

    public function testPhoneNormalization(): void
    {
        // Digit extraction: strip non-digits, keep all digits including 9 prefix
        $phone = '+54 371 234 5678';
        $normalized = preg_replace('/[^0-9]/', '', $phone);
        $this->assertEquals('543712345678', $normalized);
        
        // With + prefix added
        $normalized = '+' . $normalized;
        $this->assertEquals('+543712345678', $normalized);
    }

    public function testPhone9PrefixPreserved(): void
    {
        // Argentine mobile with 9 prefix should keep it
        $phone = '+5491155555555';
        $normalized = preg_replace('/[^0-9]/', '', $phone);
        $this->assertEquals('5491155555555', $normalized, '549 prefix must be preserved');
        $this->assertStringStartsWith('549', $normalized);
    }

    public function testValidPhoneFormats(): void
    {
        $validPhones = [
            '+543712345678',
            '+5491155555555',
            '00543112345678',
        ];

        foreach ($validPhones as $phone) {
            $normalized = preg_replace('/[^0-9]/', '', $phone);
            $this->assertGreaterThanOrEqual(10, strlen($normalized), "Phone $phone should have at least 10 digits");
        }
    }

    /**
     * Configure the EM mock to auto-generate IDs on persisted entities when flushed.
     * Without this, real entities created inside executeInternal (Message, Conversation, Ticket)
     * would have null IDs after a mock flush, causing TypeError in event constructors.
     */
    private function enableEntityIdAutoGeneration(): void
    {
        $persisted = [];

        $this->em->method('persist')->willReturnCallback(function(object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->em->method('flush')->willReturnCallback(function() use (&$persisted): void {
            $nextId = 10000;
            foreach ($persisted as $entity) {
                $ref = new \ReflectionClass($entity);
                if ($ref->hasProperty('id')) {
                    $prop = $ref->getProperty('id');
                    $prop->setAccessible(true);
                    if ($prop->getValue($entity) === null) {
                        $prop->setValue($entity, $nextId++);
                    }
                }
            }
            $persisted = [];
        });
    }

    public function testDifferentWorkspaceCreatesNewConversationAndTicket(): void
    {
        $phone = '+543712345678';
        $message = 'Hello';
        $phoneNumberId = 'B';

        $contact = $this->createMock(\App\Contacto\Contacto::class);
        $contact->method('getId')->willReturn(1);
        $contact->method('getNombre')->willReturn(null);
        $contact->method('getActualizadoAt')->willReturn(new \DateTimeImmutable());

        // Make the EM transaction execute the closure
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('transactional')->willReturnCallback(fn(callable $cb) => $cb());
        $this->em->method('getConnection')->willReturn($connection);

        $this->enableEntityIdAutoGeneration();

        $this->contactRepo->method('findOneByTelefono')->willReturn($contact);

        // No open conversation in workspace B -> must create new
        $this->conversationRepo
            ->expects($this->once())
            ->method('findAbiertaByContacto')
            ->with($contact, 'B')
            ->willReturn(null);

        // No not-closed ticket in workspace B -> must create new
        $this->ticketRepo
            ->expects($this->once())
            ->method('findNoCerradoByContacto')
            ->with($contact, 'B')
            ->willReturn(null);

        // Stub required interactions
        $this->ruleExecutor->method('execute');
        $this->eventDispatcher->method('dispatch');
        $this->eventCollector->method('collect');

        $result = $this->useCase->processInboundMessage($phone, $message, null, $phoneNumberId);

        $this->assertArrayHasKey('status', $result);
        $this->assertEquals('ok', $result['status']);
    }

    public function testSameWorkspaceReusesExistingConversationAndTicket(): void
    {
        $phone = '+543712345678';
        $message = 'Hello again';
        $phoneNumberId = 'A';

        $contact = $this->createMock(\App\Contacto\Contacto::class);
        $contact->method('getId')->willReturn(1);
        $contact->method('getNombre')->willReturn(null);
        $contact->method('getActualizadoAt')->willReturn(new \DateTimeImmutable());

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('transactional')->willReturnCallback(fn(callable $cb) => $cb());
        $this->em->method('getConnection')->willReturn($connection);

        $this->enableEntityIdAutoGeneration();

        $this->contactRepo->method('findOneByTelefono')->willReturn($contact);

        $existingConversation = $this->createMock(\App\Conversacion\Conversacion::class);
        $existingConversation->method('getId')->willReturn(10);
        $existingConversation->method('getActualizadoAt')->willReturn(new \DateTimeImmutable());

        $existingTicket = $this->createMock(\App\Ticket\Ticket::class);
        $existingTicket->method('getId')->willReturn(20);
        $existingTicket->method('getEstado')->willReturn(\App\Ticket\EstadoDeTicket::ACTIVO->value);
        $existingTicket->method('getActualizadoAt')->willReturn(new \DateTimeImmutable());
        $existingTicket->method('incrementLastEventSeq')->willReturn(1);

        // Existing conversation + ticket in workspace A -> reused
        $this->conversationRepo
            ->expects($this->once())
            ->method('findAbiertaByContacto')
            ->with($contact, 'A')
            ->willReturn($existingConversation);

        $this->ticketRepo
            ->expects($this->once())
            ->method('findNoCerradoByContacto')
            ->with($contact, 'A')
            ->willReturn($existingTicket);

        // Stub required interactions
        $this->em->method('lock');
        $this->ruleExecutor->method('execute');
        $this->eventDispatcher->method('dispatch');
        $this->eventCollector->method('collect');

        $result = $this->useCase->processInboundMessage($phone, $message, null, $phoneNumberId);

        $this->assertArrayHasKey('status', $result);
        $this->assertEquals('ok', $result['status']);
    }

    public function testNullPhoneNumberIdPreservesLegacyBehavior(): void
    {
        $phone = '+543712345678';
        $message = 'Hello with null workspace';

        $contact = $this->createMock(\App\Contacto\Contacto::class);
        $contact->method('getId')->willReturn(1);
        $contact->method('getNombre')->willReturn(null);
        $contact->method('getActualizadoAt')->willReturn(new \DateTimeImmutable());

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('transactional')->willReturnCallback(fn(callable $cb) => $cb());
        $this->em->method('getConnection')->willReturn($connection);

        $this->enableEntityIdAutoGeneration();

        $this->contactRepo->method('findOneByTelefono')->willReturn($contact);

        $existingConversation = $this->createMock(\App\Conversacion\Conversacion::class);
        $existingConversation->method('getId')->willReturn(10);
        $existingConversation->method('getActualizadoAt')->willReturn(new \DateTimeImmutable());

        $existingTicket = $this->createMock(\App\Ticket\Ticket::class);
        $existingTicket->method('getId')->willReturn(20);
        $existingTicket->method('getEstado')->willReturn(\App\Ticket\EstadoDeTicket::ACTIVO->value);
        $existingTicket->method('getActualizadoAt')->willReturn(new \DateTimeImmutable());
        $existingTicket->method('incrementLastEventSeq')->willReturn(1);

        // Legacy: findAbiertaByContacto called with null -> finds regardless of workspace
        $this->conversationRepo
            ->expects($this->once())
            ->method('findAbiertaByContacto')
            ->with($contact, null)
            ->willReturn($existingConversation);

        $this->ticketRepo
            ->expects($this->once())
            ->method('findNoCerradoByContacto')
            ->with($contact, null)
            ->willReturn($existingTicket);

        // Stub required interactions
        $this->em->method('lock');
        $this->ruleExecutor->method('execute');
        $this->eventDispatcher->method('dispatch');
        $this->eventCollector->method('collect');

        $result = $this->useCase->processInboundMessage($phone, $message);

        $this->assertArrayHasKey('status', $result);
        $this->assertEquals('ok', $result['status']);
    }
}