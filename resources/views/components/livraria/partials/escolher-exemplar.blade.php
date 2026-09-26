{{--
    Escolha de exemplar: lista por ITEM, pop-up para o código.

    Usado por Baixas e por Vendas (troca e correção) — as três fazem a mesma
    escolha, e manter uma cópia por tela garantiria que uma delas ficasse para
    trás. A lógica está em App\Support\EscolhaDeExemplar.

    Parâmetros:
      $acaoEscolher  método do componente que RECEBE o copy_id
      $acaoTirar     método que devolve o exemplar ao estoque do rascunho
      $valor         'custo' ou 'preco' — qual número a linha mostra
      $lote          true libera "pegar N de uma vez" no pop-up

    O lote é opcional de propósito: numa TROCA a pergunta é "qual exemplar o
    comprador trouxe", e oferecer quantidade ali só atrapalharia. Num acerto de
    estoque a pergunta é outra — "quantas sumiram" — e escolher dez camisetas
    uma a uma é insuportável.
--}}
@php($campoValor = $valor ?? 'preco')
@php($permiteLote = $lote ?? false)

<div class="venda-busca" style="margin-top:.8rem">
    <i class="bi bi-search"></i>
    <input type="search" wire:model.live.debounce.300ms="buscaEstoque"
           placeholder="Filtrar por nome ou código" aria-label="Buscar no estoque">
    @if ($buscaEstoque !== '')
        <button type="button" wire:click="$set('buscaEstoque', '')" aria-label="Limpar busca">
            <i class="bi bi-x-lg"></i>
        </button>
    @endif
</div>

<ul class="venda-resultados">
    @forelse ($this->estoque as $linha)
        <li wire:key="linha-{{ $linha->shipment_item_id }}">
            <button type="button" wire:click="abrirLinha({{ $linha->shipment_item_id }})">
                @if ($linha->thumb)
                    <img src="{{ Storage::disk('public')->url($linha->thumb) }}" alt="" loading="lazy">
                @else
                    <span class="thumb-vazio"><i class="bi bi-book"></i></span>
                @endif

                <span class="res-info">
                    <strong>
                        <span class="res-nome">{{ $linha->item }}</span>
                        @if ($linha->variacao) <span class="pill">{{ $linha->variacao }}</span> @endif
                    </strong>
                    <small>{{ $linha->categoria }}</small>
                </span>

                <span class="res-preco">
                    R$ {{ number_format($linha->{$campoValor}, 2, ',', '.') }}
                    <small>{{ $linha->disponiveis }}
                        {{ $linha->disponiveis == 1 ? 'disponível' : 'disponíveis' }}</small>
                </span>
            </button>
        </li>
    @empty
        <li class="vazio">
            @if (trim($buscaEstoque) !== '')
                Nada disponível para “{{ $buscaEstoque }}”.
            @else
                Nenhum exemplar disponível neste evento.
            @endif
        </li>
    @endforelse
</ul>

{{-- ── POP-UP: QUAL EXEMPLAR ──────────────────────────────────────
     O código importa — é ele que faz a conferência física bater no fim
     do evento — mas essa escolha não precisa estar na primeira tela.
     Aqui ela vem depois de o item já estar decidido. --}}
@if ($linhaAberta && $this->exemplaresDaLinha->isNotEmpty())
    @php($primeiro = $this->exemplaresDaLinha->first())
    <div class="modal-overlay" wire:click.self="fecharLinha"
         x-data x-on:keydown.escape.window="$wire.fecharLinha()">
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="ex-titulo">
            <div class="modal-header">
                <strong id="ex-titulo">{{ $primeiro->shipmentItem->rotulo() }}</strong>
                <button type="button" class="btn-sm secondary" wire:click="fecharLinha"
                        aria-label="Fechar">✕</button>
            </div>

            <div class="modal-body">
                @if ($permiteLote)
                    @php($livres = $this->exemplaresDaLinha->reject(fn ($c) => $this->jaEscolhido($c->id))->count())
                    <div class="lote-rapido">
                        <label for="lote-qtd">Pegar de uma vez</label>
                        <div class="lote-campos">
                            <input id="lote-qtd" type="number" min="1" max="{{ max(1, $livres) }}"
                                   wire:model="quantidadeLote" inputmode="numeric">
                            <button type="button" class="btn-sm"
                                    wire:click="adicionarVarios({{ $linhaAberta }}, $wire.quantidadeLote)"
                                    @disabled($livres === 0)>
                                Adicionar
                            </button>
                        </div>
                        <small class="ajuda">
                            {{ $livres }} {{ $livres == 1 ? 'livre' : 'livres' }} nesta linha.
                            O sistema escolhe pelos códigos mais altos, preservando os primeiros.
                        </small>
                    </div>
                @endif

                <p class="venda-dica">
                    {{ $permiteLote ? 'Ou escolha um a um:' : 'Escolha o exemplar.' }}
                </p>

                <ul class="resumo-itens">
                    @foreach ($this->exemplaresDaLinha as $c)
                        @php($escolhido = $this->jaEscolhido($c->id))
                        <li wire:key="ex-{{ $c->id }}">
                            <span class="resumo-item-nome">
                                @if ($c->shipmentItem->product->coverPhoto)
                                    <img class="cart-thumb"
                                         src="{{ $c->shipmentItem->product->coverPhoto->urlThumb() }}"
                                         alt="" loading="lazy">
                                @else
                                    <span class="cart-thumb vazia"><i class="bi bi-book"></i></span>
                                @endif
                                <span>
                                    <strong class="codigo">{{ $c->codigo }}</strong>
                                    @if ($escolhido) <span class="pill open">escolhido</span> @endif
                                </span>
                            </span>
                            <span class="valor">
                                @if ($escolhido)
                                    <button type="button" class="btn-sm btn-ghost"
                                            wire:click="{{ $acaoTirar }}({{ $c->id }})">
                                        Tirar
                                    </button>
                                @else
                                    <button type="button" class="btn-sm"
                                            wire:click="{{ $acaoEscolher }}({{ $c->id }})">
                                        Escolher
                                    </button>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="venda-acoes" style="padding:0 1rem 1rem">
                <button type="button" wire:click="fecharLinha">Pronto</button>
            </div>
        </div>
    </div>
@endif
