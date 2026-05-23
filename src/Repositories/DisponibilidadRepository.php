<?php

/**
 * ============================================
 * DISPONIBILIDAD REPOSITORY — Franjas horarias recurrentes
 * ============================================
 *
 * ¿Qué guarda la tabla disponibilidad?
 * Franjas horarias que se REPITEN cada semana. No son fechas concretas.
 *
 * Ejemplo de una fila:
 *   id_profesional: 1 (Pedro)
 *   id_servicio: 1 (Masaje)
 *   dia_semana: 1 (lunes)
 *   hora_inicio: 16:00
 *   hora_fin: 18:00
 *
 * Esto significa: "Pedro da masajes TODOS los lunes de 16 a 18".
 * No es "el lunes 23 de marzo" — es "todos los lunes".
 *
 * El SlotService combina esta información con las reservas existentes
 * para calcular qué slots concretos están libres en una fecha específica.
 *
 * Ubicación en el flujo:
 *   SlotService → [DISPONIBILIDAD REPOSITORY] → MySQL
 *   DisponibilidadController → [DISPONIBILIDAD REPOSITORY] → MySQL
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class DisponibilidadRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Buscar franjas de disponibilidad para un servicio en un día de la semana.
     *
     * Este es el método que usa el SlotService para calcular slots.
     *
     * Ejemplo:
     *   findByServicioYDia(1, 1)
     *   → "Dame las franjas del servicio 1 para los lunes"
     *   → [['hora_inicio' => '16:00', 'hora_fin' => '18:00']]
     *
     * @param int $servicioId  ID del servicio
     * @param int $diaSemana   Día de la semana: 1=lunes, 2=martes, ..., 7=domingo.
     *                         Coincide con lo que devuelve date('N') en PHP.
     *
     * @return array Lista de franjas activas, ordenadas por hora.
     *               Puede estar vacía si ese día no hay disponibilidad.
     */
    public function findByServicioYDia(int $servicioId, int $diaSemana): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, hora_inicio, hora_fin
             FROM disponibilidad
             WHERE id_servicio = :servicio_id
               AND dia_semana = :dia
               AND activo = 1
             ORDER BY hora_inicio ASC"
        );

        // ¿Por qué "activo = 1"?
        // El profesional puede desactivar una franja temporalmente
        // (vacaciones, enfermedad) sin borrarla. Cuando vuelva,
        // la reactiva y ya tiene su horario configurado.

        $stmt->execute([
            ':servicio_id' => $servicioId,
            ':dia'         => $diaSemana,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Listar TODA la disponibilidad de un servicio (todos los días).
     *
     * Usado por el profesional para ver y gestionar sus franjas.
     * Devuelve todas, incluyendo las inactivas (para que pueda reactivarlas).
     *
     * Ejemplo de respuesta:
     *   [
     *     ['id' => 1, 'dia_semana' => 1, 'hora_inicio' => '16:00', 'hora_fin' => '18:00', 'activo' => 1],
     *     ['id' => 2, 'dia_semana' => 3, 'hora_inicio' => '10:00', 'hora_fin' => '13:00', 'activo' => 1],
     *   ]
     */
    public function findByServicio(int $servicioId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, dia_semana, hora_inicio, hora_fin, activo
             FROM disponibilidad
             WHERE id_servicio = :servicio_id
             ORDER BY dia_semana ASC, hora_inicio ASC"
        );

        // ORDER BY dia_semana primero, hora_inicio después:
        // Así salen agrupados por día y dentro de cada día, por hora.
        // Lunes 10:00, Lunes 16:00, Martes 09:00, Miércoles 10:00...

        $stmt->execute([':servicio_id' => $servicioId]);
        return $stmt->fetchAll();
    }

    /**
     * Buscar una franja por su ID.
     *
     * Usado internamente para verificar propiedad antes de editar/eliminar,
     * y para devolver la franja después de crearla.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM disponibilidad WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        // fetch() devuelve false si no encuentra nada.
        // Convertimos a null para ser consistentes con el resto de repos.
        return $row ?: null;
    }

    /**
     * Crear una franja de disponibilidad nueva.
     *
     * @param array $data  Debe contener: id_profesional, id_servicio, dia_semana,
     *                     hora_inicio, hora_fin.
     *
     * @return array La franja creada con su ID generado.
     *
     * Si intentas crear una franja que viola el UNIQUE constraint
     * (mismo profesional + servicio + día + hora_inicio), MySQL lanza
     * una excepción. El controlador debería capturar ese caso.
     */
    public function create(array $data): array
    {
        $stmt = $this->db->prepare(
            "INSERT INTO disponibilidad (id_profesional, id_servicio, dia_semana, hora_inicio, hora_fin)
             VALUES (:id_profesional, :id_servicio, :dia_semana, :hora_inicio, :hora_fin)"
        );

        $stmt->execute([
            ':id_profesional' => $data['id_profesional'],
            ':id_servicio'    => $data['id_servicio'],
            ':dia_semana'     => $data['dia_semana'],
            ':hora_inicio'    => $data['hora_inicio'],
            ':hora_fin'       => $data['hora_fin'],
        ]);

        // MySQL no tiene RETURNING * como PostgreSQL.
        // Usamos lastInsertId() para obtener el ID generado
        // y luego hacemos un SELECT para devolver el registro completo.
        $id = (int) $this->db->lastInsertId();
        return $this->findById($id);
    }

    /**
     * Actualizar una franja existente.
     *
     * Permite cambiar día, horas y estado activo/inactivo.
     * El controlador ya verificó que la franja le pertenece al profesional.
     */
    public function update(int $id, array $data): ?array
    {
        $stmt = $this->db->prepare(
            "UPDATE disponibilidad
             SET dia_semana = :dia_semana,
                 hora_inicio = :hora_inicio,
                 hora_fin = :hora_fin,
                 activo = :activo
             WHERE id = :id"
        );

        $stmt->execute([
            ':id'          => $id,
            ':dia_semana'  => $data['dia_semana'],
            ':hora_inicio' => $data['hora_inicio'],
            ':hora_fin'    => $data['hora_fin'],
            ':activo'      => $data['activo'] ?? 1,
        ]);

        return $this->findById($id);
    }

    /**
     * Eliminar una franja.
     *
     * ¿Cuándo borrar vs desactivar?
     *   - Borrar: el profesional ya no quiere ofrecer ese horario nunca más
     *   - Desactivar (activo=0): pausa temporal (vacaciones, etc.)
     *
     * @return bool true si se borró, false si no existía.
     *
     * rowCount() devuelve cuántas filas afectó el DELETE.
     * Si es 0, el ID no existía.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM disponibilidad WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}