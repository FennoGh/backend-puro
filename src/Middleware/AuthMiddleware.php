<?php

/**
 * ============================================
 * AUTH MIDDLEWARE — Verificación real de JWT
 * ============================================
 *
 * Flujo:
 *   1. Lee el header "Authorization: Bearer eyJ..."
 *   2. Decodifica el JWT y verifica la firma con JWT_SECRET
 *   3. Verifica que no haya expirado
 *   4. Pone los datos del usuario en $_REQUEST para el controlador
 *
 * Si algo falla → 401 Unauthorized y el controlador nunca se ejecuta.
 */

declare(strict_types=1);

namespace App\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use App\Helpers\Response;

class AuthMiddleware implements MiddlewareInterface
{
    public function handle(?string $param = null): void
    {
        // ─── 1. Leer el header Authorization ───
        // El cliente lo envía así:
        //   Authorization: Bearer eyJhbGciOiJIUzI1NiJ9...
        //
        // Algunos servidores (especialmente hosting compartido) no pasan
        // el header Authorization a PHP. Si tienes ese problema, añade
        // esto a tu .htaccess:
        //   SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!str_starts_with($header, 'Bearer ')) {
            Response::error(
                401,
                'UNAUTHORIZED',
                'Token requerido. Envía el header: Authorization: Bearer <tu_token>'
            );
        }

        // Extraer el token (quitar "Bearer ")
        $token = substr($header, 7);

        if (empty(trim($token))) {
            Response::error(401, 'UNAUTHORIZED', 'Token vacío');
        }

        // ─── 2. Decodificar y verificar el JWT ───
        try {
            // JWT::decode hace tres cosas:
            //   a) Decodifica el payload (base64)
            //   b) Verifica la firma con tu JWT_SECRET
            //   c) Verifica que no haya expirado (campo 'exp')
            //
            // Si alguien manipuló el token (cambió el user_id, por ejemplo),
            // la firma no coincide y lanza una excepción.
            //
            // Key(secret, algoritmo) le dice qué clave y algoritmo usar
            // para verificar la firma.
            $decoded = JWT::decode(
                $token,
                new Key($_ENV['JWT_SECRET'], 'HS256')
            );

            // ─── 3. Poner los datos en $_REQUEST ───
            // $decoded es un objeto stdClass con los campos del payload:
            //   $decoded->sub  = ID del usuario
            //   $decoded->role = 'usuario' o 'profesional'
            //   $decoded->iat  = timestamp de creación
            //   $decoded->exp  = timestamp de expiración
            $_REQUEST['auth_user_id']   = (int) $decoded->sub;
            $_REQUEST['auth_user_role'] = $decoded->role;

        } catch (ExpiredException $e) {
            // El token era válido pero expiró
            Response::error(401, 'TOKEN_EXPIRED', 'Tu sesión ha expirado. Inicia sesión de nuevo.');

        } catch (\Exception $e) {
            // Firma inválida, formato incorrecto, etc.
            Response::error(401, 'INVALID_TOKEN', 'Token inválido');
        }
    }
}