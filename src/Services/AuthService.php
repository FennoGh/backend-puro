<?php

/**
 * ============================================
 * AUTH SERVICE — Login, registro y JWT
 * ============================================
 *
 * Aquí vive la lógica de autenticación:
 *   - Verificar credenciales (email + password)
 *   - Generar tokens JWT
 *   - Hashear contraseñas
 *
 * ¿Por qué es un Service y no está en el Controller?
 * Porque la lógica de "verificar password → generar token" es
 * lógica de NEGOCIO, no de HTTP. Si mañana quieres autenticar
 * desde una app móvil o desde la línea de comandos, reutilizas
 * este Service sin tocar nada.
 *
 * FLUJO DE LOGIN:
 *   1. Cliente envía { email, password, role }
 *   2. AuthService busca el email en la tabla correspondiente
 *   3. Verifica la contraseña contra el hash almacenado
 *   4. Si es correcto, genera un JWT con los datos del usuario
 *   5. Devuelve el token al cliente
 *
 * FLUJO DE REGISTRO:
 *   1. Cliente envía { nombre, apellido, email, password, ... }
 *   2. AuthService verifica que el email no existe
 *   3. Hashea la contraseña
 *   4. Crea el registro en la base de datos
 *   5. Genera un JWT para que el usuario ya quede logueado
 */

declare(strict_types=1);

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Repositories\UsuarioRepository;
use App\Repositories\ProfesionalRepository;

class AuthService
{
    private UsuarioRepository $usuarioRepo;
    private ProfesionalRepository $profRepo;

    public function __construct()
    {
        $this->usuarioRepo = new UsuarioRepository();
        $this->profRepo    = new ProfesionalRepository();
    }

    /**
     * Login: verifica credenciales y devuelve un JWT.
     *
     * @param string $email
     * @param string $password  La contraseña en texto plano (la que el usuario escribe)
     * @param string $role      'usuario' o 'profesional'
     *
     * @return array ['token' => 'eyJ...', 'user' => [...]]
     *
     * @throws \RuntimeException Si las credenciales son inválidas
     */
    public function login(string $email, string $password, string $role): array
    {
        // ─── 1. Buscar por email según el rol ───
        // Usuarios y profesionales están en tablas distintas.
        // El cliente nos dice qué tipo de cuenta quiere usar.
        if ($role === 'profesional') {
            $user = $this->profRepo->findByEmail($email);
        } else {
            $user = $this->usuarioRepo->findByEmail($email);
        }

        // ─── 2. ¿Existe el email? ───
        // IMPORTANTE: El mensaje de error es GENÉRICO a propósito.
        // No decimos "email no encontrado" ni "contraseña incorrecta"
        // por separado, porque eso le diría a un atacante si el email
        // existe o no en tu sistema (enumeración de usuarios).
        if (!$user) {
            throw new \RuntimeException('Credenciales inválidas');
        }

        // ─── 3. Verificar la contraseña ───
        // password_verify() toma la contraseña plana y el hash almacenado,
        // y devuelve true si coinciden.
        //
        // ¿Cómo funciona por dentro?
        // El hash incluye el algoritmo y el "salt" (valor aleatorio).
        // Ejemplo de hash: $2y$10$N9qo8uLOickgx2ZMRZoMy.MrPv3J3Ouj0T5n...
        //   $2y$   → algoritmo bcrypt
        //   10$    → "cost" (cuántas veces itera, más = más seguro pero más lento)
        //   N9qo8u → salt aleatorio
        //   resto  → el hash real
        //
        // password_verify() extrae el salt del hash guardado,
        // hashea la contraseña que envió el usuario con ese mismo salt,
        // y compara los resultados.
        if (!password_verify($password, $user['password_hash'])) {
            throw new \RuntimeException('Credenciales inválidas');
        }

        // ─── 4. Generar JWT ───
        $token = $this->generateToken($user['id'], $role);

        // ─── 5. Devolver token + datos públicos del usuario ───
        // NUNCA incluimos password_hash en la respuesta.
        unset($user['password_hash']);

        return [
            'token' => $token,
            'user'  => $user,
        ];
    }

    /**
     * Registrar un usuario nuevo.
     *
     * @return array ['token' => 'eyJ...', 'user' => [...]]
     * @throws \RuntimeException Si el email ya existe
     */
    public function registerUsuario(array $data): array
    {
        // ─── 1. Verificar email único ───
        if ($this->usuarioRepo->emailExists($data['email'])) {
            throw new \RuntimeException('DUPLICATE_EMAIL');
        }

        // ─── 2. Hashear la contraseña ───
        // NUNCA guardamos la contraseña en texto plano.
        // password_hash() genera un hash bcrypt seguro.
        //
        // PASSWORD_DEFAULT usa bcrypt hoy, pero si PHP añade un algoritmo
        // mejor en el futuro, lo usará automáticamente.
        // Así tu código se mantiene seguro sin cambiar nada.
        $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);

        // Eliminar la contraseña plana del array (ya no la necesitamos)
        unset($data['password']);

        // ─── 3. Crear en base de datos ───
        $user = $this->usuarioRepo->create($data);

        // ─── 4. Generar JWT (el usuario queda logueado automáticamente) ───
        $token = $this->generateToken($user['id'], 'usuario');

        return [
            'token' => $token,
            'user'  => $user,
        ];
    }

    /**
     * Registrar un profesional nuevo.
     */
    public function registerProfesional(array $data): array
    {
        if ($this->profRepo->emailExists($data['email'])) {
            throw new \RuntimeException('DUPLICATE_EMAIL');
        }

        // numero_documento es UNIQUE: si no lo comprobamos aquí, el INSERT
        // revienta con una PDOException → 500 genérico en vez de un 409 claro.
        if (!empty($data['numero_documento'])
            && $this->profRepo->documentoExists($data['numero_documento'])) {
            throw new \RuntimeException('DUPLICATE_DOCUMENT');
        }

        $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        unset($data['password']);

        $user = $this->profRepo->create($data);
        $token = $this->generateToken($user['id'], 'profesional');

        return [
            'token' => $token,
            'user'  => $user,
        ];
    }

    /**
     * Generar un JWT.
     *
     * El token contiene:
     *   sub  → "subject": el ID del usuario (quién es)
     *   role → qué tipo de cuenta es (usuario o profesional)
     *   iat  → "issued at": cuándo se creó el token
     *   exp  → "expires": cuándo caduca
     *
     * El token se firma con JWT_SECRET. Si alguien modifica el payload
     * (por ejemplo, cambia sub de 5 a 1 para hacerse pasar por otro),
     * la firma ya no coincide y el AuthMiddleware lo rechaza.
     *
     * ¿Por qué 24 horas de expiración?
     * Es un balance entre seguridad y comodidad:
     *   - Muy corto (1h): el usuario tiene que re-loguearse constantemente
     *   - Muy largo (30 días): si roban el token, tienen acceso mucho tiempo
     *   - 24h es razonable para un MVP
     *
     * En producción podrías implementar "refresh tokens" para renovar
     * sin pedir credenciales de nuevo.
     */
    private function generateToken(int $userId, string $role): string
    {
        $now = time();

        $payload = [
            'sub'  => $userId,
            'role' => $role,
            'iat'  => $now,
            'exp'  => $now + (3600 * 24),  // 24 horas
        ];

        // JWT::encode(payload, secreto, algoritmo)
        // HS256 = HMAC con SHA-256. Es simétrico: la misma clave
        // firma y verifica. Suficiente cuando el emisor y el
        // verificador son el mismo servidor.
        return JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');
    }
}