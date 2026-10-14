<?php

declare(strict_types=1);

namespace App\Administracion;

/**
 * An agent tried to make active a workspace it is not assigned to.
 *
 * Only admins and supervisors may switch to any workspace. Controllers map it
 * to 403 with the exception message.
 */
class WorkspaceNotAssignedException extends \RuntimeException
{
}
