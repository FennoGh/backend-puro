<?php

/**
 * ============================================
 * RESERVA CONTROLLER
 * ============================================
 *
 * Maneja todos los endpoints relacionados con reservas/pagos:
 *
 *   POST /reservas                    → Crear reserva (usuario)
 *   GET  /usuarios/me/reservas        → Mis reservas (usuario)
 *   GET  /profesionales/me/reservas   → Mi agenda (profesional)
 *   GET  /reservas/:id                → Ver detalle (ambos)
 *   POST /reservas/:id/verificar      → Verificar asistencia (ambos)
 *   POST /reservas/:id/devolucion     → Pedir devolución (usuario)
 *
 * Patrón de manejo de errores:
 *   El ReservaService lanza excepciones con códigos como 'SLOT_OCCUPIED'.
 *   El controller los traduce a respuestas HTTP con el código apropiado.
 *   Esto separa la lógica de negocio (Service) del protocolo HTTP (Controller).
 *
 * Ubicación en el flujo:
 *   Router → Middleware → [RESERVA CONTROLLER] → ReservaService → PagoRepository
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Services\ReservaService;
use App\Repositories\PagoRepository;

class ReservaController
{
    private ReservaService $reservaService;
    private PagoRepository $pagoRepo;

    public function __construct()
    {
        $this->reservaService = new ReservaService();
        $this->pagoRepo = new PagoRepository();
    }

    /**
     * POST /api/v1/reservas
     *
     * Crear una reserva nueva. Requiere auth + role:usuario.
     *
     * Body que envía el cliente:
     *   {
     *     "id_servicio": 1,
     *     "fecha": "2026-03-23",
     *     "hora_inicio": "16:00"
     *   }
     *
     * Solo 3 campos. El backend calcula el resto (hora_fin, monto, id_profesional).
     * Esto es intencional: el frontend no puede manipular el precio ni el profesional.
     *
     * Respuesta exitosa (201 Created):
     *   {
     *     "id": 1,
     *     "fecha": "2026-03-23",
     *     "hora_inicio": "16:00",
     *     "hora_fin": "17:00",
     *     "monto": 50.00,
     *     "estado": "retenido",
     *     ...
     *   }
     */
    public function store(): void
    {
        // El ID del usuario viene del JWT, puesto por AuthMiddleware
        $userId = (int) $_REQUEST['auth_user_id'];

        // Leer el body JSON de la petición
        $body = json_decode(file_get_contents('php://input'), true);

        // ─── Validar campos obligatorios ───
        if (empty($body['id_servicio']) || empty($body['fecha']) || empty($body['hora_inicio'])) {
            Response::error(422, 'VALIDATION_ERROR', 'Campos obligatorios: id_servicio, fecha, hora_inicio');
        }

        // ─── Llamar al servicio ───
        // El Service hace toda la validación de negocio:
        //   - fecha no pasada
        //   - servicio existe
        //   - slot disponible
        //   - calcular campos derivados
        try {
            $reserva = $this->reservaService->crearReserva(
                $userId,
                (int) $body['id_servicio'],
                $body['fecha'],
                $body['hora_inicio']
            );

            Response::json($reserva, 201);

        } catch (\RuntimeException $e) {
            // ─── Traducir excepciones de negocio a respuestas HTTP ───
            // El Service lanza códigos genéricos ('SLOT_OCCUPIED').
            // El Controller los traduce al código HTTP y mensaje apropiado.
            //
            // ¿Por qué este mapeo aquí y no en el Service?
            // Porque los códigos HTTP son del mundo HTTP (Controller),
            // no del mundo de negocio (Service).
            $errorMap = [
                'DATE_IN_PAST'       => [422, 'No se puede reservar en una fecha pasada'],
                'SERVICE_NOT_FOUND'  => [404, 'Servicio no encontrado'],
                'SLOT_OCCUPIED'      => [409, 'Ese horario ya está reservado'],
                'SLOT_NOT_AVAILABLE' => [422, 'No hay disponibilidad en ese horario'],
            ];

            $code = $e->getMessage();
            if (isset($errorMap[$code])) {
                Response::error($errorMap[$code][0], $code, $errorMap[$code][1]);
            }

            // Si es un error que no esperamos, dejamos que suba al catch global
            throw $e;
        }
    }

    /**
     * GET /api/v1/usuarios/me/reservas
     *
     * Lista las reservas del usuario autenticado.
     * Requiere auth + role:usuario.
     *
     * Query params opcionales:
     *   ?estado=retenido       → filtrar por estado
     *   ?desde=2026-03-01      → fecha mínima
     *   ?hasta=2026-03-31      → fecha máxima
     *   ?page=1&limit=20       → paginación
     *
     * Respuesta:
     *   {
     *     "data": [{ "id": 1, "fecha": "2026-03-23", ... }],
     *     "meta": { "page": 1, "limit": 20, "total": 5 }
     *   }
     */
    public function misReservas(): void
    {
        $userId = (int) $_REQUEST['auth_user_id'];

        // Lazy auto-release of old escrow reservations (72h)
        $this->pagoRepo->autoLiberarSaldos();

        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $limit  = min(100, max(1, (int) ($_GET['limit'] ?? 20)));

        // Recoger filtros opcionales
        $filters = [];
        if (!empty($_GET['estado'])) $filters['estado'] = $_GET['estado'];
        if (!empty($_GET['desde']))  $filters['desde']  = $_GET['desde'];
        if (!empty($_GET['hasta']))  $filters['hasta']  = $_GET['hasta'];

        $result = $this->pagoRepo->findByUsuario($userId, $filters, $page, $limit);
        Response::paginated($result['data'], $result['total'], $page, $limit);
    }

    /**
     * GET /api/v1/profesionales/me/reservas
     *
     * La agenda del profesional: muestra las reservas que tienen con él.
     * Requiere auth + role:profesional.
     *
     * Query params opcionales:
     *   ?fecha=2026-03-23      → ver un día concreto
     *   ?estado=retenido       → filtrar por estado
     *   ?desde=2026-03-01      → rango inicio
     *   ?hasta=2026-03-31      → rango fin
     *
     * La respuesta incluye datos del USUARIO (quién reservó)
     * en vez del profesional (porque el profesional ya sabe quién es él).
     */
    public function agendaProfesional(): void
    {
        $profId = (int) $_REQUEST['auth_user_id'];

        // Lazy auto-release of old escrow reservations (72h)
        $this->pagoRepo->autoLiberarSaldos();

        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $limit  = min(100, max(1, (int) ($_GET['limit'] ?? 20)));

        $filters = [];
        if (!empty($_GET['estado'])) $filters['estado'] = $_GET['estado'];
        if (!empty($_GET['fecha']))  $filters['fecha']  = $_GET['fecha'];
        if (!empty($_GET['desde']))  $filters['desde']  = $_GET['desde'];
        if (!empty($_GET['hasta']))  $filters['hasta']  = $_GET['hasta'];

        $result = $this->pagoRepo->findByProfesional($profId, $filters, $page, $limit);
        Response::paginated($result['data'], $result['total'], $page, $limit);
    }

    /**
     * GET /api/v1/reservas/:id
     *
     * Ver detalle de una reserva específica.
     * Requiere autenticación (sin rol específico).
     *
     * Solo el usuario que reservó O el profesional de la reserva
     * pueden ver el detalle. Cualquier otro recibe 403.
     *
     * @param string $id  Viene del Router (capturado de la URL como string)
     */
    public function show(string $id): void
    {
        $authId = (int) $_REQUEST['auth_user_id'];
        $role   = $_REQUEST['auth_user_role'];

        $pago = $this->pagoRepo->findById((int) $id);

        if (!$pago) {
            Response::error(404, 'NOT_FOUND', 'Reserva no encontrada');
        }

        // ─── Verificar acceso ───
        // $esUsuario: el autenticado es el usuario que hizo la reserva
        // $esProf: el autenticado es el profesional de la reserva
        $esUsuario = ($role === 'usuario' && (int) $pago['id_usuario'] === $authId);
        $esProf    = ($role === 'profesional' && $pago['profesional']['id'] === $authId);

        if (!$esUsuario && !$esProf) {
            Response::error(403, 'FORBIDDEN', 'No tienes acceso a esta reserva');
        }

        Response::json($pago);
    }

    /**
     * POST /api/v1/reservas/:id/verificar
     *
     * Verificar que el servicio se prestó.
     * Puede llamarlo tanto el usuario como el profesional.
     *
     * No necesita body — el backend sabe qué campo actualizar
     * según el rol del JWT:
     *   - Si es usuario → verificado_usuario = true
     *   - Si es profesional → verificado_profesional = true
     *
     * Cuando AMBOS verifican, el estado pasa automáticamente a 'liberado'
     * (el pago se libera al profesional).
     *
     * Este es el mecanismo de CONFIANZA de la plataforma:
     * el dinero solo se libera cuando las dos partes confirman.
     */
    public function verificar(string $id): void
    {
        $authId = (int) $_REQUEST['auth_user_id'];
        $role   = $_REQUEST['auth_user_role'];

        try {
            $result = $this->reservaService->verificar((int) $id, $authId, $role);
            Response::json($result);

        } catch (\RuntimeException $e) {
            $errorMap = [
                'NOT_FOUND'     => [404, 'Reserva no encontrada'],
                'FORBIDDEN'     => [403, 'No tienes acceso a esta reserva'],
                'INVALID_STATE' => [422, 'Solo se pueden verificar reservas con estado retenido'],
            ];

            $code = $e->getMessage();
            if (isset($errorMap[$code])) {
                Response::error($errorMap[$code][0], $code, $errorMap[$code][1]);
            }

            throw $e;
        }
    }

    /**
     * POST /api/v1/reservas/:id/devolucion
     *
     * El usuario solicita devolución de una reserva.
     * Requiere auth + role:usuario.
     *
     * Solo funciona si:
     *   - La reserva le pertenece al usuario
     *   - El estado es 'retenido' (no se puede devolver algo ya liberado)
     *
     * Después de la devolución:
     *   - estado → 'devuelto'
     *   - El slot queda libre para que otro lo reserve
     */
    public function devolucion(string $id): void
    {
        $userId = (int) $_REQUEST['auth_user_id'];

        try {
            $result = $this->reservaService->devolver((int) $id, $userId);
            Response::json($result);

        } catch (\RuntimeException $e) {
            $errorMap = [
                'NOT_FOUND'         => [404, 'Reserva no encontrada'],
                'FORBIDDEN'         => [403, 'No puedes solicitar devolución de esta reserva'],
                'ALREADY_RELEASED'  => [422, 'El pago ya fue liberado al profesional'],
                'ALREADY_REFUNDED'  => [422, 'Esta reserva ya fue devuelta'],
            ];

            $code = $e->getMessage();
            if (isset($errorMap[$code])) {
                Response::error($errorMap[$code][0], $code, $errorMap[$code][1]);
            }

            throw $e;
        }
    }
}