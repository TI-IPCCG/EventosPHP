<?php

use App\Models\Event;
use App\Models\Livraria\Copy;
use App\Models\Livraria\PaymentMethod;
use App\Models\Livraria\Sale;
use App\Services\Livraria\ExchangeService;
use App\Services\Livraria\SaleService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Support\EscolhaDeExemplar;
use App\Support\Paginacao;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * AS VENDAS REGISTRADAS — e o conserto delas.
 *
 * Até aqui o módulo só sabia registrar: SaleService::estornar() existia desde
 * o início e nunca teve tela. Uma venda errada ficava errada.
 *
 * ── AS TRÊS AÇÕES, E POR QUE NÃO SÃO UMA SÓ ───────────────────────────
 *  · CORRIGIR — foi digitado errado e ninguém trocou nada. O registro estava
 *    errado desde o início, então reescreve: itens, forma de pagamento,
 *    valor e taxa.
 *  · TROCAR   — a venda estava certa e o comprador voltou. A transação
 *    original fica intacta (o extrato do PIX continua mostrando o que
 *    passou) e a diferença vira movimento próprio, com forma e taxa
 *    próprias.
 *  · ESTORNAR — a venda inteira não deveria existir. Devolve tudo.
 *
 * Misturar as duas primeiras num botão só faria `valor_bruto` divergir do
 * extrato em toda troca, ou nunca corrigir um lançamento errado.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Vendas — Eventos IPCCG')]
class extends Component {
    use EscolhaDeExemplar;
    use Paginacao;

    private const ORDENS = [
        'recentes' => 'Mais recentes',
        'antigas'  => 'Mais antigas',
        'maior'    => 'Maior valor',
        'menor'    => 'Menor valor',
    ];

    private const FILTROS = [
        'todas'      => 'Todas',
        'validas'    => 'Válidas',
        'estornadas' => 'Estornadas',
        'com_troca'  => 'Com troca',
        'corrigidas' => 'Corrigidas',
    ];

    private const POR_PAGINA_MIN = 5;
    private const POR_PAGINA_MAX = 50;

    #[Url(except: '')]
    public string $busca = '';

    #[Url(except: 'recentes')]
    public string $ordem = 'recentes';

    #[Url(except: 'todas')]
    public string $filtro = 'todas';

    #[Url(except: 20)]
    public int $porPagina = 20;

    /** A venda aberta no painel lateral, e o que se está fazendo com ela. */
    public ?int $vendaId = null;
    public string $modo = '';          // '' | 'corrigir' | 'trocar'

    /** correção: o conjunto FINAL de exemplares que a venda deve ter */
    public array $itensFinais = [];
    public ?int $correcaoFormaId = null;
    public string $correcaoComprador = '';

    /** troca: o que sai (ids de sale_item) e o que entra (ids de copy) */
    public array $saindo = [];
    public array $entrando = [];
    public ?int $trocaFormaId = null;
    public string $trocaMotivo = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('ver-livraria'), 403);
    }

    public function updatedBusca(): void     { $this->resetPage(); }
    public function updatedOrdem(): void     { $this->resetPage(); }
    public function updatedFiltro(): void    { $this->resetPage(); }
    public function updatedPorPagina(): void { $this->resetPage(); }

    public function ordensDisponiveis(): array { return self::ORDENS; }
    public function filtrosDisponiveis(): array { return self::FILTROS; }

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
    public function formasDePagamento()
    {
        return $this->evento
            ? $this->evento->paymentMethods()->where('ativo', true)->get()
            : collect();
    }

    #[Computed]
    public function vendas()
    {
        if (! $this->evento) {
            return Sale::whereRaw('1 = 0')->paginate($this->tamanhoDaPagina());
        }

        $termo = trim($this->busca);

        return Sale::query()
            ->where('event_id', $this->evento->id)
            ->with(['paymentMethod', 'registradoPor'])
            ->withCount([
                'items as itens_count'  => fn ($q) => $q->whereNull('cancelado_em'),
                'exchanges as trocas_count',
            ])
            ->withSum(
                ['items as valor_itens' => fn ($q) => $q->whereNull('cancelado_em')],
                'preco'
            )
            ->when($termo !== '', fn ($q) => $q->where(function ($q) use ($termo) {
                $q->where('comprador', 'like', "%{$termo}%")
                  // o código do exemplar é o que o voluntário tem na mão
                  ->orWhereExists(fn ($s) => $s->select(DB::raw(1))
                      ->from('liv_sale_items as si')
                      ->join('liv_copies as c', 'c.id', '=', 'si.copy_id')
                      ->whereColumn('si.sale_id', 'liv_sales.id')
                      ->where('c.codigo', 'like', "{$termo}%"));

                // "#142" é como a própria tela identifica a venda
                if (ctype_digit(ltrim($termo, '#'))) {
                    $q->orWhere('id', (int) ltrim($termo, '#'));
                }
            }))
            ->tap(fn ($q) => match ($this->filtro) {
                'validas'    => $q->whereNull('cancelada_em'),
                'estornadas' => $q->whereNotNull('cancelada_em'),
                'corrigidas' => $q->whereNotNull('corrigida_em'),
                'com_troca'  => $q->whereHas('exchanges'),
                default      => $q,
            })
            ->tap(fn ($q) => match ($this->ordem) {
                'antigas' => $q->orderBy('vendida_em')->orderBy('id'),
                'maior'   => $q->orderByDesc('valor_bruto')->orderByDesc('id'),
                'menor'   => $q->orderBy('valor_bruto')->orderByDesc('id'),
                default   => $q->orderByDesc('vendida_em')->orderByDesc('id'),
            })
            ->paginate($this->tamanhoDaPagina());
    }

    #[Computed]
    public function venda(): ?Sale
    {
        if (! $this->vendaId || ! $this->evento) {
            return null;
        }

        return Sale::where('event_id', $this->evento->id)
            ->with([
                'items.copy.shipmentItem.product',
                'items.copy.shipmentItem.variant',
                'paymentMethod',
                'registradoPor', 'canceladaPor', 'corrigidaPor',
                'exchanges.paymentMethod', 'exchanges.registradoPor',
            ])
            ->find($this->vendaId);
    }

    /** O que a venda tem hoje — o que a correção e a troca operam. */
    #[Computed]
    public function itensAtivos()
    {
        return $this->venda?->items->whereNull('cancelado_em') ?? collect();
    }

    /** O histórico: o que já saiu, e por quê. */
    #[Computed]
    public function itensSaidos()
    {
        return $this->venda?->items->whereNotNull('cancelado_em') ?? collect();
    }

    /** Contrato de EscolhaDeExemplar. */
    protected function eventoDaEscolha(): ?Event
    {
        return $this->evento;
    }

    /**
     * O rascunho é um só, mas tem dois donos: a troca guarda em `entrando` e a
     * correção em `itensFinais`. Somar os dois é o que impede a lista de
     * oferecer duas vezes o mesmo exemplar quando se alterna entre os modos
     * sem fechar o painel.
     */
    protected function exemplaresJaEscolhidos(): array
    {
        return array_values(array_unique(array_merge($this->entrando, $this->itensFinais)));
    }

    public function jaEscolhido(int $copyId): bool
    {
        return in_array($copyId, $this->exemplaresJaEscolhidos(), true);
    }

    /** O rascunho tem dois donos: `entrando` na troca, `itensFinais` na correção. */
    protected function absorverEscolhidos(array $copyIds): void
    {
        $destino = $this->modo === 'trocar' ? 'entrando' : 'itensFinais';

        $this->{$destino} = array_values(array_unique([...$this->{$destino}, ...$copyIds]));

        unset($this->previaDaTroca);
    }

    /**
     * A conta da troca, ao vivo: o que sai vale o preço REGISTRADO na venda,
     * o que entra vale o preço de hoje na remessa.
     *
     * @return array{sai: float, entra: float, diferenca: float, taxa: float}
     */
    #[Computed]
    public function previaDaTroca(): array
    {
        $sai = (float) $this->itensAtivos
            ->whereIn('id', $this->saindo)
            ->sum(fn ($i) => (float) $i->preco);

        $entra = (float) Copy::whereIn('id', $this->entrando ?: [0])
            ->with('shipmentItem')->get()
            ->sum(fn ($c) => (float) $c->shipmentItem->preco_venda);

        $diferenca = round($entra - $sai, 2);

        $forma = $diferenca > 0 && $this->trocaFormaId
            ? $this->formasDePagamento->firstWhere('id', $this->trocaFormaId)
            : null;

        return [
            'sai'       => round($sai, 2),
            'entra'     => round($entra, 2),
            'diferenca' => $diferenca,
            'taxa'      => $forma?->taxaSobre($diferenca) ?? 0.0,
        ];
    }

    // ─────────────────────────── navegação ───────────────────────────

    public function abrir(int $id): void
    {
        $this->vendaId = $id;
        $this->modo = '';
        $this->limparRascunhos();
    }

    public function fechar(): void
    {
        $this->vendaId = null;
        $this->modo = '';
        $this->limparRascunhos();
    }

    private function limparRascunhos(): void
    {
        $this->itensFinais = [];
        $this->correcaoFormaId = null;
        $this->correcaoComprador = '';
        $this->saindo = [];
        $this->entrando = [];
        $this->trocaFormaId = null;
        $this->trocaMotivo = '';
        $this->buscaEstoque = '';
        $this->linhaAberta = null;
        $this->resetErrorBag();
        $this->recalcularEscolha();
        unset($this->venda, $this->itensAtivos, $this->itensSaidos, $this->previaDaTroca);
    }

    public function abrirCorrecao(): void
    {
        abort_unless(auth()->user()->can('livraria.corrigir'), 403);

        // O formulário começa com o que a venda tem hoje: corrigir é editar o
        // que está lá, não montar de novo do zero.
        $this->itensFinais = $this->itensAtivos->pluck('copy_id')->map('intval')->all();
        $this->correcaoFormaId = $this->venda?->payment_method_id;
        $this->correcaoComprador = (string) $this->venda?->comprador;
        $this->modo = 'corrigir';
    }

    public function abrirTroca(): void
    {
        abort_unless(auth()->user()->can('livraria.corrigir'), 403);

        $this->saindo = [];
        $this->entrando = [];
        $this->modo = 'trocar';
    }

    // ─────────────────────────── rascunhos ───────────────────────────

    public function alternarItemFinal(int $copyId): void
    {
        $this->itensFinais = in_array($copyId, $this->itensFinais, true)
            ? array_values(array_diff($this->itensFinais, [$copyId]))
            : [...$this->itensFinais, $copyId];

        $this->recalcularEscolha();
    }

    public function alternarSaida(int $saleItemId): void
    {
        $this->saindo = in_array($saleItemId, $this->saindo, true)
            ? array_values(array_diff($this->saindo, [$saleItemId]))
            : [...$this->saindo, $saleItemId];

        unset($this->previaDaTroca);
    }

    public function adicionarEntrada(int $copyId): void
    {
        if (! in_array($copyId, $this->entrando, true)) {
            $this->entrando[] = $copyId;
        }

        // A busca NÃO é limpa: trocar dois exemplares do mesmo título não
        // deve obrigar a digitar o filtro de novo entre um e outro.
        $this->recalcularEscolha();
        unset($this->previaDaTroca);
    }

    public function removerEntrada(int $copyId): void
    {
        $this->entrando = array_values(array_diff($this->entrando, [$copyId]));
        $this->recalcularEscolha();
        unset($this->previaDaTroca);
    }

    /** Acrescentar exemplar numa CORREÇÃO (o item esquecido no lançamento). */
    public function adicionarAoFinal(int $copyId): void
    {
        if (! in_array($copyId, $this->itensFinais, true)) {
            $this->itensFinais[] = $copyId;
        }

        $this->recalcularEscolha();
    }

    // ─────────────────────────── as três ações ───────────────────────

    public function salvarCorrecao(SaleService $vendas): void
    {
        abort_unless(auth()->user()->can('livraria.corrigir'), 403);

        $this->validate([
            'correcaoFormaId'   => ['required', 'integer'],
            'correcaoComprador' => ['nullable', 'string', 'max:150'],
        ], ['correcaoFormaId.required' => 'Escolha a forma de pagamento.']);

        try {
            $vendas->corrigir(
                $this->venda,
                array_map('intval', $this->itensFinais),
                $this->correcaoFormaId,
                trim($this->correcaoComprador) ?: null,
                auth()->id(),
            );
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', tipo: 'erro', titulo: 'Correção não aplicada',
                mensagem: $e->getMessage());

            return;
        }

        $this->modo = '';
        $this->limparRascunhos();
        unset($this->vendas);

        $this->dispatch('toast', tipo: 'ok', titulo: 'Venda corrigida',
            mensagem: 'O valor e a taxa foram recalculados.');
    }

    public function salvarTroca(ExchangeService $trocas): void
    {
        abort_unless(auth()->user()->can('livraria.corrigir'), 403);

        $previa = $this->previaDaTroca;

        // Cobrança adicional é transação nova: precisa dizer por onde entrou,
        // ou a conferência do caixa fica com um valor sem origem.
        if ($previa['diferenca'] > 0 && ! $this->trocaFormaId) {
            $this->addError('trocaFormaId', 'Diga como a diferença foi cobrada.');

            return;
        }

        try {
            $troca = $trocas->trocar(
                $this->venda,
                array_map('intval', $this->saindo),
                array_map('intval', $this->entrando),
                $this->trocaFormaId,
                trim($this->trocaMotivo) ?: null,
                auth()->id(),
            );
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', tipo: 'erro', titulo: 'Troca não registrada',
                mensagem: $e->getMessage());

            return;
        }

        $this->modo = '';
        $this->limparRascunhos();
        unset($this->vendas);

        $this->dispatch('toast', tipo: 'ok', titulo: 'Troca registrada',
            mensagem: match (true) {
                $troca->cobrou()   => 'Cobrar R$ '.number_format($troca->valorMovimentado(), 2, ',', '.'),
                $troca->devolveu() => 'Devolver R$ '.number_format($troca->valorMovimentado(), 2, ',', '.'),
                default            => 'Troca sem diferença de valor.',
            });
    }

    public function estornar(SaleService $vendas): void
    {
        abort_unless(auth()->user()->can('livraria.corrigir'), 403);

        try {
            $vendas->estornar($this->venda, auth()->id());
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', tipo: 'erro', titulo: 'Estorno não aplicado',
                mensagem: $e->getMessage());

            return;
        }

        $this->limparRascunhos();
        unset($this->vendas);

        $this->dispatch('toast', tipo: 'info', titulo: 'Venda estornada',
            mensagem: 'Os exemplares voltaram ao estoque.');
    }
}; ?>

<div class="vendas">
    @if (! $this->evento)
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <h2>Nenhum evento em andamento</h2>
            <p>As vendas pertencem a um evento. Peça ao coordenador para abrir o evento.</p>
        </div>
    @else

    <header class="venda-header">
        <div>
            <span class="venda-evento">{{ $this->evento->nome }}</span>
            <h1>Vendas</h1>
        </div>
    </header>

    <div class="venda-busca">
        <i class="bi bi-search"></i>
        <input type="search" wire:model.live.debounce.300ms="busca"
               placeholder="Comprador, código do item ou #número da venda"
               enterkeyhint="search" aria-label="Buscar venda">
        @if ($busca !== '')
            <button type="button" wire:click="$set('busca', '')" aria-label="Limpar busca">
                <i class="bi bi-x-lg"></i>
            </button>
        @endif
    </div>

    <div class="subtabs">
        @foreach ($this->filtrosDisponiveis() as $chave => $rotulo)
            <button type="button" class="subtab {{ $filtro === $chave ? 'active' : '' }}"
                    wire:click="$set('filtro', '{{ $chave }}')">
                {{ $rotulo }}
            </button>
        @endforeach
    </div>

    <div class="lista-controles">
        <p class="venda-dica">
            {{ $this->vendas->total() }}
            {{ $this->vendas->total() == 1 ? 'venda' : 'vendas' }}
        </p>

        <div class="lista-controles-campos">
            <label>
                <span>Ordenar</span>
                <select wire:model.live="ordem" aria-label="Ordenar as vendas">
                    @foreach ($this->ordensDisponiveis() as $chave => $rotulo)
                        <option value="{{ $chave }}">{{ $rotulo }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Por página</span>
                <select wire:model.live="porPagina" aria-label="Vendas por página">
                    @foreach ([10, 20, 30, 50] as $n)
                        <option value="{{ $n }}">{{ $n }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </div>

    <div class="card">
        @forelse ($this->vendas as $v)
            <div class="linha-registro {{ $v->cancelada_em ? 'inativo' : '' }}" wire:key="v-{{ $v->id }}">
                <div>
                    <strong>#{{ $v->id }} · R$ {{ number_format($v->valor_bruto, 2, ',', '.') }}</strong>
                    @if ($v->cancelada_em) <span class="pill closed">estornada</span> @endif
                    @if ($v->trocas_count) <span class="pill draft">{{ $v->trocas_count }} troca{{ $v->trocas_count > 1 ? 's' : '' }}</span> @endif
                    @if ($v->corrigida_em) <span class="pill warn">corrigida</span> @endif
                    <small class="bloco">
                        {{ $v->vendida_em?->format('d/m H:i') }}
                        {{-- quem operou a mesa: é a primeira pergunta quando um
                             lançamento parece estranho no fechamento --}}
                        @if ($v->registradoPor) · por {{ $v->registradoPor->name }} @endif
                        · {{ $v->paymentMethod?->nome ?? 'sem forma' }}
                        · {{ $v->itens_count }} {{ $v->itens_count == 1 ? 'item' : 'itens' }}
                        @if ($v->comprador) · {{ $v->comprador }} @endif
                        {{-- o valor dos itens só difere do bruto quando houve troca,
                             e é justamente o que explica a diferença no caixa --}}
                        @if ($v->trocas_count && ! $v->cancelada_em)
                            · itens hoje: R$ {{ number_format($v->valor_itens ?? 0, 2, ',', '.') }}
                        @endif
                    </small>
                </div>
                <div class="card-acoes">
                    <button type="button" class="btn-sm btn-ghost" wire:click="abrir({{ $v->id }})">
                        Detalhes
                    </button>
                </div>
            </div>
        @empty
            <p class="vazio">
                @if (trim($busca) !== '' || $filtro !== 'todas')
                    Nenhuma venda com esse filtro.
                @else
                    Nenhuma venda registrada neste evento ainda.
                @endif
            </p>
        @endforelse

        {{ $this->vendas->links() }}
    </div>

    {{-- ── DETALHE E AÇÕES ────────────────────────────────────── --}}
    @if ($this->venda)
        <div class="modal-overlay" wire:click.self="fechar"
             x-data x-on:keydown.escape.window="$wire.fechar()">
            <div class="modal-card modal-largo" role="dialog" aria-modal="true" aria-labelledby="v-titulo">
                <div class="modal-header">
                    <strong id="v-titulo">
                        Venda #{{ $this->venda->id }}
                        @if ($this->venda->cancelada_em) <span class="pill closed">estornada</span> @endif
                    </strong>
                    <button type="button" class="btn-sm secondary" wire:click="fechar" aria-label="Fechar">✕</button>
                </div>

                <div class="modal-body">
                    {{-- ── resumo financeiro ── --}}
                    <div class="resumo-linha">
                        <span>Pago em {{ $this->venda->vendida_em?->format('d/m/Y H:i') }}
                              · {{ $this->venda->paymentMethod?->nome ?? 'sem forma' }}</span>
                        <span class="valor">R$ {{ number_format($this->venda->valor_bruto, 2, ',', '.') }}</span>
                    </div>
                    @if ($this->venda->taxa_valor > 0)
                        <div class="resumo-linha">
                            <span>Taxa {{ number_format($this->venda->taxa_percentual, 2, ',', '.') }}%</span>
                            <span class="valor">R$ {{ number_format($this->venda->taxa_valor, 2, ',', '.') }}</span>
                        </div>
                    @endif

                    {{-- Quem fez o quê. Estava tudo no banco e em lugar nenhum
                         da tela: descobrir quem estornou exigia SQL na mão. --}}
                    <p class="venda-dica" style="margin-top:.4rem">
                        @if ($this->venda->registradoPor)
                            Registrada por <strong>{{ $this->venda->registradoPor->name }}</strong>.
                        @else
                            Registrada por um usuário que não está mais no sistema.
                        @endif
                        @if ($this->venda->corrigida_em)
                            · Corrigida em {{ $this->venda->corrigida_em->format('d/m H:i') }}
                            @if ($this->venda->corrigidaPor) por <strong>{{ $this->venda->corrigidaPor->name }}</strong>@endif.
                        @endif
                        @if ($this->venda->cancelada_em)
                            · Estornada em {{ $this->venda->cancelada_em->format('d/m H:i') }}
                            @if ($this->venda->canceladaPor) por <strong>{{ $this->venda->canceladaPor->name }}</strong>@endif.
                        @endif
                    </p>

                    @if ($this->venda->exchanges->isNotEmpty())
                        {{-- A explicação do caixa: o valor pago não é o valor dos
                             itens quando houve troca, e essa linha é o porquê. --}}
                        <div class="alert warn" role="note" style="margin:.7rem 0">
                            <strong>Esta venda teve troca.</strong>
                            O valor acima é o que passou no meio de pagamento e continua
                            batendo com o extrato. Os itens de hoje somam
                            <strong>R$ {{ number_format($this->venda->valorDosItens(), 2, ',', '.') }}</strong>.
                        </div>

                        <ul class="resumo-itens">
                            @foreach ($this->venda->exchanges as $troca)
                                <li wire:key="x-{{ $troca->id }}">
                                    <span>
                                        Troca em {{ $troca->realizada_em?->format('d/m H:i') }}
                                        @if ($troca->registradoPor) · por {{ $troca->registradoPor->name }} @endif
                                        @if ($troca->paymentMethod) · {{ $troca->paymentMethod->nome }} @endif
                                        @if ($troca->motivo) <small class="codigo">{{ $troca->motivo }}</small> @endif
                                    </span>
                                    <span class="valor">
                                        @if ($troca->cobrou())
                                            cobrado R$ {{ number_format($troca->valorMovimentado(), 2, ',', '.') }}
                                        @elseif ($troca->devolveu())
                                            devolvido R$ {{ number_format($troca->valorMovimentado(), 2, ',', '.') }}
                                        @else
                                            sem diferença
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- ── itens atuais ── --}}
                    <h3 class="bloco-titulo">Itens desta venda</h3>
                    <ul class="resumo-itens">
                        @foreach ($this->itensAtivos as $i)
                            <li wire:key="i-{{ $i->id }}">
                                <span>
                                    {{ $i->copy?->shipmentItem?->rotulo() ?? 'item removido' }}
                                    <small class="codigo">{{ $i->copy?->codigo }}</small>
                                    @if ($i->exchange_in_id) <span class="pill draft">entrou por troca</span> @endif
                                </span>
                                <span class="valor">R$ {{ number_format($i->preco, 2, ',', '.') }}</span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($this->itensSaidos->isNotEmpty())
                        <h3 class="bloco-titulo">Saíram desta venda</h3>
                        <ul class="resumo-itens">
                            @foreach ($this->itensSaidos as $i)
                                <li wire:key="s-{{ $i->id }}" class="inativo">
                                    <span>
                                        {{ $i->copy?->shipmentItem?->rotulo() ?? 'item removido' }}
                                        <small class="codigo">{{ $i->copy?->codigo }}</small>
                                        <span class="pill closed">{{ $i->motivoDaSaida() }}</span>
                                    </span>
                                    <span class="valor">R$ {{ number_format($i->preco, 2, ',', '.') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- ── o que dá para fazer ── --}}
                    @can('livraria.corrigir')
                        @if (! $this->venda->cancelada_em && $modo === '')
                            <div class="venda-acoes" style="margin-top:1rem">
                                <button type="button" class="secondary" wire:click="abrirCorrecao">
                                    Corrigir lançamento
                                </button>
                                <button type="button" wire:click="abrirTroca">
                                    Trocar item
                                </button>
                            </div>
                            <p class="venda-dica" style="margin-top:.5rem">
                                <strong>Corrigir</strong> é para lançamento errado — reescreve o valor
                                e a taxa. <strong>Trocar</strong> é para quando o comprador voltou —
                                preserva o que foi pago e registra a diferença.
                            </p>

                            <div style="margin-top:.8rem">
                                <button type="button" class="btn-sm btn-ghost" wire:click="estornar"
                                        wire:confirm="Estornar a venda #{{ $this->venda->id }} inteira? Os exemplares voltam ao estoque.">
                                    Estornar a venda inteira
                                </button>
                            </div>
                        @endif
                    @endcan

                    {{-- ── CORRIGIR ── --}}
                    @if ($modo === 'corrigir')
                        <h3 class="bloco-titulo">Corrigir o lançamento</h3>
                        <div class="alert warn" role="note">
                            Use isto quando a venda foi <strong>digitada errada</strong> e ninguém
                            trocou nada. O valor e a taxa são recalculados.
                        </div>

                        <ul class="resumo-itens">
                            @foreach ($this->itensAtivos as $i)
                                <li wire:key="f-{{ $i->id }}">
                                    <label style="display:flex;align-items:center;gap:.5rem;flex:1">
                                        <input type="checkbox"
                                               wire:click="alternarItemFinal({{ $i->copy_id }})"
                                               @checked(in_array((int) $i->copy_id, $itensFinais, true))>
                                        <span>
                                            {{ $i->copy?->shipmentItem?->rotulo() }}
                                            <small class="codigo">{{ $i->copy?->codigo }}</small>
                                        </span>
                                    </label>
                                    <span class="valor">R$ {{ number_format($i->preco, 2, ',', '.') }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="venda-dica">Desmarque o que não deveria estar na venda.</p>

                        @include('components.livraria.partials.escolher-exemplar', [
                            'acaoEscolher' => 'adicionarAoFinal',
                            'acaoTirar'    => 'alternarItemFinal',
                            'valor'        => 'preco',
                        ])

                        <div class="venda-pagamento" style="margin-top:.8rem">
                            <span class="label">Forma de pagamento</span>
                            <div class="pagamento-opcoes">
                                @foreach ($this->formasDePagamento as $forma)
                                    <button type="button"
                                            wire:click="$set('correcaoFormaId', {{ $forma->id }})"
                                            class="pagamento-btn {{ $correcaoFormaId === $forma->id ? 'ativo' : '' }}">
                                        {{ $forma->nome }}
                                        @if ($forma->taxa_percentual > 0)
                                            <small>{{ number_format($forma->taxa_percentual, 1, ',', '.') }}%</small>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                            @error('correcaoFormaId') <span class="field-error">{{ $message }}</span> @enderror
                        </div>

                        <input type="text" wire:model="correcaoComprador" maxlength="150"
                               placeholder="Comprador (opcional)" style="margin-top:.6rem">

                        <div class="venda-acoes">
                            <button type="button" class="secondary" wire:click="$set('modo', '')">Cancelar</button>
                            <button type="button" wire:click="salvarCorrecao"
                                    wire:loading.attr="disabled" wire:target="salvarCorrecao">
                                Salvar correção
                            </button>
                        </div>
                    @endif

                    {{-- ── TROCAR ── --}}
                    @if ($modo === 'trocar')
                        @php($p = $this->previaDaTroca)

                        <h3 class="bloco-titulo">Trocar item</h3>
                        <div class="alert ok" role="note">
                            A venda original não é alterada — o que foi pago continua batendo
                            com o extrato. O que muda são os itens, e a diferença vira um
                            movimento à parte.
                        </div>

                        <h4 class="bloco-subtitulo">O que o comprador devolveu</h4>
                        <ul class="resumo-itens">
                            @foreach ($this->itensAtivos as $i)
                                <li wire:key="t-{{ $i->id }}">
                                    <label style="display:flex;align-items:center;gap:.5rem;flex:1">
                                        <input type="checkbox" wire:click="alternarSaida({{ $i->id }})"
                                               @checked(in_array((int) $i->id, $saindo, true))>
                                        <span>
                                            {{ $i->copy?->shipmentItem?->rotulo() }}
                                            <small class="codigo">{{ $i->copy?->codigo }}</small>
                                        </span>
                                    </label>
                                    <span class="valor">R$ {{ number_format($i->preco, 2, ',', '.') }}</span>
                                </li>
                            @endforeach
                        </ul>

                        <h4 class="bloco-subtitulo">O que ele levou no lugar</h4>
                        @if ($entrando)
                            <ul class="resumo-itens">
                                @foreach (\App\Models\Livraria\Copy::with('shipmentItem.product', 'shipmentItem.variant')->find($entrando) as $c)
                                    <li wire:key="e-{{ $c->id }}">
                                        <span>
                                            {{ $c->shipmentItem->rotulo() }}
                                            <small class="codigo">{{ $c->codigo }}</small>
                                        </span>
                                        <span class="valor">
                                            R$ {{ number_format($c->shipmentItem->preco_venda, 2, ',', '.') }}
                                            <button type="button" class="btn-sm btn-ghost"
                                                    wire:click="removerEntrada({{ $c->id }})">✕</button>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @include('components.livraria.partials.escolher-exemplar', [
                            'acaoEscolher' => 'adicionarEntrada',
                            'acaoTirar'    => 'removerEntrada',
                            'valor'        => 'preco',
                        ])

                        {{-- ── A CONTA, que é o ponto da tela ── --}}
                        <div class="troca-conta">
                            <div class="resumo-linha">
                                <span>Devolvido</span>
                                <span class="valor">R$ {{ number_format($p['sai'], 2, ',', '.') }}</span>
                            </div>
                            <div class="resumo-linha">
                                <span>Levado</span>
                                <span class="valor">R$ {{ number_format($p['entra'], 2, ',', '.') }}</span>
                            </div>

                            @if ($p['diferenca'] > 0)
                                <div class="troca-saldo cobrar">
                                    <span>Cobrar do comprador</span>
                                    <strong>R$ {{ number_format($p['diferenca'], 2, ',', '.') }}</strong>
                                </div>
                            @elseif ($p['diferenca'] < 0)
                                <div class="troca-saldo devolver">
                                    <span>Devolver ao comprador</span>
                                    <strong>R$ {{ number_format(abs($p['diferenca']), 2, ',', '.') }}</strong>
                                </div>
                            @else
                                <div class="troca-saldo par">
                                    <span>Troca sem diferença</span>
                                    <strong>R$ 0,00</strong>
                                </div>
                            @endif

                            @if ($p['taxa'] > 0)
                                <p class="venda-taxa">
                                    Taxa sobre a cobrança: R$ {{ number_format($p['taxa'], 2, ',', '.') }}
                                </p>
                            @endif
                        </div>

                        @if ($p['diferenca'] != 0)
                            <div class="venda-pagamento">
                                <span class="label">
                                    {{ $p['diferenca'] > 0 ? 'Cobrado por' : 'Devolvido por' }}
                                </span>
                                <div class="pagamento-opcoes">
                                    @foreach ($this->formasDePagamento as $forma)
                                        <button type="button"
                                                wire:click="$set('trocaFormaId', {{ $forma->id }})"
                                                class="pagamento-btn {{ $trocaFormaId === $forma->id ? 'ativo' : '' }}">
                                            {{ $forma->nome }}
                                            @if ($forma->taxa_percentual > 0 && $p['diferenca'] > 0)
                                                <small>{{ number_format($forma->taxa_percentual, 1, ',', '.') }}%</small>
                                            @endif
                                        </button>
                                    @endforeach
                                </div>
                                @error('trocaFormaId') <span class="field-error">{{ $message }}</span> @enderror
                            </div>
                        @endif

                        <input type="text" wire:model="trocaMotivo" maxlength="200"
                               placeholder="Motivo (opcional): tamanho errado, defeito…"
                               style="margin-top:.6rem">

                        <div class="venda-acoes">
                            <button type="button" class="secondary" wire:click="$set('modo', '')">Cancelar</button>
                            <button type="button" wire:click="salvarTroca"
                                    wire:loading.attr="disabled" wire:target="salvarTroca">
                                Registrar troca
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @endif
</div>
