<?php

namespace App\Services\Livraria;

use App\Models\Livraria\Copy;
use App\Models\Livraria\PaymentMethod;
use App\Models\Livraria\Sale;
use App\Models\Livraria\SaleItem;
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
     * CORRIGIR um lançamento errado.
     *
     * ── QUANDO É ESTE CAMINHO, E NÃO A TROCA ──────────────────────────
     * Aqui o registro estava errado DESDE O INÍCIO: o voluntário tocou no
     * item errado, escolheu a forma de pagamento errada, esqueceu de lançar
     * um livro. Ninguém trocou nada e nenhum dinheiro a mais mudou de mãos.
     *
     * Por isso esta é a única operação que reescreve `valor_bruto` e a taxa:
     * o número que está lá nunca foi verdade. Na troca vale o oposto — ver
     * ExchangeService::trocar().
     *
     * ── POR QUE RECUSA VENDA COM TROCA ────────────────────────────────
     * Recalcular valor_bruto sobre os itens de hoje incluiria o que entrou
     * por troca, e o campo deixaria de significar "o que passou no meio de
     * pagamento naquele dia" — que é justamente o que a troca preservou. Em
     * vez de produzir um número que ninguém consegue explicar no
     * fechamento, recusa e diz o porquê.
     *
     * @param  array<int>  $copyIdsFinais  os exemplares que a venda DEVE ter ao fim
     *
     * @throws RuntimeException quando a venda não pode ser corrigida
     */
    public function corrigir(
        Sale $sale,
        array $copyIdsFinais,
        ?int $paymentMethodId,
        ?string $comprador = null,
        ?int $corrigidoPor = null,
    ): Sale {
        if ($sale->foiCancelada()) {
            throw new RuntimeException('Esta venda foi estornada — não há o que corrigir.');
        }

        if ($sale->exchanges()->exists()) {
            throw new RuntimeException(
                'Esta venda tem troca registrada. Corrigir reescreveria o valor original, '
                .'que precisa continuar batendo com o extrato. Use a troca, ou estorne e refaça.'
            );
        }

        if (empty($copyIdsFinais)) {
            throw new RuntimeException('Uma venda sem itens não é correção, é estorno.');
        }

        return DB::transaction(function () use ($sale, $copyIdsFinais, $paymentMethodId, $comprador, $corrigidoPor) {
            $agora    = $sale->event->agora();
            $desejado = array_values(array_unique(array_map('intval', $copyIdsFinais)));

            $atuais = $sale->items()->whereNull('cancelado_em')->lockForUpdate()->get();
            $tinha  = $atuais->pluck('copy_id')->map('intval')->all();

            // Sai o que não foi pedido; entra o que ainda não está lá. Sem
            // exchange_out_id: quem lê depois distingue correção de troca
            // exatamente por essa ausência (ver SaleItem::motivoDaSaida()).
            $remover = $atuais->whereIn('copy_id', array_diff($tinha, $desejado));
            $incluir = array_values(array_diff($desejado, $tinha));

            if ($remover->isNotEmpty()) {
                SaleItem::whereIn('id', $remover->pluck('id'))->update(['cancelado_em' => $agora]);
                $this->guard->liberar($remover->pluck('copy_id')->all());
            }

            if ($incluir) {
                // ⚠ travar() DEPOIS de liberar: corrigir "M" para "G" e de volta
                // para "M" na mesma operação encontraria o próprio exemplar
                // ainda marcado como vendido e recusaria a correção inteira.
                $novos = $this->guard->travar($sale->event_id, $incluir);
                $novos->load('shipmentItem');

                SaleItem::insert($novos->map(fn (Copy $c) => [
                    'sale_id'        => $sale->id,
                    'copy_id'        => $c->id,
                    'preco'          => $c->shipmentItem->preco_venda,
                    'custo_unitario' => $c->shipmentItem->custo_unitario,
                ])->all());

                $this->guard->marcar($novos, Copy::VENDIDO);
            }

            // O valor da venda passa a ser o dos itens que sobraram, e a taxa
            // é recalculada sobre ele: o lançamento inteiro estava errado.
            $bruto  = round((float) $sale->items()->whereNull('cancelado_em')->sum('preco'), 2);
            $metodo = $paymentMethodId ? PaymentMethod::find($paymentMethodId) : null;

            $sale->update([
                'payment_method_id' => $paymentMethodId,
                'comprador'         => $comprador,
                'valor_bruto'       => $bruto,
                'taxa_percentual'   => $metodo?->taxa_percentual ?? 0,
                'taxa_valor'        => $metodo?->taxaSobre($bruto) ?? 0.0,
                'corrigida_em'      => $agora,
                'corrigida_por'     => $corrigidoPor,
            ]);

            return $sale->refresh();
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

            // ⚠ SÓ os itens ativos. Um item que saiu numa troca já voltou ao
            // estoque e pode ter sido vendido a OUTRA pessoa desde então —
            // liberá-lo de novo aqui devolveria ao estoque um exemplar que
            // está com o comprador seguinte, e o saldo passaria a mentir.
            $ativos = $sale->items()->whereNull('cancelado_em')->get();

            // cancelado_em no item libera a coluna gerada copy_ativo, e é o que
            // permite vender o mesmo exemplar de novo.
            $sale->items()->whereNull('cancelado_em')->update(['cancelado_em' => $agora]);

            $this->guard->liberar($ativos->pluck('copy_id')->all());

            return $sale->refresh();
        });
    }
}
