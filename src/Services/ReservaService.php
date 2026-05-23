<?php

/**
 * ============================================
 * RESERVA SERVICE — Lógica de negocio de reservas
 * ============================================
 *
 * Este Service maneja las REGLAS DE NEGOCIO de las reservas:
 *   - Crear reserva: validar slot, calcular campos, crear pago
 *   - Verificar asistencia: doble verificación usuario + profesional
 *   - Devolver: reembolsar si el servicio no se prestó
 *
 * ¿Por qué no poner esta lógica en el Controller?
 * Porque el Controller solo debe manejar HTTP (leer request, enviar response).
 * Las reglas de negocio van aquí:
 *
 *   Controller:  "Recibí un POST con estos datos"
 *   Service:     "Verifico que la fecha no sea pasada, que el slot esté libre,
 *                 calculo hora_fin y monto, y creo la reserva"
 *   Repository:  "INSERT INTO pagos ..."
 *
 * Si mañana quieres crear reservas desde otro lugar (un cron job,
 * una importación, un webhook), reutilizas este Service sin tocar HTTP.
 *
 * Ubicación en el flujo:
 *   ReservaController → [RESERVA SERVICE] → SlotService + PagoRepository
 */

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ServicioRepository;
use App\Repositories\PagoRepository;
use App\Services\SlotService;

class ReservaService
{
    private ServicioRepository $servicioRepo;
    private PagoRepository $pagoRepo;
    private SlotService $slotService;

    public function __construct()
    {
        $this->servicioRepo = new ServicioRepository();
        $this->pagoRepo     = new PagoRepository();
        $this->slotService  = new SlotService();
    }

    /**
     * Crear una reserva.
     *
     * El usuario solo envía 3 campos:
     *   - id_servicio: qué servicio quiere
     *   - fecha: qué día
     *   - hora_inicio: a qué hora
     *
     * Nosotros calculamos el resto:
     *   - id_profesional: lo sacamos del servicio
     *   - hora_fin: hora_inicio + duracion_sesion del servicio
     *   - monto: precio del servicio
     *
     * ¿Por qué calcular en el backend en vez de que el frontend los envíe?
     * Porque un usuario malintencionado podría enviar monto=0 o
     * id_profesional incorrecto. El backend es la FUENTE DE VERDAD.
     *
     * @param int    $userId      ID del usuario que reserva
     * @param int    $servicioId  ID del servicio
     * @param string $fecha       Fecha YYYY-MM-DD
     * @param string $horaInicio  Hora HH:MM
     *
     * @return array El pago/reserva creado
     *
     * @throws \RuntimeException Con códigos específicos:
     *   DATE_IN_PAST       → la fecha ya pasó
     *   SERVICE_NOT_FOUND  → el servicio no existe
     *   SLOT_OCCUPIED      → ya hay una reserva en ese horario
     *   SLOT_NOT_AVAILABLE → no hay disponibilidad configurada para ese día/hora
     */
    public function crearReserva(int $userId, int $servicioId, string $fecha, string $horaInicio): array
    {
        // ─── 1. La fecha no puede ser pasada ───
        // strtotime('today') devuelve el timestamp de hoy a las 00:00.
        // Si la fecha pedida es anterior a hoy, rechazamos.
        if (strtotime($fecha) < strtotime('today')) {
            throw new \RuntimeException('DATE_IN_PAST');
        }

        // ─── 2. El servicio debe existir ───
        $servicio = $this->servicioRepo->findById($servicioId);
        if (!$servicio) {
            throw new \RuntimeException('SERVICE_NOT_FOUND');
        }

        // ─── 3. Verificar que el slot está disponible ───
        // Usamos el SlotService para obtener TODOS los slots de ese día.
        // Esto verifica dos cosas a la vez:
        //   a) Que exista disponibilidad configurada para ese día de la semana
        //   b) Que el slot concreto no esté ya reservado
        //
        // ¿Por qué no verificar directamente en la tabla pagos?
        // Porque también necesitamos saber si la hora_inicio es válida
        // dentro de la disponibilidad del profesional. Si alguien pide
        // las 15:30 pero la franja es 16:00-18:00, no es válido.
        $slotsData = $this->slotService->getSlotsDisponibles($servicioId, $fecha);

        $slotEncontrado = false;
        foreach ($slotsData['slots'] as $slot) {
            if ($slot['hora_inicio'] === $horaInicio) {
                // El slot existe en la disponibilidad
                if (!$slot['disponible']) {
                    // Existe pero ya está reservado
                    throw new \RuntimeException('SLOT_OCCUPIED');
                }
                $slotEncontrado = true;
                break;
            }
        }

        if (!$slotEncontrado) {
            // La hora no coincide con ningún slot válido
            throw new \RuntimeException('SLOT_NOT_AVAILABLE');
        }

        // ─── 4. Calcular campos derivados ───
        // hora_fin = hora_inicio + duración de la sesión
        //
        // strtotime('16:00') devuelve timestamp, le sumamos minutos
        // convertidos a segundos, y date('H:i') lo convierte de vuelta a string.
        //
        // Ejemplo: '16:00' + (60 * 60) = '17:00'
        $horaFin = date('H:i', strtotime($horaInicio) + ($servicio['duracion_sesion'] * 60));

        // ─── 5. Crear la reserva en la base de datos ───
        // El PagoRepository hace el INSERT.
        // MySQL aplica los defaults: estado='retenido', verificados=false.
        return $this->pagoRepo->create([
            'id_usuario'     => $userId,
            'id_profesional' => $servicio['profesional']['id'],
            'id_servicio'    => $servicioId,
            'fecha'          => $fecha,
            'hora_inicio'    => $horaInicio,
            'hora_fin'       => $horaFin,
            'monto'          => $servicio['precio'],
        ]);
    }

    /**
     * Verificar asistencia.
     *
     * Tanto el usuario como el profesional pueden llamar a este método.
     * El controlador determina quién llama según el JWT y pasa el rol.
     *
     * Reglas:
     *   - Solo se puede verificar si el estado es 'retenido'
     *   - Solo el usuario o profesional de ESA reserva pueden verificar
     *   - Cuando ambos verifican, el estado cambia automáticamente a 'liberado'
     *
     * @param int    $pagoId  ID del pago/reserva
     * @param int    $authId  ID del usuario autenticado (del JWT)
     * @param string $role    'usuario' o 'profesional' (del JWT)
     *
     * @return array El pago actualizado
     *
     * @throws \RuntimeException Con códigos:
     *   NOT_FOUND     → la reserva no existe
     *   FORBIDDEN     → no es tu reserva
     *   INVALID_STATE → la reserva no está en estado 'retenido'
     */
    public function verificar(int $pagoId, int $authId, string $role): array
    {
        // ─── 1. Buscar la reserva ───
        $pago = $this->pagoRepo->findById($pagoId);

        if (!$pago) {
            throw new \RuntimeException('NOT_FOUND');
        }

        // ─── 2. Verificar propiedad ───
        // Un usuario solo puede verificar SUS reservas.
        // Un profesional solo puede verificar reservas que SON CON ÉL.
        if ($role === 'usuario' && (int) $pago['id_usuario'] !== $authId) {
            throw new \RuntimeException('FORBIDDEN');
        }
        if ($role === 'profesional' && $pago['profesional']['id'] !== $authId) {
            throw new \RuntimeException('FORBIDDEN');
        }

        // ─── 3. Solo se puede verificar si está retenido ───
        // No tiene sentido verificar algo ya liberado o devuelto.
        if ($pago['estado'] !== 'retenido') {
            throw new \RuntimeException('INVALID_STATE');
        }

        // ─── 4. Determinar qué campo actualizar ───
        // Si es usuario → verificado_usuario = true
        // Si es profesional → verificado_profesional = true
        // El PagoRepository se encarga de verificar si ambos coinciden
        // y cambiar el estado a 'liberado' automáticamente.
        $campo = $role === 'usuario' ? 'verificado_usuario' : 'verificado_profesional';

        return $this->pagoRepo->verificar($pagoId, $campo);
    }

    /**
     * Solicitar devolución.
     *
     * Solo el USUARIO que hizo la reserva puede pedir devolución.
     * Solo funciona si el estado es 'retenido' (aún no se liberó el pago).
     *
     * Cuando se devuelve:
     *   - El estado cambia a 'devuelto'
     *   - El slot queda libre (slotOcupado filtra devueltos)
     *   - Otro usuario puede reservar ese mismo slot
     *
     * @param int $pagoId  ID del pago/reserva
     * @param int $userId  ID del usuario autenticado
     *
     * @return array El pago con estado 'devuelto'
     *
     * @throws \RuntimeException Con códigos:
     *   NOT_FOUND        → no existe
     *   FORBIDDEN        → no es tu reserva
     *   ALREADY_RELEASED → el pago ya se liberó al profesional
     *   ALREADY_REFUNDED → ya se devolvió antes
     */
    public function devolver(int $pagoId, int $userId): array
    {
        $pago = $this->pagoRepo->findById($pagoId);

        if (!$pago) {
            throw new \RuntimeException('NOT_FOUND');
        }

        // Solo el usuario que reservó puede pedir devolución
        if ((int) $pago['id_usuario'] !== $userId) {
            throw new \RuntimeException('FORBIDDEN');
        }

        // No se puede devolver si ya se liberó (el profesional ya cobró)
        if ($pago['estado'] === 'liberado') {
            throw new \RuntimeException('ALREADY_RELEASED');
        }

        // No se puede devolver dos veces
        if ($pago['estado'] === 'devuelto') {
            throw new \RuntimeException('ALREADY_REFUNDED');
        }

        return $this->pagoRepo->devolver($pagoId);
    }
}