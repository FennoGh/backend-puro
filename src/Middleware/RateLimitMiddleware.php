<?php

/**
 * ============================================
 * RATE LIMIT MIDDLEWARE
 * ============================================
 *
 * ¿Qué es rate limiting?
 * Es poner un tope de peticiones por cliente en un periodo de tiempo.
 * Ejemplo: máximo 60 peticiones por minuto por IP.
 *
 * ¿Por qué lo necesitas?
 * Sin esto, alguien puede:
 *   - Hacer 100.000 peticiones/segundo y tumbar tu servidor (DoS)
 *   - Probar miles de contraseñas por fuerza bruta
 *   - Scrapearte toda la base de datos
 *
 * ¿Cómo funciona?
 * 1. Identificamos al cliente por su IP
 * 2. Contamos cuántas peticiones ha hecho en la ventana actual (60 seg)
 * 3. Si supera el máximo → 429 Too Many Requests
 * 4. Si no → dejamos pasar y sumamos 1 al contador
 *
 * Esta versión usa archivos en storage/cache/.
 * En producción usarías Redis (mucho más rápido y no llena el disco).
 *
 * Los headers X-RateLimit-* informan al cliente:
 *   X-RateLimit-Limit: 60       → "puedes hacer 60 por minuto"
 *   X-RateLimit-Remaining: 45   → "te quedan 45"
 *   X-RateLimit-Reset: 17110... → "el contador se resetea en este timestamp"
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;

class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * Máximo de peticiones permitidas por ventana.
     * 60 por minuto es un valor razonable para un MVP.
     * APIs públicas grandes suelen poner 1000/min o más.
     */
    private int $maxRequests = 60;

    /** Duración de la ventana en segundos. */
    private int $windowSeconds = 60;

    public function handle(?string $param = null): void
    {
        // ─── 1. Identificar al cliente ───
        // REMOTE_ADDR es la IP del cliente.
        // En producción detrás de un proxy/load balancer, necesitarías
        // leer HTTP_X_FORWARDED_FOR en su lugar (con cuidado, es falsificable).
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // md5 convierte la IP en un string fijo de 32 chars, seguro para nombre de archivo.
        $key = md5($ip);

        // Directorio donde guardamos los contadores.
        // Asegúrate de que storage/cache/ existe y tiene permisos de escritura.
        $cacheDir = __DIR__ . '/../../storage/cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $file = $cacheDir . '/rate_' . $key . '.json';

        // ─── 2. Leer el estado actual ───
        $now = time();
        $data = ['count' => 0, 'reset_at' => $now + $this->windowSeconds];

        if (file_exists($file)) {
            $content = file_get_contents($file);
            $data = json_decode($content, true) ?: $data;

            // ¿Se acabó la ventana? Resetear el contador.
            if ($now >= $data['reset_at']) {
                $data = ['count' => 0, 'reset_at' => $now + $this->windowSeconds];
            }
        }

        // ─── 3. Incrementar contador ───
        $data['count']++;

        // ─── 4. Informar al cliente con headers estándar ───
        $remaining = max(0, $this->maxRequests - $data['count']);
        header("X-RateLimit-Limit: {$this->maxRequests}");
        header("X-RateLimit-Remaining: {$remaining}");
        header("X-RateLimit-Reset: {$data['reset_at']}");

        // ─── 5. ¿Superó el límite? ───
        if ($data['count'] > $this->maxRequests) {
            // Guardar el estado (para que el contador siga subiendo)
            file_put_contents($file, json_encode($data), LOCK_EX);

            // LOCK_EX bloquea el archivo mientras se escribe.
            // Sin esto, dos peticiones simultáneas podrían corromper el archivo.

            Response::error(429, 'RATE_LIMIT', 'Demasiadas peticiones. Intenta de nuevo en unos segundos.');
            // Response::error() llama a exit internamente.
            // El flujo se corta aquí, nunca llega al controlador.
        }

        // ─── 6. Todo bien, guardar y dejar pasar ───
        file_put_contents($file, json_encode($data), LOCK_EX);

        // No hacemos return ni exit.
        // El flujo continúa al siguiente middleware o al controlador.
    }
}