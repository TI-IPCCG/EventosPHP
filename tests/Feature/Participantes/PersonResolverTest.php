<?php

namespace Tests\Feature\Participantes;

use App\Models\Event;
use App\Models\Participantes\Person;
use App\Models\Participantes\Registration;
use App\Services\Participantes\PersonResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A deduplicação de pessoas.
 *
 * É a única peça do módulo capaz de corromper dado em silêncio: fundir duas
 * pessoas é irreversível na prática, e duplicar quebra o requisito de "os dados
 * voltam preenchidos no próximo evento". Por isso os testes vêm antes da tela.
 */
class PersonResolverTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    private PersonResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        session(['church_id' => 1]);
        $this->resolver = new PersonResolver;
    }

    private function cpf(): string
    {
        // CPFs válidos de teste, com dígito verificador correto.
        static $validos = ['52998224725', '11144477735', '19100000000', '86288366757'];
        static $i = 0;

        return $validos[$i++ % count($validos)];
    }

    public function test_pessoa_nova_e_criada(): void
    {
        $r = $this->resolver->resolver([
            'nome' => 'Fulano de Tal', 'email' => 'novo'.uniqid().'@ipccg.org.br',
        ], 1);

        $this->assertTrue($r->ehNova());
        $this->assertSame(1, (int) $r->pessoa->church_id);
    }

    public function test_reencontra_pelo_cpf_mesmo_com_email_diferente(): void
    {
        $cpf = $this->cpf();
        $original = Person::create(['church_id' => 1, 'nome' => 'Maria Souza',
            'cpf' => $cpf, 'email' => 'maria.antiga'.uniqid().'@x.org']);

        $r = $this->resolver->resolver([
            'nome' => 'Maria Souza', 'cpf' => $cpf, 'email' => 'maria.nova'.uniqid().'@x.org',
        ], 1);

        $this->assertSame($original->id, $r->pessoa->id, 'deveria reencontrar pelo CPF');
        $this->assertSame('cpf', $r->via);
    }

    public function test_cpf_com_pontuacao_encontra_o_mesmo_cadastro(): void
    {
        // Sem normalizar, "529.982.247-25" e "52998224725" seriam duas pessoas
        // e a UNIQUE não serviria para nada.
        $cpf = $this->cpf();
        $original = Person::create(['church_id' => 1, 'nome' => 'Jose Lima', 'cpf' => $cpf]);

        $formatado = substr($cpf, 0, 3).'.'.substr($cpf, 3, 3).'.'.substr($cpf, 6, 3).'-'.substr($cpf, 9, 2);
        $r = $this->resolver->resolver(['nome' => 'Jose Lima', 'cpf' => $formatado], 1);

        $this->assertSame($original->id, $r->pessoa->id);
    }

    public function test_reencontra_pelo_email_quando_nao_ha_cpf(): void
    {
        $email = 'joao'.uniqid().'@ipccg.org.br';
        $original = Person::create(['church_id' => 1, 'nome' => 'João Pedro', 'email' => $email]);

        $r = $this->resolver->resolver(['nome' => 'João Pedro', 'email' => $email], 1);

        $this->assertSame($original->id, $r->pessoa->id);
        $this->assertSame('email', $r->via);
    }

    public function test_email_com_caixa_diferente_encontra_o_mesmo_cadastro(): void
    {
        $email = 'Ana'.uniqid().'@IPCCG.org.br';
        $original = Person::create(['church_id' => 1, 'nome' => 'Ana Clara', 'email' => $email]);

        $r = $this->resolver->resolver([
            'nome' => 'Ana Clara', 'email' => mb_strtoupper($email),
        ], 1);

        $this->assertSame($original->id, $r->pessoa->id);
    }

    public function test_casal_com_email_compartilhado_nao_vira_a_mesma_pessoa(): void
    {
        // O caso mais comum, e o que a UNIQUE parece quebrar: o segundo cônjuge
        // simplesmente fica sem e-mail no cadastro mestre — e ainda assim
        // recebe a credencial, porque o envio usa o e-mail da INSCRIÇÃO.
        $email = 'casal'.uniqid().'@ipccg.org.br';
        $marido = Person::create(['church_id' => 1, 'nome' => 'Carlos Andrade', 'email' => $email]);

        $r = $this->resolver->resolver(['nome' => 'Beatriz Andrade', 'email' => $email], 1);

        $this->assertNotSame($marido->id, $r->pessoa->id, 'a esposa virou o marido');
        $this->assertTrue($r->ehNova());
        $this->assertNull($r->pessoa->email, 'o e-mail do marido foi tomado');
    }

    public function test_duas_pessoas_sem_cpf_coexistem(): void
    {
        // NULL não colide em UNIQUE, e aqui isso é a SEMÂNTICA desejada:
        // "não informou" é ausência de chave, não um valor compartilhado.
        $a = $this->resolver->resolver(['nome' => 'Sem Documento Um',
            'email' => 'a'.uniqid().'@x.org'], 1);
        $b = $this->resolver->resolver(['nome' => 'Sem Documento Dois',
            'email' => 'b'.uniqid().'@x.org'], 1);

        $this->assertNotSame($a->pessoa->id, $b->pessoa->id);
        $this->assertNull($a->pessoa->cpf);
        $this->assertNull($b->pessoa->cpf);
    }

    public function test_chave_historica_encontra_quem_trocou_de_email(): void
    {
        // O e-mail antigo só existe no snapshot de uma inscrição passada — e é
        // dali que a pessoa é reencontrada, sem tabela de aliases nenhuma.
        $antigo = 'antigo'.uniqid().'@x.org';
        $pessoa = Person::create(['church_id' => 1, 'nome' => 'Paulo Ricardo',
            'email' => 'atual'.uniqid().'@x.org']);

        $evento = Event::firstOrCreate(['church_id' => 1, 'nome' => 'Evento histórico'],
            ['inicio' => today(), 'status' => 'encerrado']);

        Registration::create([
            'event_id' => $evento->id, 'person_id' => $pessoa->id,
            'codigo' => 'H'.substr(uniqid(), -6), 'token' => Str::random(32),
            'nome' => 'Paulo Ricardo', 'email' => $antigo,
            'inscrita_em' => now(),
        ]);

        $r = $this->resolver->resolver(['nome' => 'Paulo Ricardo', 'email' => $antigo], 1);

        $this->assertSame($pessoa->id, $r->pessoa->id);
        $this->assertSame('historico', $r->via);
    }

    public function test_conflito_entre_cpf_e_email_vincula_pelo_cpf_e_marca(): void
    {
        $cpf = $this->cpf();
        $porCpf   = Person::create(['church_id' => 1, 'nome' => 'Roberto Dias', 'cpf' => $cpf]);
        $porEmail = Person::create(['church_id' => 1, 'nome' => 'Outra Pessoa',
            'email' => 'outra'.uniqid().'@x.org']);

        $r = $this->resolver->resolver([
            'nome' => 'Roberto Dias', 'cpf' => $cpf, 'email' => $porEmail->email,
        ], 1);

        $this->assertSame($porCpf->id, $r->pessoa->id, 'o CPF é a chave forte');
        $this->assertTrue($r->conflito, 'o conflito deveria ser sinalizado');
        $this->assertSame($porEmail->fresh()->email, $porEmail->email, 'a outra pessoa foi alterada');
    }

    public function test_nunca_sobrescreve_dado_preenchido_com_vazio(): void
    {
        $cpf = $this->cpf();
        $pessoa = Person::create(['church_id' => 1, 'nome' => 'Telefone Preenchido',
            'cpf' => $cpf, 'telefone' => '67999998888']);

        // Inscrição nova, sem telefone: não pode apagar o que já tínhamos.
        $this->resolver->resolver(['nome' => 'Telefone Preenchido', 'cpf' => $cpf, 'telefone' => ''], 1);

        $this->assertSame('67999998888', $pessoa->fresh()->telefone);
    }

    public function test_preenche_o_buraco_quando_o_dado_e_novo(): void
    {
        $cpf = $this->cpf();
        $pessoa = Person::create(['church_id' => 1, 'nome' => 'Sem Telefone', 'cpf' => $cpf]);

        $this->resolver->resolver(['nome' => 'Sem Telefone', 'cpf' => $cpf,
            'telefone' => '(67) 98888-7777'], 1);

        $this->assertSame('67988887777', $pessoa->fresh()->telefone);
    }

    public function test_nome_em_caixa_alta_do_forms_vira_nome_de_gente(): void
    {
        $r = $this->resolver->resolver([
            'nome' => '  JOÃO   DA   SILVA  ', 'email' => 'js'.uniqid().'@x.org',
        ], 1);

        $this->assertSame('João Da Silva', $r->pessoa->nome);
    }

    public function test_chave_de_pessoa_fundida_leva_a_quem_sobreviveu(): void
    {
        // A chave pode ter ficado na lápide. Ignorá-la faria o resolver tentar
        // criar outra pessoa com o mesmo CPF e estourar a UNIQUE na cara de quem
        // está se inscrevendo. Quem foi fundido virou aquela outra pessoa — é
        // para lá que a inscrição tem de ir.
        $cpf = $this->cpf();
        $sobrevivente = Person::create(['church_id' => 1, 'nome' => 'Sobrevivente']);
        $fundida = Person::create(['church_id' => 1, 'nome' => 'Fundida', 'cpf' => $cpf,
            'fundida_em_id' => $sobrevivente->id, 'fundida_em' => now()]);

        $r = $this->resolver->resolver(['nome' => 'Fundida', 'cpf' => $cpf], 1);

        $this->assertSame($sobrevivente->id, $r->pessoa->id, 'deveria seguir a lápide');
        $this->assertNotSame($fundida->id, $r->pessoa->id);
    }

    public function test_cadeia_de_fusao_nao_entra_em_loop(): void
    {
        // Dado torto (um ciclo de fusões) não pode travar a inscrição de
        // ninguém — o resolver desiste depois de alguns saltos e devolve alguém.
        $a = Person::create(['church_id' => 1, 'nome' => 'Ciclo A']);
        $b = Person::create(['church_id' => 1, 'nome' => 'Ciclo B', 'fundida_em_id' => $a->id]);
        $a->update(['fundida_em_id' => $b->id]);

        $cpf = $this->cpf();
        $b->update(['cpf' => $cpf]);

        $r = $this->resolver->resolver(['nome' => 'Ciclo', 'cpf' => $cpf], 1);

        $this->assertNotNull($r->pessoa->id);
    }
}
