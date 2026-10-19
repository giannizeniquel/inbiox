<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Administracion\Controller\EspacioDeTrabajoActivoDTO;
use App\Administracion\EspacioDeTrabajo;
use App\Administracion\Query\EspacioDeTrabajoDTO;
use App\Administracion\Usuario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Pins what the Administracion renames could break without a type error: the
 * security user class wired in security.yaml (provider by email and password
 * hasher) and the JSON keys of the workspace DTOs, whose properties are Spanish.
 * The login, refresh and logout flows are covered by AuthControllerTest.
 */
final class AdministracionContractCharacterizationTest extends KernelTestCase
{
    public function testSecurityLoadsUsuarioByEmailAndHashesItsPassword(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('DELETE FROM refresh_tokens');
        $em->getConnection()->executeStatement('DELETE FROM agents');
        $em->getConnection()->executeStatement('DELETE FROM `user`');

        $hasher = static::getContainer()->get(PasswordHasherFactoryInterface::class)->getPasswordHasher(Usuario::class);
        $usuario = new Usuario();
        $usuario->setEmail('contract@inbiox.local');
        $usuario->setPassword($hasher->hash('secret-123'));
        $usuario->setRoles(['ROLE_AGENT']);
        $em->persist($usuario);
        $em->flush();
        $em->clear();

        $cargado = static::getContainer()->get(UserProviderInterface::class)->loadUserByIdentifier('contract@inbiox.local');

        self::assertInstanceOf(Usuario::class, $cargado);
        self::assertSame('contract@inbiox.local', $cargado->getUserIdentifier());
        self::assertSame(['ROLE_AGENT', 'ROLE_USER'], $cargado->getRoles());
        self::assertTrue($hasher->verify((string) $cargado->getPassword(), 'secret-123'));
        self::assertTrue(static::getContainer()->get(UserProviderInterface::class)->supportsClass(Usuario::class));
    }

    public function testWorkspaceDtosKeepTheirJsonKeys(): void
    {
        $espacio = new EspacioDeTrabajo('pn-contract', 'Main line');
        (new \ReflectionProperty(EspacioDeTrabajo::class, 'id'))->setValue($espacio, 4);

        self::assertSame(
            ['workspace_id' => 4, 'phone_number_id' => 'pn-contract', 'display_name' => 'Main line'],
            EspacioDeTrabajoActivoDTO::fromEntity($espacio)->toArray(),
        );

        $dto = new EspacioDeTrabajoDTO(
            id: 4,
            phoneNumberId: 'pn-contract',
            nombreVisible: 'Main line',
            activo: true,
            creadoAt: '2026-10-01T10:00:00+00:00',
            actualizadoAt: '2026-10-02T10:00:00+00:00',
            cantidadDeAgentes: 3,
            hasPendientes: true,
        );

        self::assertSame([
            'id' => 4,
            'phone_number_id' => 'pn-contract',
            'display_name' => 'Main line',
            'is_active' => true,
            'created_at' => '2026-10-01T10:00:00+00:00',
            'updated_at' => '2026-10-02T10:00:00+00:00',
            'agent_count' => 3,
            'has_pending' => true,
        ], $dto->toArray());
    }
}
