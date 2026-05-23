<?php

/**
 * ============================================
 * CONFLICT EXCEPTION
 * ============================================
 *
 * Se lanza cuando la operación no se puede completar porque
 * entra en CONFLICTO con el estado actual de los datos.
 *
 * Ejemplos:
 *   - Reservar un slot que ya está ocupado → SLOT_OCCUPIED
 *   - Registrarse con un email que ya existe → DUPLICATE_EMAIL
 *   - Crear disponibilidad que se solapa con otra → OVERLAP
 *
 * Código HTTP asociado: 409 Conflict
 *
 * ¿Por qué 409 y no 400 o 422?
 *   400 = "tu petición está mal formada"
 *   422 = "tus datos no son válidos"
 *   409 = "tus datos son válidos, pero CHOCAN con algo que ya existe"
 *
 * La diferencia es sutil pero útil para el frontend:
 *   - 422 → mostrar "corrige estos campos"
 *   - 409 → mostrar "ese horario ya no está disponible, elige otro"
 *
 * Esta excepción tiene un campo extra: errorCode.
 * Mientras que el message es para humanos, el errorCode es para máquinas:
 *
 *   throw new ConflictException('SLOT_OCCUPIED', 'Ese horario ya está reservado');
 *
 *   $e->getErrorCode() → 'SLOT_OCCUPIED'  (el frontend usa esto para decidir qué hacer)
 *   $e->getMessage()   → 'Ese horario...' (el frontend muestra esto al usuario)
 */

declare(strict_types=1);

namespace App\Exceptions;

class ConflictException extends \RuntimeException
{
    /**
     * Código de error legible por máquina.
     *
     * Ejemplos: 'SLOT_OCCUPIED', 'DUPLICATE_EMAIL', 'OVERLAP'
     *
     * El frontend puede usar esto para tomar decisiones:
     *   if (error.code === 'SLOT_OCCUPIED') {
     *       // Recargar los slots disponibles
     *       reloadSlots();
     *   }
     */
    private string $errorCode;

    /**
     * @param string $errorCode  Código para máquinas (ej: 'SLOT_OCCUPIED')
     * @param string $message    Mensaje para humanos (ej: 'Ese horario ya está reservado')
     */
    public function __construct(string $errorCode, string $message)
    {
        $this->errorCode = $errorCode;
        parent::__construct($message, 409);
    }

    /**
     * Obtener el código de error.
     *
     * Uso en el Controller:
     *   catch (ConflictException $e) {
     *       Response::error(409, $e->getErrorCode(), $e->getMessage());
     *   }
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}