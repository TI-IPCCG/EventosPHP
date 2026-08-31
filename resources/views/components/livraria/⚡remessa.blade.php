<?php

use App\Models\Event;
use App\Models\Livraria\EventCost;
use App\Models\Livraria\Product;
use App\Models\Livraria\Shipment;
use App\Models\Livraria\ShipmentItem;
use App\Models\Livraria\Supplier;
use App\Models\Livraria\Variant;
use App\Services\Livraria\ShipmentService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Montar a remessa do evento: o que vem de cada fornecedor, quanto e a que
 * preço. Salvar já cria os EXEMPLARES com código — a etiqueta sai daqui.
 *
 * Sob consignação o erro caro não é encalhar: é levar de menos e esgotar antes
 * do fim. Por isso a tela mostra, ao lado de cada item, quanto ele girou no
 * evento anterior.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Remessa — Eventos IPCCG')]
class extends Component {
    /** Na URL: dá link direto para a remessa de um fornecedor. */
    #[Url(except: null)]
    public ?int $supplier_id = null;

    // linha em edição
    public ?int $product_id = null;
    public ?int $variant_id = null;
    public ?int $quantidade = null;
    public ?string $custo_unitario = null;
    public ?string $preco_venda = null;

    // custo do evento
    public string $custo_descricao = '';
    public ?string $custo_valor = null;
    public string $custo_rateio = 'direto';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('livraria.remessa'), 403);
    }

    #[Computed]
    public function evento(): ?Event
    {
        return Event::atual();
    }

    #[Computed]
    public function fornecedores()
    {
        return Supplier::ativos()->get();
    }

    #[Computed]
    public function remessa(): ?Shipment
    {
        if (! $this->evento || ! $this->supplier_id) {
            return null;
        }

        return Shipment::firstOrCreate(
            ['event_id' => $this->evento->id, 'supplier_id' => $this->supplier_id],
            [
                'condicao'   => Supplier::find($this->supplier_id)?->condicao_padrao ?? 'consignado',
                'created_at' => now(),
            ],
        );
    }

    /** Itens do fornecedor escolhido, para o seletor. */
    #[Computed]
    public function itensDoFornecedor()
    {
        return $this->supplier_id
            ? Product::ativos()->where('supplier_id', $this->supplier_id)->with('variants', 'category')->orderBy('nome')->get()
            : collect();
    }

    #[Computed]
    public function produto(): ?Product
    {
        return $this->product_id ? $this->itensDoFornecedor->firstWhere('id', $this->product_id) : null;
    }

    /** Linhas já montadas, com o saldo real vindo dos exemplares. */
    #[Computed]
    public function linhas()
    {
        if (! $this->remessa) {
            return collect();
        }

        return DB::table('liv_shipment_items as si')
            ->join('liv_products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('liv_variants as v', 'v.id', '=', 'si.variant_id')
            ->leftJoin('liv_copies as c', 'c.shipment_item_id', '=', 'si.id')
            ->where('si.shipment_id', $this->remessa->id)
            ->groupBy('si.id', 'p.nome', 'v.nome', 'v.ordem', 'si.quantidade', 'si.custo_unitario', 'si.preco_venda')
            ->orderBy('p.nome')->orderBy('v.ordem')->orderBy('v.id')
            ->selectRaw("si.id, p.nome as item, v.nome as variacao, si.quantidade,
                         si.custo_unitario, si.preco_venda,
                         COUNT(c.id) as exemplares,
                         SUM(c.status = 'disponivel') as disponiveis,
                         MIN(c.codigo) as primeiro_codigo, MAX(c.codigo) as ultimo_codigo")
            ->get();
    }

    #[Computed]
    public function totais(): array
    {
        $linhas = $this->linhas;

        return [
            'exemplares' => (int) $linhas->sum('exemplares'),
            'consignado' => (float) $linhas->sum(fn ($l) => $l->exemplares * $l->custo_unitario),
            'potencial'  => (float) $linhas->sum(fn ($l) => $l->exemplares * $l->preco_venda),
        ];
    }

    #[Computed]
    public function custos()
    {
        return $this->evento ? $this->evento->costs()->with('supplier')->get() : collect();
    }

    /**
     * Giro deste item no evento ANTERIOR. Sob consignação é o número que
     * importa na hora de decidir a quantidade.
     */
    #[Computed]
    public function giroAnterior(): ?array
    {
        if (! $this->produto || ! $this->evento) {
            return null;
        }

        $anterior = Event::where('id', '<>', $this->evento->id)
            ->where('inicio', '<', $this->evento->inicio)
            ->orderByDesc('inicio')->first();

        if (! $anterior) {
            return null;
        }

        $linha = DB::table('liv_copies as c')
            ->join('liv_shipment_items as si', 'si.id', '=', 'c.shipment_item_id')
            ->where('c.event_id', $anterior->id)
            ->where('si.product_id', $this->produto->id)
            ->when($this->variant_id, fn ($q) => $q->where('si.variant_id', $this->variant_id))
            ->selectRaw("COUNT(*) levados, SUM(c.status = 'vendido') vendidos")
            ->first();

        if (! $linha || ! $linha->levados) {
            return null;
        }

        return [
            'evento'   => $anterior->nome,
            'levados'  => (int) $linha->levados,
            'vendidos' => (int) $linha->vendidos,
            'esgotou'  => (int) $linha->vendidos >= (int) $linha->levados,
        ];
    }

    /** Ao escolher o item, sugere custo e preço a partir do cadastro. */
    public function updatedProductId(): void
    {
        $this->variant_id = null;
        $produto = $this->produto;

        if (! $produto) {
            return;
        }

        // Desconto do ITEM quando houver; senão o padrão do fornecedor.
        if (($sugerido = $produto->custoSugerido()) !== null) {
            $this->custo_unitario = number_format($sugerido, 2, '.', '');
        }

        // Preço sugerido = o de capa. O coordenador ajusta; a meta zero a zero
        // costuma permitir vender abaixo da capa.
        if (($capa = (float) ($produto->preco_referencia ?? 0)) > 0) {
            $this->preco_venda = number_format($capa, 2, '.', '');
        }
    }

    /**
     * Quantos exemplares esta gravação vai APAGAR, se a quantidade diminuiu.
     * A tela usa para pedir confirmação — reduzir a quantidade apagava
     * exemplares em silêncio, o que é perda de dado sem aviso.
     */
    #[Computed]
    public function exemplaresQueSeraoRemovidos(): int
    {
        if (! $this->remessa || ! $this->product_id || ! $this->quantidade) {
            return 0;
        }

        $atual = ShipmentItem::where('shipment_id', $this->remessa->id)
            ->where('product_id', $this->product_id)
            ->where('variant_id', $this->variant_id)
            ->first();

        return $atual ? max(0, $atual->copies()->count() - (int) $this->quantidade) : 0;
    }

    public function salvarLinha(ShipmentService $remessas): void
    {
        abort_unless(auth()->user()->can('livraria.remessa'), 403);

        $this->validate([
            'product_id'     => ['required', 'integer'],
            'quantidade'     => ['required', 'integer', 'min:1', 'max:999'],
            'custo_unitario' => ['required', 'numeric', 'min:0'],
            'preco_venda'    => ['required', 'numeric', 'min:0'],
        ], attributes: [
            'product_id'     => 'item',
            'custo_unitario' => 'custo',
            'preco_venda'    => 'preço de venda',
        ]);

        try {
            $item = $remessas->definirItem(
                remessa: $this->remessa,
                produto: Product::findOrFail($this->product_id),
                variacao: $this->variant_id ? Variant::find($this->variant_id) : null,
                quantidade: $this->quantidade,
                custoUnitario: (float) $this->custo_unitario,
                precoVenda: (float) $this->preco_venda,
            );
        } catch (RuntimeException $e) {
            $this->dispatch('toast', tipo: 'erro', titulo: 'Remessa não alterada',
                mensagem: $e->getMessage());

            return;
        }

        $exemplares = $item->copies()->count();
        $rotulo     = $item->rotulo();

        $this->reset(['product_id', 'variant_id', 'quantidade', 'custo_unitario', 'preco_venda']);
        unset($this->linhas, $this->totais, $this->exemplaresQueSeraoRemovidos);

        $this->dispatch('toast', tipo: 'ok', titulo: 'Remessa atualizada',
            mensagem: "{$rotulo}: {$exemplares} ".str('exemplar')->plural($exemplares).' no evento.');
    }

    public function removerLinha(int $id, ShipmentService $remessas): void
    {
        abort_unless(auth()->user()->can('livraria.remessa'), 403);

        try {
            $remessas->removerItem(ShipmentItem::findOrFail($id));
        } catch (RuntimeException $e) {
            $this->dispatch('toast', tipo: 'erro', titulo: 'Não dá para remover',
                mensagem: $e->getMessage());

            return;
        }

        unset($this->linhas, $this->totais);
        $this->dispatch('toast', tipo: 'info', mensagem: 'Item retirado da remessa.');
    }

    public function salvarCusto(): void
    {
        abort_unless(auth()->user()->can('livraria.remessa'), 403);

        $this->validate([
            'custo_descricao' => ['required', 'string', 'max:150'],
            'custo_valor'     => ['required', 'numeric', 'min:0'],
            'custo_rateio'    => ['required', 'in:direto,por_unidade_enviada,por_unidade_vendida,percentual_venda'],
        ], attributes: ['custo_descricao' => 'descrição', 'custo_valor' => 'valor']);

        EventCost::create([
            'event_id'    => $this->evento->id,
            'supplier_id' => $this->supplier_id,
            'descricao'   => $this->custo_descricao,
            'valor'       => $this->custo_valor,
            'rateio'      => $this->custo_rateio,
            'created_at'  => now(),
        ]);

        $descricao = $this->custo_descricao;
        $valor     = number_format((float) $this->custo_valor, 2, ',', '.');

        $this->reset(['custo_descricao', 'custo_valor']);
        unset($this->custos);

        $this->dispatch('toast', tipo: 'ok', mensagem: "{$descricao} · R$ {$valor} lançado.");
    }

    public function removerCusto(int $id): void
    {
        abort_unless(auth()->user()->can('livraria.remessa'), 403);

        EventCost::where('event_id', $this->evento->id)->findOrFail($id)->delete();
        unset($this->custos);

        $this->dispatch('toast', tipo: 'info', mensagem: 'Custo removido.');
    }
}; ?>

<div class="cadastro">
    @if (! $this->evento)
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <h2>Nenhum evento ativo</h2>
            <p>Escolha um evento em <a href="{{ route('eventos') }}">Eventos</a> para montar a remessa.</p>
        </div>
    @else
    <header class="painel-header">
        <div>
            <span class="painel-evento">{{ $this->evento->nome }}</span>
            <h1>Remessa</h1>
            <p class="subtitulo">O que vem de cada fornecedor. Salvar já gera os exemplares com código.</p>
        </div>
    </header>

    @if (session('ok'))
        <div class="alert ok" role="alert"><i class="bi bi-check-circle"></i> {{ session('ok') }}</div>
    @endif

    <div class="card" style="margin-bottom:1rem">
        <label for="r-fornecedor">Fornecedor</label>
        <select id="r-fornecedor" wire:model.live="supplier_id">
            <option value="">Selecione…</option>
            @foreach ($this->fornecedores as $f)
                <option value="{{ $f->id }}">{{ $f->nome }} ({{ $f->prefixo }})</option>
            @endforeach
        </select>
    </div>

    @if ($this->supplier_id)
        <div class="cadastro-grid">
            {{-- ── adicionar linha ── --}}
            <section class="card">
                <h2 class="card-titulo">Adicionar item</h2>

                <form class="form" wire:submit="salvarLinha">
                    <div>
                        <label for="r-item">Item</label>
                        <select id="r-item" wire:model.live="product_id">
                            <option value="">Selecione…</option>
                            @foreach ($this->itensDoFornecedor as $p)
                                <option value="{{ $p->id }}">{{ $p->nome }}</option>
                            @endforeach
                        </select>
                        @error('product_id') <span class="field-error">{{ $message }}</span> @enderror
                    </div>

                    @if ($this->produto?->usaVariacao())
                        <div>
                            <label for="r-variacao">{{ $this->produto->category->rotuloVariacao() }}</label>
                            <select id="r-variacao" wire:model.live="variant_id" required>
                                <option value="">Selecione…</option>
                                @foreach ($this->produto->variants as $v)
                                    <option value="{{ $v->id }}">{{ $v->nome }}</option>
                                @endforeach
                            </select>
                            <small class="ajuda">Cada um tem saldo próprio — uma linha por vez.</small>
                        </div>
                    @endif

                    @if ($this->giroAnterior)
                        <div class="alert {{ $this->giroAnterior['esgotou'] ? 'warn' : 'ok' }}" role="status">
                            <i class="bi bi-graph-up"></i>
                            Em {{ $this->giroAnterior['evento'] }}:
                            vendeu <strong>{{ $this->giroAnterior['vendidos'] }} de {{ $this->giroAnterior['levados'] }}</strong>.
                            @if ($this->giroAnterior['esgotou'])
                                <strong>Esgotou</strong> — considere levar mais.
                            @endif
                        </div>
                    @endif

                    <div>
                        <label for="r-qtd">Quantidade</label>
                        <input id="r-qtd" type="number" min="1" max="999" wire:model.live.debounce.400ms="quantidade" required>
                        <small class="ajuda">
                            Mudar depois acerta os exemplares: aumenta gera códigos novos, diminui tira só os disponíveis.
                        </small>
                        @error('quantidade') <span class="field-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="dupla">
                        <div>
                            <label for="r-custo">Custo unitário</label>
                            <input id="r-custo" type="number" step="0.01" min="0" wire:model="custo_unitario" required>
                            <small class="ajuda">
                                O que se deve ao fornecedor por exemplar.
                                @if ($this->produto && $this->produto->descontoEfetivo() !== null)
                                    Sugerido com
                                    <strong>{{ number_format($this->produto->descontoEfetivo(), 2, ',', '.') }}%</strong>
                                    @if ($this->produto->origemDoDesconto() === 'item')
                                        (desconto próprio do item).
                                    @else
                                        (padrão do fornecedor).
                                    @endif
                                @endif
                            </small>
                            @error('custo_unitario') <span class="field-error">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label for="r-preco">Preço de venda</label>
                            <input id="r-preco" type="number" step="0.01" min="0" wire:model="preco_venda" required>
                            @error('preco_venda') <span class="field-error">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    @if ($this->exemplaresQueSeraoRemovidos > 0)
                        <div class="alert warn" role="alert">
                            <i class="bi bi-exclamation-triangle"></i>
                            Reduzir para {{ $quantidade }} vai <strong>apagar
                            {{ $this->exemplaresQueSeraoRemovidos }}
                            {{ $this->exemplaresQueSeraoRemovidos == 1 ? 'exemplar disponível' : 'exemplares disponíveis' }}</strong>
                            e os códigos deles. Exemplar já vendido ou baixado não é tocado.
                        </div>
                    @endif

                    <div class="form-acoes">
                        <button type="submit" wire:loading.attr="disabled" wire:target="salvarLinha"
                                @if ($this->exemplaresQueSeraoRemovidos > 0)
                                    wire:confirm="Apagar {{ $this->exemplaresQueSeraoRemovidos }} exemplar(es) disponível(is) desta linha?"
                                @endif>
                            Salvar e gerar exemplares
                        </button>
                    </div>
                </form>
            </section>

            {{-- ── linhas da remessa ── --}}
            <section class="card">
                <h2 class="card-titulo">Na remessa</h2>

                @error('linhas') <div class="alert danger" role="alert">{{ $message }}</div> @enderror

                @forelse ($this->linhas as $l)
                    <div class="linha-registro" wire:key="linha-{{ $l->id }}">
                        <div style="flex:1;min-width:0">
                            <strong>
                                {{ $l->item }}
                                @if ($l->variacao) <span class="pill">{{ $l->variacao }}</span> @endif
                            </strong>
                            <small class="bloco">
                                {{ $l->exemplares }} {{ $l->exemplares == 1 ? 'exemplar' : 'exemplares' }}
                                @if ($l->primeiro_codigo)
                                    · {{ $l->primeiro_codigo }}@if ($l->ultimo_codigo !== $l->primeiro_codigo)–{{ $l->ultimo_codigo }}@endif
                                @endif
                                · custo R$ {{ number_format($l->custo_unitario, 2, ',', '.') }}
                                · venda R$ {{ number_format($l->preco_venda, 2, ',', '.') }}
                            </small>
                        </div>
                        <button type="button" class="btn-sm btn-danger" wire:click="removerLinha({{ $l->id }})"
                                wire:confirm="Remover {{ $l->item }} da remessa? Isso apaga os {{ $l->exemplares }} exemplares e os códigos deles.">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                @empty
                    <p class="vazio">Nada deste fornecedor ainda.</p>
                @endforelse

                @if ($this->linhas->isNotEmpty())
                    <div class="totais">
                        <div><span>Exemplares</span><strong>{{ $this->totais['exemplares'] }}</strong></div>
                        <div><span>Valor consignado</span><strong>R$ {{ number_format($this->totais['consignado'], 2, ',', '.') }}</strong></div>
                        <div><span>Se vender tudo</span><strong>R$ {{ number_format($this->totais['potencial'], 2, ',', '.') }}</strong></div>
                    </div>
                @endif
            </section>
        </div>
    @endif

    {{-- ── custos do evento ── --}}
    <section class="card" style="margin-top:1rem">
        <h2 class="card-titulo"><i class="bi bi-truck"></i> Custos do evento</h2>
        <p class="ajuda" style="margin-bottom:.8rem">
            Frete e o que mais aparecer. Entram <strong>uma vez, pelo valor real</strong>, no resultado —
            o rateio serve só à sugestão de preço.
        </p>

        <form wire:submit="salvarCusto" class="linha-form">
            <input type="text" wire:model="custo_descricao" placeholder="Frete ida e volta" maxlength="150">
            <input type="number" step="0.01" min="0" wire:model="custo_valor" placeholder="0,00">
            <select wire:model="custo_rateio">
                <option value="direto">Direto</option>
                <option value="por_unidade_enviada">Por unidade enviada</option>
                <option value="por_unidade_vendida">Por unidade vendida</option>
                <option value="percentual_venda">% sobre a venda</option>
            </select>
            <button type="submit">Lançar</button>
        </form>
        @error('custo_descricao') <span class="field-error">{{ $message }}</span> @enderror
        @error('custo_valor') <span class="field-error">{{ $message }}</span> @enderror

        @forelse ($this->custos as $c)
            <div class="linha-registro" wire:key="custo-{{ $c->id }}">
                <div style="flex:1">
                    <strong>{{ $c->descricao }}</strong>
                    <small class="bloco">
                        R$ {{ number_format($c->valor, 2, ',', '.') }}
                        @if ($c->supplier) · {{ $c->supplier->nome }} @endif
                        · rateio {{ str($c->rateio)->replace('_', ' ') }}
                    </small>
                </div>
                <button type="button" class="btn-sm btn-danger" wire:click="removerCusto({{ $c->id }})"
                        wire:confirm="Remover este custo?"><i class="bi bi-trash"></i></button>
            </div>
        @empty
            <p class="vazio">Nenhum custo lançado.</p>
        @endforelse
    </section>
    @endif
</div>
