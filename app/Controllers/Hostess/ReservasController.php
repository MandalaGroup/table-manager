<?php

namespace App\Controllers\Hostess;

use App\Controllers\BaseController;
use App\Models\ClienteModel;
use App\Models\MesaModel;
use App\Models\ReservaAsignacionModel;
use App\Models\ReservaLlegadaModel;
use App\Models\ReservaXetuxOrdenModel;
use App\Models\SucursalModel;
use App\Models\TagCategoriaModel;
use App\Models\TagModel;
use App\Services\ReservaActividadService;
use App\Services\ReservasApiService;
use App\Services\XetuxApiService;

class ReservasController extends BaseController
{
    public function index()
    {
        $fecha = $this->request->getGet('fecha') ?? date('Y-m-d');

        $solicitada = (int) $this->request->getGet('sucursal_id');
        if ($solicitada > 0) {
            $this->establecerSucursalSiPermitida($solicitada);
        }

        $sucursalId     = (int) (session()->get('sucursal_id') ?? 0);
        $sucursalActiva = $this->sucursalActivaPorId($sucursalId);
        $servicio       = new ReservasApiService();
        $reservas       = $this->enriquecerReservasConTagsCliente(
            $servicio->obtenerPorFecha($sucursalId, $fecha)
        );
        $asignaciones   = model(ReservaAsignacionModel::class)->porFecha($sucursalId, $fecha);

        $asignMap = [];
        foreach ($asignaciones as $a) {
            $asignMap[$a['reserva_codigo']] = $this->enriquecerAsignacion($a);
        }

        return view('hostess/reservas/index', [
            'titulo'         => 'Reservas',
            'fecha'          => $fecha,
            'reservas'       => $reservas,
            'asignaciones'   => $asignMap,
            'tags'           => model(TagModel::class)->activos(),
            'tagCategoriasReserva' => $this->categoriasTagsActivos('reserva'),
            'tagCategoriasCliente' => $this->categoriasTagsActivos('cliente'),
            'sucursalId'     => $sucursalId,
            'sucursalActiva' => $sucursalActiva,
            'filtrosUbicacion' => $this->filtrosUbicacionIniciales($sucursalActiva),
            'apiError'       => $servicio->obtenerUltimoError(),
            'modoDemo'       => $servicio->esModoDemo(),
        ]);
    }

    /**
     * Catálogo de tags por categoría (solo activos) para el modal hostess.
     *
     * @return list<array<string, mixed>>
     */
    private function categoriasTagsActivos(string $dominio): array
    {
        $categorias = model(TagCategoriaModel::class)->conTags();
        $resultado  = [];

        foreach ($categorias as $cat) {
            if (($cat['estatus'] ?? '') !== 'activo') {
                continue;
            }
            if (($cat['dominio'] ?? 'reserva') !== $dominio) {
                continue;
            }
            // Solo categorías visibles en el detalle de reserva (hostess)
            if (empty($cat['mostrar_en_reserva'])) {
                continue;
            }
            $activos = array_values(array_filter(
                $cat['tags'] ?? [],
                static fn (array $t): bool => ($t['estatus'] ?? '') === 'activo'
            ));
            $cat['tags'] = $activos;
            $resultado[] = $cat;
        }

        return $resultado;
    }

    /** Opciones de filtros precargadas (evita 3 peticiones AJAX al cargar la página). */
    private function filtrosUbicacionIniciales(?array $sucursalActiva): array
    {
        $model = model(SucursalModel::class);
        $ids   = $this->idsSucursalesPermitidas();
        $paisId = (int) ($sucursalActiva['pais_id'] ?? 0);
        $ciudad = trim((string) ($sucursalActiva['ciudad'] ?? ''));

        $sucursales = [];
        if ($paisId > 0 && $ciudad !== '') {
            $sucursales = array_map(static fn ($s) => [
                'id'     => (int) $s['id'],
                'nombre' => $s['nombre'],
                'ciudad' => $s['ciudad'],
            ], $model->sucursalesConAcceso($paisId, $ciudad, $ids));
        }

        return [
            'paises'     => $model->paisesConAcceso($ids),
            'ciudades'   => $paisId > 0 ? $model->ciudadesConAcceso($paisId, $ids) : [],
            'sucursales' => $sucursales,
        ];
    }

    /** JSON — países con sucursales a las que el usuario tiene acceso */
    public function apiPaises()
    {
        $paises = model(SucursalModel::class)->paisesConAcceso($this->idsSucursalesPermitidas());

        return $this->response->setJSON(['success' => true, 'paises' => $paises]);
    }

    /** JSON — ciudades con sucursales en un país (alcance del usuario) */
    public function apiCiudades(int $paisId)
    {
        $ciudades = model(SucursalModel::class)->ciudadesConAcceso($paisId, $this->idsSucursalesPermitidas());

        return $this->response->setJSON(['success' => true, 'ciudades' => $ciudades]);
    }

    /** JSON — sucursales por país y ciudad (alcance del usuario) */
    public function apiSucursales()
    {
        $paisId = (int) $this->request->getGet('pais_id');
        $ciudad = trim((string) $this->request->getGet('ciudad'));

        if ($paisId <= 0 || $ciudad === '') {
            return $this->response->setJSON(['success' => true, 'sucursales' => []]);
        }

        $sucursales = model(SucursalModel::class)->sucursalesConAcceso(
            $paisId,
            $ciudad,
            $this->idsSucursalesPermitidas()
        );

        return $this->response->setJSON([
            'success'    => true,
            'sucursales' => array_map(static fn ($s) => [
                'id'     => (int) $s['id'],
                'nombre' => $s['nombre'],
                'ciudad' => $s['ciudad'],
            ], $sucursales),
        ]);
    }

    /** JSON — reservas por sucursal y fecha (OneReservations vía backend) */
    public function apiBuscar()
    {
        $sucursalId = (int) $this->request->getGet('sucursal_id');
        $fecha      = trim((string) ($this->request->getGet('fecha') ?? ''));

        if ($sucursalId <= 0) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'error'   => 'Seleccione un establecimiento.',
            ]);
        }

        if ($fecha === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'error'   => 'Seleccione una fecha de visita.',
            ]);
        }

        if (! in_array($sucursalId, $this->idsSucursalesPermitidas(), true)) {
            return $this->response->setStatusCode(403)->setJSON([
                'success' => false,
                'error'   => 'No tienes acceso a esta sucursal.',
            ]);
        }

        $this->establecerSucursalSiPermitida($sucursalId);

        $servicio     = new ReservasApiService();
        $reservas     = $this->enriquecerReservasConTagsCliente(
            $servicio->obtenerPorFecha($sucursalId, $fecha)
        );
        $asignaciones = model(ReservaAsignacionModel::class)->porFecha($sucursalId, $fecha);

        $asignMap = [];
        foreach ($asignaciones as $a) {
            $enriched = $this->enriquecerAsignacion($a);
            $asignMap[$a['reserva_codigo']] = [
                'id'             => (int) $a['id'],
                'mesa_id'        => (int) $a['mesa_id'],
                'mesa_numero'    => $enriched['mesa_numero'] ?? null,
                'mesa_maximo'    => $enriched['mesa_maximo'] ?? null,
                'estado_mesa'    => $a['estado_mesa'] ?? 'reservada',
                'status_label'   => $enriched['status_label'] ?? 'Asignada',
                'pax_reservados' => $enriched['pax_reservados'] ?? null,
                'pax_en_mesa'    => $enriched['pax_en_mesa'] ?? 0,
                'tags_json'         => $a['tags_json'] ?? '[]',
                'tags_cliente_json' => $a['tags_cliente_json'] ?? null,
                'llegadas'          => $enriched['llegadas'] ?? [],
            ];
        }

        return $this->response->setJSON([
            'success'      => true,
            'fecha'        => $fecha,
            'sucursal_id'  => $sucursalId,
            'reservas'     => $reservas,
            'asignaciones' => $asignMap,
            'modo_demo'    => $servicio->esModoDemo(),
            'api_error'    => $servicio->obtenerUltimoError(),
            'total'        => count($reservas),
        ]);
    }

    public function detalle(string $codigo)
    {
        $solicitada = (int) $this->request->getGet('sucursal_id');
        if ($solicitada > 0) {
            $this->establecerSucursalSiPermitida($solicitada);
        }

        $sucursalId = (int) (session()->get('sucursal_id') ?? 0);
        $fecha      = trim((string) ($this->request->getGet('fecha') ?? date('Y-m-d')));
        $servicio   = new ReservasApiService();

        // Listado en caché primero (1 llamada al API como máximo); by-booking solo para QR/token
        $reserva = $sucursalId > 0
            ? $servicio->buscarEnListadoActivo($codigo, $sucursalId, $fecha)
            : null;

        if (! $reserva) {
            $reserva = $servicio->obtenerPorCodigo($codigo, $sucursalId);
        }

        if (! $reserva) {
            $mensaje = $servicio->obtenerUltimoError() ?? 'Reserva no encontrada';

            return $this->response->setStatusCode(404)->setJSON(['error' => $mensaje]);
        }

        $asignacion = model(ReservaAsignacionModel::class)->porCodigo($codigo);
        $reserva    = $this->enriquecerReservasConTagsCliente([$reserva])[0];
        $eventos    = [];
        try {
            $eventos = (new ReservaActividadService())->listar($codigo, $sucursalId);
        } catch (\Throwable $e) {
            log_message('error', 'Actividad en detalle: ' . $e->getMessage());
        }

        $xetuxOrden = null;
        if ($sucursalId > 0) {
            $xetuxOrden = model(ReservaXetuxOrdenModel::class)->activaPorCodigo($codigo, $sucursalId);
        }

        return $this->response->setJSON([
            'reserva'      => $reserva,
            'asignacion'   => $this->enriquecerAsignacion($asignacion),
            'actividad'    => $eventos,
            'xetux_orden'  => $xetuxOrden,
        ]);
    }

    public function asignarMesa()
    {
        $json = $this->request->getJSON(true);

        $rules = [
            'reserva_codigo' => 'required',
            'mesa_id'        => 'required|integer',
            'rp_codigo'      => 'permit_empty',
            'cliente_nombre' => 'permit_empty',
            'fecha'          => 'required|valid_date[Y-m-d]',
            'hora'           => 'permit_empty',
        ];

        if (! $this->validate($rules, $json)) {
            return $this->response->setStatusCode(422)->setJSON(['errors' => $this->validator->getErrors()]);
        }

        $sucursalId = (int) session()->get('sucursal_id');
        $model      = model(ReservaAsignacionModel::class);

        $existente = $model->where('reserva_codigo', $json['reserva_codigo'])
            ->where('fecha', $json['fecha'])
            ->first();

        $datos = [
            'reserva_codigo' => $json['reserva_codigo'],
            'mesa_id'        => (int) $json['mesa_id'],
            'sucursal_id'    => $sucursalId,
            'rp_codigo'      => $json['rp_codigo'] ?? null,
            'cliente_nombre' => $json['cliente_nombre'] ?? null,
            'fecha'          => $json['fecha'],
            'hora'           => $json['hora'] ?? null,
            'estado_mesa'    => $existente['estado_mesa'] ?? 'reservada',
            'asignado_por'   => session()->get('usuario_id'),
        ];

        // Conserva tags del perfil por email si la asignación aún no tiene
        $email = ClienteModel::normalizarEmail($json['email'] ?? '');
        $reservaApi = null;
        if ($email === '') {
            $reservaApi = (new ReservasApiService())->buscarEnListadoActivo(
                (string) $json['reserva_codigo'],
                $sucursalId,
                (string) $json['fecha']
            );
            $email = ClienteModel::normalizarEmail($reservaApi['email'] ?? '');
            if ($email !== '' && empty($datos['cliente_nombre']) && ! empty($reservaApi['nombre'])) {
                $datos['cliente_nombre'] = $reservaApi['nombre'];
            }
        }
        if ($reservaApi === null) {
            $reservaApi = (new ReservasApiService())->buscarEnListadoActivo(
                (string) $json['reserva_codigo'],
                $sucursalId,
                (string) $json['fecha']
            );
        }
        if ($existente === null || $existente['pax_reservados'] === null) {
            $datos['pax_reservados'] = (int) ($json['pax'] ?? $reservaApi['pax'] ?? 0);
        }
        if ($existente === null) {
            $datos['pax_en_mesa'] = 0;
        }

        if ($email !== '' && ($existente === null || empty($existente['tags_cliente_json']))) {
            $cliente = model(ClienteModel::class)->porEmail($email);
            if ($cliente && ! empty($cliente['tags_json'])) {
                $datos['tags_cliente_json'] = is_string($cliente['tags_json'])
                    ? $cliente['tags_json']
                    : json_encode($cliente['tags_json'], JSON_UNESCAPED_UNICODE);
            }
        }

        if ($existente) {
            // No degradar ocupada al reasignar mesa
            if (($existente['estado_mesa'] ?? '') === 'liberada') {
                $datos['estado_mesa'] = 'reservada';
                $datos['pax_en_mesa'] = 0;
            }
            $model->update($existente['id'], $datos);
            $asignacionId = (int) $existente['id'];
            $mesaAntes = model(MesaModel::class)->find($existente['mesa_id']);
            $mesaDespues = model(MesaModel::class)->find($datos['mesa_id']);
            $numAntes = $mesaAntes['numero'] ?? $existente['mesa_id'];
            $numDespues = $mesaDespues['numero'] ?? $datos['mesa_id'];
            if ((int) $existente['mesa_id'] !== (int) $datos['mesa_id']) {
                (new ReservaActividadService())->registrar(
                    (string) $json['reserva_codigo'],
                    'mesa_cambiada',
                    "cambió la mesa de #{$numAntes} a #{$numDespues}",
                    ['mesa_antes' => (int) $existente['mesa_id'], 'mesa_despues' => (int) $datos['mesa_id']],
                    $sucursalId
                );
            }
        } else {
            $model->insert($datos);
            $asignacionId = (int) $model->getInsertID();
            $mesa = model(MesaModel::class)->find($datos['mesa_id']);
            $num = $mesa['numero'] ?? $datos['mesa_id'];
            (new ReservaActividadService())->registrar(
                (string) $json['reserva_codigo'],
                'mesa_asignada',
                "asignó la mesa #{$num}",
                ['mesa_id' => (int) $datos['mesa_id']],
                $sucursalId
            );
        }

        return $this->response->setJSON([
            'success'    => true,
            'message'    => 'Mesa asignada correctamente.',
            'asignacion' => $this->enriquecerAsignacion($model->find($asignacionId)),
        ]);
    }

    /**
     * Registra una oleada de pax (+/−). Primera llegada positiva → Arrived.
     * Si aún no hay asignación, crea un check-in sin mesa (walk-in de llegada).
     */
    public function registrarLlegada()
    {
        $json = $this->request->getJSON(true) ?? [];
        $codigo = trim((string) ($json['reserva_codigo'] ?? ''));
        $delta  = (int) ($json['pax_delta'] ?? 0);

        if ($codigo === '' || $delta === 0) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'error'   => 'Indica la reserva y un número de pax distinto de cero.',
            ]);
        }

        $sucursalId = (int) (session()->get('sucursal_id') ?? 0);
        if ($sucursalId <= 0) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'error'   => 'Selecciona un establecimiento.',
            ]);
        }

        $model = model(ReservaAsignacionModel::class);
        $asignacion = $model->porCodigo($codigo);

        // Sin mesa aún: crea registro de check-in para poder sumar pax (Arrived)
        if (! $asignacion) {
            $fecha = trim((string) ($json['fecha'] ?? date('Y-m-d')));
            $reservaApi = (new ReservasApiService())->buscarEnListadoActivo($codigo, $sucursalId, $fecha);
            $paxRes = (int) ($json['pax'] ?? $reservaApi['pax'] ?? 0);
            $nombre = trim((string) ($json['cliente_nombre'] ?? $reservaApi['nombre'] ?? ''));
            $hora   = $reservaApi['hora'] ?? null;

            $model->insert([
                'reserva_codigo' => $codigo,
                'mesa_id'        => null,
                'sucursal_id'    => $sucursalId,
                'cliente_nombre' => $nombre !== '' ? $nombre : null,
                'fecha'          => $fecha,
                'hora'           => $hora,
                'pax_reservados' => $paxRes > 0 ? $paxRes : null,
                'pax_en_mesa'    => 0,
                'estado_mesa'    => 'reservada',
                'asignado_por'   => session()->get('usuario_id'),
            ]);
            $asignacion = $model->find($model->getInsertID());
            if (! $asignacion) {
                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'error'   => 'No se pudo iniciar el check-in de la reserva.',
                ]);
            }
        }

        if (($asignacion['estado_mesa'] ?? '') === 'liberada') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'error'   => 'La reserva ya fue liberada.',
            ]);
        }

        $nuevo = max(0, (int) ($asignacion['pax_en_mesa'] ?? 0) + $delta);
        $ahora = date('Y-m-d H:i:s');
        $update = ['pax_en_mesa' => $nuevo];

        if ($nuevo > 0 && empty($asignacion['arrived_at'])) {
            $update['arrived_at'] = $ahora;
        }

        $model->update($asignacion['id'], $update);

        model(ReservaLlegadaModel::class)->insert([
            'asignacion_id'  => (int) $asignacion['id'],
            'pax_delta'      => $delta,
            'pax_resultante' => $nuevo,
            'nota'           => $json['nota'] ?? null,
            'registrado_por' => session()->get('usuario_id'),
            'created_at'     => $ahora,
        ]);

        $signo = $delta > 0 ? '+' : '';
        (new ReservaActividadService())->registrar(
            $codigo,
            'llegada_pax',
            "registró {$signo}{$delta} pax → {$nuevo} en mesa",
            ['pax_delta' => $delta, 'pax_en_mesa' => $nuevo, 'sin_mesa' => empty($asignacion['mesa_id'])],
            $sucursalId
        );

        $warning = null;
        if (! empty($asignacion['mesa_id'])) {
            $mesa = model(MesaModel::class)->find($asignacion['mesa_id']);
            if ($mesa && $nuevo > (int) $mesa['maximo']) {
                $warning = 'Los pax en mesa (' . $nuevo . ') superan el máximo de la mesa (' . (int) $mesa['maximo'] . ').';
            }
        }

        return $this->response->setJSON([
            'success'    => true,
            'warning'    => $warning,
            'asignacion' => $this->enriquecerAsignacion($model->find($asignacion['id'])),
        ]);
    }

    /** Marca la mesa como ocupada (sentados). */
    public function sentar()
    {
        $json = $this->request->getJSON(true) ?? [];
        $codigo = trim((string) ($json['reserva_codigo'] ?? ''));
        $model = model(ReservaAsignacionModel::class);
        $asignacion = $model->porCodigo($codigo);

        if (! $asignacion) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'error'   => 'Asigna una mesa antes de sentar.',
            ]);
        }

        $ahora = date('Y-m-d H:i:s');
        $update = [
            'estado_mesa' => 'ocupada',
            'seated_at'   => $ahora,
        ];
        if (empty($asignacion['arrived_at'])) {
            $update['arrived_at'] = $ahora;
        }
        if ((int) ($asignacion['pax_en_mesa'] ?? 0) === 0 && (int) ($asignacion['pax_reservados'] ?? 0) > 0) {
            // Sentar sin oleadas previas: asume pax reservados presentes
            $update['pax_en_mesa'] = (int) $asignacion['pax_reservados'];
            model(ReservaLlegadaModel::class)->insert([
                'asignacion_id'  => (int) $asignacion['id'],
                'pax_delta'      => (int) $asignacion['pax_reservados'],
                'pax_resultante' => (int) $asignacion['pax_reservados'],
                'nota'           => 'Sentar (pax reservados)',
                'registrado_por' => session()->get('usuario_id'),
                'created_at'     => $ahora,
            ]);
        }

        $model->update($asignacion['id'], $update);

        (new ReservaActividadService())->registrar(
            $codigo,
            'sentar',
            'marcó la reserva como sentada (ocupada)',
            ['estado_mesa' => 'ocupada']
        );

        return $this->response->setJSON([
            'success'    => true,
            'asignacion' => $this->enriquecerAsignacion($model->find($asignacion['id'])),
        ]);
    }

    /** Libera la mesa. */
    public function liberar()
    {
        $json = $this->request->getJSON(true) ?? [];
        $codigo = trim((string) ($json['reserva_codigo'] ?? ''));
        $model = model(ReservaAsignacionModel::class);
        $asignacion = $model->porCodigo($codigo);

        if (! $asignacion) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'error'   => 'Asignación no encontrada.',
            ]);
        }

        // Si se sentó en Xetux: la cuenta debe estar cerrada en el POS y su consumo llega a la auditoría de OR
        $sucursalId = (int) (session()->get('sucursal_id') ?? $asignacion['sucursal_id'] ?? 0);
        $consumo    = $this->enviarConsumoXetux($codigo, $sucursalId);
        if ($consumo['error'] !== null) {
            return $this->response->setStatusCode($consumo['status'])->setJSON([
                'success' => false,
                'error'   => $consumo['error'],
            ]);
        }

        $model->update($asignacion['id'], [
            'estado_mesa'  => 'liberada',
            'liberated_at' => date('Y-m-d H:i:s'),
        ]);

        (new ReservaActividadService())->registrar(
            $codigo,
            'liberar',
            'liberó la mesa de la reserva',
            ['estado_mesa' => 'liberada'],
            $sucursalId
        );

        return $this->response->setJSON([
            'success'    => true,
            'asignacion' => $this->enriquecerAsignacion($model->find($asignacion['id'])),
            'consumo'    => $consumo['enviado'],
        ]);
    }

    /**
     * Estado de las subórdenes Xetux de una orden.
     *
     * @return array{error: ?string, abiertas: int, tickets: list<array{numero_ticket: string, cheque: string, monto: float}>, total: float}
     */
    private function estadoSubordenesXetux(int $sucursalId, array $orden): array
    {
        $xetux      = new XetuxApiService();
        $subordenes = $xetux->subordenes($sucursalId, (int) $orden['order_id']);
        if ($subordenes === null) {
            return [
                'error'    => 'No se pudo consultar la cuenta en Xetux: ' . ($xetux->obtenerUltimoError() ?? 'sin respuesta'),
                'abiertas' => 0,
                'tickets'  => [],
                'total'    => 0.0,
            ];
        }

        $abiertas = 0;
        $tickets  = [];
        $total    = 0.0;
        foreach ($subordenes as $sub) {
            $estado = mb_strtolower(trim((string) ($sub['suborderStatusDescription'] ?? '')));
            if ($estado === 'cancelada') {
                continue;
            }
            if ($estado !== 'cerrada') {
                $abiertas++;

                continue;
            }
            // Consumo = total de la suborden (la propina viene aparte en tipPaid y no se incluye)
            $monto = round((float) ($sub['suborderTotal'] ?? 0), 2);
            if ($monto <= 0 || empty($sub['suborderId'])) {
                continue;
            }
            $tickets[] = [
                'numero_ticket' => (string) $sub['suborderId'],
                'cheque'        => (string) $orden['order_id'],
                'monto'         => $monto,
            ];
            $total += $monto;
        }

        return ['error' => null, 'abiertas' => $abiertas, 'tickets' => $tickets, 'total' => round($total, 2)];
    }

    /**
     * Antes de liberar: exige cuenta cerrada en Xetux y registra su consumo en la auditoría de OR.
     *
     * @return array{error: ?string, status: int, enviado: ?array<string, mixed>}
     */
    private function enviarConsumoXetux(string $codigo, int $sucursalId): array
    {
        $ordenModel = model(ReservaXetuxOrdenModel::class);
        $orden      = $sucursalId > 0 ? $ordenModel->pendienteDeConsumo($codigo, $sucursalId) : null;
        if (! $orden || empty($orden['order_id'])) {
            return ['error' => null, 'status' => 200, 'enviado' => null];
        }

        $estado = $this->estadoSubordenesXetux($sucursalId, $orden);
        if ($estado['error'] !== null) {
            return ['error' => $estado['error'], 'status' => 502, 'enviado' => null];
        }
        if ($estado['abiertas'] > 0) {
            return [
                'error'   => 'La cuenta sigue abierta en el POS. Ciérrala en Xetux antes de liberar la mesa.',
                'status'  => 409,
                'enviado' => null,
            ];
        }

        $respuesta = null;
        if ($estado['tickets'] !== []) {
            $api       = new ReservasApiService();
            $respuesta = $api->registrarConsumos($sucursalId, $codigo, $estado['tickets']);
            if ($respuesta === null) {
                return [
                    'error'   => 'No se pudo enviar el consumo a One Reservations: ' . ($api->obtenerUltimoError() ?? 'sin respuesta'),
                    'status'  => 502,
                    'enviado' => null,
                ];
            }
        }

        $ordenModel->update($orden['id'], [
            'estatus'              => 'cerrada',
            'consumo_total'        => $estado['total'],
            'consumos_json'        => json_encode(['tickets' => $estado['tickets'], 'respuesta' => $respuesta], JSON_UNESCAPED_UNICODE),
            'consumos_enviados_at' => date('Y-m-d H:i:s'),
        ]);

        $numTickets = count($estado['tickets']);
        (new ReservaActividadService())->registrar(
            $codigo,
            'otro',
            $numTickets === 0
                ? 'cerró la cuenta en Xetux sin consumo'
                : 'envió el consumo de Xetux a la auditoría de OR: ' . number_format($estado['total'], 2)
                    . ' (' . $numTickets . ' ' . ($numTickets === 1 ? 'ticket' : 'tickets') . ')',
            ['order_id' => $orden['order_id'], 'tickets' => $estado['tickets']],
            $sucursalId
        );

        return [
            'error'   => null,
            'status'  => 200,
            'enviado' => ['total' => $estado['total'], 'tickets' => $numTickets],
        ];
    }

    public function agregarTags()
    {
        $json   = $this->request->getJSON(true) ?? [];
        $codigo = trim((string) ($json['reserva_codigo'] ?? ''));
        $email  = ClienteModel::normalizarEmail($json['email'] ?? '');

        if ($codigo === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'error'   => 'Falta el código de reserva.',
            ]);
        }

        $tagsCliente = is_array($json['tags_cliente'] ?? null) ? $json['tags_cliente'] : null;
        $tagsReserva = is_array($json['tags_reserva'] ?? null) ? $json['tags_reserva'] : null;
        // Compatibilidad: un solo arreglo "tags" se trata como tags de reserva
        if ($tagsReserva === null && is_array($json['tags'] ?? null)) {
            $tagsReserva = $json['tags'];
        }

        $asignacionModel = model(ReservaAsignacionModel::class);
        $asignacion = $asignacionModel
            ->where('reserva_codigo', $codigo)
            ->orderBy('created_at', 'DESC')
            ->first();

        $clienteGuardado = false;
        $reservaGuardada = false;

        if ($tagsCliente !== null) {
            $payloadCliente = json_encode(array_values($tagsCliente), JSON_UNESCAPED_UNICODE);
            if ($asignacion) {
                $asignacionModel->update($asignacion['id'], ['tags_cliente_json' => $payloadCliente]);
            } else {
                $sucursalId = (int) (session()->get('sucursal_id') ?? 0);
                $fecha = trim((string) ($json['fecha'] ?? date('Y-m-d')));
                $asignacionModel->insert([
                    'reserva_codigo'     => $codigo,
                    'mesa_id'            => null,
                    'sucursal_id'        => $sucursalId,
                    'cliente_nombre'     => $json['cliente_nombre'] ?? null,
                    'fecha'              => $fecha,
                    'tags_cliente_json'  => $payloadCliente,
                    'estado_mesa'        => 'reservada',
                    'asignado_por'       => session()->get('usuario_id'),
                ]);
                $asignacion = $asignacionModel->find($asignacionModel->getInsertID());
            }
            $reservaGuardada = true;

            if ($email !== '') {
                model(ClienteModel::class)->upsertTags($email, $tagsCliente, [
                    'nombre'   => $json['cliente_nombre'] ?? null,
                    'telefono' => $json['telefono'] ?? null,
                ]);
                $clienteGuardado = true;
            }
        }

        if ($tagsReserva !== null) {
            $payloadReserva = json_encode(array_values($tagsReserva), JSON_UNESCAPED_UNICODE);
            if ($asignacion) {
                $asignacionModel->update($asignacion['id'], ['tags_json' => $payloadReserva]);
            } else {
                $sucursalId = (int) (session()->get('sucursal_id') ?? 0);
                $fecha = trim((string) ($json['fecha'] ?? date('Y-m-d')));
                $asignacionModel->insert([
                    'reserva_codigo' => $codigo,
                    'mesa_id'        => null,
                    'sucursal_id'    => $sucursalId,
                    'cliente_nombre' => $json['cliente_nombre'] ?? null,
                    'fecha'          => $fecha,
                    'tags_json'      => $payloadReserva,
                    'estado_mesa'    => 'reservada',
                    'asignado_por'   => session()->get('usuario_id'),
                ]);
                $asignacion = $asignacionModel->find($asignacionModel->getInsertID());
            }
            $reservaGuardada = true;
        }

        if (! $reservaGuardada) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'error'   => 'Indica tags de cliente o tags de reserva.',
            ]);
        }

        $descParts = [];
        if ($tagsCliente !== null) {
            $descParts[] = 'cliente: ' . $this->nombresTags($tagsCliente);
        }
        if ($tagsReserva !== null) {
            $descParts[] = 'reserva: ' . $this->nombresTags($tagsReserva);
        }
        (new ReservaActividadService())->registrar(
            $codigo,
            'tags',
            'actualizó tags (' . implode(' · ', $descParts) . ')',
            [
                'tags_cliente' => $tagsCliente,
                'tags_reserva' => $tagsReserva,
            ]
        );

        $asignacion = $asignacionModel
            ->where('reserva_codigo', $codigo)
            ->orderBy('created_at', 'DESC')
            ->first();

        return $this->response->setJSON([
            'success'    => true,
            'cliente'    => $clienteGuardado,
            'reserva'    => $reservaGuardada,
            'asignacion' => $this->enriquecerAsignacion($asignacion),
        ]);
    }

    /** JSON — meseros disponibles en Xetux para la sucursal activa. */
    public function xetuxMeseros()
    {
        $sucursalId = (int) (session()->get('sucursal_id') ?? 0);
        if ($sucursalId <= 0) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'error'   => 'Selecciona un establecimiento.',
            ]);
        }

        $servicio = new XetuxApiService();
        $lista    = $servicio->meserosDisponibles($sucursalId);

        if ($lista === [] && $servicio->obtenerUltimoError()) {
            return $this->response->setStatusCode(502)->setJSON([
                'success' => false,
                'error'   => $servicio->obtenerUltimoError(),
            ]);
        }

        return $this->response->setJSON(['success' => true, 'items' => $lista]);
    }

    /** JSON — mesas (spaces) disponibles en Xetux. */
    public function xetuxMesas()
    {
        $sucursalId = (int) (session()->get('sucursal_id') ?? 0);
        if ($sucursalId <= 0) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'error'   => 'Selecciona un establecimiento.',
            ]);
        }

        $servicio = new XetuxApiService();
        $lista    = $servicio->mesasDisponibles($sucursalId);

        if ($lista === [] && $servicio->obtenerUltimoError()) {
            return $this->response->setStatusCode(502)->setJSON([
                'success' => false,
                'error'   => $servicio->obtenerUltimoError(),
            ]);
        }

        return $this->response->setJSON(['success' => true, 'items' => $lista]);
    }

    /**
     * Crea orden en Xetux (SENTAR), guarda orderId/suborderId y aplica prepago si aplica.
     */
    public function sentarXetux()
    {
        $json = $this->request->getJSON(true) ?? [];
        $codigo = trim((string) ($json['reserva_codigo'] ?? ''));
        $waiterId = (int) ($json['waiter_id'] ?? 0);
        $spaceId = (int) ($json['space_id'] ?? 0);

        if ($codigo === '' || $waiterId <= 0 || $spaceId <= 0) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'error'   => 'Selecciona mesero y mesa antes de sentar.',
            ]);
        }

        $sucursalId = (int) (session()->get('sucursal_id') ?? 0);
        if ($sucursalId <= 0) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'error'   => 'Selecciona un establecimiento.',
            ]);
        }

        $ordenModel = model(ReservaXetuxOrdenModel::class);
        if ($ordenModel->activaPorCodigo($codigo, $sucursalId)) {
            return $this->response->setStatusCode(409)->setJSON([
                'success' => false,
                'error'   => 'Esta reserva ya tiene una orden activa en Xetux.',
            ]);
        }

        $fecha = trim((string) ($json['fecha'] ?? date('Y-m-d')));
        $reserva = (new ReservasApiService())->buscarEnListadoActivo($codigo, $sucursalId, $fecha);
        if (! $reserva) {
            $reserva = (new ReservasApiService())->obtenerPorCodigo($codigo, $sucursalId);
        }
        if (! $reserva) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'error'   => 'Reserva no encontrada para enviar a Xetux.',
            ]);
        }

        // Flujo: primero check-in en One Reservations (estatus 3/4/5), luego sentar
        if (($reserva['checkin'] ?? '') !== 'Sí') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'error'   => 'La reserva no tiene check-in en One Reservations. Haz el check-in antes de sentar.',
            ]);
        }

        $xetux = new XetuxApiService();
        $cfg   = $xetux->configuracion($sucursalId);
        if ($cfg === null) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'error'   => $xetux->obtenerUltimoError(),
            ]);
        }

        [$firstName, $lastName] = XetuxApiService::partirNombre((string) ($reserva['nombre'] ?? ''));
        $pax = (int) ($reserva['pax'] ?? 1);
        if ($pax < 1) {
            $pax = 1;
        }

        $payloadCreate = [
            'stationCode'      => $cfg['station_code'],
            'reference'        => $codigo,
            'numberOfDiners'   => $pax,
            'spaceId'          => $spaceId,
            'openOrderUserId'  => $waiterId,
            'client'           => [
                'document'  => $codigo,
                'firstName' => $firstName,
                'lastName'  => $lastName,
                'phone'     => (string) ($reserva['telefono'] ?? ''),
            ],
        ];
        $emailCliente = trim((string) ($reserva['email'] ?? ''));
        if ($emailCliente !== '' && filter_var($emailCliente, FILTER_VALIDATE_EMAIL) !== false) {
            $payloadCreate['client']['email'] = $emailCliente;
        }

        $respCreate = $xetux->crearOrden($sucursalId, $payloadCreate);
        if ($respCreate === null) {
            return $this->response->setStatusCode(502)->setJSON([
                'success' => false,
                'error'   => $xetux->obtenerUltimoError() ?? 'Xetux no creó la orden.',
            ]);
        }

        $orderId = $this->extraerEnteroRespuesta($respCreate, ['orderId', 'order_id', 'OrderId']);
        $suborderId = $this->extraerEnteroRespuesta($respCreate, ['suborderId', 'suborder_id', 'SuborderId']);
        if ($orderId === null || $suborderId === null) {
            $anidado = is_array($respCreate['data'] ?? null) ? $respCreate['data'] : $respCreate;
            $orderId ??= $this->extraerEnteroRespuesta($anidado, ['orderId', 'order_id']);
            $suborderId ??= $this->extraerEnteroRespuesta($anidado, ['suborderId', 'suborder_id']);
        }

        if ($orderId === null || $suborderId === null) {
            return $this->response->setStatusCode(502)->setJSON([
                'success' => false,
                'error'   => 'Xetux no devolvió orderId/suborderId.',
                'raw'     => $respCreate,
            ]);
        }

        $ordenModel->insert([
            'reserva_codigo'        => $codigo,
            'sucursal_id'           => $sucursalId,
            'order_id'              => $orderId,
            'suborder_id'           => $suborderId,
            'waiter_id'             => $waiterId,
            'space_id'              => $spaceId,
            'estatus'               => 'activa',
            'payload_create_json'   => json_encode($payloadCreate, JSON_UNESCAPED_UNICODE),
            'payload_response_json' => json_encode($respCreate, JSON_UNESCAPED_UNICODE),
        ]);

        $prepago = (float) ($reserva['prepago'] ?? 0);
        $pagoOk  = true;
        $pagoError = null;
        if ($prepago > 0) {
            if ($cfg['payform_id'] === null) {
                $pagoOk = false;
                $pagoError = 'Hay prepago pero la sucursal no tiene payform ID configurado.';
            } else {
                $refStripe = trim((string) ($json['stripe_reference'] ?? $reserva['stripe_reference'] ?? $codigo));
                $datosOrden = is_array($respCreate['data'] ?? null) ? $respCreate['data'] : $respCreate;
                // total = total de la orden (0.00 recién creada), no el monto del prepago
                $totalOrden = is_numeric($datosOrden['orderTotal'] ?? null) ? (float) $datosOrden['orderTotal'] : 0.0;
                // Hora local del POS: la app corre en UTC, así que se toma la apertura que reporta Xetux
                $fechaPago = trim((string) ($datosOrden['openingDatetime'] ?? ''));
                if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $fechaPago) !== 1) {
                    $fechaPago = date('Y-m-d\TH:i:s');
                }
                $nombreForma = $xetux->nombreFormaPago($sucursalId, (int) $cfg['payform_id']) ?? 'PREPAGO';
                $payloadPago = [
                    'suborderId' => $suborderId,
                    'orderId'    => $orderId,
                    'total'      => $totalOrden,
                    'payments'   => [[
                        'payformId'         => $cfg['payform_id'],
                        'payformName'       => $nombreForma,
                        'referenceNumber'   => $refStripe,
                        'amount'            => $prepago,
                        'tip'               => 0,
                        'prepaid'           => true,
                        'paymentDatetime'   => $fechaPago,
                    ]],
                ];
                if ($xetux->agregarPago($sucursalId, $payloadPago) === null) {
                    $pagoOk = false;
                    $pagoError = $xetux->obtenerUltimoError();
                }
            }
        }

        // Estado local: sentados
        $asignacionModel = model(ReservaAsignacionModel::class);
        $asignacion = $asignacionModel->porCodigo($codigo);
        if ($asignacion) {
            $asignacionModel->update($asignacion['id'], [
                'estado_mesa' => 'ocupada',
                'seated_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        (new ReservaActividadService())->registrar(
            $codigo,
            'sentar',
            "sentó en Xetux (orden {$orderId}, suborden {$suborderId})",
            [
                'order_id'    => $orderId,
                'suborder_id' => $suborderId,
                'waiter_id'   => $waiterId,
                'space_id'    => $spaceId,
                'xetux'       => true,
            ],
            $sucursalId
        );

        $orden = $ordenModel->activaPorCodigo($codigo, $sucursalId);

        return $this->response->setJSON([
            'success'        => true,
            'xetux_orden'    => $orden,
            'prepago_enviado'=> $prepago > 0 && $pagoOk,
            'prepago_warning'=> $pagoError,
            'asignacion'     => $this->enriquecerAsignacion($asignacion ? $asignacionModel->find($asignacion['id']) : null),
        ]);
    }

    /** Cancela orden Xetux localmente y libera la reserva para reutilizar. */
    public function cancelarXetux()
    {
        $json = $this->request->getJSON(true) ?? [];
        $codigo = trim((string) ($json['reserva_codigo'] ?? ''));
        $sucursalId = (int) (session()->get('sucursal_id') ?? 0);

        if ($codigo === '' || $sucursalId <= 0) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'error'   => 'Datos incompletos.',
            ]);
        }

        $ordenModel = model(ReservaXetuxOrdenModel::class);
        $orden = $ordenModel->activaPorCodigo($codigo, $sucursalId);
        if (! $orden) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'error'   => 'No hay orden Xetux activa para cancelar.',
            ]);
        }

        // Xetux no expone cancelación: la orden se cancela en el POS y aquí solo se confirma.
        if (! empty($orden['order_id'])) {
            $estado = $this->estadoSubordenesXetux($sucursalId, $orden);
            if ($estado['error'] !== null) {
                return $this->response->setStatusCode(502)->setJSON([
                    'success' => false,
                    'error'   => $estado['error'],
                ]);
            }
            if ($estado['abiertas'] > 0) {
                return $this->response->setStatusCode(409)->setJSON([
                    'success' => false,
                    'error'   => 'La orden sigue abierta en el POS. Cancélala en Xetux y vuelve a intentar.',
                ]);
            }
            if ($estado['tickets'] !== []) {
                return $this->response->setStatusCode(409)->setJSON([
                    'success' => false,
                    'error'   => 'La cuenta se cerró con consumo en el POS. Usa «Liberar mesa» para enviarlo a One Reservations.',
                ]);
            }
        }

        $ordenModel->update($orden['id'], ['estatus' => 'cancelada']);

        $asignacionModel = model(ReservaAsignacionModel::class);
        $asignacion = $asignacionModel->porCodigo($codigo);
        if ($asignacion) {
            $asignacionModel->update($asignacion['id'], [
                'estado_mesa'  => 'liberada',
                'liberated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        (new ReservaActividadService())->registrar(
            $codigo,
            'liberar',
            'canceló la orden Xetux y liberó la reserva',
            ['xetux_cancel' => true, 'order_id' => $orden['order_id'] ?? null],
            $sucursalId
        );

        return $this->response->setJSON([
            'success'    => true,
            'asignacion' => $this->enriquecerAsignacion($asignacion ? $asignacionModel->find($asignacion['id']) : null),
        ]);
    }

    /** Consulta totales/productos en Xetux antes del cierre (paso final pendiente de URL destino). */
    public function cerrarCuentaXetux()
    {
        $json = $this->request->getJSON(true) ?? [];
        $codigo = trim((string) ($json['reserva_codigo'] ?? ''));
        $sucursalId = (int) (session()->get('sucursal_id') ?? 0);

        if ($codigo === '' || $sucursalId <= 0) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'error'   => 'Datos incompletos.',
            ]);
        }

        $orden = model(ReservaXetuxOrdenModel::class)->activaPorCodigo($codigo, $sucursalId);
        if (! $orden || empty($orden['suborder_id'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'error'   => 'No hay suborden activa para cerrar cuenta.',
            ]);
        }

        $xetux = new XetuxApiService();
        $info = $xetux->infoOrden($sucursalId, (int) $orden['suborder_id'], true);
        if ($info === null) {
            return $this->response->setStatusCode(502)->setJSON([
                'success' => false,
                'error'   => $xetux->obtenerUltimoError(),
            ]);
        }

        // Xetux no tiene endpoint de cierre: la cuenta se cierra en el POS y aquí solo se refleja su estado.
        $datosInfo   = is_array($info['data'] ?? null) ? $info['data'] : $info;
        $estadoPos   = trim((string) ($datosInfo['suborderStatusDescription'] ?? $datosInfo['orderStatusDescription'] ?? ''));
        $estadoLocal = match (mb_strtolower($estadoPos)) {
            'cerrada'   => 'cerrada',
            'cancelada' => 'cancelada',
            default     => 'activa',
        };

        // Solo consulta: la orden local se cierra al liberar la mesa (ahí se envía el consumo a OR)
        // o se cancela con CANCELAR, para no reabrir SENTAR antes de tiempo.

        (new ReservaActividadService())->registrar(
            $codigo,
            'otro',
            'consultó la cuenta en Xetux: ' . ($estadoPos !== '' ? $estadoPos : 'estado desconocido'),
            ['suborder_id' => $orden['suborder_id'], 'estado_pos' => $estadoPos],
            $sucursalId
        );

        return $this->response->setJSON([
            'success'     => true,
            'estado_pos'  => $estadoPos,
            'cerrada'     => $estadoLocal !== 'activa',
            'resumen'     => [
                'total'     => $datosInfo['suborderTotal'] ?? $datosInfo['orderTotal'] ?? null,
                'pagado'    => $datosInfo['amountPaid'] ?? null,
                'pendiente' => $datosInfo['amountRemaining'] ?? null,
                'propina'   => $datosInfo['tipPaid'] ?? null,
            ],
            'order_info'  => $info,
            'xetux_orden' => model(ReservaXetuxOrdenModel::class)->find($orden['id']),
        ]);
    }

    /**
     * @param list<string> $claves
     */
    private function extraerEnteroRespuesta(array $data, array $claves): ?int
    {
        foreach ($claves as $clave) {
            if (isset($data[$clave]) && is_numeric($data[$clave])) {
                return (int) $data[$clave];
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>|string> $tags
     */
    private function nombresTags(array $tags): string
    {
        $nombres = [];
        foreach ($tags as $t) {
            if (is_array($t) && ! empty($t['nombre'])) {
                $nombres[] = (string) $t['nombre'];
            } elseif (is_string($t) && $t !== '') {
                $nombres[] = $t;
            }
        }

        return $nombres === [] ? 'sin tags' : implode(', ', $nombres);
    }

    /**
     * JSON — historial de actividad consolidado de la reserva.
     */
    public function actividad()
    {
        $codigo = trim((string) ($this->request->getGet('codigo') ?? ''));
        if ($codigo === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'error'   => 'Falta el código de reserva.',
            ]);
        }

        $sucursalId = (int) (session()->get('sucursal_id') ?? 0);

        try {
            $eventos = (new ReservaActividadService())->listar($codigo, $sucursalId);
        } catch (\Throwable $e) {
            log_message('error', 'Actividad: ' . $e->getMessage());

            return $this->response->setJSON([
                'success' => true,
                'codigo'  => $codigo,
                'eventos' => [],
            ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'codigo'  => $codigo,
            'eventos' => $eventos,
        ]);
    }

    /**
     * Adjunta cliente_tags desde el perfil por email (primera visita = []).
     *
     * @param  list<array<string, mixed>> $reservas
     * @return list<array<string, mixed>>
     */
    private function enriquecerReservasConTagsCliente(array $reservas): array
    {
        if ($reservas === []) {
            return $reservas;
        }

        $emails = [];
        foreach ($reservas as $reserva) {
            $emails[] = $reserva['email'] ?? '';
        }

        $mapa = model(ClienteModel::class)->tagsPorEmails($emails);

        foreach ($reservas as &$reserva) {
            $email = ClienteModel::normalizarEmail($reserva['email'] ?? '');
            $reserva['cliente_tags'] = $mapa[$email] ?? [];
        }
        unset($reserva);

        return $reservas;
    }

    /**
     * Añade mesa, status label y llegadas a la asignación.
     */
    private function enriquecerAsignacion(?array $asignacion): ?array
    {
        if (! $asignacion) {
            return null;
        }

        $mesa = ! empty($asignacion['mesa_id'])
            ? model(MesaModel::class)->find($asignacion['mesa_id'])
            : null;
        $asignacion['mesa_numero']   = $mesa['numero'] ?? null;
        $asignacion['mesa_maximo']   = isset($mesa['maximo']) ? (int) $mesa['maximo'] : null;
        $asignacion['status_label']  = ReservaAsignacionModel::statusLabel($asignacion);
        $asignacion['pax_reservados'] = $asignacion['pax_reservados'] !== null
            ? (int) $asignacion['pax_reservados']
            : null;
        $asignacion['pax_en_mesa'] = (int) ($asignacion['pax_en_mesa'] ?? 0);
        $asignacion['llegadas'] = model(ReservaLlegadaModel::class)->porAsignacion((int) $asignacion['id']);

        return $asignacion;
    }

    public function plano()
    {
        $fecha = $this->request->getGet('fecha') ?? date('Y-m-d');

        $solicitada = (int) $this->request->getGet('sucursal_id');
        if ($solicitada > 0) {
            $this->establecerSucursalSiPermitida($solicitada);
        }

        $sucursalId = (int) session()->get('sucursal_id');
        $codigo     = trim((string) ($this->request->getGet('reserva') ?? ''));
        $nombre     = trim((string) ($this->request->getGet('nombre') ?? ''));

        if ($codigo !== '' && $nombre === '' && $sucursalId > 0) {
            $reserva = (new ReservasApiService())->buscarEnListadoActivo($codigo, $sucursalId, $fecha);
            $nombre  = trim((string) ($reserva['nombre'] ?? ''));
        }

        $sucursal = $sucursalId > 0
            ? model(SucursalModel::class)->find($sucursalId)
            : null;

        return view('hostess/reservas/plano', [
            'titulo'               => 'Plano de mesas',
            'fecha'                => $fecha,
            'sucursalId'           => $sucursalId,
            'sucursalNombre'       => trim((string) ($sucursal['nombre'] ?? '')),
            'reservaCodigo'        => $codigo,
            'reservaNombre'        => $nombre,
            'mostrarTituloTopbar'  => false,
        ]);
    }

    /**
     * Ocupa una mesa libre para invitados sin reserva (walk-in).
     */
    public function ocuparWalkIn()
    {
        $json = $this->request->getJSON(true) ?? [];

        $rules = [
            'mesa_id'        => 'required|integer',
            'fecha'          => 'required|valid_date[Y-m-d]',
            'pax'            => 'required|integer|greater_than[0]',
            'cliente_nombre' => 'permit_empty|max_length[150]',
        ];

        if (! $this->validateData($json, $rules)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'errors'  => $this->validator->getErrors(),
                'error'   => 'Datos inválidos para ocupar la mesa.',
            ]);
        }

        $sucursalId = (int) session()->get('sucursal_id');
        if ($sucursalId <= 0) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'error'   => 'Selecciona un establecimiento.',
            ]);
        }

        $mesaId = (int) $json['mesa_id'];
        $fecha  = (string) $json['fecha'];
        $pax    = (int) $json['pax'];
        $nombre = trim((string) ($json['cliente_nombre'] ?? ''));
        if ($nombre === '') {
            $nombre = 'Invitado';
        }

        $mesa = model(MesaModel::class)->find($mesaId);
        if (! $mesa) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'error'   => 'Mesa no encontrada.',
            ]);
        }

        // Evita ocupar si ya hay reserva/sentados en esa mesa hoy
        $model  = model(ReservaAsignacionModel::class);
        $activa = $model->where('mesa_id', $mesaId)
            ->where('sucursal_id', $sucursalId)
            ->where('fecha', $fecha)
            ->whereIn('estado_mesa', ['reservada', 'ocupada'])
            ->first();

        if ($activa) {
            return $this->response->setStatusCode(409)->setJSON([
                'success' => false,
                'error'   => 'La mesa ya está reservada u ocupada.',
            ]);
        }

        $codigo = $this->generarCodigoWalkIn();
        $ahora  = date('Y-m-d H:i:s');
        $hora   = date('H:i:s');

        $insertOk = $model->insert([
            'reserva_codigo' => $codigo,
            'mesa_id'        => $mesaId,
            'sucursal_id'    => $sucursalId,
            'cliente_nombre' => $nombre,
            'fecha'          => $fecha,
            'hora'           => $hora,
            'pax_reservados' => $pax,
            'pax_en_mesa'    => $pax,
            'estado_mesa'    => 'ocupada',
            'arrived_at'     => $ahora,
            'seated_at'      => $ahora,
            'asignado_por'   => session()->get('usuario_id'),
        ]);

        if (! $insertOk) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'error'   => 'No se pudo ocupar la mesa.',
            ]);
        }

        $id = (int) $model->getInsertID();

        $num = $mesa['numero'] ?? $mesaId;
        (new ReservaActividadService())->registrar(
            $codigo,
            'walk_in',
            "ocupó la mesa #{$num} sin reserva (walk-in, {$pax} pax)",
            [
                'mesa_id' => $mesaId,
                'pax'     => $pax,
                'walk_in' => true,
            ],
            $sucursalId
        );

        model(ReservaLlegadaModel::class)->insert([
            'asignacion_id'  => $id,
            'pax_delta'      => $pax,
            'pax_resultante' => $pax,
            'nota'           => 'Walk-in (sin reserva)',
            'registrado_por' => session()->get('usuario_id'),
            'created_at'     => $ahora,
        ]);

        return $this->response->setJSON([
            'success'    => true,
            'message'    => 'Mesa ocupada para invitado.',
            'asignacion' => $this->enriquecerAsignacion($model->find($id)),
        ]);
    }

    /** Código local único para walk-ins (no viene de OneReservations). */
    private function generarCodigoWalkIn(): string
    {
        return 'WI-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    /** El rol administrador puede operar cualquier sucursal activa. */
    private function esAdmin(): bool
    {
        return session()->get('rol') === 'admin';
    }

    /** IDs de sucursales que el usuario puede ver en filtros y reservas */
    private function idsSucursalesPermitidas(): array
    {
        if ($this->esAdmin()) {
            return model(SucursalModel::class)->idsActivas();
        }

        $sucursales = session()->get('sucursales') ?? [];

        return array_values(array_map(static fn ($s) => (int) $s['id'], $sucursales));
    }

    /** Cambia la sucursal activa si el usuario tiene permiso */
    private function establecerSucursalSiPermitida(int $sucursalId): bool
    {
        if ($sucursalId <= 0) {
            return false;
        }

        if ($this->esAdmin()) {
            $sucursal = model(SucursalModel::class)->obtenerConPais($sucursalId);

            if ($sucursal) {
                session()->set('sucursal_id', $sucursalId);

                return true;
            }

            return false;
        }

        foreach (session()->get('sucursales') ?? [] as $sucursal) {
            if ((int) $sucursal['id'] === $sucursalId) {
                session()->set('sucursal_id', $sucursalId);

                return true;
            }
        }

        return false;
    }

    /** Datos de la sucursal activa desde la sesión */
    private function sucursalActivaPorId(int $sucursalId): ?array
    {
        foreach (session()->get('sucursales') ?? [] as $sucursal) {
            if ((int) $sucursal['id'] === $sucursalId) {
                return $sucursal;
            }
        }

        if ($this->esAdmin() && $sucursalId > 0) {
            return model(SucursalModel::class)->obtenerConPais($sucursalId);
        }

        $sucursales = session()->get('sucursales') ?? [];

        return $sucursales[0] ?? null;
    }
}
