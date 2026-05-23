<?php

/**
 * ============================================
 * RUTAS DE LA API
 * ============================================
 *
 * Aquí defines el "mapa" completo de tu API:
 *   URL + Método HTTP → Controlador + Middleware
 *
 * Este archivo lo carga index.php DESPUÉS de crear el Router.
 * La variable $router ya existe cuando este archivo se ejecuta.
 *
 * Convención de organización:
 *   1. Primero middleware globales
 *   2. Luego rutas públicas (sin auth)
 *   3. Luego rutas protegidas, agrupadas por rol
 *
 * CONSEJO: Cuando tu API crezca mucho, puedes separar esto en
 * varios archivos (rutas_publicas.php, rutas_usuario.php, etc.)
 * y hacer require de cada uno aquí.
 */

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\ProfesionalController;
use App\Controllers\ServicioController;
use App\Controllers\UsuarioController;
use App\Controllers\ReservaController;
use App\Controllers\DisponibilidadController;
use App\Middleware\CorsMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;

// ─────────────────────────────────────────
// Prefijo: todas las rutas empiezan con api/v1/
// ─────────────────────────────────────────
$router->setPrefix('api/v1');

// ─────────────────────────────────────────
// Middleware globales (aplican a TODAS las rutas)
// ─────────────────────────────────────────
$router->addGlobalMiddleware(CorsMiddleware::class);
$router->addGlobalMiddleware(RateLimitMiddleware::class);


// ═════════════════════════════════════════
// RUTAS PÚBLICAS (sin autenticación)
// ═════════════════════════════════════════

// ─── Auth ───
$router->post('auth/login', [AuthController::class, 'login']);

// ─── Registro (crear cuenta) ───
$router->post('usuarios',      [UsuarioController::class, 'register']);
$router->post('profesionales', [ProfesionalController::class, 'register']);

// ─── Buscar profesionales ───
$router->get('profesionales',     [ProfesionalController::class, 'index']);
$router->get('profesionales/:id', [ProfesionalController::class, 'show']);

// ─── Buscar servicios ───
$router->get('servicios',          [ServicioController::class, 'index']);
$router->get('servicios/:id',      [ServicioController::class, 'show']);
$router->get('servicios/:id/slots', [ServicioController::class, 'slots']);


// ═════════════════════════════════════════
// RUTAS PROTEGIDAS — USUARIO
// ═════════════════════════════════════════
// Estas rutas necesitan:
//   1. AuthMiddleware → verificar que el JWT es válido
//   2. RoleMiddleware:usuario → verificar que es un usuario (no profesional)

$mwUsuario = [AuthMiddleware::class, RoleMiddleware::class . ':usuario'];

// ─── Mi perfil ───
$router->get('usuarios/me', [UsuarioController::class, 'me'],       $mwUsuario);
$router->put('usuarios/me', [UsuarioController::class, 'update'],   $mwUsuario);

// ─── Mis reservas ───
$router->get('usuarios/me/reservas', [ReservaController::class, 'misReservas'], $mwUsuario);

// ─── Reservar (pagar) ───
$router->post('reservas', [ReservaController::class, 'store'], $mwUsuario);

// ─── Solicitar devolución ───
$router->post('reservas/:id/devolucion', [ReservaController::class, 'devolucion'], $mwUsuario);


// ═════════════════════════════════════════
// RUTAS PROTEGIDAS — PROFESIONAL
// ═════════════════════════════════════════

$mwProfesional = [AuthMiddleware::class, RoleMiddleware::class . ':profesional'];

// ─── Mi perfil ───
$router->get('profesionales/me', [ProfesionalController::class, 'me'],     $mwProfesional);
$router->put('profesionales/me', [ProfesionalController::class, 'update'], $mwProfesional);

// ─── Mis servicios (CRUD) ───
$router->get('profesionales/me/servicios',      [ServicioController::class, 'misServicios'],  $mwProfesional);
$router->post('profesionales/me/servicios',     [ServicioController::class, 'crearServicio'], $mwProfesional);
$router->put('profesionales/me/servicios/:id',  [ServicioController::class, 'editarServicio'], $mwProfesional);
$router->delete('profesionales/me/servicios/:id', [ServicioController::class, 'eliminarServicio'], $mwProfesional);

// ─── Disponibilidad ───
$router->get('profesionales/me/servicios/:id/disponibilidad',   [DisponibilidadController::class, 'index'],  $mwProfesional);
$router->post('profesionales/me/servicios/:id/disponibilidad',  [DisponibilidadController::class, 'store'],  $mwProfesional);
$router->put('profesionales/me/disponibilidad/:id',             [DisponibilidadController::class, 'update'], $mwProfesional);
$router->delete('profesionales/me/disponibilidad/:id',          [DisponibilidadController::class, 'destroy'], $mwProfesional);

// ─── Mi agenda (reservas que tienen conmigo) ───
$router->get('profesionales/me/reservas', [ReservaController::class, 'agendaProfesional'], $mwProfesional);


// ═════════════════════════════════════════
// RUTAS COMPARTIDAS (usuario O profesional)
// ═════════════════════════════════════════
// Solo necesitan autenticación, sin rol específico.
// El controlador decide internamente qué campo actualizar
// según el rol del usuario autenticado.

$mwAuth = [AuthMiddleware::class];

$router->get('reservas/:id',           [ReservaController::class, 'show'],      $mwAuth);
$router->post('reservas/:id/verificar', [ReservaController::class, 'verificar'], $mwAuth);