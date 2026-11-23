<?php

namespace App\Services;

use App\Models\AmbienteModel;
use App\Models\FloorplanAmbienteModel;

/**
 * Ambientes (áreas) de un floorplan: los que muestra el editor y el alta
 * desde el botón «+» de la barra de ambientes.
 */
class FloorplanAmbientesService
{
    /**
     * Ambientes que muestra el editor: los ligados al floorplan; si no tiene,
     * el ambiente principal del floorplan; si tampoco, los globales activos.
     */
    public function delEditor(array $floorplan): array
    {
        $ambientes = model(FloorplanAmbienteModel::class)->ambientesDe((int) $floorplan['id']);

        if ($ambientes !== []) {
            return $ambientes;
        }

        if (! empty($floorplan['ambiente_id'])) {
            $uno = model(AmbienteModel::class)
                ->select('id, descripcion')
                ->find((int) $floorplan['ambiente_id']);

            if ($uno) {
                return [$uno];
            }
        }

        return model(AmbienteModel::class)->listarActivos();
    }

    /**
     * Ambientes de la sucursal que aún no están en el floorplan (opciones del «+»).
     */
    public function disponibles(array $floorplan): array
    {
        $actuales = array_map(static fn ($a) => (int) $a['id'], $this->delEditor($floorplan));
        $opciones = model(AmbienteModel::class)->paraSucursal((int) $floorplan['sucursal_id']);

        $libres = array_filter($opciones, static fn ($a) => ! in_array((int) $a['id'], $actuales, true));

        return array_values(array_map(static fn ($a) => [
            'id'          => (int) $a['id'],
            'descripcion' => $a['descripcion'],
        ], $libres));
    }

    /**
     * Liga un ambiente al floorplan. Recibe un ambiente existente de la
     * sucursal o una descripción nueva (se crea como ambiente propio de la
     * sucursal, o se reutiliza si ya existe uno con el mismo nombre).
     *
     * @return array{status:int, error?:string, ambiente?:array}
     */
    public function agregar(array $floorplan, ?int $ambienteId, ?string $descripcion): array
    {
        $floorplanId  = (int) $floorplan['id'];
        $sucursalId   = (int) $floorplan['sucursal_id'];
        $descripcion  = trim((string) $descripcion);
        $ambienteModel = model(AmbienteModel::class);
        $opciones     = $ambienteModel->paraSucursal($sucursalId);

        $ambiente = null;

        if ($ambienteId) {
            foreach ($opciones as $a) {
                if ((int) $a['id'] === $ambienteId) {
                    $ambiente = $a;
                    break;
                }
            }
            if ($ambiente === null) {
                return ['status' => 422, 'error' => 'El ambiente no está disponible para esta sucursal.'];
            }
        } else {
            $largo = mb_strlen($descripcion);
            if ($largo < 2 || $largo > 150) {
                return ['status' => 422, 'error' => 'Escribe un nombre de 2 a 150 caracteres.'];
            }

            foreach ($opciones as $a) {
                if (mb_strtolower(trim($a['descripcion'])) === mb_strtolower($descripcion)) {
                    $ambiente = $a;
                    break;
                }
            }

            if ($ambiente === null) {
                $nuevoId = $ambienteModel->insert([
                    'sucursal_id' => $sucursalId,
                    'descripcion' => $descripcion,
                    'estatus'     => 'activo',
                ]);
                if (! $nuevoId) {
                    return ['status' => 500, 'error' => 'No se pudo crear el ambiente.'];
                }
                $ambiente = ['id' => (int) $nuevoId, 'descripcion' => $descripcion];
            }
        }

        $relacion = model(FloorplanAmbienteModel::class);
        $ligados  = $relacion->idsDe($floorplanId);

        // Si el floorplan aún no tenía ambientes ligados, el editor mostraba
        // los de respaldo: se guardan para que no desaparezcan al agregar uno.
        if ($ligados === []) {
            $ligados = array_map(static fn ($a) => (int) $a['id'], $this->delEditor($floorplan));
        }

        $id = (int) $ambiente['id'];
        if (in_array($id, $ligados, true)) {
            return ['status' => 409, 'error' => 'Ese ambiente ya está en el floorplan.'];
        }

        $ligados[] = $id;
        $relacion->sincronizar($floorplanId, $ligados);

        return [
            'status'   => 200,
            'ambiente' => ['id' => $id, 'descripcion' => $ambiente['descripcion']],
        ];
    }
}
