/* ── Feedback de navegação e envio ───────────────────────
   Mostra uma barra de progresso no topo e marca o botão como
   "ocupado" assim que o usuário dispara uma ação, evitando a
   sensação de travamento e os cliques repetidos. ES5 para
   compatibilidade com navegadores de tablets mais antigos.       */
(function () {
  var bar = document.createElement('div');
  bar.className = 'route-progress';
  document.addEventListener('DOMContentLoaded', function () {
    document.body.appendChild(bar);
  });

  var running = false;

  function start() {
    if (running) return;
    running = true;
    if (!bar.parentNode && document.body) document.body.appendChild(bar);
    bar.classList.remove('done');
    // reflow para reiniciar a animação
    void bar.offsetWidth;
    bar.classList.add('active');
    bar.style.width = '92%';
  }

  function markBusy(btn) {
    if (btn && btn.classList) btn.classList.add('is-busy');
  }

  // Envio de formulário: barra + botão ocupado + trava contra duplo envio
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form) return;
    if (form.__submitted) { e.preventDefault(); return; }

    // Respeita confirm() que tenha cancelado o envio
    if (e.defaultPrevented) return;

    form.__submitted = true;
    var btn = form.querySelector('button[type="submit"], button:not([type]), .btn[type="submit"]');
    markBusy(btn);
    start();
  }, false); // bubble: roda depois de onsubmit="return confirm(...)"

  // Navegação por link (mesma origem, sem nova aba/âncora)
  document.addEventListener('click', function (e) {
    var a = e.target;
    while (a && a.tagName !== 'A') a = a.parentNode;
    if (!a || !a.getAttribute) return;

    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#') return;
    if (a.target === '_blank') return;
    if (a.hasAttribute('download')) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    if (href.indexOf('mailto:') === 0 || href.indexOf('tel:') === 0 || href.indexOf('javascript:') === 0) return;

    start();
  }, true);

  function finish() {
    running = false;
    bar.classList.remove('active');
    bar.classList.add('done');
  }

  // Quando a nova página é restaurada do cache (botão voltar), limpa tudo
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      running = false;
      bar.classList.remove('active');
      bar.classList.remove('done');
      bar.style.width = '0';
      var busy = document.querySelectorAll('.is-busy');
      for (var i = 0; i < busy.length; i++) busy[i].classList.remove('is-busy');
      var forms = document.querySelectorAll('form');
      for (var j = 0; j < forms.length; j++) forms[j].__submitted = false;
    }
  });

  window.addEventListener('beforeunload', finish);
})();
