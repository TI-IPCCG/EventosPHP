{{--
    Paginação do Emerald Archive.

    ── POR QUE NÃO É A VIEW QUE O LARAVEL PUBLICA ────────────────────────
    As views que vêm de fábrica (tailwind, bootstrap) dependem de classes
    utilitárias que este projeto não tem — ele não tem build de CSS. Sem
    elas o SVG da seta renderiza no tamanho natural e ocupa meia tela.

    ── COMO ELA É ESCOLHIDA ──────────────────────────────────────────────
    Nas telas Livewire, pelo trait App\Support\Paginacao — e só por ele:
    o Livewire sobrescreve o Paginator::defaultView() do app a cada render.
    Fora do Livewire, pelo default registrado no AppServiceProvider.

    `getPageName()` vai em todas as chamadas porque uma tela pode ter dois
    paginadores; sem ele, mexer num moveria o outro.

    onEachSide(1) mantém a barra estreita o bastante para caber em 390px,
    que é a largura da mesa.
--}}
@if ($paginator->hasPages())
    <nav class="paginacao" role="navigation" aria-label="Paginação">
        @if ($paginator->onFirstPage())
            <span class="pag-item desabilitado" aria-disabled="true" aria-label="Página anterior">
                <i class="bi bi-chevron-left"></i>
            </span>
        @else
            <button type="button" class="pag-item" aria-label="Página anterior"
                    wire:click="previousPage('{{ $paginator->getPageName() }}')"
                    wire:loading.attr="disabled">
                <i class="bi bi-chevron-left"></i>
            </button>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="pag-item reticencias" aria-disabled="true">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="pag-item atual" aria-current="page">{{ $page }}</span>
                    @else
                        <button type="button" class="pag-item" aria-label="Ir para a página {{ $page }}"
                                wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')"
                                wire:loading.attr="disabled">
                            {{ $page }}
                        </button>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <button type="button" class="pag-item" aria-label="Próxima página"
                    wire:click="nextPage('{{ $paginator->getPageName() }}')"
                    wire:loading.attr="disabled">
                <i class="bi bi-chevron-right"></i>
            </button>
        @else
            <span class="pag-item desabilitado" aria-disabled="true" aria-label="Próxima página">
                <i class="bi bi-chevron-right"></i>
            </span>
        @endif

        <span class="pag-resumo">
            {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}
        </span>
    </nav>
@endif
