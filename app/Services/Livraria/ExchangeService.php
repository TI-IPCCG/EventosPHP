<?php

namespace App\Services\Livraria;

use App\Models\Livraria\Copy;
use App\Models\Livraria\Exchange;
use App\Models\Livraria\PaymentMethod;
use App\Models\Livraria\Sale;
use App\Models\Livraria\SaleItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TROCAR itens de uma venda já registrada.
 *
 * ── A REGRA QUE GOVERNA ESTE ARQUIVO ──────────────────────────────────
 * A venda original NÃO é tocada. Quem pagou R$ 50 no PIX aparece no extrato
 * como R$ 50 e a operadora já reteve taxa sobre 50 — trocar por um item de
 * R$ 40 e devolver R$ 10 em dinheiro não desfaz nada disso. Reescrever
 * `valor_bruto`/`taxa_valor` faria o relatório divergir do extrato e
 * subestimar a taxa devida.
 *
 * O que muda são os ITENS (e a receita se corrige sozinha, porque
 * EventResult::receita() soma liv_sale_items). A diferença em dinheiro vira
 * um movimento próprio, com forma de pagamento e taxa próprias.
 *
 * Para "foi digitado errado, ninguém trocou nada", o caminho é outro:
 * SaleService::corrigir(), que reescreve mesmo.
 *
 * ── ORDEM DE GRAVAÇÃO ─────────────────────────────────────────────────
 * Imposta pelos gatilhos da RN09 e pela UNIQUE de copy_ativo:
 *   1. marca os itens que saem (cancelado_em) → libera copy_ativo
 *   2. devolve os exemplares ao estoque       → status 'disponivel'
 *   3. INSERT dos itens que entram            → o gatilho exige 'disponivel'
 *   4. marca os exemplares novos como vendidos
 * Inverter 3 e 4 faz o gatilho recusar a própria linha que acabou de entrar.
 */
class ExchangeService
{
    public function __construct(private StockGuard $guard) {}

    /**
     * @param  array<int>  $saleItemIdsSaindo  itens da venda que o comprador devolveu
     * @param  array<int>  $copyIdsEntrando    exemplares que ele levou no lugar
     *
     * @throws RuntimeException quando a troca não pode acontecer
     */
    public function trocar(
        Sale $sale,
        array $saleItemIdsSaindo,
        array $copyIdsEntrando,
        ?int $paymentMethodId = null,
        ?string $motivo = null,
        ?int $registradoPor = null,
    ): Exchange {
        if ($sale->foiCancelada()) {
            throw new RuntimeException('Esta venda foi estornada — não há o que trocar.');
        }

        if (empty($saleItemIdsSaindo) && empty($copyIdsEntrando)) {
            throw new RuntimeException('Escolha o que sai, o que entra, ou os dois.');
        }

        return DB::transaction(function () use (
            $sale, $saleItemIdsSaindo, $copyIdsEntrando, $paymentMethodId, $motivo, $registradoPor
        ) {
            $saindo = $this->itensQuePodemSair($sale, $saleItemIdsSaindo);

            // Devolução pura — só devolveu e levou o dinheiro — é troca com o
            // lado de entrada vazio, e travar() recusa lista vazia de propósito.
            $entrando = $copyIdsEntrando
                ? $this->guard->travar($sale->event_id, $copyIdsEntrando)
                : Copy::whereRaw('1 = 0')->get();

            $entrando->load('shipmentItem');

            // O que sai vale o PREÇO REGISTRADO NA VENDA, não o preço de hoje:
            // se a camiseta subiu de 40 para 50 depois da compra, devolver a
            // dela a 50 seria dar dinheiro que nunca entrou.
            $valorSaindo = round((float) $saindo->sum(fn (SaleItem $i) => (float) $i->preco), 2);

            // O que entra vale o preço de hoje na remessa — é a mercadoria que
            // está saindo do estoque agora.
            $valorEntrando = round(
                (float) $entrando->sum(fn (Copy $c) => (float) $c->shipmentItem->preco_venda), 2
            );

            $diferenca = round($valorEntrando - $valorSaindo, 2);

            // Taxa só sobre COBRANÇA adicional: devolver troco em dinheiro não
            // paga taxa a operadora nenhuma, e cobrar taxa sobre devolução
            // inventaria um custo que não existe.
            $metodo = $diferenca > 0 && $paymentMethodId
                ? PaymentMethod::find($paymentMethodId)
                : null;

            $exchange = Exchange::create([
                'sale_id'           => $sale->id,
                'event_id'          => $sale->event_id,
                'payment_method_id' => $diferenca == 0.0 ? null : $paymentMethodId,
                'diferenca'         => $diferenca,
                'taxa_percentual'   => $metodo?->taxa_percentual ?? 0,
                'taxa_valor'        => $metodo?->taxaSobre($diferenca) ?? 0.0,
                'motivo'            => $motivo,
                'registrado_por'    => $registradoPor,
                'realizada_em'      => $sale->event->agora(),
                'created_at'        => now(),
            ]);

            // 1 e 2 — o que saiu volta a existir no estoque
            if ($saindo->isNotEmpty()) {
                SaleItem::whereIn('id', $saindo->pluck('id'))->update([
                    'cancelado_em'    => $exchange->realizada_em,
                    'exchange_out_id' => $exchange->id,
                ]);

                $this->guard->liberar($saindo->pluck('copy_id')->all());
            }

            // 3 e 4 — o que entrou sai do estoque
            if ($entrando->isNotEmpty()) {
                SaleItem::insert($entrando->map(fn (Copy $c) => [
                    'sale_id'        => $sale->id,
                    'copy_id'        => $c->id,
                    'preco'          => $c->shipmentItem->preco_venda,
                    'custo_unitario' => $c->shipmentItem->custo_unitario,
                    'exchange_in_id' => $exchange->id,
                ])->all());

                $this->guard->marcar($entrando, Copy::VENDIDO);
            }

            return $exchange->refresh();
        });
    }

    /**
     * Os itens que a venda realmente tem para dar.
     *
     * Pedir um item de outra venda, ou um já devolvido numa troca anterior,
     * é erro de tela ou requisição forjada — nos dois casos a resposta é
     * recusar, não "ignorar o que não achou".
     *
     * @param  array<int>  $ids
     * @return \Illuminate\Database\Eloquent\Collection<int, SaleItem>
     */
    private function itensQuePodemSair(Sale $sale, array $ids)
    {
        if (empty($ids)) {
            return SaleItem::whereRaw('1 = 0')->get();
        }

        $itens = SaleItem::where('sale_id', $sale->id)
            ->whereIn('id', $ids)
            ->whereNull('cancelado_em')
            ->lockForUpdate()
            ->get();

        if ($itens->count() !== count(array_unique($ids))) {
            throw new RuntimeException(
                'Algum item não pertence a esta venda ou já foi devolvido antes.'
            );
        }

        return $itens;
    }
}
