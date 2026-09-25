<?php

namespace Tests\Feature\Participantes;

use App\Models\Event;
use App\Models\Membership;
use App\Models\Participantes\Person;
use App\Models\Participantes\Registration;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use App\Services\Participantes\PersonResolver;
use App\Services\Participantes\RegistrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Corrigir uma inscrição já feita.
 *
 * O caso é rotina: a pessoa digita o próprio e-mail errado no formulário e só
 * se descobre quando a credencial não chega. Até aqui o único caminho era
 * cancelar e inscrever de novo — que troca o código, invalida o QR já enviado
 * e some com a inscrição original do relatório.
 */
class EdicaoDeInscricaoTest extends TestCase
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

        $this->coord = $this->pessoaCom(array_keys(\Database\Seeders\PermissionSeeder::CATALOG));
        $this->inscricoes = app(RegistrationService::class);
    }

    private function pessoaCom(array $slugs): User
    {
        $perfil = SystemRole::create(['church_id' => 1, 'name' => 'P'.uniqid()]);
        $perfil->permissions()->sync(Permission::whereIn('slug', $slugs)->pluck('id'));

        $u = User::create(['name' => 'Operador', 'email' => 'e'.uniqid().'@ipccg.org.br',
            'password' => 'segredo123']);

        Membership::create(['user_id' => $u->id, 'church_id' => 1,
            'system_role_id' => $perfil->id, 'status' => true, 'created_at' => now()]);

        return $u;
    }

    private function inscrito(string $nome = 'Fulano de Tal', ?string $email = null): Registration
    {
        return $this->inscricoes->inscrever($this->evento, [
            'nome'  => $nome,
            'email' => $email ?? 'f'.uniqid().'@exemplo.com',
        ]);
    }

    // ─────────────────────── o que não pode mudar ───────────────────────

    /**
     * ⚠ A GARANTIA CENTRAL: corrigir não invalida o crachá.
     *
     * A credencial já foi enviada, impressa, talvez salva na galeria do
     * celular. Trocar o token faria o QR de quem já recebeu parar de
     * funcionar, e o erro só apareceria na portaria, com a pessoa na frente.
     */
    public function test_corrigir_nao_troca_o_codigo_nem_o_token(): void
    {
        $i = $this->inscrito('Nome Errado');

        $codigo = $i->codigo;
        $token  = $i->token;

        $this->inscricoes->corrigir($i, ['nome' => 'Nome Certo']);

        $depois = $i->fresh();
        $this->assertSame($codigo, $depois->codigo);
        $this->assertSame($token, $depois->token);
        $this->assertSame('Nome Certo', $depois->nome);
    }

    public function test_corrigir_nao_mexe_no_checkin_ja_feito(): void
    {
        $i = $this->inscrito();

        $this->inscricoes->corrigir($i, ['nome' => 'Outro Nome', 'email' => 'novo'.uniqid().'@exemplo.com']);

        $this->assertNotNull($i->fresh()->inscrita_em, 'a data da inscrição original fica');
        $this->assertNull($i->fresh()->cancelada_em);
    }

    // ─────────────────────── o conserto em si ───────────────────────

    public function test_corrige_o_email_e_a_credencial_passa_a_ir_para_o_novo(): void
    {
        $i = $this->inscrito('Typo Silva', 'erradoo@exemplo.com');

        $this->inscricoes->corrigir($i, ['nome' => 'Typo Silva', 'email' => 'CERTO@Exemplo.com ']);

        // minúsculo e sem espaço, como o resolver grava
        $this->assertSame('certo@exemplo.com', $i->fresh()->email);
        $this->assertTrue((bool) $i->fresh()->email_valido);
    }

    public function test_email_invalido_marca_a_inscricao_sem_descartar(): void
    {
        $i = $this->inscrito();

        $this->inscricoes->corrigir($i, ['nome' => $i->nome, 'email' => 'isso-nao-e-email']);

        $this->assertFalse((bool) $i->fresh()->email_valido);
        $this->assertNull($i->fresh()->cancelada_em, 'continua inscrita');
    }

    /** O recado da portaria também se edita — é o caso de quem compra camiseta no dia. */
    public function test_corrige_a_observacao_que_a_portaria_le(): void
    {
        $i = $this->inscrito();

        $this->inscricoes->corrigir($i, [
            'nome' => $i->nome, 'observacao' => 'Camiseta: comprou no dia · Tamanho G',
        ]);

        $this->assertSame('Camiseta: comprou no dia · Tamanho G', $i->fresh()->observacao);
    }

    // ─────────────────── a inscrição e o cadastro ───────────────────

    /**
     * Corrigir um e-mail digitado errado precisa acertar os DOIS registros: o
     * snapshot da inscrição (que vale para este evento) e o cadastro da pessoa
     * — senão o erro reaparece no próximo evento.
     */
    public function test_a_correcao_chega_ao_cadastro_da_pessoa(): void
    {
        $i = $this->inscrito('Maria Antiga', 'antigo'.uniqid().'@exemplo.com');
        $novo = 'novo'.uniqid().'@exemplo.com';

        $espelhou = $this->inscricoes->corrigir($i, ['nome' => 'Maria Nova', 'email' => $novo]);

        $this->assertTrue($espelhou);
        $this->assertSame($novo, $i->fresh()->person->email);
        $this->assertSame('Maria Nova', $i->fresh()->person->nome);
    }

    /**
     * ⚠ Mas o cadastro tem UNIQUE de e-mail: se o valor novo já é de outra
     * pessoa, tentar gravar tomaria uma violação na cara do usuário no meio de
     * um conserto simples. A inscrição é corrigida assim mesmo — é dela que
     * sai a credencial — e o cadastro fica como estava.
     */
    public function test_email_que_ja_e_de_outra_pessoa_corrige_so_a_inscricao(): void
    {
        $dono  = $this->inscrito('Dono do Email', 'ocupado'.uniqid().'@exemplo.com');
        $outra = $this->inscrito('Outra Pessoa');

        $emailOcupado = $dono->email;
        $cadastroAntes = $outra->person->email;

        $espelhou = $this->inscricoes->corrigir($outra, [
            'nome' => 'Outra Pessoa', 'email' => $emailOcupado,
        ]);

        $this->assertFalse($espelhou, 'o cadastro não podia ser atualizado');
        $this->assertSame($emailOcupado, $outra->fresh()->email, 'mas a inscrição foi corrigida');
        $this->assertSame($cadastroAntes, $outra->fresh()->person->email, 'o cadastro ficou como estava');
        $this->assertSame($emailOcupado, $dono->fresh()->person->email, 'e o dono não perdeu o dele');
    }

    /** Nunca funde ninguém: correção não é dedup. */
    public function test_corrigir_nao_funde_pessoas(): void
    {
        $a = $this->inscrito('Pessoa A');
        $b = $this->inscrito('Pessoa B');

        $this->inscricoes->corrigir($b, ['nome' => 'Pessoa A', 'email' => $a->email]);

        $this->assertNotSame($a->fresh()->person_id, $b->fresh()->person_id);
        $this->assertSame(2, Registration::where('event_id', $this->evento->id)->count());
    }

    // ─────────────────────────── a tela ───────────────────────────

    public function test_a_tela_carrega_e_salva_a_correcao(): void
    {
        $i = $this->inscrito('Errado da Silva', 'errado'.uniqid().'@exemplo.com');

        Livewire::actingAs($this->coord)->test('participantes.inscritos')
            ->call('editar', $i->id)
            ->assertSet('editando', $i->id)
            ->assertSet('nome', 'Errado da Silva')   // "da" minúsculo, como deve ser
            ->set('nome', 'Certo da Silva')
            ->set('observacao', 'Camiseta: Sim')
            ->call('salvar')
            ->assertHasNoErrors()
            ->assertSet('editando', null);

        $this->assertSame('Certo da Silva', $i->fresh()->nome);
        $this->assertSame('Camiseta: Sim', $i->fresh()->observacao);
    }

    /** Depois de corrigir, o formulário volta limpo — senão o próximo cadastro sai com sobras. */
    public function test_o_formulario_volta_limpo_para_um_cadastro_novo(): void
    {
        $i = $this->inscrito('Alguem Editado');

        $componente = Livewire::actingAs($this->coord)->test('participantes.inscritos')
            ->call('editar', $i->id)
            ->call('novo');

        $componente->assertSet('editando', null)->assertSet('nome', '')->assertSet('observacao', '');
    }

    public function test_editar_exige_permissao(): void
    {
        $porteiro = $this->pessoaCom(['eventos.ver', 'participantes.ver', 'participantes.checkin']);
        $i = $this->inscrito('Intocavel');

        Livewire::actingAs($porteiro)->test('participantes.inscritos')
            ->call('editar', $i->id)->assertForbidden();

        $this->assertSame('Intocavel', $i->fresh()->nome);
    }

    /** A tela só alcança inscrição DESTE evento — o findOrFail recusa o resto. */
    public function test_nao_edita_inscricao_de_outro_evento(): void
    {
        $outroEvento = Event::create(['church_id' => 1, 'nome' => 'Outro '.uniqid(),
            'inicio' => '2026-10-01', 'status' => 'planejamento']);

        $alheia = $this->inscricoes->inscrever($outroEvento, [
            'nome' => 'De Outro Evento', 'email' => 'x'.uniqid().'@exemplo.com',
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::actingAs($this->coord)->test('participantes.inscritos')
            ->call('editar', $alheia->id);
    }

    // ────────────── o nome, que antes não dava para consertar ──────────────

    /**
     * ⚠ Sem isto a edição de NOMES era inútil.
     *
     * O mutator jogava tudo em MB_CASE_TITLE, então "Shara da Silva" virava
     * "Shara Da Silva" toda vez que se salvava — o operador digitava a forma
     * certa e o modelo reescrevia por cima. Não havia como consertar.
     */
    public function test_preposicao_do_nome_fica_minuscula(): void
    {
        $i = $this->inscrito('Fulano');

        $this->inscricoes->corrigir($i, ['nome' => 'shara da silva']);

        $this->assertSame('Shara da Silva', $i->fresh()->nome);
    }

    public function test_apostrofo_mantem_a_maiuscula(): void
    {
        $i = $this->inscrito('Fulano');

        $this->inscricoes->corrigir($i, ['nome' => "arnaldo pinheiro mont'alvão júnior"]);

        $this->assertSame("Arnaldo Pinheiro Mont'Alvão Júnior", $i->fresh()->nome);
    }

    /** O grito do formulário continua virando nome de gente. */
    public function test_caixa_alta_do_formulario_continua_sendo_corrigida(): void
    {
        $i = $this->inscrito('Fulano');

        $this->inscricoes->corrigir($i, ['nome' => 'DEISE SOUZA FERNANDES MENDONÇA']);

        $this->assertSame('Deise Souza Fernandes Mendonça', $i->fresh()->nome);
    }

    /** Partícula que ABRE o nome é primeiro nome, não preposição. */
    public function test_particula_no_comeco_mantem_a_maiuscula(): void
    {
        $i = $this->inscrito('Fulano');

        $this->inscricoes->corrigir($i, ['nome' => 'da silva neto']);

        $this->assertSame('Da Silva Neto', $i->fresh()->nome);
    }
}
