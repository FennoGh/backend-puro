<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class ChatRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Obtener o crear conversación entre un usuario y un profesional.
     */
    public function getOrCreateConversacion(int $userId, int $profId): array
    {
        // 1. Intentar buscar conversación existente
        $stmt = $this->db->prepare(
            "SELECT * FROM conversaciones WHERE id_usuario = :user_id AND id_profesional = :prof_id"
        );
        $stmt->execute([':user_id' => $userId, ':prof_id' => $profId]);
        $convo = $stmt->fetch();

        if ($convo) {
            // Convert types
            $convo['id'] = (int) $convo['id'];
            $convo['id_usuario'] = (int) $convo['id_usuario'];
            $convo['id_profesional'] = (int) $convo['id_profesional'];
            return $convo;
        }

        // 2. Si no existe, crearla
        $stmt = $this->db->prepare(
            "INSERT INTO conversaciones (id_usuario, id_profesional) VALUES (:user_id, :prof_id)"
        );
        $stmt->execute([':user_id' => $userId, ':prof_id' => $profId]);
        $id = (int) $this->db->lastInsertId();

        return [
            'id' => $id,
            'id_usuario' => $userId,
            'id_profesional' => $profId,
            'created_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Listar conversaciones de un usuario (cliente).
     */
    public function listByUsuario(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, p.nombre AS prof_nombre, p.apellido AS prof_apellido, p.foto_perfil AS prof_foto
             FROM conversaciones c
             JOIN profesionales p ON c.id_profesional = p.id
             WHERE c.id_usuario = :user_id
             ORDER BY c.created_at DESC"
        );
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll();

        return array_map(function(array $row) {
            return [
                'id' => (int) $row['id'],
                'id_usuario' => (int) $row['id_usuario'],
                'id_profesional' => (int) $row['id_profesional'],
                'created_at' => $row['created_at'],
                'profesional' => [
                    'nombre' => $row['prof_nombre'],
                    'apellido' => $row['prof_apellido'],
                    'foto_perfil' => $row['prof_foto']
                ]
            ];
        }, $rows);
    }

    /**
     * Listar conversaciones de un profesional.
     */
    public function listByProfesional(int $profId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, u.nombre AS user_nombre, u.apellido AS user_apellido
             FROM conversaciones c
             JOIN usuarios u ON c.id_usuario = u.id
             WHERE c.id_profesional = :prof_id
             ORDER BY c.created_at DESC"
        );
        $stmt->execute([':prof_id' => $profId]);
        $rows = $stmt->fetchAll();

        return array_map(function(array $row) {
            return [
                'id' => (int) $row['id'],
                'id_usuario' => (int) $row['id_usuario'],
                'id_profesional' => (int) $row['id_profesional'],
                'created_at' => $row['created_at'],
                'usuario' => [
                    'nombre' => $row['user_nombre'],
                    'apellido' => $row['user_apellido']
                ]
            ];
        }, $rows);
    }

    /**
     * Buscar una conversación por ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM conversaciones WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $row['id'] = (int) $row['id'];
        $row['id_usuario'] = (int) $row['id_usuario'];
        $row['id_profesional'] = (int) $row['id_profesional'];
        return $row;
    }

    /**
     * Enviar mensaje.
     */
    public function createMensaje(int $convoId, string $senderType, string $text): array
    {
        $stmt = $this->db->prepare(
            "INSERT INTO mensajes (id_conversacion, remitente_tipo, mensaje)
             VALUES (:convo_id, :sender_type, :mensaje)"
        );
        $stmt->execute([
            ':convo_id' => $convoId,
            ':sender_type' => $senderType,
            ':mensaje' => $text
        ]);

        $id = (int) $this->db->lastInsertId();

        // Obtener el mensaje creado
        $stmt = $this->db->prepare("SELECT * FROM mensajes WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        $row['id'] = (int) $row['id'];
        $row['id_conversacion'] = (int) $row['id_conversacion'];
        $row['leido'] = (bool) $row['leido'];
        return $row;
    }

    /**
     * Obtener mensajes de una conversación (con soporte opcional de last_id para polling).
     */
    public function getMensajes(int $convoId, int $lastId = 0): array
    {
        $sql = "SELECT * FROM mensajes WHERE id_conversacion = :convo_id";
        $params = [':convo_id' => $convoId];

        if ($lastId > 0) {
            $sql .= " AND id > :last_id";
            $params[':last_id'] = $lastId;
        }

        $sql .= " ORDER BY id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map(function(array $row) {
            return [
                'id' => (int) $row['id'],
                'id_conversacion' => (int) $row['id_conversacion'],
                'remitente_tipo' => $row['remitente_tipo'],
                'mensaje' => $row['mensaje'],
                'leido' => (bool) $row['leido'],
                'created_at' => $row['created_at']
            ];
        }, $rows);
    }
}
