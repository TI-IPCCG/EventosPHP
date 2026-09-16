<?php

use App\Models\Event;
use App\Models\Participantes\EventDay;
use App\Models\Participantes\Registration;
use App\Mail\CredencialMail;
use App\Services\Participantes\EnvioService;
use App\Services\Participantes\PersonResolver;
use App\Services\Participantes\RegistrationService;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Quem está inscrito, quem já veio, e quem recebeu a credencial.
 *
 * As três coisas moram na mesma tela de propósito: no dia a dia são a mesma
 * pergunta ("como está a lista?"), e separá-las obrigava a conferir duas telas
 * para saber o estado de uma pessoa só.
 *
 * O envio fica no topo, recolhido, porque é ação de campanha — acontece uma ou
 * duas vezes antes do evento, enquanto a lista é consultada o tempo todo.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Inscritos — Eventos IPCCG')]
class extends Component {
    #[Url(except: '')]
    public string $busca = '';

    /** todos | presentes | faltantes | sem_email | conflito */
    #[Url(except: 'todos')]
    public string $filtro = 'todos';

    public bool $mostrarForm = false;
    public bool $mostrarEnvio = false;

    // envio em lote
    public bool $rodando = false;
    public int $enviadosAgora = 0;
    public string $emailTeste = '';

    public string $nome = '';
    public string $email = '';
    public string $telefone = '';
    public string $cpf = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('ver-participantes'), 403);

        $this->emailTeste = (string) auth()->user()->email;
    }

    #[Computed]
    public function evento(): ?Event
    {
        return Event::atual();
    }

    #[Computed]
    public function diaDeHoje(): ?EventDay
    {
        return $this->evento ? EventDay::deHoje($this->evento->id) : null;
    }

    #[Computed]
    public function inscritos()
    {
        if (! $this->evento) {
            return collect();
        }

        $hoje = $this->diaDeHoje?->id;

        return Registration::where('event_id', $this->evento->id)
            ->when($this->busca !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('nome', 'like', '%'.$this->busca.'%')
                ->orWhere('email', 'like', '%'.$this->busca.'%')
                ->orWhere('codigo', 'like', '%'.$this->busca.'%')))
            ->when($this->filtro === 'sem_email', fn ($q) => $q->where('email_valido', false))
            ->when($this->filtro === 'sem_credencial', fn ($q) => $q
                ->whereNull('qr_enviado_em')->where('email_valido', true))
            ->when($this->filtro === 'conflito', fn ($q) => $q->where('conflito_identidade', true))
            ->when($this->filtro === 'presentes' && $hoje, fn ($q) => $q
                ->whereHas('checkins', fn ($c) => $c->where('event_day_id', $hoje)->whereNull('cancelado_em')))
            ->when($this->filtro === 'faltantes' && $hoje, fn ($q) => $q
                ->whereDoesntHave('checkins', fn ($c) => $c->where('event_day_id', $hoje)->whereNull('cancelado_em')))
            ->with(['checkins' => fn ($c) => $c->whereNull('cancelado_em')])
            ->orderBy('nome')
            ->limit(300)
            ->get();
    }

    #[Computed]
    public function totais(): array
    {
        if (! $this->evento) {
            return ['inscritos' => 0, 'sem_email' => 0, 'conflito' => 0];
        }

        $base = Registration::where('event_id', $this->evento->id)->ativas();

        $envio = app(EnvioService::class);

        return [
            'inscritos'  => (clone $base)->count(),
            'sem_email'  => (clone $base)->where('email_valido', false)->count(),
            'conflito'   => (clone $base)->where('conflito_identidade', true)->count(),
            'enviadas'   => $envio->enviados($this->evento),
            'na_fila'    => $envio->pendentes($this->evento),
            'falharam'   => $envio->falharam($this->evento),
        ];
    }

    // ── envio das credenciais ──────────────────────────────────────

    public function iniciarEnvio(): void
    {
        abort_unless(auth()->user()->can('participantes.enviar'), 403);

        $this->rodando = true;
        $this->enviadosAgora = 0;
    }

    public function pararEnvio(): void
    {
        $this->rodando = false;
    }

    /** Chamado pelo wire:poll enquanto $rodando. */
    public function enviarLote(EnvioService $envio): void
    {
        if (! $this->rodando || ! $this->evento) {
            return;
        }

        abort_unless(auth()->user()->can('participantes.enviar'), 403);

        $r = $envio->enviarLote($this->evento);
        $this->enviadosAgora += $r['enviados'];

        unset($this->totais, $this->inscritos);

        if ($r['restam'] === 0) {
            $this->rodando = false;
            $this->dispatch('toast', tipo: 'ok', titulo: 'Envio concluído',
                mensagem: $this->enviadosAgora.' credenciais enviadas.');
        }
    }

    /**
     * O teste para si mesmo, antes do disparo geral: é assim que se descobre
     * que o e-mail cai no spam ANTES de 60 pessoas não receberem.
     */
    public function enviarTeste(): void
    {
        abort_unless(auth()->user()->can('participantes.enviar'), 403);

        $this->validate(['emailTeste' => ['required', 'email:rfc']],
            attributes: ['emailTeste' => 'e-mail']);

        $amostra = Registration::where('event_id', $this->evento->id)->ativas()->first();

        if (! $amostra) {
            $this->dispatch('toast', tipo: 'aviso',
                mensagem: 'Inscreva alguém primeiro — o teste usa uma credencial de verdade.');

            return;
        }

        try {
            Mail::to($this->emailTeste)->send(new CredencialMail(
                $amostra,
                route('participantes.credencial', ['token' => $amostra->token]),
                null,
            ));
        } catch (\Throwable $e) {
            $this->dispatch('toast', tipo: 'erro', titulo: 'O envio falhou',
                mensagem: mb_substr($e->getMessage(), 0, 160));

            return;
        }

        $this->dispatch('toast', tipo: 'ok', titulo: 'Teste enviado',
            mensagem: 'Confira a caixa de '.$this->emailTeste.' — e o spam.');
    }

    /**
     * Manda a credencial de UMA pessoa, na hora.
     *
     * Deliberadamente NÃO devolve a pessoa para a fila do lote: quem clica aqui
     * quer que aquele e-mail saia agora — o caso é "a Maria trocou de endereço"
     * ou "chegou atrasada na lista", e não faz sentido rodar uma campanha
     * inteira para atender uma pessoa. Por isso o retorno é imediato e o toast
     * diz o que aconteceu, em vez de prometer um envio futuro.
     */
    public function enviarAgora(int $id, EnvioService $envio): void
    {
        abort_unless(auth()->user()->can('participantes.enviar'), 403);

        $inscricao = Registration::where('event_id', $this->evento->id)->findOrFail($id);

        if (! $inscricao->email_valido || ! $inscricao->email) {
            $this->dispatch('toast', tipo: 'aviso',
                mensagem: $inscricao->nome.' não tem e-mail válido cadastrado.');

            return;
        }

        $ok = $envio->enviarIndividual($inscricao);

        unset($this->totais, $this->inscritos);

        $ok
            ? $this->dispatch('toast', tipo: 'ok', titulo: 'Credencial enviada',
                mensagem: 'Para '.$inscricao->email)
            : $this->dispatch('toast', tipo: 'erro', titulo: 'O envio falhou',
                mensagem: mb_substr((string) $inscricao->fresh()->qr_erro, 0, 160));
    }

    public function novo(): void
    {
        abort_unless(auth()->user()->can('participantes.gerenciar'), 403);

        $this->reset(['nome', 'email', 'telefone', 'cpf']);
        $this->resetValidation();
        $this->mostrarForm = true;
    }

    public function salvar(RegistrationService $inscricoes): void
    {
        abort_unless(auth()->user()->can('participantes.gerenciar'), 403);

        $this->validate([
            'nome'     => ['required', 'string', 'min:3', 'max:150'],
            'email'    => ['nullable', 'email:rfc', 'max:191'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'cpf'      => ['nullable', 'string', 'max:14'],
        ], attributes: ['nome' => 'nome', 'email' => 'e-mail']);

        try {
            $inscricao = $inscricoes->inscrever($this->evento, [
                'nome'     => $this->nome,
                'email'    => $this->email ?: null,
                'telefone' => $this->telefone ?: null,
                'cpf'      => $this->cpf ?: null,
            ], origem: 'manual', operadorId: auth()->id());
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', tipo: 'erro', titulo: 'Não deu', mensagem: $e->getMessage());

            return;
        }

        $this->reset(['nome', 'email', 'telefone', 'cpf']);
        $this->mostrarForm = false;
        unset($this->inscritos, $this->totais);

        $this->dispatch('toast', tipo: 'ok', titulo: 'Inscrito',
            mensagem: $inscricao->nome.' · '.$inscricao->codigo);
    }

    public function cancelar(int $id, RegistrationService $inscricoes): void
    {
        abort_unless(auth()->user()->can('participantes.gerenciar'), 403);

        $inscricao = Registration::where('event_id', $this->evento->id)->findOrFail($id);
        $inscricoes->cancelar($inscricao, auth()->id());

        unset($this->inscritos, $this->totais);
        $this->dispatch('toast', tipo: 'info',
            mensagem: 'Inscrição de '.$inscricao->nome.' cancelada. O histórico fica.');
    }
}; ?>

<div class="cadastro">
    @if (! $this->evento)
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <h2>Nenhum evento ativo</h2>
            <p>Escolha um evento em <a href="{{ route('eventos') }}">Eventos</a>.</p>
        </div>
    @else
        <header class="painel-header">
            <div>
                <span class="painel-evento">{{ $this->evento->nome }}</span>
                <h1>Inscritos</h1>
            </div>
            <div class="card-acoes">
                @can('participantes.enviar')
                    <button type="button" class="btn-sm secondary"
                            wire:click="$toggle('mostrarEnvio')">
                        Credenciais
                        @if ($this->totais['na_fila'] > 0)
                            <span class="pill warn">{{ $this->totais['na_fila'] }}</span>
                        @endif
                    </button>
                @endcan
                @can('participantes.gerenciar')
                    <button type="button" class="btn-sm secondary" wire:click="novo">Inscrever alguém</button>
                @endcan
            </div>
        </header>

        <section class="stat-grid">
            <div class="stat-card">
                <span>Inscritos</span>
                <strong>{{ $this->totais['inscritos'] }}</strong>
            </div>
            <div class="stat-card">
                <span>Sem e-mail válido</span>
                <strong>{{ $this->totais['sem_email'] }}</strong>
            </div>
            <div class="stat-card">
                <span>Credenciais enviadas</span>
                <strong>{{ $this->totais['enviadas'] }} <small>de {{ $this->totais['inscritos'] }}</small></strong>
            </div>
            <div class="stat-card">
                <span>Identidade em conflito</span>
                <strong>{{ $this->totais['conflito'] }}</strong>
            </div>
        </section>

        {{-- ── envio das credenciais: recolhido, porque é ação de campanha ── --}}
        @can('participantes.enviar')
            @if ($mostrarEnvio)
                <section class="card" @if ($rodando) wire:poll.2s="enviarLote" @endif>
                    <h2 class="card-titulo"><i class="bi bi-qr-code"></i> Credenciais com QR</h2>

                    @if ($rodando)
                        <p class="ajuda" style="margin-top:0">
                            <strong>Enviando…</strong> {{ $this->enviadosAgora }} nesta sessão,
                            {{ $this->totais['na_fila'] }} restando. Pode fechar a página — o que
                            já saiu não é reenviado, e iniciar de novo continua daqui.
                        </p>
                        <div class="form-acoes">
                            <button type="button" class="btn-sm secondary" wire:click="pararEnvio">Pausar</button>
                        </div>
                    @elseif ($this->totais['na_fila'] === 0)
                        <p class="ajuda" style="margin-top:0">
                            Ninguém na fila.
                            @if ($this->totais['enviadas'] > 0)
                                As {{ $this->totais['enviadas'] }} credenciais já foram enviadas.
                            @endif
                        </p>
                    @else
                        <p class="ajuda" style="margin-top:0">
                            {{ $this->totais['na_fila'] }}
                            {{ $this->totais['na_fila'] == 1 ? 'pessoa aguarda' : 'pessoas aguardam' }}
                            a credencial. O envio vai em blocos e pode ser pausado.
                        </p>
                        <div class="form-acoes">
                            <button type="button" wire:click="iniciarEnvio">Iniciar envio</button>
                        </div>
                    @endif

                    <div class="divider" style="margin:14px 0"></div>

                    <form class="form" wire:submit="enviarTeste">
                        <div>
                            <label for="t-email">Enviar um teste para</label>
                            <input id="t-email" type="email" wire:model="emailTeste" maxlength="191" required>
                            <small class="ajuda">
                                Manda uma credencial de verdade, com o QR que as pessoas vão receber.
                                <strong>Faça isso antes do envio geral</strong> — e confira o spam.
                            </small>
                            @error('emailTeste') <span class="field-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="form-acoes">
                            <button type="submit" class="btn-sm secondary"
                                    wire:loading.attr="disabled" wire:target="enviarTeste">
                                Enviar teste
                            </button>
                        </div>
                    </form>
                </section>
            @endif
        @endcan

        @if ($this->totais['sem_email'] > 0)
            <div class="alert info" role="alert" style="margin-bottom:14px">
                <div class="alert-content">
                    <div class="alert-title">{{ $this->totais['sem_email'] }} sem e-mail válido</div>
                    <div class="alert-message">
                        Essas pessoas <strong>não vão receber a credencial</strong>. Avise por outro
                        canal antes do evento — na portaria elas entram pelo nome.
                    </div>
                </div>
            </div>
        @endif

        @if ($mostrarForm)
            <section class="card">
                <h2 class="card-titulo">Inscrever alguém</h2>
                <form class="form" wire:submit="salvar">
                    <div>
                        <label for="i-nome">Nome completo</label>
                        <input id="i-nome" type="text" wire:model="nome" maxlength="150" required>
                        @error('nome') <span class="field-error">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label for="i-email">E-mail</label>
                        <input id="i-email" type="email" wire:model="email" maxlength="191">
                        <small class="ajuda">É para cá que a credencial com o QR vai.</small>
                        @error('email') <span class="field-error">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label for="i-tel">Telefone</label>
                        <input id="i-tel" type="text" wire:model="telefone" inputmode="tel" maxlength="20">
                    </div>
                    <div>
                        <label for="i-cpf">CPF</label>
                        <input id="i-cpf" type="text" wire:model="cpf" inputmode="numeric" maxlength="14">
                        <small class="ajuda">Opcional — ajuda a reconhecer a pessoa no próximo evento.</small>
                        @error('cpf') <span class="field-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-acoes">
                        <button type="button" class="btn-sm secondary"
                                wire:click="$set('mostrarForm', false)">Cancelar</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="salvar">Inscrever</button>
                    </div>
                </form>
            </section>
        @endif

        <section class="card">
            <h2 class="card-titulo">Lista</h2>

            <div class="table-toolbar">
                <input type="search" class="table-search" wire:model.live.debounce.300ms="busca"
                       placeholder="Filtrar por nome, e-mail ou código">
                <select wire:model.live="filtro" aria-label="Filtro">
                    <option value="todos">Todos</option>
                    <option value="presentes">Presentes hoje</option>
                    <option value="faltantes">Ainda não chegaram</option>
                    <option value="sem_email">Sem e-mail válido</option>
                    <option value="sem_credencial">Sem credencial enviada</option>
                    <option value="conflito">Identidade em conflito</option>
                </select>
            </div>

            @forelse ($this->inscritos as $i)
                @php($presente = $this->diaDeHoje && $i->checkins->firstWhere('event_day_id', $this->diaDeHoje->id))
                <div class="linha-registro {{ $i->cancelada ? 'inativo' : '' }}" wire:key="i-{{ $i->id }}">
                    <div>
                        <strong>{{ $i->nome }}</strong>
                        @if ($presente) <span class="pill open">presente</span> @endif
                        @if ($i->cancelada) <span class="pill closed">cancelada</span> @endif
                        @unless ($i->email_valido) <span class="pill warn">sem e-mail</span> @endunless
                        @if ($i->email_valido && $i->qr_enviado_em) <span class="pill open">credencial enviada</span> @endif
                        @if ($i->conflito_identidade) <span class="pill draft">conferir</span> @endif
                        <small class="bloco">
                            {{ $i->codigo }}@if ($i->email) · {{ $i->email }}@endif
                            @if ($i->telefone) · {{ $i->telefone }} @endif
                            @if ($i->qr_erro) · <span style="color:var(--danger)">{{ $i->qr_erro }}</span> @endif
                        </small>
                    </div>
                    <div class="card-acoes">
                        @can('participantes.enviar')
                            @if ($i->email_valido && ! $i->cancelada)
                                <a class="btn btn-ghost btn-sm" target="_blank"
                                   href="{{ route('participantes.credencial', ['token' => $i->token]) }}">
                                    Ver credencial
                                </a>
                                <button type="button" class="btn-sm btn-ghost"
                                        wire:click="enviarAgora({{ $i->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="enviarAgora({{ $i->id }})">
                                    <span wire:loading.remove wire:target="enviarAgora({{ $i->id }})">
                                        {{ $i->qr_enviado_em ? 'Reenviar' : 'Enviar' }} credencial
                                    </span>
                                    <span wire:loading wire:target="enviarAgora({{ $i->id }})">Enviando…</span>
                                </button>
                            @endif
                        @endcan
                        @can('participantes.gerenciar')
                            @unless ($i->cancelada)
                                <button type="button" class="btn-sm btn-ghost"
                                        wire:click="cancelar({{ $i->id }})"
                                        wire:confirm="Cancelar a inscrição de {{ $i->nome }}? Ela deixa de valer, mas o histórico fica.">
                                    Cancelar
                                </button>
                            @endunless
                        @endcan
                    </div>
                </div>
            @empty
                <p class="vazio">
                    {{ $busca !== '' || $filtro !== 'todos'
                        ? 'Ninguém com esse filtro.'
                        : 'Ninguém inscrito ainda.' }}
                </p>
            @endforelse
        </section>
    @endif
</div>
