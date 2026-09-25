<?php

use App\Models\Event;
use App\Services\Participantes\ImportService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * IMPORTAR A PLANILHA DE INSCRIÇÕES.
 *
 * Em dois passos, e o primeiro existe por um motivo: importar é irreversível
 * na prática (dá para cancelar inscrição, mas ninguém vai cancelar sessenta
 * uma a uma). Antes de gravar, a tela mostra o que ENTENDEU da planilha — qual
 * coluna é o nome, qual é o e-mail, as primeiras linhas já lidas — e deixa
 * corrigir. O palpite acerta o caso comum; a conferência cobre o resto.
 *
 * Aceita arquivo E texto colado porque a origem é um JotForm sem exportação
 * confiável: já aconteceu de o acesso de edição não existir e a saída ser
 * selecionar a tabela na tela e copiar.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Importar inscrições — Eventos IPCCG')]
class extends Component {
    use WithFileUploads;

    public string $texto = '';
    public $arquivo;

    /** Passo 2: o que a análise entendeu, guardado entre os cliques. */
    public array $cabecalho = [];
    public array $linhas = [];
    public array $mapa = [];

    /** Colunas que viram o recado da portaria (camiseta, tamanho…). */
    public array $observacoes = [];

    public bool $analisado = false;

    /** Relatório do que aconteceu. */
    public ?array $relatorio = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('participantes.importar'), 403);
    }

    #[Computed]
    public function evento(): ?Event
    {
        return Event::atual();
    }

    /** Os campos que a planilha pode alimentar, na ordem em que a tela pergunta. */
    public function campos(): array
    {
        return [
            'nome'        => 'Nome',
            'email'       => 'E-mail',
            'telefone'    => 'Telefone',
            'cpf'         => 'CPF',
            'inscrita_em' => 'Data da inscrição',
        ];
    }

    public function analisar(ImportService $import): void
    {
        abort_unless(auth()->user()->can('participantes.importar'), 403);

        $conteudo = $this->arquivo
            ? file_get_contents($this->arquivo->getRealPath())
            : $this->texto;

        try {
            $lido = $import->analisar((string) $conteudo);
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', tipo: 'erro', titulo: 'Não deu para ler',
                mensagem: $e->getMessage());

            return;
        }

        $this->cabecalho   = $lido['cabecalho'];
        $this->linhas      = $lido['linhas'];
        $this->mapa        = $import->mapear($lido['cabecalho']);
        $this->observacoes = $import->sugerirObservacoes($lido['cabecalho']);
        $this->analisado   = true;
        $this->relatorio = null;

        $this->dispatch('toast', tipo: 'ok', titulo: 'Planilha lida',
            mensagem: count($this->linhas).' linhas. Confira o que entendi antes de importar.');
    }

    public function recomecar(): void
    {
        $this->reset(['texto', 'arquivo', 'cabecalho', 'linhas', 'mapa',
                      'observacoes', 'analisado', 'relatorio']);
    }

    public function importar(ImportService $import): void
    {
        abort_unless(auth()->user()->can('participantes.importar'), 403);

        if (! $this->evento) {
            $this->dispatch('toast', tipo: 'erro', mensagem: 'Nenhum evento em andamento.');

            return;
        }

        try {
            $this->relatorio = $import->importar(
                $this->evento,
                $this->linhas,
                // vêm do <select> como string; o serviço compara índices
                array_map(fn ($v) => $v === '' || $v === null ? null : (int) $v, $this->mapa),
                $this->cabecalho,
                auth()->id(),
                array_map('intval', $this->observacoes),
            );
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', tipo: 'erro', titulo: 'Importação não começou',
                mensagem: $e->getMessage());

            return;
        }

        $r = $this->relatorio;

        $this->dispatch('toast', tipo: $r['erros'] ? 'aviso' : 'ok',
            titulo: $r['importados'].' '.str('inscrição')->plural($r['importados']).' criadas',
            mensagem: $r['repetidos'].' já estavam · '.count($r['erros']).' com problema');
    }
}; ?>

<div class="cadastro">
    <header class="painel-header">
        <div>
            <h1>Importar inscrições</h1>
            <p class="subtitulo">
                @if ($this->evento)
                    Para <strong>{{ $this->evento->nome }}</strong>.
                @else
                    Nenhum evento em andamento.
                @endif
            </p>
        </div>
        @if ($analisado)
            <button type="button" class="btn-sm secondary" wire:click="recomecar">Recomeçar</button>
        @endif
    </header>

    {{-- ── passo 1: a planilha ── --}}
    @unless ($analisado)
        <section class="card">
            <h2 class="card-titulo">1. A planilha</h2>

            <div class="alert warn" role="note">
                Pode <strong>colar</strong> direto da tela do formulário (selecione a tabela e
                copie) ou <strong>escolher um arquivo</strong> CSV/TSV. A primeira linha tem de
                ser o cabeçalho com o nome das colunas.
            </div>

            <label for="i-texto">Colar aqui</label>
            <textarea id="i-texto" wire:model="texto" rows="8"
                      placeholder="Submission Date&#9;Nome completo&#9;WhatsApp&#9;E-mail…"></textarea>

            <p class="ajuda" style="margin:.8rem 0 .4rem">ou</p>

            <input type="file" wire:model="arquivo" accept=".csv,.tsv,.txt">
            <div wire:loading wire:target="arquivo" class="ajuda">Enviando…</div>
            @error('arquivo') <span class="field-error">{{ $message }}</span> @enderror

            <div class="venda-acoes">
                <button type="button" wire:click="analisar"
                        wire:loading.attr="disabled" wire:target="analisar,arquivo">
                    Ler a planilha
                </button>
            </div>
        </section>
    @endunless

    {{-- ── passo 2: conferir e importar ── --}}
    @if ($analisado)
        <section class="card">
            <h2 class="card-titulo">2. Confira o que eu entendi</h2>

            <p class="venda-dica">
                <strong>{{ count($linhas) }}</strong>
                {{ count($linhas) == 1 ? 'linha' : 'linhas' }} na planilha.
                O palpite abaixo acerta o caso comum — corrija se alguma coluna estiver trocada.
            </p>

            <div class="cadastro-grid">
                @foreach ($this->campos() as $campo => $rotulo)
                    <div>
                        <label for="m-{{ $campo }}">
                            {{ $rotulo }}
                            @if ($campo === 'nome') <strong style="color:var(--danger)">*</strong> @endif
                        </label>
                        <select id="m-{{ $campo }}" wire:model="mapa.{{ $campo }}">
                            <option value="">— não tem na planilha —</option>
                            @foreach ($cabecalho as $i => $titulo)
                                <option value="{{ $i }}">{{ $titulo ?: 'coluna '.($i + 1) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>

            <div class="alert ok" role="note" style="margin-top:.8rem">
                As colunas que você <strong>não</strong> mapear não se perdem: viram respostas da
                inscrição (igreja, cidade…) e aparecem na ficha da pessoa.
            </div>

            {{-- ── o que a portaria precisa ver ──
                 Camiseta encomendada é tarefa, não cadastro: alguém tem de
                 separar a peça. Dentro do JSON de respostas ninguém lê a tempo,
                 então estas colunas viram um recado que aparece no check-in. --}}
            <h3 class="bloco-titulo">Avisar a portaria sobre…</h3>
            <p class="venda-dica">
                Marque o que o voluntário precisa ver <strong>no momento do check-in</strong> —
                pedido de camiseta, tamanho, qualquer coisa para entregar.
            </p>

            <div class="obs-colunas">
                @foreach ($cabecalho as $i => $titulo)
                    @if (trim($titulo) !== '')
                        <label class="obs-coluna">
                            <input type="checkbox" value="{{ $i }}" wire:model="observacoes">
                            <span>{{ $titulo }}</span>
                        </label>
                    @endif
                @endforeach
            </div>

            <h3 class="bloco-titulo">Primeiras linhas, como vão entrar</h3>
            <div style="overflow-x:auto">
                <table class="tabela">
                    <thead>
                        <tr>
                            @foreach ($this->campos() as $campo => $rotulo)
                                <th>{{ $rotulo }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (array_slice($linhas, 0, 5) as $linha)
                            <tr>
                                @foreach ($this->campos() as $campo => $rotulo)
                                    @php($idx = $mapa[$campo] ?? null)
                                    <td>{{ $idx === '' || $idx === null ? '—' : ($linha[(int) $idx] ?? '—') }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="alert warn" role="note" style="margin-top:.8rem">
                <strong>Reimportar não duplica.</strong> Quem já tem inscrição ativa neste evento é
                contado como "já estava" e segue com o mesmo código e o mesmo QR.
            </div>

            <div class="venda-acoes">
                <button type="button" class="secondary" wire:click="recomecar">Voltar</button>
                <button type="button" wire:click="importar"
                        wire:loading.attr="disabled" wire:target="importar"
                        wire:confirm="Importar {{ count($linhas) }} linhas para {{ $this->evento?->nome }}?">
                    Importar {{ count($linhas) }} {{ count($linhas) == 1 ? 'linha' : 'linhas' }}
                </button>
            </div>
            <div wire:loading wire:target="importar" class="ajuda">
                Importando… não feche a página.
            </div>
        </section>
    @endif

    {{-- ── relatório ── --}}
    @if ($relatorio)
        <section class="card">
            <h2 class="card-titulo">Resultado</h2>

            <div class="totais">
                <div><span>Inscritas agora</span><strong>{{ $relatorio['importados'] }}</strong></div>
                <div><span>Já estavam</span><strong>{{ $relatorio['repetidos'] }}</strong></div>
                <div><span>Com problema</span><strong>{{ count($relatorio['erros']) }}</strong></div>
            </div>

            @if ($relatorio['erros'])
                <h3 class="bloco-titulo">O que não entrou</h3>
                <p class="venda-dica">
                    Estas linhas ficaram de fora — as outras entraram normalmente. Corrija na
                    planilha e importe de novo, ou cadastre à mão em Inscritos.
                </p>
                <ul class="resumo-itens">
                    @foreach ($relatorio['erros'] as $erro)
                        <li>
                            <span>
                                <strong>{{ $erro['nome'] }}</strong>
                                <small class="codigo">linha {{ $erro['linha'] }}</small>
                            </span>
                            <span class="valor" style="color:var(--danger)">{{ $erro['motivo'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="venda-acoes">
                <a class="btn" href="{{ route('participantes.inscritos') }}">Ver os inscritos</a>
            </div>
        </section>
    @endif
</div>
