<?php

/**
 * ============================================
 * ROUTER — El mapa de tu API
 * ============================================
 *
 * Responsabilidad: recibir una petición HTTP (método + URL) y encontrar
 * qué controlador debe ejecutarse.
 *
 * NO es responsable de: lógica de negocio, queries SQL, validación de datos.
 *
 * Ubicación en el flujo:
 *   index.php → [ROUTER] → middleware → controlador → servicio → repositorio
 */

declare(strict_types=1);

namespace App\Router;

class Router
{
    // ─────────────────────────────────────────
    // PROPIEDADES
    // ─────────────────────────────────────────

    /**
     * Aquí se almacenan TODAS las rutas registradas.
     *
     * Estructura:
     * [
     *     'GET' => [
     *         ['path' => 'servicios',      'handler' => [ServicioController::class, 'index'], 'middleware' => []],
     *         ['path' => 'servicios/:id',   'handler' => [ServicioController::class, 'show'],  'middleware' => []],
     *     ],
     *     'POST' => [
     *         ['path' => 'reservas',        'handler' => [ReservaController::class, 'store'],  'middleware' => [AuthMiddleware::class]],
     *     ],
     * ]
     *
     * ¿Por qué agrupar por método HTTP?
     * Porque al despachar, solo necesitamos buscar entre las rutas
     * del método que llegó (GET, POST, etc.), no entre TODAS.
     * Con 50 rutas registradas, buscas entre ~15 en vez de 50.
     */
    private array $routes = [];

    /**
     * Middleware que se ejecutan en TODAS las rutas.
     * Ejemplo: CORS y rate limiting aplican siempre.
     *
     * Se ejecutan ANTES que los middleware específicos de cada ruta.
     */
    private array $globalMiddleware = [];

    /**
     * Prefijo opcional para todas las rutas.
     * Si defines 'api/v1', la ruta 'servicios' se convierte en 'api/v1/servicios'.
     *
     * ¿Para qué? Para versionar tu API. Cuando hagas cambios grandes,
     * creas 'api/v2' sin romper la v1 que tus clientes ya usan.
     */
    private string $prefix = '';


    // ─────────────────────────────────────────
    // MÉTODOS PÚBLICOS: Registrar rutas
    // ─────────────────────────────────────────
    // Cada método corresponde a un verbo HTTP.
    // Son "atajos" que llaman a addRoute() internamente.

    /**
     * Registrar una ruta GET.
     *
     * GET = "quiero obtener/leer algo"
     * Ejemplo: $router->get('servicios', [ServicioController::class, 'index']);
     *
     * @param string $path       La URL sin prefijo. Ej: 'servicios/:id/slots'
     * @param array  $handler    [NombreClase::class, 'nombreMetodo']
     * @param array  $middleware  Lista de middleware que aplican SOLO a esta ruta
     */
    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    /**
     * Registrar una ruta POST.
     *
     * POST = "quiero crear algo nuevo"
     * Ejemplo: $router->post('reservas', [ReservaController::class, 'store']);
     */
    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    /**
     * Registrar una ruta PUT.
     *
     * PUT = "quiero actualizar algo existente (reemplazar completo)"
     * Ejemplo: $router->put('usuarios/me', [UsuarioController::class, 'update']);
     */
    public function put(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middleware);
    }

    /**
     * Registrar una ruta DELETE.
     *
     * DELETE = "quiero eliminar algo"
     * Ejemplo: $router->delete('profesionales/me/servicios/:id', [...]);
     */
    public function delete(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    /**
     * Añadir un middleware global.
     *
     * Se ejecuta en TODAS las rutas, antes que los middleware específicos.
     * Orden de ejecución:
     *   global[0] → global[1] → ruta[0] → ruta[1] → controlador
     *
     * Ejemplo:
     *   $router->addGlobalMiddleware(CorsMiddleware::class);
     *   $router->addGlobalMiddleware(RateLimitMiddleware::class);
     */
    public function addGlobalMiddleware(string $middlewareClass): void
    {
        $this->globalMiddleware[] = $middlewareClass;
    }

    /**
     * Establecer un prefijo para todas las rutas.
     *
     * Ejemplo:
     *   $router->setPrefix('api/v1');
     *   $router->get('servicios', ...);  // La URL real será: api/v1/servicios
     */
    public function setPrefix(string $prefix): void
    {
        $this->prefix = trim($prefix, '/');
    }


    // ─────────────────────────────────────────
    // MÉTODO PRINCIPAL: Despachar la petición
    // ─────────────────────────────────────────

    /**
     * Este es el método que index.php llama: $router->dispatch()
     *
     * Flujo:
     * 1. Leer qué método HTTP y qué URL llegaron
     * 2. Si es OPTIONS (preflight CORS), responder vacío
     * 3. Buscar una ruta que coincida con la URL
     * 4. Si la encuentra: ejecutar middleware → controlador
     * 5. Si no la encuentra: devolver 404
     */
    public function dispatch(): void
    {
        // ─── 1. Leer el método HTTP ───
        // $_SERVER['REQUEST_METHOD'] contiene: 'GET', 'POST', 'PUT', 'DELETE', etc.
        $method = $_SERVER['REQUEST_METHOD'];

        // ─── 2. Leer y limpiar la URI ───
        $uri = $this->getCleanUri();

        // ─── 3. Preflight CORS ───
        // Cuando el navegador va a hacer un POST/PUT/DELETE a otro dominio,
        // PRIMERO envía una petición OPTIONS preguntando "¿tengo permiso?".
        // Respondemos con 204 (sin contenido) y los headers CORS (que pone
        // el CorsMiddleware global).
        if ($method === 'OPTIONS') {
            // Ejecutar solo los middleware globales (donde está CORS)
            foreach ($this->globalMiddleware as $mw) {
                $this->runMiddleware($mw);
            }
            http_response_code(204);
            return;
        }

        // ─── 4. Verificar que existen rutas para este método ───
        if (!isset($this->routes[$method])) {
            $this->sendError(405, 'METHOD_NOT_ALLOWED', "Método $method no soportado");
            return;
        }

        // ─── 5. Buscar la ruta que coincida ───
        foreach ($this->routes[$method] as $route) {

            // Convertir el path de la ruta en una expresión regular.
            // 'servicios/:id/slots' → '#^servicios/([^/]+)/slots$#'
            $pattern = $this->pathToRegex($route['path']);

            // preg_match compara la URI contra el patrón.
            // Si coincide, $matches contiene los valores capturados.
            // Ejemplo: URI 'servicios/42/slots' con patrón 'servicios/([^/]+)/slots'
            //          → $matches = ['servicios/42/slots', '42']
            if (preg_match($pattern, $uri, $matches)) {

                // $matches[0] es la coincidencia completa (no la necesitamos).
                // El resto son los parámetros capturados (:id, :id_servicio, etc.)
                array_shift($matches);
                $params = array_values($matches);

                // ─── 6. Ejecutar middleware ───
                // Primero los globales (CORS, rate limit),
                // luego los específicos de la ruta (auth, role).
                $allMiddleware = array_merge($this->globalMiddleware, $route['middleware']);

                foreach ($allMiddleware as $mw) {
                    // Si un middleware falla (ej: token inválido),
                    // él mismo envía la respuesta de error y hace exit.
                    // Por eso no necesitamos verificar un valor de retorno.
                    $this->runMiddleware($mw);
                }

                // ─── 7. Ejecutar el controlador ───
                // $route['handler'] es algo como [ServicioController::class, 'slots']
                // Lo desglosamos en clase y método.
                [$controllerClass, $methodName] = $route['handler'];

                // Crear una instancia del controlador
                $controller = new $controllerClass();

                // Llamar al método pasándole los parámetros capturados de la URL.
                // call_user_func_array permite pasar un array como argumentos.
                //
                // Ejemplo:
                //   URL: servicios/42/slots
                //   $params = ['42']
                //   Equivale a: $controller->slots('42')
                call_user_func_array([$controller, $methodName], $params);

                return; // ¡Listo! La ruta se procesó.
            }
        }

        // ─── 8. Ninguna ruta coincidió ───
        $this->sendError(404, 'NOT_FOUND', "Ruta no encontrada: $method /$uri");
    }


    // ─────────────────────────────────────────
    // MÉTODOS PRIVADOS (internos)
    // ─────────────────────────────────────────

    /**
     * Registrar una ruta en el array interno.
     * Es privado porque los métodos públicos (get, post, put, delete) lo llaman.
     */
    private function addRoute(string $method, string $path, array $handler, array $middleware): void
    {
        // Añadir el prefijo si existe
        $fullPath = $this->prefix
            ? $this->prefix . '/' . trim($path, '/')
            : trim($path, '/');

        $this->routes[$method][] = [
            'path'       => $fullPath,
            'handler'    => $handler,
            'middleware'  => $middleware,
        ];
    }

    /**
     * Limpiar la URI que llega del navegador.
     *
     * Ejemplo de lo que llega en $_SERVER['REQUEST_URI']:
     *   '/api/v1/servicios?page=2&limit=10'
     *
     * Lo que necesitamos:
     *   'api/v1/servicios'  (sin query string, sin barras al inicio/final)
     *
     * parse_url(..., PHP_URL_PATH) extrae solo la parte del path (sin ?query).
     * trim(..., '/') quita las barras del inicio y final.
     */
    private function getCleanUri(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        return trim($uri, '/');
    }

    /**
     * Convertir un path con parámetros nombrados en una expresión regular.
     *
     * ¿Qué es una expresión regular (regex)?
     * Es un patrón para buscar coincidencias en texto.
     * '#^servicios/([^/]+)/slots$#' significa:
     *   ^            → "empieza con"
     *   servicios/   → literal
     *   ([^/]+)      → "uno o más caracteres que NO sean /" (captura el :id)
     *   /slots       → literal
     *   $            → "termina aquí"
     *   # ... #      → delimitadores de la regex
     *
     * Ejemplos:
     *   'profesionales'           → '#^profesionales$#'
     *   'servicios/:id'           → '#^servicios/([^/]+)$#'
     *   'servicios/:id/slots'     → '#^servicios/([^/]+)/slots$#'
     *   'profesionales/me/servicios/:id/disponibilidad'
     *       → '#^profesionales/me/servicios/([^/]+)/disponibilidad$#'
     *
     * preg_replace busca todos los :parametro y los reemplaza por ([^/]+)
     */
    private function pathToRegex(string $path): string
    {
        // Buscar :cualquier_nombre y reemplazar por grupo de captura
        $pattern = preg_replace('#:([a-zA-Z_]+)#', '([^/]+)', $path);

        // Envolver con delimitadores y anclas
        return '#^' . $pattern . '$#';
    }

    /**
     * Ejecutar un middleware.
     *
     * Los middleware pueden tener parámetros después de ':'.
     * Ejemplo: 'App\Middleware\RoleMiddleware:profesional'
     *   → clase: App\Middleware\RoleMiddleware
     *   → parámetro: 'profesional'
     *
     * Si el middleware necesita detener la ejecución (ej: token inválido),
     * él mismo envía la respuesta HTTP y llama a exit().
     * No hay "return false" ni nada parecido — simplemente el código
     * después del middleware nunca se ejecuta.
     */
    private function runMiddleware(string $middleware): void
    {
        // Separar clase y parámetro (si existe)
        // 'App\Middleware\RoleMiddleware:profesional' → ['App\...RoleMiddleware', 'profesional']
        // 'App\Middleware\CorsMiddleware'             → ['App\...CorsMiddleware']
        $parts = explode(':', $middleware, 2);
        $class = $parts[0];
        $param = $parts[1] ?? null;

        // Crear instancia y ejecutar
        $instance = new $class();
        $instance->handle($param);
    }

    /**
     * Enviar una respuesta de error estándar.
     * Método de conveniencia para no repetir código.
     */
    private function sendError(int $status, string $code, string $message): void
    {
        http_response_code($status);
        echo json_encode([
            'error' => [
                'code'    => $code,
                'message' => $message,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }
}