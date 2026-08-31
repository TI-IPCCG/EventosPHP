# Emerald Archive — Design System

Sistema de design do app de Votação IPCCG, documentado para **reúso em outros aplicativos**.
Tom sóbrio e institucional: claro (light mode), tipografia serifada para títulos, verde-floresta
e bronze como cores de marca.

> **Princípio central:** é um design system de **CSS puro com custom properties** — zero build,
> zero dependência de framework. Você copia um arquivo `.css`, referencia os tokens, e usa.
> Isso se alinha perfeitamente à filosofia **PET/TALL** (PHP de ciclo curto + HTML renderizado no
> servidor + JS leve), sem `npm run build`, sem bundler, sem Tailwind a compilar.

---

## Sumário

1. [Filosofia e stack](#1-filosofia-e-stack)
2. [Tokens de design](#2-tokens-de-design)
3. [Cores](#3-cores)
4. [Tipografia](#4-tipografia)
5. [Raios, sombras e espaçamento](#5-raios-sombras-e-espaçamento)
6. [Componentes](#6-componentes)
7. [Padrões de UX](#7-padrões-de-ux)
8. [Acessibilidade](#8-acessibilidade)
9. [Como reutilizar em outro app](#9-como-reutilizar-em-outro-app)

---

## 1. Filosofia e stack

O design system foi pensado para rodar em **hospedagem compartilhada (cPanel)** com a stack
PET/TALL, sem etapa de build:

| Camada | Tecnologia | Papel |
|---|---|---|
| Servidor | **PHP de ciclo curto** | Regras de negócio, SQL via PDO, renderiza HTML e morre (libera RAM) |
| Estilo | **CSS com custom properties** | Tokens em `:root`, componentes em classes — sem Tailwind/build |
| Comportamento | **JS leve, vanilla** (ou HTMX/Alpine) | Só o necessário no cliente; o estado mora no banco/DOM |
| Ícones | **SVG inline** ou Bootstrap Icons via CDN | SVG inline não depende de fonte externa |
| Fontes | Google Fonts (Merriweather + Inter) | Carregadas via `<link>` no `<head>` |

**Por que CSS puro e não Tailwind?** Tailwind exige um passo de compilação (PostCSS/CLI). Em um
fluxo "edita e sobe pro cPanel", custom properties entregam o mesmo poder de tema (uma variável,
mil usos) **sem build**. Se um projeto futuro adotar HTMX/Alpine, os tokens e componentes
continuam valendo — o CSS é agnóstico de stack.

**Regra de ouro de layout:** todo elemento visual deve ser validado em **mobile e
desktop/tablet**. Dar dimensão fixa/limitada a ícones (`width`/`height` no SVG + `max-width`),
nunca `width: 100%` em SVG sem `viewBox` controlado.

---

## 2. Tokens de design

Cole este bloco no topo do seu `app.css`. Tudo no sistema deriva daqui.

```css
:root {
  /* ── Superfícies e texto ── */
  --bg:          #F7F7F6;   /* fundo da página */
  --surface:     #FFFFFF;   /* cards, inputs, áreas elevadas */
  --fg:          #062F3B;   /* texto principal (teal profundo) */
  --muted:       #EBECE9;   /* fundo sutil (linhas hover, caixas) */
  --muted-fg:    #5A7161;   /* texto secundário */
  --border:      #D9DBD6;   /* bordas e divisores */

  /* ── Marca ── */
  --primary:     #334C3B;   /* verde-floresta — ações primárias */
  --primary-fg:  #F7F7F6;   /* texto sobre primary */
  --primary-dim: rgba(51,76,59,.09);

  --accent:      #BD7522;   /* bronze — destaque, foco, "eleito" */
  --accent-fg:   #FFFFFF;
  --accent-dim:  rgba(189,117,34,.10);
  --accent-hover:#A2641D;

  /* ── Navegação (sidebar) ── */
  --sidebar-bg:   #062F3B;
  --sidebar-fg:   #E6E7E3;
  --sidebar-hover:#163A44;
  --sidebar-w:    260px;

  /* ── Semânticas (estado, NÃO é o accent) ── */
  --ok:         #667F6E;  --ok-dim:     rgba(102,127,110,.12);
  --warn:       #BD7522;  --warn-dim:   rgba(189,117,34,.12);
  --danger:     #DC2828;  --danger-dim: rgba(220,40,40,.10);
  --info:       #2D7DA0;  /* azul calmo — avisos neutros */

  /* ── Forma ── */
  --radius:      10px;
  --radius-sm:   6px;
  --radius-lg:   14px;
  --shadow-card: 0 1px 2px rgba(6,47,59,.05), 0 1px 3px rgba(6,47,59,.04);
  --shadow-hover:0 6px 16px rgba(6,47,59,.09);
}
```

> **Cor semântica ≠ cor de marca.** `--ok/--warn/--danger/--info` comunicam *estado* e são
> independentes do accent (bronze). Não use o bronze para "sucesso" nem o verde-floresta para
> "erro".

---

## 3. Cores

| Token | Hex | Uso |
|---|---|---|
| `--bg` | `#F7F7F6` | Fundo geral da aplicação |
| `--surface` | `#FFFFFF` | Cards, inputs, modais |
| `--fg` | `#062F3B` | Texto principal (teal quase-preto) |
| `--muted` | `#EBECE9` | Fundo de hover, caixas internas |
| `--muted-fg` | `#5A7161` | Texto secundário, legendas |
| `--border` | `#D9DBD6` | Bordas, divisores |
| `--primary` | `#334C3B` | Botões primários, links, ativo |
| `--accent` | `#BD7522` | Foco, destaque, indicador de ordenação, "eleito" |
| `--sidebar-bg` | `#062F3B` | Fundo da navegação lateral / topbar |
| `--ok` | `#667F6E` | Sucesso / aberto |
| `--warn` | `#BD7522` | Atenção / rascunho / congelada |
| `--danger` | `#DC2828` | Erro / exclusão |
| `--info` | `#2D7DA0` | Aviso neutro / "em pausa" |

**Neutros com viés:** os cinzas têm leve viés esverdeado (`--muted-fg #5A7161`), combinando com
a marca em vez de um cinza puro "sem escolha".

**Contraste (acessibilidade):** sobre fundos claros, prefira os tons escurecidos para texto —
ex.: erro `#8c1818`, atenção `#6e440f`, sucesso `#234433` — que passam em WCAG AA (ver
[Notificações](#notificações)).

---

## 4. Tipografia

Duas famílias via Google Fonts, carregadas no `<head>`:

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@400;700;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
```

| Papel | Família | Onde |
|---|---|---|
| **Títulos** | `Merriweather` (serif, 700/900) | `h1`–`h3`, títulos de card, KPIs, marca |
| **Corpo / UI** | `Inter` (sans, 300–700) | Texto, botões, formulários, tabelas |
| **Dados/código** | mono do sistema | Trechos de código, tokens |

```css
body {
  font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  font-size: .95rem;
  line-height: 1.6;
  color: var(--fg);
  background: var(--bg);
}
h1, h2, h3 { font-family: 'Merriweather', Georgia, serif; }
```

**Escala de tipo (use e não saia dela):**

| Elemento | Tamanho | Peso |
|---|---|---|
| `h1` / título de página | `1.5rem` | 700 |
| KPI / stat value | `1.75rem` | 700 (Merriweather) |
| `h2` / título de card | `1rem`–`1.125rem` | 700 |
| Corpo | `.95rem` | 400/500 |
| Legenda / `.muted` | `.875rem` | 400 |
| Rótulo / label | `.8rem` | 600, `text-transform: uppercase`, `letter-spacing:.04em` |

> **Mínimo 16px em inputs no mobile.** Campos de busca/formulário usam `font-size: 16px` em
> telas pequenas para evitar o auto-zoom do iOS.

---

## 5. Raios, sombras e espaçamento

```css
--radius-sm: 6px;   /* botões, inputs, pills internas */
--radius:    10px;  /* caixas, linhas de lista */
--radius-lg: 14px;  /* cards, stat-cards, auth-card */

--shadow-card:  0 1px 2px rgba(6,47,59,.05), 0 1px 3px rgba(6,47,59,.04);
--shadow-hover: 0 6px 16px rgba(6,47,59,.09);
```

- **Sombras** sempre com matiz teal (`rgba(6,47,59,...)`), nunca preto puro — integra com a paleta.
- **Espaçamento:** prefira `gap` em flex/grid a margens por elemento. Padding de card: `22px`
  (desktop) → `14px` (mobile). Margem entre cards: `16–18px`.

---

## 6. Componentes

Cada componente abaixo é uma classe pronta. Marcação mínima, sem utilitários soltos.

### Botões

```html
<button>Ação primária</button>                      <!-- verde-floresta -->
<button class="secondary">Secundária</button>        <!-- contorno -->
<a class="btn btn-accent">Destaque</a>               <!-- bronze -->
<a class="btn btn-ghost">Fantasma</a>                <!-- outline primary -->
<button class="btn-danger">Excluir</button>          <!-- vermelho suave -->
<button class="btn-sm">Pequeno</button>              <!-- compacto -->
```

- O elemento `<button>` já é primário por padrão. `.btn` em `<a>` replica o estilo.
- Variantes combinam com `.btn-sm` para densidade (ex.: `btn-danger btn-sm secondary`).
- Estado **ocupado** (durante envio): classe `.is-busy` troca o rótulo por um spinner e bloqueia
  novo clique (ver [Feedback de carregamento](#feedback-de-carregamento)).

### Cards

```html
<div class="card">
  <div class="card-header">
    <h2 class="card-title">Título da seção</h2>
    <span class="pill">12</span>
  </div>
  <!-- conteúdo -->
</div>
```

Container base de conteúdo: `--surface`, borda `--border`, `--radius-lg`, `--shadow-card`.
Variações internas: `.box` (caixa sutil) e `.box.accent` (destaque bronze).

### Pills e badges

```html
<span class="pill">Neutro</span>
<span class="pill open">Aberta</span>      <!-- verde (ok) -->
<span class="pill draft">Congelada</span>  <!-- bronze (warn) -->
<span class="pill closed">Encerrada</span> <!-- cinza -->
```

Pills são rótulos de estado: uppercase, `border-radius: 999px`, `.75rem`, peso 700. As cores
seguem as semânticas (`ok/warn/danger/info`), nunca o accent decorativo.

### Notificações

Componente unificado e acessível (`partials/_flash.php`). Ícone SVG **inline** (não depende de
fonte de ícones), título em negrito + mensagem, texto ≥16px, contraste reforçado.

```html
<div class="alert ok" role="alert" aria-live="assertive">
  <span class="alert-icon" aria-hidden="true"><!-- SVG 24x24 --></span>
  <div class="alert-content">
    <div class="alert-title">Tudo certo</div>
    <div class="alert-message">Cadastro realizado com sucesso.</div>
  </div>
</div>
```

```css
.alert {
  display: flex; align-items: flex-start; gap: 13px;
  padding: 16px 18px; border-radius: var(--radius);
  font-size: 1.05rem; line-height: 1.5; font-weight: 500;
  border: 1px solid; border-left-width: 5px;
  box-shadow: var(--shadow-card);
}
.alert-icon { flex-shrink: 0; width: 24px; height: 24px; line-height: 0; }
.alert-icon svg { width: 24px; height: 24px; max-width: 24px; max-height: 24px; display: block; }
.alert-title { font-weight: 800; font-size: 1.08rem; }

/* tons com contraste AA */
.alert.ok     { background: var(--ok-dim);     border-color: rgba(102,127,110,.5); color: #234433 }
.alert.warn   { background: var(--warn-dim);   border-color: rgba(189,117,34,.5);  color: #6e440f }
.alert.danger { background: var(--danger-dim); border-color: rgba(220,40,40,.45);  color: #8c1818 }
.alert.info   { background: rgba(45,125,160,.10); border-color: rgba(45,125,160,.45); color: #1f5c77 }
```

> **Cuidado clássico (já corrigido aqui):** SVG de ícone **deve** ter `width`/`height` fixos
> (atributo + CSS). `width:100%` sem dimensão intrínseca estoura o ícone em alguns navegadores de
> tablet/desktop e gera rolagem na página.

**Mapa tipo → estilo:** `success`→ok "Tudo certo" · `warning`→warn "Atenção" ·
`error/danger`→danger "Erro" · `info`→info "Aviso".

**Página de erro:** exceções de regra de negócio renderizam uma **página dedicada** (card
centralizado com o mesmo alerta + botões "Voltar" / "Ir para o início"), nunca texto cru.
Requisições que aceitam JSON recebem `{ "error": "..." }`.

### Tabelas + busca/ordenação

```html
<div class="table-wrap">
  <table data-tools data-search-placeholder="Buscar membro…">
    <thead>
      <tr>
        <th>Nome</th>
        <th data-sort-type="date">Cadastro</th>
        <th data-no-sort></th>
      </tr>
    </thead>
    <tbody> ... </tbody>
  </table>
</div>
```

- `.table-wrap` dá `overflow-x: auto` — tabelas largas rolam horizontalmente no mobile em vez de
  espremer.
- `data-tools` ativa o componente `table-tools.js`: injeta caixa de busca (com contador) e torna
  cabeçalhos clicáveis para ordenar.
- `data-sort-type="num|date"` no `<th>` define ordenação numérica ou por data `DD/MM/AAAA`.
- `data-no-sort` exclui a coluna (ex.: ações). A busca ignora colunas de ação automaticamente.
- Tudo **client-side** — filtragem instantânea, sem requisição (ideal para latência de cPanel).

### Formulários

```html
<form class="form">
  <div class="form-row">
    <div>
      <label>Nome completo</label>
      <input name="name" required>
    </div>
    <div>
      <label>CPF</label>
      <input name="cpf" inputmode="numeric" placeholder="000.000.000-00">
    </div>
  </div>
  <button type="submit">Salvar</button>
</form>
```

- `.form` empilha campos com `gap`; `.form-row` distribui em colunas que quebram no mobile
  (`flex-wrap`, `min-width: 140px`).
- `label` é uppercase, `.8rem`, peso 600.
- Foco com anel: `box-shadow: 0 0 0 3px rgba(51,76,59,.12)` + borda `--primary`.

### Stat cards (KPIs)

```html
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-label">Total</div>
    <div class="stat-value">128</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Em andamento</div>
    <div class="stat-value ok">3</div>
  </div>
</div>
```

Grid responsivo automático (`repeat(auto-fill, minmax(160px, 1fr))`). Valor em Merriweather
`1.75rem`. Modificadores `.ok` / `.accent` colorem o número.

### Avatar, empty state

```html
<img class="avatar" src="...">          <!-- 34px, circular, borda -->
<div class="avatar">SF</div>            <!-- fallback com iniciais -->

<div class="empty-state">Nenhum registro encontrado.</div>
```

### Navegação lateral (sidebar)

Estrutura escura (`--sidebar-bg`) fixa em desktop; vira **drawer** no mobile (ver
[Responsividade](#responsividade)).

```html
<aside class="sidebar" id="sidebar">
  <a class="sidebar-brand" href="#"><div class="sidebar-brand-title">IPCCG</div></a>
  <nav class="sidebar-nav">
    <a class="nav-item active"><i class="nav-icon">…</i><span>Eleições</span></a>
    <a class="nav-item"><i class="nav-icon">…</i><span>Membros</span>
      <span class="nav-badge">3</span></a>
  </nav>
  <div class="sidebar-footer"><button class="sidebar-logout">Sair</button></div>
</aside>
```

- Item ativo: fundo `--primary` + borda esquerda `--accent`.
- `.nav-badge`: contador vermelho (ex.: aprovações pendentes).

### Auth card (login / cadastro)

```html
<div class="auth-page">
  <div class="auth-card">
    <div class="auth-header">
      <h1 class="auth-brand-title">IPCCG</h1>
      <div class="auth-brand-sub">Sistema de Votação</div>
    </div>
    <div class="auth-body"> ...formulário... </div>
  </div>
</div>
```

Card centralizado, `max-width: 420px`, cabeçalho escuro com a marca. Usado em login, cadastro e
na página de erro/aviso.

---

## 7. Padrões de UX

### Responsividade

- **Breakpoint principal:** `768px`. Abaixo dele a sidebar some e vira drawer deslizante com
  topbar fixa (botão ☰) + overlay escuro; fecha ao clicar fora ou navegar.
- **Breakpoint compacto:** `560px` para densidade de KPIs e painel.
- Tabelas largas rolam na horizontal; busca em largura total; inputs a 16px.
- **Sempre validar nos dois viewports antes de concluir** (regra de ouro).

```css
@media (max-width: 768px) {
  .sidebar { transform: translateX(-100%); transition: transform .25s; }
  .sidebar.open { transform: translateX(0); }
  .content-area { margin-left: 0; padding: 72px 16px 32px; }
}
```

### Feedback de carregamento

`ui.js` (vanilla, ES5) dá retorno imediato a cada ação — essencial em hospedagem com latência:

- **Barra de progresso** fina no topo ao navegar/enviar (`.route-progress`, bronze).
- **Botão ocupado**: ao enviar um form, o botão vira spinner (`.is-busy`) e trava o duplo clique.
- Respeita `confirm()`: se o usuário cancelar, o botão não fica travado (handler em fase *bubble*).

### Confirmação de ação

Ações relevantes não terminam em silêncio. Ex.: após votar, uma **tela de confirmação**
("Voto registrado com sucesso!") com ícone animado e **contagem regressiva** redireciona
suavemente — em vez de pular direto para a próxima tela sem aviso.

### Estados e cores semânticas

Codifique estado na **forma além da cor** (pill + texto), nunca só cor:

| Estado | Pill | Cor |
|---|---|---|
| Aberta / sucesso | `pill open` | `--ok` |
| Em pausa / aviso | `pill draft` / `badge info` | `--warn` / `--info` |
| Encerrada | `pill closed` | cinza |
| Erro | `alert danger` | `--danger` |

> No painel **público**, estados de transição (ex.: "Em pausa") usam o **azul-claro `--info`**,
> não vermelho — evita parecer erro quando é só uma pausa momentânea.

---

## 8. Acessibilidade

- **Notificações:** `role="alert"` + `aria-live="assertive"`; ícone decorativo com
  `aria-hidden="true"`.
- **Contraste:** texto sobre fundos claros usa os tons escurecidos (AA). Evite texto em cor de
  *fundo* (ex.: usar `--muted` — cinza claro — como cor de texto deixa ilegível; use `--muted-fg`).
- **Alvos de toque:** botões com padding generoso; inputs a 16px no mobile (sem auto-zoom).
- **Foco visível:** anel de foco em inputs e estados de teclado preservados.
- **Ícones:** SVG inline com `width`/`height` fixos — funcionam mesmo sem fonte de ícones e não
  estouram o layout.

---

## 9. Como reutilizar em outro app

1. **Copie o CSS base** (`public/assets/app.css`) — ele traz os tokens (`:root`) e todos os
   componentes. É o único arquivo de estilo necessário.
2. **Carregue as fontes** no `<head>` (Merriweather + Inter via Google Fonts) e o `app.css`.
3. **Use as classes** documentadas aqui. Não invente utilitários soltos; estenda via novos
   componentes no `app.css` reaproveitando os tokens.
4. **Para tema diferente** (outra instituição), altere **só o `:root`** — troque `--primary`,
   `--accent`, `--sidebar-bg`. Todos os componentes acompanham, sem tocar em marcação.
5. **JS opcional e leve:** copie `ui.js` (feedback de carregamento) e `table-tools.js`
   (busca/ordenação) se precisar — são vanilla, sem dependências.
6. **Notificações:** reaproveite o partial `_flash.php` (ou replique a estrutura `.alert` com
   ícone SVG inline) para manter consistência e acessibilidade.
7. **Ícones:** Bootstrap Icons via CDN nas telas internas; **SVG inline** onde a fonte pode não
   carregar (telas públicas, e-mail, página de erro).

### Checklist de conformidade

- [ ] Tokens em `:root` presentes e usados (sem hex soltos no meio do código)
- [ ] Títulos em Merriweather, corpo em Inter
- [ ] Cores semânticas separadas do accent
- [ ] Validado em mobile **e** desktop/tablet
- [ ] Ícones SVG com dimensão fixa
- [ ] Notificações com `role="alert"` e contraste AA
- [ ] Nenhuma etapa de build necessária (CSS puro)

---

*Emerald Archive — sistema de design do app de Votação IPCCG. Mantido junto ao código; ao evoluir
um componente, atualize esta página.*

---

## 10. Adendo — uso no Escala de Membros (Laravel/Livewire)

> Este design system foi originalmente descrito para PHP puro. No **Escala de
> Membros** ele é aplicado **dentro de views Blade + Livewire 4**, mas continua
> **CSS puro sem build**: o `public/assets/app.css` é carregado no layout e as
> classes são usadas direto na marcação Blade. Nada de Tailwind/compilação.

### Componentes adicionados (não existiam no doc original)

**Selo de vaga aberta** (pisca; respeita `prefers-reduced-motion`):
```html
<span class="pill vaga">Vaga Aberta</span>
```

**Chip de slot** (item compacto numa linha de escala/equipe):
```html
<span class="slot-chip">João <span class="pill draft">Pendente</span></span>
```

**Modal** (usado na alocação/troca; Livewire controla o estado, sem lib de JS):
```html
<div class="modal-overlay" wire:click.self="fechar">
  <div class="modal-card">
    <div class="modal-header"> … <button class="btn-sm secondary">✕</button></div>
    <div class="modal-body"> … <button class="btn-ghost modal-list-item">Opção</button> </div>
  </div>
</div>
```

**Erro de campo** (validação em formulários):
```html
<input …>
@error('campo') <span class="field-error">{{ $message }}</span> @enderror
```

**Central de Ajuda** (accordion em HTML puro com `<details>`, zero JS):
```html
<div class="help-intro"> … <div class="help-chain">
  <span class="step">1. Perfis</span><span class="arrow">→</span> … </div></div>

<details class="help-item">
  <summary>Título do tópico</summary>
  <div class="help-body"> <p>…</p> <div class="tip">💡 dica</div> </div>
</details>
```

### Padrões de feedback com Livewire
- Estado ocupado de botão: `wire:loading.attr="disabled" wire:target="metodo"`
  (substitui o `.is-busy` do `ui.js`, já que o Livewire usa AJAX, não submit
  nativo).
- Confirmação de ação destrutiva: `wire:confirm="Tem certeza?"`.

### Regra de ouro que continua valendo
Validar sempre em **mobile e desktop**. Tabelas largas (matriz de competências,
escalas) usam `.table-wrap` (rola na horizontal no mobile).
