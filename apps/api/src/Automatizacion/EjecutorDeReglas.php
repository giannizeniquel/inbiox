<?php

namespace App\Automatizacion;

use App\Automatizacion\Repository\RegistroDeReglaRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class EjecutorDeReglas
{
    /**
     * @var ReglaInterface[]
     */
    private array $rules = [];

    public function __construct(
        private RegistroDeReglaRepository $executionLogRepo,
        private EventDispatcherInterface $eventDispatcher,
        iterable $rules = [],
    ) {
        foreach ($rules as $rule) {
            $this->rules[] = $rule;
        }
    }

    /**
     * Add a rule to be executed.
     */
    public function addRule(ReglaInterface $rule): void
    {
        $this->rules[] = $rule;
    }

    /**
     * Execute all rules that support the given context.
     */
    public function execute(ContextoDeRegla $context): void
    {
        foreach ($this->rules as $rule) {
            if (!$rule->supports($context)) {
                continue;
            }

            $executionKey = $rule->buildExecutionKey($context);

            // Idempotency check
            if ($this->executionLogRepo->findByExecutionKey($executionKey)) {
                continue;
            }

            try {
                $rule->execute($context);

                // Log successful execution
                $log = new RegistroDeRegla(
                    $executionKey,
                    $rule->getName(),
                    $context->getTicketId(),
                    $context->getMessageId()
                );
                $this->executionLogRepo->save($log);
            } catch (\Throwable $e) {
                // Log failed execution but don't break the chain
                $log = new RegistroDeRegla(
                    $executionKey,
                    $rule->getName(),
                    $context->getTicketId(),
                    $context->getMessageId()
                );
                $log->setResultStatus('failed');
                $log->setResultData(['error' => $e->getMessage()]);
                $this->executionLogRepo->save($log);
            }
        }
    }
}