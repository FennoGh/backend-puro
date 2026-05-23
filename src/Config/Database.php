<?php

/**
 * ============================================
 * DATABASE — Conexión a MySQL con PDO
 * ============================================
 *
 * ¿Qué es PDO?
 * PHP Data Objects. Es la forma estándar de PHP para hablar con
 * bases de datos. Funciona con MySQL, PostgreSQL, SQLite, etc.
 *
 * ¿Por qué PDO y no mysqli?
 *   - PDO funciona con CUALQUIER base de datos (si mañana cambias a
 *     PostgreSQL, solo cambias el string de conexión aquí)
 *   - mysqli SOLO funciona con MySQL
 *   - PDO tiene una sintaxis más limpia para prepared statements
 *
 * ¿Qué es el patrón Singleton?
 * Garantiza que solo exista UNA conexión a la base de datos durante
 * toda la petición. Abrir una conexión es "caro" (toma tiempo y recursos).
 * No queremos abrir una nueva cada vez que hacemos una query.
 *
 *   Primera llamada:  Database::getConnection() → abre conexión, la guarda
 *   Segunda llamada:  Database::getConnection() → devuelve la que ya abrió
 *   Tercera llamada:  Database::getConnection() → devuelve la que ya abrió
 *   ... siempre la misma
 *
 * Ubicación en el flujo:
 *   controller → service → repository → [DATABASE] → MySQL
 */

declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;

class Database
{
    /**
     * La conexión guardada. null = todavía no se ha abierto.
     *
     * "static" significa que pertenece a la CLASE, no a una instancia.
     * Así puedes llamar Database::getConnection() sin hacer new Database().
     *
     * "?PDO" significa "puede ser PDO o null".
     */
    private static ?PDO $connection = null;

    /**
     * Obtener la conexión. Si no existe, la crea.
     *
     * Ejemplo de uso (en un Repository):
     *   $db = Database::getConnection();
     *   $stmt = $db->prepare("SELECT * FROM servicios WHERE id = :id");
     */
    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            try {
                // ─── DSN (Data Source Name) ───
                // Es el string que le dice a PDO: "conéctate a ESTA base de datos".
                //
                // Formato para MySQL:
                //   mysql:host=localhost;port=3306;dbname=plataforma;charset=utf8mb4
                //
                // charset=utf8mb4 es IMPORTANTE:
                //   - utf8 en MySQL solo soporta 3 bytes (no emojis)
                //   - utf8mb4 soporta 4 bytes (emojis, caracteres especiales)
                //   - Siempre usa utf8mb4
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $_ENV['DB_HOST'],
                    $_ENV['DB_PORT'],
                    $_ENV['DB_NAME']
                );

                self::$connection = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], [

                    // ─── ERRMODE_EXCEPTION ───
                    // Si una query falla, PDO lanza una excepción.
                    // Sin esto, PDO falla silenciosamente y tu código
                    // sigue ejecutándose con datos incorrectos/vacíos.
                    // SIEMPRE actívalo.
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

                    // ─── FETCH_ASSOC ───
                    // Cuando haces $stmt->fetch(), devuelve un array asociativo:
                    //   ['id' => 1, 'nombre' => 'Masaje']
                    // Sin esto, devuelve un array con índices numéricos Y nombres:
                    //   [0 => 1, 'id' => 1, 1 => 'Masaje', 'nombre' => 'Masaje']
                    //   (duplicado e incómodo)
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                    // ─── EMULATE_PREPARES = false ───
                    // Le dice a PDO: "usa prepared statements REALES del motor de MySQL,
                    // no los simules tú".
                    //
                    // ¿Por qué importa para SEGURIDAD?
                    // Con emulación (true), PDO escapa los valores en PHP y los mete
                    // en la query como texto. Es menos seguro.
                    // Sin emulación (false), los valores se envían SEPARADOS de la
                    // query a MySQL. Es imposible inyectar SQL.
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);

            } catch (PDOException $e) {
                // NUNCA mostrar detalles de conexión al usuario.
                // El mensaje de PDO puede contener: host, usuario, password, puerto.
                // Lo logueamos internamente y lanzamos un error genérico.
                error_log("Database connection failed: " . $e->getMessage());
                throw new \RuntimeException('Error de conexión a base de datos');
            }
        }

        return self::$connection;
    }

    /**
     * Cerrar la conexión (opcional, PHP la cierra automáticamente
     * al terminar la petición, pero es buena práctica).
     */
    public static function close(): void
    {
        self::$connection = null;
    }
}