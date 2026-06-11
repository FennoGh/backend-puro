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
            "SELECT id, nombre, apellido, slug, descripcion, email, telefono,
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
            "SELECT id, nombre, apellido, slug, descripcion, tipo_documento,
                    numero_documento, iban, email, telefono,
                    direccion, ciudad, pais, codigo_postal, foto_perfil, created_at
             FROM profesionales WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Verificar si un slug ya existe (excluyendo un ID si es necesario).
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) AS total FROM profesionales WHERE slug = :slug";
        $params = [':slug' => $slug];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetch()['total'] > 0;
    }

    /**
     * Generar un slug único basado en nombre y apellido.
     */
    public function generateUniqueSlug(string $nombre, string $apellido, ?int $excludeId = null): string
    {
        $baseSlug = \App\Helpers\SlugHelper::slugify($nombre . ' ' . $apellido);
        $slug = $baseSlug;
        $counter = 1;

        while ($this->slugExists($slug, $excludeId)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Buscar por Slug.
     */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, nombre, apellido, slug, descripcion, email, telefono,
                    direccion, ciudad, pais, codigo_postal, foto_perfil, created_at
             FROM profesionales WHERE slug = :slug"
        );
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Registro de profesional.
     */
    public function create(array $data): array
    {
        // Generar slug único antes de insertar
        $slug = $this->generateUniqueSlug($data['nombre'], $data['apellido']);

        $stmt = $this->db->prepare(
            "INSERT INTO profesionales (nombre, apellido, slug, descripcion, tipo_documento,
                                        numero_documento, iban, email, password_hash,
                                        telefono, direccion, ciudad, pais, codigo_postal)
             VALUES (:nombre, :apellido, :slug, :descripcion, :tipo_documento,
                     :numero_documento, :iban, :email, :password_hash,
                     :telefono, :direccion, :ciudad, :pais, :codigo_postal)"
        );

        $stmt->execute([
            ':nombre'           => $data['nombre'],
            ':apellido'         => $data['apellido'],
            ':slug'             => $slug,
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
     * Actualizar profesional.
     */
    public function update(int $id, array $data): ?array
    {
        // Si se actualiza el nombre o apellido, regeneramos el slug
        if (isset($data['nombre']) || isset($data['apellido'])) {
            $current = $this->findById($id);
            if ($current) {
                $nombre = $data['nombre'] ?? $current['nombre'];
                $apellido = $data['apellido'] ?? $current['apellido'];
                $data['slug'] = $this->generateUniqueSlug($nombre, $apellido, $id);
            }
        }

        $fields = [];
        $params = [':id' => $id];

        $updatableFields = [
            'nombre', 'apellido', 'slug', 'descripcion', 'tipo_documento',
            'numero_documento', 'iban', 'email', 'telefono', 'direccion',
            'ciudad', 'pais', 'codigo_postal'
        ];

        foreach ($updatableFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return $this->findById($id);
        }

        $sql = "UPDATE profesionales SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

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
        $sql = "SELECT id, nombre, apellido, slug, descripcion, ciudad, pais, foto_perfil
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