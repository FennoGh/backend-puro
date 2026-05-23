<?php

/**
 * ============================================
 * DISPONIBILIDAD CONTROLLER
 * ============================================
 *
 * Gestión de franjas horarias del profesional.
 * TODOS los endpoints requieren auth + role:profesional.
 *
 * ¿Qué puede hacer el profesional aquí?
 *   - Ver sus franjas de disponibilidad para un servicio
 *   - Crear nuevas franjas ("ahora doy masajes también los martes de 10 a 12")
 *   - Editar franjas existentes (cambiar horas, activar/desactivar)
 *   - Eliminar franjas que ya no quiere
 *
 * Cada franja es RECURRENTE (se repite cada semana).
 * No se crean citas para fechas concretas — eso lo maneja el sistema
 * de reservas automáticamente.
 *
 * Ubicación en el flujo:
 *   Router → AuthMiddleware → RoleMiddleware:profesional → [CONTROLLER] → DisponibilidadRepo
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Repositories\DisponibilidadRepository;
use App\Repositories\ServicioRepository;

class DisponibilidadController
{
    private DisponibilidadRepository $dispRepo;
    private ServicioRepository $servicioRepo;

    public function __construct()
    {
        $this->dispRepo = new DisponibilidadRepository();
        $this->servicioRepo = new ServicioRepository();
    }

    /**
     * GET /api/v1/profesionales/me/servicios/:id_servicio/disponibilidad
     *
     * Lista todas las franjas de disponibilidad de un servicio del profesional.
     * Incluye activas e inactivas (para que pueda reactivar desde el frontend).
     *
     * Respuesta:
     *   {
     *     "data": [
     *       { "id": 1, "dia_semana": 1, "hora_inicio": "16:00", "hora_fin": "18:00", "activo": 1 },
     *       { "id": 2, "dia_semana": 3, "hora_inicio": "10:00", "hora_fin": "13:00", "activo": 1 }
     *     ]
     *   }
     *
     * @param string $idServicio  Viene del Router (capturado de la URL)
     */
    public function index(string $idServicio): void
    {
        $profId = (int) $_REQUEST['auth_user_id'];

        // ─── Verificar que el servicio le pertenece ───
        // Un profesional solo puede ver la disponibilidad de SUS servicios.
        // Si alguien manipula la URL para ver servicios de otro profesional,
        // este check lo bloquea.
        $servicio = $this->servicioRepo->findById((int) $idServicio);
        if (!$servicio || $servicio['profesional']['id'] !== $profId) {
            Response::error(403, 'FORBIDDEN', 'Este servicio no es tuyo');
        }

        $franjas = $this->dispRepo->findByServicio((int) $idServicio);
        Response::json(['data' => $franjas]);
    }

    /**
     * POST /api/v1/profesionales/me/servicios/:id_servicio/disponibilidad
     *
     * Crear una nueva franja de disponibilidad.
     *
     * Body:
     *   {
     *     "dia_semana": 1,        // 1=lunes, 2=martes, ..., 7=domingo
     *     "hora_inicio": "16:00",
     *     "hora_fin": "18:00"
     *   }
     *
     * Ejemplo: "Quiero dar masajes también los martes de 10 a 14"
     *   → dia_semana: 2, hora_inicio: "10:00", hora_fin: "14:00"
     *
     * El campo activo se pone en true automáticamente (default del schema).
     * id_profesional se toma del JWT (no del body, por seguridad).
     *
     * @param string $idServicio  Viene del Router
     */
    public function store(string $idServicio): void
    {
        $profId = (int) $_REQUEST['auth_user_id'];
        $body = json_decode(file_get_contents('php://input'), true);

        // ─── Verificar propiedad del servicio ───
        $servicio = $this->servicioRepo->findById((int) $idServicio);
        if (!$servicio || $servicio['profesional']['id'] !== $profId) {
            Response::error(403, 'FORBIDDEN', 'Este servicio no es tuyo');
        }

        // ─── Validar campos obligatorios ───
        if (empty($body['dia_semana']) || empty($body['hora_inicio']) || empty($body['hora_fin'])) {
            Response::error(422, 'VALIDATION_ERROR', 'Campos obligatorios: dia_semana, hora_inicio, hora_fin');
        }

        // ─── Validar rango de dia_semana ───
        if ($body['dia_semana'] < 1 || $body['dia_semana'] > 7) {
            Response::error(422, 'VALIDATION_ERROR', 'dia_semana debe ser entre 1 (lunes) y 7 (domingo)');
        }

        // ─── Validar que hora_fin es posterior a hora_inicio ───
        // El schema tiene un CHECK constraint para esto, pero es mejor
        // validar aquí para dar un mensaje de error claro al usuario
        // en vez del error críptico de MySQL.
        if ($body['hora_fin'] <= $body['hora_inicio']) {
            Response::error(422, 'VALIDATION_ERROR', 'hora_fin debe ser posterior a hora_inicio');
        }

        // ─── Crear la franja ───
        $franja = $this->dispRepo->create([
            'id_profesional' => $profId,
            'id_servicio'    => (int) $idServicio,
            'dia_semana'     => $body['dia_semana'],
            'hora_inicio'    => $body['hora_inicio'],
            'hora_fin'       => $body['hora_fin'],
        ]);

        Response::json($franja, 201);
    }

    /**
     * PUT /api/v1/profesionales/me/disponibilidad/:id
     *
     * Editar una franja existente.
     *
     * Body (todos los campos son requeridos):
     *   {
     *     "dia_semana": 1,
     *     "hora_inicio": "15:00",   // cambió de 16:00 a 15:00
     *     "hora_fin": "18:00",
     *     "activo": true
     *   }
     *
     * El campo "activo" permite pausar una franja temporalmente:
     *   activo: false → "esta semana no doy masajes los lunes" (vacaciones)
     *   activo: true  → "ya volví, reactivo mi horario"
     *
     * @param string $id  ID de la franja (no del servicio)
     */
    public function update(string $id): void
    {
        $profId = (int) $_REQUEST['auth_user_id'];
        $body = json_decode(file_get_contents('php://input'), true);

        // ─── Verificar que la franja le pertenece ───
        $franja = $this->dispRepo->findById((int) $id);
        if (!$franja || (int) $franja['id_profesional'] !== $profId) {
            Response::error(403, 'FORBIDDEN', 'Esta franja no es tuya');
        }

        $result = $this->dispRepo->update((int) $id, $body);
        Response::json($result);
    }

    /**
     * DELETE /api/v1/profesionales/me/disponibilidad/:id
     *
     * Eliminar una franja permanentemente.
     *
     * ¿Cuándo borrar vs desactivar?
     *   - Borrar (DELETE):     "Ya no quiero ofrecer masajes los lunes nunca más"
     *   - Desactivar (PUT):    "Esta semana no puedo, pero la que viene sí"
     *
     * Las reservas YA HECHAS no se cancelan al borrar la disponibilidad.
     * Solo afecta a las reservas FUTURAS (ya no aparecerán slots para ese día).
     *
     * Respuesta: 204 No Content (éxito sin cuerpo)
     *
     * @param string $id  ID de la franja
     */
    public function destroy(string $id): void
    {
        $profId = (int) $_REQUEST['auth_user_id'];

        // Verificar propiedad
        $franja = $this->dispRepo->findById((int) $id);
        if (!$franja || (int) $franja['id_profesional'] !== $profId) {
            Response::error(403, 'FORBIDDEN', 'Esta franja no es tuya');
        }

        $this->dispRepo->delete((int) $id);

        // 204 = "todo bien, no hay nada que devolver"
        // Es la convención HTTP para DELETE exitoso.
        Response::json(null, 204);
    }
}