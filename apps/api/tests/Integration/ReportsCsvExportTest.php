<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Reporte\Query\ExportTicketsCsvQuery;
use App\Agente\Agent;
use App\Contacto\Contact;
use App\Conversacion\Conversation;
use App\Ticket\Tag;
use App\Ticket\TicketTag;
use App\Ticket\Ticket;
use App\Administracion\User;
use App\Ticket\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP-level integration tests for `GET /api/reports/export`
 * (`reports-csv-export` spec/design):
 * - Role gating (403 agent, 200 supervisor/admin)
 * - Streamed CSV headers (Content-Type, Content-Disposition, X-Accel-Buffering)
 * - BOM + 14-column header row
 * - Filter scenarios reused from `ListTicketsQuery`/`TicketFilterBuilder`
 * - Formula-injection escaping (Phase 4)
 * - 1200-row chunk-boundary proof (Phase 4, load-bearing)
 *
 * Captures the `StreamedResponse` body via `ob_start(); $response->sendContent(); ob_get_clean();`
 * (see `StreamedResponseCaptureSpikeTest.php` for the proven idiom).
 */
class ReportsCsvExportTest extends WebTestCase
{
    private const TEST_PASSWORD = 'test-password';

    private ?KernelBrowser $client = null;
    private ?EntityManagerInterface $em = null;
    private int $phoneSeq = 0;
    private int $userSeq = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM refresh_tokens');
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM ticket_events');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
        $conn->executeStatement('DELETE FROM agents');
        $conn->executeStatement('DELETE FROM `user`');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->client = null;
        $this->em = null;
    }

    // ────────────────────────────────────────────────────────
    //  Helpers
    // ────────────────────────────────────────────────────────

    private function authAs(array $roles): string
    {
        $this->userSeq++;
        $email = sprintf('export-test-%d@inbiox.local', $this->userSeq);

        $user = new User();
        $user->setEmail($email);
        $user->setPassword(\password_hash(self::TEST_PASSWORD, \PASSWORD_BCRYPT));
        $user->setRoles($roles);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode(['email' => $email, 'password' => self::TEST_PASSWORD]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), 'Login must succeed: ' . $response->getContent());

        $data = \json_decode($response->getContent(), true);

        return $data['access_token'];
    }

    private function createTicket(
        string $status = 'active',
        array $tagNames = [],
        ?int $assignedAgentId = null,
        ?\DateTimeImmutable $createdAt = null,
        string $contactName = 'Jane Doe',
    ): int {
        $this->phoneSeq++;

        $contact = new Contact();
        $contact->setPhone('+549110000' . str_pad((string) $this->phoneSeq, 4, '0', STR_PAD_LEFT));
        $contact->setName($contactName);
        $this->em->persist($contact);

        $conversation = new Conversation();
        $conversation->setContact($contact);
        $this->em->persist($conversation);

        $ticket = new Ticket();
        $ticket->setContact($contact);
        $ticket->setConversation($conversation);
        $ticket->setStatus($status);
        if (null !== $assignedAgentId) {
            $ticket->setAssignedAgentId($assignedAgentId);
        }
        if (null !== $createdAt) {
            $ticket->setCreatedAt($createdAt);
        }
        $this->em->persist($ticket);
        $this->em->flush();

        $tagRepo = static::getContainer()->get(TagRepository::class);
        foreach ($tagNames as $tagName) {
            $tag = $tagRepo->findByName($tagName);
            if (!$tag) {
                $tag = new Tag($tagName);
                $this->em->persist($tag);
                $this->em->flush();
            }
            $ticketTag = new TicketTag($ticket, $tag);
            $this->em->persist($ticketTag);
        }
        $this->em->flush();

        $id = $ticket->getId();
        \assert($id !== null);

        return $id;
    }

    /**
     * Requests the export endpoint and returns the captured streamed body.
     *
     * `AbstractBrowser::request()` (BrowserKit) unconditionally calls
     * `HttpKernelBrowser::filterResponse()`, which already drains the
     * `StreamedResponse` exactly once via
     * `ob_start(); $response->sendContent(); ob_end_clean();` (see
     * `StreamedResponseCaptureSpikeTest` for the proof of that idiom on a
     * bare `StreamedResponse`). Because `StreamedResponse::sendContent()`
     * only runs its callback once (`$this->streamed` guard), a *second*
     * manual `sendContent()` call here is a silent no-op — the captured
     * bytes must be read back from BrowserKit's internal response instead.
     */
    private function requestExport(string $token, string $queryString = 'from=2026-01-01T00:00:00Z&to=2026-01-31T00:00:00Z'): string
    {
        $this->client->request('GET', '/api/reports/export?' . $queryString, [], [], [
            'HTTP_ACCEPT' => 'text/csv',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        return $this->client->getInternalResponse()->getContent();
    }

    // ────────────────────────────────────────────────────────
    //  Role gating
    // ────────────────────────────────────────────────────────

    public function test_supervisor_gets_200(): void
    {
        $token = $this->authAs(['ROLE_SUPERVISOR']);

        $this->requestExport($token);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function test_admin_inherits_export_access(): void
    {
        $token = $this->authAs(['ROLE_ADMIN']);

        $this->requestExport($token);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function test_agent_is_denied(): void
    {
        $token = $this->authAs(['ROLE_AGENT']);

        $this->requestExport($token);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  Headers + CSV shape
    // ────────────────────────────────────────────────────────

    public function test_headers_signal_a_file_download(): void
    {
        $token = $this->authAs(['ROLE_SUPERVISOR']);

        $this->requestExport($token);
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('filename=', $response->headers->get('Content-Disposition'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    public function test_header_row_has_utf8_bom_and_fourteen_columns(): void
    {
        $token = $this->authAs(['ROLE_SUPERVISOR']);

        $body = $this->requestExport($token);

        self::assertStringStartsWith("\xEF\xBB\xBF", $body);

        $reader = Reader::createFromString(substr($body, 3));
        self::assertSame(ExportTicketsCsvQuery::COLUMNS, $reader->fetchOne(0));
    }

    // ────────────────────────────────────────────────────────
    //  Filter scenarios (Phase 4)
    // ────────────────────────────────────────────────────────

    public function test_date_range_filters_on_created_at(): void
    {
        $this->createTicket(createdAt: new \DateTimeImmutable('2026-01-05'));
        $this->createTicket(createdAt: new \DateTimeImmutable('2026-03-05'));

        $token = $this->authAs(['ROLE_SUPERVISOR']);
        $body = $this->requestExport($token, 'from=2026-01-01T00:00:00Z&to=2026-01-31T00:00:00Z');

        $reader = Reader::createFromString(substr($body, 3));
        $reader->setHeaderOffset(0);
        $records = [...$reader->getRecords()];

        self::assertCount(1, $records);
    }

    public function test_no_status_param_includes_every_status(): void
    {
        foreach (['new', 'active', 'waiting', 'resolved', 'closed'] as $status) {
            $this->createTicket(status: $status, createdAt: new \DateTimeImmutable('2026-01-10'));
        }

        $token = $this->authAs(['ROLE_SUPERVISOR']);
        $body = $this->requestExport($token, 'from=2026-01-01T00:00:00Z&to=2026-01-31T00:00:00Z');

        $reader = Reader::createFromString(substr($body, 3));
        $reader->setHeaderOffset(0);
        $records = [...$reader->getRecords()];

        self::assertCount(5, $records);
    }

    public function test_status_param_narrows_results(): void
    {
        $this->createTicket(status: 'new', createdAt: new \DateTimeImmutable('2026-01-10'));
        $this->createTicket(status: 'closed', createdAt: new \DateTimeImmutable('2026-01-10'));

        $token = $this->authAs(['ROLE_SUPERVISOR']);
        $body = $this->requestExport($token, 'from=2026-01-01T00:00:00Z&to=2026-01-31T00:00:00Z&status=closed');

        $reader = Reader::createFromString(substr($body, 3));
        $reader->setHeaderOffset(0);
        $records = [...$reader->getRecords()];

        self::assertCount(1, $records);
        self::assertSame('closed', $records[0]['status']);
    }

    public function test_agent_id_param_narrows_results(): void
    {
        $agentA = new Agent('Agent A');
        $this->em->persist($agentA);
        $agentB = new Agent('Agent B');
        $this->em->persist($agentB);
        $this->em->flush();

        $wanted = $this->createTicket(assignedAgentId: $agentA->getId(), createdAt: new \DateTimeImmutable('2026-01-10'));
        $this->createTicket(assignedAgentId: $agentB->getId(), createdAt: new \DateTimeImmutable('2026-01-10'));

        $token = $this->authAs(['ROLE_SUPERVISOR']);
        $body = $this->requestExport($token, 'from=2026-01-01T00:00:00Z&to=2026-01-31T00:00:00Z&agent_id=' . $agentA->getId());

        $reader = Reader::createFromString(substr($body, 3));
        $reader->setHeaderOffset(0);
        $records = [...$reader->getRecords()];

        self::assertCount(1, $records);
        self::assertSame((string) $wanted, $records[0]['ticket_id']);
    }

    public function test_tag_and_semantics_apply(): void
    {
        $both = $this->createTicket(tagNames: ['vip', 'urgente'], createdAt: new \DateTimeImmutable('2026-01-10'));
        $this->createTicket(tagNames: ['vip'], createdAt: new \DateTimeImmutable('2026-01-10'));

        $token = $this->authAs(['ROLE_SUPERVISOR']);
        $body = $this->requestExport($token, 'from=2026-01-01T00:00:00Z&to=2026-01-31T00:00:00Z&tag=vip,urgente');

        $reader = Reader::createFromString(substr($body, 3));
        $reader->setHeaderOffset(0);
        $records = [...$reader->getRecords()];

        self::assertCount(1, $records);
        self::assertSame((string) $both, $records[0]['ticket_id']);
    }

    public function test_more_than_five_tags_rejected_as_validation_error_and_no_export_runs(): void
    {
        $token = $this->authAs(['ROLE_SUPERVISOR']);

        $this->requestExport($token, 'from=2026-01-01T00:00:00Z&to=2026-01-31T00:00:00Z&tag=a,b,c,d,e,f');

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  Formula injection (Phase 4)
    // ────────────────────────────────────────────────────────

    public function test_formula_injection_escaped_on_name_columns_but_phone_untouched(): void
    {
        $this->createTicket(
            createdAt: new \DateTimeImmutable('2026-01-10'),
            contactName: '=cmd|/c calc',
        );

        $token = $this->authAs(['ROLE_SUPERVISOR']);
        $body = $this->requestExport($token, 'from=2026-01-01T00:00:00Z&to=2026-01-31T00:00:00Z');

        $reader = Reader::createFromString(substr($body, 3));
        $reader->setHeaderOffset(0);
        $records = [...$reader->getRecords()];

        self::assertCount(1, $records);
        self::assertSame("'=cmd|/c calc", $records[0]['contact_name']);
        self::assertStringStartsWith('+549', $records[0]['contact_phone']);
    }

    // ────────────────────────────────────────────────────────
    //  Load-bearing: 1200 tickets across 500/500/200 chunk boundaries
    // ────────────────────────────────────────────────────────

    public function test_1200_tickets_export_with_no_gaps_or_duplicates_across_chunk_boundaries(): void
    {
        $ids = [];
        $baseDate = new \DateTimeImmutable('2026-01-01 00:00:00');
        for ($i = 0; $i < 1200; $i++) {
            $ids[] = $this->createTicket(createdAt: $baseDate->modify("+{$i} seconds"));
        }

        $token = $this->authAs(['ROLE_SUPERVISOR']);
        $body = $this->requestExport($token, 'from=2025-12-01T00:00:00Z&to=2026-06-01T00:00:00Z');

        $reader = Reader::createFromString(substr($body, 3));
        $reader->setHeaderOffset(0);
        $records = [...$reader->getRecords()];

        self::assertCount(1200, $records);

        $exportedIds = array_map(static fn(array $r) => (int) $r['ticket_id'], $records);

        // Strictly ascending (no gaps skipped, no re-fetch of already-seen rows)
        for ($i = 1; $i < count($exportedIds); $i++) {
            self::assertGreaterThan($exportedIds[$i - 1], $exportedIds[$i], "Row {$i} must be strictly ascending");
        }

        // No duplicates
        self::assertCount(1200, array_unique($exportedIds));

        // Exact set match against seeded ids
        sort($ids);
        self::assertSame($ids, $exportedIds);
    }
}
