<?php

/**
 * ============================================
 * PAGO REPOSITORY — Reservas y pagos
 * ============================================
 *
 * ¿Por qué la tabla se llama "pagos" pero hablamos de "reservas"?
 * Porque en tu plataforma, PAGAR = RESERVAR. Son el mismo acto.
 * La tabla almacena tanto la información de la cita como la del pago.
 *
 * Estados de un pago:
 *
 *   'retenido'  → Estado inicial. El dinero está retenido.
 *                  El servicio aún no se ha prestado.
 *
 *   'liberado'  → Ambas partes verificaron que el servicio se prestó.
 *                  El dinero se libera al profesional.
 *                  Es AUTOMÁTICO cuando verificado_usuario = true
 *                  Y verificado_profesional = true.
 *
 *   'devuelto'  → El usuario pidió devolución (servicio no prestado).
 *                  El dinero vuelve al usuario.
 *                  El slot queda libre para otra reserva.
 *
 * Flujo normal:
 *   retenido → (ambos verifican) → liberado
 *
 * Flujo con problema:
 *   retenido → (usuario pide devolución) → devuelto
 *
 * Ubicación en el flujo:
 *   ReservaService → [PAGO REPOSITORY] → MySQL
 *   SlotService → [PAGO REPOSITORY] → MySQL (para verificar ocupación)
 *   ReservaController → [PAGO REPOSITORY] → MySQL (para listar)
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class PagoRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * ¿Está ocupado este slot?
     *
     * Este método es CRÍTICO para el cálculo de disponibilidad.
     * El SlotService lo llama para cada slot posible al calcular
     * qué horarios están libres.
     *
     * Un slot está ocupado si existe un pago para ese profesional
     * en esa fecha y hora que NO esté devuelto.
     * Los pagos devueltos "liberan" el slot — como si la reserva
     * nunca hubiera existido.
     *
     * Ejemplo:
     *   slotOcupado(1, '2026-03-23', '16:00')
     *   → "¿El profesional 1 tiene reserva el 23 de marzo a las 16:00?"
     *   → true (sí, está ocupado) / false (no, está libre)
     *
     * @param int    $profesionalId  ID del profesional
     * @param string $fecha          Fecha concreta YYYY-MM-DD
     * @param string $horaInicio     Hora del slot HH:MM
     *
     * @return bool true si el slot ya tiene reserva activa
     */
    public function slotOcupado(int $profesionalId, string $fecha, string $horaInicio): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total FROM pagos
             WHERE id_profesional = :prof_id
               AND fecha = :fecha
               AND hora_inicio = :hora
               AND estado != 'devuelto'"
        );

        $stmt->execute([
            ':prof_id' => $profesionalId,
            ':fecha'   => $fecha,
            ':hora'    => $horaInicio,
        ]);

        // COUNT(*) siempre devuelve un número.
        // Si es > 0, hay al menos una reserva activa → ocupado.
        return (int) $stmt->fetch()['total'] > 0;
    }

    /**
     * Crear una reserva (pago).
     *
     * Los campos que recibe ya fueron calculados por el ReservaService:
     *   - id_profesional → extraído del servicio
     *   - hora_fin → calculada como hora_inicio + duracion_sesion
     *   - monto → precio del servicio
     *
     * MySQL aplica los defaults del schema:
     *   - estado → 'retenido'
     *   - verificado_profesional → false
     *   - verificado_usuario → false
     *   - fecha_pago → CURRENT_TIMESTAMP
     *
     * El UNIQUE constraint (id_profesional, fecha, hora_inicio) impide
     * que se creen dos reservas en el mismo slot. Si alguien intenta
     * reservar un slot que otro usuario acaba de reservar (condición de carrera),
     * MySQL lanza un error de duplicado y la reserva no se crea.
     *
     * @return array El pago creado con todos sus campos
     */
    public function create(array $data): array
    {
        $stmt = $this->db->prepare(
            "INSERT INTO pagos (id_usuario, id_profesional, id_servicio,
                                fecha, hora_inicio, hora_fin, monto)
             VALUES (:id_usuario, :id_profesional, :id_servicio,
                     :fecha, :hora_inicio, :hora_fin, :monto)"
        );

        $stmt->execute([
            ':id_usuario'     => $data['id_usuario'],
            ':id_profesional' => $data['id_profesional'],
            ':id_servicio'    => $data['id_servicio'],
            ':fecha'          => $data['fecha'],
            ':hora_inicio'    => $data['hora_inicio'],
            ':hora_fin'       => $data['hora_fin'],
            ':monto'          => $data['monto'],
        ]);

        $id = (int) $this->db->lastInsertId();
        return $this->findById($id);
    }

    /**
     * Buscar un pago por ID, con datos del servicio y profesional.
     *
     * Usa JOIN para traer todo en una sola query.
     * El resultado se formatea con formatPago() para tener
     * objetos anidados (servicio: {...}, profesional: {...}).
     *
     * @return array|null El pago formateado, o null si no existe
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT p.*,
                    s.nombre AS servicio_nombre,
                    pr.nombre AS prof_nombre, pr.apellido AS prof_apellido
             FROM pagos p
             JOIN servicios s ON p.id_servicio = s.id
             JOIN profesionales pr ON p.id_profesional = pr.id
             WHERE p.id = :id"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row) return null;

        return $this->formatPago($row);
    }

    /**
     * Buscar reservas de un usuario (perspectiva cliente).
     *
     * Incluye datos del servicio y del profesional en cada reserva.
     * Soporta filtros por estado y rango de fechas.
     *
     * Ejemplo:
     *   findByUsuario(5, ['estado' => 'retenido', 'desde' => '2026-03-01'], 1, 20)
     *   → "Dame las reservas retenidas del usuario 5 desde marzo, página 1"
     *
     * @return array ['data' => [...], 'total' => int]
     */
    public function findByUsuario(int $userId, array $filters = [], int $page = 1, int $limit = 20): array
    {
        // ─── Construir la query con filtros dinámicos ───
        // Mismo patrón que en ServicioRepository:
        // query base + "AND ..." por cada filtro presente.
        $sql = "SELECT p.*,
                       s.nombre AS servicio_nombre,
                       pr.nombre AS prof_nombre, pr.apellido AS prof_apellido
                FROM pagos p
                JOIN servicios s ON p.id_servicio = s.id
                JOIN profesionales pr ON p.id_profesional = pr.id
                WHERE p.id_usuario = :user_id";

        $params = [':user_id' => $userId];

        if (!empty($filters['estado'])) {
            $sql .= " AND p.estado = :estado";
            $params[':estado'] = $filters['estado'];
        }

        if (!empty($filters['desde'])) {
            $sql .= " AND p.fecha >= :desde";
            $params[':desde'] = $filters['desde'];
        }

        if (!empty($filters['hasta'])) {
            $sql .= " AND p.fecha <= :hasta";
            $params[':hasta'] = $filters['hasta'];
        }

        // ─── Contar total (para paginación) ───
        $countStmt = $this->db->prepare("SELECT COUNT(*) AS total FROM ($sql) AS counted");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['total'];

        // ─── Paginar ───
        $offset = ($page - 1) * $limit;
        $sql .= " ORDER BY p.fecha DESC, p.hora_inicio ASC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        $rows = $stmt->fetchAll();

        // Formatear cada fila
        $data = array_map([$this, 'formatPago'], $rows);

        return ['data' => $data, 'total' => $total];
    }

    /**
     * Buscar reservas de un profesional (su agenda).
     *
     * Similar a findByUsuario pero desde la perspectiva del profesional:
     * incluye datos del USUARIO (quién reservó) en vez del profesional.
     *
     * Soporta filtrar por fecha exacta (ver un día concreto de la agenda)
     * además de rango y estado.
     *
     * @return array ['data' => [...], 'total' => int]
     */
    public function findByProfesional(int $profId, array $filters = [], int $page = 1, int $limit = 20): array
    {
        $sql = "SELECT p.*,
                       s.nombre AS servicio_nombre,
                       u.nombre AS usuario_nombre, u.apellido AS usuario_apellido
                FROM pagos p
                JOIN servicios s ON p.id_servicio = s.id
                JOIN usuarios u ON p.id_usuario = u.id
                WHERE p.id_profesional = :prof_id";

        $params = [':prof_id' => $profId];

        if (!empty($filters['estado'])) {
            $sql .= " AND p.estado = :estado";
            $params[':estado'] = $filters['estado'];
        }

        // Filtro por fecha exacta (para ver "mi agenda del lunes")
        if (!empty($filters['fecha'])) {
            $sql .= " AND p.fecha = :fecha";
            $params[':fecha'] = $filters['fecha'];
        }

        if (!empty($filters['desde'])) {
            $sql .= " AND p.fecha >= :desde";
            $params[':desde'] = $filters['desde'];
        }

        if (!empty($filters['hasta'])) {
            $sql .= " AND p.fecha <= :hasta";
            $params[':hasta'] = $filters['hasta'];
        }

        // Count
        $countStmt = $this->db->prepare("SELECT COUNT(*) AS total FROM ($sql) AS counted");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['total'];

        // Paginar
        $offset = ($page - 1) * $limit;
        $sql .= " ORDER BY p.fecha DESC, p.hora_inicio ASC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        $rows = $stmt->fetchAll();

        // Formatear — diferente a findByUsuario porque aquí
        // incluimos datos del usuario en vez del profesional
        $data = array_map(function (array $row): array {
            return [
                'id'                     => (int) $row['id'],
                'fecha'                  => $row['fecha'],
                'hora_inicio'            => $row['hora_inicio'],
                'hora_fin'               => $row['hora_fin'],
                'monto'                  => (float) $row['monto'],
                'estado'                 => $row['estado'],
                'verificado_profesional' => (bool) $row['verificado_profesional'],
                'verificado_usuario'     => (bool) $row['verificado_usuario'],
                'servicio'               => [
                    'id'     => (int) $row['id_servicio'],
                    'nombre' => $row['servicio_nombre'],
                ],
                'usuario'                => [
                    'id'       => (int) $row['id_usuario'],
                    'nombre'   => $row['usuario_nombre'],
                    'apellido' => $row['usuario_apellido'],
                ],
            ];
        }, $rows);

        return ['data' => $data, 'total' => $total];
    }

    /**
     * Verificar asistencia.
     *
     * Este método implementa la DOBLE VERIFICACIÓN:
     *   1. Actualiza el campo correspondiente (usuario o profesional)
     *   2. Si AMBOS han verificado, cambia el estado a 'liberado'
     *
     * Usa una TRANSACCIÓN para que ambas operaciones sean atómicas:
     *   - Si algo falla entre el paso 1 y el 2, todo se deshace
     *   - Imposible que quede en un estado inconsistente
     *
     * ¿Qué es una transacción?
     *   beginTransaction() → "voy a hacer varias operaciones"
     *   commit()           → "todo salió bien, confirma los cambios"
     *   rollBack()         → "algo falló, deshaz TODO"
     *
     * Sin transacción, si PHP se cae entre el paso 1 y 2, quedaría
     * verificado=true pero estado='retenido' cuando debería ser 'liberado'.
     *
     * @param int    $pagoId  ID del pago
     * @param string $campo   'verificado_usuario' o 'verificado_profesional'
     *                        El ReservaService decide cuál según el rol.
     *
     * @return array El pago actualizado
     */
    public function verificar(int $pagoId, string $campo): array
    {
        $this->db->beginTransaction();

        try {
            // Paso 1: Marcar verificación
            $stmt = $this->db->prepare(
                "UPDATE pagos SET {$campo} = 1 WHERE id = :id"
            );
            $stmt->execute([':id' => $pagoId]);

            // Paso 2: Leer el estado actualizado
            $stmt = $this->db->prepare("SELECT * FROM pagos WHERE id = :id");
            $stmt->execute([':id' => $pagoId]);
            $pago = $stmt->fetch();

            // Paso 3: Si ambos verificaron → liberar automáticamente
            if ($pago['verificado_usuario'] && $pago['verificado_profesional']) {
                $stmt = $this->db->prepare(
                    "UPDATE pagos SET estado = 'liberado' WHERE id = :id"
                );
                $stmt->execute([':id' => $pagoId]);
                $pago['estado'] = 'liberado';
            }

            // Todo bien → confirmar los cambios en la base de datos
            $this->db->commit();
            return $pago;

        } catch (\Exception $e) {
            // Algo falló → deshacer TODO
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Cambiar estado a 'devuelto'.
     *
     * El ReservaService ya verificó que:
     *   - La reserva existe
     *   - Le pertenece al usuario
     *   - No está ya liberada ni devuelta
     *
     * Cuando un pago se devuelve, el slot queda libre de nuevo
     * porque slotOcupado() filtra "AND estado != 'devuelto'".
     */
    public function devolver(int $pagoId): array
    {
        $stmt = $this->db->prepare(
            "UPDATE pagos SET estado = 'devuelto' WHERE id = :id"
        );
        $stmt->execute([':id' => $pagoId]);

        return $this->findById($pagoId);
    }

    /**
     * Formatear un pago desde la fila del JOIN (perspectiva usuario).
     *
     * ¿Por qué formatear?
     * La query con JOIN devuelve filas planas:
     *   ['id' => 1, 'servicio_nombre' => 'Masaje', 'prof_nombre' => 'Pedro', ...]
     *
     * Las convertimos en objetos anidados que son más limpios para el frontend:
     *   ['id' => 1, 'servicio' => ['nombre' => 'Masaje'], 'profesional' => ['nombre' => 'Pedro']]
     *
     * También forzamos los tipos (int, float, bool) porque MySQL devuelve
     * todo como strings y el frontend espera tipos correctos en el JSON.
     */
    private function formatPago(array $row): array
    {
        return [
            'id'                     => (int) $row['id'],
            'id_usuario'             => (int) $row['id_usuario'],
            'fecha'                  => $row['fecha'],
            'hora_inicio'            => $row['hora_inicio'],
            'hora_fin'               => $row['hora_fin'],
            'monto'                  => (float) $row['monto'],
            'estado'                 => $row['estado'],
            'verificado_profesional' => (bool) $row['verificado_profesional'],
            'verificado_usuario'     => (bool) $row['verificado_usuario'],
            'fecha_pago'             => $row['fecha_pago'],
            'servicio'               => [
                'id'     => (int) $row['id_servicio'],
                'nombre' => $row['servicio_nombre'] ?? null,
            ],
            'profesional'            => [
                'id'       => (int) $row['id_profesional'],
                'nombre'   => $row['prof_nombre'] ?? null,
                'apellido' => $row['prof_apellido'] ?? null,
            ],
        ];
    }
}