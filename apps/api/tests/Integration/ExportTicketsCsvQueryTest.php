<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Query\ExportTicketsCsvQuery;
use App\Domain\Agent\Agent;
use App\Domain\Contact\Contact;
use App\Domain\Conversation\Conversation;
use App\Domain\Tag\Tag;
use App\Domain\Tag\TicketTag;
use App\Domain\Ticket\Ticket;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use League\Csv\Writer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `reports-csv-export` spec/design: `ExportTicketsCsvQuery` keyset-chunked
 * row streaming — filter reuse via `TicketFilterBuilder`, date-range clause
 * appended after builder params, 14-column contract, tag join.
 *
 * The 500/500/200 chunk-boundary proof (1200 seeded tickets) lives in
 * `ReportsCsvExportTest.php` (Phase 4, load-bearing).
 */
class ExportTicketsCsvQueryTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;
    private int $phoneSeq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM outbox_events');
        $conn->executeStatement('DELETE FROM ticket_events');
        $conn->executeStatement('DELETE FROM ticket_tags');
        $conn->executeStatement('DELETE FROM messages');
        $conn->executeStatement('DELETE FROM assignments');
        $conn->executeStatement('DELETE FROM tickets');
        $conn->executeStatement('DELETE FROM conversations');
        $conn->executeStatement('DELETE FROM contacts');
        $conn->executeStatement('DELETE FROM agents');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
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

    private function createAgent(string $name = 'Agent Smith'): int
    {
        $agent = new Agent($name);
        $this->em->persist($agent);
        $this->em->flush();

        $id = $agent->getId();
        \assert($id !== null);

        return $id;
    }

    /** @return list<array<string, string>> */
    private function runExport(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $agentId = null,
        ?string $status = null,
        ?string $tag = null,
        ?string $phoneNumberId = null,
    ): array {
        $query = static::getContainer()->get(ExportTicketsCsvQuery::class);
        $writer = Writer::createFromString();

        $query->stream($writer, $from, $to, $agentId, $status, $tag, $phoneNumberId);

        $reader = Reader::createFromString($writer->toString());
        $reader->setHeaderOffset(0);

        return [...$reader->getRecords()];
    }

    public function test_header_row_matches_column_contract(): void
    {
        $writer = Writer::createFromString();
        $query = static::getContainer()->get(ExportTicketsCsvQuery::class);
        $query->stream($writer, new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2030-01-01'));

        $reader = Reader::createFromString($writer->toString());
        self::assertSame(ExportTicketsCsvQuery::COLUMNS, $reader->fetchOne(0));
    }

    public function test_row_shape_has_all_fourteen_columns_with_tags_joined(): void
    {
        $agentId = $this->createAgent('Alice');
        $ticketId = $this->createTicket(
            status: 'resolved',
            tagNames: ['vip', 'urgente'],
            assignedAgentId: $agentId,
            createdAt: new \DateTimeImmutable('2026-01-15 10:00:00'),
            contactName: 'John Client',
        );

        $records = $this->runExport(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));

        self::assertCount(1, $records);
        $row = $records[0];
        self::assertCount(14, $row);
        self::assertSame((string) $ticketId, $row['ticket_id']);
        self::assertSame('John Client', $row['contact_name']);
        self::assertSame('Alice', $row['assigned_agent']);
        self::assertSame('resolved', $row['status']);
        self::assertSame('vip;urgente', $row['tags']);
        self::assertStringEndsWith('Z', $row['created_at']);
    }

    public function test_date_range_filters_on_created_at(): void
    {
        $this->createTicket(createdAt: new \DateTimeImmutable('2026-01-01 00:00:00'));
        $this->createTicket(createdAt: new \DateTimeImmutable('2026-02-01 00:00:00'));

        $records = $this->runExport(new \DateTimeImmutable('2026-01-01 00:00:00'), new \DateTimeImmutable('2026-01-31 23:59:59'));

        self::assertCount(1, $records);
    }

    public function test_no_status_filter_includes_every_status(): void
    {
        foreach (['new', 'active', 'waiting', 'resolved', 'closed'] as $status) {
            $this->createTicket(status: $status, createdAt: new \DateTimeImmutable('2026-01-10'));
        }

        $records = $this->runExport(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));

        self::assertCount(5, $records);
    }

    public function test_status_filter_narrows_results(): void
    {
        $this->createTicket(status: 'new', createdAt: new \DateTimeImmutable('2026-01-10'));
        $this->createTicket(status: 'closed', createdAt: new \DateTimeImmutable('2026-01-10'));

        $records = $this->runExport(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'), status: 'closed');

        self::assertCount(1, $records);
        self::assertSame('closed', $records[0]['status']);
    }

    public function test_tag_and_semantics_and_five_tag_cap_reused_from_builder(): void
    {
        $both = $this->createTicket(tagNames: ['vip', 'urgente'], createdAt: new \DateTimeImmutable('2026-01-10'));
        $this->createTicket(tagNames: ['vip'], createdAt: new \DateTimeImmutable('2026-01-10'));

        $records = $this->runExport(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'), tag: 'vip,urgente');

        self::assertCount(1, $records);
        self::assertSame((string) $both, $records[0]['ticket_id']);
    }

    public function test_more_than_five_tags_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->runExport(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'), tag: 'a,b,c,d,e,f');
    }

    public function test_agent_id_filter_narrows_results(): void
    {
        $agentA = $this->createAgent('Agent A');
        $agentB = $this->createAgent('Agent B');
        $ownTicket = $this->createTicket(assignedAgentId: $agentA, createdAt: new \DateTimeImmutable('2026-01-10'));
        $this->createTicket(assignedAgentId: $agentB, createdAt: new \DateTimeImmutable('2026-01-10'));

        $records = $this->runExport(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'), agentId: (string) $agentA);

        self::assertCount(1, $records);
        self::assertSame((string) $ownTicket, $records[0]['ticket_id']);
    }

    public function test_stream_returns_total_row_count(): void
    {
        $this->createTicket(createdAt: new \DateTimeImmutable('2026-01-10'));
        $this->createTicket(createdAt: new \DateTimeImmutable('2026-01-11'));

        $writer = Writer::createFromString();
        $query = static::getContainer()->get(ExportTicketsCsvQuery::class);
        $count = $query->stream($writer, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));

        self::assertSame(2, $count);
    }
}
