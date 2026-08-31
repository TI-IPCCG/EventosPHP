<?php

use App\Models\Church;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('components.layouts.app')]
#[Title('Entrar — Eventos IPCCG')]
class extends Component {
    public ?int $church_id = null;
    public string $email = '';
    public string $password = '';

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

    /**
     * Login = identidade + vínculo.
     *
     * O e-mail e a senha identificam a PESSOA (identidade global). A
     * congregação escolhida precisa ter um vínculo ATIVO — exceto super-admin,
     * que entra em qualquer congregação ativa.
     */
    public function authenticate()
    {
        $this->validate([
            'church_id' => ['required', 'integer'],
            'email'     => ['required', 'email'],
            'password'  => ['required'],
        ], attributes: [
            'church_id' => 'congregação',
            'email'     => 'e-mail',
            'password'  => 'senha',
        ]);

        // Throttle por e-mail + IP: 5 tentativas por minuto.
        $chave = 'login:'.mb_strtolower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($chave, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Muitas tentativas. Tente de novo em '
                    .RateLimiter::availableIn($chave).' segundos.',
            ]);
        }

        $igreja = Church::ativas()->find($this->church_id);

        if (! $igreja) {
            throw ValidationException::withMessages(['church_id' => 'Congregação indisponível.']);
        }

        // ⚠ withoutGlobalScopes() não é necessário em User (ele não tem escopo
        // de igreja de propósito), mas a busca é explícita para deixar claro
        // que aqui ainda NÃO existe church_id na sessão.
        $user = User::where('email', $this->email)->first();

        if (! $user || ! Hash::check($this->password, $user->password)) {
            RateLimiter::hit($chave, 60);

            // Mensagem única para não revelar se o e-mail existe.
            throw ValidationException::withMessages(['email' => 'Credenciais inválidas.']);
        }

        $vinculo = Membership::where('user_id', $user->id)
            ->where('church_id', $igreja->id)
            ->ativos()
            ->first();

        if (! $vinculo && ! $user->is_super) {
            RateLimiter::hit($chave, 60);

            throw ValidationException::withMessages([
                'church_id' => 'Você não tem acesso ativo nesta congregação. '
                    .'Fale com o responsável para liberar.',
            ]);
        }

        RateLimiter::clear($chave);

        Auth::login($user, remember: true);
        session()->regenerate();
        session([
            'church_id' => $igreja->id,
            'church_tz' => $igreja->timezone ?: 'America/Sao_Paulo',
        ]);

        $this->redirectIntended(route('painel'), navigate: true);
    }
}; ?>

<div class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <h1 class="auth-brand-title">IPCCG</h1>
            <div class="auth-brand-sub">Eventos</div>
        </div>

        <div class="auth-body">
            <h2 class="auth-title">Entrar</h2>
            <p class="auth-subtitle">Escolha a congregação e informe seus dados.</p>

            @if ($errors->any())
                <div class="alert danger" role="alert" aria-live="assertive" style="margin-bottom:14px">
                    <div class="alert-content">
                        <div class="alert-title">Não foi possível entrar</div>
                        <div class="alert-message">{{ $errors->first() }}</div>
                    </div>
                </div>
            @endif

            <form class="form" wire:submit="authenticate">
                <div>
                    <label for="l-church">Congregação</label>
                    <select id="l-church" wire:model="church_id" required>
                        <option value="">Selecione…</option>
                        @foreach ($this->congregacoes() as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="email">E-mail</label>
                    <input id="email" type="email" wire:model="email"
                           autocomplete="username" required>
                </div>

                <div>
                    <label for="password">Senha</label>
                    <input id="password" type="password" wire:model="password"
                           autocomplete="current-password" required>
                </div>

                <button type="submit" wire:loading.attr="disabled" wire:target="authenticate">
                    <span wire:loading.remove wire:target="authenticate">Entrar</span>
                    <span wire:loading wire:target="authenticate">Entrando…</span>
                </button>
            </form>
        </div>
    </div>
</div>
