<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

/**
 * Characterization of App\Contacto\Controller\ContactoController:
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

        $response = $this->request('GET', '/api/contactos/' . $contact->getId(), $token);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(['id', 'telefono', 'nombre', 'estado', 'canal', 'responsableId', 'email', 'fechas'], \array_keys($body));
        self::assertSame($contact->getId(), $body['id']);
        self::assertSame('+5491133334444', $body['telefono']);
        self::assertSame('Carla', $body['nombre']);
        self::assertSame('activo', $body['estado']);
        self::assertSame('whatsapp', $body['canal']);
        self::assertNull($body['responsableId']);
        self::assertNull($body['email']);
        self::assertSame(['creadoAt', 'actualizadoAt'], \array_keys($body['fechas']));
        // Raw database format, unlike the ISO timestamps of other endpoints.
        self::assertMatchesRegularExpression(self::DB_DATETIME, $body['fechas']['creadoAt']);
    }

    public function testDetailUnknownContactReturns404(): void
    {
        $token = $this->agentToken();

        $this->assertJsonResponse(404, ['error' => 'Contact not found'], $this->request('GET', '/api/contactos/999999999', $token));
    }

    // ── app_contacts_update ──

    public function testUpdateNormalizesPhoneAndPersistsFields(): void
    {
        $contact = $this->createContact('+5491133334444', 'Carla');
        $token = $this->agentToken();

        $response = $this->request('PUT', '/api/contactos/' . $contact->getId(), $token, [
            'nombre' => 'Carla B',
            'telefono' => '54 9 11 5555-6666',
            'estado' => 'inactivo',
            'canal' => 'otro',
            'responsableId' => '7',
            'email' => 'carla@example.com',
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->json($response);
        self::assertSame(['id', 'telefono', 'nombre', 'estado', 'canal', 'responsableId', 'email', 'fechas'], \array_keys($body));
        self::assertSame([
            'id' => $contact->getId(),
            'telefono' => '+5491155556666',
            'nombre' => 'Carla B',
            'estado' => 'inactivo',
            'canal' => 'otro',
            'responsableId' => 7,
            'email' => 'carla@example.com',
        ], \array_diff_key($body, ['fechas' => true]));
        self::assertMatchesRegularExpression(self::DB_DATETIME, $body['fechas']['actualizadoAt']);

        $row = $this->connection()->fetchAssociative('SELECT phone, name, status, channel, owner_id, email FROM contacts WHERE id = ?', [$contact->getId()]);
        self::assertSame('+5491155556666', $row['phone']);
        self::assertSame('Carla B', $row['name']);
        self::assertSame('inactivo', $row['status']);
        self::assertSame('otro', $row['channel']);
        self::assertSame(7, (int) $row['owner_id']);
        self::assertSame('carla@example.com', $row['email']);
        self::assertSame(0, $this->countRows('outbox_events'));
    }

    public function testUpdateWithNullOwnerClearsIt(): void
    {
        $contact = $this->createContact('+5491133334444', 'Carla');
        $contact->setResponsableId(5);
        $this->em->flush();
        $token = $this->agentToken();

        $body = $this->json($this->request('PUT', '/api/contactos/' . $contact->getId(), $token, ['responsableId' => null]));

        self::assertNull($body['responsableId']);
        self::assertNull($this->connection()->fetchOne('SELECT owner_id FROM contacts WHERE id = ?', [$contact->getId()]));
    }

    public function testUpdateErrors(): void
    {
        $contact = $this->createContact('+5491133334444', 'Carla');
        $this->createContact('+5491177778888', 'Other');
        $token = $this->agentToken();
        $uri = '/api/contactos/' . $contact->getId();

        // Characterization: current behavior, not necessarily desired.
        // An unknown contact is 400 on update but 404 on delete (same exception).
        $this->assertJsonResponse(400, ['error' => 'Contact not found'], $this->request('PUT', '/api/contactos/999999999', $token, ['nombre' => 'X']));
        $this->assertJsonResponse(400, ['error' => 'Invalid JSON body'], $this->request('PUT', $uri, $token, '{not json'));
        $this->assertJsonResponse(400, ['error' => 'Invalid phone number'], $this->request('PUT', $uri, $token, ['telefono' => '123']));
        $this->assertJsonResponse(400, ['error' => 'Another contact with this phone already exists'], $this->request('PUT', $uri, $token, ['telefono' => '+5491177778888']));
        $this->assertJsonResponse(400, ['error' => 'Invalid status. Must be "activo" or "inactivo"'], $this->request('PUT', $uri, $token, ['estado' => 'gone']));
        $this->assertJsonResponse(400, ['error' => 'Invalid channel. Must be "whatsapp" or "otro"'], $this->request('PUT', $uri, $token, ['canal' => 'sms']));

        $row = $this->connection()->fetchAssociative('SELECT phone, status, channel FROM contacts WHERE id = ?', [$contact->getId()]);
        self::assertSame(['phone' => '+5491133334444', 'status' => 'activo', 'channel' => 'whatsapp'], $row);
    }

    // ── app_contacts_create ──

    public function testCreateReadsTheSpanishKeysAndValues(): void
    {
        $token = $this->agentToken();

        $response = $this->request('POST', '/api/contactos', $token, [
            'telefono' => '54 9 11 2222-3333',
            'nombre' => 'Dario',
            'estado' => 'inactivo',
            'canal' => 'otro',
            'responsableId' => '4',
            'email' => 'dario@example.com',
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([
            'telefono' => '+5491122223333',
            'nombre' => 'Dario',
            'estado' => 'inactivo',
            'canal' => 'otro',
            'responsableId' => 4,
            'email' => 'dario@example.com',
        ], \array_diff_key($this->json($response), ['id' => true, 'fechas' => true]));
        self::assertSame(
            ['phone' => '+5491122223333', 'status' => 'inactivo', 'channel' => 'otro'],
            $this->connection()->fetchAssociative("SELECT phone, status, channel FROM contacts WHERE phone = '+5491122223333'"),
        );

        $this->assertJsonResponse(400, ['error' => 'Phone is required'], $this->request('POST', '/api/contactos', $token, ['phone' => '+5491122224444']));
    }

    // ── app_contacts_list ──

    public function testListFiltersByTheSpanishQueryKeysAndValues(): void
    {
        $activo = $this->createContact('+5491133330001', 'Lista Uno');
        $inactivo = $this->createContact('+5491133330002', 'Lista Dos');
        $inactivo->setEstado('inactivo');
        $inactivo->setCanal('otro');
        $this->em->flush();
        $token = $this->agentToken();

        $body = $this->json($this->request('GET', '/api/contactos?busqueda=Lista&estado=inactivo&canal=otro', $token));

        self::assertSame([$inactivo->getId()], \array_column($body['data'], 'id'));
        self::assertSame(
            ['id', 'telefono', 'nombre', 'estado', 'canal', 'responsableId', 'email', 'fechas', 'ticketActivoId'],
            \array_keys($body['data'][0]),
        );
        self::assertSame(['inactivo', 'otro'], [$body['data'][0]['estado'], $body['data'][0]['canal']]);

        $todos = $this->json($this->request('GET', '/api/contactos?busqueda=Lista&estado=active', $token));
        // Un valor viejo no agrega filtro, como cualquier valor desconocido.
        self::assertSame([$inactivo->getId(), $activo->getId()], \array_column($todos['data'], 'id'));
    }

    // ── app_contacts_delete ──

    public function testDeleteIsASoftDeactivation(): void
    {
        $contact = $this->createContact('+5491133334444', 'Carla');
        $token = $this->agentToken();

        $response = $this->request('DELETE', '/api/contactos/' . $contact->getId(), $token);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame('inactivo', $this->connection()->fetchOne('SELECT status FROM contacts WHERE id = ?', [$contact->getId()]));

        // The contact is still readable afterwards.
        $detail = $this->request('GET', '/api/contactos/' . $contact->getId(), $token);
        self::assertSame(200, $detail->getStatusCode());
        self::assertSame('inactivo', $this->json($detail)['estado']);
    }

    public function testDeleteUnknownContactReturns404(): void
    {
        $token = $this->agentToken();

        $this->assertJsonResponse(404, ['error' => 'Contact not found'], $this->request('DELETE', '/api/contactos/999999999', $token));
    }
}
