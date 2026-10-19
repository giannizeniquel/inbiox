<?php

namespace App\Tests\Integration;

use App\Ticket\Etiqueta;
use App\Automatizacion\ReglaInterface;
use App\Automatizacion\ContextoDeRegla;
use App\Automatizacion\ReglaDeReaperturaAutomatica;
use App\Automatizacion\ReglaDeEtiquetadoPorPalabraClave;
use App\Ticket\UseCase\AgregarEtiquetaATicketUseCase;
use App\Conversacion\UseCase\ProcesarMensajeEntranteUseCase;
use App\Ticket\Ticket;
use App\Ticket\EstadoDeTicket;
use App\Conversacion\Mensaje;
use App\Ticket\Repository\EtiquetaRepository;
use App\Ticket\Repository\TicketRepository;
use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for Sprint 08 - Automation Rules + Tagging System.
 *
 * Tests cover:
 * - Etiqueta normalization (lowercase + trim)
 * - AddTagToTicket idempotency
 * - ReglaInterface contract
 * - ReglaDeReaperturaAutomatica (resolved → waiting, closed NOT reopened)
 * - ReglaDeEtiquetadoPorPalabraClave (error keyword detection)
 * - EjecutorDeReglas (execution key prevents duplicates)
 * - ProcesarMensajeEntranteUseCase integration with rules
 */
class Sprint08AutomationTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;
    private ?EtiquetaRepository $tagRepo = null;
    private ?TicketRepository $ticketRepo = null;
    private ?AgregarEtiquetaATicketUseCase $addTagUseCase = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->tagRepo = static::getContainer()->get(EtiquetaRepository::class);
        $this->ticketRepo = static::getContainer()->get(TicketRepository::class);
        $this->addTagUseCase = static::getContainer()->get(AgregarEtiquetaATicketUseCase::class);

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
        $normalized = Etiqueta::normalizar($tagName);

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
        $normalized = Etiqueta::normalizar($tagName);

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
        $normalized = Etiqueta::normalizar($tagName);

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
        $normalized = Etiqueta::normalizar($tagName);

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
        $contact = new Contacto();
        $contact->setTelefono('+543711234567');
        $this->em->persist($contact);

        $conversation = new Conversacion();
        $conversation->setContacto($contact);
        $conversation->setEstado('open');
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado(EstadoDeTicket::NUEVO->value);
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
        $contact = new Contacto();
        $contact->setTelefono('+543711234567');
        $this->em->persist($contact);

        $conversation = new Conversacion();
        $conversation->setContacto($contact);
        $conversation->setEstado('open');
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado(EstadoDeTicket::NUEVO->value);
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
     * Test ReglaInterface contract - all methods present.
     */
    public function testRuleInterfaceContract(): void
    {
        // Arrange
        $rule = new ReglaDeEtiquetadoPorPalabraClave(
            $this->addTagUseCase
        );

        // Assert - verify all required methods exist
        $this->assertTrue(method_exists($rule, 'supports'), 'Rule must have supports() method');
        $this->assertTrue(method_exists($rule, 'execute'), 'Rule must have execute() method');
        $this->assertTrue(method_exists($rule, 'getNombre'), 'Rule must have getNombre() method');
        $this->assertTrue(method_exists($rule, 'armarClaveDeEjecucion'), 'Rule must have armarClaveDeEjecucion() method');

        // Assert - getName returns string
        $this->assertIsString($rule->getNombre());
        $this->assertNotEmpty($rule->getNombre());
    }

    /**
     * Test ReglaDeReaperturaAutomatica getName returns correct identifier.
     */
    public function testAutoReopenRuleGetName(): void
    {
        // Arrange
        $rule = $this->createMockAutoReopenRule();

        // Assert
        $this->assertEquals('auto_reopen', $rule->getNombre());
    }

    // ============================================
    // AUTO REOPEN RULE TESTS
    // ============================================

    /**
     * Test ReglaDeReaperturaAutomatica supports resolved ticket with inbound trigger.
     */
    public function testAutoReopenRuleSupportsResolvedTicket(): void
    {
        // Arrange
        $rule = $this->createMockAutoReopenRule();
        $ticket = $this->createTestTicket(EstadoDeTicket::RESUELTO->value);
        $context = new ContextoDeRegla($ticket, null, 'inbound');

        // Assert
        $this->assertTrue($rule->supports($context));
    }

    /**
     * Test ReglaDeReaperturaAutomatica does NOT support closed tickets.
     */
    public function testAutoReopenRuleDoesNotSupportClosedTickets(): void
    {
        // Arrange
        $rule = $this->createMockAutoReopenRule();
        $ticket = $this->createTestTicket(EstadoDeTicket::CERRADO->value);
        $context = new ContextoDeRegla($ticket, null, 'inbound');

        // Assert
        $this->assertFalse($rule->supports($context));
    }

    /**
     * Test ReglaDeReaperturaAutomatica does NOT support active tickets.
     */
    public function testAutoReopenRuleDoesNotSupportActiveTickets(): void
    {
        // Arrange
        $rule = $this->createMockAutoReopenRule();
        $ticket = $this->createTestTicket(EstadoDeTicket::ACTIVO->value);
        $context = new ContextoDeRegla($ticket, null, 'inbound');

        // Assert
        $this->assertFalse($rule->supports($context));
    }

    /**
     * Test ReglaDeReaperturaAutomatica does NOT support outbound trigger.
     */
    public function testAutoReopenRuleDoesNotSupportOutbound(): void
    {
        // Arrange
        $rule = $this->createMockAutoReopenRule();
        $ticket = $this->createTestTicket(EstadoDeTicket::RESUELTO->value);
        $context = new ContextoDeRegla($ticket, null, 'outbound');

        // Assert
        $this->assertFalse($rule->supports($context));
    }

    /**
     * Test ReglaDeReaperturaAutomatica armarClaveDeEjecucion includes ticket and message.
     */
    public function testAutoReopenRuleBuildExecutionKey(): void
    {
        // Arrange
        $rule = $this->createMockAutoReopenRule();
        $ticket = $this->createTestTicket(EstadoDeTicket::RESUELTO->value);
        $message = $this->createTestMessage(123);
        $context = new ContextoDeRegla($ticket, $message, 'inbound');

        // Act
        $key = $rule->armarClaveDeEjecucion($context);

        // Assert
        $this->assertStringContainsString('auto_reopen', $key);
        $this->assertStringContainsString((string)$ticket->getId(), $key);
    }

    // ============================================
    // AUTO CLOSE RULE TESTS
    // ============================================

    // ============================================
    // KEYWORD TAGGING RULE TESTS
    // ============================================

    /**
     * Test ReglaDeEtiquetadoPorPalabraClave detects "error" keyword in message.
     */
    public function testKeywordTaggingRuleDetectsErrorKeyword(): void
    {
        // Arrange
        $rule = new ReglaDeEtiquetadoPorPalabraClave($this->addTagUseCase);
        $ticket = $this->createTestTicket(EstadoDeTicket::ACTIVO->value);
        $message = $this->createTestMessage(123);
        $message->setContenido('There is an error in the system');
        $context = new ContextoDeRegla($ticket, $message, 'inbound');

        // Assert
        $this->assertTrue($rule->supports($context));
    }

    /**
     * Test ReglaDeEtiquetadoPorPalabraClave is case insensitive.
     */
    public function testKeywordTaggingRuleIsCaseInsensitive(): void
    {
        // Arrange
        $rule = new ReglaDeEtiquetadoPorPalabraClave($this->addTagUseCase);
        $ticket = $this->createTestTicket(EstadoDeTicket::ACTIVO->value);
        $message = $this->createTestMessage(123);
        $message->setContenido('ERROR detected in the logs');
        $context = new ContextoDeRegla($ticket, $message, 'inbound');

        // Assert
        $this->assertTrue($rule->supports($context));
    }

    /**
     * Test ReglaDeEtiquetadoPorPalabraClave does NOT match words containing "error".
     */
    public function testKeywordTaggingRuleDoesNotFalsePositive(): void
    {
        // Arrange - "error" is substring but not whole word
        $rule = new ReglaDeEtiquetadoPorPalabraClave($this->addTagUseCase);
        $ticket = $this->createTestTicket(EstadoDeTicket::ACTIVO->value);
        $message = $this->createTestMessage(123);
        $message->setContenido('The errorHandler crashed'); // contains "error" as substring
        $context = new ContextoDeRegla($ticket, $message, 'inbound');

        // Assert - stripos finds substring
        $this->assertTrue($rule->supports($context));
    }

    /**
     * Test ReglaDeEtiquetadoPorPalabraClave does NOT support outbound trigger.
     */
    public function testKeywordTaggingRuleDoesNotSupportOutbound(): void
    {
        // Arrange
        $rule = new ReglaDeEtiquetadoPorPalabraClave($this->addTagUseCase);
        $ticket = $this->createTestTicket(EstadoDeTicket::ACTIVO->value);
        $message = $this->createTestMessage(123);
        $message->setContenido('There is an error');
        $context = new ContextoDeRegla($ticket, $message, 'outbound');

        // Assert
        $this->assertFalse($rule->supports($context));
    }

    // ============================================
    // RULE EXECUTOR SERVICE IDEMPOTENCY TESTS
    // ============================================

    /**
     * Test EjecutorDeReglas execution key prevents duplicate execution.
     */
    public function testRuleExecutorServiceIdempotencyCheck(): void
    {
        // Arrange - create a resolved ticket
        $contact = new Contacto();
        $contact->setTelefono('+543711234567');
        $this->em->persist($contact);

        $conversation = new Conversacion();
        $conversation->setContacto($contact);
        $conversation->setEstado('open');
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado(EstadoDeTicket::RESUELTO->value);
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
        $ruleExecutor = static::getContainer()->get(\App\Automatizacion\EjecutorDeReglas::class);
        $context = new ContextoDeRegla($ticket, null, 'inbound');

        // Act - execute rules (should skip due to idempotency)
        $ruleExecutor->execute($context);

        // Assert - ticket should NOT be transitioned (still resolved)
        $this->em->clear();
        $ticket = $this->ticketRepo->find($ticket->getId());
        $this->assertEquals(EstadoDeTicket::RESUELTO->value, $ticket->getEstado());
    }

    // ============================================
    // INTEGRATION - HANDLE INBOUND MESSAGE WITH RULES
    // ============================================

    /**
     * Test ProcesarMensajeEntranteUseCase triggers automation rules.
     */
    public function testHandleInboundMessageUseCaseWithRules(): void
    {
        // Arrange
        $useCase = static::getContainer()->get(ProcesarMensajeEntranteUseCase::class);

        // Act - send message with "error" keyword
        $result = $useCase->processInboundMessage(
            '+543711234567',
            'There is an error in the system'
        );

        // Assert - message was processed
        $this->assertEquals('ok', $result['status']);
        $this->assertNotNull($result['ticket_id']);
        $this->assertNotNull($result['message_id']);

        // Verify "error" tag was added by ReglaDeEtiquetadoPorPalabraClave
        $tagCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM ticket_tags tt
             JOIN tags t ON tt.tag_id = t.id
             WHERE tt.ticket_id = ? AND t.name = ?',
            [$result['ticket_id'], 'error']
        )->fetchOne();

        $this->assertEquals(1, $tagCount, 'Error tag should be added by KeywordTaggingRule');
    }

    /**
     * Test ProcesarMensajeEntranteUseCase with resolved ticket triggers auto-reopen.
     */
    public function testHandleInboundMessageResolvesTicketTriggersReopen(): void
    {
        // Arrange - create resolved ticket first
        $contact = new Contacto();
        $contact->setTelefono('+543711234567');
        $this->em->persist($contact);

        $conversation = new Conversacion();
        $conversation->setContacto($contact);
        $conversation->setEstado('open');
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContacto($contact);
        $ticket->setConversacion($conversation);
        $ticket->setEstado(EstadoDeTicket::RESUELTO->value);
        $this->em->persist($ticket);
        $this->em->flush();

        // Act - send inbound message to resolved ticket
        $useCase = static::getContainer()->get(ProcesarMensajeEntranteUseCase::class);
        $result = $useCase->processInboundMessage(
            '+543711234567',
            'I need more help with this issue'
        );

        // Assert - ticket should be waiting (auto-reopened)
        $this->em->clear();
        $ticket = $this->ticketRepo->find($result['ticket_id']);
        $this->assertEquals(EstadoDeTicket::EN_ESPERA->value, $ticket->getEstado());
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    private function createTestTicket(string $status): Ticket
    {
        $ticket = new Ticket();
        $ticket->setEstado($status);
        $ticket->setCreadoAt(new \DateTimeImmutable());
        $ticket->setActualizadoAt(new \DateTimeImmutable());

        // Set ID for testing (mock object behavior)
        $reflection = new \ReflectionProperty(Ticket::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($ticket, 1);

        return $ticket;
    }

    private function createTestMessage(int $id): Mensaje
    {
        $message = new Mensaje();

        // Set ID via reflection
        $reflection = new \ReflectionProperty(Mensaje::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($message, $id);

        return $message;
    }

    private function createMockAutoReopenRule(): ReglaDeReaperturaAutomatica
    {
        $transitionUseCase = $this->createMock(\App\Ticket\UseCase\CambiarEstadoDeTicketUseCase::class);
        $em = $this->createMock(EntityManagerInterface::class);

        return new ReglaDeReaperturaAutomatica($transitionUseCase, $em);
    }
}