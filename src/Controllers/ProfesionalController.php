<?php

/**
 * ============================================
 * PROFESIONAL CONTROLLER — Refactorizado
 * ============================================
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Helpers\Validator;
use App\Services\AuthService;
use App\Services\ImageService;
use App\Repositories\ProfesionalRepository;
use App\Exceptions\ValidationException;
use App\Exceptions\ConflictException;

class ProfesionalController
{
    private AuthService $authService;
    private ProfesionalRepository $repo;
    private ImageService $imageService;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->repo = new ProfesionalRepository();
        $this->imageService = new ImageService();
    }

    /** GET /api/v1/profesionales (público) */
    public function index(): void
    {
        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));

        $filters = [];
        if (!empty($_GET['ciudad'])) $filters['ciudad'] = $_GET['ciudad'];
        if (!empty($_GET['pais']))   $filters['pais']   = $_GET['pais'];
        if (!empty($_GET['q']))      $filters['q']      = $_GET['q'];

        $result = $this->repo->findAll($filters, $page, $limit);
        Response::paginated($result['data'], $result['total'], $page, $limit);
    }

    /** GET /api/v1/profesionales/:id (público) */
    public function show(string $id): void
    {
        $prof = $this->repo->findById((int) $id);

        if (!$prof) {
            Response::error(404, 'NOT_FOUND', "Profesional con id $id no encontrado");
        }

        Response::json($prof);
    }

    /**
     * POST /api/v1/profesionales (registro)
     *
     * IMPORTANTE: Este endpoint NO recibe JSON.
     * Recibe multipart/form-data porque incluye un archivo (la foto).
     *
     * ¿Cuál es la diferencia?
     *
     *   JSON (Content-Type: application/json):
     *     Solo puede enviar texto. No archivos.
     *     Se lee con: json_decode(file_get_contents('php://input'))
     *     PHP lo pone en: (nada, lo lees tú manualmente)
     *
     *   Multipart (Content-Type: multipart/form-data):
     *     Puede enviar texto + archivos.
     *     PHP lo pone automáticamente en: $_POST (campos) y $_FILES (archivos)
     *
     * Desde el frontend se envía así:
     *
     *   const formData = new FormData();
     *   formData.append('nombre', 'Pedro');
     *   formData.append('apellido', 'García');
     *   formData.append('email', 'pedro@test.com');
     *   formData.append('password', 'miPassword123');
     *   formData.append('foto', fileInput.files[0]);  ← el archivo
     *
     *   fetch('/api/v1/profesionales', {
     *     method: 'POST',
     *     // NO poner Content-Type — el navegador lo pone solo con el boundary
     *     body: formData,
     *   });
     *
     * Desde curl:
     *   curl -X POST .../api/v1/profesionales \
     *     -F "nombre=Pedro" \
     *     -F "apellido=García" \
     *     -F "email=pedro@test.com" \
     *     -F "password=miPassword123" \
     *     -F "foto=@/ruta/imagen.jpg"
     */
    public function register(): void
    {
        // ─── Leer campos de $_POST (no de php://input) ───
        // En multipart/form-data, los campos de texto van en $_POST.
        // Los archivos van en $_FILES.
        $body = $_POST;

        try {
            // ─── Validar campos obligatorios ───
            // Todos los campos del profesional son obligatorios (el esquema los
            // declara NOT NULL), salvo la foto, que se valida aparte más abajo.
            Validator::required([
                'nombre'           => $body['nombre'] ?? null,
                'apellido'         => $body['apellido'] ?? null,
                'tagline'          => $body['tagline'] ?? null,
                'bio'              => $body['bio'] ?? null,
                'tipo_documento'   => $body['tipo_documento'] ?? null,
                'numero_documento' => $body['numero_documento'] ?? null,
                'iban'             => $body['iban'] ?? null,
                'email'            => $body['email'] ?? null,
                'password'         => $body['password'] ?? null,
                'telefono'         => $body['telefono'] ?? null,
                'direccion'        => $body['direccion'] ?? null,
                'ciudad'           => $body['ciudad'] ?? null,
                'pais'             => $body['pais'] ?? null,
                'codigo_postal'    => $body['codigo_postal'] ?? null,
            ]);
            Validator::email($body['email']);
            Validator::minLength($body['password'], 8, 'password');

            // ─── Validar foto obligatoria ───
            if (empty($_FILES['foto']) || $_FILES['foto']['error'] === UPLOAD_ERR_NO_FILE) {
                throw new ValidationException(
                    'La foto de perfil es obligatoria para registrarse como profesional'
                );
            }

            // ─── 1. Crear la cuenta primero ───
            // Necesitamos el ID del profesional para saber en qué carpeta
            // guardar la foto (images/profesionales/42/profile.jpg).
            // Por eso primero creamos la cuenta y después subimos la foto.
            $result = $this->authService->registerProfesional($body);
            $profId = (int) $result['user']['id'];

            // ─── 2. Subir la foto ───
            // ImageService valida el archivo (tipo, tamaño, extensión)
            // y lo mueve a images/profesionales/{id}/profile.jpg
            $rutaFoto = $this->imageService->subirFotoPerfil($_FILES['foto'], $profId);

            // ─── 3. Guardar la ruta en la base de datos ───
            $this->repo->updateFoto($profId, $rutaFoto);

            // ─── 4. Añadir la URL de la foto a la respuesta ───
            $result['user']['foto_perfil'] = $rutaFoto;
            $result['user']['foto_perfil_url'] = $this->imageService->getUrl($rutaFoto);

            Response::json($result, 201);

        } catch (ValidationException $e) {
            Response::error(422, 'VALIDATION_ERROR', $e->getMessage());
        } catch (ConflictException $e) {
            Response::error(409, $e->getErrorCode(), $e->getMessage());
        }
    }

    /** GET /api/v1/profesionales/me (protegido) */
    public function me(): void
    {
        $profId = (int) $_REQUEST['auth_user_id'];
        $prof = $this->repo->findByIdPrivate($profId);

        if (!$prof) {
            Response::error(404, 'NOT_FOUND', 'Profesional no encontrado');
        }

        Response::json($prof);
    }

    /** PUT /api/v1/profesionales/me (protegido) */
    public function update(): void
    {
        $profId = (int) $_REQUEST['auth_user_id'];
        $body = json_decode(file_get_contents('php://input'), true);

        if (!$body) {
            Response::error(400, 'BAD_REQUEST', 'El cuerpo de la petición debe ser JSON válido');
        }

        // Validar campos obligatorios si están presentes
        if (isset($body['nombre']) && empty($body['nombre'])) {
            Response::error(422, 'VALIDATION_ERROR', 'El nombre no puede estar vacío');
        }
        if (isset($body['apellido']) && empty($body['apellido'])) {
            Response::error(422, 'VALIDATION_ERROR', 'El apellido no puede estar vacío');
        }
        if (isset($body['email'])) {
            if (empty($body['email']) || !filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
                Response::error(422, 'VALIDATION_ERROR', 'Formato de email inválido o vacío');
            }
        }

        // Si se intenta cambiar el email, verificar que no esté duplicado
        if (isset($body['email'])) {
            $current = $this->repo->findByIdPrivate($profId);
            if ($current && $current['email'] !== $body['email']) {
                if ($this->repo->emailExists($body['email'])) {
                    Response::error(409, 'DUPLICATE_EMAIL', 'Ya existe una cuenta con este email');
                }
            }
        }

        // Realizar la actualización en base de datos
        $updatedProf = $this->repo->update($profId, $body);

        if (!$updatedProf) {
            Response::error(404, 'NOT_FOUND', 'Profesional no encontrado');
        }

        Response::json($updatedProf);
    }

    /**
     * POST /api/v1/profesionales/me/foto
     *
     * Subir o reemplazar la foto de perfil del profesional autenticado.
     *
     * ¿Cómo se envía desde el frontend?
     * No como JSON. Se usa FormData (multipart/form-data):
     *
     *   const formData = new FormData();
     *   formData.append('foto', fileInput.files[0]);
     *
     *   fetch('/api/v1/profesionales/me/foto', {
     *     method: 'POST',
     *     headers: { 'Authorization': 'Bearer ...' },  // SIN Content-Type
     *     body: formData,
     *   });
     *
     * IMPORTANTE: No poner Content-Type en el header.
     * El navegador lo pone automáticamente con el boundary correcto.
     * Si lo pones manualmente, la petición se rompe.
     *
     * PHP recibe el archivo en $_FILES['foto'].
     */
    public function subirFoto(): void
    {
        $profId = (int) $_REQUEST['auth_user_id'];

        try {
            // ─── Verificar que se envió un archivo ───
            if (empty($_FILES['foto'])) {
                throw new ValidationException(
                    'No se envió ninguna imagen. Envía un campo "foto" con el archivo.'
                );
            }

            // ─── Obtener la foto actual (para borrarla si existe) ───
            $prof = $this->repo->findByIdPrivate($profId);
            $fotoAnterior = $prof['foto_perfil'] ?? null;

            // ─── Subir la nueva foto ───
            // ImageService valida el archivo (tipo, tamaño, extensión)
            // y lo mueve al directorio correcto.
            $rutaRelativa = $this->imageService->subirFotoPerfil($_FILES['foto'], $profId);

            // ─── Guardar la ruta en la base de datos ───
            $this->repo->updateFoto($profId, $rutaRelativa);

            // ─── Eliminar la foto anterior si existía y es diferente ───
            // (puede ser diferente extensión: antes .png, ahora .jpg)
            if ($fotoAnterior && $fotoAnterior !== $rutaRelativa) {
                $this->imageService->eliminar($fotoAnterior);
            }

            // ─── Responder con la URL de la nueva foto ───
            Response::json([
                'foto_perfil'     => $rutaRelativa,
                'foto_perfil_url' => $this->imageService->getUrl($rutaRelativa),
            ], 201);

        } catch (ValidationException $e) {
            Response::error(422, 'VALIDATION_ERROR', $e->getMessage());
        }
    }
}