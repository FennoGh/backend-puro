<?php

/**
 * ============================================
 * AUTH CONTROLLER — Login
 * ============================================
 *
 * Maneja el endpoint de login.
 * El registro está en UsuarioController y ProfesionalController
 * porque cada uno tiene campos diferentes.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Services\AuthService;

class AuthController
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    /**
     * POST /api/v1/auth/login
     *
     * Body:
     * {
     *   "email": "pedro@email.com",
     *   "password": "123456",
     *   "role": "profesional"
     * }
     *
     * Response 200:
     * {
     *   "token": "eyJhbGciOiJIUzI1NiJ9...",
     *   "user": { "id": 1, "nombre": "Pedro", ... }
     * }
     */
    public function login(): void
    {
        // ─── 1. Leer el body ───
        $body = json_decode(file_get_contents('php://input'), true);

        // ─── 2. Validar campos obligatorios ───
        if (empty($body['email']) || empty($body['password']) || empty($body['role'])) {
            Response::error(422, 'VALIDATION_ERROR', 'Campos obligatorios: email, password, role');
        }

        // ─── 3. Validar que el rol es válido ───
        $rolesValidos = ['usuario', 'profesional'];
        if (!in_array($body['role'], $rolesValidos, true)) {
            Response::error(422, 'VALIDATION_ERROR', 'El campo role debe ser: usuario o profesional');
        }

        // ─── 4. Intentar login ───
        try {
            $result = $this->authService->login(
                $body['email'],
                $body['password'],
                $body['role']
            );

            Response::json($result);

        } catch (\RuntimeException $e) {
            // El mensaje siempre es "Credenciales inválidas" (genérico a propósito)
            Response::error(401, 'INVALID_CREDENTIALS', $e->getMessage());
        }
    }
}