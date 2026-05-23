<?php
 
/**
 * ============================================
 * INDEX.PHP — Punto de entrada único (Front Controller)
 * ============================================
 *
 * Este archivo es el ÚNICO que Apache ejecuta directamente.
 * Todas las URLs llegan aquí gracias al .htaccess.
 *
 * Su trabajo: preparar el entorno y delegar al Router.
 * NO debe contener lógica de negocio, queries SQL, ni HTML.
 */
 
// ─────────────────────────────────────────────
// PASO 1: Modo estricto de tipos
// ─────────────────────────────────────────────
// Esto le dice a PHP: "sé estricto con los tipos de datos".
//
// Sin esto:
//   function sumar(int $a, int $b) { return $a + $b; }
//   sumar("5", "3");  ← PHP lo acepta y convierte silenciosamente
//
// Con esto:
//   sumar("5", "3");  ← PHP lanza un TypeError
//
// ¿Por qué importa? Porque las conversiones silenciosas causan bugs
// muy difíciles de encontrar. Mejor que PHP te avise inmediatamente.
 
declare(strict_types=1);
 
 
// ─────────────────────────────────────────────
// PASO 2: Cargar el autoloader de Composer
// ─────────────────────────────────────────────
// ¿Qué es esto? Composer genera un archivo que sabe cargar cualquier
// clase automáticamente. Sin esto tendrías que escribir:
//
//   require 'src/Router/Router.php';
//   require 'src/Helpers/Response.php';
//   require 'src/Config/Database.php';
//   ... (para CADA archivo)
//
// Con el autoloader, solo haces:
//   use App\Router\Router;
//   $router = new Router();    ← Composer carga el archivo solo
//
// __DIR__ es la carpeta donde está ESTE archivo (public/).
// Subimos un nivel (..) para llegar a la raíz del proyecto donde
// está la carpeta vendor/.
 
require_once __DIR__ . '/../vendor/autoload.php';
 
 
// ─────────────────────────────────────────────
// PASO 3: Cargar variables de entorno
// ─────────────────────────────────────────────
// El archivo .env contiene datos sensibles:
//   DB_HOST=localhost
//   DB_PASS=mi_password
//   JWT_SECRET=abc123...
//
// ¿Por qué no poner esto directamente en el código?
// Porque el código se sube a Git y cualquiera lo vería.
// El .env se queda SOLO en el servidor y en tu máquina local.
//
// Después de esto, accedes a los valores con $_ENV['DB_HOST'].
 
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
 
// Validar que las variables críticas existen.
// Si alguien olvida configurar el .env, mejor que falle AQUÍ
// con un mensaje claro, y no después con un error críptico.
$dotenv->required([
    'APP_ENV',
    'DB_HOST',
    'DB_PORT',
    'DB_NAME',
    'DB_USER',
    'DB_PASS',
    'JWT_SECRET',
]);
 
 
// ─────────────────────────────────────────────
// PASO 4: Configurar manejo de errores
// ─────────────────────────────────────────────
// En DESARROLLO quieres ver los errores en pantalla para debuggear.
// En PRODUCCIÓN nunca, porque:
//   - Revelan rutas del servidor (/var/www/...)
//   - Revelan nombres de tablas y columnas
//   - Revelan versiones de PHP y librerías
//   - Un atacante usa esa info para encontrar vulnerabilidades
 
if ($_ENV['APP_ENV'] === 'production') {
    ini_set('display_errors', '0');       // NO mostrar al usuario
    ini_set('log_errors', '1');           // SÍ guardar en archivo
    ini_set('error_log', __DIR__ . '/../storage/logs/php_errors.log');
} else {
    ini_set('display_errors', '1');       // Mostrar para debuggear
}
 
// Reportar TODOS los errores (incluidos notices y warnings).
// Muchos tutoriales ponen E_ALL & ~E_NOTICE, pero eso esconde
// warnings que a menudo son bugs reales.
error_reporting(E_ALL);
 
 
// ─────────────────────────────────────────────
// PASO 5: Headers por defecto
// ─────────────────────────────────────────────
// Estos headers se envían en TODAS las respuestas.
 
// Le dice al navegador/cliente: "mi respuesta es JSON en UTF-8"
header('Content-Type: application/json; charset=utf-8');
 
// ─── Headers de seguridad ───
 
// Evita que el navegador "adivine" el tipo de archivo.
// Sin esto, un archivo .txt con contenido HTML podría ejecutarse como HTML.
header('X-Content-Type-Options: nosniff');
 
// Evita que tu API se cargue dentro de un <iframe> de otra web.
// Esto previene ataques de "clickjacking".
header('X-Frame-Options: DENY');
 
// Fuerza HTTPS durante 1 año. Si alguien intenta http://, el navegador
// lo redirige automáticamente a https://. Solo actívalo si ya tienes
// certificado SSL configurado.
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
 
 
// ─────────────────────────────────────────────
// PASO 6: Arrancar la aplicación
// ─────────────────────────────────────────────
// Todo dentro de try/catch para que NINGÚN error llegue al usuario
// como un feo stack trace de PHP.
 
try {
 
    // Crear el router
    $router = new App\Router\Router();
 
    // Registrar todas las rutas (están en un archivo aparte para
    // mantener index.php limpio)
    require_once __DIR__ . '/../src/Routes/api.php';
 
    // Despachar: el router lee la URL actual, busca qué controlador
    // le corresponde, ejecuta los middleware, y llama al controlador.
    $router->dispatch();
 
} catch (\Throwable $e) {
    // ─────────────────────────────────────────
    // PASO 7: Manejar errores inesperados
    // ─────────────────────────────────────────
    // \Throwable captura TODO: Exceptions Y Errors (como TypeError,
    // ParseError, etc.). Es más amplio que \Exception.
    //
    // Aquí llegan solo los errores que NO fueron capturados por
    // los controladores o servicios. Son errores "de verdad" (bugs).
 
    // Registrar el error completo en el log (para que tú lo veas)
    error_log(sprintf(
        "[%s] UNCAUGHT %s: %s in %s:%d\nStack trace:\n%s",
        date('Y-m-d H:i:s'),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));
 
    // Devolver al usuario un error genérico (sin detalles internos)
    http_response_code(500);
 
    $response = [
        'error' => [
            'code'    => 'INTERNAL_ERROR',
            'message' => 'Ha ocurrido un error interno del servidor.',
        ],
    ];
 
    // En desarrollo, añadir detalles para ayudar a debuggear
    if (($_ENV['APP_ENV'] ?? 'production') !== 'production') {
        $response['error']['debug'] = [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ];
    }
 
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}