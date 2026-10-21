<?php

declare(strict_types=1);

namespace App\Reporte\Query;

use App\Agente\Repository\AgenteRepository;
use App\Disponibilidad\DisponibilidadDeAgenteService;
use App\Reporte\Repository\ReporteRepository;

/**
 * Servicio para las queries de reportes y métricas.
 *
 * Orquesta la obtención de los datos de reportes y da formato a las respuestas.
 * Todas las fechas deben estar en UTC.
 *
 * Alcance por responsabilidad:
 * - Todos los métodos aceptan un parámetro opcional ?int $agenteId para filtrar a nivel de query
 * - Cuando se indica, todos los resultados se acotan al agente dado
 * - El controlador (ReporteController) decide el alcance según el rol del usuario
 */
class ReporteService
{
    public function __construct(
        private ReporteRepository $reporteRepository,
        private AgenteRepository $agenteRepository,
        private DisponibilidadDeAgenteService $disponibilidadDeAgenteService,
    ) {}

    /**
     * Obtiene las métricas de tiempo de primera respuesta.
     *
     * @param \DateTimeImmutable $from Inicio del rango de fechas (UTC)
     * @param \DateTimeImmutable $to Fin del rango de fechas (UTC)
     * @param int|null $agenteId Filtro opcional por agente asignado
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @param int|null $equipoId Filtro opcional por equipo (selección única)
     * @return array{frtPromedioSegundos: float|null, frtMinimoSegundos: int|null, frtMaximoSegundos: int|null, frtPromedioMinutos: float|null, frtMinimoMinutos: int|null, frtMaximoMinutos: int|null}
     */
    public function getFrt(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agenteId = null, ?string $phoneNumberId = null, ?int $equipoId = null): array
    {
        $this->validarRangoDeFechas($from, $to);

        return $this->reporteRepository->getMetricasDeFrt($from, $to, $agenteId, $phoneNumberId, $equipoId);
    }

    /**
     * Obtiene las métricas de tiempo de resolución.
     *
     * @param \DateTimeImmutable $from Inicio del rango de fechas (UTC)
     * @param \DateTimeImmutable $to Fin del rango de fechas (UTC)
     * @param int|null $agenteId Filtro opcional por agente asignado
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @param int|null $equipoId Filtro opcional por equipo (selección única)
     * @return array{rtPromedioSegundos: float|null, rtMinimoSegundos: int|null, rtMaximoSegundos: int|null, rtPromedioMinutos: float|null, rtMinimoMinutos: int|null, rtMaximoMinutos: int|null}
     */
    public function getRt(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agenteId = null, ?string $phoneNumberId = null, ?int $equipoId = null): array
    {
        $this->validarRangoDeFechas($from, $to);

        return $this->reporteRepository->getMetricasDeRt($from, $to, $agenteId, $phoneNumberId, $equipoId);
    }

    /**
     * Obtiene la cantidad de tickets por estado.
     *
     * @param \DateTimeImmutable $from Inicio del rango de fechas (UTC)
     * @param \DateTimeImmutable $to Fin del rango de fechas (UTC)
     * @param int|null $agenteId Filtro opcional por agente asignado
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @param int|null $equipoId Filtro opcional por equipo (selección única)
     * @return array{nuevo: int, activo: int, en_espera: int, resuelto: int, cerrado: int}
     */
    public function getConteos(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agenteId = null, ?string $phoneNumberId = null, ?int $equipoId = null): array
    {
        $this->validarRangoDeFechas($from, $to);

        return $this->reporteRepository->getConteosPorEstado($from, $to, $agenteId, $phoneNumberId, $equipoId);
    }

    /**
     * Obtiene el volumen de tickets a lo largo del tiempo.
     *
     * @param \DateTimeImmutable $from Inicio del rango de fechas (UTC)
     * @param \DateTimeImmutable $to Fin del rango de fechas (UTC)
     * @param int|null $agenteId Filtro opcional por agente asignado
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @param int|null $equipoId Filtro opcional por equipo (selección única)
     * @return array<int, array{fecha: string, ticketsCreados: int, ticketsResueltos: int}>
     */
    public function getVolumen(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agenteId = null, ?string $phoneNumberId = null, ?int $equipoId = null): array
    {
        $this->validarRangoDeFechas($from, $to);

        return $this->reporteRepository->getVolumenEnElTiempo($from, $to, $agenteId, $phoneNumberId, $equipoId);
    }

    /**
     * Obtiene las métricas de rendimiento de los agentes.
     *
     * @param \DateTimeImmutable $from Inicio del rango de fechas (UTC)
     * @param \DateTimeImmutable $to Fin del rango de fechas (UTC)
     * @param int|null $agenteId Filtro opcional por un agente específico
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @param int|null $equipoId Filtro opcional por equipo (selección única)
     * @return array<int, array{agenteId: int, agenteNombre: string|null, ticketsAsignados: int, ticketsResueltos: int, ticketsActivosActuales: int, maximoDeTicketsActivos: int, cargaActual: float, enTurno: bool, frtPromedioSegundos: float|null, frtPromedioMinutos: float|null, rtPromedioSegundos: float|null, rtPromedioMinutos: float|null}>
     */
    public function getRendimientoDeAgentes(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $agenteId = null, ?string $phoneNumberId = null, ?int $equipoId = null): array
    {
        $this->validarRangoDeFechas($from, $to);

        $rows = $this->reporteRepository->getRendimientoDeAgentes($from, $to, $agenteId, $phoneNumberId, $equipoId);

        return $this->agregarEnTurno($rows);
    }

    /**
     * Agrega `enTurno` (solo según el horario, evaluado ahora) justo después de
     * `cargaActual` en cada fila de agente. Un agente que no se puede cargar se
     * informa como fuera de turno.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function agregarEnTurno(array $rows): array
    {
        $now = new \DateTimeImmutable();
        $mapaDeAgentes = [];
        if (!empty($rows)) {
            $agenteIds = array_map(fn($r) => (int) $r['agenteId'], $rows);
            foreach ($this->agenteRepository->findByIds($agenteIds) as $agente) {
                $mapaDeAgentes[$agente->getId()] = $agente;
            }
        }

        $enriched = [];
        foreach ($rows as $row) {
            $enTurno = false;
            if (isset($mapaDeAgentes[$row['agenteId']])) {
                $enTurno = $this->disponibilidadDeAgenteService->isEnTurno($mapaDeAgentes[$row['agenteId']], $now);
            }

            $enrichedRow = [];
            foreach ($row as $key => $value) {
                $enrichedRow[$key] = $value;
                if ($key === 'cargaActual') {
                    $enrichedRow['enTurno'] = $enTurno;
                }
            }
            $enriched[] = $enrichedRow;
        }

        return $enriched;
    }

    /**
     * Obtiene, por agente, la cantidad de tickets activos frente a la capacidad máxima.
     *
     * @param int|null $agenteId Filtro opcional por un agente específico
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @return array<int, array{agenteId: int, agenteNombre: string, ticketsActivos: int, capacidadMaxima: int, porcentajeDeCarga: float}>
     */
    public function getCargaDeAgentes(?int $agenteId = null, ?string $phoneNumberId = null): array
    {
        return $this->reporteRepository->getCargaDeAgentes($agenteId, $phoneNumberId);
    }

    /**
     * Obtiene los tickets cuyo assigned_agent_id IS NULL.
     *
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @param \DateTimeImmutable|null $from Inicio opcional del rango de fechas
     * @param \DateTimeImmutable|null $to Fin opcional del rango de fechas
     * @param int $limit Máximo de resultados (por defecto 50)
     * @return array{cantidad: int, tickets: array<int, array{id: int, contactoNombre: string|null, creadoAt: string, prioridad: string}>}
     */
    public function getSinAsignar(?string $phoneNumberId = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, int $limit = 50): array
    {
        return $this->reporteRepository->getSinAsignar($phoneNumberId, $from, $to, $limit);
    }

    /**
     * Obtiene el FRT agregado por día dentro de un rango de fechas.
     *
     * @param \DateTimeImmutable $from Inicio del rango de fechas (UTC)
     * @param \DateTimeImmutable $to Fin del rango de fechas (UTC)
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @return array<int, array{fecha: string, frtPromedioSegundos: float, cantidadDeTickets: int}>
     */
    public function getTendenciaDeFrt(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null): array
    {
        $this->validarRangoDeFechas($from, $to);

        return $this->reporteRepository->getTendenciaDeFrt($from, $to, $phoneNumberId);
    }

    /**
     * Obtiene el volumen de tickets agrupado por día de la semana x hora para el mapa de calor.
     *
     * @param \DateTimeImmutable $from Inicio del rango de fechas (UTC)
     * @param \DateTimeImmutable $to Fin del rango de fechas (UTC)
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @return array<int, array{diaDeLaSemana: int, hora: int, cantidad: int}>
     */
    public function getMapaDeCalor(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null): array
    {
        $this->validarRangoDeFechas($from, $to);

        return $this->reporteRepository->getMapaDeCalor($from, $to, $phoneNumberId);
    }

    /**
     * Obtiene los contactos con >= umbral tickets en los últimos N días.
     *
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @param int $umbral Cantidad mínima de tickets (por defecto 3)
     * @param int $days Ventana retrospectiva en días (por defecto 90)
     * @return array<int, array{contactoId: int, contactoNombre: string|null, email: string|null, cantidadDeTickets: int, ultimoTicketAt: string}>
     */
    public function getContactosRecurrentes(?string $phoneNumberId = null, int $umbral = 3, int $days = 90): array
    {
        return $this->reporteRepository->getContactosRecurrentes($phoneNumberId, $umbral, $days);
    }

    /**
     * Obtiene los tickets que salieron del estado resuelto (eventos reapertura_automatica).
     *
     * @param \DateTimeImmutable $from Inicio del rango de fechas (UTC)
     * @param \DateTimeImmutable $to Fin del rango de fechas (UTC)
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @param int $limit Máximo de resultados (por defecto 100)
     * @return array<int, array{ticketId: int, contactoNombre: string|null, resueltoAt: string|null, reabiertoAt: string, estadoActual: string}>
     */
    public function getReabiertos(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null, int $limit = 100): array
    {
        $this->validarRangoDeFechas($from, $to);

        return $this->reporteRepository->getReabiertos($from, $to, $phoneNumberId, $limit);
    }

    /**
     * Obtiene las métricas de demora de respuesta agregadas y por agente.
     *
     * @param \DateTimeImmutable $from Inicio del rango de fechas (UTC); filtra por el inicio del episodio del ticket
     * @param \DateTimeImmutable $to Fin del rango de fechas (UTC)
     * @param string|null $phoneNumberId Filtro opcional por phone_number_id del espacio de trabajo
     * @return array{datosDisponiblesDesde: string|null, datosParciales: bool, global: array{demoraPromedioSegundos: float|null, cantidadDeMuestras: int, cantidadDeEntrantesSinResponder: int}, porAgente: array<int, array{agenteId: int, agenteNombre: string|null, demoraPromedioSegundos: float|null, cantidadDeMuestras: int}>}
     */
    public function getDemoraDeRespuesta(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId = null): array
    {
        $this->validarRangoDeFechas($from, $to);

        $corte = $this->reporteRepository->getCorteDeAtribucion();
        $metrics = $this->reporteRepository->getMetricasDeDemoraDeRespuesta($from, $to, $phoneNumberId);

        return [
            'datosDisponiblesDesde' => $corte?->format('Y-m-d H:i:s'),
            // Un corte null significa que toda la tabla messages está sin atribuir;
            // eso también son datos parciales, no "no hay ningún dato disponible".
            'datosParciales' => $corte === null || $corte > $from,
            'global' => $metrics['global'],
            'porAgente' => $metrics['porAgente'],
        ];
    }

    /**
     * Obtiene el detalle de participación por ticket.
     *
     * @param int $ticketId ID del ticket
     * @return array{ticketId: int, datosDisponiblesDesde: string|null, datosParciales: bool, participantes: array<int, array{agenteId: int, agenteNombre: string|null, cantidadDeMensajes: int, cantidadDeMuestras: int, demoraPromedioSegundos: float|null}>, cantidadDeMensajesAutomaticos: int, cantidadDeEntrantesSinResponder: int, pares: array<int, array{inicioDeRachaAt: string, respuestaAt: string|null, agenteId: int|null, demoraSegundos: float|null}>}|null null cuando el ticket no existe o no tiene conversación
     */
    public function getParticipacionEnTicket(int $ticketId): ?array
    {
        $result = $this->reporteRepository->getParticipacionEnTicket($ticketId);

        if ($result === null) {
            return null;
        }

        $result['datosDisponiblesDesde'] = $result['datosDisponiblesDesde'] instanceof \DateTimeImmutable
            ? $result['datosDisponiblesDesde']->format('Y-m-d H:i:s')
            : $result['datosDisponiblesDesde'];

        return $result;
    }

    /**
     * Valida el rango de fechas.
     *
     * @throws \InvalidArgumentException Si desde es posterior a hasta
     */
    private function validarRangoDeFechas(\DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        if ($from >= $to) {
            throw new \InvalidArgumentException('"desde" must be before "hasta"');
        }
    }
}
