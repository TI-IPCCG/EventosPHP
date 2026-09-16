<?php

namespace Tests\Feature\Participantes;

use App\Mail\CredencialMail;
use App\Models\Event;
use App\Models\Membership;
use App\Models\Participantes\Registration;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use App\Services\Participantes\EnvioService;
use App\Services\Participantes\PersonResolver;
use App\Services\Participantes\QrService;
use App\Services\Participantes\RegistrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Credencial, QR e envio em lote.
 *
 * O que estes testes protegem: o QR não virar porta de entrada para quem não
 * é operador, ninguém receber a credencial duas vezes, e o lote sobreviver a
 * ser interrompido no meio — que é o normal quando não há worker.
 */
class CredencialTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    private Event $evento;
    private User $coord;
    private RegistrationService $inscricoes;

    protected function setUp(): void
    {
        parent::setUp();
        session(['church_id' => 1]);
        Mail::fake();

        foreach (array_keys(\Database\Seeders\PermissionSeeder::CATALOG) as $slug) {
            Permission::firstOrCreate(['slug' => $slug], ['description' => $slug]);
        }

        $this->evento = Event::create(['church_id' => 1, 'nome' => 'Simpósio '.uniqid(),
            'inicio' => '2026-09-25', 'fim' => '2026-09-26', 'status' => 'em_andamento']);
        session(['event_id' => $this->evento->id]);

        $this->coord = $this->pessoaCom(array_keys(\Database\Seeders\PermissionSeeder::CATALOG));
        $this->inscricoes = new RegistrationService(new PersonResolver);
    }

    private function pessoaCom(array $slugs): User
    {
        $perfil = SystemRole::create(['church_id' => 1, 'name' => 'P'.uniqid()]);
        $perfil->permissions()->sync(Permission::whereIn('slug', $slugs)->pluck('id'));

        $u = User::create(['name' => 'Op', 'email' => 'q'.uniqid().'@ipccg.org.br',
            'password' => 'segredo123']);

        Membership::create(['user_id' => $u->id, 'church_id' => 1,
            'system_role_id' => $perfil->id, 'status' => true, 'created_at' => now()]);

        return $u;
    }

    private function inscrito(string $nome = 'Participante', ?string $email = null): Registration
    {
        return $this->inscricoes->inscrever($this->evento, [
            'nome' => $nome, 'email' => $email ?? ('c'.uniqid().'@ipccg.org.br'),
        ]);
    }

    // ── o QR ────────────────────────────────────────────────────────

    public function test_o_qr_sai_em_png_e_em_svg(): void
    {
        $qr = app(QrService::class);
        $i = $this->inscrito();

        $png = $qr->png($i);
        $this->assertNotEmpty($png);
        $this->assertNotFalse(@imagecreatefromstring($png), 'o PNG não é imagem válida');

        $svg = $qr->svg($i);
        $this->assertStringContainsString('<svg', $svg);
    }

    public function test_o_qr_aponta_para_a_rota_de_scan_com_o_token(): void
    {
        $i = $this->inscrito();

        $this->assertSame(
            route('participantes.scan', ['token' => $i->token]),
            app(QrService::class)->url($i),
        );
    }

    public function test_o_png_e_gravado_uma_vez_e_reaproveitado(): void
    {
        $qr = app(QrService::class);
        $i = $this->inscrito();

        $caminho = $qr->caminhoDoPng($i);
        $primeiro = filemtime($qr->arquivoDoPng($i));

        $this->assertSame($caminho, $qr->caminhoDoPng($i));
        $this->assertSame($primeiro, filemtime($qr->arquivoDoPng($i)), 'regerou o arquivo à toa');

        @unlink($qr->arquivoDoPng($i));
    }

    // ── as duas rotas, e por que são separadas ──────────────────────

    public function test_a_credencial_e_publica(): void
    {
        // ⚠ A sessão é ESVAZIADA de propósito, e é isso que faz este teste
        // valer: o participante chega sem login e sem church_id. A versão
        // anterior deixava a sessão do setUp e passava com a página quebrada em
        // produção — o ChurchScope do Event devolvia null e a página estourava
        // justamente para o único público que a usa.
        $i = $this->inscrito('Publico Silva');

        session()->flush();

        $this->get(route('participantes.credencial', ['token' => $i->token]))
            ->assertOk()
            ->assertSee('Publico Silva')
            ->assertSee($i->codigo)
            ->assertSee('<svg', escape: false);

        $this->assertGuest();
    }

    public function test_o_qr_da_credencial_sai_em_preto(): void
    {
        // QR é código de barras, não enfeite: o leitor decide pelo CONTRASTE, e
        // pintar de verde-marca derrubou a leitura em celular de verdade.
        $i = $this->inscrito();
        session()->flush();

        $html = $this->get(route('participantes.credencial', ['token' => $i->token]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('.qr path { fill: #000000; }', $html);
        $this->assertStringNotContainsString('.qr path { fill: var(--primary); }', $html);
    }

    public function test_a_credencial_mostra_se_a_pessoa_ja_entrou(): void
    {
        // É a primeira pergunta de quem abre o próprio ingresso.
        $i = $this->inscrito('Ja Entrou Hoje');
        (new \App\Services\Participantes\DayService)->sincronizar($this->evento);

        $dia = \App\Models\Participantes\EventDay::where('event_id', $this->evento->id)->first();
        (new \App\Services\Participantes\CheckinService)->registrar($i, $dia, 'qr');

        session()->flush();

        $this->get(route('participantes.credencial', ['token' => $i->token]))
            ->assertOk()
            ->assertSee('entrada às')
            ->assertSee('dia-ok', escape: false);
    }

    public function test_quem_nao_entrou_ve_a_instrucao_em_vez_da_hora(): void
    {
        $i = $this->inscrito('Ainda Nao Veio');
        (new \App\Services\Participantes\DayService)->sincronizar($this->evento);

        session()->flush();

        $this->get(route('participantes.credencial', ['token' => $i->token]))
            ->assertOk()
            ->assertSee('A entrada é registrada na portaria')
            ->assertDontSee('entrada às');
    }

    public function test_a_credencial_mostra_o_evento_e_os_dias_sem_sessao(): void
    {
        $i = $this->inscrito('Com Dias');
        (new \App\Services\Participantes\DayService)->sincronizar($this->evento);

        session()->flush();

        $this->get(route('participantes.credencial', ['token' => $i->token]))
            ->assertOk()
            ->assertSee($this->evento->nome)
            ->assertSee('25/09/2026');
    }

    public function test_o_scan_do_qr_exige_operador_logado(): void
    {
        // A anti-falsificação do QR: quem escaneia o próprio crachá cai no
        // login, não num check-in.
        $i = $this->inscrito();

        $this->get(route('participantes.scan', ['token' => $i->token]))
            ->assertRedirect(route('login'));
    }

    public function test_o_scan_leva_o_operador_a_tela_de_confirmacao(): void
    {
        // O QR não registra entrada sozinho: leva à confirmação, onde quem está
        // na porta confere o nome e vê para qual DIA a entrada vai. Escanear e
        // registrar no mesmo toque marcaria presença de quem passou o crachá
        // alheio, e ninguém perceberia até o relatório sair errado.
        $i = $this->inscrito();

        $this->actingAs($this->coord)
            ->get(route('participantes.scan', ['token' => $i->token]))
            ->assertRedirect(route('participantes.confirmar', ['token' => $i->token]));
    }

    public function test_token_inexistente_na_credencial_da_404(): void
    {
        $this->get(route('participantes.credencial', ['token' => str_repeat('x', 32)]))
            ->assertNotFound();
    }

    public function test_inscricao_cancelada_nao_tem_credencial(): void
    {
        $i = $this->inscrito();
        $this->inscricoes->cancelar($i);

        $this->get(route('participantes.credencial', ['token' => $i->token]))
            ->assertNotFound();
    }

    // ── o envio em lote ─────────────────────────────────────────────

    public function test_envia_e_marca_quem_ja_recebeu(): void
    {
        $a = $this->inscrito('Um');
        $b = $this->inscrito('Dois');

        $envio = app(EnvioService::class);
        $this->assertSame(2, $envio->pendentes($this->evento));

        $envio->enviarLote($this->evento);

        Mail::assertSent(CredencialMail::class, 2);
        $this->assertNotNull($a->fresh()->qr_enviado_em);
        $this->assertSame(0, $envio->pendentes($this->evento));
    }

    public function test_rodar_de_novo_nao_reenvia(): void
    {
        // É o que permite clicar em "iniciar" de novo sem medo depois de fechar
        // o navegador no meio.
        $this->inscrito('Um');
        $envio = app(EnvioService::class);

        $envio->enviarLote($this->evento);
        Mail::assertSent(CredencialMail::class, 1);

        $envio->enviarLote($this->evento);
        Mail::assertSent(CredencialMail::class, 1);
    }

    public function test_quem_tem_email_invalido_fica_fora_da_fila(): void
    {
        $this->inscricoes->inscrever($this->evento, ['nome' => 'Torto', 'email' => 'nao eh email']);

        $this->assertSame(0, app(EnvioService::class)->pendentes($this->evento));
    }

    public function test_reserva_expirada_volta_para_a_fila(): void
    {
        // O lote interrompido (navegador fechado, deploy) se cura sozinho: a
        // reserva expira NO FILTRO, sem rotina de limpeza.
        $i = $this->inscrito();

        $i->update(['qr_reservado_em' => $this->evento->agora()->subMinutes(10), 'qr_envios' => 1]);

        $this->assertSame(1, app(EnvioService::class)->pendentes($this->evento),
            'a reserva velha deveria ter expirado');
    }

    public function test_reserva_recente_segura_a_linha(): void
    {
        $i = $this->inscrito();
        $i->update(['qr_reservado_em' => $this->evento->agora(), 'qr_envios' => 1]);

        $this->assertSame(0, app(EnvioService::class)->pendentes($this->evento),
            'outro processo pegaria a mesma linha');
    }

    public function test_falha_de_smtp_nao_derruba_o_lote(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP fora do ar'));

        $i = $this->inscrito('Vai Falhar');
        $r = app(EnvioService::class)->enviarLote($this->evento);

        $this->assertSame(1, $r['falhas']);
        $this->assertSame(0, $r['enviados']);
        $this->assertStringContainsString('SMTP', $i->fresh()->qr_erro);
    }

    public function test_falha_nao_queima_as_tentativas_de_uma_vez(): void
    {
        // Com o servidor de e-mail fora do ar, devolver a pessoa à fila na hora
        // gastaria as três tentativas dela em três segundos — antes de o SMTP
        // ter qualquer chance de voltar. A reserva segura até expirar.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP fora do ar'));

        $i = $this->inscrito('Uma Tentativa Por Vez');
        app(EnvioService::class)->enviarLote($this->evento);

        $this->assertSame(1, (int) $i->fresh()->qr_envios, 'queimou mais de uma tentativa no mesmo lote');
        $this->assertNotNull($i->fresh()->qr_reservado_em, 'a reserva deveria segurar a próxima tentativa');
    }

    public function test_reenviar_devolve_a_pessoa_para_a_fila(): void
    {
        $i = $this->inscrito();
        app(EnvioService::class)->enviarLote($this->evento);

        $this->assertSame(0, app(EnvioService::class)->pendentes($this->evento));

        Livewire::actingAs($this->coord)->test('participantes.inscritos')
            ->call('reenviar', $i->id);

        $this->assertSame(1, app(EnvioService::class)->pendentes($this->evento));
        $this->assertNull($i->fresh()->qr_enviado_em);
    }

    // ── a tela ──────────────────────────────────────────────────────

    public function test_a_tela_de_inscritos_conta_as_credenciais(): void
    {
        // O envio foi absorvido por Inscritos: no dia a dia "quem está inscrito"
        // e "quem recebeu credencial" são a mesma pergunta.
        $this->inscrito('Na Fila');

        $this->actingAs($this->coord)->get(route('participantes.inscritos'))
            ->assertOk()->assertSee('Inscritos');

        $totais = Livewire::actingAs($this->coord)->test('participantes.inscritos')
            ->instance()->totais;

        $this->assertSame(1, $totais['na_fila']);
        $this->assertSame(0, $totais['enviadas']);
    }

    public function test_a_rota_antiga_de_credenciais_redireciona(): void
    {
        $this->actingAs($this->coord)->get(route('participantes.credenciais'))
            ->assertRedirect('/participantes');
    }

    public function test_o_teste_para_si_mesmo_envia(): void
    {
        $this->inscrito();

        Livewire::actingAs($this->coord)->test('participantes.inscritos')
            ->set('emailTeste', 'eu@ipccg.org.br')
            ->call('enviarTeste')
            ->assertHasNoErrors();

        Mail::assertSent(CredencialMail::class);
    }

    public function test_quem_nao_pode_enviar_nao_dispara_o_lote(): void
    {
        // Permissão separada de propósito: gasta cota de SMTP e não tem desfazer.
        // A Portaria VÊ a lista (precisa, para achar gente), mas não envia.
        $porteiro = $this->pessoaCom(['eventos.ver', 'participantes.ver', 'participantes.checkin']);
        $this->inscrito();

        $this->actingAs($porteiro)->get(route('participantes.inscritos'))->assertOk();

        Livewire::actingAs($porteiro)->test('participantes.inscritos')
            ->call('iniciarEnvio')
            ->assertForbidden();
    }
}
