<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 | ⚠ Sempre route()/asset(), nunca caminho absoluto fixo: em produção o app
 | roda numa SUBPASTA (ipccg.org.br/eventos) e "/painel" perderia o prefixo.
 */

Route::get('/', fn () => redirect()->route('login'));

Route::livewire('/login', 'auth.login')->name('login');

// Auto-cadastro. Fica FORA do middleware auth de propósito: é a porta de
// entrada de quem ainda não tem conta. O vínculo nasce pendente — ver o
// componente. Quem libera é o responsável, hoje pelo banco.
Route::livewire('/cadastro', 'auth.cadastro')->name('registrar');

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

    // ── Módulo Livraria ──
    Route::livewire('/livraria/venda', 'livraria.venda')
        ->name('livraria.venda')->middleware('can:livraria.vender');

    Route::livewire('/livraria/estoque', 'livraria.estoque')
        ->name('livraria.estoque')->middleware('can:ver-livraria');

    // Cadastros
    Route::livewire('/livraria/fornecedores', 'livraria.fornecedores')
        ->name('livraria.fornecedores')->middleware('can:livraria.catalogo');

    Route::livewire('/livraria/catalogo', 'livraria.catalogo')
        ->name('livraria.catalogo')->middleware('can:livraria.catalogo');

    Route::livewire('/livraria/categorias', 'livraria.categorias')
        ->name('livraria.categorias')->middleware('can:livraria.catalogo');

    Route::livewire('/livraria/remessa', 'livraria.remessa')
        ->name('livraria.remessa')->middleware('can:livraria.remessa');
});
