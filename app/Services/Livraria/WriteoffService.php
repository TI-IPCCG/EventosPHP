<?php

namespace App\Services\Livraria;

use App\Models\Livraria\Copy;
use App\Models\Livraria\Writeoff;
use App\Models\Livraria\WriteoffReason;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Baixa de exemplar sem venda: sorteio, cortesia, doação, perda.
 *
 * A regra que mais importa aqui: exemplar consignado que sai NÃO volta ao
 * fornecedor, logo é devido a ele como se tivesse sido vendido. Quem decide
 * isso é o motivo (gera_custo), e o valor é copiado para o item — se amanhã
 * alguém desmarcar o custo do motivo, o acerto de um evento fechado não muda.
 */
class WriteoffService
{
    public function __construct(private StockGuard $guard) {}

    /**
     * Uma baixa, vários exemplares: sortear três livros informa motivo e
     * autorização UMA vez.
     *
     * @param  array<int>  $copyIds
     */
    public function registrar(
        int $eventId,
        array $copyIds,
        int $reasonId,
        string $autorizadoPor,
        ?string $observacao = null,
        ?int $registradoPor = null,
    ): Writeoff {
        return DB::transaction(function () use ($eventId, $copyIds, $reasonId, $autorizadoPor, $observacao, $registradoPor) {
            $motivo = WriteoffReason::withoutGlobalScopes()->findOrFail($reasonId);
            $copies = $this->guard->travar($eventId, $copyIds);
            $copies->load('shipmentItem');

            $writeoff = Writeoff::create([
                'event_id'       => $eventId,
                'reason_id'      => $motivo->id,
                'autorizado_por' => $autorizadoPor,
                'registrado_por' => $registradoPor,
                'observacao'     => $observacao,
                'registrada_em'  => now(),
                'created_at'     => now(),
            ]);

            // ⚠ item ANTES do status (gatilhos do banco).
            $writeoff->items()->createMany(
                $copies->map(fn (Copy $c) => [
                    'copy_id'        => $c->id,
                    'custo_unitario' => $c->shipmentItem->custo_unitario,
                    'gera_custo'     => $motivo->gera_custo,   // snapshot
                ])->all()
            );

            $this->guard->marcar($copies, Copy::BAIXADO);

            return $writeoff->load('items.copy');
        });
    }

    /** Cancela a baixa registrada por engano e devolve os exemplares. */
    public function cancelar(Writeoff $writeoff, ?int $canceladaPor = null): Writeoff
    {
        if ($writeoff->foiCancelada()) {
            throw new RuntimeException('Esta baixa já foi cancelada.');
        }

        return DB::transaction(function () use ($writeoff, $canceladaPor) {
            $agora = now();

            $writeoff->update(['cancelada_em' => $agora, 'cancelada_por' => $canceladaPor]);
            $writeoff->items()->update(['cancelado_em' => $agora]);
            $this->guard->liberar($writeoff->items()->pluck('copy_id')->all());

            return $writeoff->refresh();
        });
    }
}
