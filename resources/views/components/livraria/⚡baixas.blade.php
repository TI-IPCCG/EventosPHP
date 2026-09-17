<?php

use App\Models\Event;
use App\Models\Livraria\Copy;
use App\Models\Livraria\Writeoff;
use App\Models\Livraria\WriteoffReason;
use App\Services\Livraria\WriteoffService;
use App\Support\Paginacao;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * BAIXA SEM VENDA — sorteio, cortesia, doação, perda.
 *
 * O exemplar sai do estoque e não gera receita. Sem esta tela ele ficava
 * eternamente "disponível" no saldo, e o acerto do fornecedor fechava com um
 * item que já não existe — ou alguém "vendia" por R$ 0 para dar baixa, o que
 * inventa uma venda que nunca houve e suja a conferência do caixa.
 *
 * ── O QUE O MOTIVO DECIDE ─────────────────────────────────────────────
 * `gera_custo` é a regra virada dado: exemplar consignado que sai NÃO volta
 * ao fornecedor, então é devido a ele como se tivesse sido vendido. A exceção
 * é o exemplar que a própria editora doou para o sorteio — aí nada é devido.
 * O valor é copiado para o item na hora, então desmarcar o motivo amanhã não
 * mexe no acerto de um evento já fechado.
 *
 * ── POR QUE EXEMPLAR, E NÃO "3 UNIDADES DE X" ─────────────────────────
 * Quem sorteia tem o livro na mão, com a etiqueta. O código diz QUAL saiu, e
 * é isso que faz a conferência física do estoque bater no fim do evento.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Baixas — Eventos IPCCG')]
class extends Component {
    use Paginacao;

    private const ORDENS = [
        'recentes' => 'Mais recentes',
        'antigas'  => 'Mais antigas',
    ];

    private const POR_PAGINA_MIN = 5;
    private const POR_PAGINA_MAX = 50;

    #[Url(except: 'recentes')]
    public string $ordem = 'recentes';

    #[Url(except: 20)]
    public int $porPagina = 20;

    /** rascunho da baixa */
    public ?int $reason_id = null;
    public string $autorizado_por = '';
    public string $observacao = '';
    public array $escolhidos = [];
    public string $buscaEstoque = '';

    /** Linha de estoque aberta no pop-up, para escolher QUAL exemplar sai. */
    public ?int $linhaAberta = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('ver-livraria'), 403);
    }

    public function updatedOrdem(): void     { $this->resetPage(); }
    public function updatedPorPagina(): void { $this->resetPage(); }

    public function ordensDisponiveis(): array { return self::ORDENS; }

    /** Entrada de usuário vinda da URL: sem teto, ?porPagina=100000 derruba a tela. */
    private function tamanhoDaPagina(): int
    {
        return max(self::POR_PAGINA_MIN, min(self::POR_PAGINA_MAX, $this->porPagina));
    }

    #[Computed]
    public function evento(): ?Event
    {
        return Event::atual();
    }

    #[Computed]
    public function motivos()
    {
        return WriteoffReason::ativos()->get();
    }

    #[Computed]
    public function motivoEscolhido(): ?WriteoffReason
    {
        return $this->reason_id ? $this->motivos->firstWhere('id', $this->reason_id) : null;
    }

    /** Os exemplares já marcados para esta baixa. */
    #[Computed]
    public function selecionados()
    {
        return $this->escolhidos
            ? Copy::with('shipmentItem.product', 'shipmentItem.variant')
                ->whereIn('id', $this->escolhidos)->orderBy('codigo')->get()
            : collect();
    }

    /**
     * Quanto esta baixa vai custar ao evento.
     *
     * É o número que muda a decisão: sortear cinco livros consignados de R$ 30
     * é R$ 150 devidos ao fornecedor, e quem autoriza precisa ver isso ANTES
     * de confirmar — não no fechamento.
     */
    #[Computed]
    public function custoPrevisto(): float
    {
        if (! $this->motivoEscolhido?->gera_custo) {
            return 0.0;
        }

        return round(
            (float) $this->selecionados->sum(fn (Copy $c) => (float) $c->shipmentItem->custo_unitario),
            2
        );
    }

    /**
     * O estoque livre agrupado por ITEM, como a mesa enxerga.
     *
     * Vinte e cinco camisetas M são vinte e cinco linhas no banco e UMA coisa
     * na cabeça de quem opera. Listar exemplar por exemplar enterrava os
     * outros títulos sob uma parede de códigos quase idênticos.
     *
     * Qual exemplar sai continua importando — é o código que faz a conferência
     * física bater no fim — mas essa escolha desce um nível, para o pop-up.
     *
     * Uma consulta só, agregando: contar exemplar a exemplar viraria N+1 na
     * mão do voluntário. Mesmo desenho da busca da mesa.
     */
    #[Computed]
    public function estoque()
    {
        if (! $this->evento) {
            return collect();
        }

        $termo = trim($this->buscaEstoque);

        return DB::table('liv_copies as c')
            ->join('liv_shipment_items as si', 'si.id', '=', 'c.shipment_item_id')
            ->join('liv_products as p', 'p.id', '=', 'si.product_id')
            ->join('liv_categories as cat', 'cat.id', '=', 'p.category_id')
            ->leftJoin('liv_variants as v', 'v.id', '=', 'si.variant_id')
            ->leftJoin('liv_product_photos as f', function ($j) {
                $j->on('f.product_id', '=', 'p.id')->where('f.capa', '=', 1);
            })
            ->where('c.event_id', $this->evento->id)
            ->where('c.status', Copy::DISPONIVEL)
            ->when($this->escolhidos, fn ($q) => $q->whereNotIn('c.id', $this->escolhidos))
            ->when(mb_strlen($termo) >= 2, fn ($q) => $q->where(function ($q) use ($termo) {
                $q->where('p.nome', 'like', "%{$termo}%")
                  ->orWhere('c.codigo', 'like', "{$termo}%");
            }))
            ->groupBy('si.id', 'p.nome', 'cat.nome', 'v.nome', 'v.ordem',
                      'si.custo_unitario', 'f.caminho_thumb')
            ->orderBy('p.nome')->orderBy('v.ordem')->orderBy('v.id')
            ->selectRaw('si.id as shipment_item_id, p.nome as item, cat.nome as categoria,
                         v.nome as variacao, si.custo_unitario as custo,
                         f.caminho_thumb as thumb, COUNT(*) as disponiveis')
            ->limit(60)
            ->get();
    }

    /**
     * Os exemplares da linha aberta — incluindo os que já estão no rascunho.
     *
     * Os escolhidos FICAM na lista, marcados: sumir ao ser tocado faria a
     * lista pular sob o dedo e esconderia o que se acabou de fazer.
     */
    #[Computed]
    public function exemplaresDaLinha()
    {
        if (! $this->linhaAberta || ! $this->evento) {
            return collect();
        }

        return Copy::where('event_id', $this->evento->id)
            ->where('shipment_item_id', $this->linhaAberta)
            ->where(fn ($q) => $q
                ->where('status', Copy::DISPONIVEL)
                ->orWhereIn('id', $this->escolhidos ?: [0]))
            ->with('shipmentItem.product', 'shipmentItem.variant')
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

    #[Computed]
    public function baixas()
    {
        if (! $this->evento) {
            return Writeoff::whereRaw('1 = 0')->paginate($this->tamanhoDaPagina());
        }

        return Writeoff::where('event_id', $this->evento->id)
            ->with(['reason', 'registradoPor', 'items.copy.shipmentItem.product', 'items.copy.shipmentItem.variant'])
            ->withCount('items')
            ->tap(fn ($q) => $this->ordem === 'antigas'
                ? $q->orderBy('registrada_em')->orderBy('id')
                : $q->orderByDesc('registrada_em')->orderByDesc('id'))
            ->paginate($this->tamanhoDaPagina());
    }

    // ─────────────────────────── rascunho ───────────────────────────

    public function adicionarExemplar(int $copyId): void
    {
        if (! in_array($copyId, $this->escolhidos, true)) {
            $this->escolhidos[] = $copyId;
        }

        // A busca NÃO é limpa aqui: quem está sorteando três livros do mesmo
        // título continua na mesma lista, e zerar o filtro a cada escolha
        // obrigaria a digitar de novo entre um e outro.
        unset($this->estoque, $this->selecionados, $this->custoPrevisto, $this->exemplaresDaLinha);
    }

    public function removerExemplar(int $copyId): void
    {
        $this->escolhidos = array_values(array_diff($this->escolhidos, [$copyId]));
        unset($this->estoque, $this->selecionados, $this->custoPrevisto, $this->exemplaresDaLinha);
    }

    public function escolherMotivo(int $id): void
    {
        $this->reason_id = $id;
        unset($this->motivoEscolhido, $this->custoPrevisto);
    }

    public function limpar(): void
    {
        $this->reset(['reason_id', 'autorizado_por', 'observacao', 'escolhidos',
                      'buscaEstoque', 'linhaAberta']);
        $this->resetErrorBag();
        unset($this->estoque, $this->selecionados, $this->custoPrevisto,
              $this->motivoEscolhido, $this->exemplaresDaLinha);
    }

    // ─────────────────────────── as ações ───────────────────────────

    public function registrar(WriteoffService $baixas): void
    {
        abort_unless(auth()->user()->can('livraria.baixar'), 403);

        $this->validate([
            'reason_id'      => ['required', 'integer'],
            'autorizado_por' => ['required', 'string', 'max:150'],
            'escolhidos'     => ['required', 'array', 'min:1'],
            'observacao'     => ['nullable', 'string', 'max:500'],
        ], [
            'reason_id.required'      => 'Escolha o motivo da baixa.',
            'autorizado_por.required' => 'Diga quem autorizou.',
            'escolhidos.required'     => 'Escolha ao menos um exemplar.',
        ], attributes: [
            'autorizado_por' => 'autorização',
            'escolhidos'     => 'exemplares',
        ]);

        try {
            $baixa = $baixas->registrar(
                eventId: $this->evento->id,
                copyIds: array_map('intval', $this->escolhidos),
                reasonId: $this->reason_id,
                autorizadoPor: trim($this->autorizado_por),
                observacao: trim($this->observacao) ?: null,
                registradoPor: auth()->id(),
            );
        } catch (\RuntimeException $e) {
            // Outro voluntário pode ter vendido o exemplar entre a busca e o
            // toque: nunca falhar em silêncio.
            $this->dispatch('toast', tipo: 'erro', titulo: 'Baixa não registrada',
                mensagem: $e->getMessage());

            return;
        }

        $qtd = $baixa->items()->count();
        $this->limpar();
        unset($this->baixas);

        $this->dispatch('toast', tipo: 'ok', titulo: 'Baixa registrada',
            mensagem: $qtd.' '.str('exemplar')->plural($qtd).' fora do estoque.');
    }

    public function cancelar(int $id, WriteoffService $baixas): void
    {
        abort_unless(auth()->user()->can('livraria.baixar'), 403);

        $baixa = Writeoff::where('event_id', $this->evento?->id)->findOrFail($id);

        try {
            $baixas->cancelar($baixa, auth()->id());
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', tipo: 'erro', titulo: 'Baixa não cancelada',
                mensagem: $e->getMessage());

            return;
        }

        unset($this->baixas);
        $this->dispatch('toast', tipo: 'info', titulo: 'Baixa cancelada',
            mensagem: 'Os exemplares voltaram ao estoque.');
    }
}; ?>

<div class="baixas">
    @if (! $this->evento)
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <h2>Nenhum evento em andamento</h2>
            <p>A baixa pertence a um evento. Peça ao coordenador para abrir o evento.</p>
        </div>
    @else

    <header class="venda-header">
        <div>
            <span class="venda-evento">{{ $this->evento->nome }}</span>
            <h1>Baixas sem venda</h1>
        </div>
    </header>

    <p class="venda-dica">
        Exemplar que saiu sem ser vendido: sorteio, cortesia, doação, perda.
        Sai do estoque e não gera receita.
    </p>

    <div class="cadastro-grid">
        {{-- ── registrar ── --}}
        <section class="card">
            <h2 class="card-titulo">Registrar baixa</h2>

            @can('livraria.baixar')
                <div class="venda-pagamento">
                    <span class="label">Motivo</span>
                    <div class="pagamento-opcoes">
                        @foreach ($this->motivos as $m)
                            <button type="button" wire:click="escolherMotivo({{ $m->id }})"
                                    class="pagamento-btn {{ $reason_id === $m->id ? 'ativo' : '' }}">
                                {{ $m->nome }}
                                <small>{{ $m->gera_custo ? 'devido ao fornecedor' : 'sem custo' }}</small>
                            </button>
                        @endforeach
                    </div>
                    @error('reason_id') <span class="field-error">{{ $message }}</span> @enderror
                </div>

                <div style="margin-top:.8rem">
                    <label for="b-autorizou">Autorizado por</label>
                    <input id="b-autorizou" type="text" maxlength="150"
                           wire:model="autorizado_por" placeholder="Quem decidiu — Pr. Fulano, preletor…">
                    <small class="ajuda">
                        Texto livre: quem autoriza nem sempre tem login no app.
                    </small>
                    @error('autorizado_por') <span class="field-error">{{ $message }}</span> @enderror
                </div>

                <div style="margin-top:.6rem">
                    <label for="b-obs">Observação <small>opcional</small></label>
                    <input id="b-obs" type="text" maxlength="500" wire:model="observacao"
                           placeholder="Sorteio da plenária de sábado, capa danificada…">
                    @error('observacao') <span class="field-error">{{ $message }}</span> @enderror
                </div>

                <h3 class="bloco-titulo">Exemplares</h3>
                @error('escolhidos') <div class="alert danger" role="alert">{{ $message }}</div> @enderror

                @if ($this->selecionados->isNotEmpty())
                    <ul class="resumo-itens">
                        @foreach ($this->selecionados as $c)
                            <li wire:key="sel-{{ $c->id }}">
                                <span class="resumo-item-nome">
                                    @if ($c->shipmentItem->product->coverPhoto)
                                        <img class="cart-thumb"
                                             src="{{ $c->shipmentItem->product->coverPhoto->urlThumb() }}"
                                             alt="" loading="lazy">
                                    @else
                                        <span class="cart-thumb vazia"><i class="bi bi-book"></i></span>
                                    @endif
                                    <span>
                                        {{ $c->shipmentItem->rotulo() }}
                                        <small class="codigo">{{ $c->codigo }}</small>
                                    </span>
                                </span>
                                <span class="valor">
                                    R$ {{ number_format($c->shipmentItem->custo_unitario, 2, ',', '.') }}
                                    <button type="button" class="btn-sm btn-ghost"
                                            wire:click="removerExemplar({{ $c->id }})"
                                            aria-label="Tirar {{ $c->codigo }}">✕</button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- ── escolher o que sai ────────────────────────────
                     Agrupado por ITEM: 25 camisetas M são uma coisa só na
                     cabeça de quem opera, e listar exemplar por exemplar
                     enterrava os outros títulos sob uma parede de códigos. --}}
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
                                    R$ {{ number_format($linha->custo, 2, ',', '.') }}
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

                {{-- O número que muda a decisão, ANTES de confirmar. --}}
                @if ($this->selecionados->isNotEmpty())
                    <div class="troca-conta">
                        <div class="resumo-linha">
                            <span>{{ $this->selecionados->count() }}
                                  {{ $this->selecionados->count() == 1 ? 'exemplar' : 'exemplares' }}</span>
                            <span class="valor">
                                {{ $this->motivoEscolhido?->nome ?? 'sem motivo' }}
                            </span>
                        </div>

                        @if ($this->motivoEscolhido?->gera_custo)
                            <div class="troca-saldo devolver">
                                <span>Devido ao fornecedor</span>
                                <strong>R$ {{ number_format($this->custoPrevisto, 2, ',', '.') }}</strong>
                            </div>
                            <p class="venda-taxa">
                                <small>Exemplar consignado que sai não volta para a editora —
                                é devido igual ao vendido, mas sem receita nenhuma.</small>
                            </p>
                        @elseif ($this->motivoEscolhido)
                            <div class="troca-saldo par">
                                <span>Sem custo para o evento</span>
                                <strong>R$ 0,00</strong>
                            </div>
                        @endif
                    </div>
                @endif

                <div class="venda-acoes">
                    <button type="button" class="secondary" wire:click="limpar">Limpar</button>
                    <button type="button" wire:click="registrar"
                            wire:loading.attr="disabled" wire:target="registrar"
                            @if ($this->custoPrevisto > 0)
                                wire:confirm="Dar baixa em {{ $this->selecionados->count() }} exemplar(es)? Isso gera R$ {{ number_format($this->custoPrevisto, 2, ',', '.') }} devidos ao fornecedor."
                            @endif>
                        Registrar baixa
                    </button>
                </div>
            @else
                <p class="vazio">
                    Você pode consultar as baixas, mas registrar exige a permissão
                    <code>livraria.baixar</code>.
                </p>
            @endcan
        </section>

        {{-- ── histórico ── --}}
        <section class="card">
            <h2 class="card-titulo">Baixas deste evento</h2>

            <div class="lista-controles">
                <p class="venda-dica">
                    {{ $this->baixas->total() }}
                    {{ $this->baixas->total() == 1 ? 'baixa' : 'baixas' }}
                </p>
                <div class="lista-controles-campos">
                    <label>
                        <span>Ordenar</span>
                        <select wire:model.live="ordem" aria-label="Ordenar as baixas">
                            @foreach ($this->ordensDisponiveis() as $chave => $rotulo)
                                <option value="{{ $chave }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        <span>Por página</span>
                        <select wire:model.live="porPagina" aria-label="Baixas por página">
                            @foreach ([10, 20, 30, 50] as $n)
                                <option value="{{ $n }}">{{ $n }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </div>

            @forelse ($this->baixas as $b)
                <div class="linha-registro {{ $b->cancelada_em ? 'inativo' : '' }}" wire:key="b-{{ $b->id }}">
                    <div>
                        <strong>{{ $b->reason?->nome ?? 'motivo removido' }}</strong>
                        @if ($b->cancelada_em) <span class="pill closed">cancelada</span> @endif
                        @unless ($b->reason?->gera_custo) <span class="pill open">sem custo</span> @endunless
                        <small class="bloco">
                            {{ $b->registrada_em?->format('d/m H:i') }}
                            · {{ $b->items_count }} {{ $b->items_count == 1 ? 'exemplar' : 'exemplares' }}
                            · autorizado por {{ $b->autorizado_por }}
                        </small>
                        <small class="bloco">
                            @foreach ($b->items as $i)
                                <span class="codigo">{{ $i->copy?->codigo }}</span>
                                {{ $i->copy?->shipmentItem?->rotulo() }}@if (! $loop->last) · @endif
                            @endforeach
                        </small>
                        @if ($b->observacao)
                            <small class="bloco">{{ $b->observacao }}</small>
                        @endif
                    </div>
                    <div class="card-acoes">
                        @can('livraria.baixar')
                            @unless ($b->cancelada_em)
                                <button type="button" class="btn-sm btn-ghost"
                                        wire:click="cancelar({{ $b->id }})"
                                        wire:confirm="Cancelar esta baixa? Os {{ $b->items_count }} exemplar(es) voltam para o estoque.">
                                    Cancelar
                                </button>
                            @endunless
                        @endcan
                    </div>
                </div>
            @empty
                <p class="vazio">Nenhuma baixa neste evento.</p>
            @endforelse

            {{ $this->baixas->links() }}
        </section>
    </div>

    {{-- ── POP-UP: QUAL EXEMPLAR SAI ──────────────────────────────
         O código importa — é ele que faz a conferência física bater no
         fim do evento — mas essa escolha não precisa estar na primeira
         tela. Aqui ela vem depois de o item já estar decidido. --}}
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
                    <p class="venda-dica">
                        Escolha o exemplar que saiu — o código é o que confere com a
                        etiqueta na mão.
                    </p>

                    <ul class="resumo-itens">
                        @foreach ($this->exemplaresDaLinha as $c)
                            @php($jaEscolhido = in_array((int) $c->id, $escolhidos, true))
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
                                        @if ($jaEscolhido) <span class="pill open">escolhido</span> @endif
                                    </span>
                                </span>
                                <span class="valor">
                                    @if ($jaEscolhido)
                                        <button type="button" class="btn-sm btn-ghost"
                                                wire:click="removerExemplar({{ $c->id }})">
                                            Tirar
                                        </button>
                                    @else
                                        <button type="button" class="btn-sm"
                                                wire:click="adicionarExemplar({{ $c->id }})">
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

    @endif
</div>
