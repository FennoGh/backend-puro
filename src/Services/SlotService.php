<?php

/**
 * ============================================
 * SLOT SERVICE — El cerebro de la disponibilidad
 * ============================================
 *
 * Este es el servicio más importante de tu plataforma.
 * Resuelve la pregunta central:
 *
 *   "¿Qué horarios tiene libres este servicio en esta fecha?"
 *
 * ¿Cómo lo calcula? Combinando tres fuentes de datos:
 *
 *   ┌─────────────────────────────────────────────────────────┐
 *   │  DISPONIBILIDAD (recurrente)                            │
 *   │  "Masaje: lunes de 16:00 a 18:00"                      │
 *   │                                                         │
 *   │          ÷ duración de sesión (60 min)                  │
 *   │                     ↓                                   │
 *   │  SLOTS POSIBLES:                                        │
 *   │  [16:00-17:00] [17:00-18:00]                            │
 *   │                                                         │
 *   │          - reservas existentes                          │
 *   │                     ↓                                   │
 *   │  SLOTS FINALES:                                         │
 *   │  [16:00-17:00 ❌ ocupado] [17:00-18:00 ✅ libre]        │
 *   └─────────────────────────────────────────────────────────┘
 *
 * Ejemplo con Fisioterapia (30 min de sesión):
 *   Franja: miércoles 10:00-13:00
 *   Slots posibles: 10:00, 10:30, 11:00, 11:30, 12:00, 12:30 (6 slots)
 *   Si 11:00 está reservado:
 *   → 10:00 ✅, 10:30 ✅, 11:00 ❌, 11:30 ✅, 12:00 ✅, 12:30 ✅
 *
 * Ubicación en el flujo:
 *   ServicioController::slots() → [SLOT SERVICE] → DisponibilidadRepo + PagoRepo
 *   ReservaService::crearReserva() → [SLOT SERVICE] (para verificar que el slot es válido)
 */

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ServicioRepository;
use App\Repositories\DisponibilidadRepository;
use App\Repositories\PagoRepository;

class SlotService
{
    private ServicioRepository $servicioRepo;
    private DisponibilidadRepository $dispRepo;
    private PagoRepository $pagoRepo;

    public function __construct()
    {
        $this->servicioRepo = new ServicioRepository();
        $this->dispRepo     = new DisponibilidadRepository();
        $this->pagoRepo     = new PagoRepository();
    }

    /**
     * Calcular los slots disponibles para un servicio en una fecha concreta.
     *
     * @param int    $servicioId  ID del servicio (ej: 1 = Masaje)
     * @param string $fecha       Fecha concreta YYYY-MM-DD (ej: '2026-03-23')
     *
     * @return array Estructura completa:
     *   {
     *     "servicio_id": 1,
     *     "fecha": "2026-03-23",
     *     "dia_semana": 1,
     *     "duracion_sesion": 60,
     *     "precio": 50.00,
     *     "slots": [
     *       { "hora_inicio": "16:00", "hora_fin": "17:00", "disponible": true },
     *       { "hora_inicio": "17:00", "hora_fin": "18:00", "disponible": false }
     *     ]
     *   }
     *
     * @throws \RuntimeException Si el servicio no existe
     */
    public function getSlotsDisponibles(int $servicioId, string $fecha): array
    {
        // ───────────────────────────────
        // PASO 1: Obtener el servicio
        // ───────────────────────────────
        // Necesitamos tres datos del servicio:
        //   - duracion_sesion: para saber de cuántos minutos es cada slot
        //   - precio: para informar al cliente cuánto cuesta
        //   - profesional.id: para verificar si los slots están ocupados
        $servicio = $this->servicioRepo->findById($servicioId);

        if (!$servicio) {
            throw new \RuntimeException('Servicio no encontrado');
        }

        // ───────────────────────────────
        // PASO 2: ¿Qué día de la semana es?
        // ───────────────────────────────
        // La disponibilidad está definida por DÍA DE LA SEMANA (lunes, martes...),
        // no por fecha concreta. Necesitamos convertir la fecha a día de semana.
        //
        // strtotime('2026-03-23') convierte la fecha a un timestamp Unix.
        // date('N', timestamp) devuelve el día de la semana como número:
        //   1 = lunes, 2 = martes, 3 = miércoles, 4 = jueves,
        //   5 = viernes, 6 = sábado, 7 = domingo
        //
        // Esto coincide con el campo dia_semana de tu schema.
        // (PHP tiene también date('w') que devuelve 0=domingo, pero 'N' es ISO-8601
        // y coincide mejor con tu esquema donde 1=lunes)
        $diaSemana = (int) date('N', strtotime($fecha));

        // ───────────────────────────────
        // PASO 3: Buscar franjas de disponibilidad
        // ───────────────────────────────
        // "¿Qué franjas tiene este servicio para los lunes?"
        // Puede devolver 0, 1, o varias franjas.
        //
        // Ejemplo para Masaje (servicio 1) un lunes (día 1):
        //   → [{ hora_inicio: '16:00', hora_fin: '18:00' }]
        //
        // Si devuelve vacío, el profesional no trabaja ese día
        // para este servicio → 0 slots disponibles.
        $franjas = $this->dispRepo->findByServicioYDia($servicioId, $diaSemana);

        // ───────────────────────────────
        // PASO 4: Dividir franjas en slots
        // ───────────────────────────────
        $duracionMinutos = (int) $servicio['duracion_sesion'];
        $duracionSegundos = $duracionMinutos * 60;
        $profesionalId = (int) $servicio['profesional']['id'];

        $slots = [];

        foreach ($franjas as $franja) {
            // Convertir horas (string "16:00") a timestamps (enteros)
            // para poder hacer aritmética.
            //
            // strtotime('16:00') devuelve el timestamp de "hoy a las 16:00".
            // No importa que sea "hoy" porque solo nos interesa la diferencia
            // entre inicio y fin, no la fecha absoluta.
            $inicio = strtotime($franja['hora_inicio']);
            $fin    = strtotime($franja['hora_fin']);

            // Recorrer la franja en pasos de duracion_sesion.
            //
            // La condición ($t + $duracionSegundos) <= $fin es IMPORTANTE:
            // asegura que el último slot CABE completo antes del fin de la franja.
            //
            // Ejemplo: franja 16:00-18:00, sesión 60 min
            //
            //   $t = 16:00 (57600 seg)
            //     16:00 + 3600 = 17:00 ≤ 18:00? SÍ → slot 16:00-17:00
            //
            //   $t = 17:00 (61200 seg)
            //     17:00 + 3600 = 18:00 ≤ 18:00? SÍ → slot 17:00-18:00
            //
            //   $t = 18:00 (64800 seg)
            //     18:00 + 3600 = 19:00 ≤ 18:00? NO → fin del bucle
            //
            // Ejemplo: franja 10:00-13:00, sesión 30 min
            //   → 6 slots: 10:00, 10:30, 11:00, 11:30, 12:00, 12:30
            //   (12:30 + 30min = 13:00 ≤ 13:00 → cabe)
            //   (13:00 + 30min = 13:30 > 13:00 → no cabe → fin)
            for ($t = $inicio; ($t + $duracionSegundos) <= $fin; $t += $duracionSegundos) {

                // Convertir los timestamps de vuelta a strings "HH:MM"
                $slotInicio = date('H:i', $t);
                $slotFin    = date('H:i', $t + $duracionSegundos);

                // ───────────────────────────────
                // PASO 5: ¿Este slot ya está reservado?
                // ───────────────────────────────
                // Para CADA slot, consultamos la tabla pagos.
                //
                // ¿Es eficiente? Para un MVP sí. Si tienes 6 slots,
                // son 6 queries sencillas con índice (muy rápidas).
                //
                // Para escalar: podrías hacer UNA query que traiga TODOS
                // los pagos de ese profesional+fecha y compararlos en PHP.
                // Pero para empezar, esto es más claro y funciona bien.
                $ocupado = $this->pagoRepo->slotOcupado(
                    $profesionalId,
                    $fecha,
                    $slotInicio
                );

                $slots[] = [
                    'hora_inicio' => $slotInicio,
                    'hora_fin'    => $slotFin,
                    'disponible'  => !$ocupado,
                ];
            }
        }

        // ───────────────────────────────
        // PASO 6: Devolver resultado
        // ───────────────────────────────
        // Incluimos metadatos útiles para el frontend:
        //   - dia_semana: por si quiere mostrar "Lunes 23 de marzo"
        //   - duracion_sesion: por si quiere mostrar "Sesiones de 60 min"
        //   - precio: por si quiere mostrar el precio junto a los slots
        return [
            'servicio_id'     => $servicioId,
            'fecha'           => $fecha,
            'dia_semana'      => $diaSemana,
            'duracion_sesion' => $duracionMinutos,
            'precio'          => $servicio['precio'],
            'slots'           => $slots,
        ];
    }
}