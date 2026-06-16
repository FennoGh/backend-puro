<?php

/**
 * ============================================
 * SERVICIO CONTROLLER — Endpoints de servicios
 * ============================================
 *
 * Maneja dos tipos de endpoints:
 *
 * PÚBLICOS (sin auth):
 *   GET /servicios              → Buscar servicios con filtros
 *   GET /servicios/:id          → Ver detalle de un servicio
 *   GET /servicios/:id/slots    → Ver slots disponibles en una fecha
 *
 * PROFESIONAL (auth + role:profesional):
 *   GET    /profesionales/me/servicios       → Listar mis servicios
 *   POST   /profesionales/me/servicios       → Crear servicio
 *   PUT    /profesionales/me/servicios/:id   → Editar servicio
 *   DELETE /profesionales/me/servicios/:id   → Eliminar servicio
 *
 * Ubicación en el flujo:
 *   Router → Middleware → [SERVICIO CONTROLLER] → ServicioRepository / SlotService
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Repositories\ServicioRepository;
use App\Services\SlotService;

class ServicioController
{
    private ServicioRepository $repo;
    private SlotService $slotService;

    public function __construct()
    {
        $this->repo = new ServicioRepository();
        $this->slotService = new SlotService();
    }

    // ═════════════════════════════════════════
    // ENDPOINTS PÚBLICOS
    // ═════════════════════════════════════════

    /**
     * GET /api/v1/servicios
     * GET /api/v1/servicios?ciudad=Barcelona
     * GET /api/v1/servicios?q=masaje&precio_max=60&page=2
     *
     * Buscar servicios con filtros opcionales.
     * Cualquiera puede acceder (no necesita auth).
     *
     * Query params opcionales:
     *   ciudad      → filtrar por ciudad del servicio
     *   pais        → filtrar por país
     *   precio_min  → precio mínimo
     *   precio_max  → precio máximo
     *   q           → búsqueda por texto (nombre o descripción)
     *   page        → página (default: 1)
     *   limit       → resultados por página (default: 20, máximo: 100)
     */
    public function index(): void
    {
        // ─── Leer query params con valores por defecto seguros ───
        // max(1, ...) → que nunca sea menor que 1 (página 0 no tiene sentido)
        // min(100, ...) → que nunca sea mayor que 100
        // Esto evita que alguien pida ?limit=999999 y sobrecargue la DB.
        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));

        // Solo añadimos al array los filtros que el cliente envió.
        // Los que no estén, el Repository los ignora.
        $filters = [];
        if (!empty($_GET['ciudad']))     $filters['ciudad']     = $_GET['ciudad'];
        if (!empty($_GET['pais']))       $filters['pais']       = $_GET['pais'];
        if (!empty($_GET['precio_min'])) $filters['precio_min'] = $_GET['precio_min'];
        if (!empty($_GET['precio_max'])) $filters['precio_max'] = $_GET['precio_max'];
        if (!empty($_GET['q']))          $filters['q']          = $_GET['q'];

        $result = $this->repo->findAll($filters, $page, $limit);
        Response::paginated($result['data'], $result['total'], $page, $limit);
    }

    /**
     * GET /api/v1/servicios/:id
     *
     * Ver el detalle completo de un servicio, incluyendo datos del profesional.
     *
     * @param string $id  Viene del Router (capturado de la URL).
     *                     Siempre es string porque la URL es texto.
     */
    public function show(string $id): void
    {
        $servicio = $this->repo->findById((int) $id);

        if (!$servicio) {
            Response::error(404, 'NOT_FOUND', "Servicio con id $id no encontrado");
        }

        Response::json($servicio);
    }

    /**
     * GET /api/v1/servicios/:id/slots?fecha=2026-03-23
     *
     * El endpoint MÁS IMPORTANTE de la plataforma para el usuario final.
     * Devuelve los horarios disponibles para reservar un servicio en una fecha.
     *
     * Usa el SlotService que combina:
     *   1. Disponibilidad recurrente del profesional
     *   2. Duración de sesión del servicio
     *   3. Reservas existentes en esa fecha
     *
     * Respuesta:
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
     * El frontend solo necesita pintar los slots y dejar reservar los que
     * tienen disponible=true. Toda la lógica compleja está en el backend.
     */
    public function slots(string $id): void
    {
        $servicioId = (int) $id;

        // ─── Validar que el param fecha viene y tiene formato correcto ───
        $fecha = $_GET['fecha'] ?? null;
        if (!$fecha) {
            Response::error(422, 'VALIDATION_ERROR', 'El parámetro fecha es obligatorio. Ejemplo: ?fecha=2026-03-17');
        }

        // DateTime::createFromFormat intenta crear una fecha con ese formato.
        // Si el formato no coincide, devuelve false.
        // La segunda comprobación ($dateObj->format(...) !== $fecha) detecta
        // fechas como "2026-02-30" que pasan el formato pero no son válidas.
        $dateObj = \DateTime::createFromFormat('Y-m-d', $fecha);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $fecha) {
            Response::error(422, 'VALIDATION_ERROR', "Formato de fecha inválido: $fecha. Usa YYYY-MM-DD");
        }

        // ─── Calcular slots ───
        try {
            $result = $this->slotService->getSlotsDisponibles($servicioId, $fecha);
            Response::json($result);
        } catch (\RuntimeException $e) {
            Response::error(404, 'NOT_FOUND', $e->getMessage());
        }
    }

    // ═════════════════════════════════════════
    // ENDPOINTS DEL PROFESIONAL
    // ═════════════════════════════════════════
    // Requieren autenticación + role:profesional.
    // El ID del profesional viene del JWT, NO de la URL.
    // Así un profesional solo puede gestionar SUS servicios.

    /**
     * GET /api/v1/profesionales/me/servicios
     *
     * Lista los servicios del profesional autenticado.
     * "me" en la URL indica que usamos el ID del JWT, no un parámetro.
     */
    public function misServicios(): void
    {
        $profId = (int) $_REQUEST['auth_user_id'];
        $servicios = $this->repo->findByProfesional($profId);
        Response::json(['data' => $servicios]);
    }

    /**
     * POST /api/v1/profesionales/me/servicios
     *
     * Crear un servicio nuevo.
     *
     * Body:
     *   {
     *     "nombre": "Masaje descontracturante",
     *     "tagline": "Sesión enfocada en cervicales y lumbares",
     *     "bio": "Trabajo profundo para aliviar la tensión acumulada.",
     *     "precio": 55.00,
     *     "duracion_sesion": 60,
     *     "ciudad": "Barcelona",
     *     "pais": "España"
     *   }
     *
     * id_profesional se toma del JWT (no del body).
     * Si el frontend enviara id_profesional en el body, lo ignoramos.
     */
    public function crearServicio(): void
    {
        $profId = (int) $_REQUEST['auth_user_id'];
        $body = json_decode(file_get_contents('php://input'), true);

        if (empty($body['nombre']) || empty($body['precio']) || empty($body['duracion_sesion'])) {
            Response::error(422, 'VALIDATION_ERROR', 'Campos obligatorios: nombre, precio, duracion_sesion');
        }

        // Forzar el id_profesional del JWT (seguridad)
        $body['id_profesional'] = $profId;

        $servicio = $this->repo->create($body);
        Response::json($servicio, 201);
    }

    /**
     * PUT /api/v1/profesionales/me/servicios/:id
     *
     * Editar un servicio existente.
     * Solo el profesional dueño puede editarlo.
     */
    public function editarServicio(string $id): void
    {
        $profId = (int) $_REQUEST['auth_user_id'];
        $body = json_decode(file_get_contents('php://input'), true);

        // ─── Verificar propiedad ───
        // Buscamos el servicio y comprobamos que el profesional del JWT
        // es el mismo que el dueño del servicio. Si alguien manipula la URL
        // para editar servicios de otro profesional, este check lo bloquea.
        $servicio = $this->repo->findById((int) $id);
        if (!$servicio || $servicio['profesional']['id'] !== $profId) {
            Response::error(403, 'FORBIDDEN', 'No puedes editar un servicio que no es tuyo');
        }

        $result = $this->repo->update((int) $id, $body);
        Response::json($result);
    }

    /**
     * DELETE /api/v1/profesionales/me/servicios/:id
     *
     * Eliminar un servicio.
     * CASCADE en el schema borra la disponibilidad asociada automáticamente.
     * Las reservas YA HECHAS no se borran (están en la tabla pagos
     * que referencia servicios sin CASCADE).
     */
    public function eliminarServicio(string $id): void
    {
        $profId = (int) $_REQUEST['auth_user_id'];

        $servicio = $this->repo->findById((int) $id);
        if (!$servicio || $servicio['profesional']['id'] !== $profId) {
            Response::error(403, 'FORBIDDEN', 'No puedes eliminar un servicio que no es tuyo');
        }

        $this->repo->delete((int) $id);
        Response::json(null, 204);
    }
}