<?php

/**
 * ============================================
 * RESPONSE — Helper para respuestas JSON
 * ============================================
 *
 * ¿Qué problema resuelve?
 * Sin este helper, cada vez que quieras responder al cliente tendrías
 * que escribir esto:
 *
 *   http_response_code(200);
 *   header('Content-Type: application/json');
 *   echo json_encode($data);
 *   exit;
 *
 * Son 4 líneas que repetirías en CADA método de CADA controlador.
 * Con este helper, solo escribes:
 *
 *   Response::json($data);
 *   Response::json($data, 201);       // para creación
 *   Response::json($error, 404);      // para errores
 *
 * Además centraliza el formato: si mañana quieres añadir un campo
 * "timestamp" a todas las respuestas, lo cambias AQUÍ y aplica
 * en toda la API.
 *
 * Ubicación en el flujo:
 *   controlador → [RESPONSE] → cliente
 *   middleware  → [RESPONSE] → cliente (si falla auth, por ejemplo)
 */

declare(strict_types=1);

namespace App\Helpers;

class Response
{
    /**
     * Enviar una respuesta JSON.
     *
     * @param mixed $data    Los datos a enviar. Puede ser un array, objeto, o null.
     * @param int   $status  Código HTTP (200, 201, 400, 404, 500, etc.)
     *
     * Códigos HTTP que usarás más:
     *   200 = OK (todo bien, aquí tienes los datos)
     *   201 = Created (se creó el recurso, aquí está)
     *   204 = No Content (todo bien, no hay nada que devolver)
     *   400 = Bad Request (la petición está mal formada)
     *   401 = Unauthorized (no te has identificado / token inválido)
     *   403 = Forbidden (te identificaste pero no tienes permiso)
     *   404 = Not Found (esa ruta o recurso no existe)
     *   409 = Conflict (conflicto: ej. slot ya reservado, email duplicado)
     *   422 = Unprocessable Entity (datos válidos pero no procesables: fecha pasada, etc.)
     *   429 = Too Many Requests (rate limit superado)
     *   500 = Internal Server Error (algo explotó en el servidor)
     *
     * Ejemplo de uso en un controlador:
     *   Response::json(['id' => 1, 'nombre' => 'Masaje'], 200);
     *
     * Lo que recibe el cliente:
     *   HTTP/1.1 200 OK
     *   Content-Type: application/json; charset=utf-8
     *   {"id":1,"nombre":"Masaje"}
     */
    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);

        if ($data !== null) {
            // JSON_UNESCAPED_UNICODE → que "García" se envíe como "García"
            //   y no como "Garc\u00eda". Importante para español.
            //
            // JSON_UNESCAPED_SLASHES → que las URLs se envíen como
            //   "https://ejemplo.com" y no como "https:\/\/ejemplo.com"
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // exit detiene la ejecución de PHP aquí.
        // ¿Por qué? Porque después de enviar la respuesta no queremos
        // que se ejecute más código (especialmente en middleware que
        // necesitan "cortar" la ejecución al detectar un error).
        exit;
    }

    /**
     * Respuesta paginada estándar.
     *
     * Todas las listas de tu API deberían usar este formato.
     * El cliente siempre sabe:
     *   - data: los resultados de esta página
     *   - meta.page: en qué página estoy
     *   - meta.limit: cuántos resultados por página
     *   - meta.total: cuántos hay en total (para calcular "página 3 de 7")
     *
     * Ejemplo de uso:
     *   $servicios = $repo->findAll($page, $limit);
     *   $total = $repo->countAll();
     *   Response::paginated($servicios, $total, $page, $limit);
     *
     * Lo que recibe el cliente:
     *   {
     *     "data": [{ "id": 1, "nombre": "Masaje" }, ...],
     *     "meta": { "page": 1, "limit": 20, "total": 58 }
     *   }
     */
    public static function paginated(array $data, int $total, int $page, int $limit): void
    {
        self::json([
            'data' => $data,
            'meta' => [
                'page'  => $page,
                'limit' => $limit,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Respuesta de error estándar.
     *
     * Centraliza el formato de errores para que TODOS los errores
     * de tu API tengan la misma estructura. El cliente siempre sabe
     * que si hay error, viene así:
     *   { "error": { "code": "NOT_FOUND", "message": "..." } }
     *
     * Ejemplo de uso:
     *   Response::error(404, 'NOT_FOUND', 'Servicio no encontrado');
     *   Response::error(409, 'SLOT_OCCUPIED', 'Ese horario ya está reservado');
     *   Response::error(422, 'VALIDATION_ERROR', 'El campo email es obligatorio');
     */
    public static function error(int $status, string $code, string $message): void
    {
        self::json([
            'error' => [
                'code'    => $code,
                'message' => $message,
            ],
        ], $status);
    }
}