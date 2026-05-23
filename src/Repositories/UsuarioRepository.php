<?php

/**
 * ============================================
 * USUARIO REPOSITORY
 * ============================================
 *
 * Acceso a datos de la tabla usuarios.
 * Incluye métodos para el flujo de autenticación (buscar por email)
 * y para el perfil del usuario.
 *
 * IMPORTANTE: La tabla usuarios necesita un campo password_hash
 * que no está en el schema original. Antes de probar, ejecuta:
 *
 *   ALTER TABLE usuarios ADD COLUMN password_hash VARCHAR(255) NOT NULL;
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class UsuarioRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Buscar un usuario por email.
     * Usado en el login para verificar credenciales.
     *
     * Devuelve TODOS los campos incluyendo password_hash
     * (el Service lo necesita para verificar la contraseña).
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM usuarios WHERE email = :email"
        );
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Buscar un usuario por ID.
     * NO devuelve password_hash (es para el perfil público).
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, nombre, apellido, email, telefono,
                    direccion, ciudad, pais, codigo_postal, created_at
             FROM usuarios WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Crear un usuario nuevo (registro).
     *
     * @return array El usuario creado (sin password_hash)
     */
    public function create(array $data): array
    {
        $stmt = $this->db->prepare(
            "INSERT INTO usuarios (nombre, apellido, email, password_hash,
                                   telefono, direccion, ciudad, pais, codigo_postal)
             VALUES (:nombre, :apellido, :email, :password_hash,
                     :telefono, :direccion, :ciudad, :pais, :codigo_postal)"
        );

        $stmt->execute([
            ':nombre'        => $data['nombre'],
            ':apellido'      => $data['apellido'],
            ':email'         => $data['email'],
            ':password_hash' => $data['password_hash'],
            ':telefono'      => $data['telefono'] ?? null,
            ':direccion'     => $data['direccion'] ?? null,
            ':ciudad'        => $data['ciudad'] ?? null,
            ':pais'          => $data['pais'] ?? null,
            ':codigo_postal' => $data['codigo_postal'] ?? null,
        ]);

        $id = (int) $this->db->lastInsertId();
        return $this->findById($id);
    }

    /**
     * Actualizar perfil del usuario.
     * NO permite cambiar email ni password (esos van por flujos separados).
     */
    public function update(int $id, array $data): ?array
    {
        $stmt = $this->db->prepare(
            "UPDATE usuarios
             SET nombre = :nombre,
                 apellido = :apellido,
                 telefono = :telefono,
                 direccion = :direccion,
                 ciudad = :ciudad,
                 pais = :pais,
                 codigo_postal = :codigo_postal
             WHERE id = :id"
        );

        $stmt->execute([
            ':id'             => $id,
            ':nombre'         => $data['nombre'],
            ':apellido'       => $data['apellido'],
            ':telefono'       => $data['telefono'] ?? null,
            ':direccion'      => $data['direccion'] ?? null,
            ':ciudad'         => $data['ciudad'] ?? null,
            ':pais'           => $data['pais'] ?? null,
            ':codigo_postal'  => $data['codigo_postal'] ?? null,
        ]);

        return $this->findById($id);
    }

    /**
     * Verificar si un email ya está registrado.
     */
    public function emailExists(string $email): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total FROM usuarios WHERE email = :email"
        );
        $stmt->execute([':email' => $email]);
        return (int) $stmt->fetch()['total'] > 0;
    }
}