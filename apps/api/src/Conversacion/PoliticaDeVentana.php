<?php

declare(strict_types=1);

namespace App\Conversacion;

/**
 * Predicado sin dependencias para la regla de Meta de la ventana de conversación de 24 h de WhatsApp.
 *
 * Meta rechaza los mensajes de texto libre enviados más de 24 h después del último
 * mensaje entrante del cliente. El límite efectivo es la ventana literal de 24 h
 * menos un margen de seguridad configurable, que absorbe el desfase de reloj y la
 * latencia de red en el camino de envío (design.md D3).
 *
 * La semántica de null es deliberadamente asimétrica (design.md D8):
 *   - Envío:   un `$ultimoMensajeEntranteAt` null se trata como fail closed —
 *               nunca se envía texto libre si no se puede probar que está dentro de la ventana.
 *   - Cierre:  un `$ultimoMensajeEntranteAt` null se trata como fail open —
 *               nunca se cierra automáticamente un ticket sin mensaje entrante conocido
 *               (mitigación del cierre masivo para tickets preexistentes).
 */
final class PoliticaDeVentana
{
    public const HORAS_DE_VENTANA = 24;

    public function __construct(private readonly int $safetyMarginMinutes = 0)
    {
    }

    /** Envío: null => false (fail closed). */
    public function isAbiertaParaEnviar(?\DateTimeImmutable $ultimoMensajeEntranteAt, \DateTimeImmutable $now): bool
    {
        if ($ultimoMensajeEntranteAt === null) {
            return false;
        }

        return $now < $this->getVenceAt($ultimoMensajeEntranteAt);
    }

    /** Cierre por vencimiento: null => false (fail open — no cierra tickets sin mensaje entrante conocido). */
    public function isVencidaParaCerrar(?\DateTimeImmutable $ultimoMensajeEntranteAt, \DateTimeImmutable $now): bool
    {
        if ($ultimoMensajeEntranteAt === null) {
            return false;
        }

        return $now >= $this->getVenceAt($ultimoMensajeEntranteAt);
    }

    /** Plazo efectivo = lastInbound + 24h - safetyMargin. */
    public function getVenceAt(\DateTimeImmutable $ultimoMensajeEntranteAt): \DateTimeImmutable
    {
        return $ultimoMensajeEntranteAt
            ->modify(sprintf('+%d hours', self::HORAS_DE_VENTANA))
            ->modify(sprintf('-%d minutes', $this->safetyMarginMinutes));
    }
}
