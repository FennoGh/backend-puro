<?php

/**
 * ============================================
 * VALIDATION EXCEPTION
 * ============================================
 *
 * Se lanza cuando los datos del cliente no son válidos.
 *
 * Ejemplos de cuándo se lanza:
 *   - Falta un campo obligatorio (nombre, email, etc.)
 *   - El email tiene formato incorrecto
 *   - La fecha no es YYYY-MM-DD
 *   - La contraseña es muy corta
 *   - dia_semana no está entre 1 y 7
 *
 * Código HTTP asociado: 422 Unprocessable Entity
 *
 * ¿Por qué 422 y no 400?
 *   400 Bad Request = "tu petición está mal formada" (JSON roto, etc.)
 *   422 Unprocessable Entity = "el formato es correcto pero los datos no son válidos"
 *   Técnicamente podrías usar 400 para ambos, pero 422 es más preciso.
 *
 * ¿Por qué crear esta clase y no usar \RuntimeException?
 *
 *   Con \RuntimeException:
 *     throw new \RuntimeException('Email inválido');
 *     // En el Controller:
 *     catch (\RuntimeException $e) {
 *         // ¿Es validación? ¿Es un error de DB? ¿Es un slot ocupado?
 *         // No sé, todo es RuntimeException...
 *         if ($e->getMessage() === '...') { ... }  // Frágil
 *     }
 *
 *   Con ValidationException:
 *     throw new ValidationException('Email inválido');
 *     // En el Controller:
 *     catch (ValidationException $e) {
 *         // Sé que es un error de validación → 422
 *         Response::error(422, 'VALIDATION_ERROR', $e->getMessage());
 *     }
 *     catch (ConflictException $e) {
 *         // Sé que es un conflicto → 409
 *         Response::error(409, $e->getErrorCode(), $e->getMessage());
 *     }
 *
 * PHP puede capturar excepciones por TIPO. Eso es mucho más robusto
 * que comparar strings de mensajes.
 */

declare(strict_types=1);

namespace App\Exceptions;

class ValidationException extends \RuntimeException
{
    /**
     * @param string $message  Descripción del error de validación.
     *                         Se envía al cliente, así que debe ser útil.
     *                         Ejemplo: "Campos obligatorios: email, nombre"
     */
    public function __construct(string $message)
    {
        // parent::__construct llama al constructor de RuntimeException.
        // Le pasamos el mensaje y el código HTTP 422.
        // El código se guarda en $this->code (accesible con $e->getCode()).
        parent::__construct($message, 422);
    }
}