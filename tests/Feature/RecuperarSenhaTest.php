<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Recuperação de senha. O que precisa estar travado: o link é de uso único,
 * expira, e não conta a ninguém quais e-mails existem.
 */
class RecuperarSenhaTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->user = User::create([
            'name' => 'Fulano', 'email' => 'r'.uniqid().'@ipccg.org.br', 'password' => 'senhaAntiga1',
        ]);
        RateLimiter::clear('pwreset:'.Str::lower($this->user->email).'|127.0.0.1');
    }

    /** Dispara o pedido e devolve o token em claro que foi para o e-mail. */
    private function pedirToken(): string
    {
        Livewire::test('auth.esqueci-senha')
            ->set('email', $this->user->email)->call('enviar')
            ->assertSet('enviado', true);

        $token = null;
        Mail::assertSent(ResetPasswordMail::class, function ($mail) use (&$token) {
            parse_str(parse_url($mail->url, PHP_URL_QUERY) ?? '', $q);
            $token = $q['token'] ?? null;

            return true;
        });

        $this->assertNotNull($token, 'o link deveria trazer o token');

        return $token;
    }

    public function test_pedido_gera_token_hasheado_e_envia_email(): void
    {
        $token = $this->pedirToken();

        $linha = DB::table('password_reset_tokens')->where('email', $this->user->email)->first();
        $this->assertNotNull($linha);
        $this->assertNotSame($token, $linha->token, 'o token não pode ficar em claro no banco');
        $this->assertTrue(Hash::check($token, $linha->token));
    }

    public function test_redefine_a_senha_e_consome_o_token(): void
    {
        $token = $this->pedirToken();

        Livewire::withQueryParams(['token' => $token, 'email' => $this->user->email])
            ->test('auth.redefinir-senha')
            ->set('password', 'senhaNova123')->set('password_confirmation', 'senhaNova123')
            ->call('redefinir')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('senhaNova123', $this->user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $this->user->email]);
    }

    public function test_o_mesmo_link_nao_serve_duas_vezes(): void
    {
        $token = $this->pedirToken();
        $params = ['token' => $token, 'email' => $this->user->email];

        Livewire::withQueryParams($params)->test('auth.redefinir-senha')
            ->set('password', 'senhaNova123')->set('password_confirmation', 'senhaNova123')
            ->call('redefinir')->assertHasNoErrors();

        // Segunda tentativa com o MESMO link: o token já foi apagado.
        Livewire::withQueryParams($params)->test('auth.redefinir-senha')
            ->set('password', 'outraSenha123')->set('password_confirmation', 'outraSenha123')
            ->call('redefinir')->assertHasErrors('password');

        $this->assertTrue(Hash::check('senhaNova123', $this->user->fresh()->password));
    }

    public function test_token_expirado_e_recusado(): void
    {
        $token = $this->pedirToken();

        // Envelhece o pedido para 61 minutos atrás.
        DB::table('password_reset_tokens')->where('email', $this->user->email)
            ->update(['created_at' => now()->subMinutes(61)]);

        Livewire::withQueryParams(['token' => $token, 'email' => $this->user->email])
            ->test('auth.redefinir-senha')
            ->set('password', 'senhaNova123')->set('password_confirmation', 'senhaNova123')
            ->call('redefinir')->assertHasErrors('password');

        $this->assertTrue(Hash::check('senhaAntiga1', $this->user->fresh()->password));
    }

    public function test_token_forjado_e_recusado(): void
    {
        $this->pedirToken();

        Livewire::withQueryParams(['token' => Str::random(64), 'email' => $this->user->email])
            ->test('auth.redefinir-senha')
            ->set('password', 'senhaNova123')->set('password_confirmation', 'senhaNova123')
            ->call('redefinir')->assertHasErrors('password');

        $this->assertTrue(Hash::check('senhaAntiga1', $this->user->fresh()->password));
    }

    public function test_email_inexistente_responde_igual_e_nao_envia_nada(): void
    {
        Livewire::test('auth.esqueci-senha')
            ->set('email', 'ninguem'.uniqid().'@ipccg.org.br')->call('enviar')
            ->assertHasNoErrors()
            ->assertSet('enviado', true);          // resposta idêntica

        Mail::assertNothingSent();
    }

    public function test_rate_limit_do_pedido(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Livewire::test('auth.esqueci-senha')
                ->set('email', $this->user->email)->call('enviar')->assertHasNoErrors();
        }

        Livewire::test('auth.esqueci-senha')
            ->set('email', $this->user->email)->call('enviar')->assertHasErrors('email');
    }

    public function test_telas_renderizam_e_o_login_aponta_para_elas(): void
    {
        $this->get(route('password.request'))->assertOk()->assertSee('Recuperar senha');
        $this->get(route('password.reset', ['token' => 'x', 'email' => 'a@b.c']))
            ->assertOk()->assertSee('Nova senha');
        $this->get(route('login'))->assertOk()
            ->assertSee(route('password.request'), escape: false);
    }
}
