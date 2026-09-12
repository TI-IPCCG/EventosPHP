<?php

namespace Tests\Feature\Participantes;

use App\Models\Church;
use App\Models\Event;
use App\Models\Participantes\Checkin;
use App\Models\Participantes\EventDay;
use App\Models\Participantes\Registration;
use App\Services\Participantes\CheckinService;
use App\Services\Participantes\DayService;
use App\Services\Participantes\PersonResolver;
use App\Services\Participantes\RegistrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Check-in: uma entrada por inscrição por dia, com o horário certo.
 *
 * O que estes testes protegem é o que a portaria não pode perdoar: contar a
 * mesma pessoa duas vezes, recusar quem tem direito, e gravar a presença no dia
 * errado por três horas de fuso.
 */
class CheckinTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    private Event $evento;
    private CheckinService $checkin;
    private RegistrationService $inscricoes;

    protected function setUp(): void
    {
        parent::setUp();
        session(['church_id' => 1]);

        $this->evento = Event::create([
            'church_id' => 1, 'nome' => 'Simpósio '.uniqid(),
            'inicio' => '2026-09-25', 'fim' => '2026-09-26', 'status' => 'em_andamento',
        ]);

        $this->checkin    = new CheckinService;
        $this->inscricoes = new RegistrationService(new PersonResolver);

        (new DayService)->sincronizar($this->evento);
    }

    private function inscrito(string $nome = 'Participante'): Registration
    {
        return $this->inscricoes->inscrever($this->evento, [
            'nome' => $nome, 'email' => strtolower(str_replace(' ', '', $nome)).uniqid().'@x.org',
        ]);
    }

    private function dia(int $qual = 0): EventDay
    {
        return EventDay::where('event_id', $this->evento->id)->orderBy('data')->get()[$qual];
    }

    public function test_dias_sao_semeados_do_intervalo_do_evento(): void
    {
        $dias = EventDay::where('event_id', $this->evento->id)->orderBy('data')->get();

        $this->assertCount(2, $dias, '25 e 26 = dois dias');
        $this->assertSame('2026-09-25', $dias[0]->data->toDateString());
        $this->assertSame('2026-09-26', $dias[1]->data->toDateString());
    }

    public function test_evento_de_um_dia_gera_um_dia(): void
    {
        $umDia = Event::create(['church_id' => 1, 'nome' => 'Culto '.uniqid(),
            'inicio' => '2026-10-10', 'status' => 'planejamento']);

        (new DayService)->sincronizar($umDia);

        $this->assertSame(1, EventDay::where('event_id', $umDia->id)->count());
    }

    public function test_semear_de_novo_nao_duplica(): void
    {
        $criados = (new DayService)->sincronizar($this->evento);

        $this->assertSame(0, $criados);
        $this->assertSame(2, EventDay::where('event_id', $this->evento->id)->count());
    }

    public function test_registra_a_entrada(): void
    {
        $inscricao = $this->inscrito();

        $c = $this->checkin->registrar($inscricao, $this->dia(), 'qr');

        $this->assertNotNull($c->id);
        $this->assertTrue($inscricao->fresh()->presenteEm($this->dia()->id));
    }

    public function test_segunda_leitura_no_mesmo_dia_e_recusada_com_a_hora_da_primeira(): void
    {
        // Ler duas vezes é rotina na fila. A resposta útil não é um erro seco:
        // é dizer quando a pessoa entrou, que é como se pega crachá compartilhado.
        $inscricao = $this->inscrito();
        $this->checkin->registrar($inscricao, $this->dia());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Já entrou às \d{2}:\d{2}/');

        $this->checkin->registrar($inscricao, $this->dia());
    }

    public function test_a_mesma_pessoa_entra_no_dia_seguinte(): void
    {
        $inscricao = $this->inscrito();

        $this->checkin->registrar($inscricao, $this->dia(0));
        $segundo = $this->checkin->registrar($inscricao, $this->dia(1));

        $this->assertNotNull($segundo->id);
        $this->assertSame(2, Checkin::where('registration_id', $inscricao->id)->count());
    }

    public function test_cancelar_permite_marcar_de_novo_e_preserva_o_engano(): void
    {
        $inscricao = $this->inscrito();
        $primeiro = $this->checkin->registrar($inscricao, $this->dia());

        $this->checkin->cancelar($primeiro);
        $segundo = $this->checkin->registrar($inscricao, $this->dia());

        $this->assertNotNull($segundo->id);
        $this->assertNotNull($primeiro->fresh()->cancelado_em, 'o cancelado deveria continuar lá');
        $this->assertSame(2, Checkin::where('registration_id', $inscricao->id)->count());
    }

    public function test_o_banco_recusa_duplicata_mesmo_sem_passar_pelo_service(): void
    {
        // A trava não pode depender de ninguém lembrar de usar o Service.
        $inscricao = $this->inscrito();
        $this->checkin->registrar($inscricao, $this->dia());

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        Checkin::create([
            'registration_id' => $inscricao->id,
            'event_day_id'    => $this->dia()->id,
            'canal'           => 'manual',
            'registrado_em'   => now(),
        ]);
    }

    public function test_inscricao_cancelada_nao_entra(): void
    {
        $inscricao = $this->inscrito();
        $this->inscricoes->cancelar($inscricao);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/cancelada/');

        $this->checkin->registrar($inscricao->fresh(), $this->dia());
    }

    public function test_dia_desativado_nao_aceita_entrada(): void
    {
        $inscricao = $this->inscrito();
        $dia = $this->dia();
        $dia->update(['ativo' => false]);

        $this->expectException(\RuntimeException::class);

        $this->checkin->registrar($inscricao, $dia->fresh());
    }

    public function test_dia_de_outro_evento_e_recusado(): void
    {
        $outro = Event::create(['church_id' => 1, 'nome' => 'Outro '.uniqid(),
            'inicio' => '2026-11-01', 'status' => 'planejamento']);
        (new DayService)->sincronizar($outro);

        $inscricao = $this->inscrito();
        $diaAlheio = EventDay::where('event_id', $outro->id)->first();

        $this->expectException(\RuntimeException::class);

        $this->checkin->registrar($inscricao, $diaAlheio);
    }

    /**
     * O teste que um ambiente todo em UTC nunca pegaria.
     */
    public function test_horario_sai_no_fuso_da_congregacao_e_nao_em_utc(): void
    {
        $inscricao = $this->inscrito();

        // 02:30 UTC = 23:30 do dia ANTERIOR no horário de Campo Grande.
        Carbon::setTestNow(Carbon::parse('2026-09-26 02:30:00', 'UTC'));

        $c = $this->checkin->registrar($inscricao, $this->dia(1));

        $esperado = Church::agora(1);
        $this->assertSame(
            $esperado->format('Y-m-d H:i'),
            $c->registrado_em->format('Y-m-d H:i'),
            'a entrada foi gravada em UTC, não na hora-de-parede da congregação',
        );

        // E o dia civil é o de lá, não o do servidor.
        $this->assertSame('2026-09-25', $esperado->toDateString(),
            'às 02:30 UTC ainda é dia 25 em Campo Grande');

        Carbon::setTestNow();
    }

    public function test_dia_de_hoje_usa_o_fuso_da_congregacao(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-26 02:30:00', 'UTC'));

        $hoje = EventDay::deHoje($this->evento->id);

        $this->assertNotNull($hoje);
        $this->assertSame('2026-09-25', $hoje->data->toDateString(),
            'today() em UTC diria 26; na congregação ainda é 25');

        Carbon::setTestNow();
    }

    public function test_apagar_dia_com_presenca_e_recusado(): void
    {
        $inscricao = $this->inscrito();
        $this->checkin->registrar($inscricao, $this->dia());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/presença|presenças/');

        (new DayService)->apagar($this->dia());
    }

    public function test_apagar_dia_vazio_funciona(): void
    {
        $dia = $this->dia(1);

        (new DayService)->apagar($dia);

        $this->assertNull(EventDay::find($dia->id));
    }
}
