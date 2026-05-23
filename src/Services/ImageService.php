<?php

/**
 * ============================================
 * IMAGE SERVICE — Subida y gestión de imágenes
 * ============================================
 *
 * ¿Cómo funciona la subida de archivos en PHP?
 *
 * Cuando el frontend envía un formulario con un archivo (un <input type="file">),
 * el navegador lo envía como "multipart/form-data" (no como JSON).
 *
 * PHP recibe el archivo en la variable global $_FILES. Ejemplo:
 *   $_FILES['foto'] = [
 *       'name'     => 'mi-foto.jpg',       ← nombre original del archivo
 *       'type'     => 'image/jpeg',         ← tipo MIME
 *       'tmp_name' => '/tmp/phpYzdqkD',     ← ruta temporal donde PHP lo guardó
 *       'error'    => 0,                    ← código de error (0 = sin error)
 *       'size'     => 245632,               ← tamaño en bytes
 *   ]
 *
 * El archivo está en /tmp/ y PHP lo BORRA automáticamente al terminar la petición.
 * Nuestro trabajo es MOVERLO a una ubicación permanente antes de que eso pase.
 *
 * SEGURIDAD — ¿Por qué validar las imágenes?
 * Un atacante podría subir un archivo .php disfrazado de imagen.
 * Si el servidor lo ejecuta, tiene acceso total. Por eso:
 *   1. Validamos la extensión (.jpg, .png, .webp)
 *   2. Validamos el tipo MIME real del archivo (no el que dice el navegador)
 *   3. Renombramos el archivo (nunca confiamos en el nombre original)
 *   4. Lo guardamos FUERA del directorio de la API
 *   5. Limitamos el tamaño (5MB máximo)
 *
 * Ubicación en el flujo:
 *   Controller recibe $_FILES → [IMAGE SERVICE] → mueve archivo → devuelve ruta
 */

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;

class ImageService
{
    /**
     * Directorio base donde se guardan las imágenes.
     * Está FUERA de la carpeta de la API (fuera de /puro/).
     * Apache sirve este directorio directamente como archivos estáticos.
     */
    private string $basePath;

    /**
     * URL base para acceder a las imágenes desde el navegador.
     * Ejemplo: 'https://puro.hiroshitsunoda.com/images'
     */
    private string $baseUrl;

    /**
     * Extensiones permitidas.
     * Solo imágenes reales. Nunca .php, .html, .svg (puede contener JS), etc.
     */
    private array $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Tipos MIME permitidos.
     * Verificamos el tipo REAL del archivo, no el que dice el navegador.
     * Un atacante puede decir que un .php es 'image/jpeg', pero finfo
     * lee los bytes reales del archivo y detecta el tipo verdadero.
     */
    private array $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Tamaño máximo en bytes. 5MB = 5 * 1024 * 1024 = 5242880
     */
    private int $maxSize = 5242880;

    public function __construct()
    {
        // La ruta al directorio de imágenes.
        // __DIR__ es /home/hiroshi/www/puro/src/Services/
        // Subimos 2 niveles para llegar a /home/hiroshi/www/puro/
        // y luego entramos en /images/
        $this->basePath = __DIR__ . '/../../images';
        $this->baseUrl = '/images';
    }

    /**
     * Subir una foto de perfil de profesional.
     *
     * @param array $file  El array de $_FILES['foto']
     * @param int   $profesionalId  ID del profesional
     *
     * @return string La ruta relativa de la imagen (para guardar en la DB).
     *                Ejemplo: 'profesionales/42/profile.jpg'
     *
     * @throws ValidationException Si el archivo no es válido
     */
    public function subirFotoPerfil(array $file, int $profesionalId): string
    {
        // ─── 1. Validar el archivo ───
        $this->validarArchivo($file);

        // ─── 2. Determinar la extensión ───
        // Usamos la extensión del nombre original pero SOLO después de validar.
        // pathinfo() extrae las partes de una ruta de archivo.
        // 'mi-foto.JPG' → extension: 'JPG' → strtolower: 'jpg'
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        // ─── 3. Crear el directorio del profesional ───
        // Ejemplo: /home/hiroshi/www/images/profesionales/42/
        $dirRelativo = 'profesionales/' . $profesionalId;
        $dirAbsoluto = $this->basePath . '/' . $dirRelativo;

        if (!is_dir($dirAbsoluto)) {
            // mkdir con recursive=true crea todos los niveles que falten.
            // 0755 = el dueño puede todo, grupo y otros pueden leer/ejecutar.
            mkdir($dirAbsoluto, 0755, true);
        }

        // ─── 4. Nombre del archivo ───
        // Siempre 'profile.jpg' (o .png, .webp).
        // Si el profesional sube una nueva foto, sobrescribe la anterior.
        // No usamos el nombre original del archivo — podría contener
        // caracteres especiales o ser malicioso.
        $nombreArchivo = 'profile.' . $extension;
        $rutaRelativa = $dirRelativo . '/' . $nombreArchivo;
        $rutaAbsoluta = $dirAbsoluto . '/' . $nombreArchivo;

        // ─── 5. Mover el archivo ───
        // move_uploaded_file() es la forma SEGURA de mover archivos subidos.
        // A diferencia de rename() o copy(), VERIFICA que el archivo
        // realmente fue subido por HTTP (no fue creado manualmente en /tmp/).
        // Esto previene un tipo de ataque donde se intenta mover archivos
        // arbitrarios del servidor.
        if (!move_uploaded_file($file['tmp_name'], $rutaAbsoluta)) {
            throw new ValidationException('Error al guardar la imagen. Verifica permisos del directorio.');
        }

        // ─── 6. Devolver la ruta relativa ───
        // Esto es lo que se guarda en la base de datos.
        // Para construir la URL completa, el frontend hace:
        //   'https://puro.hiroshitsunoda.com/images/' + rutaRelativa
        return $rutaRelativa;
    }

    /**
     * Obtener la URL pública de una imagen.
     *
     * @param string|null $rutaRelativa  Lo que está guardado en la DB.
     * @return string|null La URL completa, o null si no hay imagen.
     *
     * Ejemplo:
     *   getUrl('profesionales/42/profile.jpg')
     *   → '/images/profesionales/42/profile.jpg'
     */
    public function getUrl(?string $rutaRelativa): ?string
    {
        if (!$rutaRelativa) return null;
        return $this->baseUrl . '/' . $rutaRelativa;
    }

    /**
     * Eliminar una imagen del disco.
     *
     * @param string|null $rutaRelativa  Lo que está en la DB.
     */
    public function eliminar(?string $rutaRelativa): void
    {
        if (!$rutaRelativa) return;

        $rutaAbsoluta = $this->basePath . '/' . $rutaRelativa;

        if (file_exists($rutaAbsoluta)) {
            unlink($rutaAbsoluta);
        }
    }

    /**
     * Validar un archivo subido.
     *
     * Hace 4 comprobaciones de seguridad:
     *   1. ¿Hubo error en la subida?
     *   2. ¿El tamaño está dentro del límite?
     *   3. ¿La extensión está permitida?
     *   4. ¿El tipo MIME real es una imagen?
     *
     * @throws ValidationException Si alguna comprobación falla
     */
    private function validarArchivo(array $file): void
    {
        // ─── 1. Error de subida ───
        // PHP pone un código de error en $file['error'].
        // UPLOAD_ERR_OK (0) = todo bien.
        // Otros códigos comunes:
        //   UPLOAD_ERR_INI_SIZE (1) = excede upload_max_filesize de php.ini
        //   UPLOAD_ERR_FORM_SIZE (2) = excede MAX_FILE_SIZE del formulario
        //   UPLOAD_ERR_NO_FILE (4) = no se envió archivo
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE   => 'El archivo excede el tamaño máximo del servidor',
                UPLOAD_ERR_FORM_SIZE  => 'El archivo excede el tamaño máximo permitido',
                UPLOAD_ERR_PARTIAL    => 'El archivo se subió parcialmente',
                UPLOAD_ERR_NO_FILE    => 'No se envió ningún archivo',
                UPLOAD_ERR_NO_TMP_DIR => 'Falta el directorio temporal del servidor',
                UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo en disco',
            ];

            $msg = $errors[$file['error']] ?? 'Error desconocido al subir el archivo';
            throw new ValidationException($msg);
        }

        // ─── 2. Tamaño ───
        if ($file['size'] > $this->maxSize) {
            $maxMB = $this->maxSize / 1024 / 1024;
            throw new ValidationException("La imagen no puede superar {$maxMB}MB");
        }

        // ─── 3. Extensión ───
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $this->allowedExtensions, true)) {
            throw new ValidationException(
                'Formato no permitido. Usa: ' . implode(', ', $this->allowedExtensions)
            );
        }

        // ─── 4. Tipo MIME real ───
        // finfo lee los primeros bytes del archivo (los "magic bytes")
        // para determinar el tipo real. Es mucho más fiable que
        // $file['type'], que lo envía el navegador y se puede falsificar.
        //
        // Un .php renombrado a .jpg seguirá teniendo MIME 'text/x-php'.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        if (!in_array($mime, $this->allowedMimes, true)) {
            throw new ValidationException(
                "El archivo no es una imagen válida (tipo detectado: $mime)"
            );
        }
    }
}