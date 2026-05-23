<?php
 
/**
 * ============================================
 * CORS MIDDLEWARE
 * ============================================
 *
 * CORS = Cross-Origin Resource Sharing
 *
 * ¿Qué problema resuelve?
 * Tu frontend está en https://app.tudominio.com
 * Tu API está en https://api.tudominio.com
 *
 * Son dominios distintos. Por seguridad, el NAVEGADOR bloquea
 * las peticiones entre dominios diferentes. CORS le dice al
 * navegador: "tranquilo, este frontend tiene permiso".
 *
 * ¿Cuándo NO lo necesitas?
 * Si frontend y API están en el MISMO dominio exacto (raro en producción).
 *
 * ¿Qué pasa si no lo pones?
 * El navegador muestra un error tipo:
 *   "Access to fetch at 'https://api...' from origin 'https://app...'
 *    has been blocked by CORS policy"
 * Y tu frontend no recibe la respuesta.
 *
 * IMPORTANTE: CORS es una protección del NAVEGADOR, no del servidor.
 * Herramientas como Postman o curl ignoran CORS completamente.
 * Por eso al probar con Postman todo funciona pero desde el
 * navegador no — es confuso al principio.
 */
 
declare(strict_types=1);
 
namespace App\Middleware;
 
class CorsMiddleware implements MiddlewareInterface
{
    public function handle(?string $param = null): void
    {
        // ─── ¿Qué origenes tienen permiso? ───
        // En desarrollo: localhost en varios puertos (React, Vue, etc.)
        // En producción: SOLO tu dominio de frontend.
        //
        // NUNCA pongas '*' (todos) en producción si tu API maneja
        // datos privados. Cualquier web podría hacer peticiones.
        $allowedOrigins = array_map(
            'trim',
            explode(',', $_ENV['CORS_ORIGINS'] ?? 'http://localhost:3000')
        );
 
        // $_SERVER['HTTP_ORIGIN'] es el dominio desde donde viene la petición.
        // Solo existe si la petición viene de un navegador (no de Postman/curl).
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
 
        if (in_array($origin, $allowedOrigins, true)) {
            header("Access-Control-Allow-Origin: $origin");
        }
 
        // ─── Qué métodos HTTP se permiten ───
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
 
        // ─── Qué headers puede enviar el frontend ───
        // Content-Type: para enviar JSON
        // Authorization: para enviar el JWT
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
 
        // ─── Cachear la respuesta preflight ───
        // El navegador envía OPTIONS antes de POST/PUT/DELETE.
        // Con esto, cachea la respuesta 24h y no repite la petición OPTIONS.
        header('Access-Control-Max-Age: 86400');
    }
}
 