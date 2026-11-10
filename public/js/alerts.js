/**
 * Pop-up de avisos da Gestão de Demandas (canto inferior direito, com efeito sonoro).
 * Consulta front/alerts.php a cada poucos segundos (o intervalo vem das configurações) e mostra os avisos
 * novos: "demanda atribuída a você", aprovação pedida etc. O som é gerado pelo navegador (Web Audio),
 * sem arquivo. Navegadores só liberam o som depois do primeiro clique na página; se o aviso chegar antes
 * disso, o pop-up aparece e o som toca no primeiro toque. O botão do sino silencia (fica salvo no navegador).
 */
(() => {
    if (window.__mktAlerts) return;
    window.__mktAlerts = true;

    const root = (window.CFG_GLPI && window.CFG_GLPI.root_doc) || '';
    const endpoint = `${root}/plugins/marketing/front/alerts.php`;
    const MUTE_KEY = 'mkt_alerts_mute';
    let interval = 20;
    let soundOn = true;
    let audio = null;
    let pendingChime = false;
    let timer = null;
    let stopped = false;
    let box = null;

    const isMuted = () => { try { return localStorage.getItem(MUTE_KEY) === '1'; } catch (e) { return false; } };
    const setMuted = (v) => { try { localStorage.setItem(MUTE_KEY, v ? '1' : '0'); } catch (e) { /* sem armazenamento */ } };

    function ensureAudio() {
        try {
            audio = audio || new (window.AudioContext || window.webkitAudioContext)();
            if (audio.state === 'suspended') audio.resume();
            if (pendingChime && audio.state === 'running') { pendingChime = false; chime(); }
        } catch (e) { /* sem áudio */ }
    }
    ['pointerdown', 'keydown', 'touchstart'].forEach((ev) => document.addEventListener(ev, ensureAudio, {passive: true}));

    /** Dois tons curtos (lá e mi agudo), suaves. */
    function chime() {
        if (!soundOn || isMuted()) return;
        if (!audio || audio.state !== 'running') { pendingChime = true; return; }
        const t = audio.currentTime;
        [[880, 0], [1320, 0.16]].forEach(([freq, delay]) => {
            const osc = audio.createOscillator();
            const gain = audio.createGain();
            osc.type = 'sine';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.0001, t + delay);
            gain.gain.exponentialRampToValueAtTime(0.22, t + delay + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, t + delay + 0.5);
            osc.connect(gain).connect(audio.destination);
            osc.start(t + delay);
            osc.stop(t + delay + 0.55);
        });
    }

    function container() {
        if (box) return box;
        box = document.createElement('div');
        box.className = 'mkt-toasts';
        box.setAttribute('role', 'region');
        box.setAttribute('aria-label', 'Avisos da Gestão de Demandas');
        document.body.appendChild(box);
        return box;
    }

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    }

    function toast(item) {
        const el = document.createElement('div');
        el.className = 'mkt-toast';
        el.setAttribute('role', 'alert');
        el.innerHTML = `<div class="mkt-toast-icon"><i class="ti ti-bell-ringing"></i></div>
            <div class="mkt-toast-body"><div class="mkt-toast-title">${esc(item.title)}</div>
            <div class="mkt-toast-msg">${esc(item.message)}</div>
            ${item.url ? `<a class="mkt-toast-link" href="${esc(item.url)}">Abrir demanda</a>` : ''}</div>
            <div class="mkt-toast-tools">
              <button type="button" class="mkt-toast-mute" title="${isMuted() ? 'Ativar som' : 'Silenciar'}" aria-label="Alternar som"><i class="ti ${isMuted() ? 'ti-bell-off' : 'ti-bell'}"></i></button>
              <button type="button" class="mkt-toast-close" aria-label="Fechar"><i class="ti ti-x"></i></button>
            </div>`;
        const close = () => { el.classList.add('out'); setTimeout(() => el.remove(), 250); };
        el.querySelector('.mkt-toast-close').addEventListener('click', close);
        el.querySelector('.mkt-toast-mute').addEventListener('click', (ev) => {
            const now = !isMuted();
            setMuted(now);
            ev.currentTarget.innerHTML = `<i class="ti ${now ? 'ti-bell-off' : 'ti-bell'}"></i>`;
            ev.currentTarget.title = now ? 'Ativar som' : 'Silenciar';
        });
        container().appendChild(el);
        requestAnimationFrame(() => el.classList.add('in'));
        setTimeout(close, 12000);
        while (box.children.length > 4) box.firstElementChild.remove();
    }

    async function poll() {
        if (stopped || document.hidden) return schedule();
        try {
            const res = await fetch(endpoint, {credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
            if (res.status === 401 || res.status === 403) { stopped = true; return; }
            if (!res.ok) return schedule();
            const data = await res.json();
            if (data.disabled) { stopped = true; return; }
            interval = Math.max(5, Number(data.interval) || interval);
            soundOn = data.sound !== false;
            if (Array.isArray(data.items) && data.items.length) {
                data.items.forEach(toast);
                chime();
            }
        } catch (e) { /* rede indisponível: tenta de novo no próximo ciclo */ }
        schedule();
    }

    function schedule() {
        clearTimeout(timer);
        if (!stopped) timer = setTimeout(poll, interval * 1000);
    }

    document.addEventListener('visibilitychange', () => { if (!document.hidden && !stopped) { clearTimeout(timer); poll(); } });
    // primeira consulta logo depois do carregamento da página
    timer = setTimeout(poll, 2500);

    window.MktAlerts = {test: () => { toast({title: 'Nova demanda atribuída a você', message: 'MKT-00000 · Exemplo de aviso', url: ''}); chime(); }};
    // botão "Mostrar aviso de teste" das configurações (sem atributo inline, por causa da CSP do GLPI)
    document.addEventListener('click', (ev) => {
        if (ev.target.closest('[data-mkt-test]')) { ensureAudio(); window.MktAlerts.test(); }
    });
})();
