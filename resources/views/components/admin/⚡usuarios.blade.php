<?php

use App\Models\Membership;
use App\Models\SystemRole;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Pessoas da congregação: quem entra, com qual perfil, e quem ainda espera.
 *
 * Identidade é GLOBAL, acesso é POR CONGREGAÇÃO. Por isso quase tudo aqui mexe
 * no VÍNCULO (memberships), não no usuário: "remover" tira a pessoa desta
 * congregação e preserva a identidade dela — que pode servir em outra.
 *
 * A fila de espera fica no topo, separada: é o que exige ação. Quem se cadastra
 * pela tela pública nasce pendente e sem perfil, e sem esta tela a liberação
 * seria UPDATE no banco a cada voluntário novo.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Pessoas — Eventos IPCCG')]
class extends Component {
    public string $busca = '';

    // formulário
    public bool $mostrarForm = false;
    public ?int $editandoId = null;
    public string $nome = '';
    public string $email = '';
    public string $telefone = '';
    public string $senha = '';
    public ?int $system_role_id = null;
    public bool $ativo = true;
    public bool $is_super = false;

    // liberação rápida: perfil escolhido para cada pendente
    public array $perfilPendente = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('usuarios.ver'), 403);
    }

    private function podeGerenciar(): bool
    {
        return auth()->user()?->can('usuarios.gerenciar') ?? false;
    }

    /** Só super-admin mexe no super de alguém — inclusive para concedê-lo. */
    private function ehSuper(): bool
    {
        return auth()->user()?->can('super-admin') ?? false;
    }

    #[Computed]
    public function congregacao()
    {
        return \App\Models\Church::find(session('church_id'));
    }

    /**
     * Super-admin entra em QUALQUER congregação, inclusive numa onde não tem
     * vínculo — e aí a lista vem vazia com toda a razão. Sem dizer isso, a tela
     * parece quebrada.
     */
    #[Computed]
    public function souDeFora(): bool
    {
        return ! Membership::where('user_id', auth()->id())
            ->where('church_id', session('church_id'))->exists();
    }

    #[Computed]
    public function perfis()
    {
        return SystemRole::orderBy('name')->get();
    }

    /**
     * Fila = quem NUNCA foi liberado: vínculo inativo e ainda sem perfil, que é
     * como o auto-cadastro cria. Quem foi desativado também tem vínculo
     * inativo, mas guarda o perfil — e pertence à lista, marcado como inativo,
     * senão sumiria da tela sem deixar como reativar.
     */
    #[Computed]
    public function pendentes()
    {
        return User::naCongregacao()
            ->whereHas('memberships', fn ($q) => $q
                ->where('church_id', session('church_id'))
                ->where('status', false)
                ->whereNull('system_role_id'))
            ->with('memberships')
            ->orderBy('name')
            ->get();
    }

    /** Todo mundo já liberado alguma vez — ativo ou não. */
    #[Computed]
    public function pessoas()
    {
        return User::naCongregacao()
            ->whereHas('memberships', fn ($q) => $q
                ->where('church_id', session('church_id'))
                ->where(fn ($w) => $w->where('status', true)->orWhereNotNull('system_role_id')))
            ->when($this->busca !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$this->busca.'%')
                ->orWhere('email', 'like', '%'.$this->busca.'%')))
            ->with('memberships')
            ->orderBy('name')
            ->get();
    }

    private function vinculoDe(User $u): ?Membership
    {
        return $u->memberships->firstWhere('church_id', session('church_id'));
    }

    /** Exposto à view: a view não deve saber como o vínculo é encontrado. */
    public function vinculo(User $u): ?Membership
    {
        return $this->vinculoDe($u);
    }

    public function nomeDoPerfil(?int $id): string
    {
        return $id ? ($this->perfis->firstWhere('id', $id)->name ?? '—') : 'sem perfil';
    }

    // ── fila de espera ───────────────────────────────────────────────────
    public function liberar(int $userId): void
    {
        abort_unless($this->podeGerenciar(), 403);

        $perfil = $this->perfilPendente[$userId] ?? null;

        if (! $perfil) {
            $this->dispatch('toast', tipo: 'aviso', titulo: 'Escolha o perfil',
                mensagem: 'Sem perfil a pessoa entra, mas não enxerga nada além do Painel.');

            return;
        }

        $u = User::naCongregacao()->findOrFail($userId);
        $this->vinculoDe($u)?->update(['status' => true, 'system_role_id' => (int) $perfil]);

        unset($this->pendentes, $this->pessoas);
        $this->dispatch('toast', tipo: 'ok',
            mensagem: $u->name.' liberado como '.$this->nomeDoPerfil((int) $perfil).'.');
    }

    public function recusar(int $userId): void
    {
        abort_unless($this->podeGerenciar(), 403);

        $u = User::naCongregacao()->findOrFail($userId);
        $this->vinculoDe($u)?->delete();

        unset($this->pendentes, $this->pessoas);
        $this->dispatch('toast', tipo: 'info',
            mensagem: 'Pedido de '.$u->name.' recusado. A conta continua existindo; ela pode pedir de novo.');
    }

    // ── formulário ───────────────────────────────────────────────────────
    public function novo(): void
    {
        abort_unless($this->podeGerenciar(), 403);

        $this->reset(['editandoId', 'nome', 'email', 'telefone', 'senha', 'system_role_id', 'is_super']);
        $this->ativo = true;
        $this->resetValidation();
        $this->mostrarForm = true;
    }

    public function editar(int $userId): void
    {
        abort_unless($this->podeGerenciar(), 403);

        $u = User::naCongregacao()->findOrFail($userId);
        abort_if($u->is_super && ! $this->ehSuper(), 403);

        $v = $this->vinculoDe($u);

        $this->editandoId     = $u->id;
        $this->nome           = $u->name;
        $this->email          = $u->email;
        $this->telefone       = (string) $u->telefone;
        $this->senha          = '';
        $this->system_role_id = $v?->system_role_id;
        $this->ativo          = (bool) ($v?->status ?? true);
        $this->is_super       = (bool) $u->is_super;

        $this->resetValidation();
        $this->mostrarForm = true;
    }

    public function cancelar(): void
    {
        $this->mostrarForm = false;
        $this->reset(['editandoId', 'nome', 'email', 'telefone', 'senha', 'system_role_id', 'is_super']);
        $this->resetValidation();
    }

    public function salvar(): void
    {
        abort_unless($this->podeGerenciar(), 403);

        $igreja = session('church_id');

        $this->validate([
            'nome'           => ['required', 'string', 'max:150'],
            // Sem unique aqui: no CADASTRO um e-mail já existente é caso
            // legítimo — a pessoa serve em outra congregação e só falta o
            // vínculo. Quem checa duplicidade é cada ramo abaixo.
            'email'          => ['required', 'email', 'max:191'],
            'telefone'       => ['nullable', 'string', 'max:20'],
            'system_role_id' => ['nullable', 'integer', Rule::in($this->perfis->pluck('id')->all())],
            'ativo'          => ['boolean'],
        ], [
            'email.unique' => 'Já existe alguém com este e-mail.',
        ], [
            'nome' => 'nome', 'email' => 'e-mail', 'system_role_id' => 'perfil',
        ]);

        if ($this->editandoId) {
            $u = User::naCongregacao()->findOrFail($this->editandoId);
            abort_if($u->is_super && ! $this->ehSuper(), 403);

            // Na edição, sim: trocar o e-mail para o de outra pessoa colidiria
            // com a chave única de users.
            $this->validate(
                ['email' => [Rule::unique('users', 'email')->ignore($u->id)]],
                ['email.unique' => 'Já existe alguém com este e-mail.'],
            );

            if ($this->senha !== '') {
                $this->validate(['senha' => ['string', 'min:8']],
                    ['senha.min' => 'A senha deve ter ao menos 8 caracteres.']);
            }

            // Anti-lockout: sem isto o administrador consegue se desativar, ou
            // tirar o próprio super, e fica de fora do sistema que administra —
            // com ninguém para reverter senão pelo banco.
            if ($u->id === auth()->id()) {
                if (! $this->ativo) {
                    $this->addError('ativo', 'Você não pode desativar o seu próprio acesso.');

                    return;
                }

                if ($u->is_super && ! $this->is_super) {
                    $this->addError('is_super', 'Você não pode remover o seu próprio super administrador.');

                    return;
                }
            }

            $dados = [
                'name'     => $this->nome,
                'email'    => $this->email,
                'telefone' => $this->telefone ?: null,
            ];

            if ($this->senha !== '') {
                $dados['password'] = $this->senha;      // cast "hashed" no model
            }

            if ($this->ehSuper()) {
                $dados['is_super'] = $this->is_super;
            }

            $u->update($dados);

            Membership::updateOrCreate(
                ['user_id' => $u->id, 'church_id' => $igreja],
                ['system_role_id' => $this->system_role_id ?: null, 'status' => $this->ativo],
            );

            $this->dispatch('toast', tipo: 'ok', mensagem: $u->name.' atualizado.');
        } else {
            // Identidade é global: se o e-mail já existe, só falta o VÍNCULO
            // com esta congregação. Nunca se cria um segundo usuário para a
            // mesma pessoa, nem se mexe na senha da conta que já existe.
            $existente = User::where('email', $this->email)->first();

            if ($existente) {
                if (Membership::where('user_id', $existente->id)->where('church_id', $igreja)->exists()) {
                    $this->addError('email', 'Essa pessoa já está nesta congregação.');

                    return;
                }

                Membership::create([
                    'user_id'        => $existente->id,
                    'church_id'      => $igreja,
                    'system_role_id' => $this->system_role_id ?: null,
                    'status'         => $this->ativo,
                    'created_at'     => now(),
                ]);

                $this->dispatch('toast', tipo: 'ok', titulo: 'Já tinha conta',
                    mensagem: $existente->name.' foi vinculado a esta congregação. A senha dela não mudou.');
            } else {
                $this->validate(['senha' => ['required', 'string', 'min:8']], [
                    'senha.required' => 'Defina uma senha para o primeiro acesso.',
                    'senha.min'      => 'A senha deve ter ao menos 8 caracteres.',
                ]);

                $novo = User::create([
                    'name'     => $this->nome,
                    'email'    => $this->email,
                    'telefone' => $this->telefone ?: null,
                    'password' => $this->senha,
                    'is_super' => $this->ehSuper() ? $this->is_super : false,
                ]);

                Membership::create([
                    'user_id'        => $novo->id,
                    'church_id'      => $igreja,
                    'system_role_id' => $this->system_role_id ?: null,
                    'status'         => $this->ativo,
                    'created_at'     => now(),
                ]);

                $this->dispatch('toast', tipo: 'ok', mensagem: $novo->name.' cadastrado.');
            }
        }

        unset($this->pendentes, $this->pessoas);
        $this->cancelar();
    }

    public function alternarAtivo(int $userId): void
    {
        abort_unless($this->podeGerenciar(), 403);

        $u = User::naCongregacao()->findOrFail($userId);
        abort_if($u->is_super && ! $this->ehSuper(), 403);

        if ($u->id === auth()->id()) {
            $this->dispatch('toast', tipo: 'erro', titulo: 'Não dá',
                mensagem: 'Você não pode desativar o seu próprio acesso.');

            return;
        }

        $v = $this->vinculoDe($u);
        $v?->update(['status' => ! $v->status]);

        unset($this->pendentes, $this->pessoas);
        $this->dispatch('toast', tipo: $v->status ? 'ok' : 'info',
            mensagem: $u->name.($v->status ? ' reativado.' : ' desativado — não entra mais, mas o histórico fica.'));
    }
}; ?>

<div class="cadastro">
    <header class="painel-header">
        <div>
            <h1>Pessoas</h1>
            <p class="subtitulo">Quem entra no app, com qual perfil, e quem ainda espera liberação.</p>
        </div>
        @can('usuarios.gerenciar')
            <button type="button" class="btn-sm secondary" wire:click="novo">Cadastrar pessoa</button>
        @endcan
    </header>

    {{-- ── fila de espera: no topo porque é o que exige ação ── --}}
    @if ($this->pendentes->isNotEmpty())
        <section class="card">
            <h2 class="card-titulo">
                <i class="bi bi-hourglass-split"></i>
                Aguardando liberação
                <span class="pill warn">{{ $this->pendentes->count() }}</span>
            </h2>

            @can('usuarios.gerenciar')
                <p class="ajuda" style="margin-top:0">
                    Cadastraram-se pela tela pública. <strong>Escolha o perfil</strong> e libere —
                    sem perfil a pessoa entra, mas não enxerga nada além do Painel e da Ajuda.
                </p>
            @else
                <p class="ajuda" style="margin-top:0">
                    Cadastraram-se pela tela pública e aguardam liberação. Você pode ver a fila,
                    mas quem libera é quem tem permissão para gerenciar pessoas.
                </p>
            @endcan

            @foreach ($this->pendentes as $p)
                <div class="linha-registro">
                    <div>
                        <strong>{{ $p->name }}</strong>
                        <small class="bloco">
                            {{ $p->email }}@if ($p->telefone) · {{ $p->telefone }} @endif
                        </small>
                    </div>
                    @can('usuarios.gerenciar')
                        <div class="card-acoes">
                            <select wire:model="perfilPendente.{{ $p->id }}" aria-label="Perfil de {{ $p->name }}">
                                <option value="">Perfil…</option>
                                @foreach ($this->perfis as $perfil)
                                    <option value="{{ $perfil->id }}">{{ $perfil->name }}</option>
                                @endforeach
                            </select>
                            <button type="button" class="btn-sm" wire:click="liberar({{ $p->id }})">
                                Liberar
                            </button>
                            <button type="button" class="btn-sm btn-danger" wire:click="recusar({{ $p->id }})"
                                    wire:confirm="Recusar o pedido de {{ $p->name }}? A conta continua existindo e ela pode pedir de novo.">
                                Recusar
                            </button>
                        </div>
                    @endcan
                </div>
            @endforeach
        </section>
    @endif

    <div class="cadastro-grid">
        {{-- ── formulário ── --}}
        @if ($mostrarForm)
            <section class="card">
                <h2 class="card-titulo">{{ $editandoId ? 'Editar pessoa' : 'Nova pessoa' }}</h2>

                <form class="form" wire:submit="salvar">
                    <div>
                        <label for="u-nome">Nome</label>
                        <input id="u-nome" type="text" wire:model="nome" maxlength="150" required>
                        @error('nome') <span class="field-error">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="u-email">E-mail</label>
                        <input id="u-email" type="email" wire:model="email" maxlength="191" required>
                        <small class="ajuda">É com ele que a pessoa entra. Serve em todas as congregações.</small>
                        @error('email') <span class="field-error">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="u-tel">Telefone</label>
                        <input id="u-tel" type="text" wire:model="telefone" inputmode="tel" maxlength="20">
                        @error('telefone') <span class="field-error">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="u-senha">{{ $editandoId ? 'Nova senha' : 'Senha' }}</label>
                        <input id="u-senha" type="password" wire:model="senha" autocomplete="new-password">
                        <small class="ajuda">
                            {{ $editandoId
                                ? 'Deixe vazio para não mexer na senha atual.'
                                : 'Ao menos 8 caracteres. A pessoa pode trocar depois em "Esqueci minha senha".' }}
                        </small>
                        @error('senha') <span class="field-error">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="u-perfil">Perfil</label>
                        <select id="u-perfil" wire:model="system_role_id">
                            <option value="">Sem perfil — entra, mas não faz nada</option>
                            @foreach ($this->perfis as $perfil)
                                <option value="{{ $perfil->id }}">{{ $perfil->name }}</option>
                            @endforeach
                        </select>
                        <small class="ajuda">É o perfil que decide o que ela enxerga no menu.</small>
                        @error('system_role_id') <span class="field-error">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="u-ativo">
                            <input id="u-ativo" type="checkbox" wire:model="ativo"> Acesso ativo
                        </label>
                        @error('ativo') <span class="field-error">{{ $message }}</span> @enderror
                    </div>

                    @can('super-admin')
                        <div>
                            <label for="u-super">
                                <input id="u-super" type="checkbox" wire:model="is_super"> Super administrador
                            </label>
                            <small class="ajuda">Passa por cima de qualquer permissão, em qualquer congregação.</small>
                            @error('is_super') <span class="field-error">{{ $message }}</span> @enderror
                        </div>
                    @endcan

                    <div class="form-acoes">
                        <button type="button" class="btn-sm secondary" wire:click="cancelar">Cancelar</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="salvar">
                            {{ $editandoId ? 'Salvar' : 'Cadastrar' }}
                        </button>
                    </div>
                </form>
            </section>
        @endif

        {{-- ── lista ── --}}
        <section class="card">
            <h2 class="card-titulo">
                Em {{ $this->congregacao?->name ?? 'esta congregação' }}
            </h2>

            @if ($this->souDeFora)
                <div class="alert info" role="alert" style="margin-bottom:12px">
                    <div class="alert-content">
                        <div class="alert-title">Você está vendo outra congregação</div>
                        <div class="alert-message">
                            Seu acesso é de super administrador, então você entra em qualquer
                            congregação — inclusive numa em que não tem vínculo. O que aparece
                            aqui é a lista
                            <strong>{{ $this->congregacao?->name ?? 'da congregação escolhida' }}</strong>.
                            Para ver a sua, saia e entre escolhendo-a no login.
                        </div>
                    </div>
                </div>
            @endif

            <div class="table-toolbar">
                <input type="search" class="table-search" wire:model.live.debounce.300ms="busca"
                       placeholder="Filtrar por nome ou e-mail">
            </div>

            @forelse ($this->pessoas as $p)
                @php($v = $this->vinculo($p))
                <div class="linha-registro {{ $v && $v->status ? '' : 'inativo' }}">
                    <div>
                        <strong>{{ $p->name }}</strong>
                        @if ($v && $v->status)
                            <span class="pill open">ativo</span>
                        @else
                            <span class="pill closed">inativo</span>
                        @endif
                        @if ($p->is_super) <span class="pill">super</span> @endif
                        @if ($p->id === auth()->id()) <span class="pill">você</span> @endif
                        <small class="bloco">
                            {{ $p->email }}@if ($p->telefone) · {{ $p->telefone }} @endif
                            · {{ $this->nomeDoPerfil($v?->system_role_id) }}
                        </small>
                    </div>
                    @can('usuarios.gerenciar')
                        <div class="card-acoes">
                            <button type="button" class="btn-sm secondary" wire:click="editar({{ $p->id }})">
                                Editar
                            </button>
                            @if ($p->id !== auth()->id())
                                <button type="button" class="btn-sm btn-ghost"
                                        wire:click="alternarAtivo({{ $p->id }})"
                                        @if ($v && $v->status)
                                            wire:confirm="Desativar {{ $p->name }}? Ela deixa de entrar; o histórico não muda."
                                        @endif>
                                    {{ $v && $v->status ? 'Desativar' : 'Reativar' }}
                                </button>
                            @endif
                        </div>
                    @endcan
                </div>
            @empty
                <p class="vazio">
                    @if ($busca !== '')
                        Ninguém com esse nome ou e-mail.
                    @else
                        Ninguém com acesso ativo em
                        {{ $this->congregacao?->name ?? 'esta congregação' }}.
                    @endif
                </p>
            @endforelse
        </section>
    </div>
</div>
