<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve o contexto da congregação em toda request web: reidrata a sessão
 * quando ela se perde, e aplica o fuso.
 *
 * ⚠ Vale só no caminho web. Comando de terminal (cron, artisan) NÃO tem
 * sessão, então quem roda fora do navegador precisa fixar o fuso por
 * congregação na mão — foi assim que o EscalaMembros levou um bug de lembrete
 * disparando na hora errada.
 *
 * ── POR QUE A REIDRATAÇÃO EXISTE ──────────────────────────────────────
 * `church_id` era gravado num lugar só: o login. Com SESSION_LIFETIME=120 e
 * Auth::login(remember: true), a sessão expira em 2h e o cookie remember
 * re-autentica numa sessão NOVA E VAZIA — autenticado, sem church_id.
 *
 * Aí o ChurchScope passa a filtrar `WHERE church_id IS NULL`, o que devolve
 * zero linhas em TUDO: Event::atual() vira null e cada tela informa "nenhum
 * evento", sem erro nenhum e sem pista do motivo. Quem logou às 17h e volta ao
 * app às 19h30 encontra um app vazio e não tem como se recuperar.
 */
class SetChurchContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($redirecionar = $this->resolverCongregacao($request)) {
            return $redirecionar;
        }

        if ($tz = session('church_tz')) {
            date_default_timezone_set($tz);
            config(['app.timezone' => $tz]);
        }

        return $next($request);
    }

    /**
     * Devolve um redirect quando a congregação não pode ser deduzida; null
     * quando o contexto está resolvido (ou não é da nossa conta).
     */
    private function resolverCongregacao(Request $request): ?Response
    {
        $user = Auth::user();

        if (! $user || session()->has('church_id')) {
            return null;
        }

        // As telas de autenticação são a saída, não o problema: mexer na
        // sessão delas faria o login se redirecionar para si mesmo em loop.
        if ($request->routeIs('login', 'logout', 'registrar', 'password.*')) {
            return null;
        }

        $vinculos = Membership::where('user_id', $user->id)
            ->where('status', true)
            ->with('church')
            ->get();

        // Um vínculo só: não há o que perguntar. É o caso da esmagadora
        // maioria — e o do voluntário de mesa, que não sabe a senha para
        // relogar e ficaria preso numa tela vazia.
        if ($vinculos->count() === 1) {
            $igreja = $vinculos->first()->church;

            session([
                'church_id' => $vinculos->first()->church_id,
                'church_tz' => $igreja?->timezone ?: 'America/Sao_Paulo',
            ]);

            return null;
        }

        // Nenhum vínculo (super-admin) ou mais de um: só a pessoa sabe onde
        // quer entrar. Volta ao login, que já tem o seletor de congregação.
        $destino = $request->fullUrl();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // ⚠ Depois do invalidate, não antes: ele limpa a sessão inteira, e a
        // intenção gravada antes iria embora com ela. É o que faz a pessoa
        // voltar para a tela que queria, em vez de sempre para o painel.
        session(['url.intended' => $destino]);

        return redirect()->route('login')->with(
            'aviso_login',
            'Sua sessão expirou. Escolha a congregação para continuar.',
        );
    }
}
