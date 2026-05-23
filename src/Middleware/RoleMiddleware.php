<?php

/**
 * ============================================
 * ROLE MIDDLEWARE
 * ============================================
 *
 * Verifica que el usuario autenticado tiene el ROL correcto.
 *
 * Diferencia entre autenticación y autorización:
 *   Autenticación (AuthMiddleware) = ¿QUIÉN eres?
 *   Autorización (RoleMiddleware)  = ¿QUÉ PUEDES HACER?
 *
 * Ejemplo:
 *   Pedro tiene rol 'profesional'. Si intenta acceder a
 *   POST /reservas (que requiere rol 'usuario'), este middleware
 *   lo bloquea con 403 Forbidden.
 *
 * Este middleware SIEMPRE se ejecuta DESPUÉS de AuthMiddleware,
 * porque necesita que $_REQUEST['auth_user_role'] ya esté definido.
 *
 * En api.php se usa así:
 *   $router->post('reservas', [...], [AuthMiddleware::class, RoleMiddleware::class . ':usuario']);
 *
 * El ':usuario' es el parámetro que el Router pasa a handle($param).
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;

class RoleMiddleware implements MiddlewareInterface
{
    /**
     * @param string|null $param  El rol requerido (ej: 'usuario', 'profesional').
     *                            Viene de la definición de la ruta: ':usuario' o ':profesional'.
     */
    public function handle(?string $param = null): void
    {
        // El rol del usuario autenticado lo puso AuthMiddleware en $_REQUEST
        $userRole = $_REQUEST['auth_user_role'] ?? null;

        // $param es el rol requerido para esta ruta
        $requiredRole = $param;

        // Si no coinciden → 403 Forbidden
        // 401 = "no sé quién eres" (no autenticado)
        // 403 = "sé quién eres pero no tienes permiso" (no autorizado)
        if ($userRole !== $requiredRole) {
            Response::error(
                403,
                'FORBIDDEN',
                "Esta acción requiere rol '$requiredRole'. Tu rol es '$userRole'."
            );
        }

        // Si el rol coincide, no hacemos nada.
        // El flujo continúa al controlador.
    }
}