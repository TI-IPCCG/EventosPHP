{{--
    Paginação do Emerald Archive.

    O projeto não tem build de CSS, então as views que o Laravel publica
    (tailwind, bootstrap) não servem: elas dependem de classes utilitárias
    que não existem aqui. Esta é a view padrão do app, registrada em
    AppServiceProvider — `{{ $lista->links() }}` funciona em qualquer tela.

    `onEachSide(1)` mantém a barra estreita o bastante para caber em 390px
    sem quebrar, que é a largura da mesa.
--}}
@if ($paginator->hasPages())
    <nav class="paginacao" role="navigation" aria-label="Paginação">
        @if ($paginator->onFirstPage())
            <span class="pag-item desabilitado" aria-disabled="true">
                <i class="bi bi-chevron-left"></i>
            </span>
        @else
            <button type="button" class="pag-item" wire:click="previousPage" rel="prev"
                    aria-label="Página anterior">
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
                        <button type="button" class="pag-item" wire:click="gotoPage({{ $page }})">
                            {{ $page }}
                        </button>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <button type="button" class="pag-item" wire:click="nextPage" rel="next"
                    aria-label="Próxima página">
                <i class="bi bi-chevron-right"></i>
            </button>
        @else
            <span class="pag-item desabilitado" aria-disabled="true">
                <i class="bi bi-chevron-right"></i>
            </span>
        @endif

        <span class="pag-resumo">
            {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}
        </span>
    </nav>
@endif
