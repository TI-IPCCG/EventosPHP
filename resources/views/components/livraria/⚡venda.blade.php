<?php

use App\Models\Event;
use App\Models\Livraria\CartHold;
use App\Models\Livraria\Copy;
use App\Models\Livraria\PaymentMethod;
use App\Services\Livraria\SaleService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A MESA. É a tela que decide se o app presta.
 *
 * Restrições que moldaram tudo aqui:
 *  · uma das mãos, em pé, atrás de uma mesa, com fila esperando
 *  · item único concluído em quatro toques a partir da tela inicial
 *  · 4G de evento: nada de foto original em listagem, nada de round-trip à toa
 *  · vários voluntários na mesma mesa vendo o mesmo saldo
 *
 * A busca devolve LINHAS DE ESTOQUE (item + variação), não exemplares: o
 * voluntário escolhe "Camiseta · M", e qual exemplar físico sai é problema do
 * sistema. Digitar um código, porém, adiciona direto — é o caminho mais rápido
 * de todos quando a etiqueta está à mão.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Venda — Eventos IPCCG')]
class extends Component {
    /** Na URL: recarregar a página no meio do atendimento não perde a busca. */
    #[Url(except: '')]
    public string $busca = '';

    /** carrinho: [copy_id => ['rotulo' => string, 'preco' => float, 'codigo' => string]] */
    public array $carrinho = [];

    public ?int $payment_method_id = null;
    public string $comprador = '';

    /**
     * Documento de quem comprou. Texto livre de propósito: hoje é digitado,
     * mas quando o módulo de pessoas existir este campo vira a ponte — a venda
     * passa a apontar para o cadastro em vez de guardar a string.
     */
    public string $documento = '';

    /** Confirmação antes de gravar: venda é dinheiro, não se registra por engano. */
    public bool $confirmando = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('livraria.vender'), 403);
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

    /**
     * Linhas de estoque disponíveis que casam com a busca.
     *
     * Uma query só, agregando por (item, variação) — nada de contar exemplar
     * item a item, que viraria N+1 na mão do voluntário.
     */
    #[Computed]
    public function resultados()
    {
        $evento = $this->evento;
        $termo  = trim($this->busca);

        if (! $evento) {
            return collect();
        }

        // Sem busca, mostra o estoque disponível. Antes exigia 3 caracteres e a
        // tela abria em branco — ninguém adivinha que precisa digitar, e numa
        // livraria de evento (uma dezena de títulos) tocar na lista é mais
        // rápido que digitar.
        $temBusca = mb_strlen($termo) >= 2;
        $agora    = CartHold::agoraDo($evento->id);

        $noCarrinho = array_keys($this->carrinho);

        return DB::table('liv_copies as c')
            ->join('liv_shipment_items as si', 'si.id', '=', 'c.shipment_item_id')
            ->join('liv_products as p', 'p.id', '=', 'si.product_id')
            ->join('liv_categories as cat', 'cat.id', '=', 'p.category_id')
            ->leftJoin('liv_variants as v', 'v.id', '=', 'si.variant_id')
            ->leftJoin('liv_product_photos as f', function ($j) {
                $j->on('f.product_id', '=', 'p.id')->where('f.capa', '=', 1);
            })
            // Reserva de OUTRA pessoa, ainda vigente. A minha não conta: os
            // itens do meu carrinho já saem da lista por outro caminho.
            ->leftJoin('liv_cart_holds as h', function ($j) use ($agora) {
                // fuso da congregação, não do ambiente — ver CartHold::agoraDo()
                $j->on('h.copy_id', '=', 'c.id')
                  ->where('h.expira_em', '>', $agora)
                  ->where('h.user_id', '<>', (int) auth()->id());
            })
            ->where('c.event_id', $evento->id)
            ->where('c.status', Copy::DISPONIVEL)
            ->when($noCarrinho, fn ($q) => $q->whereNotIn('c.id', $noCarrinho))
            ->when($temBusca, fn ($q) => $q->where(function ($q) use ($termo) {
                $q->where('p.nome', 'like', "%{$termo}%")
                  ->orWhere('c.codigo', 'like', "{$termo}%")
                  ->orWhereRaw('JSON_SEARCH(p.atributos, "one", ?) IS NOT NULL', ["%{$termo}%"]);
            }))
            ->groupBy('si.id', 'p.id', 'p.nome', 'p.atributos', 'cat.nome', 'v.nome', 'v.ordem',
                      'si.preco_venda', 'f.caminho_thumb')
            // desempate por id: sem ele, duas variações com a mesma ordem
            // saem embaralhadas e a mesa vê P/M/G trocando de lugar a cada busca
            ->orderBy('p.nome')->orderBy('v.ordem')->orderBy('v.id')
            ->selectRaw('si.id as shipment_item_id, p.nome as item, p.atributos,
                         cat.nome as categoria, v.nome as variacao,
                         si.preco_venda as preco, f.caminho_thumb as thumb,
                         SUM(h.id IS NULL)   as disponiveis,
                         SUM(h.id IS NOT NULL) as reservados,
                         COALESCE(
                             MIN(CASE WHEN h.id IS NULL THEN c.id END),
                             MIN(c.id)
                         ) as proxima_copy_id')
            ->limit(60)
            ->get();
    }

    #[Computed]
    public function total(): float
    {
        return round(array_sum(array_column($this->carrinho, 'preco')), 2);
    }

    #[Computed]
    public function taxaPrevista(): float
    {
        $forma = $this->payment_method_id
            ? $this->formasDePagamento->firstWhere('id', $this->payment_method_id)
            : null;

        return $forma ? $forma->taxaSobre($this->total) : 0.0;
    }

    public function adicionar(int $copyId): void
    {
        if (isset($this->carrinho[$copyId])) {
            return;
        }

        $copy = Copy::with('shipmentItem.product', 'shipmentItem.variant')
            ->where('event_id', $this->evento?->id)
            ->find($copyId);

        // Outro voluntário pode ter vendido entre a busca e o toque.
        if (! $copy || ! $copy->estaDisponivel()) {
            unset($this->carrinho[$copyId]);
            $this->dispatch('toast', tipo: 'aviso', titulo: 'Exemplar indisponível',
                mensagem: 'Outra pessoa acabou de vender este exemplar. A lista foi atualizada.');

            return;
        }

        // Quantos exemplares deste MESMO item já estão em carrinho alheio, e
        // quantos sobram livres. A checagem é ANTES de reservar o meu, senão
        // eu contaria a minha própria reserva.
        $disputa = $this->disputaDoItem($copy->shipment_item_id);

        $this->carrinho[$copyId] = [
            'codigo' => $copy->codigo,
            'rotulo' => $copy->shipmentItem->rotulo(),
            'preco'  => (float) $copy->shipmentItem->preco_venda,
        ];

        // Reserva enquanto estiver no carrinho. Não bloqueia ninguém: só torna
        // a disputa visível para os voluntários se acertarem na mesa.
        //
        // Se o exemplar JÁ está reservado por outra pessoa, a reserva dela é
        // mantida — o carrinho aceita mesmo assim, com o aviso. Roubar a
        // reserva faria o primeiro perder o sinal sem nunca saber.
        CartHold::reservarSeLivre($this->evento->id, $copyId, (int) auth()->id());

        if ($disputa['reservados'] > 0 && $disputa['reservados'] >= $disputa['disponiveis']) {
            $this->dispatch('toast', tipo: 'aviso', titulo: 'Atenção — item disputado',
                mensagem: 'Existem vendas ativas com este item selecionado. '
                    .'Antes de finalizar, verifique com o outro vendedor se pode prosseguir.');
        } else {
            $this->dispatch('toast', tipo: 'ok',
                mensagem: $copy->shipmentItem->rotulo().' · R$ '
                    .number_format($copy->shipmentItem->preco_venda, 2, ',', '.').' no carrinho');
        }

        // Limpa a busca para o próximo item: a mesa é sequencial.
        $this->busca = '';
        $this->resetErrorBag('busca');
    }

    /**
     * Situação de um item agora: quantos exemplares estão livres e quantos
     * estão em carrinho de outra pessoa.
     *
     * @return array{disponiveis: int, reservados: int}
     */
    private function disputaDoItem(int $shipmentItemId): array
    {
        $agora = CartHold::agoraDo($this->evento?->id);

        $linha = DB::table('liv_copies as c')
            ->leftJoin('liv_cart_holds as h', function ($j) use ($agora) {
                // fuso da congregação, não do ambiente — ver CartHold::agoraDo()
                $j->on('h.copy_id', '=', 'c.id')
                  ->where('h.expira_em', '>', $agora)
                  ->where('h.user_id', '<>', (int) auth()->id());
            })
            ->where('c.shipment_item_id', $shipmentItemId)
            ->where('c.status', Copy::DISPONIVEL)
            ->selectRaw('SUM(h.id IS NULL) livres, SUM(h.id IS NOT NULL) reservados')
            ->first();

        return [
            'disponiveis' => (int) ($linha->livres ?? 0),
            'reservados'  => (int) ($linha->reservados ?? 0),
        ];
    }

    public function remover(int $copyId): void
    {
        $rotulo = $this->carrinho[$copyId]['rotulo'] ?? 'Item';
        unset($this->carrinho[$copyId]);
        CartHold::soltar([$copyId]);

        $this->dispatch('toast', tipo: 'info', mensagem: "{$rotulo} removido do carrinho");
    }

    public function limpar(): void
    {
        CartHold::soltar(array_map('intval', array_keys($this->carrinho)));
        $this->carrinho = [];
        $this->comprador = '';
        $this->documento = '';
        $this->payment_method_id = null;
        $this->busca = '';
        $this->confirmando = false;
    }

    /** Abre a confirmação. Só valida aqui — gravar é no confirmar(). */
    public function revisar(): void
    {
        $this->validate([
            'carrinho'          => ['required', 'array', 'min:1'],
            'payment_method_id' => ['required', 'integer'],
            'documento'         => ['nullable', 'string', 'max:30'],
        ], [
            'carrinho.required'          => 'Adicione ao menos um item.',
            'payment_method_id.required' => 'Escolha a forma de pagamento.',
        ]);

        $this->confirmando = true;
    }

    public function cancelarRevisao(): void
    {
        $this->confirmando = false;
    }

    /** Resumo da transação exibido na confirmação. */
    #[Computed]
    public function resumo(): array
    {
        $forma = $this->payment_method_id
            ? $this->formasDePagamento->firstWhere('id', $this->payment_method_id)
            : null;

        return [
            'itens'      => $this->carrinho,
            'subtotal'   => $this->total,
            'forma'      => $forma?->nome,
            'percentual' => (float) ($forma?->taxa_percentual ?? 0),
            'taxa'       => $this->taxaPrevista,
            'total'      => round($this->total + $this->taxaPrevista, 2),
        ];
    }

    public function concluir(SaleService $vendas): void
    {
        abort_unless(auth()->user()->can('livraria.vender'), 403);

        $this->validate([
            'carrinho'          => ['required', 'array', 'min:1'],
            'payment_method_id' => ['required', 'integer'],
            'documento'         => ['nullable', 'string', 'max:30'],
        ], [
            'carrinho.required'          => 'Adicione ao menos um item.',
            'payment_method_id.required' => 'Escolha a forma de pagamento.',
        ]);

        try {
            $venda = $vendas->registrar(
                eventId: $this->evento->id,
                copyIds: array_map('intval', array_keys($this->carrinho)),
                paymentMethodId: $this->payment_method_id,
                comprador: $this->identificacaoDoComprador(),
                registradoPor: auth()->id(),
            );
        } catch (\RuntimeException $e) {
            // Corrida com outro voluntário: nunca falhar em silêncio.
            $this->confirmando = false;
            $this->dispatch('toast', tipo: 'erro', titulo: 'Venda não registrada',
                mensagem: $e->getMessage());

            return;
        }

        $qtd = count($this->carrinho);
        $this->limpar();   // solta as reservas junto

        $this->dispatch('toast', tipo: 'ok', titulo: 'Venda registrada',
            mensagem: $qtd.' '.str('item')->plural($qtd).' · R$ '
                .number_format($venda->valor_bruto + $venda->taxa_valor, 2, ',', '.'));
    }

    /** Junta nome e documento numa linha só, do jeito que a venda guarda hoje. */
    private function identificacaoDoComprador(): ?string
    {
        $partes = array_filter([trim($this->comprador), trim($this->documento)]);

        return $partes ? implode(' · ', $partes) : null;
    }
}; ?>

<div class="venda" x-data>
    @if (! $this->evento)
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <h2>Nenhum evento em andamento</h2>
            <p>A venda precisa de um evento ativo. Peça ao coordenador para abrir o evento.</p>
        </div>
    @else

    <header class="venda-header">
        <div>
            <span class="venda-evento">{{ $this->evento->nome }}</span>
            <h1>Venda</h1>
        </div>
        <div class="venda-total" aria-live="polite">
            <span>Total</span>
            <strong>R$ {{ number_format($this->total, 2, ',', '.') }}</strong>
        </div>
    </header>

    @if (session('ok'))
        <div class="alert ok" role="alert">
            <i class="bi bi-check-circle"></i> {{ session('ok') }}
        </div>
    @endif

    {{-- ── BUSCA ──────────────────────────────────────────────── --}}
    <div class="venda-busca">
        <i class="bi bi-search"></i>
        <input type="search"
               wire:model.live.debounce.250ms="busca"
               placeholder="Título, autor ou código"
               autofocus
               enterkeyhint="search"
               aria-label="Buscar item">
        @if ($busca !== '')
            <button type="button" wire:click="$set('busca', '')" aria-label="Limpar busca">
                <i class="bi bi-x-lg"></i>
            </button>
        @endif
    </div>
    @error('busca') <div class="alert warn" role="alert">{{ $message }}</div> @enderror

    <p class="venda-dica">
        @if (trim($busca) === '')
            Toque no item para adicionar. Use a busca para filtrar.
        @else
            {{ $this->resultados->count() }}
            {{ $this->resultados->count() == 1 ? 'resultado' : 'resultados' }} para “{{ $busca }}”.
        @endif
    </p>

    <ul class="venda-resultados">
            @forelse ($this->resultados as $r)
                <li>
                    <button type="button" wire:click="adicionar({{ $r->proxima_copy_id }})"
                            wire:loading.attr="disabled">
                        @if ($r->thumb)
                            <img src="{{ Storage::disk('public')->url($r->thumb) }}" alt="" loading="lazy">
                        @else
                            <span class="thumb-vazio"><i class="bi bi-book"></i></span>
                        @endif

                        <span class="res-info">
                            <strong>
                                {{-- nome trunca; a pill do tamanho nunca encolhe --}}
                                <span class="res-nome">{{ $r->item }}</span>
                                @if ($r->variacao) <span class="pill">{{ $r->variacao }}</span> @endif
                            </strong>
                            <small>
                                {{ $r->categoria }}
                                @php($attrs = json_decode($r->atributos ?? '{}', true))
                                @if (! empty($attrs['autor'])) · {{ $attrs['autor'] }} @endif
                                @if (! empty($attrs['modelo'])) · {{ $attrs['modelo'] }} @endif
                            </small>
                        </span>

                        <span class="res-preco">
                            R$ {{ number_format($r->preco, 2, ',', '.') }}
                            <small class="{{ $r->disponiveis <= 2 ? 'critico' : '' }}">
                                {{ $r->disponiveis }} {{ $r->disponiveis == 1 ? 'restante' : 'restantes' }}
                            </small>
                            @if ($r->reservados > 0)
                                {{-- em carrinho de outro voluntário agora --}}
                                <small class="reservado" title="Em venda em andamento por outra pessoa">
                                    <i class="bi bi-hourglass-split"></i>
                                    Reservados: {{ $r->reservados }}
                                </small>
                            @endif
                        </span>
                    </button>
                </li>
            @empty
                <li class="vazio">
                    @if (trim($busca) !== '')
                        Nada encontrado para “{{ $busca }}”.
                    @else
                        Nenhum exemplar disponível neste evento.
                        <a href="{{ route('livraria.remessa') }}">Monte a remessa</a> primeiro.
                    @endif
                </li>
            @endforelse
        </ul>

    {{-- ── CARRINHO ───────────────────────────────────────────── --}}
    @if ($carrinho)
        <section class="venda-carrinho">
            <h2>{{ count($carrinho) }} {{ count($carrinho) == 1 ? 'item' : 'itens' }}</h2>

            <ul>
                @foreach ($carrinho as $copyId => $item)
                    <li wire:key="cart-{{ $copyId }}">
                        <span class="cart-rotulo">
                            {{ $item['rotulo'] }}
                            <small>{{ $item['codigo'] }}</small>
                        </span>
                        <span class="cart-preco">R$ {{ number_format($item['preco'], 2, ',', '.') }}</span>
                        <button type="button" wire:click="remover({{ $copyId }})"
                                aria-label="Remover {{ $item['rotulo'] }}">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </li>
                @endforeach
            </ul>

            @error('carrinho') <div class="alert danger" role="alert">{{ $message }}</div> @enderror

            {{-- Forma de pagamento: botões grandes, não select. Um toque. --}}
            <div class="venda-pagamento">
                <span class="label">Pagamento</span>
                <div class="pagamento-opcoes">
                    @foreach ($this->formasDePagamento as $forma)
                        <button type="button"
                                wire:click="$set('payment_method_id', {{ $forma->id }})"
                                class="pagamento-btn {{ $payment_method_id === $forma->id ? 'ativo' : '' }}">
                            {{ $forma->nome }}
                            @if ($forma->taxa_percentual > 0)
                                <small>{{ number_format($forma->taxa_percentual, 1, ',', '.') }}%</small>
                            @endif
                        </button>
                    @endforeach
                </div>
                @error('payment_method_id') <span class="field-error">{{ $message }}</span> @enderror
            </div>

            @if ($this->taxaPrevista > 0)
                <p class="venda-taxa">
                    Taxa da transação: R$ {{ number_format($this->taxaPrevista, 2, ',', '.') }}
                    <small>cobrada uma vez sobre o total, não por item</small>
                </p>
            @endif

            <details class="venda-comprador">
                <summary>Identificar o comprador (opcional)</summary>
                <div class="dupla" style="margin-top:.5rem">
                    <input type="text" wire:model="comprador" placeholder="Nome de quem levou" maxlength="150">
                    <input type="text" wire:model="documento" placeholder="CPF ou documento" maxlength="30">
                </div>
                @error('documento') <span class="field-error">{{ $message }}</span> @enderror
            </details>

            <div class="venda-acoes">
                <button type="button" class="secondary" wire:click="limpar"
                        wire:confirm="Descartar esta venda?">
                    Descartar
                </button>
                <button type="button" wire:click="revisar"
                        wire:loading.attr="disabled" wire:target="revisar">
                    Concluir · R$ {{ number_format($this->total + $this->taxaPrevista, 2, ',', '.') }}
                </button>
            </div>
        </section>
    @endif

    {{-- ── CONFIRMAÇÃO ─────────────────────────────────────────
         Venda é dinheiro e o ambiente é ruim: em pé, com fila, celular na
         mão. Um toque errado em "Concluir" grava a venda errada e o conserto
         é um estorno. A revisão mostra item a item, a taxa em separado e o
         total, antes de qualquer gravação. --}}
    @if ($confirmando)
        @php($r = $this->resumo)
        <div class="modal-overlay" wire:click.self="cancelarRevisao"
             x-data x-on:keydown.escape.window="$wire.cancelarRevisao()">
            <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="conf-titulo">
                <div class="modal-header">
                    <strong id="conf-titulo">Confirmar venda</strong>
                    <button type="button" class="btn-sm secondary" wire:click="cancelarRevisao"
                            aria-label="Fechar">✕</button>
                </div>

                <div class="modal-body">
                    <ul class="resumo-itens">
                        @foreach ($r['itens'] as $copyId => $item)
                            {{-- o código distingue dois exemplares do mesmo título:
                                 é por ele que o voluntário confere o livro na mão --}}
                            <li wire:key="resumo-{{ $copyId }}">
                                <span>
                                    {{ $item['rotulo'] }}
                                    <small class="codigo">{{ $item['codigo'] }}</small>
                                </span>
                                <span class="valor">R$ {{ number_format($item['preco'], 2, ',', '.') }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <div class="resumo-linha">
                        <span>Subtotal · {{ count($r['itens']) }} {{ count($r['itens']) == 1 ? 'item' : 'itens' }}</span>
                        <span class="valor">R$ {{ number_format($r['subtotal'], 2, ',', '.') }}</span>
                    </div>

                    <div class="resumo-linha">
                        <span>
                            {{ $r['forma'] }}
                            @if ($r['percentual'] > 0)
                                · taxa {{ number_format($r['percentual'], 2, ',', '.') }}%
                            @else
                                · sem taxa
                            @endif
                        </span>
                        <span class="valor">
                            {{ $r['taxa'] > 0 ? 'R$ '.number_format($r['taxa'], 2, ',', '.') : '—' }}
                        </span>
                    </div>

                    @if ($comprador || $documento)
                        <div class="resumo-linha comprador">
                            <span>Comprador</span>
                            <span class="valor">{{ trim($comprador.' '.$documento) }}</span>
                        </div>
                    @endif

                    <div class="resumo-total">
                        <span>Total</span>
                        <strong>R$ {{ number_format($r['total'], 2, ',', '.') }}</strong>
                    </div>
                </div>

                <div class="modal-acoes">
                    <button type="button" class="secondary" wire:click="cancelarRevisao">Cancelar</button>
                    <button type="button" wire:click="concluir"
                            wire:loading.attr="disabled" wire:target="concluir">
                        <span wire:loading.remove wire:target="concluir">Confirmar venda</span>
                        <span wire:loading wire:target="concluir">Registrando…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    @endif
</div>
