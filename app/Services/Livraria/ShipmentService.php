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

        $remover = $existentes - $quantidade;

        // ⚠ "Disponível" não é o mesmo que "apagável": um exemplar de venda
        // estornada volta a ficar disponível, mas continua referenciado em
        // liv_sale_items, e a FK RESTRICT recusa o DELETE. Contar disponíveis
        // e apagar em seguida estourava SQLSTATE 23000 na cara do usuário.
        $removiveis = $this->semHistorico($item->copies()->where('status', Copy::DISPONIVEL));
        $quantos    = (clone $removiveis)->count();

        if ($quantos < $remover) {
            throw new RuntimeException(
                "Não dá para reduzir para {$quantidade}: só {$quantos} "
                .($quantos == 1 ? 'exemplar pode ser retirado' : 'exemplares podem ser retirados')
                .' — o restante já saiu ou já esteve em alguma venda, mesmo que estornada.'
            );
        }

        $alvos = (clone $removiveis)
            ->orderByDesc('id')          // tira os últimos, preservando os códigos baixos
            ->limit($remover)
            ->pluck('id');

        Copy::whereIn('id', $alvos)->delete();
    }

    /**
     * Remove a linha inteira — só se nenhum exemplar dela tiver história.
     *
     * ⚠ Olhar só o STATUS não basta, e essa lacuna chegou à mesa como um erro
     * de SQL cru na tela. O estorno é soft: ele devolve o exemplar para
     * 'disponivel' mas MANTÉM a linha em liv_sale_items (com cancelado_em),
     * porque apagá-la apagaria o histórico da venda. Então, depois de um
     * estorno, o status diz "pode remover" e a FK ON DELETE RESTRICT diz
     * "não pode" — e quem recebia a discordância era o usuário, em SQLSTATE.
     *
     * A pergunta certa não é "o exemplar está disponível?", é "este exemplar
     * já apareceu em alguma venda ou baixa, ainda que cancelada?".
     */
    public function removerItem(ShipmentItem $item): void
    {
        $sairam = $item->copies()->where('status', '<>', Copy::DISPONIVEL)->count();

        if ($sairam > 0) {
            throw new RuntimeException(
                "Não dá para remover: {$sairam} "
                .($sairam == 1 ? 'exemplar já saiu' : 'exemplares já saíram').'.'
            );
        }

        $comHistorico = $this->exemplaresComHistorico($item);

        if ($comHistorico > 0) {
            throw new RuntimeException(
                "Não dá para remover: {$comHistorico} "
                .($comHistorico == 1 ? 'exemplar já esteve' : 'exemplares já estiveram')
                .' em venda ou baixa — mesmo estornada, a venda continua no histórico e '
                .'apagar o exemplar apagaria o registro dela. '
                .'Para acertar custo, preço ou quantidade, use o botão Editar da linha.'
            );
        }

        DB::transaction(function () use ($item) {
            $item->copies()->delete();
            $item->delete();
        });
    }

    /**
     * Exemplares desta linha que aparecem em alguma venda ou baixa — incluindo
     * as canceladas, que são exatamente as que o status já não denuncia.
     */
    private function exemplaresComHistorico(ShipmentItem $item): int
    {
        return $this->comHistorico($item->copies())->count();
    }

    /** Filtra a consulta de exemplares para os que TÊM história. */
    private function comHistorico($query)
    {
        return $query->where(fn ($q) => $q
            ->whereExists(fn ($s) => $s->select(DB::raw(1))
                ->from('liv_sale_items as si')->whereColumn('si.copy_id', 'liv_copies.id'))
            ->orWhereExists(fn ($s) => $s->select(DB::raw(1))
                ->from('liv_writeoff_items as wi')->whereColumn('wi.copy_id', 'liv_copies.id')));
    }

    /** Filtra para os exemplares que NÃO têm história — os que podem sumir. */
    private function semHistorico($query)
    {
        return $query
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))
                ->from('liv_sale_items as si')->whereColumn('si.copy_id', 'liv_copies.id'))
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))
                ->from('liv_writeoff_items as wi')->whereColumn('wi.copy_id', 'liv_copies.id'));
    }
}
