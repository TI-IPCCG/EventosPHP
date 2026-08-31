{{--
    Pilha de notificações. Reaproveita o visual do .alert do design system.

    Qualquer componente Livewire avisa assim:
        $this->dispatch('toast', tipo: 'ok', mensagem: 'Item salvo.');
        $this->dispatch('toast', tipo: 'erro', titulo: 'Não deu', mensagem: '…');

    Tipos: ok · info · aviso · erro

    Por que toast e não session flash: o flash só aparece na PRÓXIMA renderização
    de página. Numa tela Livewire, onde tudo acontece sem recarregar, ele some
    ou chega atrasado — foi o que deixou a venda sem confirmação nenhuma.

    Acessibilidade: aria-live="polite" para sucesso, "assertive" para erro; o
    tempo na tela é maior no erro, porque exige leitura. Quem prefere menos
    animação (prefers-reduced-motion) recebe sem transição.
--}}
<div class="toasts"
     x-data="{
        itens: [],
        add(d) {
            const id = Date.now() + Math.random();
            const tipo = d.tipo || 'ok';
            this.itens.push({ id, tipo, titulo: d.titulo || '', mensagem: d.mensagem || '' });

            // Erro fica mais tempo: exige leitura, e às vezes ação.
            const ms = (tipo === 'erro' || tipo === 'aviso') ? 8000 : 4000;
            setTimeout(() => this.fechar(id), ms);
        },
        fechar(id) { this.itens = this.itens.filter(i => i.id !== id) },
        classe(t)  { return { ok: 'ok', info: 'info', aviso: 'warn', erro: 'danger' }[t] || 'ok' },
        icone(t)   { return { ok: 'bi-check-circle', info: 'bi-info-circle',
                              aviso: 'bi-exclamation-triangle', erro: 'bi-x-circle' }[t] || 'bi-check-circle' },
     }"
     @toast.window="add($event.detail)">

    <template x-for="item in itens" :key="item.id">
        <div class="toast alert" :class="classe(item.tipo)"
             :role="item.tipo === 'erro' ? 'alert' : 'status'"
             :aria-live="item.tipo === 'erro' ? 'assertive' : 'polite'"
             x-transition:enter="toast-entrando"
             x-transition:leave="toast-saindo">

            <span class="alert-icon" aria-hidden="true"><i class="bi" :class="icone(item.tipo)"></i></span>

            <div class="alert-content">
                <div class="alert-title" x-show="item.titulo" x-text="item.titulo"></div>
                <div class="alert-message" x-text="item.mensagem"></div>
            </div>

            <button type="button" class="toast-fechar" @click="fechar(item.id)" aria-label="Fechar aviso">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </template>
</div>
