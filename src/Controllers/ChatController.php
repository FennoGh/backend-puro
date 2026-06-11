<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Repositories\ChatRepository;

class ChatController
{
    private ChatRepository $chatRepo;

    public function __construct()
    {
        $this->chatRepo = new ChatRepository();
    }

    /**
     * GET /api/v1/conversaciones
     * Listar conversaciones del usuario/profesional autenticado.
     */
    public function index(): void
    {
        $authId = (int) $_REQUEST['auth_user_id'];
        $role   = $_REQUEST['auth_user_role'];

        if ($role === 'profesional') {
            $convos = $this->chatRepo->listByProfesional($authId);
        } else {
            $convos = $this->chatRepo->listByUsuario($authId);
        }

        Response::json($convos);
    }

    /**
     * POST /api/v1/conversaciones
     * Crear o recuperar conversación. Requiere auth_user_role = usuario.
     */
    public function store(): void
    {
        $userId = (int) $_REQUEST['auth_user_id'];
        $role   = $_REQUEST['auth_user_role'];

        if ($role !== 'usuario') {
            Response::error(403, 'FORBIDDEN', 'Solo los clientes pueden iniciar nuevas conversaciones');
        }

        $body = json_decode(file_get_contents('php://input'), true);
        if (empty($body['id_profesional'])) {
            Response::error(422, 'VALIDATION_ERROR', 'El campo id_profesional es obligatorio');
        }

        $profId = (int) $body['id_profesional'];
        $convo = $this->chatRepo->getOrCreateConversacion($userId, $profId);

        Response::json($convo, 201);
    }

    /**
     * GET /api/v1/conversaciones/:id/mensajes
     * Obtener mensajes de la conversación. Soporta polling dinámico.
     */
    public function showMessages(string $id): void
    {
        $authId = (int) $_REQUEST['auth_user_id'];
        $role   = $_REQUEST['auth_user_role'];
        $convoId = (int) $id;
        $lastId = max(0, (int) ($_GET['last_id'] ?? 0));

        $convo = $this->chatRepo->findById($convoId);
        if (!$convo) {
            Response::error(404, 'NOT_FOUND', 'Conversación no encontrada');
        }

        // Verificar acceso: el usuario debe ser participante del chat
        $esUsuario = ($role === 'usuario' && $convo['id_usuario'] === $authId);
        $esProf    = ($role === 'profesional' && $convo['id_profesional'] === $authId);

        if (!$esUsuario && !$esProf) {
            Response::error(403, 'FORBIDDEN', 'No tienes acceso a esta conversación');
        }

        $mensajes = $this->chatRepo->getMensajes($convoId, $lastId);
        Response::json($mensajes);
    }

    /**
     * POST /api/v1/conversaciones/:id/mensajes
     * Enviar mensaje.
     */
    public function sendMessage(string $id): void
    {
        $authId = (int) $_REQUEST['auth_user_id'];
        $role   = $_REQUEST['auth_user_role'];
        $convoId = (int) $id;

        $body = json_decode(file_get_contents('php://input'), true);
        if (empty($body['mensaje'])) {
            Response::error(422, 'VALIDATION_ERROR', 'El campo mensaje no puede estar vacío');
        }

        $convo = $this->chatRepo->findById($convoId);
        if (!$convo) {
            Response::error(404, 'NOT_FOUND', 'Conversación no encontrada');
        }

        // Verificar acceso: el usuario debe ser participante del chat
        $esUsuario = ($role === 'usuario' && $convo['id_usuario'] === $authId);
        $esProf    = ($role === 'profesional' && $convo['id_profesional'] === $authId);

        if (!$esUsuario && !$esProf) {
            Response::error(403, 'FORBIDDEN', 'No tienes acceso a esta conversación');
        }

        $mensaje = $this->chatRepo->createMensaje($convoId, $role, $body['mensaje']);
        Response::json($mensaje, 201);
    }
}
