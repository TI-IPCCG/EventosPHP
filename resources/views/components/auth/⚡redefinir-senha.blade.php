<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Redefinição propriamente dita, a partir do link do e-mail.
 *
 * O token chega pela URL e é conferido contra o HASH guardado no banco. Vale
 * por 60 minutos e é APAGADO ao ser usado — link de senha é de uso único; se
 * ficasse válido, um e-mail antigo continuaria abrindo a conta para sempre.
 */
new
#[Layout('components.layouts.app')]
#[Title('Redefinir senha — Eventos IPCCG')]
class extends Component {
    public string $token = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(): void
    {
        $this->token = (string) request('token', '');
        $this->email = (string) request('email', '');
    }

    public function redefinir()
    {
        $this->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.required'  => 'Informe a nova senha.',
            'password.min'       => 'A senha deve ter ao menos 8 caracteres.',
            'password.confirmed' => 'A confirmação não confere.',
        ]);

        $linha = DB::table('password_reset_tokens')->where('email', $this->email)->first();

        // addMinutes(60)->isPast() em vez de diffInMinutes: a comparação é
        // direcional e não depende do sinal que a versão do Carbon devolve.
        $valido = $linha
            && Hash::check($this->token, $linha->token)
            && ! Carbon::parse($linha->created_at)->addMinutes(60)->isPast();

        if (! $valido) {
            throw ValidationException::withMessages([
                'password' => 'Link inválido ou expirado. Solicite um novo.',
            ]);
        }

        $user = User::where('email', $this->email)->first();

        if (! $user) {
            throw ValidationException::withMessages(['password' => 'Usuário não encontrado.']);
        }

        $user->update(['password' => $this->password]);   // cast "hashed" no model

        DB::table('password_reset_tokens')->where('email', $this->email)->delete();

        session()->flash('aviso_login', 'Senha redefinida. Entre com a nova senha.');

        return redirect()->route('login');
    }
}; ?>

<div class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <h1 class="auth-brand-title">IPCCG</h1>
            <div class="auth-brand-sub">Redefinir senha</div>
        </div>

        <div class="auth-body">
            <h2 class="auth-title">Nova senha</h2>
            <p class="auth-subtitle">Escolha a senha que você vai usar para entrar.</p>

            @if ($errors->any())
                <div class="alert danger" role="alert" aria-live="assertive" style="margin-bottom:14px">
                    <span class="alert-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg></span>
                    <div class="alert-content">
                        <div class="alert-message">{{ $errors->first() }}</div>
                    </div>
                </div>
            @endif

            <form class="form" wire:submit="redefinir">
                <div>
                    <label for="r-email">E-mail</label>
                    <input id="r-email" type="email" wire:model="email" readonly>
                </div>

                <div>
                    <label for="r-password">Nova senha</label>
                    <input id="r-password" type="password" wire:model="password"
                           autocomplete="new-password" required>
                    <small>Ao menos 8 caracteres.</small>
                </div>

                <div>
                    <label for="r-password2">Repita a nova senha</label>
                    <input id="r-password2" type="password" wire:model="password_confirmation"
                           autocomplete="new-password" required>
                </div>

                <button type="submit" wire:loading.attr="disabled" wire:target="redefinir">
                    <span wire:loading.remove wire:target="redefinir">Salvar nova senha</span>
                    <span wire:loading wire:target="redefinir">Salvando…</span>
                </button>
            </form>

            <div class="auth-links">
                <a class="link" href="{{ route('login') }}" wire:navigate>Voltar ao login</a>
            </div>
        </div>
    </div>
</div>
