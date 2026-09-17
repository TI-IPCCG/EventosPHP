<?php

use App\Models\Event;
use App\Models\Livraria\Copy;
use App\Models\Livraria\Writeoff;
use App\Models\Livraria\WriteoffReason;
use App\Services\Livraria\WriteoffService;
use App\Support\EscolhaDeExemplar;
use App\Support\Paginacao;
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
 * Quem sorteia tem o livro na mão. O código diz QUAL exemplar saiu, e é isso
 * que faz a conferência física do estoque bater no fim do evento — com ou sem
 * etiqueta colada, que é opcional (ver livraria/etiquetas).
 */
new
#[Layout('components.layouts.admin')]
#[Title('Baixas — Eventos IPCCG')]
class extends Component {
    use EscolhaDeExemplar;
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

    /** Contrato de EscolhaDeExemplar. */
    protected function eventoDaEscolha(): ?Event
    {
        return $this->evento;
    }

    protected function exemplaresJaEscolhidos(): array
    {
        return $this->escolhidos;
    }

    public function jaEscolhido(int $copyId): bool
    {
        return in_array($copyId, $this->escolhidos, true);
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
        $this->recalcularEscolha();
        unset($this->selecionados, $this->custoPrevisto);
    }

    public function removerExemplar(int $copyId): void
    {
        $this->escolhidos = array_values(array_diff($this->escolhidos, [$copyId]));
        $this->recalcularEscolha();
        unset($this->selecionados, $this->custoPrevisto);
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
        $this->recalcularEscolha();
        unset($this->selecionados, $this->custoPrevisto, $this->motivoEscolhido);
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

                @include('components.livraria.partials.escolher-exemplar', [
                    'acaoEscolher' => 'adicionarExemplar',
                    'acaoTirar'    => 'removerExemplar',
                    'valor'        => 'custo',
                ])

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

    @endif
</div>
