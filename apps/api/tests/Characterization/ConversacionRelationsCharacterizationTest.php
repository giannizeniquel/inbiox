<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Contacto\Contacto;
use App\Conversacion\Conversacion;
use App\Conversacion\DireccionDeMensaje;
use App\Conversacion\EstadoDeConversacion;
use App\Conversacion\Mensaje;
use App\Conversacion\Query\MensajeDTO;
use App\Conversacion\Repository\ConversacionRepository;
use App\Conversacion\Repository\MensajeRepository;
use App\Conversacion\TipoDeAdjunto;
use App\Conversacion\TipoDeRemitente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Pins what the Conversacion renames could break without a type error: the
 * Contacto/Conversacion/Mensaje relations (mappedBy/inversedBy strings), the
 * repository lookups whose field names live in findBy keys, the enum values
 * stored in the database and the JSON keys of MensajeDTO.
 */
final class ConversacionRelationsCharacterizationTest extends KernelTestCase
{
    private const TELEFONO = '+5491100000911';

    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->limpiar();
    }

    protected function tearDown(): void
    {
        $this->limpiar();
        parent::tearDown();
        $this->em = null;
    }

    public function testRelationsAndLookupsLoadAfterClear(): void
    {
        $contacto = new Contacto();
        $contacto->setTelefono(self::TELEFONO);
        $this->em->persist($contacto);

        $cerrada = $this->conversacion($contacto, 'pn-conv-a', EstadoDeConversacion::CERRADA);
        $abiertaA = $this->conversacion($contacto, 'pn-conv-a', EstadoDeConversacion::ABIERTA);
        $abiertaB = $this->conversacion($contacto, 'pn-conv-b', EstadoDeConversacion::ABIERTA);

        $entrante = $this->mensaje($abiertaA, DireccionDeMensaje::ENTRANTE, 'wamid.conv-char-1');
        $entrante->setTipoDeRemitente(TipoDeRemitente::CONTACTO);
        $saliente = $this->mensaje($abiertaA, DireccionDeMensaje::SALIENTE, 'wamid.conv-char-2');
        $saliente->setTipoDeRemitente(TipoDeRemitente::AUTOMATICO);
        $this->mensaje($abiertaB, DireccionDeMensaje::ENTRANTE, null);
        $this->em->flush();

        $ids = [$contacto->getId(), $cerrada->getId(), $abiertaA->getId(), $abiertaB->getId(), $entrante->getId(), $saliente->getId()];
        $this->em->clear();
        [$contactoId, $cerradaId, $abiertaAId, $abiertaBId, $entranteId, $salienteId] = $ids;

        $contacto = $this->em->find(Contacto::class, $contactoId);
        self::assertEqualsCanonicalizing([$cerradaId, $abiertaAId, $abiertaBId], $this->ids($contacto->getConversaciones()));
        $conversacion = $this->em->find(Conversacion::class, $abiertaAId);
        self::assertSame($contactoId, $conversacion->getContacto()?->getId());
        self::assertEqualsCanonicalizing([$entranteId, $salienteId], $this->ids($conversacion->getMensajes()));
        self::assertSame($abiertaAId, $this->em->find(Mensaje::class, $salienteId)->getConversacion()?->getId());

        $conversaciones = static::getContainer()->get(ConversacionRepository::class);
        self::assertSame($abiertaAId, $conversaciones->findAbiertaByContacto($contacto, 'pn-conv-a')?->getId());
        self::assertSame($abiertaBId, $conversaciones->findAbiertaByContacto($contacto, 'pn-conv-b')?->getId());
        self::assertNull($conversaciones->findAbiertaByContacto($contacto, 'pn-conv-none'));

        $mensajes = static::getContainer()->get(MensajeRepository::class);
        self::assertEqualsCanonicalizing([$entranteId, $salienteId], $this->ids($mensajes->findByConversacion($conversacion)));
        self::assertSame($salienteId, $mensajes->findByExternalId('wamid.conv-char-2')?->getId());

        $conn = $this->em->getConnection();
        self::assertSame(
            ['closed', 'open', 'open'],
            $conn->fetchFirstColumn('SELECT status FROM conversations WHERE contact_id = ? ORDER BY status', [$contactoId]),
        );
        self::assertSame(
            [['direction' => 'entrante', 'sender_type' => 'contacto'], ['direction' => 'saliente', 'sender_type' => 'automatico']],
            $conn->fetchAllAssociative('SELECT direction, sender_type FROM messages WHERE conversation_id = ? ORDER BY id', [$abiertaAId]),
        );
    }

    public function testStoredEnumValuesAndDtoKeys(): void
    {
        self::assertSame(['agente', 'automatico', 'contacto'], array_map(fn ($c) => $c->value, TipoDeRemitente::cases()));
        self::assertSame(['entrante', 'saliente'], array_map(fn ($c) => $c->value, DireccionDeMensaje::cases()));
        self::assertSame(['open', 'closed'], array_map(fn ($c) => $c->value, EstadoDeConversacion::cases()));
        self::assertSame(['imagen', 'video', 'audio', 'documento'], array_map(fn ($c) => $c->value, TipoDeAdjunto::cases()));

        $dto = new MensajeDTO(
            mensajeId: 7,
            direccion: 'saliente',
            contenido: 'Hola',
            creadoAt: '2026-10-01T10:00:00Z',
            adjunto: ['key' => 'k', 'mimeType' => 'image/png', 'filename' => 'a.png', 'size' => 1, 'kind' => 'imagen', 'providerId' => 'p', 'caption' => 'c'],
            agenteRemitenteId: 3,
            tipoDeRemitente: 'agente',
        );

        self::assertSame([
            'mensajeId' => 7,
            'direccion' => 'saliente',
            'contenido' => 'Hola',
            'creadoAt' => '2026-10-01T10:00:00Z',
            'adjunto' => ['key' => 'k', 'mimeType' => 'image/png', 'nombreDeArchivo' => 'a.png', 'tamanio' => 1, 'tipo' => 'imagen', 'providerId' => 'p', 'leyenda' => 'c'],
            'agenteRemitenteId' => 3,
            'tipoDeRemitente' => 'agente',
        ], $dto->toArray());
    }

    private function conversacion(Contacto $contacto, string $phoneNumberId, EstadoDeConversacion $estado): Conversacion
    {
        $conversacion = new Conversacion();
        $conversacion->setContacto($contacto);
        $conversacion->setPhoneNumberId($phoneNumberId);
        $conversacion->setEstado($estado->value);
        $this->em->persist($conversacion);

        return $conversacion;
    }

    private function mensaje(Conversacion $conversacion, DireccionDeMensaje $direccion, ?string $externalId): Mensaje
    {
        $mensaje = new Mensaje();
        $mensaje->setConversacion($conversacion);
        $mensaje->setDireccion($direccion->value);
        $mensaje->setContenido('text');
        $mensaje->setExternalId($externalId);
        $this->em->persist($mensaje);

        return $mensaje;
    }

    private function limpiar(): void
    {
        $conn = $this->em->getConnection();
        $conversaciones = 'SELECT c.id FROM conversations c JOIN contacts ct ON ct.id = c.contact_id WHERE ct.phone = ?';
        $conn->executeStatement("DELETE FROM messages WHERE conversation_id IN (SELECT id FROM ($conversaciones) x)", [self::TELEFONO]);
        $conn->executeStatement("DELETE FROM conversations WHERE id IN (SELECT id FROM ($conversaciones) x)", [self::TELEFONO]);
        $conn->executeStatement('DELETE FROM contacts WHERE phone = ?', [self::TELEFONO]);
    }

    /** @return list<int|null> */
    private function ids(iterable $entities): array
    {
        $ids = [];
        foreach ($entities as $entity) {
            $ids[] = $entity->getId();
        }

        return $ids;
    }
}
