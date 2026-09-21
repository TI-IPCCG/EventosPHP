<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 | ⚠ Sempre route()/asset(), nunca caminho absoluto fixo: em produção o app
 | roda numa SUBPASTA (ipccg.org.br/eventos) e "/painel" perderia o prefixo.
 */

Route::get('/', fn () => redirect()->route('login'));

Route::livewire('/login', 'auth.login')->name('login');

/*
 | A CREDENCIAL do participante — pública, e fora do `auth` de propósito: quem
 | a abre não tem login, e o token no endereço já é o segredo.
 |
 | ⚠ É uma rota SEPARADA de /p/{token} (o check-in). Servir as duas no mesmo
 | endereço deixaria o endpoint que REGISTRA presença a um auth()->check() de
 | distância de qualquer pessoa com um crachá na mão.
 */
Route::get('/credencial/{token}', function (string $token) {
    $inscricao = App\Models\Participantes\Registration::where('token', $token)->firstOrFail();

    abort_if($inscricao->cancelada, 404);

    /*
     | ⚠ withoutGlobalScopes() no EVENTO, e é obrigatório: Event usa
     | BelongsToChurch, cujo ChurchScope filtra pela congregação da SESSÃO. Aqui
     | quem abre é o participante, que não tem login nem sessão — o escopo
     | filtraria por NULL e devolveria evento nenhum, estourando a página com
     | "property on null".
     |
     | Não é furo de isolamento: chegar aqui exige o token de 32 caracteres, que
     | já identifica uma inscrição específica. A congregação vem dela.
     */
    $evento = App\Models\Event::withoutGlobalScopes()->findOrFail($inscricao->event_id);

    // A presença que já existe, por dia. A pessoa abre a credencial para saber
    // se já entrou hoje — antes disto, a página não respondia essa pergunta, e
    // ela é a primeira que alguém faz ao abrir o próprio ingresso.
    $presencas = App\Models\Participantes\Checkin::where('registration_id', $inscricao->id)
        ->whereNull('cancelado_em')
        ->get()
        ->keyBy('event_day_id');

    return view('participantes.credencial', [
        'inscricao' => $inscricao,
        'evento'    => $evento,
        'presencas' => $presencas,
    ]);
})->name('participantes.credencial');

// Auto-cadastro. Fica FORA do middleware auth de propósito: é a porta de
// entrada de quem ainda não tem conta. O vínculo nasce pendente — ver o
// componente. Quem libera é o responsável, hoje pelo banco.
Route::livewire('/cadastro', 'auth.cadastro')->name('registrar');

/*
 | CARDÁPIO PÚBLICO — sem login, para o participante consultar no celular.
 |
 | O que sai daqui é o que já estaria colado na parede: item, autor, preço e
 | saldo. NÃO expõe custo, fornecedor, margem, nem pessoa nenhuma — e vale
 | conferir isso a cada mudança na view, porque é a única página da livraria
 | que qualquer um alcança.
 |
 | Fica fora do grupo `auth` de propósito, e por isso a view resolve o evento
 | sem depender de sessão (ver o comentário lá).
 */
Route::view('/cardapio', 'livraria.menu')->name('livraria.cardapio');

// Recuperação de senha. Os nomes password.request/password.reset são os que o
// Laravel usa por convenção — mantidos para não surpreender quem conhece.
Route::livewire('/esqueci-senha', 'auth.esqueci-senha')->name('password.request');
Route::livewire('/redefinir-senha', 'auth.redefinir-senha')->name('password.reset');

Route::post('/logout', function () {
    Auth::logout();
    session()->invalidate();
    session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');

Route::middleware('auth')->group(function () {
    Route::livewire('/painel', 'painel')->name('painel');

    // Central de ajuda (página estática) — sem permissão: todo mundo que entra
    // precisa poder ler como o app funciona.
    Route::view('/ajuda', 'ajuda')->name('ajuda');

    Route::livewire('/eventos', 'eventos')
        ->name('eventos')->middleware('can:eventos.ver');

    // Pessoas da congregação: liberar quem se cadastrou, definir perfil.
    // Ver é uma permissão; agir é outra — o componente checa usuarios.gerenciar
    // em cada ação, então quem só tem usuarios.ver enxerga sem poder mexer.
    Route::livewire('/usuarios', 'admin.usuarios')
        ->name('usuarios')->middleware('can:usuarios.ver');

    // Perfis de acesso. perfis.gerenciar é a chave mestra — quem a tem pode se
    // dar qualquer permissão —, então não há versão "só ver" desta tela.
    Route::livewire('/perfis', 'admin.perfis')
        ->name('perfis')->middleware('can:perfis.gerenciar');

    /*
     | O destino do QR. Fica DENTRO do `auth` com can:participantes.checkin, e é
     | daí que vem a anti-falsificação: o token não é credencial, é chave de
     | busca — a autorização vem da sessão do OPERADOR.
     |
     | Quem escaneia o próprio QR cai no login, não num check-in. E um QR
     | forjado só pode apontar para uma inscrição que já existe; não cria nada.
     |
     | Redireciona para a MESMA tela de check-in, com a busca preenchida: o
     | operador vê sempre a mesma coisa, venha do scan, da digitação ou do nome.
     */
    Route::get('/p/{token}', fn (string $token) => redirect()->route(
        'participantes.confirmar', ['token' => $token],
    ))->name('participantes.scan')->middleware('can:participantes.checkin');

    /*
     | A CONFIRMAÇÃO da entrada — uma tela por pessoa, para onde o QR leva.
     |
     | Separada da busca de propósito: quem está na porta precisa CONFERIR antes
     | de liberar. Escanear e registrar no mesmo toque marcaria presença de quem
     | passou o crachá alheio, e ninguém perceberia até o relatório sair errado.
     */
    Route::livewire('/participantes/confirmar/{token}', 'participantes.confirmar')
        ->name('participantes.confirmar')->middleware('can:participantes.checkin');

    // ── Módulo Participantes ──
    // A portaria exige `ver-participantes` (gate composto), e não
    // `participantes.ver`: o voluntário que só tem `participantes.checkin`
    // tomaria 403 na própria tela que precisa operar.
    Route::livewire('/participantes/checkin', 'participantes.checkin')
        ->name('participantes.checkin')->middleware('can:ver-participantes');

    Route::livewire('/participantes', 'participantes.inscritos')
        ->name('participantes.inscritos')->middleware('can:ver-participantes');

    Route::livewire('/participantes/dias', 'participantes.dias')
        ->name('participantes.dias')->middleware('can:participantes.gerenciar');

    // As credenciais viviam numa tela própria e foram absorvidas por Inscritos:
    // no dia a dia, "quem está inscrito" e "quem recebeu a credencial" são a
    // mesma pergunta, e separá-las obrigava a conferir duas telas. A rota fica
    // como redirect para não quebrar link já salvo por ninguém.
    Route::redirect('/participantes/credenciais', '/participantes')
        ->name('participantes.credenciais');

    // Papel: o plano B para a internet cair na portaria.
    Route::view('/participantes/lista-presenca', 'participantes.lista-presenca')
        ->name('participantes.lista')->middleware('can:ver-participantes');

    // ── Módulo Livraria ──
    Route::livewire('/livraria/venda', 'livraria.venda')
        ->name('livraria.venda')->middleware('can:livraria.vender');

    Route::livewire('/livraria/estoque', 'livraria.estoque')
        ->name('livraria.estoque')->middleware('can:ver-livraria');

    // Ver as vendas é parte de ver a livraria; MEXER nelas exige
    // livraria.corrigir, checado dentro da tela em cada ação.
    Route::livewire('/livraria/vendas', 'livraria.vendas')
        ->name('livraria.vendas')->middleware('can:ver-livraria');

    // Mesma divisão de Vendas: consultar é ver a livraria, registrar exige
    // livraria.baixar — checado dentro da tela, em cada ação.
    Route::livewire('/livraria/baixas', 'livraria.baixas')
        ->name('livraria.baixas')->middleware('can:ver-livraria');

    // Cadastros
    Route::livewire('/livraria/fornecedores', 'livraria.fornecedores')
        ->name('livraria.fornecedores')->middleware('can:livraria.catalogo');

    // Consultar o catálogo é parte de ver a livraria — o voluntário de mesa
    // precisa saber o que existe. Cadastrar e editar seguem em
    // livraria.catalogo, checado dentro da tela em cada ação.
    Route::livewire('/livraria/catalogo', 'livraria.catalogo')
        ->name('livraria.catalogo')->middleware('can:ver-livraria');

    Route::livewire('/livraria/categorias', 'livraria.categorias')
        ->name('livraria.categorias')->middleware('can:livraria.catalogo');

    Route::livewire('/livraria/remessa', 'livraria.remessa')
        ->name('livraria.remessa')->middleware('can:livraria.remessa');

    // Folha de etiquetas: opcional, sem trava nenhuma no resto do app.
    Route::view('/livraria/etiquetas', 'livraria.etiquetas')
        ->name('livraria.etiquetas')->middleware('can:livraria.remessa');

    // Cardápio para a parede: quem opera a mesa imprime, então basta ver a
    // livraria — não é ato de gestão.
    Route::view('/livraria/menu', 'livraria.menu')
        ->name('livraria.menu')->middleware('can:ver-livraria');
});
