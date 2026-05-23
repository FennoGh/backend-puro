<?php
 
/**
 * ============================================
 * MIDDLEWARE INTERFACE
 * ============================================
 *
 * ¿Qué es una interface?
 * Es un "contrato" que dice: "toda clase que se llame middleware
 * DEBE tener un método handle()".
 *
 * Si creas un middleware nuevo y olvidas el método handle(),
 * PHP te da un error inmediato. Sin la interface, el error
 * aparecería mucho después (cuando el Router intente llamar
 * a handle() y no exista).
 *
 * Es como un formulario que dice "campos obligatorios":
 * no puedes enviarlo sin llenarlos.
 */
 
declare(strict_types=1);
 
namespace App\Middleware;
 
interface MiddlewareInterface
{
    /**
     * Ejecutar el middleware.
     *
     * @param string|null $param  Parámetro opcional (ej: 'profesional' en RoleMiddleware).
     *
     * Si el middleware necesita BLOQUEAR la petición (ej: token inválido),
     * debe llamar a Response::error() que hace exit internamente.
     *
     * Si todo está bien, simplemente no hace nada y el flujo continúa
     * al siguiente middleware o al controlador.
     */
    public function handle(?string $param = null): void;
}