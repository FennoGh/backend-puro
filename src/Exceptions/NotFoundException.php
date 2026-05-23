<?php

/**
 * ============================================
 * NOT FOUND EXCEPTION
 * ============================================
 *
 * Se lanza cuando un recurso no existe en la base de datos.
 *
 * Ejemplos:
 *   - GET /servicios/999 → servicio con ID 999 no existe
 *   - POST /reservas con id_servicio que no existe
 *   - GET /reservas/50 → reserva con ID 50 no existe
 *
 * Código HTTP asociado: 404 Not Found
 *
 * ¿Por qué no devolver null y manejar en el Controller?
 * Podrías hacerlo (y en los repositories sí devolvemos null).
 * Pero en los Services, lanzar una excepción es más claro:
 *
 *   // En el Service:
 *   $servicio = $this->repo->findById($id);
 *   if (!$servicio) {
 *       throw new NotFoundException("Servicio con id $id no encontrado");
 *   }
 *   // El código después de esto SIEMPRE tiene $servicio válido.
 *   // No hay que verificar null en cada línea.
 *
 * Esto se llama "fail fast" (fallar rápido): si algo va mal,
 * lo detectas inmediatamente y no sigues ejecutando código
 * que asume que todo está bien.
 */

declare(strict_types=1);

namespace App\Exceptions;

class NotFoundException extends \RuntimeException
{
    /**
     * @param string $message  Descripción de qué no se encontró.
     *                         Se envía al cliente.
     *                         Ejemplo: "Servicio con id 42 no encontrado"
     */
    public function __construct(string $message = 'Recurso no encontrado')
    {
        // El valor por defecto 'Recurso no encontrado' se usa si no
        // pasas un mensaje específico. Así puedes hacer:
        //   throw new NotFoundException();                          → mensaje genérico
        //   throw new NotFoundException('Servicio no encontrado');  → mensaje específico
        parent::__construct($message, 404);
    }
}