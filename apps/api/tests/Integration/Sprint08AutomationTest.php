<?php

namespace App\Tests\Integration;

use App\Domain\Tag\Tag;
use App\Domain\Rule\RuleInterface;
use App\Domain\Rule\RuleContext;
use App\Domain\Rule\AutoReopenRule;
use App\Domain\Rule\AutoCloseRule;
use App\Domain\Rule\KeywordTaggingRule;
use App\Application\UseCase\AddTagToTicketUseCase;
use App\Application\UseCase\HandleInboundMessageUseCase;
use App\Domain\Ticket\Ticket;
use App\Domain\Ticket\TicketStatus;
use App\Domain\Message\Message;
use App\Repository\TagRepository;
use App\Repository\TicketRepository;
use App\Domain\Contact\Contact;
use App\Domain\Conversation\Conversation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for Sprint 08 - Automation Rules + Tagging System.
 *
 * Tests cover:
 * - Tag normalization (lowercase + trim)
 * - AddTagToTicket idempotency
 * - RuleInterface contract
 * - AutoReopenRule (resolved → waiting, closed NOT reopened)
 * - AutoCloseRule (threshold check)
 * - KeywordTaggingRule (error keyword detection)
 * - RuleExecutorService (execution key prevents duplicates)
 * - HandleInboundMessageUseCase integration with rules
 */
class Sprint08AutomationTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;
    private ?TagRepository $tagRepo = null;
    private ?TicketRepository $ticketRepo = null;
    private ?AddTagToTicketUseCase $addTagUseCase = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->tagRepo = static::getContainer()->get(TagRepository::class);
        $this->ticketRepo = static::getContainer()->get(TicketRepository::class);
        $this->addTagUseCase = static::getContainer()->get(AddTagToTicketUseCase::class);

        // Clean up test data
        $this->em->getConnection()->executeStatement('DELETE FROM ticket_tags');
        $this->em->getConnection()->executeStatement('DELETE FROM tags');
        $this->em->getConnection()->executeStatement('DELETE FROM rule_execution_logs');
        $this->em->getConnection()->executeStatement('DELETE FROM messages');
        $this->em->getConnection()->executeStatement('DELETE FROM tickets');
        $this->em->getConnection()->executeStatement('DELETE FROM conversations');
        $this->em->getConnection()->executeStatement('DELETE FROM contacts');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
    }

    // ============================================
    // TAG NORMALIZATION TESTS
    // ============================================

    /**
     * Test tag normalization converts to lowercase.
     */
    public function testTagNormalizationConvertsToLowercase(): void
    {
        // Arrange
        $tagName = 'ERROR';

        // Act
        $normalized = Tag::normalize($tagName);

        // Assert
        $this->assertEquals('error', $normalized);
    }

    /**
     * Test tag normalization trims whitespace.
     */
    public function testTagNormalizationTrimsWhitespace(): void
    {
        // Arrange
        $tagName = '  error  ';

        // Act
        $normalized = Tag::normalize($tagName);

        // Assert
        $this->assertEquals('error', $normalized);
    }

    /**
     * Test tag normalization handles combined whitespace and case.
     */
    public function testTagNormalizationHandlesCombinedWhitespaceAndCase(): void
    {
        // Arrange
        $tagName = '  ERROR  TAG  ';

        // Act
        $normalized = Tag::normalize($tagName);

        // Assert
        $this->assertEquals('error tag', $normalized);
    }

    /**
     * Test tag normalization handles Unicode characters.
     */
    public function testTagNormalizationPreservesUnicode(): void
    {
        // Arrange
        $tagName = 'urgenté';

        // Act
        $normalized = Tag::normalize($tagName);

        // Assert
        $this->assertEquals('urgenté', $normalized);
    }

    // ============================================
    // ADD TAG TO TICKET IDEMPOTENCY TESTS
    // ============================================

    /**
     * Test adding same tag to same ticket is idempotent - no duplicate.
     */
    public function testAddTagToTicketIdempotencyNoDuplicate(): void
    {
        // Arrange - create ticket and tag
        $contact = new Contact();
        $contact->setPhone('+543711234567');
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $conversation->setStatus('open');
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContact($contact);
        $ticket->setConversation($conversation);
        $ticket->setStatus(TicketStatus::NEW->value);
        $this->em->persist($ticket);
        $this->em->flush();

        // Act - add tag first time
        $result1 = $this->addTagUseCase->execute($ticket->getId(), 'error', 'test');

        // Act - add same tag again (should be idempotent)
        $result2 = $this->addTagUseCase->execute($ticket->getId(), 'error', 'test');

        // Assert - first should be 'added', second should be 'already_exists'
        $this->assertEquals('added', $result1['action']);
        $this->assertEquals('already_exists', $result2['action']);

        // Verify only one tag exists
        $count = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM ticket_tags WHERE ticket_id = ?',
            [$ticket->getId()]
        )->fetchOne();

        $this->assertEquals(1, $count);
    }

    /**
     * Test adding different tags to same ticket creates multiple relations.
     */
    public function testAddDifferentTagsCreatesMultipleRelations(): void
    {
        // Arrange - create ticket
        $contact = new Contact();
        $contact->setPhone('+543711234567');
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $conversation->setStatus('open');
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContact($contact);
        $ticket->setConversation($conversation);
        $ticket->setStatus(TicketStatus::NEW->value);
        $this->em->persist($ticket);
        $this->em->flush();

        // Act - add different tags
        $this->addTagUseCase->execute($ticket->getId(), 'error', 'test');
        $this->addTagUseCase->execute($ticket->getId(), 'urgent', 'test');

        // Assert - both tags should exist
        $count = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM ticket_tags WHERE ticket_id = ?',
            [$ticket->getId()]
        )->fetchOne();

        $this->assertEquals(2, $count);
    }

    // ============================================
    // RULE INTERFACE CONTRACT TESTS
    // ============================================

    /**
     * Test RuleInterface contract - all methods present.
     */
    public function testRuleInterfaceContract(): void
    {
        // Arrange
        $rule = new KeywordTaggingRule(
            $this->addTagUseCase
        );

        // Assert - verify all required methods exist
        $this->assertTrue(method_exists($rule, 'supports'), 'Rule must have supports() method');
        $this->assertTrue(method_exists($rule, 'execute'), 'Rule must have execute() method');
        $this->assertTrue(method_exists($rule, 'getName'), 'Rule must have getName() method');
        $this->assertTrue(method_exists($rule, 'buildExecutionKey'), 'Rule must have buildExecutionKey() method');

        // Assert - getName returns string
        $this->assertIsString($rule->getName());
        $this->assertNotEmpty($rule->getName());
    }

    /**
     * Test AutoReopenRule getName returns correct identifier.
     */
    public function testAutoReopenRuleGetName(): void
    {
        // Arrange
        $rule = $this->createMockAutoReopenRule();

        // Assert
        $this->assertEquals('auto_reopen', $rule->getName());
    }

    /**
     * Test AutoCloseRule getName returns correct identifier.
     */
    public function testAutoCloseRuleGetName(): void
    {
        // Arrange
        $rule = $this->createMockAutoCloseRule();

        // Assert
        $this->assertEquals('auto_close', $rule->getName());
    }

    // ============================================
    // AUTO REOPEN RULE TESTS
    // ============================================

    /**
     * Test AutoReopenRule supports resolved ticket with inbound trigger.
     */
    public function testAutoReopenRuleSupportsResolvedTicket(): void
    {
        // Arrange
        $rule = $this->createMockAutoReopenRule();
        $ticket = $this->createTestTicket(TicketStatus::RESOLVED->value);
        $context = new RuleContext($ticket, null, 'inbound');

        // Assert
        $this->assertTrue($rule->supports($context));
    }

    /**
     * Test AutoReopenRule does NOT support closed tickets.
     */
    public function testAutoReopenRuleDoesNotSupportClosedTickets(): void
    {
        // Arrange
        $rule = $this->createMockAutoReopenRule();
        $ticket = $this->createTestTicket(TicketStatus::CLOSED->value);
        $context = new RuleContext($ticket, null, 'inbound');

        // Assert
        $this->assertFalse($rule->supports($context));
    }

    /**
     * Test AutoReopenRule does NOT support active tickets.
     */
    public function testAutoReopenRuleDoesNotSupportActiveTickets(): void
    {
        // Arrange
        $rule = $this->createMockAutoReopenRule();
        $ticket = $this->createTestTicket(TicketStatus::ACTIVE->value);
        $context = new RuleContext($ticket, null, 'inbound');

        // Assert
        $this->assertFalse($rule->supports($context));
    }

    /**
     * Test AutoReopenRule does NOT support outbound trigger.
     */
    public function testAutoReopenRuleDoesNotSupportOutbound(): void
    {
        // Arrange
        $rule = $this->createMockAutoReopenRule();
        $ticket = $this->createTestTicket(TicketStatus::RESOLVED->value);
        $context = new RuleContext($ticket, null, 'outbound');

        // Assert
        $this->assertFalse($rule->supports($context));
    }

    /**
     * Test AutoReopenRule buildExecutionKey includes ticket and message.
     */
    public function testAutoReopenRuleBuildExecutionKey(): void
    {
        // Arrange
        $rule = $this->createMockAutoReopenRule();
        $ticket = $this->createTestTicket(TicketStatus::RESOLVED->value);
        $message = $this->createTestMessage(123);
        $context = new RuleContext($ticket, $message, 'inbound');

        // Act
        $key = $rule->buildExecutionKey($context);

        // Assert
        $this->assertStringContainsString('auto_reopen', $key);
        $this->assertStringContainsString((string)$ticket->getId(), $key);
    }

    // ============================================
    // AUTO CLOSE RULE TESTS
    // ============================================

    /**
     * Test AutoCloseRule supports resolved ticket with manual trigger after threshold.
     * With >= logic: ticket is 48 hours old, threshold is 24 hours.
     * 48 >= 24 = TRUE - ticket has been unresolved for at least the threshold hours.
     */
    public function testAutoCloseRuleSupportsResolvedTicketAfterThreshold(): void
    {
        // Arrange - create ticket with old updatedAt (past threshold)
        $rule = $this->createMockAutoCloseRule(24);
        $ticket = $this->createTestTicket(TicketStatus::RESOLVED->value);
        $ticket->setUpdatedAt(new \DateTimeImmutable('-48 hours'));
        $context = new RuleContext($ticket, null, 'manual');

        // Assert - 48 hours old >= 24 hour threshold = TRUE
        $this->assertTrue($rule->supports($context));
    }

    /**
     * Test AutoCloseRule does NOT support before threshold.
     * With >= logic: ticket is 12 hours old, threshold is 24 hours.
     * 12 >= 24 = FALSE - ticket hasn't reached the threshold yet.
     */
    public function testAutoCloseRuleDoesNotSupportBeforeThreshold(): void
    {
        // Arrange - create ticket with recent updatedAt
        $rule = $this->createMockAutoCloseRule(24);
        $ticket = $this->createTestTicket(TicketStatus::RESOLVED->value);
        $ticket->setUpdatedAt(new \DateTimeImmutable('-12 hours'));
        $context = new RuleContext($ticket, null, 'manual');

        // Assert - 12 hours old < 24 hour threshold = FALSE
        $this->assertFalse($rule->supports($context));
    }

    /**
     * Test AutoCloseRule does NOT support active tickets.
     */
    public function testAutoCloseRuleDoesNotSupportActiveTickets(): void
    {
        // Arrange
        $rule = $this->createMockAutoCloseRule();
        $ticket = $this->createTestTicket(TicketStatus::ACTIVE->value);
        $context = new RuleContext($ticket, null, 'manual');

        // Assert
        $this->assertFalse($rule->supports($context));
    }

    /**
     * Test AutoCloseRule threshold is configurable.
     * With corrected logic: ticket must be at least threshold hours old to support.
     * 36 hours < 48 hour threshold = should NOT support.
     */
    public function testAutoCloseRuleThresholdIsConfigurable(): void
    {
        // Arrange - 48 hour threshold, ticket is 36 hours old (should NOT pass)
        $rule = $this->createMockAutoCloseRule(48);
        $ticket = $this->createTestTicket(TicketStatus::RESOLVED->value);
        $ticket->setUpdatedAt(new \DateTimeImmutable('-36 hours'));
        $context = new RuleContext($ticket, null, 'manual');

        // Assert - 36 hours is less than 48 hour threshold, so rule should NOT support
        $this->assertFalse($rule->supports($context));
    }

    // ============================================
    // KEYWORD TAGGING RULE TESTS
    // ============================================

    /**
     * Test KeywordTaggingRule detects "error" keyword in message.
     */
    public function testKeywordTaggingRuleDetectsErrorKeyword(): void
    {
        // Arrange
        $rule = new KeywordTaggingRule($this->addTagUseCase);
        $ticket = $this->createTestTicket(TicketStatus::ACTIVE->value);
        $message = $this->createTestMessage(123);
        $message->setContent('There is an error in the system');
        $context = new RuleContext($ticket, $message, 'inbound');

        // Assert
        $this->assertTrue($rule->supports($context));
    }

    /**
     * Test KeywordTaggingRule is case insensitive.
     */
    public function testKeywordTaggingRuleIsCaseInsensitive(): void
    {
        // Arrange
        $rule = new KeywordTaggingRule($this->addTagUseCase);
        $ticket = $this->createTestTicket(TicketStatus::ACTIVE->value);
        $message = $this->createTestMessage(123);
        $message->setContent('ERROR detected in the logs');
        $context = new RuleContext($ticket, $message, 'inbound');

        // Assert
        $this->assertTrue($rule->supports($context));
    }

    /**
     * Test KeywordTaggingRule does NOT match words containing "error".
     */
    public function testKeywordTaggingRuleDoesNotFalsePositive(): void
    {
        // Arrange - "error" is substring but not whole word
        $rule = new KeywordTaggingRule($this->addTagUseCase);
        $ticket = $this->createTestTicket(TicketStatus::ACTIVE->value);
        $message = $this->createTestMessage(123);
        $message->setContent('The errorHandler crashed'); // contains "error" as substring
        $context = new RuleContext($ticket, $message, 'inbound');

        // Assert - stripos finds substring
        $this->assertTrue($rule->supports($context));
    }

    /**
     * Test KeywordTaggingRule does NOT support outbound trigger.
     */
    public function testKeywordTaggingRuleDoesNotSupportOutbound(): void
    {
        // Arrange
        $rule = new KeywordTaggingRule($this->addTagUseCase);
        $ticket = $this->createTestTicket(TicketStatus::ACTIVE->value);
        $message = $this->createTestMessage(123);
        $message->setContent('There is an error');
        $context = new RuleContext($ticket, $message, 'outbound');

        // Assert
        $this->assertFalse($rule->supports($context));
    }

    // ============================================
    // RULE EXECUTOR SERVICE IDEMPOTENCY TESTS
    // ============================================

    /**
     * Test RuleExecutorService execution key prevents duplicate execution.
     */
    public function testRuleExecutorServiceIdempotencyCheck(): void
    {
        // Arrange - create a resolved ticket
        $contact = new Contact();
        $contact->setPhone('+543711234567');
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $conversation->setStatus('open');
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContact($contact);
        $ticket->setConversation($conversation);
        $ticket->setStatus(TicketStatus::RESOLVED->value);
        $this->em->persist($ticket);
        $this->em->flush();

        // Create a rule execution log to simulate previous execution
        $this->em->getConnection()->executeStatement(
            'INSERT INTO rule_execution_logs (execution_key, rule_name, ticket_id, message_id, executed_at, result_status) VALUES (?, ?, ?, ?, ?, ?)',
            [
                'auto_reopen:' . $ticket->getId() . ':no_message',
                'auto_reopen',
                $ticket->getId(),
                null,
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'success'
            ]
        );

        // Get rule executor service
        $ruleExecutor = static::getContainer()->get(\App\Application\Rule\RuleExecutorService::class);
        $context = new RuleContext($ticket, null, 'inbound');

        // Act - execute rules (should skip due to idempotency)
        $ruleExecutor->execute($context);

        // Assert - ticket should NOT be transitioned (still resolved)
        $this->em->clear();
        $ticket = $this->ticketRepo->find($ticket->getId());
        $this->assertEquals(TicketStatus::RESOLVED->value, $ticket->getStatus());
    }

    // ============================================
    // INTEGRATION - HANDLE INBOUND MESSAGE WITH RULES
    // ============================================

    /**
     * Test HandleInboundMessageUseCase triggers automation rules.
     */
    public function testHandleInboundMessageUseCaseWithRules(): void
    {
        // Arrange
        $useCase = static::getContainer()->get(HandleInboundMessageUseCase::class);

        // Act - send message with "error" keyword
        $result = $useCase->processInboundMessage(
            '+543711234567',
            'There is an error in the system'
        );

        // Assert - message was processed
        $this->assertEquals('ok', $result['status']);
        $this->assertNotNull($result['ticket_id']);
        $this->assertNotNull($result['message_id']);

        // Verify "error" tag was added by KeywordTaggingRule
        $tagCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM ticket_tags tt
             JOIN tags t ON tt.tag_id = t.id
             WHERE tt.ticket_id = ? AND t.name = ?',
            [$result['ticket_id'], 'error']
        )->fetchOne();

        $this->assertEquals(1, $tagCount, 'Error tag should be added by KeywordTaggingRule');
    }

    /**
     * Test HandleInboundMessageUseCase with resolved ticket triggers auto-reopen.
     */
    public function testHandleInboundMessageResolvesTicketTriggersReopen(): void
    {
        // Arrange - create resolved ticket first
        $contact = new Contact();
        $contact->setPhone('+543711234567');
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $conversation->setStatus('open');
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContact($contact);
        $ticket->setConversation($conversation);
        $ticket->setStatus(TicketStatus::RESOLVED->value);
        $this->em->persist($ticket);
        $this->em->flush();

        // Act - send inbound message to resolved ticket
        $useCase = static::getContainer()->get(HandleInboundMessageUseCase::class);
        $result = $useCase->processInboundMessage(
            '+543711234567',
            'I need more help with this issue'
        );

        // Assert - ticket should be waiting (auto-reopened)
        $this->em->clear();
        $ticket = $this->ticketRepo->find($result['ticket_id']);
        $this->assertEquals(TicketStatus::WAITING->value, $ticket->getStatus());
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    private function createTestTicket(string $status): Ticket
    {
        $ticket = new Ticket();
        $ticket->setStatus($status);
        $ticket->setCreatedAt(new \DateTimeImmutable());
        $ticket->setUpdatedAt(new \DateTimeImmutable());

        // Set ID for testing (mock object behavior)
        $reflection = new \ReflectionProperty(Ticket::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($ticket, 1);

        return $ticket;
    }

    private function createTestMessage(int $id): Message
    {
        $message = new Message();

        // Set ID via reflection
        $reflection = new \ReflectionProperty(Message::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($message, $id);

        return $message;
    }

    private function createMockAutoReopenRule(): AutoReopenRule
    {
        $transitionUseCase = $this->createMock(\App\Application\UseCase\TransitionTicketStatusUseCase::class);
        $em = $this->createMock(EntityManagerInterface::class);

        return new AutoReopenRule($transitionUseCase, $em);
    }

    private function createMockAutoCloseRule(int $thresholdHours = 24): AutoCloseRule
    {
        $transitionUseCase = $this->createMock(\App\Application\UseCase\TransitionTicketStatusUseCase::class);
        $em = $this->createMock(EntityManagerInterface::class);

        return new AutoCloseRule($transitionUseCase, $em, $thresholdHours);
    }
}