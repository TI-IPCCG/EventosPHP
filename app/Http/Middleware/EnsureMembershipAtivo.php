<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Desativar alguém precisa valer AGORA, não quando a sessão expirar.
 *
 * Perder o vínculo já zera as permissões (User::permissionSlugs), então as
 * telas com `can:` respondem 403 na hora. Mas o /painel não exige permissão
 * nenhuma — e mostra receita, custos e o devido aos fornecedores. Sem este
 * middleware, quem acabou de ser desativado continuava lendo o financeiro do
 * evento por até duas horas, que é a validade da sessão.
 *
 * Super-admin passa: ele entra em qualquer congregação, inclusive onde não tem
 * vínculo — é a mesma regra que o login aplica.
 */
class EnsureMembershipAtivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user || $user->is_super || ! session()->has('church_id')) {
            return $next($request);
        }

        $ativo = Membership::where('user_id', $user->id)
            ->where('church_id', session('church_id'))
            ->where('status', true)
            ->exists();

        if (! $ativo) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with(
                'aviso_login',
                'Seu acesso nesta congregação foi encerrado. Fale com o Administrador.',
            );
        }

        return $next($request);
    }
}
