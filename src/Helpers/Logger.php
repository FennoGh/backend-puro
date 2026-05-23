<?php

/**
 * ============================================
 * LOGGER — Registro de eventos y errores
 * ============================================
 *
 * ¿Para qué sirve un logger?
 * Para saber QUÉ PASÓ en tu servidor cuando algo falla.
 *
 * Imagina este escenario:
 *   Un usuario dice "no puedo reservar, me da error".
 *   Tú miras la API y dice "Error interno del servidor".
 *   Sin logger: no tienes idea de qué pasó. Pides al usuario que repita.
 *   Con logger: abres el log y ves exactamente qué excepción ocurrió, en qué
 *               archivo, en qué línea, y con qué datos.
 *
 * Niveles de log (de menor a mayor gravedad):
 *
 *   info     → "El usuario 5 hizo login"
 *               Eventos normales. Útil para auditoría.
 *
 *   warning  → "Rate limit: IP 192.168.1.1 tiene 55/60 peticiones"
 *               Algo inusual pero no un error.
 *
 *   error    → "Query SQL falló: Column 'email' cannot be null"
 *               Algo salió mal. Necesita atención.
 *
 * ¿Por qué no usar simplemente echo o var_dump?
 *   1. echo va al navegador del usuario (inseguro, revela info interna)
 *   2. var_dump rompe el JSON de respuesta
 *   3. El logger escribe en un ARCHIVO que solo tú puedes ver
 *
 * ¿Dónde se guardan los logs?
 * En storage/logs/. Este directorio NO está expuesto al web
 * (está fuera de public/). Solo accesible por SSH.
 *
 * En producción, podrías reemplazar esto por un servicio como
 * Sentry, Datadog, o CloudWatch que te envían alertas.
 *
 * Ubicación: se usa desde CUALQUIER parte del código.
 *   Logger::error('Algo falló', ['user_id' => 5]);
 */

declare(strict_types=1);

namespace App\Helpers;

class Logger
{
    /**
     * Registrar un error.
     *
     * Ejemplo de uso:
     *   Logger::error('Query SQL falló', [
     *       'query' => 'INSERT INTO pagos ...',
     *       'error' => $e->getMessage(),
     *   ]);
     *
     * Lo que escribe en el archivo:
     *   [2026-03-19 08:15:30] ERROR: Query SQL falló {"query":"INSERT INTO...","error":"..."}
     *
     * @param string $message  Descripción breve del error
     * @param array  $context  Datos adicionales para debuggear.
     *                         NUNCA incluyas contraseñas o tokens aquí.
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context, 'error.log');
    }

    /**
     * Registrar un evento informativo.
     *
     * Ejemplo:
     *   Logger::info('Usuario registrado', ['user_id' => 5, 'email' => 'test@test.com']);
     *   Logger::info('Reserva creada', ['pago_id' => 12, 'servicio' => 'Masaje']);
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context, 'app.log');
    }

    /**
     * Registrar una advertencia.
     *
     * Ejemplo:
     *   Logger::warning('Rate limit casi alcanzado', ['ip' => '192.168.1.1', 'count' => 55]);
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context, 'app.log');
    }

    /**
     * Método interno que escribe en el archivo de log.
     *
     * Formato de cada línea:
     *   [FECHA HORA] NIVEL: Mensaje {contexto JSON}
     *
     * @param string $level    Nivel: ERROR, INFO, WARNING
     * @param string $message  El mensaje
     * @param array  $context  Datos extra (se convierten a JSON)
     * @param string $file     Nombre del archivo de log
     */
    private static function write(string $level, string $message, array $context, string $file): void
    {
        // ─── Asegurar que el directorio existe ───
        // La primera vez que se escribe un log, el directorio
        // podría no existir. mkdir con recursive=true lo crea.
        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
            // 0755 = owner puede leer/escribir/ejecutar,
            //         grupo y otros pueden leer/ejecutar.
        }

        $logFile = $logDir . '/' . $file;

        // ─── Formatear la línea ───
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';

        $line = "[$timestamp] $level: $message$contextStr" . PHP_EOL;
        // PHP_EOL es el salto de línea del sistema operativo.
        // En Linux es \n, en Windows es \r\n.

        // ─── Escribir en el archivo ───
        // FILE_APPEND → añadir al final (no sobrescribir)
        // LOCK_EX → bloquear el archivo mientras se escribe.
        //           Sin esto, dos peticiones simultáneas podrían
        //           escribir a la vez y corromper el archivo.
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}