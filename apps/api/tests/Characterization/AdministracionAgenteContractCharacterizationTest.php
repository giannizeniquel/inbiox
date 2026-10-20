<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Agente\Equipo;
use App\Disponibilidad\Turno;

/**
 * Contract stage 2, BU5 (`docs/contrato-api.md`, annex A.2, A.3, A.4 and A.6): the exact
 * keys, in order, of the administration, agent, auto-reply and auth responses, and the
 * request keys they read.
 */
final class AdministracionAgenteContractCharacterizationTest extends CharacterizationTestCase
{
    private const META = ['page', 'limit', 'total', 'haySiguiente'];

    public function testAdminListsUseTheSharedPaginationMeta(): void
    {
        $token = $this->loginAs('bu5-admin@characterization.local', ['ROLE_ADMINISTRADOR'], $this->createAgent('BU5 Admin'));

        foreach (['/api/administracion/agentes', '/api/administracion/equipos', '/api/administracion/espacios-de-trabajo', '/api/administracion/usuarios'] as $uri) {
            $body = $this->json($this->request('GET', $uri . '?page=1&limit=1', $token));
            self::assertSame(['data', 'meta'], \array_keys($body), $uri);
            self::assertSame(self::META, \array_keys($body['meta']), $uri);
        }

        $body = $this->json($this->request('GET', '/api/administracion/agentes?page=1&limit=1', $token));
        self::assertFalse($body['meta']['haySiguiente']);
    }

    public function testAdminAgentAndLinkKeys(): void
    {
        $token = $this->loginAs('bu5-admin@characterization.local', ['ROLE_ADMINISTRADOR']);

        $response = $this->request('POST', '/api/administracion/agentes', $token, ['nombre' => 'Nuevo', 'zonaHoraria' => 'America/Argentina/Buenos_Aires']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $agente = $this->json($response);
        self::assertSame(['id', 'nombre', 'activo', 'zonaHoraria', 'disponible', 'usuarioId', 'usuarioEmail', 'creadoAt', 'actualizadoAt'], \array_keys($agente));
        self::assertSame('Nuevo', $agente['nombre']);
        self::assertSame('America/Argentina/Buenos_Aires', $agente['zonaHoraria']);

        $body = $this->json($this->request('PUT', '/api/administracion/agentes/' . $agente['id'], $token, ['nombre' => 'Renombrado', 'activo' => false, 'zonaHoraria' => 'UTC']));
        self::assertSame(['Renombrado', false, 'UTC'], [$body['nombre'], $body['activo'], $body['zonaHoraria']]);

        $usuario = $this->createUser('bu5-link@characterization.local', ['ROLE_AGENTE']);
        $this->assertJsonResponse(400, ['error' => 'usuarioId is required and must be an integer'], $this->request('POST', '/api/administracion/agentes/' . $agente['id'] . '/vincular-usuario', $token, ['user_id' => $usuario->getId()]));
        $this->assertJsonResponse(200, ['id' => $agente['id'], 'usuarioId' => $usuario->getId(), 'usuarioEmail' => 'bu5-link@characterization.local'], $this->request('POST', '/api/administracion/agentes/' . $agente['id'] . '/vincular-usuario', $token, ['usuarioId' => $usuario->getId()]));
        self::assertSame(204, $this->request('DELETE', '/api/administracion/agentes/' . $agente['id'] . '/vincular-usuario', $token)->getStatusCode());
    }

    public function testAdminTeamAndWorkspaceKeys(): void
    {
        $agente = $this->createAgent('Miembro');
        $token = $this->loginAs('bu5-admin@characterization.local', ['ROLE_ADMINISTRADOR']);

        $response = $this->request('POST', '/api/administracion/espacios-de-trabajo', $token, ['phoneNumberId' => 'bu5-pn', 'nombreVisible' => 'Linea', 'activo' => true]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $espacio = $this->json($response);
        self::assertSame(['id', 'phoneNumberId', 'nombreVisible', 'activo', 'cantidadDeAgentes', 'agenteIds', 'creadoAt', 'actualizadoAt'], \array_keys($espacio));
        $this->assertJsonResponse(200, ['espacioDeTrabajoId' => $espacio['id'], 'agenteIds' => [$agente->getId()]], $this->request('PUT', '/api/administracion/espacios-de-trabajo/' . $espacio['id'] . '/agentes', $token, ['agenteIds' => [$agente->getId()]]));

        $response = $this->request('POST', '/api/administracion/equipos', $token, ['espacioDeTrabajoId' => $espacio['id'], 'nombre' => 'Soporte', 'activo' => true]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $equipo = $this->json($response);
        self::assertSame(['id', 'espacioDeTrabajoId', 'nombre', 'activo', 'cantidadDeAgentes', 'agenteIds', 'creadoAt', 'actualizadoAt'], \array_keys($equipo));
        self::assertSame([$espacio['id'], 'Soporte', true], [$equipo['espacioDeTrabajoId'], $equipo['nombre'], $equipo['activo']]);
        $this->assertJsonResponse(200, ['equipoId' => $equipo['id'], 'agenteIds' => [$agente->getId()]], $this->request('PUT', '/api/administracion/equipos/' . $equipo['id'] . '/agentes', $token, ['agenteIds' => [$agente->getId()]]));

        $body = $this->json($this->request('PUT', '/api/administracion/equipos/' . $equipo['id'], $token, ['nombre' => 'Ventas', 'activo' => false]));
        self::assertSame(['Ventas', false], [$body['nombre'], $body['activo']]);
    }

    public function testAdminUserKeys(): void
    {
        $agente = $this->createAgent('Con Usuario');
        $token = $this->loginAs('bu5-admin@characterization.local', ['ROLE_ADMINISTRADOR']);

        $response = $this->request('POST', '/api/administracion/usuarios', $token, ['email' => 'bu5-new@characterization.local', 'password' => 'secret-123', 'agenteId' => $agente->getId()]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $usuario = $this->json($response);
        self::assertSame(['id', 'email', 'roles', 'activo', 'agenteId', 'agenteNombre', 'creadoAt', 'actualizadoAt'], \array_keys($usuario));
        // Sin `roles` en el request, el usuario nace agente.
        self::assertSame(['ROLE_AGENTE', 'ROLE_USER'], $usuario['roles']);
        self::assertSame([$agente->getId(), 'Con Usuario'], [$usuario['agenteId'], $usuario['agenteNombre']]);

        $body = $this->json($this->request('PUT', '/api/administracion/usuarios/' . $usuario['id'], $token, ['activo' => false]));
        self::assertFalse($body['activo']);
    }

    public function testAgentSettingsKeys(): void
    {
        $espacio = $this->createWorkspace('bu5-pn-mi', 'Mi Linea');
        $agente = $this->createAgent('Agente BU5');
        $agente->setEspacioDeTrabajoActivo($espacio);
        $agente->addEspacioDeTrabajo($espacio);
        $espacio->addAgente($agente);
        $turno = new Turno($agente, 1, new \DateTimeImmutable('09:00'), new \DateTimeImmutable('17:00'));
        $agente->addTurno($turno);
        $this->em->persist($turno);
        $equipo = new Equipo($espacio, 'Soporte');
        $this->em->persist($equipo);
        $this->em->flush();
        $token = $this->loginAs('bu5-agent@characterization.local', ['ROLE_AGENTE'], $agente);

        $this->assertJsonResponse(200, [
            'perfil' => ['nombre' => 'Agente BU5', 'email' => 'bu5-agent@characterization.local', 'firma' => null],
            'notificaciones' => ['sonido' => true, 'escritorio' => true],
            'respuestaAutomatica' => ['habilitada' => false, 'texto' => null],
            'espacioDeTrabajo' => ['id' => $espacio->getId(), 'nombreVisible' => 'Mi Linea', 'phoneNumberId' => 'bu5-pn-mi'],
            'horarioDeAtencion' => [['diaDeLaSemana' => 1, 'inicio' => '09:00', 'fin' => '17:00']],
        ], $this->request('GET', '/api/mi/configuracion', $token));

        $this->assertJsonResponse(200, ['nombre' => 'Agente BU5', 'email' => 'bu5-agent@characterization.local', 'firma' => 'Saludos'], $this->request('PUT', '/api/mi/perfil', $token, ['firma' => 'Saludos']));
        $this->assertJsonResponse(200, ['sonido' => false, 'escritorio' => true], $this->request('PUT', '/api/mi/notificaciones', $token, ['sonido' => false]));
        $this->assertJsonResponse(200, ['habilitada' => true, 'texto' => 'Fuera de horario'], $this->request('PUT', '/api/mi/respuesta-automatica', $token, ['habilitada' => true, 'texto' => 'Fuera de horario']));

        $this->assertJsonResponse(200, [['id' => $equipo->getId(), 'espacioDeTrabajoId' => $espacio->getId(), 'nombre' => 'Soporte', 'activo' => true]], $this->request('GET', '/api/equipos', $token));
    }

    public function testMyWorkspaceKeys(): void
    {
        $espacio = $this->createWorkspace('bu5-pn-ws', 'Linea WS');
        $agente = $this->createAgent('Agente WS');
        $agente->addEspacioDeTrabajo($espacio);
        $espacio->addAgente($agente);
        $this->em->flush();
        $token = $this->loginAs('bu5-ws@characterization.local', ['ROLE_AGENTE'], $agente);

        $this->assertJsonResponse(404, ['espacioDeTrabajoId' => null, 'phoneNumberId' => null, 'nombreVisible' => null], $this->request('GET', '/api/mi/espacio-de-trabajo', $token));
        $expected = ['espacioDeTrabajoId' => $espacio->getId(), 'phoneNumberId' => 'bu5-pn-ws', 'nombreVisible' => 'Linea WS'];
        $this->assertJsonResponse(200, $expected, $this->request('PUT', '/api/mi/espacio-de-trabajo', $token, ['espacioDeTrabajoId' => $espacio->getId()]));
        $this->assertJsonResponse(200, $expected, $this->request('GET', '/api/mi/espacio-de-trabajo', $token));

        $lista = $this->json($this->request('GET', '/api/mi/espacios-de-trabajo', $token));
        self::assertSame(['id', 'phoneNumberId', 'nombreVisible', 'activo', 'creadoAt', 'actualizadoAt', 'cantidadDeAgentes', 'hayPendientes'], \array_keys($lista[0]));
        self::assertFalse($lista[0]['hayPendientes']);
    }

    public function testAuthMeAndLogoutKeys(): void
    {
        $token = $this->loginAs('bu5-me@characterization.local', ['ROLE_AGENTE'], $this->createAgent('Agente Me'));

        $body = $this->json($this->request('GET', '/api/auth/me', $token));
        self::assertSame(['id', 'email', 'roles', 'activo', 'agenteId', 'agenteNombre'], \array_keys($body));
        self::assertSame(['ROLE_AGENTE', 'ROLE_USER'], $body['roles']);
        self::assertSame('Agente Me', $body['agenteNombre']);

        $this->assertJsonResponse(200, ['mensaje' => 'Logged out successfully'], $this->request('POST', '/api/auth/logout', $token));
    }
}
