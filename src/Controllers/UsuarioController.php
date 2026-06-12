<?php

/**
 * ============================================
 * USUARIO CONTROLLER — Registro y perfil
 * ============================================
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Services\AuthService;
use App\Repositories\UsuarioRepository;

class UsuarioController
{
    private AuthService $authService;
    private UsuarioRepository $repo;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->repo = new UsuarioRepository();
    }

    /**
     * POST /api/v1/usuarios (registro)
     *
     * Body:
     * {
     *   "nombre": "Carlos",
     *   "apellido": "López",
     *   "email": "carlos@email.com",
     *   "password": "miPasswordSeguro123",
     *   "telefono": "+34612345678",
     *   "ciudad": "Barcelona",
     *   "pais": "España"
     * }
     */
    public function register(): void
    {
        $body = json_decode(file_get_contents('php://input'), true);

        // ─── Validar campos obligatorios ───
        // telefono es NOT NULL en el esquema de usuarios.
        if (empty($body['nombre']) || empty($body['apellido'])
            || empty($body['email']) || empty($body['password'])
            || empty($body['telefono'])) {
            Response::error(422, 'VALIDATION_ERROR', 'Campos obligatorios: nombre, apellido, email, password, telefono');
        }

        // ─── Validar email ───
        if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
            Response::error(422, 'VALIDATION_ERROR', 'Formato de email inválido');
        }

        // ─── Validar longitud de contraseña ───
        // 8 caracteres es el mínimo razonable.
        // En producción podrías exigir mayúsculas, números, etc.
        if (strlen($body['password']) < 8) {
            Response::error(422, 'VALIDATION_ERROR', 'La contraseña debe tener al menos 8 caracteres');
        }

        // ─── Registrar ───
        try {
            $result = $this->authService->registerUsuario($body);
            Response::json($result, 201);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'DUPLICATE_EMAIL') {
                Response::error(409, 'DUPLICATE_EMAIL', 'Ya existe una cuenta con este email');
            }
            throw $e; // Si es otro error, que suba al catch global
        }
    }

    /**
     * GET /api/v1/usuarios/me
     *
     * Devuelve el perfil del usuario autenticado.
     * El ID viene de $_REQUEST['auth_user_id'] (puesto por AuthMiddleware).
     */
    public function me(): void
    {
        $userId = (int) $_REQUEST['auth_user_id'];
        $user = $this->repo->findById($userId);

        if (!$user) {
            Response::error(404, 'NOT_FOUND', 'Usuario no encontrado');
        }

        Response::json($user);
    }

    /**
     * PUT /api/v1/usuarios/me
     */
    public function update(): void
    {
        $userId = (int) $_REQUEST['auth_user_id'];
        $body = json_decode(file_get_contents('php://input'), true);

        if (empty($body['nombre']) || empty($body['apellido'])) {
            Response::error(422, 'VALIDATION_ERROR', 'Campos obligatorios: nombre, apellido');
        }

        $user = $this->repo->update($userId, $body);
        Response::json($user);
    }
}