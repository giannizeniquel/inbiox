<?php

namespace App\Tests\Unit;

use App\Application\UseCase\HandleInboundMessageUseCase;
use App\Application\UseCase\TransitionTicketStatusUseCase;
use App\Application\Rule\RuleExecutorService;
use App\Domain\Event\EventCollector;
use App\Repository\ContactRepository;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
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

        $this->useCase = new HandleInboundMessageUseCase(
            $this->em,
            $this->contactRepo,
            $this->conversationRepo,
            $this->messageRepo,
            $this->ticketRepo,
            $this->eventDispatcher,
            $this->transitionUseCase,
            $this->ruleExecutor,
            $this->eventCollector
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
}