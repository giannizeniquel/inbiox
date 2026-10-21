<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Administracion\EspacioDeTrabajo;
use App\Agente\Agente;
use App\Compartido\Storage\AdjuntoStorageService;
use App\Conversacion\Mensaje;
use App\Ticket\Ticket;

/**
 * Permission matrix: for one representative route of every guarded group
 * (admin only, supervisor or admin, agent, authenticated only, voter guarded)
 * and every role, the HTTP status the API answers. It combines
 * `access_control`, class and method `#[IsGranted]`, `isGranted()` calls and
 * the voters, so a role string left behind by a rename shows up as a changed
 * status here.
 *
 * The expectations must stay identical across the role rename; only the role
 * names in ROLES change.
 */
final class PermissionMatrixCharacterizationTest extends CharacterizationTestCase
{
    /** Roles stored for each profile. `sin_rol` only gets the implicit ROLE_USER. */
    private const ROLES = [
        'administrador' => ['ROLE_ADMINISTRADOR'],
        'supervisor' => ['ROLE_SUPERVISOR'],
        'agente' => ['ROLE_AGENTE'],
        'sin_rol' => [],
    ];

    /**
     * route => [method, uri, body, [administrador, supervisor, agente, sin_rol]].
     * Placeholders: {agenteSinUsuario}, {otroAgente}, {espacio}, {espacioAjeno},
     * {ticket}, {contacto}, {mensaje}, {agenteDelActor}.
     */
    private const MATRIZ = [
        'admin users list' => ['GET', '/api/administracion/usuarios', null, [200, 403, 403, 403]],
        'admin workspaces list' => ['GET', '/api/administracion/espacios-de-trabajo', null, [200, 403, 403, 403]],
        'admin agents list' => ['GET', '/api/administracion/agentes', null, [200, 200, 403, 403]],
        'admin teams list' => ['GET', '/api/administracion/equipos', null, [200, 200, 403, 403]],
        'admin agent unlink user' => ['DELETE', '/api/administracion/agentes/{agenteSinUsuario}/vincular-usuario', null, [400, 403, 403, 403]],
        'outbox publish' => ['POST', '/admin/outbox/publish', null, [200, 403, 403, 403]],
        'teams list' => ['GET', '/api/equipos', null, [200, 200, 200, 403]],
        'my settings' => ['GET', '/api/mi/configuracion', null, [200, 200, 200, 403]],
        'my auto reply' => ['PUT', '/api/mi/respuesta-automatica', [], [200, 200, 200, 403]],
        'my shifts' => ['GET', '/api/mi/turnos', null, [200, 200, 200, 403]],
        'my workspaces' => ['GET', '/api/mi/espacios-de-trabajo', null, [200, 200, 200, 200]],
        'set my workspace to an unassigned one' => ['PUT', '/api/mi/espacio-de-trabajo', ['espacioDeTrabajoId' => '{espacioAjeno}'], [200, 200, 403, 403]],
        'auth me' => ['GET', '/api/auth/me', null, [200, 200, 200, 200]],
        'mercure token' => ['GET', '/api/mercure/token', null, [200, 200, 200, 403]],
        'tickets list' => ['GET', '/api/tickets', null, [200, 200, 200, 403]],
        'contacts list' => ['GET', '/api/contactos', null, [200, 200, 200, 403]],
        'contacts import without file' => ['POST', '/api/contactos/importar', null, [400, 400, 403, 403]],
        'contact timeline' => ['GET', '/api/contactos/{contacto}/linea-de-tiempo', null, [200, 200, 200, 403]],
        'reports frt' => ['GET', '/api/reportes/frt?desde=2026-01-01T00:00:00Z&hasta=2026-01-02T00:00:00Z', null, [200, 200, 200, 403]],
        'reports export' => ['GET', '/api/reportes/exportar?desde=2026-01-01T00:00:00Z&hasta=2026-01-02T00:00:00Z', null, [200, 200, 403, 403]],
        'available agents of a workspace' => ['GET', '/api/espacios-de-trabajo/{espacio}/agentes-disponibles', null, [200, 200, 200, 403]],
        'agent availability' => ['GET', '/api/agentes/{otroAgente}/disponibilidad', null, [200, 200, 200, 403]],
        'resolve a ticket owned by another agent' => ['POST', '/api/tickets/{ticket}/resolver', null, [200, 200, 403, 403]],
        // 409 AGENT_NOT_ACTIVE comes after the ASSIGN voter: the voter let it through.
        'assign a ticket owned by another agent' => ['POST', '/api/tickets/{ticket}/asignar', ['agenteId' => '{agenteDelActor}', 'asignadoPor' => 'matriz'], [409, 409, 403, 403]],
        'media of a message on a ticket owned by another agent' => ['GET', '/api/mensajes/{mensaje}/adjunto', null, [200, 200, 403, 403]],
    ];

    private const PERFILES = ['administrador', 'supervisor', 'agente', 'sin_rol'];

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $storage = $this->createMock(AdjuntoStorageService::class);
        $storage->method('temporaryUrl')->willReturnCallback(fn (string $key) => 'https://media.test/' . $key . '?signed');
        static::getContainer()->set(AdjuntoStorageService::class, $storage);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function casos(): iterable
    {
        foreach (\array_keys(self::MATRIZ) as $ruta) {
            foreach (self::PERFILES as $perfil) {
                yield $ruta . ' as ' . $perfil => [$ruta, $perfil];
            }
        }
    }

    /**
     * @dataProvider casos
     */
    public function testStatusPerRouteAndRole(string $ruta, string $perfil): void
    {
        [$method, $uri, $body, $esperados] = self::MATRIZ[$ruta];
        $token = $this->escenario($perfil);

        $response = $this->request($method, $this->resolver($uri), $token, $this->resolverCuerpo($body));

        $esperado = $esperados[\array_search($perfil, self::PERFILES, true)];
        self::assertSame($esperado, $response->getStatusCode(), $ruta . ' as ' . $perfil . ': ' . $response->getContent());
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function topicos(): iterable
    {
        yield 'administrador' => ['administrador', true];
        yield 'supervisor' => ['supervisor', true];
        yield 'agente' => ['agente', false];
    }

    /**
     * @dataProvider topicos
     */
    public function testMercureTokenTopicsPerRole(string $perfil, bool $comodin): void
    {
        $token = $this->escenario($perfil);

        $response = $this->request('GET', '/api/mercure/token', $token);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $agenteId = $this->ids['agenteDelActor'];
        $esperados = ['tickets', 'agentes', "agentes/{$agenteId}/bandeja", "agentes/{$agenteId}/disponibilidad", "agentes/{$agenteId}/turnos"];
        if ($comodin) {
            $esperados[] = '*';
        }
        self::assertSame($esperados, $this->topicosSuscritos($this->json($response)['token']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function perfiles(): iterable
    {
        foreach (self::PERFILES as $perfil) {
            yield $perfil => [$perfil];
        }
    }

    /**
     * @dataProvider perfiles
     */
    public function testAuthMeReturnsTheStoredRolesPlusRoleUser(string $perfil): void
    {
        $token = $this->escenario($perfil);

        $body = $this->json($this->request('GET', '/api/auth/me', $token));

        self::assertSame([...self::ROLES[$perfil], 'ROLE_USER'], $body['roles']);
    }

    /**
     * Admin and supervisor see every active workspace; the others only theirs.
     *
     * @dataProvider perfiles
     */
    public function testMyWorkspacesScopePerRole(string $perfil): void
    {
        $token = $this->escenario($perfil);

        $body = $this->json($this->request('GET', '/api/mi/espacios-de-trabajo', $token));

        $esperados = \in_array($perfil, ['administrador', 'supervisor'], true)
            ? [$this->ids['espacioAjeno'], $this->ids['espacio']]
            : [$this->ids['espacio']];
        $ids = \array_map(fn (array $w) => $w['id'], $body);
        \sort($ids);
        \sort($esperados);
        self::assertSame($esperados, $ids);
    }

    /**
     * A row that still holds the legacy names (the roles migration not run) gets no
     * privilege: only the implicit ROLE_USER, like `sin_rol`.
     */
    public function testLegacyRoleNamesGrantNothing(): void
    {
        $this->escenario('agente');
        $token = $this->loginAs('matriz-legado@characterization.local', ['ROLE_ADMIN', 'ROLE_AGENT'], $this->createAgent('Legado'));

        $this->assertSecurityForbidden($this->request('GET', '/api/administracion/usuarios', $token));
        $this->assertSecurityForbidden($this->request('GET', '/api/tickets', $token));
        self::assertSame(200, $this->request('GET', '/api/auth/me', $token)->getStatusCode());
    }

    // ────────────────────────────────────────────────────────
    //  Fixtures
    // ────────────────────────────────────────────────────────

    /**
     * One workspace with the actor's agent and another agent, a ticket owned
     * by the other agent with a message that has media, an agent without a
     * user, and a second workspace the actor is not assigned to.
     */
    private function escenario(string $perfil): string
    {
        $espacio = $this->createWorkspace('matriz-pnid-1', 'Matriz Uno');
        $espacioAjeno = $this->createWorkspace('matriz-pnid-2', 'Matriz Dos');

        $agenteDelActor = $this->agenteEn($espacio, 'Actor ' . $perfil);
        $otroAgente = $this->agenteEn($espacio, 'Otro Agente');
        $this->createUser('matriz-otro@characterization.local', self::ROLES['agente'], $otroAgente);
        $agenteSinUsuario = $this->createAgent('Sin Usuario');

        $ticket = $this->createTicket('activo', (int) $otroAgente->getId());
        $ticket->setPhoneNumberId($espacio->getPhoneNumberId());
        $this->em->flush();
        $mensaje = $this->mensajeConAdjunto($ticket);

        $this->ids = [
            'espacio' => (int) $espacio->getId(),
            'espacioAjeno' => (int) $espacioAjeno->getId(),
            'agenteDelActor' => (int) $agenteDelActor->getId(),
            'otroAgente' => (int) $otroAgente->getId(),
            'agenteSinUsuario' => (int) $agenteSinUsuario->getId(),
            'ticket' => (int) $ticket->getId(),
            'contacto' => (int) $ticket->getContacto()->getId(),
            'mensaje' => $mensaje,
        ];

        return $this->loginAs("matriz-{$perfil}@characterization.local", self::ROLES[$perfil], $agenteDelActor);
    }

    private function agenteEn(EspacioDeTrabajo $espacio, string $nombre): Agente
    {
        $agente = $this->createAgent($nombre);
        $agente->addEspacioDeTrabajo($espacio);
        $espacio->addAgente($agente);
        $agente->setEspacioDeTrabajoActivo($espacio);
        $this->em->flush();

        return $agente;
    }

    private function mensajeConAdjunto(Ticket $ticket): int
    {
        $mensaje = new Mensaje();
        $mensaje->setConversacion($ticket->getConversacion());
        $mensaje->setDireccion('entrante');
        $mensaje->setContenido('');
        $mensaje->setAdjunto(['key' => 'media/matriz.jpg', 'kind' => 'imagen']);
        $this->em->persist($mensaje);
        $this->em->flush();

        return (int) $mensaje->getId();
    }

    private function resolver(string $uri): string
    {
        return (string) \preg_replace_callback('/\{(\w+)\}/', fn (array $m) => (string) $this->ids[$m[1]], $uri);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>|null
     */
    private function resolverCuerpo(?array $body): ?array
    {
        if (null === $body) {
            return null;
        }

        return \array_map(
            fn (mixed $valor) => \is_string($valor) && \preg_match('/^\{(\w+)\}$/', $valor, $m) ? $this->ids[$m[1]] : $valor,
            $body,
        );
    }

    /**
     * Reads the `mercure.subscribe` claim without verifying the signature.
     *
     * @return list<string>
     */
    private function topicosSuscritos(string $jwt): array
    {
        $payload = \explode('.', $jwt)[1];
        $claims = \json_decode((string) \base64_decode(\strtr($payload, '-_', '+/'), true), true, 512, \JSON_THROW_ON_ERROR);

        return $claims['mercure']['subscribe'];
    }
}
