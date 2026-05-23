<?php

/**
 * ============================================
 * PROFESIONAL REPOSITORY
 * ============================================
 *
 * Mismo patrón que UsuarioRepository pero para la tabla profesionales.
 *
 * IMPORTANTE: La tabla profesionales también necesita password_hash:
 *
 *   ALTER TABLE profesionales ADD COLUMN password_hash VARCHAR(255) NOT NULL;
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class ProfesionalRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Buscar por email (para login). Incluye password_hash.
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM profesionales WHERE email = :email"
        );
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Buscar por ID. SIN datos sensibles (password, iban, documento).
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, nombre, apellido, descripcion, email, telefono,
                    direccion, ciudad, pais, codigo_postal, foto_perfil, created_at
             FROM profesionales WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Buscar por ID con datos privados (para el propio profesional).
     * Incluye IBAN y documento, pero NO password_hash.
     */
    public function findByIdPrivate(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, nombre, apellido, descripcion, tipo_documento,
                    numero_documento, iban, email, telefono,
                    direccion, ciudad, pais, codigo_postal, foto_perfil, created_at
             FROM profesionales WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Registro de profesional.
     */
    public function create(array $data): array
    {
        $stmt = $this->db->prepare(
            "INSERT INTO profesionales (nombre, apellido, descripcion, tipo_documento,
                                        numero_documento, iban, email, password_hash,
                                        telefono, direccion, ciudad, pais, codigo_postal)
             VALUES (:nombre, :apellido, :descripcion, :tipo_documento,
                     :numero_documento, :iban, :email, :password_hash,
                     :telefono, :direccion, :ciudad, :pais, :codigo_postal)"
        );

        $stmt->execute([
            ':nombre'           => $data['nombre'],
            ':apellido'         => $data['apellido'],
            ':descripcion'      => $data['descripcion'] ?? null,
            ':tipo_documento'   => $data['tipo_documento'] ?? null,
            ':numero_documento' => $data['numero_documento'] ?? null,
            ':iban'             => $data['iban'] ?? null,
            ':email'            => $data['email'],
            ':password_hash'    => $data['password_hash'],
            ':telefono'         => $data['telefono'] ?? null,
            ':direccion'        => $data['direccion'] ?? null,
            ':ciudad'           => $data['ciudad'] ?? null,
            ':pais'             => $data['pais'] ?? null,
            ':codigo_postal'    => $data['codigo_postal'] ?? null,
        ]);

        $id = (int) $this->db->lastInsertId();
        return $this->findById($id);
    }

    /**
     * Verificar si un email ya está registrado.
     */
    public function emailExists(string $email): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total FROM profesionales WHERE email = :email"
        );
        $stmt->execute([':email' => $email]);
        return (int) $stmt->fetch()['total'] > 0;
    }

    /**
     * Actualizar la foto de perfil de un profesional.
     *
     * Guarda la ruta relativa en la base de datos.
     * Ejemplo: 'profesionales/42/profile.jpg'
     *
     * El ImageService se encarga de mover el archivo al disco.
     * Aquí solo guardamos la referencia.
     *
     * @param int         $id            ID del profesional
     * @param string|null $rutaRelativa  Ruta de la imagen (null para eliminar)
     *
     * @return array|null El profesional actualizado
     */
    public function updateFoto(int $id, ?string $rutaRelativa): ?array
    {
        $stmt = $this->db->prepare(
            "UPDATE profesionales SET foto_perfil = :foto WHERE id = :id"
        );
        $stmt->execute([
            ':id'   => $id,
            ':foto' => $rutaRelativa,
        ]);

        return $this->findById($id);
    }

    /**
     * Listar profesionales (endpoint público).
     */
    public function findAll(array $filters = [], int $page = 1, int $limit = 20): array
    {
        $sql = "SELECT id, nombre, apellido, descripcion, ciudad, pais, foto_perfil
                FROM profesionales WHERE 1=1";

        $params = [];

        if (!empty($filters['ciudad'])) {
            $sql .= " AND ciudad = :ciudad";
            $params[':ciudad'] = $filters['ciudad'];
        }

        if (!empty($filters['pais'])) {
            $sql .= " AND pais = :pais";
            $params[':pais'] = $filters['pais'];
        }

        if (!empty($filters['q'])) {
            $sql .= " AND (nombre LIKE :q OR apellido LIKE :q2 OR descripcion LIKE :q3)";
            $params[':q']  = '%' . $filters['q'] . '%';
            $params[':q2'] = '%' . $filters['q'] . '%';
            $params[':q3'] = '%' . $filters['q'] . '%';
        }

        // Count
        $countStmt = $this->db->prepare("SELECT COUNT(*) AS total FROM ($sql) AS counted");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['total'];

        // Paginar
        $offset = ($page - 1) * $limit;
        $sql .= " ORDER BY id DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return ['data' => $stmt->fetchAll(), 'total' => $total];
    }
}