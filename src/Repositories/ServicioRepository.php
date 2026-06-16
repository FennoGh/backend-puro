<?php

/*
 * ============================================
 * SERVICIO REPOSITORY — Acceso a datos de servicios
 * ============================================
 *
 * ¿Qué es un Repository?
 * Es el ÚNICO lugar donde existe SQL en tu proyecto.
 * Encapsula todas las queries relacionadas con una tabla (o grupo de tablas).
 *
 * Reglas:
 *   ✓ Solo queries SQL y nada más
 *   ✗ No valida datos (eso es del Service)
 *   ✗ No lee $_GET ni $_POST (eso es del Controller)
 *   ✗ No envía respuestas HTTP (eso es del Controller/Response)
 *
 * ¿Por qué separar el SQL?
 *   1. Si cambias de MySQL a PostgreSQL, solo tocas los Repositories
 *   2. Puedes testear la lógica de negocio sin base de datos (usando mocks)
 *   3. Evitas duplicar queries — si 3 controladores necesitan buscar
 *      servicios por ciudad, todos llaman al mismo método del repo
 *
 * Ubicación en el flujo:
 *   controller → service → [REPOSITORY] → MySQL
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class ServicioRepository
{
    /**
     * La conexión a la base de datos.
     * Se inicializa en el constructor.
     */
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Buscar servicios con filtros opcionales.
     *
     * Ejemplo de llamada:
     *   $repo->findAll(['ciudad' => 'Barcelona', 'precio_max' => 60], 1, 20);
     *
     * @param array $filters  Filtros opcionales: ciudad, pais, precio_min, precio_max, q
     * @param int   $page     Página actual (empieza en 1)
     * @param int   $limit    Resultados por página
     *
     * @return array ['data' => [...], 'total' => int]
     */
    public function findAll(array $filters = [], int $page = 1, int $limit = 20): array
    {
        // ─── Construir la query base ───
        // JOIN con profesionales para devolver nombre del profesional junto al servicio.
        //
        // ¿Por qué JOIN y no dos queries separadas?
        // Porque una sola query con JOIN es más rápida que hacer:
        //   1. SELECT servicios
        //   2. Para cada servicio, SELECT profesional WHERE id = ...
        // Eso sería el "problema N+1" (1 query + N queries extra).
        $sql = "SELECT s.id, s.nombre, s.tagline, s.bio, s.foto, s.precio, s.duracion_sesion,
                       s.direccion, s.ciudad, s.pais,
                       p.id AS prof_id, p.nombre AS prof_nombre, p.apellido AS prof_apellido
                FROM servicios s
                JOIN profesionales p ON s.id_profesional = p.id
                WHERE 1=1";

        // "WHERE 1=1" es un truco útil:
        // Como todos los filtros se añaden con "AND ...", necesitamos
        // un WHERE inicial. "1=1" es siempre verdadero, no afecta el resultado,
        // pero nos permite añadir "AND ciudad = :ciudad" sin preocuparnos
        // de si es el primer filtro o no.

        $params = [];

        // ─── Filtros dinámicos ───
        // Cada filtro se añade SOLO si el cliente lo envió.
        // Siempre usamos :parametro (prepared statement), NUNCA concatenamos
        // el valor directamente en el SQL.

        if (!empty($filters['ciudad'])) {
            $sql .= " AND s.ciudad = :ciudad";
            $params[':ciudad'] = $filters['ciudad'];
        }

        if (!empty($filters['pais'])) {
            $sql .= " AND s.pais = :pais";
            $params[':pais'] = $filters['pais'];
        }

        if (!empty($filters['precio_min'])) {
            $sql .= " AND s.precio >= :precio_min";
            $params[':precio_min'] = $filters['precio_min'];
        }

        if (!empty($filters['precio_max'])) {
            $sql .= " AND s.precio <= :precio_max";
            $params[':precio_max'] = $filters['precio_max'];
        }

        // ─── Búsqueda por texto ───
        // LIKE '%texto%' busca el texto en cualquier parte del campo.
        // Es lento en tablas grandes (no usa índice), pero suficiente para un MVP.
        // Para escalar: usa FULLTEXT index de MySQL o Elasticsearch.
        if (!empty($filters['q'])) {
            $sql .= " AND (s.nombre LIKE :q OR s.tagline LIKE :q2 OR s.bio LIKE :q3)";
            $params[':q']  = '%' . $filters['q'] . '%';
            $params[':q2'] = '%' . $filters['q'] . '%';
            $params[':q3'] = '%' . $filters['q'] . '%';
        }

        // ─── Contar el total ANTES de paginar ───
        // El cliente necesita saber cuántos resultados hay en total
        // para mostrar "Página 1 de 7".
        //
        // Usamos la misma query con los mismos filtros, pero envuelta en COUNT(*).
        // Así contamos exactamente los mismos registros que vamos a devolver.
        $countSql = "SELECT COUNT(*) AS total FROM ($sql) AS counted";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['total'];

        // ─── Paginación ───
        // LIMIT = cuántos resultados devolver
        // OFFSET = cuántos saltarse
        //
        // Página 1, limit 20: OFFSET 0  (empieza desde el inicio)
        // Página 2, limit 20: OFFSET 20 (salta los primeros 20)
        // Página 3, limit 20: OFFSET 40 (salta los primeros 40)
        //
        // Fórmula: offset = (page - 1) * limit
        $offset = ($page - 1) * $limit;
        $sql .= " ORDER BY s.id DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);

        // ─── Bind de parámetros ───
        // Para los filtros de texto usamos bindValue normal.
        // Para LIMIT y OFFSET DEBEMOS especificar PDO::PARAM_INT.
        //
        // ¿Por qué? Porque con EMULATE_PREPARES=false, MySQL necesita
        // que LIMIT y OFFSET sean enteros reales, no strings.
        // Si pasas "20" (string), MySQL da error.
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        $rows = $stmt->fetchAll();

        // ─── Formatear la respuesta ───
        // Transformamos las filas planas del JOIN en objetos anidados.
        // En vez de: { "prof_nombre": "Pedro", "prof_apellido": "García" }
        // Devolvemos: { "profesional": { "id": 1, "nombre": "Pedro", "apellido": "García" } }
        //
        // Esto es más limpio para el frontend y coincide con el diseño de la API.
        $data = array_map(function (array $row): array {
            return [
                'id'              => (int) $row['id'],
                'nombre'          => $row['nombre'],
                'tagline'         => $row['tagline'],
                'bio'             => $row['bio'],
                'foto'            => $row['foto'],
                'precio'          => (float) $row['precio'],
                'duracion_sesion' => (int) $row['duracion_sesion'],
                'ciudad'          => $row['ciudad'],
                'pais'            => $row['pais'],
                'profesional'     => [
                    'id'       => (int) $row['prof_id'],
                    'nombre'   => $row['prof_nombre'],
                    'apellido' => $row['prof_apellido'],
                ],
            ];
        }, $rows);

        return ['data' => $data, 'total' => $total];
    }

    /**
     * Buscar un servicio por su ID.
     *
     * @return array|null  El servicio o null si no existe.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, p.id AS prof_id, p.nombre AS prof_nombre,
                    p.apellido AS prof_apellido, p.tagline AS prof_tagline,
                    p.bio AS prof_bio
             FROM servicios s
             JOIN profesionales p ON s.id_profesional = p.id
             WHERE s.id = :id"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return [
            'id'              => (int) $row['id'],
            'nombre'          => $row['nombre'],
            'tagline'         => $row['tagline'],
            'bio'             => $row['bio'],
            'foto'            => $row['foto'],
            'precio'          => (float) $row['precio'],
            'duracion_sesion' => (int) $row['duracion_sesion'],
            'direccion'       => $row['direccion'],
            'ciudad'          => $row['ciudad'],
            'pais'            => $row['pais'],
            'profesional'     => [
                'id'       => (int) $row['prof_id'],
                'nombre'   => $row['prof_nombre'],
                'apellido' => $row['prof_apellido'],
                'tagline'  => $row['prof_tagline'],
                'bio'      => $row['prof_bio'],
            ],
        ];
    }

    /**
     * Buscar servicios de un profesional específico.
     * Usado por el profesional para ver "mis servicios".
     */
    public function findByProfesional(int $profesionalId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, nombre, tagline, bio, foto, precio, duracion_sesion,
                    direccion, ciudad, pais, created_at
             FROM servicios
             WHERE id_profesional = :prof_id
             ORDER BY created_at DESC"
        );
        $stmt->execute([':prof_id' => $profesionalId]);
        return $stmt->fetchAll();
    }

    /**
     * Crear un servicio nuevo.
     *
     * NOTA: En MySQL no existe RETURNING * como en PostgreSQL.
     * Usamos lastInsertId() para obtener el ID generado y luego
     * hacemos un SELECT para devolver el registro completo.
     */
    public function create(array $data): array
    {
        $stmt = $this->db->prepare(
            "INSERT INTO servicios (id_profesional, nombre, tagline, bio, foto, precio,
                                    duracion_sesion, direccion, ciudad, pais)
             VALUES (:id_profesional, :nombre, :tagline, :bio, :foto, :precio,
                     :duracion_sesion, :direccion, :ciudad, :pais)"
        );

        $stmt->execute([
            ':id_profesional' => $data['id_profesional'],
            ':nombre'         => $data['nombre'],
            ':tagline'        => $data['tagline'] ?? null,
            ':bio'            => $data['bio'] ?? null,
            ':foto'           => $data['foto'] ?? null,
            ':precio'         => $data['precio'],
            ':duracion_sesion'=> $data['duracion_sesion'],
            ':direccion'      => $data['direccion'] ?? null,
            ':ciudad'         => $data['ciudad'] ?? null,
            ':pais'           => $data['pais'] ?? null,
        ]);

        // lastInsertId() devuelve el ID autoincrement que MySQL generó
        $id = (int) $this->db->lastInsertId();

        // Devolver el registro completo
        return $this->findById($id);
    }

    /**
     * Actualizar un servicio existente.
     */
    public function update(int $id, array $data): ?array
    {
        $stmt = $this->db->prepare(
            "UPDATE servicios
             SET nombre = :nombre,
                 tagline = :tagline,
                 bio = :bio,
                 foto = :foto,
                 precio = :precio,
                 duracion_sesion = :duracion_sesion,
                 direccion = :direccion,
                 ciudad = :ciudad,
                 pais = :pais
             WHERE id = :id"
        );

        $stmt->execute([
            ':id'              => $id,
            ':nombre'          => $data['nombre'],
            ':tagline'         => $data['tagline'] ?? null,
            ':bio'             => $data['bio'] ?? null,
            ':foto'            => $data['foto'] ?? null,
            ':precio'          => $data['precio'],
            ':duracion_sesion' => $data['duracion_sesion'],
            ':direccion'       => $data['direccion'] ?? null,
            ':ciudad'          => $data['ciudad'] ?? null,
            ':pais'            => $data['pais'] ?? null,
        ]);

        return $this->findById($id);
    }

    /**
     * Eliminar un servicio.
     * CASCADE en el schema borra la disponibilidad asociada automáticamente.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM servicios WHERE id = :id");
        $stmt->execute([':id' => $id]);

        // rowCount() devuelve cuántas filas se afectaron.
        // Si es 0, el servicio no existía.
        return $stmt->rowCount() > 0;
    }
}