<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Consumo enviado a la auditoría de One Reservations al liberar la mesa.
 */
class XetuxOrdenConsumos extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('tm_reserva_xetux_ordenes')) {
            return;
        }

        $campos = [];
        if (! $this->db->fieldExists('consumo_total', 'tm_reserva_xetux_ordenes')) {
            $campos['consumo_total'] = [
                'type'       => 'DECIMAL',
                'constraint' => '12,2',
                'null'       => true,
                'after'      => 'estatus',
            ];
        }
        if (! $this->db->fieldExists('consumos_json', 'tm_reserva_xetux_ordenes')) {
            $campos['consumos_json'] = ['type' => 'JSON', 'null' => true, 'after' => 'consumo_total'];
        }
        if (! $this->db->fieldExists('consumos_enviados_at', 'tm_reserva_xetux_ordenes')) {
            $campos['consumos_enviados_at'] = ['type' => 'DATETIME', 'null' => true, 'after' => 'consumos_json'];
        }

        if ($campos !== []) {
            $this->forge->addColumn('tm_reserva_xetux_ordenes', $campos);
        }
    }

    public function down()
    {
        foreach (['consumos_enviados_at', 'consumos_json', 'consumo_total'] as $campo) {
            if ($this->db->fieldExists($campo, 'tm_reserva_xetux_ordenes')) {
                $this->forge->dropColumn('tm_reserva_xetux_ordenes', $campo);
            }
        }
    }
}
