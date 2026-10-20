<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Roles del contrato de la API, etapa 2 (`docs/contrato-api.md`, secciones 3.3 y 4):
 * `ROLE_ADMIN` → `ROLE_ADMINISTRADOR` y `ROLE_AGENT` → `ROLE_AGENTE` en `user.roles` (JSON).
 * `ROLE_SUPERVISOR` no cambia y `ROLE_USER` no se guarda.
 *
 * Se reemplaza el token con comillas (`"ROLE_ADMIN"`) sobre el texto del JSON: `ROLE_ADMIN` es
 * prefijo de `ROLE_ADMINISTRADOR`, así que un reemplazo sin comillas se aplicaría dos veces.
 * Con comillas, una segunda corrida o una fila que ya tiene el nombre nuevo no cambian, y los
 * roles desconocidos (`ROLE_BOGUS`) u otras mayúsculas pasan igual. `REPLACE` y `JSON_CONTAINS`
 * distinguen mayúsculas. `down()` aplica el mapa inverso. Los mapas son literales a propósito:
 * la migración no depende del código actual.
 */
final class Version20261020210000 extends AbstractMigration
{
    /** Rol viejo => rol nuevo. */
    private const ROLES = [
        'ROLE_ADMIN' => 'ROLE_ADMINISTRADOR',
        'ROLE_AGENT' => 'ROLE_AGENTE',
    ];

    public function getDescription(): string
    {
        return 'User roles: ROLE_ADMIN and ROLE_AGENT become ROLE_ADMINISTRADOR and ROLE_AGENTE';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ROLES as $viejo => $nuevo) {
            $this->reemplazar($viejo, $nuevo);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::ROLES as $viejo => $nuevo) {
            $this->reemplazar($nuevo, $viejo);
        }
    }

    private function reemplazar(string $origen, string $destino): void
    {
        $this->addSql(
            'UPDATE `user` SET roles = CAST(REPLACE(CAST(roles AS CHAR), ?, ?) AS JSON) WHERE JSON_CONTAINS(roles, ?)',
            [\json_encode($origen), \json_encode($destino), \json_encode($origen)],
        );
    }
}
