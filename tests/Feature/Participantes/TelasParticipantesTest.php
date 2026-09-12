<?php

namespace Tests\Feature\Participantes;

use App\Models\Event;
use App\Models\Membership;
use App\Models\Participantes\Checkin;
use App\Models\Participantes\EventDay;
use App\Models\Participantes\Registration;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use App\Services\Participantes\DayService;
use App\Services\Participantes\PersonResolver;
use App\Services\Participantes\RegistrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * As telas do módulo, com atenção ao que a portaria não perdoa: a busca achar
 * a pessoa, o dia certo vir selecionado, e quem só marca presença não esbarrar
 * em 403 na tela que precisa operar.
 */
class TelasParticipantesTest extends TestCase
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

        foreach (array_keys(\Database\Seeders\PermissionSeeder::CATALOG) as $slug) {
            Permission::firstOrCreate(['slug' => $slug], ['description' => $slug]);
        }

        $this->evento = Event::create(['church_id' => 1, 'nome' => 'Simpósio '.uniqid(),
            'inicio' => '2026-09-25', 'fim' => '2026-09-26', 'status' => 'em_andamento']);

        session(['event_id' => $this->evento->id]);

        (new DayService)->sincronizar($this->evento);

        $this->coord = $this->pessoaCom(array_keys(\Database\Seeders\PermissionSeeder::CATALOG));
        $this->inscricoes = new RegistrationService(new PersonResolver);
    }

    private function pessoaCom(array $slugs): User
    {
        $perfil = SystemRole::create(['church_id' => 1, 'name' => 'P'.uniqid()]);
        $perfil->permissions()->sync(Permission::whereIn('slug', $slugs)->pluck('id'));

        $u = User::create(['name' => 'Operador', 'email' => 't'.uniqid().'@ipccg.org.br',
            'password' => 'segredo123']);

        Membership::create(['user_id' => $u->id, 'church_id' => 1,
            'system_role_id' => $perfil->id, 'status' => true, 'created_at' => now()]);

        return $u;
    }

    private function inscrito(string $nome): Registration
    {
        return $this->inscricoes->inscrever($this->evento, [
            'nome' => $nome, 'email' => 'p'.uniqid().'@ipccg.org.br',
        ]);
    }

    public function test_as_quatro_telas_renderizam(): void
    {
        foreach (['participantes.checkin', 'participantes.inscritos',
                  'participantes.dias', 'participantes.lista'] as $rota) {
            $this->actingAs($this->coord)->get(route($rota))->assertOk();
        }
    }

    public function test_a_busca_acha_por_nome_codigo_e_email(): void
    {
        $i = $this->inscrito('Rebeca Ferreira');

        foreach (['Rebeca', $i->codigo, $i->email] as $termo) {
            $achados = Livewire::actingAs($this->coord)->test('participantes.checkin')
                ->set('busca', $termo)
                ->instance()->resultados;

            $this->assertTrue($achados->contains('id', $i->id), "não achou por: {$termo}");
        }
    }

    public function test_a_busca_acha_pelo_token_do_qr(): void
    {
        $i = $this->inscrito('Token Teste');

        $achados = Livewire::actingAs($this->coord)->test('participantes.checkin')
            ->set('busca', $i->token)
            ->instance()->resultados;

        $this->assertTrue($achados->contains('id', $i->id));
        $this->assertCount(1, $achados, 'token é casamento exato, não LIKE');
    }

    public function test_busca_de_uma_letra_nao_lista_o_evento_inteiro(): void
    {
        $this->inscrito('Alguem');

        $achados = Livewire::actingAs($this->coord)->test('participantes.checkin')
            ->set('busca', 'A')
            ->instance()->resultados;

        $this->assertCount(0, $achados);
    }

    public function test_confirmar_registra_a_entrada_e_limpa_a_busca(): void
    {
        $i = $this->inscrito('Confirma Silva');

        Livewire::actingAs($this->coord)->test('participantes.checkin')
            ->set('busca', 'Confirma')
            ->call('confirmar', $i->id)
            ->assertSet('busca', '');

        $this->assertTrue($i->fresh()->presenteEm(EventDay::where('event_id', $this->evento->id)->first()->id));
    }

    public function test_confirmar_duas_vezes_avisa_em_vez_de_estourar(): void
    {
        // Ler o mesmo crachá duas vezes é rotina na fila; a tela não pode
        // quebrar, tem de informar.
        $i = $this->inscrito('Duplo Toque');

        Livewire::actingAs($this->coord)->test('participantes.checkin')
            ->call('confirmar', $i->id)
            ->call('confirmar', $i->id)
            ->assertHasNoErrors();

        $this->assertSame(1, Checkin::where('registration_id', $i->id)->whereNull('cancelado_em')->count());
    }

    public function test_walkin_inscreve_e_libera_de_uma_vez(): void
    {
        Livewire::actingAs($this->coord)->test('participantes.checkin')
            ->set('w_nome', 'Apareceu Sem Avisar')
            ->set('w_contato', '67999997777')
            ->call('inscreverEEntrar')
            ->assertHasNoErrors();

        $i = Registration::where('event_id', $this->evento->id)
            ->where('nome', 'Apareceu Sem Avisar')->first();

        $this->assertNotNull($i, 'o walk-in não foi inscrito');
        $this->assertSame('manual', $i->origem);
        $this->assertSame(1, Checkin::where('registration_id', $i->id)->count());
    }

    public function test_desfazer_tira_da_contagem(): void
    {
        $i = $this->inscrito('Erro Do Operador');
        $dia = EventDay::where('event_id', $this->evento->id)->first();

        $t = Livewire::actingAs($this->coord)->test('participantes.checkin')
            ->call('confirmar', $i->id);

        $checkin = Checkin::where('registration_id', $i->id)->first();
        $t->call('desfazer', $checkin->id);

        $this->assertFalse($i->fresh()->presenteEm($dia->id));
        $this->assertNotNull($checkin->fresh()->cancelado_em, 'deveria ficar o rastro');
    }

    public function test_portaria_opera_o_checkin_sem_tomar_403(): void
    {
        // O footgun do gate: quem tem só participantes.checkin precisa abrir a
        // tela que existe para ele.
        $porteiro = $this->pessoaCom(['eventos.ver', 'participantes.ver', 'participantes.checkin']);
        $i = $this->inscrito('Visitante');

        $this->actingAs($porteiro)->get(route('participantes.checkin'))->assertOk();

        Livewire::actingAs($porteiro)->test('participantes.checkin')
            ->call('confirmar', $i->id)
            ->assertHasNoErrors();

        $this->assertSame(1, Checkin::where('registration_id', $i->id)->count());
    }

    public function test_portaria_nao_desfaz_checkin(): void
    {
        $porteiro = $this->pessoaCom(['eventos.ver', 'participantes.ver', 'participantes.checkin']);
        $i = $this->inscrito('Nao Desfaz');

        Livewire::actingAs($porteiro)->test('participantes.checkin')->call('confirmar', $i->id);
        $checkin = Checkin::where('registration_id', $i->id)->first();

        Livewire::actingAs($porteiro)->test('participantes.checkin')
            ->call('desfazer', $checkin->id)
            ->assertForbidden();
    }

    public function test_quem_nao_tem_nada_do_modulo_leva_403(): void
    {
        $estranho = $this->pessoaCom(['eventos.ver']);

        $this->actingAs($estranho)->get(route('participantes.checkin'))->assertForbidden();
        $this->actingAs($estranho)->get(route('participantes.inscritos'))->assertForbidden();
    }

    public function test_a_lista_em_papel_traz_os_inscritos_e_os_dias(): void
    {
        $i = $this->inscrito('Sai No Papel');

        $this->actingAs($this->coord)->get(route('participantes.lista'))
            ->assertOk()
            ->assertSee('Sai No Papel')
            ->assertSee($i->codigo)
            ->assertSee('25/09');
    }

    public function test_filtro_de_faltantes_na_lista_de_inscritos(): void
    {
        $veio = $this->inscrito('Ja Chegou');
        $naoVeio = $this->inscrito('Nao Chegou');

        // Só UM dia vira hoje: dois com a mesma data violariam a UNIQUE
        // (event_id, data) — que é justamente o que ela existe para impedir.
        EventDay::where('event_id', $this->evento->id)->orderBy('data')->first()
            ->update(['data' => $this->evento->agora()->toDateString()]);

        Livewire::actingAs($this->coord)->test('participantes.checkin')->call('confirmar', $veio->id);

        $faltantes = Livewire::actingAs($this->coord)->test('participantes.inscritos')
            ->set('filtro', 'faltantes')
            ->instance()->inscritos;

        $this->assertTrue($faltantes->contains('id', $naoVeio->id));
        $this->assertFalse($faltantes->contains('id', $veio->id));
    }

    public function test_tela_de_dias_semeia_e_renomeia(): void
    {
        $t = Livewire::actingAs($this->coord)->test('participantes.dias');

        $dia = EventDay::where('event_id', $this->evento->id)->orderBy('data')->first();

        $t->call('editar', $dia->id)
          ->set('nome', 'Sexta — Abertura')
          ->call('salvarNome')
          ->assertHasNoErrors();

        $this->assertSame('Sexta — Abertura', $dia->fresh()->nome);
    }
}
