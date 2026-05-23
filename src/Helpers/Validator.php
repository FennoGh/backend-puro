<?php

/**
 * ============================================
 * VALIDATOR — Validación y sanitización de datos
 * ============================================
 *
 * ¿Qué es validar?
 * Verificar que los datos que envía el cliente son correctos
 * ANTES de procesarlos. Ejemplo:
 *   - ¿El email tiene formato válido?
 *   - ¿La fecha es YYYY-MM-DD?
 *   - ¿Vinieron todos los campos obligatorios?
 *
 * ¿Qué es sanitizar?
 * Limpiar los datos para que sean seguros. Ejemplo:
 *   - Quitar etiquetas HTML (<script>alert('hack')</script> → "alert('hack')")
 *   - Quitar espacios al inicio y final
 *
 * ¿Por qué es un helper y no está en el Controller o el Service?
 * Porque TODOS los controllers y services necesitan validar datos.
 * Si cada uno tuviera su propia validación, estarías repitiendo
 * el mismo código en 10 sitios. Con el Validator, lo escribes
 * una vez y lo usas en todas partes.
 *
 * ¿Por qué los métodos son static?
 * Porque no necesitan guardar estado entre llamadas.
 * Validator::email('test@email.com') siempre hace lo mismo
 * sin importar qué pasó antes. No hay nada que "recordar".
 * Así lo usas directamente sin crear una instancia:
 *   Validator::email($valor)   ← más limpio que
 *   $v = new Validator(); $v->email($valor)
 *
 * Ubicación en el flujo:
 *   Controller recibe datos → [VALIDATOR] → si pasa, sigue al Service
 *                                         → si falla, lanza ValidationException
 */

declare(strict_types=1);

namespace App\Helpers;

use App\Exceptions\ValidationException;

class Validator
{
    /**
     * Verificar que todos los campos requeridos están presentes y no vacíos.
     *
     * Recibe un array asociativo donde:
     *   - la clave es el NOMBRE del campo (para el mensaje de error)
     *   - el valor es el VALOR del campo
     *
     * Ejemplo de uso:
     *   Validator::required([
     *       'email'    => $body['email'] ?? null,
     *       'password' => $body['password'] ?? null,
     *       'nombre'   => $body['nombre'] ?? null,
     *   ]);
     *
     * Si 'email' y 'nombre' están vacíos, lanza:
     *   ValidationException: "Campos obligatorios: email, nombre"
     *
     * ¿Por qué ?? null?
     * Si $body['email'] no existe en el array, PHP da un warning.
     * ?? null dice: "si no existe, usa null". Y null es "vacío"
     * para nuestra validación.
     *
     * @throws ValidationException Si algún campo falta o está vacío
     */
    public static function required(array $fields): void
    {
        $missing = [];

        foreach ($fields as $name => $value) {
            // null    → no se envió
            // ''      → se envió vacío
            // Ambos son inválidos para un campo requerido.
            if ($value === null || $value === '') {
                $missing[] = $name;
            }
        }

        if (!empty($missing)) {
            throw new ValidationException(
                'Campos obligatorios: ' . implode(', ', $missing)
            );
            // implode(', ', ['email', 'nombre']) → "email, nombre"
        }
    }

    /**
     * Validar formato de fecha YYYY-MM-DD.
     *
     * ¿Por qué no basta con comprobar el formato?
     * Porque "2026-02-30" tiene el formato correcto pero no existe
     * (febrero no tiene 30 días). DateTime::createFromFormat
     * aceptaría el formato pero crearía "2026-03-02" internamente.
     * Por eso comparamos el format() con el input: si no coinciden,
     * PHP "corrigió" la fecha → la original era inválida.
     *
     * Ejemplo:
     *   Validator::date('2026-03-23')  → ok
     *   Validator::date('2026-02-30')  → lanza excepción
     *   Validator::date('23-03-2026')  → lanza excepción (formato incorrecto)
     *   Validator::date('no-es-fecha') → lanza excepción
     *
     * @throws ValidationException Si el formato o la fecha son inválidos
     */
    public static function date(string $value): void
    {
        $d = \DateTime::createFromFormat('Y-m-d', $value);

        if (!$d || $d->format('Y-m-d') !== $value) {
            throw new ValidationException(
                "Fecha inválida: $value (formato esperado: YYYY-MM-DD)"
            );
        }
    }

    /**
     * Validar formato de hora HH:MM (24 horas).
     *
     * La regex:
     *   ([01]\d|2[0-3])  → horas: 00-19 o 20-23
     *   :                → literal
     *   [0-5]\d          → minutos: 00-59
     *
     * Ejemplo:
     *   Validator::time('16:00')  → ok
     *   Validator::time('23:59')  → ok
     *   Validator::time('25:00')  → lanza excepción
     *   Validator::time('4:00')   → lanza excepción (falta el 0 adelante)
     *
     * @throws ValidationException Si el formato es inválido
     */
    public static function time(string $value): void
    {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
            throw new ValidationException(
                "Hora inválida: $value (formato esperado: HH:MM)"
            );
        }
    }

    /**
     * Validar formato de email.
     *
     * filter_var con FILTER_VALIDATE_EMAIL es la forma estándar de PHP.
     * Comprueba que tenga @, dominio, etc. No es perfecto (algunos
     * emails raros pasan o fallan) pero es suficiente para un MVP.
     *
     * Para una validación 100% precisa, la única forma es enviar
     * un email de confirmación y que el usuario haga clic.
     *
     * @throws ValidationException Si el email no tiene formato válido
     */
    public static function email(string $value): void
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException("Email inválido: $value");
        }
    }

    /**
     * Validar que un valor está en una lista de valores permitidos.
     *
     * Útil para campos con opciones fijas como:
     *   - estado: ['retenido', 'liberado', 'devuelto']
     *   - role: ['usuario', 'profesional']
     *   - dia_semana: [1, 2, 3, 4, 5, 6, 7]
     *
     * Ejemplo:
     *   Validator::inList('retenido', ['retenido', 'liberado', 'devuelto'], 'estado');
     *   → ok
     *
     *   Validator::inList('cancelado', ['retenido', 'liberado', 'devuelto'], 'estado');
     *   → lanza: "estado debe ser uno de: retenido, liberado, devuelto"
     *
     * El tercer parámetro true en in_array() activa comparación ESTRICTA:
     * compara tipo Y valor. Sin él, PHP podría considerar 0 == 'retenido' como true.
     *
     * @throws ValidationException Si el valor no está en la lista
     */
    public static function inList(string $value, array $allowed, string $field): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new ValidationException(
                "$field debe ser uno de: " . implode(', ', $allowed)
            );
        }
    }

    /**
     * Validar longitud mínima de un string.
     *
     * Usado principalmente para contraseñas:
     *   Validator::minLength($password, 8, 'password');
     *
     * @throws ValidationException Si el string es más corto
     */
    public static function minLength(string $value, int $min, string $field): void
    {
        if (strlen($value) < $min) {
            throw new ValidationException(
                "$field debe tener al menos $min caracteres"
            );
        }
    }

    /**
     * Sanitizar un string: eliminar contenido peligroso.
     *
     * Hace tres cosas:
     *   1. trim()           → quita espacios al inicio y final
     *   2. strip_tags()     → quita etiquetas HTML/PHP
     *   3. htmlspecialchars() → convierte caracteres especiales a entidades HTML
     *
     * Ejemplo:
     *   sanitize('  <script>alert("hack")</script>  ')
     *   → 'alert(&quot;hack&quot;)'
     *
     * ¿Cuándo usar sanitize?
     * Siempre que guardes texto que el usuario escribió y que
     * podría mostrarse en una página web después.
     * Para una API JSON pura el riesgo es menor, pero es buena práctica.
     *
     * ¿Cuándo NO sanitizar?
     * Contraseñas — si sanitizas la contraseña, el hash cambia
     * y el usuario no puede hacer login. Las contraseñas se hashean,
     * nunca se muestran.
     *
     * @param string $value  El texto a limpiar
     * @return string El texto limpio
     */
    public static function sanitize(string $value): string
    {
        return htmlspecialchars(
            strip_tags(trim($value)),
            ENT_QUOTES,  // Convierte tanto ' como " a entidades
            'UTF-8'      // Encoding explícito para evitar problemas
        );
    }
}