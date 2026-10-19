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
        private RegistroDeReglaRepository $executionLogRepo,
        private EventDispatcherInterface $eventDispatcher,
        iterable $rules = [],
    ) {
        foreach ($rules as $rule) {
            $this->reglas[] = $rule;
        }
    }

    /**
     * Add a rule to be executed.
     */
    public function agregarRegla(ReglaInterface $rule): void
    {
        $this->reglas[] = $rule;
    }

    /**
     * Execute all rules that support the given context.
     */
    public function execute(ContextoDeRegla $context): void
    {
        foreach ($this->reglas as $rule) {
            if (!$rule->supports($context)) {
                continue;
            }

            $executionKey = $rule->armarClaveDeEjecucion($context);

            // Idempotency check
            if ($this->executionLogRepo->findByClaveDeEjecucion($executionKey)) {
                continue;
            }

            try {
                $rule->execute($context);

                // Log successful execution
                $log = new RegistroDeRegla(
                    $executionKey,
                    $rule->getNombre(),
                    $context->getTicketId(),
                    $context->getMensajeId()
                );
                $this->executionLogRepo->save($log);
            } catch (\Throwable $e) {
                // Log failed execution but don't break the chain
                $log = new RegistroDeRegla(
                    $executionKey,
                    $rule->getNombre(),
                    $context->getTicketId(),
                    $context->getMensajeId()
                );
                $log->setEstadoDeResultado('failed');
                $log->setDatosDeResultado(['error' => $e->getMessage()]);
                $this->executionLogRepo->save($log);
            }
        }
    }
}