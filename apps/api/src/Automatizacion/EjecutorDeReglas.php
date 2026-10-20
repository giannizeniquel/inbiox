<?php

namespace App\Automatizacion;

use App\Automatizacion\Repository\RegistroDeReglaRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class EjecutorDeReglas
{
    /**
     * @var ReglaInterface[]
     */
    private array $reglas = [];

    public function __construct(
        private RegistroDeReglaRepository $registroDeReglaRepo,
        private EventDispatcherInterface $eventDispatcher,
        iterable $reglas = [],
    ) {
        foreach ($reglas as $regla) {
            $this->reglas[] = $regla;
        }
    }

    /**
     * Agrega una regla para ejecutar.
     */
    public function agregarRegla(ReglaInterface $regla): void
    {
        $this->reglas[] = $regla;
    }

    /**
     * Ejecuta todas las reglas que admiten el contexto dado.
     */
    public function execute(ContextoDeRegla $contexto): void
    {
        foreach ($this->reglas as $regla) {
            if (!$regla->supports($contexto)) {
                continue;
            }

            $claveDeEjecucion = $regla->armarClaveDeEjecucion($contexto);

            // Verificación de idempotencia
            if ($this->registroDeReglaRepo->findByClaveDeEjecucion($claveDeEjecucion)) {
                continue;
            }

            try {
                $regla->execute($contexto);

                // Registra la ejecución exitosa
                $registro = new RegistroDeRegla(
                    $claveDeEjecucion,
                    $regla->getNombre(),
                    $contexto->getTicketId(),
                    $contexto->getMensajeId()
                );
                $this->registroDeReglaRepo->save($registro);
            } catch (\Throwable $e) {
                // Registra la ejecución fallida pero no corta la cadena
                $registro = new RegistroDeRegla(
                    $claveDeEjecucion,
                    $regla->getNombre(),
                    $contexto->getTicketId(),
                    $contexto->getMensajeId()
                );
                $registro->setEstadoDeResultado('failed');
                $registro->setDatosDeResultado(['error' => $e->getMessage()]);
                $this->registroDeReglaRepo->save($registro);
            }
        }
    }
}