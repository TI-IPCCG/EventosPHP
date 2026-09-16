<?php

namespace Tests\Feature\Participantes;

use App\Models\Church;
use App\Models\Event;
use App\Models\Membership;
use App\Models\Participantes\Checkin;
use App\Models\Participantes\EventDay;
use App\Models\Participantes\Registration;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use App\Services\Participantes\CheckinService;
use App\Services\Participantes\DayService;
use App\Services\Participantes\PersonResolver;
use App\Services\Participantes\RegistrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A tela de confirmação — para onde o QR leva.
 *
 * O que ela precisa garantir: mostrar QUEM é antes de liberar, deixar claro
 * para QUAL DIA a entrada vai (o mesmo QR serve os dois dias do evento), e não
 * deixar ninguém registrar entrada sem ser da portaria.
 */
class ConfirmacaoTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    private Event $evento;
    private User $porteiro;
    private RegistrationService $inscricoes;

    protected function setUp(): void
    {
        parent::setUp();
        session(['church_id' => 1]);

        foreach (array_keys(\Database\Seeders\PermissionSeeder::CATALOG) as $slug) {
            Permission::firstOrCreate(['slug' => $slug], ['description' => $slug]);
        }

        // ⚠ A data vem da CONGREGAÇÃO, não de today().
        //
        // today() devolve a data do servidor, em UTC. Às 22h em Campo Grande já
        // é o dia seguinte em UTC — e aí o evento nasceria começando amanhã,
        // EventDay::deHoje() não acharia dia nenhum, e a tela diria "hoje não é
        // dia de evento" num teste que deveria estar registrando entrada.
        //
        // É o mesmo erro que o módulo inteiro existe para evitar, e ele pega
        // quem escreve o teste tão fácil quanto quem escreve o código.
        $hoje = Church::agora(1)->startOfDay();

        $this->evento = Event::create(['church_id' => 1, 'nome' => 'Simpósio '.uniqid(),
            'inicio' => $hoje->toDateString(),
            'fim' => $hoje->copy()->addDay()->toDateString(),
            'status' => 'em_andamento']);
        session(['event_id' => $this->evento->id]);

        (new DayService)->sincronizar($this->evento);

        $this->porteiro = $this->pessoaCom(['eventos.ver', 'participantes.ver', 'participantes.checkin']);
        $this->inscricoes = new RegistrationService(new PersonResolver);
    }

    private function pessoaCom(array $slugs): User
    {
        $perfil = SystemRole::create(['church_id' => 1, 'name' => 'P'.uniqid()]);
        $perfil->permissions()->sync(Permission::whereIn('slug', $slugs)->pluck('id'));

        $u = User::create(['name' => 'Porteiro', 'email' => 'f'.uniqid().'@ipccg.org.br',
            'password' => 'segredo123']);

        Membership::create(['user_id' => $u->id, 'church_id' => 1,
            'system_role_id' => $perfil->id, 'status' => true, 'created_at' => now()]);

        return $u;
    }

    private function inscrito(string $nome = 'Fulano de Tal'): Registration
    {
        return $this->inscricoes->inscrever($this->evento, [
            'nome' => $nome, 'email' => 'k'.uniqid().'@ipccg.org.br',
        ]);
    }

    public function test_mostra_quem_e_antes_de_liberar(): void
    {
        $i = $this->inscrito('Rebeca Bernardes');

        $this->actingAs($this->porteiro)
            ->get(route('participantes.confirmar', ['token' => $i->token]))
            ->assertOk()
            ->assertSee('Rebeca Bernardes')
            ->assertSee($i->codigo);
    }

    public function test_deixa_claro_para_qual_dia_a_entrada_vai(): void
    {
        // O mesmo QR serve os dois dias: o que decide é o dia de HOJE, e isso
        // tem de estar escrito na tela, não subentendido.
        $i = $this->inscrito();
        $hoje = EventDay::deHoje($this->evento->id);

        $this->actingAs($this->porteiro)
            ->get(route('participantes.confirmar', ['token' => $i->token]))
            ->assertOk()
            ->assertSee('Entrada para')
            ->assertSee($hoje->data->format('d/m/Y'));
    }

    public function test_confirmar_registra_no_dia_de_hoje(): void
    {
        $i = $this->inscrito();
        $hoje = EventDay::deHoje($this->evento->id);

        Livewire::actingAs($this->porteiro)
            ->test('participantes.confirmar', ['token' => $i->token])
            ->call('confirmar')
            ->assertSet('registrado', true);

        $this->assertTrue($i->fresh()->presenteEm($hoje->id));
    }

    public function test_nao_registra_no_dia_seguinte_por_engano(): void
    {
        $i = $this->inscrito();
        $amanha = EventDay::where('event_id', $this->evento->id)
            ->whereDate('data', Church::agora(1)->addDay()->toDateString())->first();

        Livewire::actingAs($this->porteiro)
            ->test('participantes.confirmar', ['token' => $i->token])
            ->call('confirmar');

        $this->assertFalse($i->fresh()->presenteEm($amanha->id),
            'a entrada foi parar no dia errado');
    }

    public function test_quem_ja_entrou_ve_a_hora_em_vez_do_botao(): void
    {
        $i = $this->inscrito('Ja Passou Aqui');
        $hoje = EventDay::deHoje($this->evento->id);
        (new CheckinService)->registrar($i, $hoje, 'qr', $this->porteiro->id);

        $this->actingAs($this->porteiro)
            ->get(route('participantes.confirmar', ['token' => $i->token]))
            ->assertOk()
            ->assertSee('Já entrou')
            ->assertSee('Porteiro')          // quem registrou
            ->assertDontSee('wire:click="confirmar"', escape: false);
    }

    public function test_confirmar_duas_vezes_avisa_sem_duplicar(): void
    {
        $i = $this->inscrito();

        Livewire::actingAs($this->porteiro)
            ->test('participantes.confirmar', ['token' => $i->token])
            ->call('confirmar')
            ->call('confirmar')
            ->assertHasNoErrors();

        $this->assertSame(1, Checkin::where('registration_id', $i->id)
            ->whereNull('cancelado_em')->count());
    }

    public function test_mostra_em_que_outros_dias_a_pessoa_ja_esteve(): void
    {
        $i = $this->inscrito();
        $amanha = EventDay::where('event_id', $this->evento->id)
            ->whereDate('data', Church::agora(1)->addDay()->toDateString())->first();
        $amanha->update(['nome' => 'Sábado — Encerramento']);

        (new CheckinService)->registrar($i, $amanha, 'manual', $this->porteiro->id);

        $this->actingAs($this->porteiro)
            ->get(route('participantes.confirmar', ['token' => $i->token]))
            ->assertOk()
            ->assertSee('Já esteve em')
            ->assertSee('Sábado — Encerramento');
    }

    public function test_token_de_outro_evento_nao_confunde(): void
    {
        $outro = Event::create(['church_id' => 1, 'nome' => 'Outro '.uniqid(),
            'inicio' => Church::agora(1)->toDateString(), 'status' => 'planejamento']);
        (new DayService)->sincronizar($outro);
        $alheio = $this->inscricoes->inscrever($outro, [
            'nome' => 'De Outro Evento', 'email' => 'z'.uniqid().'@x.org',
        ]);

        $this->actingAs($this->porteiro)
            ->get(route('participantes.confirmar', ['token' => $alheio->token]))
            ->assertOk()
            ->assertSee('Credencial não encontrada')
            ->assertDontSee('wire:click="confirmar"', escape: false);
    }

    public function test_quando_o_dia_nao_e_hoje_avisa_em_vermelho_mas_deixa_lancar(): void
    {
        // O evento ainda não começou (ou já passou). Bloquear aqui impediria
        // testar na véspera e lançar o que ficou anotado no papel — então a
        // tela AVISA em vez de travar, e o aviso é gritante porque marcar no
        // dia errado é o engano mais caro da portaria.
        $futuro = Event::create(['church_id' => 1, 'nome' => 'Ano que vem '.uniqid(),
            'inicio' => Church::agora(1)->addYear()->toDateString(), 'status' => 'planejamento']);
        (new DayService)->sincronizar($futuro);
        session(['event_id' => $futuro->id]);

        $i = $this->inscricoes->inscrever($futuro, [
            'nome' => 'Adiantado', 'email' => 'y'.uniqid().'@x.org',
        ]);

        $this->actingAs($this->porteiro)
            ->get(route('participantes.confirmar', ['token' => $i->token]))
            ->assertOk()
            ->assertSee('NÃO é hoje')
            ->assertSee('conf-dia-alerta', escape: false)
            ->assertSee('wire:click="confirmar"', escape: false);
    }

    public function test_lancar_em_dia_que_nao_e_hoje_marca_o_canal_retroativo(): void
    {
        // Distinguir o que foi lido na hora do que foi digitado depois responde
        // "quantas entradas foram lançadas à mão?", que é pergunta real quando
        // o leitor falha no meio do evento.
        $i = $this->inscrito();
        $amanha = EventDay::where('event_id', $this->evento->id)
            ->whereDate('data', Church::agora(1)->addDay()->toDateString())->first();

        Livewire::actingAs($this->porteiro)
            ->test('participantes.confirmar', ['token' => $i->token])
            ->set('dia_id', $amanha->id)
            ->call('confirmar')
            ->assertSet('registrado', true);

        $this->assertSame('retroativo',
            Checkin::where('registration_id', $i->id)->where('event_day_id', $amanha->id)->value('canal'));
    }

    public function test_evento_sem_dias_gera_os_dias_em_vez_de_travar(): void
    {
        // A portaria não pode parar porque ninguém lembrou de cadastrar os dias
        // antes. Abrir a tela semeia o que falta, a partir do período do evento.
        $i = $this->inscrito();
        EventDay::where('event_id', $this->evento->id)->delete();

        $this->actingAs($this->porteiro)
            ->get(route('participantes.confirmar', ['token' => $i->token]))
            ->assertOk()
            ->assertSee('Entrada para')
            ->assertSee('wire:click="confirmar"', escape: false);

        $this->assertSame(2, EventDay::where('event_id', $this->evento->id)->count(),
            'os dois dias do evento deveriam ter sido recriados');
    }

    public function test_o_qr_leva_direto_para_a_confirmacao(): void
    {
        $i = $this->inscrito();

        $this->actingAs($this->porteiro)
            ->get(route('participantes.scan', ['token' => $i->token]))
            ->assertRedirect(route('participantes.confirmar', ['token' => $i->token]));
    }

    public function test_quem_nao_e_da_portaria_leva_403(): void
    {
        $i = $this->inscrito();
        $curioso = $this->pessoaCom(['eventos.ver', 'participantes.ver']);

        $this->actingAs($curioso)
            ->get(route('participantes.confirmar', ['token' => $i->token]))
            ->assertForbidden();
    }

    public function test_visitante_sem_login_nao_registra_entrada(): void
    {
        // O QR na mão de quem não é operador não vira check-in.
        $i = $this->inscrito();

        $this->get(route('participantes.confirmar', ['token' => $i->token]))
            ->assertRedirect(route('login'));
    }
}
