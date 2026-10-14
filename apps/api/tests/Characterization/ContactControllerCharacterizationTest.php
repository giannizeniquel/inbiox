<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

/**
 * Characterization of App\Contacto\Controller\ContactController:
 * app_contacts_detail, app_contacts_update, app_contacts_delete.
 */
final class ContactControllerCharacterizationTest extends CharacterizationTestCase
{
    private const DB_DATETIME = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    private function agentToken(): string
    {
        return $this->loginAs('contacts@characterization.local', ['ROLE_AGENT'], $this->createAgent('Me'));
    }

    // ── app_contacts_detail ──

    public function testDetailShape(): void
    {
        $contact = $this->createContact('+5491133334444', 'Carla');
        $token = $this->agentToken();

        $response = $this->request('GET', '/api/contacts/' . $contact->getId(), $token);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(['id', 'phone', 'name', 'status', 'channel', 'owner_id', 'email', 'timestamps'], \array_keys($body));
        self::assertSame($contact->getId(), $body['id']);
        self::assertSame('+5491133334444', $body['phone']);
        self::assertSame('Carla', $body['name']);
        self::assertSame('active', $body['status']);
        self::assertSame('whatsapp', $body['channel']);
        self::assertNull($body['owner_id']);
        self::assertNull($body['email']);
        self::assertSame(['created_at', 'updated_at'], \array_keys($body['timestamps']));
        // Raw database format, unlike the ISO timestamps of other endpoints.
        self::assertMatchesRegularExpression(self::DB_DATETIME, $body['timestamps']['created_at']);
    }

    public function testDetailUnknownContactReturns404(): void
    {
        $token = $this->agentToken();

        $this->assertJsonResponse(404, ['error' => 'Contact not found'], $this->request('GET', '/api/contacts/999999999', $token));
    }

    // ── app_contacts_update ──

    public function testUpdateNormalizesPhoneAndPersistsFields(): void
    {
        $contact = $this->createContact('+5491133334444', 'Carla');
        $token = $this->agentToken();

        $response = $this->request('PUT', '/api/contacts/' . $contact->getId(), $token, [
            'name' => 'Carla B',
            'phone' => '54 9 11 5555-6666',
            'status' => 'inactive',
            'channel' => 'other',
            'owner_id' => '7',
            'email' => 'carla@example.com',
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->json($response);
        self::assertSame(['id', 'phone', 'name', 'status', 'channel', 'owner_id', 'email', 'timestamps'], \array_keys($body));
        self::assertSame([
            'id' => $contact->getId(),
            'phone' => '+5491155556666',
            'name' => 'Carla B',
            'status' => 'inactive',
            'channel' => 'other',
            'owner_id' => 7,
            'email' => 'carla@example.com',
        ], \array_diff_key($body, ['timestamps' => true]));
        self::assertMatchesRegularExpression(self::DB_DATETIME, $body['timestamps']['updated_at']);

        $row = $this->connection()->fetchAssociative('SELECT phone, name, status, channel, owner_id, email FROM contacts WHERE id = ?', [$contact->getId()]);
        self::assertSame('+5491155556666', $row['phone']);
        self::assertSame('Carla B', $row['name']);
        self::assertSame('inactive', $row['status']);
        self::assertSame('other', $row['channel']);
        self::assertSame(7, (int) $row['owner_id']);
        self::assertSame('carla@example.com', $row['email']);
        self::assertSame(0, $this->countRows('outbox_events'));
    }

    public function testUpdateWithNullOwnerClearsIt(): void
    {
        $contact = $this->createContact('+5491133334444', 'Carla');
        $contact->setOwnerId(5);
        $this->em->flush();
        $token = $this->agentToken();

        $body = $this->json($this->request('PUT', '/api/contacts/' . $contact->getId(), $token, ['owner_id' => null]));

        self::assertNull($body['owner_id']);
        self::assertNull($this->connection()->fetchOne('SELECT owner_id FROM contacts WHERE id = ?', [$contact->getId()]));
    }

    public function testUpdateErrors(): void
    {
        $contact = $this->createContact('+5491133334444', 'Carla');
        $this->createContact('+5491177778888', 'Other');
        $token = $this->agentToken();
        $uri = '/api/contacts/' . $contact->getId();

        // Characterization: current behavior, not necessarily desired.
        // An unknown contact is 400 on update but 404 on delete (same exception).
        $this->assertJsonResponse(400, ['error' => 'Contact not found'], $this->request('PUT', '/api/contacts/999999999', $token, ['name' => 'X']));
        $this->assertJsonResponse(400, ['error' => 'Invalid JSON body'], $this->request('PUT', $uri, $token, '{not json'));
        $this->assertJsonResponse(400, ['error' => 'Invalid phone number'], $this->request('PUT', $uri, $token, ['phone' => '123']));
        $this->assertJsonResponse(400, ['error' => 'Another contact with this phone already exists'], $this->request('PUT', $uri, $token, ['phone' => '+5491177778888']));
        $this->assertJsonResponse(400, ['error' => 'Invalid status. Must be "active" or "inactive"'], $this->request('PUT', $uri, $token, ['status' => 'gone']));
        $this->assertJsonResponse(400, ['error' => 'Invalid channel. Must be "whatsapp" or "other"'], $this->request('PUT', $uri, $token, ['channel' => 'sms']));

        $row = $this->connection()->fetchAssociative('SELECT phone, status, channel FROM contacts WHERE id = ?', [$contact->getId()]);
        self::assertSame(['phone' => '+5491133334444', 'status' => 'active', 'channel' => 'whatsapp'], $row);
    }

    // ── app_contacts_delete ──

    public function testDeleteIsASoftDeactivation(): void
    {
        $contact = $this->createContact('+5491133334444', 'Carla');
        $token = $this->agentToken();

        $response = $this->request('DELETE', '/api/contacts/' . $contact->getId(), $token);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame('inactive', $this->connection()->fetchOne('SELECT status FROM contacts WHERE id = ?', [$contact->getId()]));

        // The contact is still readable afterwards.
        $detail = $this->request('GET', '/api/contacts/' . $contact->getId(), $token);
        self::assertSame(200, $detail->getStatusCode());
        self::assertSame('inactive', $this->json($detail)['status']);
    }

    public function testDeleteUnknownContactReturns404(): void
    {
        $token = $this->agentToken();

        $this->assertJsonResponse(404, ['error' => 'Contact not found'], $this->request('DELETE', '/api/contacts/999999999', $token));
    }
}
