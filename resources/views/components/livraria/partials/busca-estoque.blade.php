{{--
    Busca de exemplar no estoque livre, compartilhada pela correção e pela
    troca. `$acao` é o método do componente que recebe o copy_id.

    Aqui se escolhe UM exemplar específico, ao contrário da mesa — onde o
    voluntário escolhe "Camiseta · M" e o sistema sorteia qual sai. Neste
    ponto o operador tem o item na mão e o código dele é o que importa.
--}}
<div class="venda-busca" style="margin-top:.8rem">
    <i class="bi bi-search"></i>
    <input type="search" wire:model.live.debounce.300ms="buscaEstoque"
           placeholder="Acrescentar item: nome ou código" aria-label="Buscar no estoque">
</div>

@if ($this->estoque->isNotEmpty())
    <ul class="resumo-itens">
        @foreach ($this->estoque as $c)
            <li wire:key="est-{{ $c->id }}">
                <span>
                    {{ $c->shipmentItem->rotulo() }}
                    <small class="codigo">{{ $c->codigo }}</small>
                </span>
                <span class="valor">
                    R$ {{ number_format($c->shipmentItem->preco_venda, 2, ',', '.') }}
                    <button type="button" class="btn-sm btn-ghost" wire:click="{{ $acao }}({{ $c->id }})">
                        Adicionar
                    </button>
                </span>
            </li>
        @endforeach
    </ul>
@elseif (mb_strlen(trim($buscaEstoque)) >= 2)
    <p class="vazio">Nenhum exemplar livre para “{{ $buscaEstoque }}”.</p>
@endif
