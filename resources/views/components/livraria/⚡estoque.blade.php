<?php

use App\Models\Event;
use App\Models\Livraria\Copy;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Saldo do evento por linha de estoque (item + variação).
 *
 * O saldo sai de COUNT sobre os exemplares — não existe campo de quantidade
 * que possa desencontrar do que está fisicamente na caixa.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Estoque — Eventos IPCCG')]
class extends Component {
    public string $busca = '';

    #[Computed]
    public function evento(): ?Event
    {
        return Event::atual();
    }

    #[Computed]
    public function linhas()
    {
        if (! $this->evento) {
            return collect();
        }

        return DB::table('liv_shipment_items as si')
            ->join('liv_shipments as s', 's.id', '=', 'si.shipment_id')
            ->join('liv_products as p', 'p.id', '=', 'si.product_id')
            ->join('liv_categories as cat', 'cat.id', '=', 'p.category_id')
            ->join('liv_suppliers as f', 'f.id', '=', 's.supplier_id')
            ->leftJoin('liv_variants as v', 'v.id', '=', 'si.variant_id')
            ->leftJoin('liv_copies as c', 'c.shipment_item_id', '=', 'si.id')
            ->where('s.event_id', $this->evento->id)
            ->when(trim($this->busca) !== '', fn ($q) => $q->where('p.nome', 'like', '%'.trim($this->busca).'%'))
            ->groupBy('si.id', 'p.id', 'p.nome', 'p.observacoes', 'cat.nome', 'cat.ordem',
                      'f.nome', 'v.nome', 'v.ordem', 'si.preco_venda')
            ->orderBy('cat.ordem')->orderBy('p.nome')->orderBy('v.ordem')->orderBy('v.id')
            ->selectRaw("p.id as product_id, p.nome as item, p.observacoes,
                         cat.nome as categoria, f.nome as fornecedor,
                         v.nome as variacao, si.preco_venda as preco,
                         COUNT(c.id) as enviados,
                         SUM(c.status = 'disponivel') as disponivel,
                         SUM(c.status = 'vendido')    as vendido,
                         SUM(c.status = 'baixado')    as baixado")
            ->get();
    }
}; ?>

<div class="estoque">
    @if (! $this->evento)
        <div class="empty-state">
            <i class="bi bi-box"></i>
            <h2>Nenhum evento ativo</h2>
            <p>Escolha um evento para ver o estoque.</p>
        </div>
    @else
        <header class="painel-header">
            <div>
                <span class="painel-evento">{{ $this->evento->nome }}</span>
                <h1>Estoque</h1>
            </div>
        </header>

        <div class="venda-busca">
            <i class="bi bi-search"></i>
            <input type="search" wire:model.live.debounce.250ms="busca"
                   placeholder="Filtrar por item" aria-label="Filtrar por item">
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Fornecedor</th>
                        <th class="num">Preço</th>
                        <th class="num">Enviados</th>
                        <th class="num">Disponível</th>
                        <th class="num">Vendido</th>
                        <th class="num">Baixado</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->linhas as $l)
                        <tr>
                            <td>
                                {{-- atalho para o cadastro: quem confere o estoque é quem
                                     percebe o dado errado, e corrigir ali mesmo evita a
                                     caça ao item na tela de catálogo --}}
                                @can('livraria.catalogo')
                                    <a href="{{ route('livraria.catalogo', ['editando' => $l->product_id]) }}"
                                       class="link-item" title="Editar “{{ $l->item }}” no catálogo">{{ $l->item }}&nbsp;<i class="bi bi-pencil-square"></i></a>
                                @else
                                    {{ $l->item }}
                                @endcan
                                @if ($l->variacao) <span class="pill">{{ $l->variacao }}</span> @endif
                                <small class="bloco">{{ $l->categoria }}</small>
                                @if ($l->observacoes)
                                    <small class="bloco obs">{{ Str::limit($l->observacoes, 70) }}</small>
                                @endif
                            </td>
                            <td>{{ $l->fornecedor }}</td>
                            <td class="num">R$ {{ number_format($l->preco, 2, ',', '.') }}</td>
                            <td class="num">{{ $l->enviados }}</td>
                            <td class="num {{ $l->disponivel == 0 ? 'critico' : '' }}">{{ $l->disponivel }}</td>
                            <td class="num">{{ $l->vendido }}</td>
                            <td class="num">{{ $l->baixado }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="vazio">Nada na remessa deste evento ainda.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
