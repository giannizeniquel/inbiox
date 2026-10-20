<?php

namespace App\Contacto\UseCase;

use App\Contacto\Query\DetalleDeContactoDTO;
use App\Contacto\CanalDeContacto;
use App\Contacto\Contacto;
use App\Contacto\EstadoDeContacto;
use App\Contacto\Repository\ContactoRepository;
use App\Contacto\NormalizadorDeTelefono;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

class CreateContactoUseCase
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContactoRepository $contactoRepository,
    ) {}

    public function execute(array $data): DetalleDeContactoDTO
    {
        if (empty($data['telefono'])) {
            throw new \InvalidArgumentException('Phone is required');
        }

        $telefono = NormalizadorDeTelefono::normalizar($data['telefono']);

        $existing = $this->contactoRepository->findOneByTelefono($telefono);
        if ($existing) {
            throw new \InvalidArgumentException('Contact with this phone already exists');
        }

        $contacto = new Contacto();
        $contacto->setTelefono($telefono);

        if (isset($data['nombre'])) {
            $contacto->setNombre($data['nombre']);
        }

        if (isset($data['estado']) && is_string($data['estado']) && EstadoDeContacto::tryFrom($data['estado']) !== null) {
            $contacto->setEstado($data['estado']);
        }

        if (isset($data['canal']) && is_string($data['canal']) && CanalDeContacto::tryFrom($data['canal']) !== null) {
            $contacto->setCanal($data['canal']);
        }

        if (isset($data['responsableId']) && is_numeric($data['responsableId'])) {
            $contacto->setResponsableId((int) $data['responsableId']);
        }

        if (isset($data['email'])) {
            $contacto->setEmail($data['email']);
        }

        $this->entityManager->persist($contacto);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Defensa en profundidad: el chequeo previo de arriba cierra esta carrera en la
            // inmensa mayoría de los casos, pero una petición realmente concurrente puede
            // ganar igual la restricción única a nivel de base de datos al momento del flush. Se convierte
            // en el mismo error determinista que lanza el chequeo previo en lugar de
            // dejar que se propague sin capturar (lo que cerraría el EntityManager).
            throw new \InvalidArgumentException('Contact with this phone already exists');
        }

        return new DetalleDeContactoDTO(
            id: $contacto->getId(),
            telefono: $contacto->getTelefono(),
            nombre: $contacto->getNombre(),
            estado: $contacto->getEstado(),
            canal: $contacto->getCanal(),
            responsableId: $contacto->getResponsableId(),
            email: $contacto->getEmail(),
            marcasDeTiempo: [
                'creadoAt' => $contacto->getCreadoAt()->format('Y-m-d H:i:s'),
                'actualizadoAt' => $contacto->getActualizadoAt()->format('Y-m-d H:i:s'),
            ],
        );
    }
}