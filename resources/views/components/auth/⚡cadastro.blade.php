<?php

use App\Models\Church;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Auto-cadastro: a pessoa se apresenta, o responsável libera.
 *
 * O vínculo nasce PENDENTE (status = false), nunca ativo. O /painel não exige
 * permissão e mostra receita, custos e o devido aos fornecedores — cadastro que
 * já entrasse ativo entregaria o financeiro do evento a qualquer um. O login já
 * era preparado para isto: "Você não tem acesso ativo nesta congregação".
 *
 * Identidade é GLOBAL, vínculo é POR CONGREGAÇÃO (mesma regra do login). Então
 * quem já tem conta e se cadastra em outra congregação ganha só o vínculo novo:
 * o usuário existente NUNCA é tocado — nome e, principalmente, senha ficam como
 * estão. Sem isso, este formulário seria um sequestro de conta em uma etapa.
 *
 * A resposta é sempre a mesma, exista o e-mail ou não. É a mesma decisão que o
 * login tomou ao usar "Credenciais inválidas" para os dois casos: não entregar
 * quais e-mails estão cadastrados.
 */
new
#[Layout('components.layouts.app')]
#[Title('Criar conta — Eventos IPCCG')]
class extends Component {
    public ?int $church_id = null;
    public string $name = '';
    public string $email = '';
    public string $telefone = '';
    public string $password = '';
    public string $password_confirmation = '';

    /** Alterna o card para a confirmação, sem trocar de página. */
    public bool $enviado = false;

    public function mount(): void
    {
        if (Auth::check()) {
            $this->redirectIntended(route('painel'), navigate: true);
        }
    }

    public function congregacoes()
    {
        return Church::ativas()->orderBy('name')->get();
    }

    public function cadastrar()
    {
        $this->validate([
            'church_id' => ['required', 'integer'],
            'name'      => ['required', 'string', 'min:3', 'max:150'],
            'email'     => ['required', 'email', 'max:191'],
            'telefone'  => ['nullable', 'string', 'max:20'],
            'password'  => ['required', 'string', 'min:8', 'confirmed'],
        ], attributes: [
            'church_id' => 'congregação',
            'name'      => 'nome',
            'email'     => 'e-mail',
            'telefone'  => 'telefone',
            'password'  => 'senha',
        ]);

        // Trava de spam por IP: formulário público sem captcha precisa de teto.
        $chave = 'cadastro:'.request()->ip();

        if (RateLimiter::tooManyAttempts($chave, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Muitos cadastros deste dispositivo. Tente de novo em '
                    .RateLimiter::availableIn($chave).' segundos.',
            ]);
        }

        $igreja = Church::ativas()->find($this->church_id);

        if (! $igreja) {
            throw ValidationException::withMessages(['church_id' => 'Congregação indisponível.']);
        }

        RateLimiter::hit($chave, 600);

        DB::transaction(function () use ($igreja) {
            $user = User::where('email', $this->email)->first();

            if (! $user) {
                $user = User::create([
                    'name'       => $this->name,
                    'email'      => $this->email,
                    'telefone'   => $this->telefone ?: null,
                    'password'   => $this->password,   // cast "hashed" no model
                    'created_at' => now(),
                ]);
            }

            // firstOrCreate e não updateOrCreate: se o vínculo existe, seja
            // pendente ou já ativo, ele fica INTACTO — este formulário não pode
            // rebaixar quem já tem acesso, nem reativar quem foi desativado.
            Membership::firstOrCreate(
                ['user_id' => $user->id, 'church_id' => $igreja->id],
                ['system_role_id' => null, 'status' => false, 'created_at' => now()],
            );
        });

        $this->enviado = true;
    }
}; ?>

<div class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <h1 class="auth-brand-title">IPCCG</h1>
            <div class="auth-brand-sub">Eventos</div>
        </div>

        <div class="auth-body">
            @if ($enviado)
                <h2 class="auth-title">Cadastro enviado</h2>
                <p class="auth-subtitle">Falta a liberação do responsável.</p>

                <div class="alert ok" role="alert" aria-live="polite" style="margin-bottom:14px">
                    <span class="alert-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                    <div class="alert-content">
                        <div class="alert-title">Tudo certo por aqui</div>
                        <div class="alert-message">
                            Seu acesso precisa ser liberado pelo Administrador.
                            Assim que isso acontecer, você entra com o e-mail e a senha
                            que acabou de cadastrar.
                        </div>
                    </div>
                </div>

                <div class="auth-links">
                    <a class="link" href="{{ route('login') }}" wire:navigate>Ir para a tela de entrada</a>
                </div>
            @else
                <h2 class="auth-title">Criar conta</h2>
                <p class="auth-subtitle">Cadastre-se e peça liberação ao Administrador.</p>

                @if ($errors->any())
                    <div class="alert danger" role="alert" aria-live="assertive" style="margin-bottom:14px">
                        <span class="alert-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg></span>
                        <div class="alert-content">
                            <div class="alert-title">Não foi possível cadastrar</div>
                            <div class="alert-message">{{ $errors->first() }}</div>
                        </div>
                    </div>
                @endif

                <form class="form" wire:submit="cadastrar">
                    <div>
                        <label for="c-church">Congregação</label>
                        <select id="c-church" wire:model="church_id" required>
                            <option value="">Selecione…</option>
                            @foreach ($this->congregacoes() as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="c-name">Nome completo</label>
                        <input id="c-name" type="text" wire:model="name"
                               autocomplete="name" required>
                    </div>

                    <div>
                        <label for="c-email">E-mail</label>
                        <input id="c-email" type="email" wire:model="email"
                               autocomplete="username" required>
                    </div>

                    <div>
                        <label for="c-tel">Telefone</label>
                        <input id="c-tel" type="text" wire:model="telefone"
                               inputmode="tel" maxlength="20" placeholder="(67) 90000-0000"
                               autocomplete="tel">
                        <small>Opcional — é por aqui que o Administrador te acha para liberar.</small>
                    </div>

                    <div>
                        <label for="c-password">Senha</label>
                        <input id="c-password" type="password" wire:model="password"
                               autocomplete="new-password" required>
                        <small>Ao menos 8 caracteres.</small>
                    </div>

                    <div>
                        <label for="c-password2">Repita a senha</label>
                        <input id="c-password2" type="password" wire:model="password_confirmation"
                               autocomplete="new-password" required>
                    </div>

                    <button type="submit" wire:loading.attr="disabled" wire:target="cadastrar">
                        <span wire:loading.remove wire:target="cadastrar">Criar conta</span>
                        <span wire:loading wire:target="cadastrar">Enviando…</span>
                    </button>
                </form>

                <div class="auth-links">
                    <a class="link" href="{{ route('login') }}" wire:navigate>Já tenho conta — entrar</a>
                </div>
            @endif
        </div>
    </div>
</div>
