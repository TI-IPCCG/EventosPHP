<?php

namespace App\Services\Livraria;

use App\Models\Livraria\Copy;
use App\Models\Livraria\PaymentMethod;
use App\Models\Livraria\Sale;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registrar e estornar venda.
 *
 * Duas regras moram aqui, e não na tela:
 *   · a taxa incide sobre o valor da TRANSAÇÃO, uma vez, não por item
 *   · preço e custo são copiados (snapshot) da remessa para o item vendido
 */
class SaleService
{
    public function __construct(private StockGuard $guard) {}

    /**
     * @param  array<int>  $copyIds  exemplares que o comprador levou
     */
    public function registrar(
        int $eventId,
        array $copyIds,
        ?int $paymentMethodId,
        ?string $comprador = null,
        ?int $registradoPor = null,
        ?\DateTimeInterface $vendidaEm = null,   // preenchido no lançamento retroativo
    ): Sale {
        return DB::transaction(function () use ($eventId, $copyIds, $paymentMethodId, $comprador, $registradoPor, $vendidaEm) {
            $copies = $this->guard->travar($eventId, $copyIds);

            // Snapshot: o preço e o custo são os da remessa daquele evento,
            // não o preço de tabela de hoje.
            $copies->load('shipmentItem');

            $itens = $copies->map(fn (Copy $c) => [
                'copy_id'        => $c->id,
                'preco'          => $c->shipmentItem->preco_venda,
                'custo_unitario' => $c->shipmentItem->custo_unitario,
            ]);

            $bruto = $itens->sum(fn ($i) => (float) $i['preco']);

            $metodo    = $paymentMethodId ? PaymentMethod::find($paymentMethodId) : null;
            $percentual = $metodo?->taxa_percentual ?? 0;
            $taxa       = $metodo?->taxaSobre($bruto) ?? 0.0;

            $sale = Sale::create([
                'event_id'          => $eventId,
                'payment_method_id' => $paymentMethodId,
                'registrado_por'    => $registradoPor,
                'comprador'         => $comprador,
                'valor_bruto'       => $bruto,
                'taxa_percentual'   => $percentual,
                'taxa_valor'        => $taxa,
                'vendida_em'        => $vendidaEm ?? now(),
                'created_at'        => now(),
            ]);

            // ⚠ item ANTES do status: os gatilhos exigem exemplar 'disponivel'
            // no momento do INSERT.
            $sale->items()->createMany($itens->all());
            $this->guard->marcar($copies, Copy::VENDIDO);

            return $sale->load('items.copy');
        });
    }

    /**
     * Estorna a venda: devolve os exemplares ao estoque e tira a venda das
     * somas do resultado. Soft — a venda cancelada continua consultável.
     */
    public function estornar(Sale $sale, ?int $canceladaPor = null): Sale
    {
        if ($sale->foiCancelada()) {
            throw new RuntimeException('Esta venda já foi estornada.');
        }

        return DB::transaction(function () use ($sale, $canceladaPor) {
            $agora = now();

            $sale->update(['cancelada_em' => $agora, 'cancelada_por' => $canceladaPor]);

            // cancelado_em no item libera a coluna gerada copy_ativo, e é o que
            // permite vender o mesmo exemplar de novo.
            $sale->items()->update(['cancelado_em' => $agora]);

            $this->guard->liberar($sale->items()->pluck('copy_id')->all());

            return $sale->refresh();
        });
    }
}
