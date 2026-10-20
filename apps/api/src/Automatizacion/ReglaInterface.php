<?php

namespace App\Automatizacion;

interface ReglaInterface
{
    /**
     * Indica si esta regla admite el contexto dado.
     */
    public function supports(ContextoDeRegla $contexto): bool;

    /**
     * Ejecuta la acción de la regla.
     */
    public function execute(ContextoDeRegla $contexto): void;

    /**
     * Obtiene el nombre único de esta regla.
     */
    public function getNombre(): string;

    /**
     * Arma la clave de ejecución para la verificación de idempotencia.
     */
    public function armarClaveDeEjecucion(ContextoDeRegla $contexto): string;
}