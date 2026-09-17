<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Livraria\Copy;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

/**
 * Escolher exemplar do estoque: por ITEM primeiro, pelo código depois.
 *
 * ── O PROBLEMA QUE ISTO RESOLVE ───────────────────────────────────────
 * Listar exemplar por exemplar mostra o modelo de dados, não o que a mesa
 * enxerga: 25 camisetas M viram 25 cartões de códigos quase idênticos, e os
 * outros títulos ficam enterrados embaixo.
 *
 * Então a lista agrupa por item — com capa e quantos restam — e o código sai
 * num segundo passo. Ele NÃO some do fluxo, e não podia: é o código que faz a
 * conferência física do estoque bater no fim do evento, porque o sistema
 * precisa saber qual unidade saiu da caixa.
 *
 * ── POR QUE TRAIT, E NÃO CÓPIA ────────────────────────────────────────
 * Baixas e Vendas (troca e correção) fazem a MESMA escolha, com a mesma
 * armadilha: esquecer o filtro de evento ou de status oferece exemplar de
 * outro evento, ou já vendido — e o erro só aparece lá no serviço, depois de
 * o operador ter escolhido. Uma consulta só, num lugar só.
 *
 * Quem usa precisa dizer QUAIS exemplares já estão no rascunho
 * (exemplaresJaEscolhidos) e como reconhecê-los na tela (jaEscolhido).
 */
trait EscolhaDeExemplar
{
    public string $buscaEstoque = '';

    /** Linha de estoque aberta no pop-up, onde se escolhe QUAL exemplar. */
    public ?int $linhaAberta = null;

    /** Os exemplares já no rascunho — saem da lista e da contagem. */
    abstract protected function exemplaresJaEscolhidos(): array;

    /** Se este exemplar já está no rascunho (o pop-up mostra marcado). */
    abstract public function jaEscolhido(int $copyId): bool;

    /** O evento cujo estoque está em jogo. */
    abstract protected function eventoDaEscolha(): ?Event;

    /**
     * Estoque livre, agrupado por item.
     *
     * Uma consulta só, agregando: contar exemplar a exemplar viraria N+1 na
     * mão do voluntário, em 4G, com fila esperando.
     *
     * Sem busca já lista — exigir que se digite deixa a tela em branco e
     * ninguém adivinha o que fazer.
     */
    #[Computed]
    public function estoque()
    {
        $evento = $this->eventoDaEscolha();

        if (! $evento) {
            return collect();
        }

        $termo      = trim($this->buscaEstoque);
        $escolhidos = $this->exemplaresJaEscolhidos();

        return DB::table('liv_copies as c')
            ->join('liv_shipment_items as si', 'si.id', '=', 'c.shipment_item_id')
            ->join('liv_products as p', 'p.id', '=', 'si.product_id')
            ->join('liv_categories as cat', 'cat.id', '=', 'p.category_id')
            ->leftJoin('liv_variants as v', 'v.id', '=', 'si.variant_id')
            ->leftJoin('liv_product_photos as f', function ($j) {
                $j->on('f.product_id', '=', 'p.id')->where('f.capa', '=', 1);
            })
            ->where('c.event_id', $evento->id)
            ->where('c.status', Copy::DISPONIVEL)
            ->when($escolhidos, fn ($q) => $q->whereNotIn('c.id', $escolhidos))
            // ⚠ O termo vai num grupo próprio. Solto, o orWhere escaparia dos
            // filtros de evento e status, e a lista ofereceria exemplar de
            // outro evento — ou já vendido.
            ->when(mb_strlen($termo) >= 2, fn ($q) => $q->where(function ($q) use ($termo) {
                $q->where('p.nome', 'like', "%{$termo}%")
                  ->orWhere('c.codigo', 'like', "{$termo}%");
            }))
            ->groupBy('si.id', 'p.nome', 'cat.nome', 'v.nome', 'v.ordem',
                      'si.custo_unitario', 'si.preco_venda', 'f.caminho_thumb')
            // desempate por id: sem ele, duas variações com a mesma ordem saem
            // embaralhadas e P/M/G trocam de lugar a cada busca
            ->orderBy('p.nome')->orderBy('v.ordem')->orderBy('v.id')
            ->selectRaw('si.id as shipment_item_id, p.nome as item, cat.nome as categoria,
                         v.nome as variacao, si.custo_unitario as custo,
                         si.preco_venda as preco, f.caminho_thumb as thumb,
                         COUNT(*) as disponiveis')
            ->limit(60)
            ->get();
    }

    /**
     * Os exemplares da linha aberta, COM os que já estão no rascunho.
     *
     * Os escolhidos ficam na lista, marcados: sumir ao ser tocado faria a
     * lista pular sob o dedo e esconderia o que se acabou de fazer.
     */
    #[Computed]
    public function exemplaresDaLinha()
    {
        $evento = $this->eventoDaEscolha();

        if (! $this->linhaAberta || ! $evento) {
            return collect();
        }

        return Copy::where('event_id', $evento->id)
            ->where('shipment_item_id', $this->linhaAberta)
            ->where(fn ($q) => $q
                ->where('status', Copy::DISPONIVEL)
                ->orWhereIn('id', $this->exemplaresJaEscolhidos() ?: [0]))
            ->with('shipmentItem.product.coverPhoto', 'shipmentItem.variant')
            ->orderBy('codigo')
            ->get();
    }

    public function abrirLinha(int $shipmentItemId): void
    {
        $this->linhaAberta = $shipmentItemId;
        unset($this->exemplaresDaLinha);
    }

    public function fecharLinha(): void
    {
        $this->linhaAberta = null;
        $this->buscaEstoque = '';
        unset($this->estoque, $this->exemplaresDaLinha);
    }

    /** Depois de mexer no rascunho, as duas listas precisam ser refeitas. */
    protected function recalcularEscolha(): void
    {
        unset($this->estoque, $this->exemplaresDaLinha);
    }
}
