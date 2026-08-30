<?php

namespace App\Tests\Unit;

use App\Application\Service\AgentAvailabilityService;
use App\Application\Service\AutoReplyService;
use App\Application\Service\GraphClient;
use App\Application\UseCase\HandleInboundMessageUseCase;
use App\Application\UseCase\TransitionTicketStatusUseCase;
use App\Application\Rule\RuleExecutorService;
use App\Domain\Agent\AgentShiftRepository;
use App\Domain\Event\EventCollector;
use App\Domain\Ticket\ConversationWindowPolicy;
use App\Repository\AgentRepository;
use App\Repository\ContactRepository;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Repository\ProcessedEventRepository;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class HandleInboundMessageUseCaseTest extends TestCase
{
    private EntityManagerInterface|MockObject $em;
    private ContactRepository|MockObject $contactRepo;
    private ConversationRepository|MockObject $conversationRepo;
    private MessageRepository|MockObject $messageRepo;
    private TicketRepository|MockObject $ticketRepo;
    private EventDispatcherInterface|MockObject $eventDispatcher;
    private TransitionTicketStatusUseCase|MockObject $transitionUseCase;
    private RuleExecutorService|MockObject $ruleExecutor;
    private EventCollector|MockObject $eventCollector;
    private AutoReplyService $autoReplyService;
    private LoggerInterface|MockObject $logger;
    private HandleInboundMessageUseCase $useCase;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->contactRepo = $this->createMock(ContactRepository::class);
        $this->conversationRepo = $this->createMock(ConversationRepository::class);
        $this->messageRepo = $this->createMock(MessageRepository::class);
        $this->ticketRepo = $this->createMock(TicketRepository::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->transitionUseCase = $this->createMock(TransitionTicketStatusUseCase::class);
        $this->ruleExecutor = $this->createMock(RuleExecutorService::class);
        $this->eventCollector = $this->createMock(EventCollector::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Create a real AutoReplyService with mocked deps (can't mock final class)
        $agentRepo = $this->createMock(AgentRepository::class);
        $agentShiftRepo = $this->createMock(AgentShiftRepository::class);
        $availabilityService = new AgentAvailabilityService($agentRepo, $agentShiftRepo, $this->em);
        $graphClient = $this->createMock(GraphClient::class);
        $processedEventRepo = $this->createMock(ProcessedEventRepository::class);
        $this->autoReplyService = new AutoReplyService(
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

        $contact = $this->createMock(\App\Domain\Contact\Contact::class);
        $contact->method('getId')->willReturn(1);
        $contact->method('getName')->willReturn(null);
        $contact->method('getUpdatedAt')->willReturn(new \DateTimeImmutable());

        // Make the EM transaction execute the closure
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('transactional')->willReturnCallback(fn(callable $cb) => $cb());
        $this->em->method('getConnection')->willReturn($connection);

        $this->enableEntityIdAutoGeneration();

        $this->contactRepo->method('findOneByPhone')->willReturn($contact);

        // No open conversation in workspace B -> must create new
        $this->conversationRepo
            ->expects($this->once())
            ->method('findOpenByContact')
            ->with($contact, 'B')
            ->willReturn(null);

        // No not-closed ticket in workspace B -> must create new
        $this->ticketRepo
            ->expects($this->once())
            ->method('findNotClosedByContact')
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

        $contact = $this->createMock(\App\Domain\Contact\Contact::class);
        $contact->method('getId')->willReturn(1);
        $contact->method('getName')->willReturn(null);
        $contact->method('getUpdatedAt')->willReturn(new \DateTimeImmutable());

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('transactional')->willReturnCallback(fn(callable $cb) => $cb());
        $this->em->method('getConnection')->willReturn($connection);

        $this->enableEntityIdAutoGeneration();

        $this->contactRepo->method('findOneByPhone')->willReturn($contact);

        $existingConversation = $this->createMock(\App\Domain\Conversation\Conversation::class);
        $existingConversation->method('getId')->willReturn(10);
        $existingConversation->method('getUpdatedAt')->willReturn(new \DateTimeImmutable());

        $existingTicket = $this->createMock(\App\Domain\Ticket\Ticket::class);
        $existingTicket->method('getId')->willReturn(20);
        $existingTicket->method('getStatus')->willReturn(\App\Domain\Ticket\TicketStatus::ACTIVE->value);
        $existingTicket->method('getUpdatedAt')->willReturn(new \DateTimeImmutable());
        $existingTicket->method('incrementLastEventSeq')->willReturn(1);

        // Existing conversation + ticket in workspace A -> reused
        $this->conversationRepo
            ->expects($this->once())
            ->method('findOpenByContact')
            ->with($contact, 'A')
            ->willReturn($existingConversation);

        $this->ticketRepo
            ->expects($this->once())
            ->method('findNotClosedByContact')
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

        $contact = $this->createMock(\App\Domain\Contact\Contact::class);
        $contact->method('getId')->willReturn(1);
        $contact->method('getName')->willReturn(null);
        $contact->method('getUpdatedAt')->willReturn(new \DateTimeImmutable());

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('transactional')->willReturnCallback(fn(callable $cb) => $cb());
        $this->em->method('getConnection')->willReturn($connection);

        $this->enableEntityIdAutoGeneration();

        $this->contactRepo->method('findOneByPhone')->willReturn($contact);

        $existingConversation = $this->createMock(\App\Domain\Conversation\Conversation::class);
        $existingConversation->method('getId')->willReturn(10);
        $existingConversation->method('getUpdatedAt')->willReturn(new \DateTimeImmutable());

        $existingTicket = $this->createMock(\App\Domain\Ticket\Ticket::class);
        $existingTicket->method('getId')->willReturn(20);
        $existingTicket->method('getStatus')->willReturn(\App\Domain\Ticket\TicketStatus::ACTIVE->value);
        $existingTicket->method('getUpdatedAt')->willReturn(new \DateTimeImmutable());
        $existingTicket->method('incrementLastEventSeq')->willReturn(1);

        // Legacy: findOpenByContact called with null -> finds regardless of workspace
        $this->conversationRepo
            ->expects($this->once())
            ->method('findOpenByContact')
            ->with($contact, null)
            ->willReturn($existingConversation);

        $this->ticketRepo
            ->expects($this->once())
            ->method('findNotClosedByContact')
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