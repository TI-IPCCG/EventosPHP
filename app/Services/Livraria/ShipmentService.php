<?php

namespace App\Services\Livraria;

use App\Models\Livraria\Copy;
use App\Models\Livraria\Product;
use App\Models\Livraria\Shipment;
use App\Models\Livraria\ShipmentItem;
use App\Models\Livraria\Variant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Montar a remessa: dizer o que vem de cada fornecedor, em que quantidade e a
 * que preço — e materializar isso em exemplares com código único.
 *
 * A quantidade da remessa e o número de exemplares andam juntos SEMPRE: quem
 * muda a quantidade para mais ganha exemplares novos; para menos, perde os que
 * ainda estão disponíveis. Exemplar já vendido ou baixado nunca some.
 */
class ShipmentService
{
    public function __construct(private CodeGenerator $codigos) {}

    /**
     * Acrescenta (ou ajusta) uma linha da remessa e acerta os exemplares.
     */
    public function definirItem(
        Shipment $remessa,
        Product $produto,
        ?Variant $variacao,
        int $quantidade,
        float $custoUnitario,
        float $precoVenda,
    ): ShipmentItem {
        if ($quantidade < 1) {
            throw new RuntimeException('A quantidade precisa ser pelo menos 1.');
        }

        // Variação tem de pertencer ao item — senão a camiseta sairia com o
        // tamanho de outro produto.
        if ($variacao && $variacao->product_id !== $produto->id) {
            throw new RuntimeException('Essa variação não é deste item.');
        }

        if ($produto->usaVariacao() && ! $variacao) {
            throw new RuntimeException("Escolha {$produto->category->rotuloVariacao()} para este item.");
        }

        return DB::transaction(function () use ($remessa, $produto, $variacao, $quantidade, $custoUnitario, $precoVenda) {
            $item = ShipmentItem::updateOrCreate(
                [
                    'shipment_id' => $remessa->id,
                    'product_id'  => $produto->id,
                    'variant_id'  => $variacao?->id,
                ],
                [
                    'quantidade'     => $quantidade,
                    'custo_unitario' => $custoUnitario,
                    'preco_venda'    => $precoVenda,
                ],
            );

            $this->sincronizarExemplares($item, $quantidade);

            return $item->refresh();
        });
    }

    /**
     * Faz o número de exemplares bater com a quantidade da linha.
     *
     * Ao reduzir, remove apenas exemplares DISPONÍVEIS — e recusa se a conta
     * não fechar sem mexer no que já saiu. Perder o registro de um exemplar
     * vendido apagaria receita e dívida junto.
     */
    private function sincronizarExemplares(ShipmentItem $item, int $quantidade): void
    {
        $existentes = $item->copies()->count();

        if ($existentes === $quantidade) {
            return;
        }

        if ($existentes < $quantidade) {
            $fornecedor = $item->shipment->supplier;
            $eventId    = $item->shipment->event_id;
            $novos      = $quantidade - $existentes;

            foreach ($this->codigos->gerar($eventId, $fornecedor, $novos) as $codigo) {
                Copy::create([
                    'event_id'         => $eventId,
                    'shipment_item_id' => $item->id,
                    'codigo'           => $codigo,
                    'status'           => Copy::DISPONIVEL,
                ]);
            }

            return;
        }

        $remover     = $existentes - $quantidade;
        $disponiveis = $item->copies()->where('status', Copy::DISPONIVEL)->count();

        if ($disponiveis < $remover) {
            throw new RuntimeException(
                "Não dá para reduzir para {$quantidade}: só {$disponiveis} "
                .($disponiveis == 1 ? 'exemplar está disponível' : 'exemplares estão disponíveis')
                .' e o restante já saiu.'
            );
        }

        $item->copies()
            ->where('status', Copy::DISPONIVEL)
            ->orderByDesc('id')          // tira os últimos, preservando os códigos baixos
            ->limit($remover)
            ->delete();
    }

    /** Remove a linha inteira — só se nenhum exemplar dela tiver saído. */
    public function removerItem(ShipmentItem $item): void
    {
        $sairam = $item->copies()->where('status', '<>', Copy::DISPONIVEL)->count();

        if ($sairam > 0) {
            throw new RuntimeException(
                "Não dá para remover: {$sairam} "
                .($sairam == 1 ? 'exemplar já saiu' : 'exemplares já saíram').'.'
            );
        }

        DB::transaction(function () use ($item) {
            $item->copies()->delete();
            $item->delete();
        });
    }
}
