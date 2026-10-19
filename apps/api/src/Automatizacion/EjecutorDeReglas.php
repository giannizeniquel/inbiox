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
     * Add a rule to be executed.
     */
    public function agregarRegla(ReglaInterface $regla): void
    {
        $this->reglas[] = $regla;
    }

    /**
     * Execute all rules that support the given context.
     */
    public function execute(ContextoDeRegla $contexto): void
    {
        foreach ($this->reglas as $regla) {
            if (!$regla->supports($contexto)) {
                continue;
            }

            $claveDeEjecucion = $regla->armarClaveDeEjecucion($contexto);

            // Idempotency check
            if ($this->registroDeReglaRepo->findByClaveDeEjecucion($claveDeEjecucion)) {
                continue;
            }

            try {
                $regla->execute($contexto);

                // Log successful execution
                $registro = new RegistroDeRegla(
                    $claveDeEjecucion,
                    $regla->getNombre(),
                    $contexto->getTicketId(),
                    $contexto->getMensajeId()
                );
                $this->registroDeReglaRepo->save($registro);
            } catch (\Throwable $e) {
                // Log failed execution but don't break the chain
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