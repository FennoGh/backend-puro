<?php

/**
 * ============================================
 * UNAUTHORIZED EXCEPTION
 * ============================================
 *
 * Se lanza cuando el usuario no está autenticado o sus credenciales
 * son inválidas.
 *
 * Ejemplos:
 *   - Login con contraseña incorrecta
 *   - Token JWT expirado
 *   - Token JWT con firma inválida
 *   - Petición sin token a ruta protegida
 *
 * Código HTTP asociado: 401 Unauthorized
 *
 * ¿Cuál es la diferencia entre 401 y 403?
 *
 *   401 Unauthorized = "No sé quién eres"
 *     → No te identificaste, o tu identificación es inválida.
 *     → Solución: hacer login de nuevo.
 *
 *   403 Forbidden = "Sé quién eres, pero no tienes permiso"
 *     → Te identificaste correctamente, pero tu rol no tiene acceso.
 *     → Solución: no hay (necesitas otro rol o permisos).
 *
 * Ejemplo real:
 *   Pedro es profesional. Si intenta POST /reservas (que requiere rol usuario):
 *     → NO es 401 (Pedro sí está autenticado, tiene un JWT válido)
 *     → SÍ es 403 (Pedro es profesional, pero la ruta requiere usuario)
 *
 *   Si alguien hace POST /reservas sin token:
 *     → SÍ es 401 (no sabemos quién es)
 */

declare(strict_types=1);

namespace App\Exceptions;

class UnauthorizedException extends \RuntimeException
{
    /**
     * @param string $message  Descripción del problema de autenticación.
     *                         IMPORTANTE: Por seguridad, el mensaje debe ser
     *                         GENÉRICO en el login. No decir "email no encontrado"
     *                         ni "contraseña incorrecta" por separado, porque
     *                         eso revela si el email existe (enumeración de usuarios).
     *                         Siempre: "Credenciales inválidas".
     */
    public function __construct(string $message = 'No autorizado')
    {
        parent::__construct($message, 401);
    }
}