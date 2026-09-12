<?php

namespace Tests\Feature\Participantes;

use App\Models\Event;
use App\Models\Participantes\EventSetting;
use App\Models\Participantes\Person;
use App\Models\Participantes\Registration;
use App\Services\Participantes\PersonResolver;
use App\Services\Participantes\RegistrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InscricaoTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    private Event $evento;
    private RegistrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        session(['church_id' => 1]);

        $this->evento = Event::create(['church_id' => 1, 'nome' => 'Evento '.uniqid(),
            'inicio' => '2026-09-25', 'fim' => '2026-09-26', 'status' => 'em_andamento']);

        $this->service = new RegistrationService(new PersonResolver);
    }

    private function dados(string $nome = 'Fulano de Tal'): array
    {
        return ['nome' => $nome, 'email' => 'i'.uniqid().'@ipccg.org.br', 'telefone' => '67999990000'];
    }

    public function test_inscreve_gerando_codigo_e_token(): void
    {
        $i = $this->service->inscrever($this->evento, $this->dados());

        $this->assertSame('INS0001', $i->codigo);
        $this->assertSame(32, strlen($i->token));
        $this->assertNotNull($i->person_id);
    }

    public function test_codigo_e_sequencial_por_evento(): void
    {
        $a = $this->service->inscrever($this->evento, $this->dados('Um'));
        $b = $this->service->inscrever($this->evento, $this->dados('Dois'));

        $this->assertSame('INS0001', $a->codigo);
        $this->assertSame('INS0002', $b->codigo);

        // Outro evento recomeça do 1: a credencial é curta para ler de relance.
        $outro = Event::create(['church_id' => 1, 'nome' => 'Outro '.uniqid(),
            'inicio' => '2026-12-01', 'status' => 'planejamento']);

        $c = $this->service->inscrever($outro, $this->dados('Tres'));
        $this->assertSame('INS0001', $c->codigo);
    }

    public function test_apagar_do_meio_nao_reaproveita_o_numero(): void
    {
        // É para isto que o gerador lê o MAIOR sufixo em vez de contar linhas:
        // contar devolveria 3 aqui, que já é de alguém.
        $a = $this->service->inscrever($this->evento, $this->dados('Um'));
        $b = $this->service->inscrever($this->evento, $this->dados('Dois'));
        $c = $this->service->inscrever($this->evento, $this->dados('Tres'));

        $b->delete();   // apaga o do MEIO

        $d = $this->service->inscrever($this->evento, $this->dados('Quatro'));

        $this->assertSame('INS0004', $d->codigo, 'reaproveitou um número que já é de alguém');
    }

    public function test_cancelar_nao_libera_o_numero(): void
    {
        // O caso real: inscrição cancelada não some, então o MAX continua certo
        // e ninguém herda um código que já foi impresso e enviado por e-mail.
        $a = $this->service->inscrever($this->evento, $this->dados('Um'));
        $b = $this->service->inscrever($this->evento, $this->dados('Dois'));

        $this->service->cancelar($b);

        $c = $this->service->inscrever($this->evento, $this->dados('Tres'));

        $this->assertSame('INS0003', $c->codigo);
    }

    public function test_prefixo_do_codigo_vem_das_configuracoes_do_evento(): void
    {
        EventSetting::create(['event_id' => $this->evento->id, 'prefixo_codigo' => 'UMP']);

        $i = $this->service->inscrever($this->evento, $this->dados());

        $this->assertSame('UMP0001', $i->codigo);
    }

    public function test_tokens_sao_diferentes(): void
    {
        $a = $this->service->inscrever($this->evento, $this->dados('Um'));
        $b = $this->service->inscrever($this->evento, $this->dados('Dois'));

        $this->assertNotSame($a->token, $b->token);
    }

    public function test_a_mesma_pessoa_nao_se_inscreve_duas_vezes_no_mesmo_evento(): void
    {
        // É o que torna reimportar a planilha idempotente — o erro operacional
        // mais provável de todos.
        $dados = $this->dados('Repetido');
        $this->service->inscrever($this->evento, $dados);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/já está inscrit/');

        $this->service->inscrever($this->evento, $dados);
    }

    public function test_quem_cancelou_pode_se_inscrever_de_novo(): void
    {
        $dados = $this->dados('Arrependido');
        $primeira = $this->service->inscrever($this->evento, $dados);

        $this->service->cancelar($primeira);
        $segunda = $this->service->inscrever($this->evento, $dados);

        $this->assertNotSame($primeira->id, $segunda->id);
        $this->assertSame($primeira->person_id, $segunda->person_id, 'deveria ser a mesma pessoa');
    }

    public function test_a_mesma_pessoa_se_inscreve_em_eventos_diferentes(): void
    {
        $dados = $this->dados('Frequentador');
        $a = $this->service->inscrever($this->evento, $dados);

        $outro = Event::create(['church_id' => 1, 'nome' => 'Retiro '.uniqid(),
            'inicio' => '2027-01-10', 'status' => 'planejamento']);
        $b = $this->service->inscrever($outro, $dados);

        $this->assertSame($a->person_id, $b->person_id, 'os dados deveriam voltar preenchidos');
        $this->assertSame(1, Person::withoutGlobalScopes()->where('id', $a->person_id)->count());
    }

    public function test_snapshot_nao_muda_quando_a_pessoa_muda(): void
    {
        // A razão de existir do snapshot: corrigir o telefone hoje não pode
        // reescrever a lista de presença de um evento encerrado.
        $dados = $this->dados('Mudou de Numero');
        $i = $this->service->inscrever($this->evento, $dados);

        $i->person->update(['telefone' => '67900000000', 'nome' => 'Nome Novo']);

        $this->assertSame('67999990000', $i->fresh()->telefone);
        $this->assertSame('Mudou De Numero', $i->fresh()->nome);
    }

    public function test_email_invalido_marca_sem_descartar_a_pessoa(): void
    {
        // Ela continua inscrita e entra pelo nome na portaria; a marca serve
        // para avisá-la por outro canal ANTES do evento.
        $i = $this->service->inscrever($this->evento, [
            'nome' => 'Email Torto', 'email' => 'isso nao e email',
        ]);

        $this->assertFalse($i->email_valido);
        $this->assertSame(Registration::CONFIRMADA, $i->status);
    }

    public function test_sem_email_tambem_inscreve(): void
    {
        $i = $this->service->inscrever($this->evento, ['nome' => 'Sem Email']);

        $this->assertNotNull($i->id);
        $this->assertFalse($i->email_valido);
    }

    public function test_respostas_do_formulario_ficam_congeladas_na_inscricao(): void
    {
        $i = $this->service->inscrever($this->evento, [
            'nome' => 'Com Respostas', 'email' => 'r'.uniqid().'@x.org',
            'respostas' => ['igreja_que_voce_congrega' => 'IPCCG', 'camiseta' => 'M 2'],
        ], origem: 'importacao');

        $this->assertSame('IPCCG', $i->resposta('igreja_que_voce_congrega'));
        $this->assertSame('M 2', $i->resposta('camiseta'));
        $this->assertSame('importacao', $i->origem);
    }

    public function test_inscrita_em_usa_o_fuso_da_congregacao(): void
    {
        $i = $this->service->inscrever($this->evento, $this->dados());

        $this->assertSame(
            $this->evento->agora()->format('Y-m-d H:i'),
            $i->inscrita_em->format('Y-m-d H:i'),
        );
    }
}
