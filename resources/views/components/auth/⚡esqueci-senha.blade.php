<?php

use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Pedido de redefinição de senha.
 *
 * A resposta é sempre a mesma, exista o e-mail ou não — mesma decisão do login
 * e do cadastro: não entregar quais e-mails estão cadastrados.
 *
 * O token vai em claro no link e HASHEADO no banco. Quem ler a tabela não
 * consegue forjar um link; é a mesma lógica de uma senha.
 */
new
#[Layout('components.layouts.app')]
#[Title('Recuperar senha — Eventos IPCCG')]
class extends Component {
    public string $email = '';
    public bool $enviado = false;

    public function enviar(): void
    {
        $this->validate(
            ['email' => ['required', 'email']],
            ['email.required' => 'Informe o e-mail.', 'email.email' => 'E-mail inválido.'],
        );

        // 3 pedidos por 5 minutos, por e-mail + IP: evita usar o formulário
        // para inundar a caixa de alguém.
        $chave = 'pwreset:'.Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($chave, 3)) {
            $this->addError('email', 'Muitos pedidos. Aguarde alguns minutos e tente de novo.');

            return;
        }

        RateLimiter::hit($chave, 300);

        // Sem filtro de vínculo ativo: quem está pendente também pode ter
        // errado a senha, e barrar aqui só produziria um silêncio inexplicável.
        // Quem controla o acesso é o login, não a recuperação.
        $user = User::where('email', $this->email)->first();

        if ($user) {
            $token = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()],
            );

            try {
                Mail::to($user->email)->send(new ResetPasswordMail(
                    route('password.reset', ['token' => $token, 'email' => $user->email]),
                    $user->name,
                ));
            } catch (\Throwable $e) {
                // SMTP fora do ar não pode virar erro 500 na cara de quem
                // pediu: a tela diria "algo deu errado" e a pessoa tentaria de
                // novo em looping. Registra e segue com a resposta de sempre.
                Log::error('Falha ao enviar e-mail de redefinição: '.$e->getMessage());
            }
        }

        $this->enviado = true;
    }
}; ?>

<div class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <h1 class="auth-brand-title">IPCCG</h1>
            <div class="auth-brand-sub">Recuperar senha</div>
        </div>

        <div class="auth-body">
            @if ($enviado)
                <div class="alert ok" role="alert" aria-live="polite">
                    <span class="alert-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 6h20v12H2z"/><path d="m22 6-10 7L2 6"/></svg></span>
                    <div class="alert-content">
                        <div class="alert-title">Verifique seu e-mail</div>
                        <div class="alert-message">
                            Se houver uma conta com esse e-mail, enviamos um link para
                            redefinir a senha. O link expira em 60 minutos.
                        </div>
                    </div>
                </div>

                <div class="auth-links">
                    <a class="link" href="{{ route('login') }}" wire:navigate>Voltar ao login</a>
                </div>
            @else
                <h2 class="auth-title">Recuperar senha</h2>
                <p class="auth-subtitle">Enviamos um link para você criar uma nova senha.</p>

                <form class="form" wire:submit="enviar">
                    <div>
                        <label for="f-email">E-mail</label>
                        <input id="f-email" type="email" wire:model="email"
                               autocomplete="username" required>
                        @error('email') <span class="field-error">{{ $message }}</span> @enderror
                    </div>

                    <button type="submit" wire:loading.attr="disabled" wire:target="enviar">
                        <span wire:loading.remove wire:target="enviar">Enviar link</span>
                        <span wire:loading wire:target="enviar">Enviando…</span>
                    </button>
                </form>

                <div class="auth-links">
                    <a class="link" href="{{ route('login') }}" wire:navigate>Voltar ao login</a>
                </div>
            @endif
        </div>
    </div>
</div>
