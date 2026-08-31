<?php

use App\Models\Event;
use App\Models\Livraria\Copy;
use App\Services\Livraria\EventResult;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * PAINEL AO VIVO do evento, para o coordenador.
 *
 * Com meta zero a zero, a informação do topo não é lucro — é quanto FALTA
 * vender para cobrir os custos. É a única pergunta capaz de mudar uma decisão
 * no meio do dia.
 *
 * O bloco "Exige atenção" é o que transforma isto de relatório em ferramenta:
 * saber que um título está no último exemplar permite avisar a mesa e anotar o
 * título para a próxima remessa enquanto a demanda ainda está visível.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Painel — Eventos IPCCG')]
class extends Component {
    #[Computed]
    public function evento(): ?Event
    {
        return Event::atual()?->loadMissing('livrariaSettings');
    }

    #[Computed]
    public function apuracao(): array
    {
        return $this->evento ? EventResult::para($this->evento)->apuracao() : [];
    }

    #[Computed]
    public function faltaParaMeta(): float
    {
        return $this->evento ? EventResult::para($this->evento)->faltaParaMeta() : 0.0;
    }

    #[Computed]
    public function exemplares(): object
    {
        return DB::table('liv_copies')
            ->where('event_id', $this->evento->id)
            ->selectRaw("COUNT(*) total,
                         SUM(status = 'vendido')  vendidos,
                         SUM(status = 'baixado')  baixados")
            ->first();
    }

    /** Títulos esgotados ou no fim: 2 exemplares ou menos. */
    #[Computed]
    public function atencao()
    {
        return DB::table('liv_shipment_items as si')
            ->join('liv_products as p', 'p.id', '=', 'si.product_id')
            ->join('liv_shipments as s', 's.id', '=', 'si.shipment_id')
            ->leftJoin('liv_variants as v', 'v.id', '=', 'si.variant_id')
            ->leftJoin('liv_copies as c', function ($j) {
                $j->on('c.shipment_item_id', '=', 'si.id')->where('c.status', '=', Copy::DISPONIVEL);
            })
            ->where('s.event_id', $this->evento->id)
            ->groupBy('si.id', 'p.nome', 'v.nome', 'v.ordem')
            ->havingRaw('COUNT(c.id) <= 2')
            ->orderByRaw('COUNT(c.id) ASC')
            ->orderBy('p.nome')->orderBy('v.ordem')->orderBy('v.id')
            ->selectRaw('p.nome as item, v.nome as variacao, COUNT(c.id) as restam')
            ->limit(12)
            ->get();
    }

    /** Percentual da meta já coberto, limitado a 100 para a barra não estourar. */
    public function progresso(): float
    {
        $receita = $this->apuracao['receita'] ?? 0;
        $alvo    = ($this->apuracao['devido'] ?? 0) + ($this->apuracao['custos'] ?? 0) + ($this->apuracao['taxas'] ?? 0);

        return $alvo > 0 ? min(100, round($receita / $alvo * 100, 1)) : 0.0;
    }
}; ?>

<div class="painel">
    @if (! $this->evento)
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <h2>Nenhum evento cadastrado</h2>
            <p>Crie um evento para começar a acompanhar a livraria.</p>
        </div>
    @else
        @php($a = $this->apuracao)

        <header class="painel-header">
            <div>
                <span class="painel-evento">{{ $this->evento->nome }}</span>
                <h1>Painel</h1>
            </div>
            @if ($this->evento->status === 'em_andamento')
                <span class="pill vaga">ao vivo</span>
            @else
                <span class="pill draft">{{ str($this->evento->status)->replace('_', ' ') }}</span>
            @endif
        </header>

        {{-- ── META: o número que muda decisão ─────────────────── --}}
        <section class="card meta-card">
            <span class="meta-label">Meta · zero a zero</span>

            <div class="meta-barra" role="img"
                 aria-label="{{ $this->progresso() }}% dos custos cobertos">
                <div class="meta-preenchida" style="width: {{ $this->progresso() }}%"></div>
            </div>

            <strong class="meta-valor">R$ {{ number_format($a['receita'], 2, ',', '.') }}</strong>

            @if ($this->faltaParaMeta > 0)
                <p class="meta-falta">
                    faltam <strong>R$ {{ number_format($this->faltaParaMeta, 2, ',', '.') }}</strong>
                    para cobrir os custos
                </p>
            @else
                <p class="meta-sobra">
                    sobra de <strong>R$ {{ number_format(abs($this->faltaParaMeta), 2, ',', '.') }}</strong>
                    <small>espaço não usado: poderia ter virado preço menor ou mais títulos</small>
                </p>
            @endif
        </section>

        {{-- ── NÚMEROS DO EVENTO ───────────────────────────────── --}}
        <section class="stat-grid">
            <div class="stat-card">
                <span>Exemplares vendidos</span>
                <strong>{{ $this->exemplares->vendidos }} <small>de {{ $this->exemplares->total }}</small></strong>
            </div>
            <div class="stat-card">
                <span>Devido aos fornecedores</span>
                <strong>R$ {{ number_format($a['devido'], 2, ',', '.') }}</strong>
            </div>
            <div class="stat-card">
                <span>Custos do evento</span>
                <strong>R$ {{ number_format($a['custos'], 2, ',', '.') }}</strong>
            </div>
            <div class="stat-card">
                <span>Baixas sem venda</span>
                <strong>{{ $this->exemplares->baixados }}</strong>
            </div>
        </section>

        {{-- ── EXIGE ATENÇÃO ───────────────────────────────────── --}}
        <section class="card">
            <h2 class="card-titulo"><i class="bi bi-exclamation-triangle"></i> Exige atenção</h2>

            @forelse ($this->atencao as $item)
                <div class="atencao-linha">
                    <span class="pill {{ $item->restam == 0 ? 'danger' : 'warn' }}">
                        {{ $item->restam == 0 ? 'esgotado' : ($item->restam == 1 ? 'resta 1' : "restam {$item->restam}") }}
                    </span>
                    <span>{{ $item->item }}@if ($item->variacao) · {{ $item->variacao }}@endif</span>
                </div>
            @empty
                <p class="vazio">Nenhum item no fim do estoque. Tudo tranquilo.</p>
            @endforelse
        </section>

        {{-- ── COMPOSIÇÃO DO PAGAMENTO ─────────────────────────── --}}
        @if ($a['pagamentos'])
            <section class="card">
                <h2 class="card-titulo"><i class="bi bi-credit-card"></i> Pagamento</h2>
                <div class="pagamento-mix">
                    @foreach ($a['pagamentos'] as $p)
                        <div>
                            <strong>{{ $p['forma'] }}</strong>
                            <span>R$ {{ number_format($p['valor'], 2, ',', '.') }}</span>
                            <small>{{ $p['transacoes'] }} {{ $p['transacoes'] == 1 ? 'transação' : 'transações' }}</small>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    @endif
</div>
