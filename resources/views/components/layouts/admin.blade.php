{{-- App shell autenticado (Emerald Archive): sidebar fixa no desktop, drawer no
     mobile. As classes são as do design system — inventar nome novo custou uma
     página que rolava na horizontal na primeira tentativa.

     ⚠ Todo link usa route(), nunca caminho absoluto: em produção o app roda
     numa SUBPASTA (ipccg.org.br/eventos) e "/painel" perderia o prefixo. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@400;700;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ filemtime(public_path('assets/app.css')) }}">

    @livewireStyles
</head>
<body>
    @php($church = \App\Models\Church::find(session('church_id')))
    @php($evento = \App\Models\Event::atual())

    <div class="mobile-topbar">
        <button class="hamburger" id="sidebar-toggle" aria-label="Abrir menu">
            <i class="bi bi-list"></i>
        </button>
        <div style="flex:1;min-width:0">
            <div class="mobile-topbar-title">IPCCG</div>
            <div class="mobile-topbar-sub">{{ $evento?->nome ?? 'Eventos' }}</div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="topbar-logout" aria-label="Sair">
                <i class="bi bi-box-arrow-right"></i>
            </button>
        </form>
    </div>

    <div class="sidebar-overlay" id="sidebar-overlay"></div>

    <div class="admin-layout">
        <aside class="sidebar" id="sidebar">
            <a class="sidebar-brand" href="{{ route('painel') }}">
                <div>
                    <div class="sidebar-brand-title">IPCCG</div>
                    <div class="sidebar-brand-sub">{{ $church?->name ?? 'Eventos' }}</div>
                </div>
            </a>

            @if ($evento)
                <div class="sidebar-context">
                    <span class="sidebar-context-label">Evento ativo</span>
                    <strong>{{ $evento->nome }}</strong>
                </div>
            @endif

            {{-- Menu em grupos: os dois itens do dia a dia ficam soltos no topo,
                 o resto se recolhe. Cada MÓDULO novo entra como um <details>
                 próprio — é isso que impede a barra de virar uma lista rolante
                 conforme o app cresce.

                 O grupo abre sozinho quando a tela aberta está dentro dele, para
                 a pessoa nunca ter de caçar onde está. --}}
            <nav class="sidebar-nav">
                <a href="{{ route('painel') }}"
                   class="nav-item {{ request()->routeIs('painel') ? 'active' : '' }}">
                    <i class="bi bi-speedometer2 nav-icon"></i><span>Painel</span>
                </a>

                {{-- A mesa fica fora de grupo: é a tela mais usada durante o
                     evento, e um toque a mais na fila custa caro. --}}
                @can('livraria.vender')
                    <a href="{{ route('livraria.venda') }}"
                       class="nav-item {{ request()->routeIs('livraria.venda') ? 'active' : '' }}">
                        <i class="bi bi-bag-check nav-icon"></i><span>Venda</span>
                    </a>
                @endcan

                {{-- ── Módulo Livraria ── --}}
                @php($verLivraria = auth()->user()?->can('ver-livraria')
                                 || auth()->user()?->can('livraria.remessa')
                                 || auth()->user()?->can('livraria.catalogo'))
                @if ($verLivraria)
                    <details class="nav-group"
                             @if (request()->routeIs('livraria.estoque', 'livraria.remessa',
                                                     'livraria.catalogo', 'livraria.categorias',
                                                     'livraria.fornecedores')) open @endif>
                        <summary>
                            <i class="bi bi-book nav-icon"></i><span>Livraria</span>
                        </summary>

                        @can('ver-livraria')
                            <a href="{{ route('livraria.estoque') }}"
                               class="nav-item {{ request()->routeIs('livraria.estoque') ? 'active' : '' }}">
                                <i class="bi bi-box-seam nav-icon"></i><span>Estoque</span>
                            </a>
                        @endcan

                        @can('livraria.remessa')
                            <a href="{{ route('livraria.remessa') }}"
                               class="nav-item {{ request()->routeIs('livraria.remessa') ? 'active' : '' }}">
                                <i class="bi bi-truck nav-icon"></i><span>Remessa</span>
                            </a>
                        @endcan

                        @can('livraria.catalogo')
                            <a href="{{ route('livraria.catalogo') }}"
                               class="nav-item {{ request()->routeIs('livraria.catalogo') ? 'active' : '' }}">
                                <i class="bi bi-journals nav-icon"></i><span>Catálogo</span>
                            </a>

                            <a href="{{ route('livraria.categorias') }}"
                               class="nav-item {{ request()->routeIs('livraria.categorias') ? 'active' : '' }}">
                                <i class="bi bi-tags nav-icon"></i><span>Categorias</span>
                            </a>

                            <a href="{{ route('livraria.fornecedores') }}"
                               class="nav-item {{ request()->routeIs('livraria.fornecedores') ? 'active' : '' }}">
                                <i class="bi bi-shop nav-icon"></i><span>Fornecedores</span>
                            </a>
                        @endcan
                    </details>
                @endif

                {{-- ── Administração: vale para o app inteiro, não para um módulo ── --}}
                @php($verAdmin = auth()->user()?->can('eventos.ver')
                              || auth()->user()?->can('usuarios.ver'))
                @if ($verAdmin)
                    <details class="nav-group"
                             @if (request()->routeIs('eventos', 'usuarios')) open @endif>
                        <summary>
                            <i class="bi bi-sliders2 nav-icon"></i><span>Administração</span>
                        </summary>

                        @can('eventos.ver')
                            <a href="{{ route('eventos') }}"
                               class="nav-item {{ request()->routeIs('eventos') ? 'active' : '' }}">
                                <i class="bi bi-calendar3 nav-icon"></i><span>Eventos</span>
                            </a>
                        @endcan

                        @can('usuarios.ver')
                            <a href="{{ route('usuarios') }}"
                               class="nav-item {{ request()->routeIs('usuarios') ? 'active' : '' }}">
                                <i class="bi bi-people nav-icon"></i><span>Pessoas</span>
                            </a>
                        @endcan
                    </details>
                @endif
            </nav>

            <div class="sidebar-footer">
                {{-- Ajuda fica aqui, separada da navegação de trabalho: não é
                     uma tela de operação, e no fim do menu ela é encontrada
                     quando se procura, sem competir com o fluxo do dia. --}}
                <a href="{{ route('ajuda') }}"
                   class="nav-item {{ request()->routeIs('ajuda') ? 'active' : '' }}" style="margin-bottom:8px">
                    <i class="bi bi-question-circle nav-icon"></i><span>Ajuda</span>
                </a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="sidebar-logout">
                        <i class="bi bi-box-arrow-right"></i> Sair
                    </button>
                </form>
            </div>
        </aside>

        <main class="content-area">
            {{ $slot }}
        </main>
    </div>

    <x-toasts />

    @livewireScripts
    <script src="{{ asset('assets/ui.js') }}"></script>
    <script>
        // Drawer no mobile: o overlay e o hambúrguer só alternam uma classe.
        (function () {
            const btn = document.getElementById('sidebar-toggle');
            const bar = document.getElementById('sidebar');
            const ovl = document.getElementById('sidebar-overlay');
            if (!btn || !bar || !ovl) return;
            const fechar = () => { bar.classList.remove('open'); ovl.classList.remove('open'); };
            btn.addEventListener('click', () => {
                bar.classList.toggle('open');
                ovl.classList.toggle('open');
            });
            ovl.addEventListener('click', fechar);
            document.addEventListener('keydown', e => e.key === 'Escape' && fechar());
        })();
    </script>
</body>
</html>
