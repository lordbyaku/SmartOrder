/* =====================================================================
 * GAHC Smart Outlet Ordering — Staff Monitor PWA & CMS (staff.html)
 * Vanilla JS, tanpa dependency (QR generator dimuat dari CDN hanya di tab QR).
 * ===================================================================== */
(function () {
  'use strict';

  var OUTLETS = ['Pandan Coffee Shop', 'Kemangi Lobby Lounge', 'Limaran Sky Lounge', 'Room Service'];
  var CATEGORIES = ['Signature', 'Makanan Utama', 'Minuman', 'Camilan & Pastry'];
  var POLL_MS = 5000;
  var STATUS_LABEL = { NEW: 'Input VHP!', VHP_INPUTTED: 'Diproses VHP', COMPLETED: 'Selesai', CANCELLED: 'Dibatalkan' };
  var QR_LIBS = [
    'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js',
    'https://unpkg.com/qrcode-generator@1.4.4/qrcode.js',
  ];

  /* ------------------------------ utils ----------------------------- */
  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"'`]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' }[c];
    });
  }
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function fmt(n) { return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(Number(n) || 0)); }
  function num(n) { return new Intl.NumberFormat('id-ID').format(Number(n) || 0); }
  function lsGet(k, d) { try { var v = localStorage.getItem(k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } }
  function lsSet(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* */ } }
  function hm(s) { var m = String(s || '').match(/(\d{2}):(\d{2})/); return m ? m[1] + ':' + m[2] : ''; }
  function dmy(s) { var m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? m[3] + '/' + m[2] + '/' + m[1] : ''; }
  function parseLocal(s) { var m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):?(\d{2})?/); return m ? new Date(+m[1], m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)) : null; }
  function ago(created, serverNow) {
    var a = parseLocal(created), b = parseLocal(serverNow) || new Date();
    if (!a) return '';
    var min = Math.max(0, Math.round((b - a) / 60000));
    if (min < 1) return 'baru saja';
    if (min < 60) return min + ' mnt lalu';
    var h = Math.floor(min / 60);
    return h < 24 ? h + ' jam lalu' : Math.floor(h / 24) + ' hari lalu';
  }
  function todayStr() { var d = new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function isIOS() { return /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1); }
  function isStandalone() { return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true; }

  /* ------------------------------ state ----------------------------- */
  var state = {
    user: null, csrf: '', tab: lsGet('gahc_staff_tab', 'monitor'),
    dash: { orders: [], kpi: null, maxId: 0, lastId: 0, outlet: lsGet('gahc_dash_outlet', 'ALL'), status: 'ACTIVE', date: '', serverTime: '' },
    alertQueue: [], alertedIds: {},
    soundOn: false, audioCtx: null,
    deferredPrompt: null, pushOn: false, swReg: null,
    menus: [], menuFilter: { q: '', outlet: 'ALL', cat: 'ALL' },
    outletCfg: [], qr: null, qrBase: '', qrOutlet: OUTLETS[0], qrTent: true,
    users: [],
    report: { from: todayStr(), to: todayStr(), outlet: 'ALL', status: 'ALL', q: '', data: null },
    pollTimer: null, polling: false, online: true,
  };
  var pendingOpenOrder = new URLSearchParams(location.search).get('open_order');

  /* ------------------------------ API ------------------------------- */
  function api(url, opts) {
    opts = opts || {};
    var method = opts.method || 'GET';
    var init = { method: method === 'GET' ? 'GET' : 'POST', headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' };
    if (method !== 'GET' && method !== 'POST') init.headers['X-HTTP-Method-Override'] = method; // aman dari blokir WAF PATCH/DELETE
    if (method !== 'GET' && state.csrf) init.headers['X-CSRF-Token'] = state.csrf;
    if (opts.form) init.body = opts.form;
    else if (opts.body !== undefined) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
    return fetch(url, init).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (json) {
        if (!json) { var e = new Error('Respon server tidak valid (HTTP ' + res.status + ').'); e.status = res.status; throw e; }
        if (res.status === 401 && url.indexOf('auth_staff') === -1) { onSessionExpired(); }
        if (!json.success) { var e2 = new Error(json.message || 'Terjadi kesalahan.'); e2.status = res.status; e2.data = json.data; throw e2; }
        return json.data;
      });
    }, function () { var e3 = new Error('Koneksi internet terputus.'); e3.status = 0; throw e3; });
  }

  /* ------------------------------ UI helpers ------------------------ */
  function toast(msg, type) {
    var el = document.createElement('div');
    el.className = 'toast ' + (type || '');
    el.textContent = msg;
    $('#toastRoot').appendChild(el);
    setTimeout(function () { el.remove(); }, type === 'err' ? 5000 : 3000);
  }
  function modal(html, opts) {
    opts = opts || {};
    var wrap = document.createElement('div');
    wrap.className = 'modal-backdrop' + (opts.sheet !== false ? ' sheet' : '') + (opts.cls ? ' ' + opts.cls : '');
    wrap.innerHTML = '<div class="modal' + (opts.wide ? ' wide' : '') + '" role="dialog" aria-modal="true">' + html + '</div>';
    if (!opts.locked) wrap.addEventListener('click', function (e) { if (e.target === wrap) closeModal(wrap); });
    $$('[data-close]', wrap).forEach(function (b) { b.addEventListener('click', function () { closeModal(wrap); }); });
    $('#modalRoot').appendChild(wrap);
    document.body.style.overflow = 'hidden';
    var first = $('input:not([type=hidden]),textarea,select', wrap);
    if (first && opts.focus !== false && window.matchMedia('(pointer:fine)').matches) setTimeout(function () { first.focus(); }, 50);
    return wrap;
  }
  function closeModal(w) {
    if (w && w.parentNode) w.parentNode.removeChild(w);
    if (w && w._onClose) w._onClose();
    if (!$('#modalRoot').children.length) document.body.style.overflow = '';
  }
  function busy(btn, on, label) {
    if (!btn) return;
    if (on) { btn._label = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="spinner"></span>' + (label ? ' ' + esc(label) : ''); }
    else { btn.disabled = false; if (btn._label != null) btn.innerHTML = btn._label; }
  }
  function confirmBox(title, text, okLabel, okClass) {
    return new Promise(function (resolve) {
      var w = modal('<div class="modal-head"><h3>' + esc(title) + '</h3></div><div class="modal-body">' + esc(text) + '</div>' +
        '<div class="modal-foot"><button class="btn ghost" data-no>Batal</button><button class="btn ' + (okClass || 'red') + '" data-yes>' + esc(okLabel || 'Ya') + '</button></div>', { sheet: false });
      w._onClose = function () { resolve(false); };
      $('[data-no]', w).onclick = function () { closeModal(w); };
      $('[data-yes]', w).onclick = function () { w._onClose = null; closeModal(w); resolve(true); };
    });
  }
  function outletOptions(selected, withAll, allLabel) {
    return (withAll ? '<option value="ALL"' + (selected === 'ALL' ? ' selected' : '') + '>' + esc(allLabel || 'Semua Outlet (4)') + '</option>' : '') +
      OUTLETS.map(function (o) { return '<option value="' + esc(o) + '"' + (selected === o ? ' selected' : '') + '>' + esc(o) + '</option>'; }).join('');
  }
  function isAdmin() { return state.user && state.user.role === 'admin'; }
  function canMenu() { return state.user && (state.user.role === 'admin' || state.user.role === 'manager'); }
  var ROLE_LABEL = { admin: 'Admin', manager: 'FB SPV/Manager', cashier: 'Kasir' };
  function roleLabel(r) { return ROLE_LABEL[r] || r; }
  function lockedOutlet() { return state.user && state.user.role !== 'admin' && state.user.assigned_outlet !== 'ALL' ? state.user.assigned_outlet : null; }

  /* ------------------------------ Sound ----------------------------- */
  function unlockAudio() {
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return;
      if (!state.audioCtx) state.audioCtx = new AC();
      if (state.audioCtx.state === 'suspended') state.audioCtx.resume();
      if (!state.soundOn) { state.soundOn = true; updateSoundBtn(); }
    } catch (e) { /* */ }
  }
  function chime() {
    var ctx = state.audioCtx;
    if (!ctx || ctx.state !== 'running') return;
    var t0 = ctx.currentTime;
    [[880, 0], [1175, 0.18], [1568, 0.36], [1175, 0.7], [1568, 0.88]].forEach(function (n) {
      var o = ctx.createOscillator(), g = ctx.createGain();
      o.type = 'sine'; o.frequency.value = n[0];
      g.gain.setValueAtTime(0.0001, t0 + n[1]);
      g.gain.exponentialRampToValueAtTime(0.5, t0 + n[1] + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + n[1] + 0.32);
      o.connect(g); g.connect(ctx.destination);
      o.start(t0 + n[1]); o.stop(t0 + n[1] + 0.35);
    });
    if (navigator.vibrate) { try { navigator.vibrate([300, 150, 300, 150, 400]); } catch (e) { /* */ } }
  }
  function updateSoundBtn() {
    var b = $('#soundBtn');
    if (!b) return;
    b.classList.toggle('sound-off', !state.soundOn);
    b.textContent = state.soundOn ? '🔊 Suara ON' : '🔇 Ketuk utk Suara';
  }
  document.addEventListener('pointerdown', unlockAudio, { passive: true });
  document.addEventListener('keydown', unlockAudio);

  /* ------------------------------ Boot / Auth ----------------------- */
  var app = $('#app');

  function boot() {
    registerSW();
    api('api/auth_staff.php').then(function (d) {
      if (d.logged_in) { setUser(d); renderShell(); } else renderLogin();
    }).catch(function (e) {
      app.innerHTML = '<div class="login-wrap"><div class="card login-card center"><p>' + esc(e.message) + '</p><button class="btn" onclick="location.reload()">Muat Ulang</button></div></div>';
    });
  }
  function setUser(d) {
    state.user = d.user; state.csrf = d.csrf;
    var lock = lockedOutlet();
    if (lock) state.dash.outlet = lock;
    var allowed = ['monitor', 'report', 'menu', 'account'].concat(isAdmin() ? ['hours', 'qr', 'users', 'settings'] : []);
    if (allowed.indexOf(state.tab) === -1) state.tab = 'monitor';
  }
  function onSessionExpired() {
    if (!state.user) return;
    state.user = null; stopPolling();
    $('#modalRoot').innerHTML = ''; document.body.style.overflow = '';
    toast('Sesi berakhir, silakan login kembali.', 'err');
    renderLogin();
  }

  function renderLogin() {
    stopPolling();
    app.innerHTML = '<div class="login-wrap"><div class="card login-card">' +
      '<img class="logo" src="assets/icons/icon-192.png" alt="">' +
      '<h2 class="center" style="font-family:var(--serif);color:var(--navy);margin:0">Smart Outlet Ordering</h2>' +
      '<p class="center muted small" style="margin:4px 0 16px">Monitor Kasir & CMS — Grand Artos Hotel &amp; Convention</p>' +
      '<form id="loginForm" class="stack">' +
        '<label class="field"><span>Username</span><input class="input" name="username" autocomplete="username" autocapitalize="none" required></label>' +
        '<label class="field"><span>Password</span><input class="input" name="password" type="password" autocomplete="current-password" required></label>' +
        '<div class="error-text" id="loginErr" hidden></div>' +
        '<button class="btn lg block" type="submit">Masuk</button>' +
      '</form></div></div>';
    $('#loginForm').addEventListener('submit', function (e) {
      e.preventDefault();
      var f = e.target, btn = $('button', f), err = $('#loginErr');
      err.hidden = true; busy(btn, true);
      api('api/auth_staff.php', { method: 'POST', body: { action: 'login', username: f.elements.namedItem('username').value.trim(), password: f.elements.namedItem('password').value } })
        .then(function (d) { setUser(d); renderShell(); })
        .catch(function (ex) { busy(btn, false); err.textContent = ex.message; err.hidden = false; });
    });
  }

  function logout() {
    api('api/auth_staff.php', { method: 'POST', body: { action: 'logout' } }).catch(function () { /* */ }).then(function () {
      state.user = null; stopPolling(); renderLogin();
    });
  }

  /* ------------------------------ Shell ----------------------------- */
  var TABS = [
    { id: 'monitor', label: '📋 Monitor' },
    { id: 'report', label: '📊 Laporan' },
    { id: 'menu', label: '🍽️ Menu' },
    { id: 'hours', label: '🕒 Jam Outlet', admin: true },
    { id: 'qr', label: '🔳 QR Code', admin: true },
    { id: 'users', label: '👥 Pengguna', admin: true },
    { id: 'settings', label: '🛠️ Pengaturan', admin: true },
    { id: 'account', label: '⚙️ Akun' },
  ];

  function renderShell() {
    var u = state.user;
    app.innerHTML =
      '<header class="app-header"><div class="container">' +
        '<div class="bar">' +
          '<div class="brand"><img src="assets/icons/icon-192.png" alt="" width="36" height="36"><div class="brand-text">' +
            '<div class="t1">GAHC Smart Order</div><div class="t2">' + esc(u.full_name) + ' · ' + esc(roleLabel(u.role)) +
            (u.assigned_outlet !== 'ALL' ? ' · ' + esc(u.assigned_outlet) : '') + '</div></div></div>' +
          '<div class="spacer"></div>' +
          '<div class="staff-actions">' +
            '<button class="btn light sm" id="installBtn" hidden>📲 Install App di HP</button>' +
            '<button class="btn light sm" id="pushBtn">🔔 Notifikasi HP: OFF</button>' +
            '<button class="btn light sm" id="soundBtn"></button>' +
            '<button class="btn light sm" id="logoutBtn" title="Keluar">⎋</button>' +
          '</div>' +
        '</div>' +
        '<nav class="tabs">' + TABS.filter(function (t) { return !t.admin || isAdmin(); }).map(function (t) {
          return '<button class="tab' + (state.tab === t.id ? ' active' : '') + '" data-tab="' + t.id + '">' + t.label +
            (t.id === 'monitor' ? '<span class="count" id="newCount" hidden>0</span>' : '') + '</button>';
        }).join('') + '</nav>' +
      '</div></header>' +
      '<div id="iosGuide"></div>' +
      '<main class="container" id="view" style="padding-bottom:40px"></main>';

    $$('.tab[data-tab]').forEach(function (b) { b.addEventListener('click', function () { switchTab(b.getAttribute('data-tab')); }); });
    $('#logoutBtn').onclick = function () { confirmBox('Keluar', 'Keluar dari dashboard?', 'Keluar', 'red').then(function (ok) { if (ok) logout(); }); };
    $('#soundBtn').onclick = function () { unlockAudio(); chime(); };
    $('#pushBtn').onclick = togglePush;
    $('#installBtn').onclick = doInstall;
    updateSoundBtn();
    updateInstallUI();
    refreshPushState();
    switchTab(state.tab);
    startPolling();
  }

  function switchTab(id) {
    state.tab = id; lsSet('gahc_staff_tab', id);
    $$('.tab[data-tab]').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-tab') === id); });
    var v = $('#view');
    v.innerHTML = '';
    ({ monitor: viewMonitor, report: viewReport, menu: viewMenu, hours: viewHours, qr: viewQR, users: viewUsers, settings: viewSettings, account: viewAccount }[id] || viewMonitor)(v);
    window.scrollTo(0, 0);
  }

  /* ================================================================== */
  /* MONITOR                                                            */
  /* ================================================================== */
  function viewMonitor(v) {
    var d = state.dash, lock = lockedOutlet();
    var chips = (lock ? [lock] : ['ALL'].concat(OUTLETS)).map(function (o) {
      return '<button class="chip' + (d.outlet === o ? ' active' : '') + '" data-outlet="' + esc(o) + '">' + esc(o === 'ALL' ? 'Semua Outlet (4)' : o) + '</button>';
    }).join('');
    v.innerHTML =
      '<div class="grid grid-4" style="margin-top:16px" id="kpis"></div>' +
      '<div class="filters">' +
        '<div class="chips" style="flex:1 1 100%">' + chips + '</div>' +
        '<select class="input sm" id="fStatus">' +
          [['ACTIVE', 'Aktif (Baru + Diproses)'], ['ALL', 'Semua Status'], ['NEW', 'Perlu Accept'], ['VHP_INPUTTED', 'Diproses VHP'], ['COMPLETED', 'Selesai'], ['CANCELLED', 'Dibatalkan']]
            .map(function (s) { return '<option value="' + s[0] + '"' + (d.status === s[0] ? ' selected' : '') + '>' + s[1] + '</option>'; }).join('') +
        '</select>' +
        '<input class="input sm" type="date" id="fDate" value="' + esc(d.date) + '" title="Tanggal (kosong = hari ini)">' +
        '<span class="spacer"></span><span class="small muted" id="pollInfo"></span>' +
      '</div>' +
      '<div id="orders"></div>';
    $$('[data-outlet]', v).forEach(function (c) {
      c.onclick = function () { d.outlet = c.getAttribute('data-outlet'); lsSet('gahc_dash_outlet', d.outlet); $$('[data-outlet]', v).forEach(function (x) { x.classList.toggle('active', x === c); }); poll(true); };
    });
    $('#fStatus').onchange = function (e) { d.status = e.target.value; poll(true); };
    $('#fDate').onchange = function (e) { d.date = e.target.value; poll(true); };
    renderDashboard();
    poll(true);
  }

  function renderDashboard() {
    var d = state.dash;
    var k = d.kpi;
    var kp = $('#kpis');
    if (kp && k) {
      kp.innerHTML =
        '<div class="kpi red' + (k.need_accept ? ' pulse' : '') + '"><div class="l">Perlu Accept & Post VHP</div><div class="v">' + num(k.need_accept) + '</div></div>' +
        '<div class="kpi blue"><div class="l">Diproses VHP OK</div><div class="v">' + num(k.vhp_inputted) + '</div></div>' +
        '<div class="kpi green"><div class="l">Selesai / Diantar</div><div class="v">' + num(k.completed) + '</div></div>' +
        '<div class="kpi gold"><div class="l">Total Estimasi Omzet</div><div class="v" style="font-size:20px">' + fmt(k.omzet) + '</div><div class="tiny muted">' + num(k.total_today) + ' pesanan · tanpa batal</div></div>';
    }
    var nc = $('#newCount');
    if (nc && k) { nc.hidden = !k.need_accept; nc.textContent = k.need_accept; }
    document.title = (k && k.need_accept ? '(' + k.need_accept + ') ' : '') + 'GAHC Smart Order — Monitor';
    var box = $('#orders');
    if (!box) return;
    if (!d.kpi) { box.innerHTML = '<div class="skeleton" style="height:160px"></div>'; return; }
    if (!d.orders.length) { box.innerHTML = '<div class="empty"><div class="big">✅</div>Tidak ada pesanan untuk filter ini.</div>'; return; }
    box.innerHTML = '<div class="orders-grid">' + d.orders.map(orderCardHTML).join('') + '</div>';
    bindOrderActions(box);
  }

  function statusBadge(s) {
    if (s === 'NEW') return '<span class="badge solid-red blink">Input VHP!</span>';
    var cls = { VHP_INPUTTED: 'blue', COMPLETED: 'green', CANCELLED: 'grey' }[s] || 'grey';
    return '<span class="badge ' + cls + '">' + esc(STATUS_LABEL[s] || s) + '</span>';
  }

  function orderCardHTML(o) {
    var where = o.order_type === 'Room Service' ? '🛏️ Kamar <b>' + esc(o.room_number || '-') + '</b>' : (o.order_type === 'Dine In' ? '🍽️ Dine In' : '🛍️ Take Away');
    var actions = '';
    if (o.status === 'NEW') actions = '<button class="btn red sm" data-act="accept" data-id="' + o.id + '">✔ Accept & Post to VHP</button><button class="btn ghost sm" data-act="cancel" data-id="' + o.id + '">Batalkan</button>';
    else if (o.status === 'VHP_INPUTTED') actions = '<button class="btn green sm" data-act="complete" data-id="' + o.id + '">🚀 Selesai / Antar</button><button class="btn ghost sm" data-act="cancel" data-id="' + o.id + '">Batalkan</button>';
    var aliases = o.guest_aliases && o.guest_aliases.length ? '<div class="tiny muted">Nama lain: ' + esc(o.guest_aliases.join(', ')) + '</div>' : '';
    return '<div class="ocard s-' + esc(o.status) + '" id="o-' + o.id + '">' +
      '<div class="head"><div style="flex:1;min-width:0"><div class="code" data-act="detail" data-id="' + o.id + '" style="cursor:pointer">' + esc(o.order_code) + '</div>' +
        '<div class="small muted">' + esc(o.outlet_name) + ' · ' + esc(hm(o.created_at)) + ' · ' + esc(ago(o.created_at, state.dash.serverTime)) + '</div></div>' + statusBadge(o.status) + '</div>' +
      '<div class="body">' +
        '<div class="row wrap small"><span>' + where + '</span><span class="muted">·</span><strong>' + esc(o.guest_name) + '</strong><a href="tel:' + esc(o.guest_phone) + '" class="muted">' + esc(o.guest_phone) + '</a></div>' + aliases +
        '<div class="items">' + o.items.map(function (it) {
          return '<div class="item"><span class="q">' + it.qty + '×</span><span class="n">' + esc(it.menu_name) +
            (it.vhp_code ? ' <span class="tiny muted">[' + esc(it.vhp_code) + ']</span>' : '') +
            (it.item_note ? '<div class="inote">📝 ' + esc(it.item_note) + '</div>' : '') + '</span><span class="small">' + fmt(it.line_total) + '</span></div>';
        }).join('') + '</div>' +
        (o.global_note ? '<div class="gnote">📝 ' + esc(o.global_note) + '</div>' : '') +
        '<div class="row small" style="margin-top:8px"><span class="muted spacer">Subtotal ' + fmt(o.subtotal) + ' + Tax&Svc ' + fmt(o.tax_service_amount) + '</span><strong>' + fmt(o.grand_total) + '</strong></div>' +
        (o.vhp_bill_no || o.vhp_staff_name ? '<div class="tiny muted" style="margin-top:4px">VHP: ' + esc(o.vhp_bill_no || '-') + ' · ' + esc(o.vhp_staff_name || '-') + '</div>' : '') +
        (o.status === 'CANCELLED' && o.cancel_reason ? '<div class="banner warn small">Alasan batal: ' + esc(o.cancel_reason) + '</div>' : '') +
      '</div>' +
      (actions ? '<div class="actions">' + actions + '</div>' : '') +
    '</div>';
  }

  function findOrder(id) {
    var all = state.dash.orders.concat(state.alertQueue);
    if (state.report.data) all = all.concat(state.report.data.orders);
    return all.filter(function (o) { return o.id === id; })[0] || null;
  }

  function bindOrderActions(root) {
    $$('[data-act]', root).forEach(function (b) {
      b.onclick = function () {
        var o = findOrder(Number(b.getAttribute('data-id')));
        if (!o) return;
        var a = b.getAttribute('data-act');
        if (a === 'accept') acceptOrder(o);
        else if (a === 'complete') completeOrder(o, b);
        else if (a === 'cancel') cancelOrder(o);
        else if (a === 'detail') showOrderDetail(o);
      };
    });
  }

  function patchOrder(body) {
    return api('api/orders.php', { method: 'PATCH', body: body }).then(function (d) {
      ackAlert(d.order.id);
      return d.order;
    }).catch(function (e) {
      toast(e.message, 'err');
      if (e.status === 409) poll(true);
      throw e;
    });
  }

  function acceptOrder(o) {
    var lastName = lsGet('gahc_vhp_staff', state.user.full_name);
    var w = modal('<div class="modal-head"><h3>Accept & Post to VHP — ' + esc(o.order_code) + '</h3><button class="icon-btn" data-close>✕</button></div>' +
      '<div class="modal-body stack">' +
        '<div class="banner info">Input pesanan ini ke POS VHP terlebih dahulu, lalu konfirmasi di sini.</div>' +
        '<label class="field"><span>Nomor Bill / Check VHP (opsional)</span><input class="input" id="vBill" maxlength="50" inputmode="text"></label>' +
        '<label class="field"><span>Nama Waiter / Kasir</span><input class="input" id="vStaff" maxlength="80" value="' + esc(lastName) + '"></label>' +
      '</div>' +
      '<div class="modal-foot"><button class="btn ghost" data-close>Batal</button><button class="btn red" id="vOk">✔ Konfirmasi Diproses VHP</button></div>');
    $('#vOk', w).onclick = function () {
      var btn = this; busy(btn, true);
      var staffName = $('#vStaff', w).value.trim();
      lsSet('gahc_vhp_staff', staffName);
      patchOrder({ order_id: o.id, action: 'accept', vhp_bill_no: $('#vBill', w).value.trim(), vhp_staff_name: staffName })
        .then(function () { closeModal(w); toast('Pesanan ' + o.order_code + ' diproses VHP.', 'ok'); poll(true); })
        .catch(function () { busy(btn, false); });
    };
  }

  function completeOrder(o, btn) {
    busy(btn, true);
    patchOrder({ order_id: o.id, action: 'complete' })
      .then(function () { toast('Pesanan ' + o.order_code + ' selesai.', 'ok'); poll(true); })
      .catch(function () { busy(btn, false); });
  }

  function cancelOrder(o) {
    var reasons = ['Stok habis', 'Pesanan ganda / dobel', 'Tamu membatalkan', 'Data tamu tidak valid', 'Outlet tutup'];
    var w = modal('<div class="modal-head"><h3>Batalkan ' + esc(o.order_code) + '</h3><button class="icon-btn" data-close>✕</button></div>' +
      '<div class="modal-body stack"><div class="chips" style="flex-wrap:wrap">' + reasons.map(function (r) { return '<button class="chip" data-r="' + esc(r) + '">' + esc(r) + '</button>'; }).join('') + '</div>' +
      '<label class="field"><span>Alasan pembatalan (wajib)</span><textarea class="input" id="cReason" maxlength="255"></textarea></label>' +
      '<div class="error-text" id="cErr" hidden></div></div>' +
      '<div class="modal-foot"><button class="btn ghost" data-close>Kembali</button><button class="btn red" id="cOk">Batalkan Pesanan</button></div>');
    $$('[data-r]', w).forEach(function (c) { c.onclick = function () { $('#cReason', w).value = c.getAttribute('data-r'); }; });
    $('#cOk', w).onclick = function () {
      var r = $('#cReason', w).value.trim();
      if (r.length < 3) { $('#cErr', w).textContent = 'Alasan wajib diisi.'; $('#cErr', w).hidden = false; return; }
      var btn = this; busy(btn, true);
      patchOrder({ order_id: o.id, action: 'cancel', cancel_reason: r })
        .then(function () { closeModal(w); toast('Pesanan ' + o.order_code + ' dibatalkan.'); poll(true); if (state.tab === 'report') loadReport(); })
        .catch(function () { busy(btn, false); });
    };
  }

  function showOrderDetail(o) {
    var w = modal('<div class="modal-head"><h3>Detail Pesanan</h3><button class="icon-btn" data-close>✕</button></div>' +
      '<div class="modal-body" style="padding:0">' + orderCardHTML(o).replace('class="ocard', 'style="box-shadow:none;border-radius:0" class="ocard') + '</div>', { wide: true });
    // ikat aksi langsung ke objek pesanan ini (bisa berasal dari push/laporan, tidak selalu ada di daftar monitor)
    $$('[data-act]', w).forEach(function (b) {
      var a = b.getAttribute('data-act');
      if (a === 'detail') { b.style.cursor = 'default'; return; }
      b.onclick = function () {
        if (a === 'complete') { completeOrder(o, b); setTimeout(function () { closeModal(w); }, 300); return; }
        closeModal(w);
        if (a === 'accept') acceptOrder(o); else if (a === 'cancel') cancelOrder(o);
      };
    });
  }

  function openOrderByCode(code) {
    if (!code) return;
    api('api/orders.php?order_code=' + encodeURIComponent(code)).then(function (d) {
      if (state.tab !== 'monitor') switchTab('monitor');
      showOrderDetail(d.order);
    }).catch(function (e) { toast(e.message, 'err'); });
  }

  /* ---------- New-order alert (pop-up + alarm) ---------- */
  var alarmTimer = null, alertModal = null;
  function enqueueAlerts(list) {
    list.forEach(function (o) {
      if (state.alertedIds[o.id]) return;
      state.alertedIds[o.id] = true;
      state.alertQueue.push(o);
    });
    if (state.alertQueue.length) showAlertModal();
  }
  function ackAlert(id) {
    state.alertQueue = state.alertQueue.filter(function (o) { return o.id !== id; });
    if (alertModal) showAlertModal();
  }
  function stopAlarm() { clearInterval(alarmTimer); alarmTimer = null; }
  function showAlertModal() {
    if (!state.alertQueue.length) { if (alertModal) { var m = alertModal; alertModal = null; closeModal(m); } stopAlarm(); return; }
    var o = state.alertQueue[0];
    var more = state.alertQueue.length - 1;
    var html = '<div class="modal-head"><h3>🔔 Pesanan Baru — ' + esc(o.outlet_name) + '</h3></div>' +
      '<div class="modal-body" style="padding:0">' + orderCardHTML(o).replace(/<div class="actions">[\s\S]*<\/div><\/div>$/, '</div>') + '</div>' +
      (more > 0 ? '<div class="center small bold" style="padding:8px;color:var(--red)">+ ' + more + ' pesanan baru lainnya</div>' : '') +
      '<div class="modal-foot"><button class="btn ghost" id="aLater">Lihat Nanti</button><button class="btn red" id="aAccept">✔ Accept & Post to VHP</button></div>';
    if (alertModal) closeModal(alertModal);
    alertModal = modal(html, { locked: true, cls: 'alert-modal', sheet: false, focus: false });
    $('#aLater', alertModal).onclick = function () { state.alertQueue.shift(); showAlertModal(); };
    $('#aAccept', alertModal).onclick = function () { var cur = state.alertQueue.shift(); showAlertModal(); acceptOrder(cur); };
    chime();
    if (!alarmTimer) alarmTimer = setInterval(function () { if (state.alertQueue.length) chime(); else stopAlarm(); }, 4000);
  }

  /* ---------- Smart polling ---------- */
  function startPolling() { stopPolling(); state.pollTimer = setTimeout(pollLoop, 300); }
  function stopPolling() { clearTimeout(state.pollTimer); state.pollTimer = null; }
  function pollLoop() {
    poll().then(function () {
      if (state.user) state.pollTimer = setTimeout(pollLoop, POLL_MS);
    });
  }
  function poll(force) {
    if (!state.user) return Promise.resolve();
    if (state.polling && !force) return Promise.resolve();
    state.polling = true;
    var d = state.dash;
    var qs = (d.initialized ? 'after_id=' + d.lastId + '&' : '') + 'outlet=' + encodeURIComponent(d.outlet) + '&status=' + encodeURIComponent(d.status) + (d.date ? '&date=' + d.date : '');
    return api('api/orders.php?' + qs).then(function (r) {
      state.polling = false;
      state.online = true;
      d.orders = r.orders; d.kpi = r.kpi; d.serverTime = r.server_time;
      if (!d.initialized) {
        // pemuatan pertama: pesanan NEW yang sudah ada tidak memicu alarm (tetap tampil berkedip di daftar)
        r.orders.forEach(function (o) { if (o.status === 'NEW') state.alertedIds[o.id] = true; });
        d.initialized = true;
      } else if (r.has_new_orders) {
        enqueueAlerts(r.new_orders);
      }
      d.lastId = Math.max(d.lastId, r.max_id);
      if (state.tab === 'monitor') renderDashboard();
      var pi = $('#pollInfo'); if (pi) pi.textContent = '● Live · ' + hm(r.server_time);
      if (pendingOpenOrder) { var c = pendingOpenOrder; pendingOpenOrder = null; history.replaceState(null, '', location.pathname); openOrderByCode(c); }
    }).catch(function (e) {
      state.polling = false;
      state.online = false;
      var pi = $('#pollInfo'); if (pi) pi.innerHTML = '<span style="color:var(--red)">● Offline — ' + esc(e.message) + '</span>';
    });
  }
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible' && state.user) poll(true); });
  window.addEventListener('online', function () { if (state.user) poll(true); });

  /* ================================================================== */
  /* LAPORAN                                                            */
  /* ================================================================== */
  var REPORT_PRESETS = [
    ['today', 'Hari ini'], ['yesterday', 'Kemarin'], ['7d', '7 hari terakhir'], ['30d', '30 hari terakhir'],
    ['month', 'Bulan ini'], ['lastmonth', 'Bulan lalu'], ['year', 'Tahun ini'], ['custom', 'Pilih tanggal…'],
  ];
  function ymd(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function presetRange(p) {
    var t = new Date(); t.setHours(12, 0, 0, 0);
    var y = t.getFullYear(), m = t.getMonth();
    function back(n) { var d = new Date(t); d.setDate(d.getDate() - n); return d; }
    switch (p) {
      case 'yesterday': return [ymd(back(1)), ymd(back(1))];
      case '7d': return [ymd(back(6)), ymd(t)];
      case '30d': return [ymd(back(29)), ymd(t)];
      case 'month': return [ymd(new Date(y, m, 1)), ymd(t)];
      case 'lastmonth': return [ymd(new Date(y, m - 1, 1)), ymd(new Date(y, m, 0))];
      case 'year': return [ymd(new Date(y, 0, 1)), ymd(t)];
      default: return [ymd(t), ymd(t)];
    }
  }
  var DAY_NAMES = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
  function dayName(s) { var d = parseLocal(s + ' 12:00:00'); return d ? DAY_NAMES[d.getDay()] : ''; }
  function pct(v) { return (Number(v) || 0).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + '%'; }
  function periodLabel(d) { return d.date_from === d.date_to ? dmy(d.date_from) : dmy(d.date_from) + ' – ' + dmy(d.date_to); }

  /** Unduh file dari API via fetch+blob (aman di PWA standalone iOS). */
  function downloadFile(url, filename, btn) {
    busy(btn, true);
    return fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (res) {
        if (res.status === 401) { onSessionExpired(); throw new Error('Sesi berakhir.'); }
        if (!res.ok) return res.json().catch(function () { return null; }).then(function (j) { throw new Error((j && j.message) || 'Export gagal (HTTP ' + res.status + ').'); });
        return res.blob();
      }).then(function (blob) {
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
      }).catch(function (e) { toast(e.message, 'err'); }).then(function () { busy(btn, false); });
  }

  function viewReport(v) {
    var r = state.report, lock = lockedOutlet();
    if (!r.preset) r.preset = 'today';
    if (!r.view) r.view = 'summary';
    if (!r.type) r.type = 'ALL';
    v.innerHTML = '<div class="row wrap" style="margin-top:16px"><h2 class="section-title spacer" style="margin:0">Laporan</h2>' +
        '<div class="seg" id="rView"><button data-v="summary">📊 Rekap</button><button data-v="tx">🧾 Transaksi</button></div></div>' +
      '<div class="card" style="margin-top:12px"><div class="grid grid-4">' +
        '<label class="field"><span>Periode</span><select class="input sm" id="rPreset">' +
          REPORT_PRESETS.map(function (p) { return '<option value="' + p[0] + '"' + (r.preset === p[0] ? ' selected' : '') + '>' + p[1] + '</option>'; }).join('') + '</select></label>' +
        '<label class="field"><span>Dari tanggal</span><input class="input sm" type="date" id="rFrom" value="' + esc(r.from) + '"></label>' +
        '<label class="field"><span>Sampai tanggal</span><input class="input sm" type="date" id="rTo" value="' + esc(r.to) + '"></label>' +
        '<label class="field"><span>Outlet</span><select class="input sm" id="rOutlet"' + (lock ? ' disabled' : '') + '>' + outletOptions(lock || r.outlet, !lock) + '</select></label>' +
        '<label class="field"><span>Tipe Pesanan</span><select class="input sm" id="rType">' +
          [['ALL', 'Semua tipe'], ['Dine In', 'Dine In'], ['Take Away', 'Take Away'], ['Room Service', 'Room Service']]
            .map(function (s) { return '<option value="' + s[0] + '"' + (r.type === s[0] ? ' selected' : '') + '>' + s[1] + '</option>'; }).join('') + '</select></label>' +
        '<label class="field rtx-only"><span>Status</span><select class="input sm" id="rStatus">' +
          [['ALL', 'Semua'], ['NEW', 'Baru'], ['VHP_INPUTTED', 'Diproses VHP'], ['COMPLETED', 'Selesai'], ['CANCELLED', 'Dibatalkan']]
            .map(function (s) { return '<option value="' + s[0] + '"' + (r.status === s[0] ? ' selected' : '') + '>' + s[1] + '</option>'; }).join('') + '</select></label>' +
        '<label class="field rtx-only" style="grid-column:span 2"><span>Cari</span><input class="input sm" id="rQ" placeholder="Nama, no. HP, no. kamar, kode order, no. bill VHP" value="' + esc(r.q) + '"></label>' +
      '</div>' +
      '<div class="row wrap" style="margin-top:12px">' +
        '<button class="btn sm" id="rGo">Tampilkan</button><span class="spacer"></span>' +
        '<select class="input sm" id="rDelim" style="width:auto" title="Pemisah CSV"><option value="semicolon">Excel Indonesia (;)</option><option value="comma">CSV standar (,)</option></select>' +
        '<button class="btn ghost sm" id="rCsvSum">⬇ Rekap CSV</button>' +
        '<button class="btn ghost sm" id="rCsv">⬇ Transaksi CSV</button>' +
        '<button class="btn gold sm" id="rPrint">🖨 Cetak / PDF</button>' +
      '</div></div>' +
      '<div id="rBody" style="margin-top:14px"></div>';

    function syncView() {
      $$('#rView button').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-v') === r.view); });
      $$('.rtx-only').forEach(function (el) { el.style.display = r.view === 'tx' ? '' : 'none'; });
    }
    function read() {
      r.from = $('#rFrom').value || todayStr(); r.to = $('#rTo').value || r.from;
      if (r.to < r.from) { var x = r.from; r.from = r.to; r.to = x; $('#rFrom').value = r.from; $('#rTo').value = r.to; }
      r.outlet = lock || $('#rOutlet').value; r.type = $('#rType').value;
      r.status = $('#rStatus').value; r.q = $('#rQ').value.trim();
    }
    function qs() {
      return 'date_from=' + r.from + '&date_to=' + r.to + '&outlet=' + encodeURIComponent(r.outlet) +
        '&order_type=' + encodeURIComponent(r.type) + '&status=' + r.status + '&q=' + encodeURIComponent(r.q);
    }
    function go() { read(); loadReport(); }
    if (r.preset !== 'custom') { var pr = presetRange(r.preset); r.from = pr[0]; r.to = pr[1]; $('#rFrom').value = r.from; $('#rTo').value = r.to; }
    $('#rPreset').onchange = function () {
      r.preset = this.value;
      if (r.preset !== 'custom') { var p = presetRange(r.preset); $('#rFrom').value = p[0]; $('#rTo').value = p[1]; go(); }
      else $('#rFrom').focus();
    };
    $('#rFrom').onchange = $('#rTo').onchange = function () { r.preset = 'custom'; $('#rPreset').value = 'custom'; };
    $('#rOutlet').onchange = $('#rType').onchange = $('#rStatus').onchange = go;
    $('#rGo').onclick = go;
    $('#rQ').onkeydown = function (e) { if (e.key === 'Enter') go(); };
    $$('#rView button').forEach(function (b) {
      b.onclick = function () { r.view = b.getAttribute('data-v'); syncView(); go(); };
    });
    $('#rCsv').onclick = function () {
      read();
      downloadFile('api/orders.php?action=export&' + qs() + '&delim=' + $('#rDelim').value, 'transaksi-smart-order-' + r.from + '_sd_' + r.to + '.csv', this);
    };
    $('#rCsvSum').onclick = function () {
      read();
      downloadFile('api/reports.php?action=export&' + qs() + '&delim=' + $('#rDelim').value, 'rekap-smart-order-' + r.from + '_sd_' + r.to + '.csv', this);
    };
    $('#rPrint').onclick = function () {
      read();
      var btn = this; busy(btn, true);
      api('api/reports.php?' + qs()).then(function (d) {
        var pa = $('#printArea');
        pa.innerHTML = '<div class="report-print">' + reportPrintHTML(d) + '</div>';
        setTimeout(function () { window.print(); setTimeout(function () { pa.innerHTML = ''; }, 1000); }, 100);
      }).catch(function (e) { toast(e.message, 'err'); }).then(function () { busy(btn, false); });
    };
    state.report.qs = qs;
    syncView();
    loadReport();
  }

  function loadReport() {
    var r = state.report;
    var body = $('#rBody');
    if (!body || !r.qs) return;
    body.innerHTML = '<div class="skeleton" style="height:120px"></div>';
    if (r.view === 'tx') { loadTransactions(body); return; }
    api('api/reports.php?' + r.qs()).then(function (d) {
      r.summary = d;
      body.innerHTML = reportSummaryHTML(d);
    }).catch(function (e) { body.innerHTML = '<div class="banner warn">' + esc(e.message) + '</div>'; });
  }

  /* ---------- tampilan rekap ---------- */
  function bars(list, valueKey, labelFn, tipFn, opts) {
    opts = opts || {};
    var max = list.reduce(function (m, x) { return Math.max(m, Number(x[valueKey]) || 0); }, 0);
    if (!max) return '<div class="empty small">Belum ada data.</div>';
    var showEvery = Math.max(1, Math.ceil(list.length / (opts.maxLabels || 16)));
    return '<div class="vbars" role="img" aria-label="' + esc(opts.aria || '') + '">' + list.map(function (x, i) {
      var val = Number(x[valueKey]) || 0, h = val ? Math.max(2, Math.round(val / max * 100)) : 0;
      return '<div class="vbar" title="' + esc(tipFn(x)) + '"><div class="vbar-track"><div class="vbar-fill" style="height:' + h + '%"></div></div>' +
        '<div class="vbar-l">' + (i % showEvery === 0 ? esc(labelFn(x)) : '&nbsp;') + '</div></div>';
    }).join('') + '</div>';
  }
  function meter(val, max) {
    var w = max > 0 ? Math.max(val > 0 ? 2 : 0, Math.round(val / max * 100)) : 0;
    return '<div class="meter"><span style="width:' + w + '%"></span></div>';
  }
  function tableHTML(head, rows, foot) {
    return '<div class="table-wrap"><table class="table"><thead><tr>' + head.map(function (h) {
      return '<th' + (h.charAt(0) === '>' ? ' class="right">' + esc(h.slice(1)) : '>' + esc(h)) + '</th>';
    }).join('') + '</tr></thead><tbody>' + rows.join('') + '</tbody>' + (foot ? '<tfoot>' + foot + '</tfoot>' : '') + '</table></div>';
  }
  function card(title, inner, sub) {
    return '<div class="card rcard"><div class="row" style="margin-bottom:10px"><h3 class="spacer" style="margin:0;font-size:16px">' + esc(title) + '</h3>' +
      (sub ? '<span class="tiny muted">' + esc(sub) + '</span>' : '') + '</div>' + inner + '</div>';
  }

  function reportSummaryHTML(d) {
    var s = d.summary;
    var head = '<div class="banner info small">Periode <b>' + esc(periodLabel(d)) + '</b> (' + d.days + ' hari) · ' +
      esc(d.outlet === 'ALL' ? 'Semua outlet' : d.outlet) + (d.order_type !== 'ALL' ? ' · ' + esc(d.order_type) : '') +
      ' · Omzet tidak termasuk pesanan dibatalkan.</div>';
    var kpi = '<div class="grid grid-4" style="margin-top:12px">' +
      '<div class="kpi gold"><div class="l">Omzet (Grand Total)</div><div class="v" style="font-size:22px">' + fmt(s.omzet) + '</div><div class="tiny muted">Rata-rata/hari ' + fmt(s.avg_per_day) + '</div></div>' +
      '<div class="kpi blue"><div class="l">Pesanan Valid</div><div class="v">' + num(s.orders_valid) + '</div><div class="tiny muted">dari ' + num(s.orders_total) + ' pesanan masuk</div></div>' +
      '<div class="kpi green"><div class="l">Rata-rata / Pesanan</div><div class="v" style="font-size:22px">' + fmt(s.avg_order) + '</div><div class="tiny muted">Terbesar ' + fmt(s.max_order) + '</div></div>' +
      '<div class="kpi red"><div class="l">Dibatalkan</div><div class="v">' + num(s.status.CANCELLED) + '</div><div class="tiny muted">' + pct(s.cancel_rate) + ' · ' + fmt(s.cancelled_value) + '</div></div>' +
      '<div class="kpi"><div class="l">Subtotal</div><div class="v" style="font-size:20px">' + fmt(s.subtotal) + '</div></div>' +
      '<div class="kpi"><div class="l">Tax & Service</div><div class="v" style="font-size:20px">' + fmt(s.tax_service) + '</div></div>' +
      '<div class="kpi"><div class="l">Item Terjual</div><div class="v">' + num(s.items_qty) + '</div><div class="tiny muted">porsi/qty</div></div>' +
      '<div class="kpi"><div class="l">Tamu Unik</div><div class="v">' + num(s.unique_guests) + '</div><div class="tiny muted">nomor HP berbeda</div></div>' +
      '</div>';
    var pending = s.status.NEW + s.status.VHP_INPUTTED;
    var statusRow = '<div class="row wrap small" style="margin-top:10px;gap:8px">Status: ' +
      '<span class="badge solid-red">Baru ' + num(s.status.NEW) + '</span>' +
      '<span class="badge blue">Diproses VHP ' + num(s.status.VHP_INPUTTED) + '</span>' +
      '<span class="badge green">Selesai ' + num(s.status.COMPLETED) + '</span>' +
      '<span class="badge grey">Dibatalkan ' + num(s.status.CANCELLED) + '</span>' +
      (pending ? '<span class="muted">· ' + num(pending) + ' pesanan belum selesai</span>' : '') + '</div>';

    var maxOutlet = d.by_outlet.reduce(function (m, x) { return Math.max(m, x.omzet); }, 0);
    var outletT = tableHTML(['Outlet', '>Pesanan', '>Batal', '>Item', '>Subtotal', '>Tax & Svc', '>Omzet', '>Rata-rata', 'Kontribusi'],
      d.by_outlet.map(function (o) {
        return '<tr><td class="bold">' + esc(o.outlet) + '</td><td class="right">' + num(o.orders_valid) + '</td><td class="right">' + num(o.cancelled) + '</td>' +
          '<td class="right">' + num(o.items_qty) + '</td><td class="right nowrap">' + fmt(o.subtotal) + '</td><td class="right nowrap">' + fmt(o.tax_service) + '</td>' +
          '<td class="right nowrap bold">' + fmt(o.omzet) + '</td><td class="right nowrap">' + fmt(o.avg_order) + '</td>' +
          '<td style="min-width:120px">' + meter(o.omzet, maxOutlet) + '<span class="tiny muted">' + pct(o.share) + '</span></td></tr>';
      }),
      d.by_outlet.length > 1 ? '<tr><td class="bold">TOTAL</td><td class="right bold">' + num(s.orders_valid) + '</td><td class="right bold">' + num(s.status.CANCELLED) + '</td><td class="right bold">' + num(s.items_qty) + '</td>' +
        '<td class="right nowrap bold">' + fmt(s.subtotal) + '</td><td class="right nowrap bold">' + fmt(s.tax_service) + '</td><td class="right nowrap bold">' + fmt(s.omzet) + '</td><td class="right nowrap bold">' + fmt(s.avg_order) + '</td><td></td></tr>' : '');

    var typeT = d.by_type.length ? tableHTML(['Tipe', '>Pesanan', '>Batal', '>Omzet'], d.by_type.map(function (t) {
      return '<tr><td>' + esc(t.order_type) + '</td><td class="right">' + num(t.orders_valid) + '</td><td class="right">' + num(t.cancelled) + '</td><td class="right nowrap bold">' + fmt(t.omzet) + '</td></tr>';
    })) : '<div class="empty small">Belum ada data.</div>';

    var catMax = d.by_category.reduce(function (m, x) { return Math.max(m, x.sales); }, 0);
    var catT = d.by_category.length ? tableHTML(['Kategori', '>Qty', '>Penjualan', ''], d.by_category.map(function (c) {
      return '<tr><td>' + esc(c.category) + '</td><td class="right">' + num(c.qty) + '</td><td class="right nowrap bold">' + fmt(c.sales) + '</td><td style="min-width:90px">' + meter(c.sales, catMax) + '</td></tr>';
    })) : '<div class="empty small">Belum ada data.</div>';

    var dayChart = d.by_day.length > 1 ? card('Omzet per Hari', bars(d.by_day, 'omzet', function (x) { return x.date.slice(8) + (d.by_day.length <= 14 ? ' ' + dayName(x.date) : ''); },
      function (x) { return dayName(x.date) + ' ' + dmy(x.date) + ' — ' + fmt(x.omzet) + ' · ' + x.orders_valid + ' pesanan' + (x.cancelled ? ' · ' + x.cancelled + ' batal' : ''); },
      { aria: 'Grafik omzet per hari', maxLabels: 16 }), 'Arahkan kursor/ketuk batang untuk detail') : '';
    var hours = d.by_hour.filter(function (h) { return h.orders > 0; });
    // tampilkan minimal jam 06–22 agar pola jam sibuk terbaca, diperluas bila ada pesanan di luar rentang itu
    var hFrom = Math.min(6, hours.length ? hours[0].hour : 6), hTo = Math.max(22, hours.length ? hours[hours.length - 1].hour : 22);
    var hourList = d.by_hour.slice(hFrom, hTo + 1);
    var peak = hours.reduce(function (p, h) { return !p || h.orders > p.orders ? h : p; }, null);
    var hourChart = card('Jumlah Pesanan per Jam', bars(hourList, 'orders', function (x) { return ('0' + x.hour).slice(-2); },
      function (x) { return ('0' + x.hour).slice(-2) + ':00–' + ('0' + x.hour).slice(-2) + ':59 — ' + x.orders + ' pesanan · ' + fmt(x.omzet); },
      { aria: 'Grafik pesanan per jam', maxLabels: 24 }), peak ? 'Jam tersibuk ' + ('0' + peak.hour).slice(-2) + ':00 (' + peak.orders + ' pesanan)' : '');

    var dayT = d.by_day.length > 1 ? card('Rincian per Hari', tableHTML(['Tanggal', '>Pesanan', '>Batal', '>Omzet'], d.by_day.map(function (x) {
      return '<tr' + (x.orders_valid || x.cancelled ? '' : ' class="muted"') + '><td class="nowrap">' + esc(dayName(x.date) + ', ' + dmy(x.date)) + '</td><td class="right">' + num(x.orders_valid) + '</td><td class="right">' + num(x.cancelled) + '</td><td class="right nowrap bold">' + fmt(x.omzet) + '</td></tr>';
    }), '<tr><td class="bold">TOTAL</td><td class="right bold">' + num(s.orders_valid) + '</td><td class="right bold">' + num(s.status.CANCELLED) + '</td><td class="right bold nowrap">' + fmt(s.omzet) + '</td></tr>')) : '';

    var menuMax = d.by_menu.reduce(function (m, x) { return Math.max(m, x.qty); }, 0);
    var menuT = d.by_menu.length ? tableHTML(['#', 'Menu', 'Kode VHP', 'Kategori', '>Qty', '', '>Penjualan'], d.by_menu.map(function (m, i) {
      return '<tr><td class="muted">' + (i + 1) + '</td><td class="bold">' + esc(m.menu_name) + '</td><td class="small">' + esc(m.vhp_code || '-') + '</td><td class="small">' + esc(m.category) + '</td>' +
        '<td class="right bold">' + num(m.qty) + '</td><td style="min-width:90px">' + meter(m.qty, menuMax) + '</td><td class="right nowrap">' + fmt(m.sales) + '</td></tr>';
    })) : '<div class="empty small">Belum ada penjualan.</div>';

    var staffT = d.by_staff.length ? tableHTML(['Staff VHP', '>Pesanan', '>Omzet'], d.by_staff.map(function (x) {
      return '<tr><td>' + esc(x.staff) + '</td><td class="right">' + num(x.orders) + '</td><td class="right nowrap">' + fmt(x.omzet) + '</td></tr>';
    })) : '<div class="empty small">Belum ada pesanan yang diproses.</div>';
    var cancelT = d.cancel_reasons.length ? tableHTML(['Alasan', '>Jumlah', '>Nilai'], d.cancel_reasons.map(function (x) {
      return '<tr><td>' + esc(x.reason) + '</td><td class="right">' + num(x.count) + '</td><td class="right nowrap">' + fmt(x.value) + '</td></tr>';
    })) : '<div class="empty small">Tidak ada pembatalan. 👍</div>';
    var guestT = d.top_guests.length ? tableHTML(['Tamu', 'No. HP', '>Pesanan', '>Omzet'], d.top_guests.map(function (x) {
      return '<tr><td>' + esc(x.name) + '</td><td class="small">' + esc(x.phone) + '</td><td class="right">' + num(x.orders) + '</td><td class="right nowrap">' + fmt(x.omzet) + '</td></tr>';
    })) : '<div class="empty small">Belum ada data.</div>';

    if (!s.orders_total) return head + '<div class="empty" style="margin-top:14px">Tidak ada transaksi pada periode ini.</div>';
    return head + kpi + statusRow +
      '<div class="stack" style="margin-top:14px">' +
        card('Per Outlet', outletT) +
        dayChart + hourChart +
        '<div class="grid grid-2">' + card('Per Tipe Pesanan', typeT) + card('Per Kategori', catT, 'sebelum Tax & Svc') + '</div>' +
        card('Penjualan per Menu', menuT, d.by_menu.length + ' menu · urut qty terbanyak · nilai sebelum Tax & Svc') +
        dayT +
        '<div class="grid grid-2">' + card('Per Staff VHP', staffT, 'pesanan diproses/selesai') + card('Alasan Pembatalan', cancelT) + '</div>' +
        card('Tamu Teratas', guestT, '20 tamu dengan belanja terbesar') +
      '</div>' +
      '<div class="tiny muted center" style="margin-top:10px">Dibuat ' + esc(dmy(d.generated_at) + ' ' + hm(d.generated_at)) + ' oleh ' + esc(d.generated_by) + '</div>';
  }

  /* ---------- versi cetak (A4) ---------- */
  function reportPrintHTML(d) {
    var s = d.summary;
    function t(head, rows) {
      return '<table><thead><tr>' + head.map(function (h) { return '<th' + (h.charAt(0) === '>' ? ' class="r">' + esc(h.slice(1)) : '>' + esc(h)) + '</th>'; }).join('') + '</tr></thead><tbody>' + rows.join('') + '</tbody></table>';
    }
    function sec(title, inner) { return '<h3>' + esc(title) + '</h3>' + inner; }
    var html = '<div class="rp-head"><div><div class="rp-brand">Grand Artos Hotel & Convention</div><h1>Laporan Smart Outlet Ordering</h1>' +
      '<div>Periode: <b>' + esc(periodLabel(d)) + '</b> (' + d.days + ' hari) · Outlet: <b>' + esc(d.outlet === 'ALL' ? 'Semua Outlet' : d.outlet) + '</b>' +
      (d.order_type !== 'ALL' ? ' · Tipe: <b>' + esc(d.order_type) + '</b>' : '') + '</div></div>' +
      '<div class="rp-meta">Dicetak ' + esc(dmy(d.generated_at) + ' ' + hm(d.generated_at)) + '<br>oleh ' + esc(d.generated_by) + '</div></div>';
    html += '<div class="rp-kpi">' + [
      ['Omzet', fmt(s.omzet)], ['Pesanan Valid', num(s.orders_valid)], ['Rata-rata/Pesanan', fmt(s.avg_order)], ['Item Terjual', num(s.items_qty)],
      ['Subtotal', fmt(s.subtotal)], ['Tax & Service', fmt(s.tax_service)], ['Dibatalkan', num(s.status.CANCELLED) + ' (' + fmt(s.cancelled_value) + ')'], ['Tamu Unik', num(s.unique_guests)],
    ].map(function (k) { return '<div><span>' + esc(k[0]) + '</span><b>' + esc(k[1]) + '</b></div>'; }).join('') + '</div>';
    html += sec('Per Outlet', t(['Outlet', '>Pesanan', '>Batal', '>Item', '>Subtotal', '>Tax & Svc', '>Omzet', '>%'], d.by_outlet.map(function (o) {
      return '<tr><td>' + esc(o.outlet) + '</td><td class="r">' + num(o.orders_valid) + '</td><td class="r">' + num(o.cancelled) + '</td><td class="r">' + num(o.items_qty) + '</td><td class="r">' + fmt(o.subtotal) + '</td><td class="r">' + fmt(o.tax_service) + '</td><td class="r"><b>' + fmt(o.omzet) + '</b></td><td class="r">' + pct(o.share) + '</td></tr>';
    }).concat(['<tr class="tot"><td>TOTAL</td><td class="r">' + num(s.orders_valid) + '</td><td class="r">' + num(s.status.CANCELLED) + '</td><td class="r">' + num(s.items_qty) + '</td><td class="r">' + fmt(s.subtotal) + '</td><td class="r">' + fmt(s.tax_service) + '</td><td class="r">' + fmt(s.omzet) + '</td><td class="r">100%</td></tr>'])));
    if (d.by_type.length) html += sec('Per Tipe Pesanan', t(['Tipe', '>Pesanan', '>Batal', '>Omzet'], d.by_type.map(function (x) {
      return '<tr><td>' + esc(x.order_type) + '</td><td class="r">' + num(x.orders_valid) + '</td><td class="r">' + num(x.cancelled) + '</td><td class="r">' + fmt(x.omzet) + '</td></tr>';
    })));
    if (d.by_day.length > 1) html += sec('Per Hari', t(['Tanggal', '>Pesanan', '>Batal', '>Omzet'], d.by_day.map(function (x) {
      return '<tr><td>' + esc(dayName(x.date) + ', ' + dmy(x.date)) + '</td><td class="r">' + num(x.orders_valid) + '</td><td class="r">' + num(x.cancelled) + '</td><td class="r">' + fmt(x.omzet) + '</td></tr>';
    })));
    if (d.by_menu.length) html += sec('Penjualan per Menu (sebelum Tax & Service)', t(['#', 'Menu', 'Kode VHP', 'Kategori', '>Qty', '>Penjualan'], d.by_menu.map(function (m, i) {
      return '<tr><td>' + (i + 1) + '</td><td>' + esc(m.menu_name) + '</td><td>' + esc(m.vhp_code || '-') + '</td><td>' + esc(m.category) + '</td><td class="r">' + num(m.qty) + '</td><td class="r">' + fmt(m.sales) + '</td></tr>';
    })));
    var hrs = d.by_hour.filter(function (h) { return h.orders > 0; });
    if (hrs.length) html += sec('Per Jam', t(['Jam', '>Pesanan', '>Omzet'], hrs.map(function (h) {
      var hh = ('0' + h.hour).slice(-2); return '<tr><td>' + hh + ':00–' + hh + ':59</td><td class="r">' + num(h.orders) + '</td><td class="r">' + fmt(h.omzet) + '</td></tr>';
    })));
    if (d.by_staff.length) html += sec('Per Staff VHP', t(['Staff', '>Pesanan', '>Omzet'], d.by_staff.map(function (x) {
      return '<tr><td>' + esc(x.staff) + '</td><td class="r">' + num(x.orders) + '</td><td class="r">' + fmt(x.omzet) + '</td></tr>';
    })));
    if (d.cancel_reasons.length) html += sec('Alasan Pembatalan', t(['Alasan', '>Jumlah', '>Nilai'], d.cancel_reasons.map(function (x) {
      return '<tr><td>' + esc(x.reason) + '</td><td class="r">' + num(x.count) + '</td><td class="r">' + fmt(x.value) + '</td></tr>';
    })));
    html += '<div class="rp-sign"><div>Dibuat oleh,<br><br><br>( ' + esc(d.generated_by) + ' )</div><div>Diperiksa oleh,<br><br><br>( ........................ )</div><div>Mengetahui,<br><br><br>( FBM / Accounting )</div></div>';
    return html;
  }

  /* ---------- daftar transaksi ---------- */
  function loadTransactions(body) {
    var r = state.report;
    api('api/orders.php?scope=report&' + r.qs()).then(function (d) {
      r.data = d;
      var s = d.summary;
      var html = '<div class="grid grid-4">' +
        '<div class="kpi gold"><div class="l">Omzet (tanpa batal)</div><div class="v" style="font-size:20px">' + fmt(s.omzet) + '</div></div>' +
        '<div class="kpi blue"><div class="l">Jumlah Pesanan</div><div class="v">' + num(s.count) + '</div></div>' +
        '<div class="kpi green"><div class="l">Subtotal / Tax&Svc</div><div class="v" style="font-size:16px">' + fmt(s.subtotal) + '<br>' + fmt(s.tax_service) + '</div></div>' +
        '<div class="kpi red"><div class="l">Dibatalkan</div><div class="v">' + num(s.cancelled) + '</div></div></div>';
      if (!d.orders.length) { body.innerHTML = html + '<div class="empty" style="margin-top:14px">Tidak ada transaksi.</div>'; return; }
      html += '<div class="table-wrap" style="margin-top:14px">' + (d.truncated ? '<div class="banner warn" style="margin:10px">Menampilkan 1.000 transaksi terbaru. Gunakan Transaksi CSV untuk data lengkap.</div>' : '') +
        '<table class="table"><thead><tr><th>Waktu</th><th>Kode</th><th>Outlet</th><th>Tipe/Kamar</th><th>Tamu</th><th>Item</th><th class="right">Total</th><th>Status</th><th>VHP</th></tr></thead><tbody>' +
        d.orders.map(function (o) {
          return '<tr><td class="nowrap">' + esc(dmy(o.created_at)) + '<br><span class="muted">' + esc(hm(o.created_at)) + '</span></td>' +
            '<td class="nowrap"><a href="#" data-act="detail" data-id="' + o.id + '">' + esc(o.order_code) + '</a></td>' +
            '<td>' + esc(o.outlet_name) + '</td>' +
            '<td>' + esc(o.order_type === 'Room Service' ? 'Kamar ' + (o.room_number || '-') : o.order_type) + '</td>' +
            '<td>' + esc(o.guest_name) + '<br><span class="muted small">' + esc(o.guest_phone) + '</span></td>' +
            '<td class="small">' + o.items.map(function (it) { return esc(it.qty + '× ' + it.menu_name); }).join('<br>') + '</td>' +
            '<td class="right nowrap bold">' + fmt(o.grand_total) + '</td>' +
            '<td>' + statusBadge(o.status) + (o.cancel_reason ? '<div class="tiny muted">' + esc(o.cancel_reason) + '</div>' : '') + '</td>' +
            '<td class="small">' + esc(o.vhp_bill_no || '-') + '<br><span class="muted">' + esc(o.vhp_staff_name || '') + '</span></td></tr>';
        }).join('') + '</tbody></table></div>';
      body.innerHTML = html;
      $$('a[data-act=detail]', body).forEach(function (a) { a.onclick = function (e) { e.preventDefault(); var o = findOrder(Number(a.getAttribute('data-id'))); if (o) showOrderDetail(o); }; });
    }).catch(function (e) { body.innerHTML = '<div class="banner warn">' + esc(e.message) + '</div>'; });
  }

  /* ================================================================== */
  /* PENGATURAN ADMIN (WhatsApp Fonnte + info sistem)                   */
  /* ================================================================== */
  function viewSettings(v) {
    v.innerHTML = '<h2 class="section-title">Pengaturan Admin</h2><div id="sBody"><div class="skeleton" style="height:160px"></div></div>';
    api('api/settings.php').then(function (d) { renderSettings(d); })
      .catch(function (e) { $('#sBody').innerHTML = '<div class="banner warn">' + esc(e.message) + '</div>'; });
  }

  function renderSettings(d) {
    var wa = d.wa, sys = d.system, body = $('#sBody');
    if (!body) return;
    var sendRate = wa.stats_7d.total ? Math.round(wa.stats_7d.ok * 100 / wa.stats_7d.total) + '% berhasil' : 'belum ada kiriman';
    body.innerHTML =
      '<div class="card stack">' +
        '<div class="row wrap"><h3 class="spacer" style="margin:0">💬 Notifikasi WhatsApp Outlet (Fonnte)</h3>' +
          '<span class="badge ' + (wa.enabled ? 'green' : 'grey') + '">' + (wa.enabled ? 'AKTIF' : 'NONAKTIF') + '</span></div>' +
        '<p class="small muted" style="margin:0">Opsional. Setiap pesanan baru akan dikirim juga ke WhatsApp outlet (nomor HP atau grup WA) lewat ' +
          '<a href="https://fonnte.com" target="_blank" rel="noopener">fonnte.com</a>. Notifikasi push & alarm dashboard tetap berjalan seperti biasa. ' +
          '7 hari terakhir: ' + num(wa.stats_7d.total) + ' kiriman, ' + esc(sendRate) + '.</p>' +
        '<label class="row" style="gap:10px;cursor:pointer"><input type="checkbox" id="waEnabled"' + (wa.enabled ? ' checked' : '') + ' style="width:20px;height:20px"> <b>Aktifkan notifikasi WhatsApp</b></label>' +
        '<label class="field"><span>Token Device Fonnte' + (wa.has_token ? ' — tersimpan: <code>' + esc(wa.token_hint) + '</code>' : '') + '</span>' +
          '<div class="row" style="gap:8px"><input class="input" id="waToken" type="password" autocomplete="off" placeholder="' + (wa.has_token ? 'Kosongkan jika tidak diganti' : 'Tempel token dari menu Device di fonnte.com') + '" style="flex:1">' +
          (wa.has_token ? '<button class="btn ghost sm" id="waDevice" type="button">Cek Device</button>' : '') + '</div></label>' +
        (wa.has_token ? '<label class="row small" style="gap:8px"><input type="checkbox" id="waClear"> Hapus token tersimpan</label>' : '') +
        '<div id="waDeviceInfo"></div>' +
        '<div><div class="small bold" style="margin-bottom:6px">Nomor / Grup WhatsApp per Outlet</div>' +
          '<div class="tiny muted" style="margin-bottom:8px">Pisahkan dengan koma untuk lebih dari satu tujuan. Contoh: <code>081234567890, 0813xxxx</code> atau ID grup <code>120363xxxxxxxx@g.us</code> (lihat di menu Group pada Fonnte).</div>' +
          '<div class="stack">' + OUTLETS.map(function (o, i) {
            return '<div class="row wrap" style="gap:8px;align-items:center">' +
              '<label class="row" style="gap:6px;min-width:200px"><input type="checkbox" data-oon="' + i + '"' + (wa.outlet_enabled[o] ? ' checked' : '') + '> <b class="small">' + esc(o) + '</b></label>' +
              '<input class="input sm" data-otg="' + i + '" style="flex:1;min-width:220px" value="' + esc(wa.targets[o] || '') + '" placeholder="Nomor WA kasir / grup ' + esc(o) + '">' +
              '<button class="btn ghost sm" data-otest="' + i + '" type="button">Kirim Uji</button></div>';
          }).join('') + '</div></div>' +
        '<label class="row small" style="gap:8px"><input type="checkbox" id="waCancel"' + (wa.notify_cancel ? ' checked' : '') + '> Kirim juga WhatsApp saat pesanan <b>dibatalkan</b></label>' +
        '<label class="field"><span>Template Pesan</span><textarea class="input" id="waTpl" rows="12" style="font-family:ui-monospace,Consolas,monospace;font-size:13px">' + esc(wa.template) + '</textarea></label>' +
        '<div class="tiny muted">Kode isian: ' + wa.placeholders.map(function (p) { return '<code data-ph="' + esc(p) + '" style="cursor:pointer">' + esc(p) + '</code>'; }).join(' ') +
          ' · Format WA: <code>*tebal*</code> <code>_miring_</code>. <a href="#" id="waReset">Kembalikan template bawaan</a></div>' +
        '<div class="error-text" id="waErr" hidden></div>' +
        '<div class="row wrap"><span class="spacer"></span><button class="btn gold" id="waSave">💾 Simpan Pengaturan WhatsApp</button></div>' +
        '<div class="banner info tiny">Catatan: simpan dulu sebelum "Kirim Uji". Kirim uji memakai token & template tersimpan dengan contoh pesanan dummy.</div>' +
      '</div>' +

      '<div class="card" style="margin-top:14px"><div class="row" style="margin-bottom:8px"><h3 class="spacer" style="margin:0;font-size:16px">Riwayat Kiriman WhatsApp (30 terakhir)</h3><button class="btn ghost sm" id="waReload">↻ Muat ulang</button></div>' +
        (d.wa_logs.length ? '<div class="table-wrap"><table class="table"><thead><tr><th>Waktu</th><th>Kode</th><th>Outlet</th><th>Tujuan</th><th>Jenis</th><th>Hasil</th></tr></thead><tbody>' +
          d.wa_logs.map(function (l) {
            return '<tr><td class="nowrap small">' + esc(dmy(l.created_at) + ' ' + hm(l.created_at)) + '</td><td class="nowrap">' + esc(l.order_code || '-') + '</td><td class="small">' + esc(l.outlet_name || '-') + '</td>' +
              '<td class="small">' + esc(l.target) + '</td><td class="small">' + esc({ new_order: 'Pesanan baru', cancel: 'Pembatalan', test: 'Uji coba' }[l.event] || l.event) + '</td>' +
              '<td><span class="badge ' + (l.success ? 'green">✔ Terkirim' : 'red">✖ Gagal') + '</span><div class="tiny muted">' + esc(l.response || '') + '</div></td></tr>';
          }).join('') + '</tbody></table></div>' : '<div class="empty small">Belum ada kiriman WhatsApp.</div>') +
      '</div>' +

      '<div class="card" style="margin-top:14px"><h3 style="margin:0 0 8px;font-size:16px">🖥️ Info Sistem</h3><div class="table-wrap"><table class="table"><tbody>' +
        [['Versi aplikasi', sys.app_version], ['URL aplikasi', sys.app_url], ['PHP', sys.php_version], ['Jam server', dmy(sys.server_time) + ' ' + hm(sys.server_time) + ' (' + sys.timezone + ')'],
         ['HTTPS', sys.https ? '✔ Aktif' : '✖ Tidak aktif (push & PWA butuh HTTPS)'], ['Ekstensi curl', sys.curl ? '✔' : '✖ (WhatsApp & push tidak bisa kirim)'],
         ['Ekstensi GD', sys.gd ? '✔' : '✖ (upload foto tidak bisa)'], ['HP staf terdaftar notifikasi push', num(sys.push_devices) + ' perangkat']]
          .map(function (r) { return '<tr><td class="muted" style="width:40%">' + esc(r[0]) + '</td><td>' + esc(r[1]) + '</td></tr>'; }).join('') +
      '</tbody></table></div></div>';

    function collect() {
      var targets = {}, on = {};
      OUTLETS.forEach(function (o, i) { targets[o] = $('[data-otg="' + i + '"]').value.trim(); on[o] = $('[data-oon="' + i + '"]').checked; });
      return {
        action: 'save_wa', enabled: $('#waEnabled').checked, token: $('#waToken').value.trim(),
        clear_token: !!($('#waClear') && $('#waClear').checked),
        targets: targets, outlet_enabled: on, notify_cancel: $('#waCancel').checked, template: $('#waTpl').value,
      };
    }
    $('#waSave').onclick = function () {
      var btn = this, err = $('#waErr'); err.hidden = true; busy(btn, true);
      api('api/settings.php', { method: 'POST', body: collect() }).then(function () {
        toast('Pengaturan WhatsApp disimpan.', 'ok'); viewSettings($('#view'));
      }).catch(function (e) { busy(btn, false); err.textContent = e.message; err.hidden = false; });
    };
    $$('[data-otest]').forEach(function (b) {
      b.onclick = function () {
        var i = Number(b.getAttribute('data-otest')), target = $('[data-otg="' + i + '"]').value.trim();
        if (!target) { toast('Isi nomor WhatsApp ' + OUTLETS[i] + ' dulu.', 'err'); return; }
        busy(b, true);
        api('api/settings.php', { method: 'POST', body: { action: 'test_wa', outlet: OUTLETS[i], target: target } })
          .then(function (r) { toast('Pesan uji dikirim ke ' + r.targets.join(', ') + '.', 'ok'); })
          .catch(function (e) { toast(e.message, 'err'); })
          .then(function () { busy(b, false); });
      };
    });
    if ($('#waDevice')) $('#waDevice').onclick = function () {
      var b = this; busy(b, true);
      api('api/settings.php', { method: 'POST', body: { action: 'device_wa' } }).then(function (r) {
        var dv = r.device || {}, rows = Object.keys(dv).map(function (k) { return esc(k) + ': <b>' + esc(dv[k]) + '</b>'; }).join(' · ');
        $('#waDeviceInfo').innerHTML = '<div class="banner ' + (String(dv.device_status || '').toLowerCase() === 'connect' ? 'info' : 'warn') + ' small">' + (rows || 'Device terhubung.') + '</div>';
      }).catch(function (e) { $('#waDeviceInfo').innerHTML = '<div class="banner warn small">' + esc(e.message) + '</div>'; })
        .then(function () { busy(b, false); });
    };
    $('#waReset').onclick = function (e) { e.preventDefault(); $('#waTpl').value = wa.default_template; };
    $$('[data-ph]').forEach(function (c) {
      c.onclick = function () {
        var ta = $('#waTpl'), p = c.getAttribute('data-ph'), s = ta.selectionStart || ta.value.length;
        ta.value = ta.value.slice(0, s) + p + ta.value.slice(ta.selectionEnd || s); ta.focus(); ta.selectionStart = ta.selectionEnd = s + p.length;
      };
    });
    $('#waReload').onclick = function () { viewSettings($('#view')); };
  }

  /* ================================================================== */
  /* CMS MENU                                                           */
  /* ================================================================== */
  function viewMenu(v) {
    var f = state.menuFilter;
    v.innerHTML = '<div class="row wrap" style="margin-top:16px"><h2 class="section-title spacer" style="margin:0">Master Menu</h2>' +
      (canMenu() ? '<button class="btn gold" id="mAdd">+ Tambah Menu</button>' : '') + '</div>' +
      '<div class="filters"><input class="input sm" id="mQ" placeholder="Cari nama / kode VHP" value="' + esc(f.q) + '" style="flex:1;min-width:200px">' +
        '<select class="input sm" id="mOutlet">' + outletOptions(f.outlet, true, 'Semua Outlet') + '</select>' +
        '<select class="input sm" id="mCat"><option value="ALL">Semua Kategori</option>' + CATEGORIES.map(function (c) { return '<option' + (f.cat === c ? ' selected' : '') + '>' + esc(c) + '</option>'; }).join('') + '</select></div>' +
      '<div class="table-wrap" id="mTable"><div style="padding:16px"><div class="skeleton" style="height:80px"></div></div></div>';
    if (canMenu()) $('#mAdd').onclick = function () { menuForm(null); };
    $('#mQ').oninput = function (e) { f.q = e.target.value; renderMenuTable(); };
    $('#mOutlet').onchange = function (e) { f.outlet = e.target.value; renderMenuTable(); };
    $('#mCat').onchange = function (e) { f.cat = e.target.value; renderMenuTable(); };
    loadMenus();
  }
  function loadMenus() {
    return api('api/menus.php').then(function (d) { state.menus = d.menus; renderMenuTable(); })
      .catch(function (e) { var t = $('#mTable'); if (t) t.innerHTML = '<div class="banner warn" style="margin:10px">' + esc(e.message) + '</div>'; });
  }
  function renderMenuTable() {
    var t = $('#mTable');
    if (!t) return;
    var f = state.menuFilter, q = f.q.trim().toLowerCase();
    var list = state.menus.filter(function (m) {
      if (f.outlet !== 'ALL' && m.outlets.indexOf(f.outlet) === -1) return false;
      if (f.cat !== 'ALL' && m.category !== f.cat) return false;
      if (q && (m.name + ' ' + (m.vhp_code || '')).toLowerCase().indexOf(q) === -1) return false;
      return true;
    });
    if (!list.length) { t.innerHTML = '<div class="empty">Belum ada menu.</div>'; return; }
    t.innerHTML = '<table class="table"><thead><tr><th></th><th>Menu</th><th>Kategori</th><th class="right">Harga Dasar</th><th>Tax&Svc</th><th>Outlet & Harga Khusus</th><th>Tersedia</th><th></th></tr></thead><tbody>' +
      list.map(function (m) {
        var outlets = m.outlets.map(function (o) {
          var cp = m.outlet_prices[o];
          return '<div class="tiny nowrap">• ' + esc(o) + (cp != null ? ' <b>' + fmt(cp) + '</b>' : '') + '</div>';
        }).join('');
        return '<tr><td>' + (m.image_url ? '<img class="thumb-sm" src="' + esc(m.image_url) + '" alt="" loading="lazy">' : '<div class="thumb-sm">🍽️</div>') + '</td>' +
          '<td><div class="bold">' + esc(m.name) + '</div><div class="tiny muted">' + esc(m.vhp_code || '—') + '</div></td>' +
          '<td class="small">' + esc(m.category) + '</td>' +
          '<td class="right nowrap">' + fmt(m.base_price) + '</td>' +
          '<td class="small">' + esc(+m.tax_service_rate) + '%</td>' +
          '<td>' + outlets + '</td>' +
          '<td><label class="switch"><input type="checkbox" data-avail="' + m.id + '"' + (m.is_available ? ' checked' : '') + '><span class="sl"></span></label>' +
            '<div class="tiny ' + (m.is_available ? 'muted' : '') + '" style="color:' + (m.is_available ? '' : 'var(--red)') + '">' + (m.is_available ? 'Tersedia' : 'Habis') + '</div></td>' +
          '<td class="nowrap">' + (canMenu()
            ? '<button class="btn ghost xs" data-edit="' + m.id + '">Edit</button> ' +
              (m.outlets.length < OUTLETS.length ? '<button class="btn ghost xs" data-copy="' + m.id + '">Copy</button> ' : '') +
              '<button class="btn ghost xs" data-del="' + m.id + '" style="color:var(--red)">Hapus</button>'
            : '') + '</td></tr>';
      }).join('') + '</tbody></table>';
    $$('[data-avail]', t).forEach(function (cb) {
      cb.onchange = function () {
        var id = Number(cb.getAttribute('data-avail'));
        cb.disabled = true;
        api('api/menus.php', { method: 'PATCH', body: { id: id, is_available: cb.checked ? 1 : 0 } })
          .then(function (d) { var m = menuById(id); if (m) m.is_available = d.is_available; toast(d.is_available ? 'Menu tersedia.' : 'Menu ditandai Habis / Sold Out.', 'ok'); renderMenuTable(); })
          .catch(function (e) { cb.checked = !cb.checked; cb.disabled = false; toast(e.message, 'err'); });
      };
    });
    $$('[data-edit]', t).forEach(function (b) { b.onclick = function () { menuForm(menuById(Number(b.getAttribute('data-edit')))); }; });
    $$('[data-copy]', t).forEach(function (b) { b.onclick = function () { copyForm(menuById(Number(b.getAttribute('data-copy')))); }; });
    $$('[data-del]', t).forEach(function (b) {
      b.onclick = function () {
        var m = menuById(Number(b.getAttribute('data-del')));
        confirmBox('Hapus Menu', 'Hapus "' + m.name + '" dari semua outlet? Riwayat pesanan lama tetap tersimpan.', 'Hapus').then(function (ok) {
          if (!ok) return;
          api('api/menus.php', { method: 'DELETE', body: { id: m.id } }).then(function () { toast('Menu dihapus.', 'ok'); loadMenus(); }).catch(function (e) { toast(e.message, 'err'); });
        });
      };
    });
  }
  function menuById(id) { return state.menus.filter(function (m) { return m.id === id; })[0] || null; }

  /** Kompres gambar di browser sebelum upload (hemat kuota & hindari limit upload hosting). */
  function compressImage(file, maxW, asPng) {
    return new Promise(function (resolve) {
      if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { resolve(file); return; }
      var img = new Image();
      var url = URL.createObjectURL(file);
      img.onload = function () {
        URL.revokeObjectURL(url);
        var scale = Math.min(1, maxW / img.naturalWidth);
        var c = document.createElement('canvas');
        c.width = Math.round(img.naturalWidth * scale); c.height = Math.round(img.naturalHeight * scale);
        var ctx = c.getContext('2d');
        if (!asPng) { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); }
        ctx.drawImage(img, 0, 0, c.width, c.height);
        c.toBlob(function (b) {
          if (!b || b.size > file.size && scale === 1) { resolve(file); return; }
          resolve(new File([b], asPng ? 'image.png' : 'image.jpg', { type: asPng ? 'image/png' : 'image/jpeg' }));
        }, asPng ? 'image/png' : 'image/jpeg', 0.85);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }
  function uploadImage(file, type) {
    return compressImage(file, type === 'menu' ? 1200 : 600, type === 'qr_logo').then(function (f) {
      if (f.size > 3 * 1024 * 1024) throw new Error('Ukuran file maksimal 3 MB.');
      var fd = new FormData();
      fd.append('type', type);
      fd.append('file', f, f.name || 'image.jpg');
      return api('api/upload.php', { method: 'POST', form: fd });
    });
  }

  function menuForm(m) {
    var isNew = !m;
    m = m || { name: '', vhp_code: '', category: 'Makanan Utama', base_price: '', tax_service_rate: 21, description: '', image_url: '', is_available: true, outlets: [], outlet_prices: {} };
    var outletRows = OUTLETS.map(function (o, i) {
      var on = m.outlets.indexOf(o) !== -1;
      var cp = m.outlet_prices[o];
      return '<div class="outlet-row"><input type="checkbox" id="oc' + i + '" data-o="' + esc(o) + '"' + (on ? ' checked' : '') + ' style="width:18px;height:18px">' +
        '<label for="oc' + i + '">' + esc(o) + '</label>' +
        '<input class="input sm" data-cp="' + esc(o) + '" type="number" min="0" step="500" inputmode="numeric" placeholder="Ikut harga dasar" value="' + (cp != null ? esc(cp) : '') + '"></div>';
    }).join('');
    var w = modal('<div class="modal-head"><h3>' + (isNew ? 'Tambah Menu' : 'Edit Menu') + '</h3><button class="icon-btn" data-close>✕</button></div>' +
      '<div class="modal-body stack">' +
        '<div class="grid grid-2">' +
          '<label class="field"><span>Nama Menu *</span><input class="input" id="fName" maxlength="150" value="' + esc(m.name) + '"></label>' +
          '<label class="field"><span>Kode Menu VHP</span><input class="input" id="fVhp" maxlength="30" value="' + esc(m.vhp_code || '') + '"></label>' +
          '<label class="field"><span>Kategori</span><select class="input" id="fCat">' + CATEGORIES.map(function (c) { return '<option' + (m.category === c ? ' selected' : '') + '>' + esc(c) + '</option>'; }).join('') + '</select></label>' +
          '<label class="field"><span>Harga Dasar (Rp) *</span><input class="input" id="fPrice" type="number" min="0" step="500" inputmode="numeric" value="' + esc(m.base_price) + '"></label>' +
        '</div>' +
        '<div><span class="label">Tax & Service (%)</span><div class="row wrap">' +
          '<div class="seg" id="fRateSeg">' + [21, 10, 0].map(function (r) { return '<button type="button" data-rate="' + r + '"' + (+m.tax_service_rate === r ? ' class="active"' : '') + '>' + r + '%</button>'; }).join('') + '</div>' +
          '<input class="input sm" id="fRate" type="number" min="0" max="100" step="0.5" style="width:100px" value="' + esc(+m.tax_service_rate) + '"></div></div>' +
        '<label class="field"><span>Deskripsi</span><textarea class="input" id="fDesc" maxlength="2000">' + esc(m.description || '') + '</textarea></label>' +
        '<div><span class="label">Foto Menu</span><div class="row wrap" style="align-items:flex-start">' +
          '<img class="preview-img" id="fImgPrev" src="' + esc(m.image_url || '') + '" alt=""' + (m.image_url ? '' : ' hidden') + ' style="max-width:120px">' +
          '<div class="stack"><input type="file" id="fImg" accept="image/jpeg,image/png,image/webp">' +
          '<div class="hint">JPG/PNG/WEBP, maks 3 MB. Otomatis dikompres & di-resize (lebar maks 800px).</div>' +
          '<button type="button" class="btn ghost xs" id="fImgDel"' + (m.image_url ? '' : ' hidden') + '>Hapus foto</button></div></div></div>' +
        '<div><span class="label">Aktif di Outlet * & Harga Khusus</span><div class="card flat" style="padding:4px 12px">' + outletRows + '</div>' +
          '<div class="hint">Kosongkan harga khusus agar outlet mengikuti harga dasar.</div></div>' +
        '<label class="check"><input type="checkbox" id="fAvail"' + (m.is_available ? ' checked' : '') + '> Tersedia (tidak habis)</label>' +
        '<div class="error-text" id="fErr" hidden></div>' +
      '</div>' +
      '<div class="modal-foot"><button class="btn ghost" data-close>Batal</button><button class="btn gold" id="fSave">Simpan Menu</button></div>', { wide: true });

    var imageUrl = m.image_url || '';
    $$('[data-rate]', w).forEach(function (b) {
      b.onclick = function () { $('#fRate', w).value = b.getAttribute('data-rate'); $$('[data-rate]', w).forEach(function (x) { x.classList.toggle('active', x === b); }); };
    });
    $('#fRate', w).oninput = function () { var v = Number(this.value); $$('[data-rate]', w).forEach(function (x) { x.classList.toggle('active', Number(x.getAttribute('data-rate')) === v); }); };
    $('#fImg', w).onchange = function () {
      var file = this.files[0];
      if (!file) return;
      var inp = this; inp.disabled = true;
      $('#fSave', w).disabled = true;
      uploadImage(file, 'menu').then(function (d) {
        imageUrl = d.url; $('#fImgPrev', w).src = d.url; $('#fImgPrev', w).hidden = false; $('#fImgDel', w).hidden = false; toast('Foto terupload.', 'ok');
      }).catch(function (e) { toast(e.message, 'err'); }).then(function () { inp.disabled = false; inp.value = ''; $('#fSave', w).disabled = false; });
    };
    $('#fImgDel', w).onclick = function () { imageUrl = ''; $('#fImgPrev', w).hidden = true; this.hidden = true; };
    $('#fSave', w).onclick = function () {
      var err = $('#fErr', w);
      var outlets = $$('[data-o]', w).filter(function (c) { return c.checked; }).map(function (c) {
        var o = c.getAttribute('data-o');
        var cp = $('[data-cp="' + o + '"]', w).value.trim();
        return { outlet_name: o, custom_price: cp === '' ? null : Number(cp) };
      });
      var body = {
        id: isNew ? 0 : m.id, name: $('#fName', w).value.trim(), vhp_code: $('#fVhp', w).value.trim(), category: $('#fCat', w).value,
        base_price: $('#fPrice', w).value, tax_service_rate: $('#fRate', w).value, description: $('#fDesc', w).value.trim(),
        image_url: imageUrl, is_available: $('#fAvail', w).checked ? 1 : 0, outlets: outlets,
      };
      err.hidden = true;
      if (body.name.length < 2) { err.textContent = 'Nama menu wajib diisi.'; err.hidden = false; return; }
      if (body.base_price === '' || Number(body.base_price) < 0) { err.textContent = 'Harga dasar wajib diisi.'; err.hidden = false; return; }
      if (!outlets.length) { err.textContent = 'Centang minimal 1 outlet.'; err.hidden = false; return; }
      var btn = this; busy(btn, true);
      api('api/menus.php', { method: 'POST', body: body }).then(function () {
        closeModal(w); toast('Menu disimpan.', 'ok'); loadMenus();
      }).catch(function (e) { busy(btn, false); err.textContent = e.message; err.hidden = false; });
    };
  }

  function copyForm(m) {
    var missing = OUTLETS.filter(function (o) { return m.outlets.indexOf(o) === -1; });
    var w = modal('<div class="modal-head"><h3>Copy Menu to Outlet</h3><button class="icon-btn" data-close>✕</button></div>' +
      '<div class="modal-body stack"><p class="mt-0"><b>' + esc(m.name) + '</b> · harga dasar ' + fmt(m.base_price) + '</p>' +
      '<div class="card flat" style="padding:4px 12px">' + missing.map(function (o, i) {
        return '<div class="outlet-row"><input type="checkbox" id="cc' + i + '" data-o="' + esc(o) + '" checked style="width:18px;height:18px"><label for="cc' + i + '">' + esc(o) + '</label>' +
          '<input class="input sm" data-cp="' + esc(o) + '" type="number" min="0" step="500" placeholder="Ikut harga dasar"></div>';
      }).join('') + '</div><div class="hint">Isi harga khusus bila harga di outlet tujuan berbeda.</div></div>' +
      '<div class="modal-foot"><button class="btn ghost" data-close>Batal</button><button class="btn gold" id="cSave">Salin Menu</button></div>');
    $('#cSave', w).onclick = function () {
      var targets = $$('[data-o]', w).filter(function (c) { return c.checked; }).map(function (c) {
        var o = c.getAttribute('data-o'), cp = $('[data-cp="' + o + '"]', w).value.trim();
        return { outlet_name: o, custom_price: cp === '' ? null : Number(cp) };
      });
      if (!targets.length) { toast('Pilih minimal 1 outlet tujuan.', 'err'); return; }
      var btn = this; busy(btn, true);
      api('api/menus.php?action=copy_outlet', { method: 'POST', body: { menu_id: m.id, target_outlets: targets } })
        .then(function (d) { closeModal(w); toast('Menu disalin ke ' + d.added + ' outlet.', 'ok'); loadMenus(); })
        .catch(function (e) { busy(btn, false); toast(e.message, 'err'); });
    };
  }

  /* ================================================================== */
  /* JAM OPERASIONAL                                                    */
  /* ================================================================== */
  function viewHours(v) {
    v.innerHTML = '<h2 class="section-title">Jam Operasional Outlet</h2><p class="muted small" style="margin-top:-6px">Zona waktu WIB (Asia/Jakarta). Jam tutup lebih kecil dari jam buka = buka melewati tengah malam.</p>' +
      '<div class="grid grid-2" id="hGrid"><div class="skeleton" style="height:160px"></div></div>';
    api('api/outlets.php').then(function (d) { state.outletCfg = d.outlets; state.hoursServerTime = d.server_time; renderHours(); }).catch(function (e) { $('#hGrid').innerHTML = '<div class="banner warn">' + esc(e.message) + '</div>'; });
  }
  function renderHours() {
    var serverTime = state.hoursServerTime;
    var g = $('#hGrid');
    if (!g) return;
    g.innerHTML = state.outletCfg.map(function (o, i) {
      return '<div class="card" data-i="' + i + '"><div class="row"><h3 class="spacer" style="margin:0;font-size:16px">' + esc(o.outlet_name) + '</h3>' +
        (o.is_open ? '<span class="badge green">Buka</span>' : '<span class="badge red">Tutup</span>') + '</div>' +
        '<div class="grid grid-2" style="margin-top:12px">' +
          '<label class="field"><span>Jam Buka</span><input class="input" type="time" data-f="open_time" value="' + esc(o.open_time) + '"' + (o.is_24_hours ? ' disabled' : '') + '></label>' +
          '<label class="field"><span>Jam Tutup</span><input class="input" type="time" data-f="close_time" value="' + esc(o.close_time) + '"' + (o.is_24_hours ? ' disabled' : '') + '></label>' +
        '</div>' +
        '<div class="row wrap" style="margin-top:12px;gap:18px">' +
          '<label class="check"><input type="checkbox" data-f="is_24_hours"' + (o.is_24_hours ? ' checked' : '') + '> Buka 24 Jam</label>' +
          '<label class="row small"><span class="switch red"><input type="checkbox" data-f="is_force_closed"' + (o.is_force_closed ? ' checked' : '') + '><span class="sl"></span></span> Tutup Sementara (Force Closed)</label>' +
        '</div>' +
        '<button class="btn sm" style="margin-top:14px" data-save>Simpan</button></div>';
    }).join('') + '<div class="small muted">Waktu server: ' + esc(serverTime || '') + '</div>';
    $$('.card[data-i]', g).forEach(function (card) {
      var i = Number(card.getAttribute('data-i'));
      $('[data-f=is_24_hours]', card).onchange = function () { var on = this.checked; $$('input[type=time]', card).forEach(function (x) { x.disabled = on; }); };
      $('[data-save]', card).onclick = function () {
        var btn = this;
        var o = state.outletCfg[i];
        var body = {
          outlet_name: o.outlet_name, open_time: $('[data-f=open_time]', card).value, close_time: $('[data-f=close_time]', card).value,
          is_24_hours: $('[data-f=is_24_hours]', card).checked ? 1 : 0, is_force_closed: $('[data-f=is_force_closed]', card).checked ? 1 : 0,
        };
        if (!body.is_24_hours && (!body.open_time || !body.close_time)) { toast('Isi jam buka & tutup.', 'err'); return; }
        busy(btn, true);
        api('api/outlets.php', { method: 'POST', body: { outlets: [body] } }).then(function (d) {
          state.outletCfg = d.outlets; renderHours(); toast('Jam operasional ' + o.outlet_name + ' disimpan.', 'ok');
        }).catch(function (e) { busy(btn, false); toast(e.message, 'err'); });
      };
    });
  }

  /* ================================================================== */
  /* QR CODE GENERATOR & EDITOR                                         */
  /* ================================================================== */
  var qrLibPromise = null;
  function loadQrLib() {
    if (window.qrcode) return Promise.resolve();
    if (qrLibPromise) return qrLibPromise;
    qrLibPromise = new Promise(function (resolve, reject) {
      var i = 0;
      (function next() {
        if (i >= QR_LIBS.length) { qrLibPromise = null; reject(new Error('Library QR gagal dimuat. Periksa koneksi internet.')); return; }
        var s = document.createElement('script');
        s.src = QR_LIBS[i++]; s.async = true;
        s.onload = function () { window.qrcode ? resolve() : next(); };
        s.onerror = function () { s.remove(); next(); };
        document.head.appendChild(s);
      })();
    });
    return qrLibPromise;
  }
  function qrUrl(outlet) { return state.qrBase.replace(/\/+$/, '') + '/?outlet=' + encodeURIComponent(outlet); }

  function viewQR(v) {
    v.innerHTML = '<h2 class="section-title">QR Code Generator & Editor</h2>' +
      '<div class="grid grid-2" style="align-items:start"><div class="card stack" id="qEditor"><div class="skeleton" style="height:200px"></div></div>' +
      '<div><div class="qr-stage" id="qStage"></div><div class="row wrap" style="margin-top:12px;justify-content:center">' +
        '<button class="btn" id="qPrint">🖨️ Cetak Tent Card</button><button class="btn ghost" id="qDl">⬇ Download QR (PNG)</button></div>' +
        '<p class="center small muted" id="qUrlText" style="word-break:break-all"></p></div></div>';
    Promise.all([api('api/qr_settings.php'), loadQrLib().catch(function (e) { return e; })]).then(function (res) {
      state.qr = res[0].settings; state.qrBase = res[0].base_url;
      if (res[1] instanceof Error) toast(res[1].message, 'err');
      renderQREditor();
      renderQRPreview();
    }).catch(function (e) { $('#qEditor').innerHTML = '<div class="banner warn">' + esc(e.message) + '</div>'; });
    $('#qPrint').onclick = printQR;
    $('#qDl').onclick = function () {
      var c = $('#qStage canvas:last-of-type') || $('#qStage canvas');
      if (!c) return;
      var a = document.createElement('a');
      a.download = 'QR-' + state.qrOutlet.replace(/\s+/g, '-') + '.png';
      a.href = c.toDataURL('image/png');
      document.body.appendChild(a); a.click(); a.remove();
    };
  }

  function renderQREditor() {
    var s = state.qr, ed = $('#qEditor');
    ed.innerHTML =
      '<label class="field"><span>Outlet</span><select class="input" id="qOutlet">' + outletOptions(state.qrOutlet, false) + '</select></label>' +
      '<label class="field"><span>Base URL Aplikasi</span><input class="input" id="qBase" value="' + esc(state.qrBase) + '"><div class="hint">Diambil dari setup (APP_URL). Contoh https://order.grandartoshotel.com</div></label>' +
      '<label class="field"><span>Judul Hotel <span class="muted">(opsional, kosongkan jika sudah ada logo)</span></span><input class="input" id="qHotel" maxlength="150" value="' + esc(s.hotel_title || '') + '"></label>' +
      '<label class="field"><span>Judul Outlet (khusus outlet terpilih)</span><input class="input" id="qOutletTitle" maxlength="80" value="' + esc(s.custom_outlet_titles[state.qrOutlet] || state.qrOutlet) + '"></label>' +
      '<label class="field"><span>Teks Badge Layanan</span><input class="input" id="qBadge" maxlength="80" value="' + esc(s.badge_text) + '"><div class="hint">Untuk Room Service, badge otomatis "Room Service — Delivered to Your Room" bila teks masih default.</div></label>' +
      '<label class="field"><span>Instruksi Scan (bawah)</span><textarea class="input" id="qFoot" maxlength="500">' + esc(s.footer_desc || '') + '</textarea></label>' +
      '<div><span class="label">Logo</span><div class="row wrap">' + (s.logo_url ? '<img src="' + esc(s.logo_url) + '" alt="" style="height:48px;background:#f3f3f3;border-radius:8px;padding:4px">' : '<span class="muted small">Belum ada logo</span>') +
        '<input type="file" id="qLogo" accept="image/png,image/jpeg,image/webp"></div><div class="hint">Disarankan PNG transparan. Maks 3 MB.</div></div>' +
      '<label class="check"><input type="checkbox" id="qCenter"' + (s.show_center_logo ? ' checked' : '') + '> Tampilkan logo di tengah QR (Error Correction H 30%)</label>' +
      '<label class="check"><input type="checkbox" id="qHeader"' + (s.show_header_logo ? ' checked' : '') + '> Tampilkan logo di header kartu</label>' +
      '<label class="check"><input type="checkbox" id="qTent"' + (state.qrTent ? ' checked' : '') + '> Mode Tent Card (2 sisi, lipat di tengah)</label>' +
      '<button class="btn gold" id="qSave">Simpan Pengaturan</button>';
    function sync() {
      s.hotel_title = $('#qHotel').value; s.badge_text = $('#qBadge').value; s.footer_desc = $('#qFoot').value;
      s.custom_outlet_titles[state.qrOutlet] = $('#qOutletTitle').value || state.qrOutlet;
      s.show_center_logo = $('#qCenter').checked; s.show_header_logo = $('#qHeader').checked; state.qrTent = $('#qTent').checked;
      state.qrBase = $('#qBase').value.trim() || state.qrBase;
      renderQRPreview();
    }
    $$('input:not([type=file]),textarea', ed).forEach(function (el) { el.addEventListener('input', sync); el.addEventListener('change', sync); });
    $('#qOutlet').onchange = function () { sync(); state.qrOutlet = this.value; $('#qOutletTitle').value = s.custom_outlet_titles[state.qrOutlet] || state.qrOutlet; renderQRPreview(); };
    $('#qLogo').onchange = function () {
      var f = this.files[0]; if (!f) return;
      var inp = this; inp.disabled = true;
      uploadImage(f, 'qr_logo').then(function (d) { s.logo_url = d.url; renderQREditor(); renderQRPreview(); toast('Logo terupload. Klik Simpan Pengaturan.', 'ok'); })
        .catch(function (e) { toast(e.message, 'err'); inp.disabled = false; });
    };
    $('#qSave').onclick = function () {
      sync();
      var btn = this; busy(btn, true);
      api('api/qr_settings.php', { method: 'POST', body: s }).then(function (d) { state.qr = d.settings; busy(btn, false); toast('Pengaturan QR disimpan.', 'ok'); })
        .catch(function (e) { busy(btn, false); toast(e.message, 'err'); });
    };
  }

  function drawQR(text, logoImg, px) {
    var qr = window.qrcode(0, 'H');
    qr.addData(unescape(encodeURIComponent(text))); // UTF-8 aman
    qr.make();
    var n = qr.getModuleCount(), quiet = 4, cell = Math.max(4, Math.floor(px / (n + quiet * 2)));
    var size = cell * (n + quiet * 2);
    var c = document.createElement('canvas');
    c.width = size; c.height = size;
    var ctx = c.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, size, size);
    ctx.fillStyle = '#0b192c';
    for (var r = 0; r < n; r++) for (var col = 0; col < n; col++) if (qr.isDark(r, col)) ctx.fillRect((col + quiet) * cell, (r + quiet) * cell, cell, cell);
    if (logoImg) {
      var box = Math.round(n * cell * 0.22); // ≤22% lebar QR: aman untuk level H (30%)
      var bx = Math.round((size - box) / 2), pad = Math.round(cell * 0.8);
      ctx.fillStyle = '#fff';
      ctx.fillRect(bx - pad, bx - pad, box + pad * 2, box + pad * 2);
      var ratio = Math.min(box / logoImg.naturalWidth, box / logoImg.naturalHeight);
      var lw = logoImg.naturalWidth * ratio, lh = logoImg.naturalHeight * ratio;
      ctx.drawImage(logoImg, (size - lw) / 2, (size - lh) / 2, lw, lh);
    }
    return c;
  }
  function loadImg(src) {
    return new Promise(function (resolve) {
      if (!src) { resolve(null); return; }
      var i = new Image();
      i.onload = function () { resolve(i); };
      i.onerror = function () { resolve(null); };
      i.src = src;
    });
  }
  var qrRenderSeq = 0;
  function renderQRPreview() {
    var stage = $('#qStage');
    if (!stage || !state.qr) return;
    var s = state.qr, outlet = state.qrOutlet, url = qrUrl(outlet), seq = ++qrRenderSeq;
    $('#qUrlText').textContent = url;
    if (!window.qrcode) { stage.innerHTML = '<div class="banner warn">Library QR belum termuat. Periksa koneksi internet lalu buka ulang tab ini.</div>'; return; }
    loadImg(s.logo_url).then(function (logo) {
      if (seq !== qrRenderSeq) return;
      var badge = s.badge_text;
      if (outlet === 'Room Service' && badge === 'Dine In & Take Away') badge = 'Room Service — Delivered to Your Room';
      function panel(flip) {
        return '<div class="tent-panel' + (flip ? ' flip' : '') + '">' +
          (s.show_header_logo && s.logo_url ? '<img class="t-logo" src="' + esc(s.logo_url) + '" alt="">' : '') +
          (s.hotel_title ? '<div class="t-hotel">' + esc(s.hotel_title) + '</div>' : '') +
          '<div class="t-outlet">' + esc(s.custom_outlet_titles[outlet] || outlet) + '</div>' +
          '<div class="t-badge">' + esc(badge) + '</div>' +
          '<div data-qr></div>' +
          '<div class="t-line"></div><div class="t-foot">' + esc(s.footer_desc || '') + '</div></div>';
      }
      stage.innerHTML = '<div class="tent">' + (state.qrTent ? panel(true) : '') + panel(false) + '</div>';
      $$('[data-qr]', stage).forEach(function (holder) {
        holder.replaceWith(drawQR(url, s.show_center_logo ? logo : null, 640));
      });
    });
  }
  function printQR() {
    var stage = $('#qStage');
    if (!stage || !$('canvas', stage)) { toast('Preview QR belum siap.', 'err'); return; }
    var clone = stage.cloneNode(true);
    var srcCanvases = $$('canvas', stage), dstCanvases = $$('canvas', clone);
    dstCanvases.forEach(function (c, i) { var img = new Image(); img.src = srcCanvases[i].toDataURL('image/png'); img.style.cssText = 'width:56mm;height:56mm;margin:5mm 0 4mm'; c.replaceWith(img); });
    var pa = $('#printArea');
    pa.innerHTML = '';
    pa.appendChild($('.tent', clone));
    var imgs = $$('img', pa);
    Promise.all(imgs.map(function (i) { return i.complete ? 1 : new Promise(function (r) { i.onload = i.onerror = r; }); })).then(function () {
      window.print();
      setTimeout(function () { pa.innerHTML = ''; }, 1000);
    });
  }

  /* ================================================================== */
  /* PENGGUNA                                                           */
  /* ================================================================== */
  function viewUsers(v) {
    v.innerHTML = '<div class="row wrap" style="margin-top:16px"><h2 class="section-title spacer" style="margin:0">Akun Staff</h2><button class="btn gold" id="uAdd">+ Tambah Akun</button></div>' +
      '<div class="table-wrap" style="margin-top:14px" id="uTable"><div style="padding:16px"><div class="skeleton" style="height:60px"></div></div></div>';
    $('#uAdd').onclick = function () { userForm(null); };
    loadUsers();
  }
  function loadUsers() {
    api('api/staff_users.php').then(function (d) { state.users = d.users; renderUsers(); }).catch(function (e) { $('#uTable').innerHTML = '<div class="banner warn" style="margin:10px">' + esc(e.message) + '</div>'; });
  }
  function renderUsers() {
    var t = $('#uTable'); if (!t) return;
    t.innerHTML = '<table class="table"><thead><tr><th>Username</th><th>Nama</th><th>Role</th><th>Outlet</th><th>HP Notifikasi</th><th></th></tr></thead><tbody>' +
      state.users.map(function (u) {
        return '<tr><td class="bold">' + esc(u.username) + '</td><td>' + esc(u.full_name) + '</td><td>' + '<span class="badge ' + ({ admin: 'gold', manager: 'green' }[u.role] || 'grey') + '">' + esc(roleLabel(u.role)) + '</span>' + '</td>' +
          '<td class="small">' + esc(u.assigned_outlet === 'ALL' ? 'Semua Outlet' : u.assigned_outlet) + '</td><td class="small">' + u.devices + ' perangkat</td>' +
          '<td class="nowrap"><button class="btn ghost xs" data-ue="' + u.id + '">Edit</button> ' + (u.id !== state.user.id ? '<button class="btn ghost xs" style="color:var(--red)" data-ud="' + u.id + '">Hapus</button>' : '') + '</td></tr>';
      }).join('') + '</tbody></table>';
    $$('[data-ue]', t).forEach(function (b) { b.onclick = function () { userForm(state.users.filter(function (u) { return u.id === Number(b.getAttribute('data-ue')); })[0]); }; });
    $$('[data-ud]', t).forEach(function (b) {
      b.onclick = function () {
        var u = state.users.filter(function (x) { return x.id === Number(b.getAttribute('data-ud')); })[0];
        confirmBox('Hapus Akun', 'Hapus akun "' + u.username + '"? Langganan notifikasi HP-nya juga dihapus.', 'Hapus').then(function (ok) {
          if (ok) api('api/staff_users.php', { method: 'DELETE', body: { id: u.id } }).then(function (d) { state.users = d.users; renderUsers(); toast('Akun dihapus.', 'ok'); }).catch(function (e) { toast(e.message, 'err'); });
        });
      };
    });
  }
  function userForm(u) {
    var isNew = !u;
    u = u || { username: '', full_name: '', role: 'cashier', assigned_outlet: 'ALL' };
    var w = modal('<div class="modal-head"><h3>' + (isNew ? 'Tambah Akun' : 'Edit Akun') + '</h3><button class="icon-btn" data-close>✕</button></div>' +
      '<div class="modal-body stack">' +
        '<label class="field"><span>Username</span><input class="input" id="uName" autocapitalize="none" value="' + esc(u.username) + '"></label>' +
        '<label class="field"><span>Nama Lengkap</span><input class="input" id="uFull" value="' + esc(u.full_name) + '"></label>' +
        '<div class="grid grid-2"><label class="field"><span>Role</span><select class="input" id="uRole"><option value="cashier"' + (u.role === 'cashier' ? ' selected' : '') + '>Kasir / Waiter</option><option value="manager"' + (u.role === 'manager' ? ' selected' : '') + '>FB SPV / Manager (+ kelola menu)</option><option value="admin"' + (u.role === 'admin' ? ' selected' : '') + '>Admin (CMS penuh)</option></select></label>' +
        '<label class="field"><span>Outlet</span><select class="input" id="uOutlet">' + outletOptions(u.assigned_outlet, true, 'Semua Outlet') + '</select></label></div>' +
        '<label class="field"><span>Password ' + (isNew ? '(min. 8)' : '(kosongkan bila tidak diganti)') + '</span><input class="input" id="uPass" type="password" autocomplete="new-password"></label>' +
        '<div class="error-text" id="uErr" hidden></div></div>' +
      '<div class="modal-foot"><button class="btn ghost" data-close>Batal</button><button class="btn gold" id="uSave">Simpan</button></div>');
    $('#uSave', w).onclick = function () {
      var btn = this; busy(btn, true);
      api('api/staff_users.php', { method: 'POST', body: { id: isNew ? 0 : u.id, username: $('#uName', w).value.trim(), full_name: $('#uFull', w).value.trim(), role: $('#uRole', w).value, assigned_outlet: $('#uOutlet', w).value, password: $('#uPass', w).value } })
        .then(function (d) { state.users = d.users; closeModal(w); renderUsers(); toast('Akun disimpan.', 'ok'); })
        .catch(function (e) { busy(btn, false); $('#uErr', w).textContent = e.message; $('#uErr', w).hidden = false; });
    };
  }

  /* ================================================================== */
  /* AKUN & PERANGKAT                                                   */
  /* ================================================================== */
  function viewAccount(v) {
    v.innerHTML = '<h2 class="section-title">Akun & Perangkat</h2><div class="grid grid-2" style="align-items:start">' +
      '<div class="card stack"><h3 style="font-size:16px">Ganti Password</h3>' +
        '<label class="field"><span>Password Lama</span><input class="input" type="password" id="pOld" autocomplete="current-password"></label>' +
        '<label class="field"><span>Password Baru (min. 8)</span><input class="input" type="password" id="pNew" autocomplete="new-password"></label>' +
        '<button class="btn" id="pSave">Simpan Password</button></div>' +
      '<div class="card stack"><h3 style="font-size:16px">Notifikasi & Aplikasi HP</h3>' +
        '<div class="small" id="devInfo"></div>' +
        '<button class="btn ghost" id="pTest">📨 Kirim Notifikasi Uji ke HP ini</button>' +
        '<div class="hint">Push notification butuh HTTPS. iPhone/iPad: iOS 16.4+ dan aplikasi harus di-"Add to Home Screen" terlebih dahulu.</div></div></div>';
    $('#pSave').onclick = function () {
      var btn = this; busy(btn, true);
      api('api/auth_staff.php', { method: 'POST', body: { action: 'change_password', old_password: $('#pOld').value, new_password: $('#pNew').value } })
        .then(function () { busy(btn, false); $('#pOld').value = $('#pNew').value = ''; toast('Password diubah.', 'ok'); })
        .catch(function (e) { busy(btn, false); toast(e.message, 'err'); });
    };
    $('#pTest').onclick = function () {
      var btn = this;
      getSubscription().then(function (sub) {
        if (!sub) { toast('Aktifkan "Notifikasi HP" terlebih dahulu.', 'err'); return; }
        busy(btn, true);
        return api('api/push_subscribe.php?action=test', { method: 'POST', body: { endpoint: sub.endpoint } })
          .then(function () { toast('Notifikasi uji dikirim. Cek layar HP.', 'ok'); })
          .catch(function (e) { toast(e.message, 'err'); }).then(function () { busy(btn, false); });
      });
    };
    var info = [];
    info.push('Mode aplikasi: <b>' + (isStandalone() ? 'Terinstal (PWA)' : 'Browser') + '</b>');
    info.push('HTTPS: <b>' + (location.protocol === 'https:' ? 'Ya' : 'Tidak') + '</b>');
    info.push('Izin notifikasi: <b>' + ('Notification' in window ? Notification.permission : 'tidak didukung') + '</b>');
    info.push('Push di perangkat ini: <b>' + (state.pushOn ? 'ON' : 'OFF') + '</b>');
    $('#devInfo').innerHTML = info.join('<br>');
  }

  /* ================================================================== */
  /* PWA: Service Worker, Install, Push                                 */
  /* ================================================================== */
  function registerSW() {
    if (!('serviceWorker' in navigator)) return;
    navigator.serviceWorker.register('sw.js', { scope: './' }).then(function (reg) {
      state.swReg = reg;
      reg.update().catch(function () { /* */ });
    }).catch(function (e) { console.warn('SW gagal didaftarkan:', e); });
    navigator.serviceWorker.addEventListener('message', function (ev) {
      var d = ev.data || {};
      if (d.type === 'open_order' && d.order_code) openOrderByCode(d.order_code);
      if (d.type === 'push_received') poll(true);
    });
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    state.deferredPrompt = e;
    updateInstallUI();
  });
  window.addEventListener('appinstalled', function () { state.deferredPrompt = null; updateInstallUI(); toast('Aplikasi terinstal di HP.', 'ok'); });

  function updateInstallUI() {
    var b = $('#installBtn');
    if (b) b.hidden = !(state.deferredPrompt || (isIOS() && !isStandalone()));
    var g = $('#iosGuide');
    if (g && isIOS() && !isStandalone() && !lsGet('gahc_ios_guide_hide', false)) {
      g.innerHTML = '<div class="container"><div class="ios-guide row" style="margin-top:12px">' +
        '<span class="spacer">📲 <b>iPhone/iPad:</b> Ketuk ikon Share (⬆︎) lalu pilih <b>\'Add to Home Screen\'</b> agar notifikasi aktif.</span>' +
        '<button class="icon-btn" id="iosHide">✕</button></div></div>';
      $('#iosHide').onclick = function () { lsSet('gahc_ios_guide_hide', true); g.innerHTML = ''; };
    }
  }
  function doInstall() {
    if (state.deferredPrompt) {
      state.deferredPrompt.prompt();
      state.deferredPrompt.userChoice.finally(function () { state.deferredPrompt = null; updateInstallUI(); });
    } else if (isIOS()) {
      modal('<div class="modal-head"><h3>Install di iPhone / iPad</h3><button class="icon-btn" data-close>✕</button></div>' +
        '<div class="modal-body"><ol style="padding-left:18px;margin:0;line-height:1.8"><li>Buka halaman ini di <b>Safari</b>.</li><li>Ketuk ikon <b>Share (⬆︎)</b> di bawah layar.</li>' +
        '<li>Pilih <b>\'Add to Home Screen\'</b> lalu <b>Add</b>.</li><li>Buka aplikasi <b>GAHC Kasir</b> dari Home Screen, login, lalu ketuk <b>Notifikasi HP</b>.</li></ol></div>' +
        '<div class="modal-foot"><button class="btn" data-close>Mengerti</button></div>');
    }
  }

  function urlB64ToUint8Array(b64) {
    var pad = '='.repeat((4 - (b64.length % 4)) % 4);
    var raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    var arr = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
  }
  function pushSupported() { return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window; }
  function getSubscription() {
    if (!pushSupported()) return Promise.resolve(null);
    return navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).catch(function () { return null; });
  }
  function setPushBtn(on) {
    state.pushOn = on;
    var b = $('#pushBtn');
    if (b) { b.textContent = '🔔 Notifikasi HP: ' + (on ? 'ON' : 'OFF'); b.classList.toggle('gold', on); b.classList.toggle('light', !on); }
  }
  function refreshPushState() {
    if (!pushSupported()) { setPushBtn(false); return; }
    var hasPerm = Notification.permission === 'granted';
    getSubscription().then(function (sub) {
      var on = !!sub && hasPerm && lsGet('gahc_push_on', false);
      setPushBtn(on);
      if (sub && hasPerm && on) saveSubscription(sub, lsGet('gahc_push_outlet', 'ALL'), true); // sinkron ulang (misal DB direset)
    });
  }
  function saveSubscription(sub, outlet, silent) {
    var j = sub.toJSON();
    return api('api/push_subscribe.php', {
      method: 'POST',
      body: { endpoint: j.endpoint, keys: j.keys, outlet_filter: outlet, device_label: (isStandalone() ? 'PWA ' : 'Browser ') + navigator.userAgent.slice(0, 80) },
    }).catch(function (e) { if (!silent) throw e; });
  }
  function togglePush() {
    unlockAudio();
    if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
      toast('Notifikasi HP wajib memakai HTTPS. Aktifkan SSL subdomain.', 'err'); return;
    }
    if (!pushSupported()) {
      if (isIOS() && !isStandalone()) doInstall();
      else toast('Browser ini tidak mendukung Push Notification. Gunakan Chrome (Android) / Safari iOS 16.4+.', 'err');
      return;
    }
    var btn = $('#pushBtn');
    if (state.pushOn) {
      busy(btn, true);
      getSubscription().then(function (sub) {
        if (!sub) return;
        return api('api/push_subscribe.php', { method: 'DELETE', body: { endpoint: sub.endpoint } }).catch(function () { /* */ }).then(function () { return sub.unsubscribe(); });
      }).then(function () { lsSet('gahc_push_on', false); busy(btn, false); setPushBtn(false); toast('Notifikasi HP dinonaktifkan.'); })
        .catch(function (e) { busy(btn, false); toast(e.message, 'err'); });
      return;
    }
    var lock = lockedOutlet();
    var w = modal('<div class="modal-head"><h3>Aktifkan Notifikasi HP</h3><button class="icon-btn" data-close>✕</button></div>' +
      '<div class="modal-body stack"><label class="field"><span>Outlet yang dipantau perangkat ini</span><select class="input" id="pOutlet"' + (lock ? ' disabled' : '') + '>' +
      outletOptions(lock || lsGet('gahc_push_outlet', 'ALL'), !lock) + '</select></label>' +
      '<div class="hint">Setelah klik Aktifkan, pilih <b>Izinkan / Allow</b> pada permintaan izin browser.</div></div>' +
      '<div class="modal-foot"><button class="btn ghost" data-close>Batal</button><button class="btn gold" id="pOn">Aktifkan</button></div>', { focus: false });
    $('#pOn', w).onclick = function () {
      var outlet = lock || $('#pOutlet', w).value, b2 = this;
      busy(b2, true);
      enablePush(outlet).then(function () {
        closeModal(w); lsSet('gahc_push_on', true); lsSet('gahc_push_outlet', outlet); setPushBtn(true);
        toast('Notifikasi HP aktif untuk ' + (outlet === 'ALL' ? 'semua outlet' : outlet) + '.', 'ok');
      }).catch(function (e) { busy(b2, false); toast(e.message, 'err'); });
    };
  }
  function enablePush(outlet) {
    return Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') throw new Error('Izin notifikasi ditolak. Aktifkan lewat pengaturan situs di browser.');
      return Promise.all([api('api/push_subscribe.php'), navigator.serviceWorker.ready]);
    }).then(function (r) {
      var key = r[0].vapid_public_key, reg = r[1];
      if (!key || !r[0].push_ready) throw new Error('Server push belum siap (VAPID key / ekstensi openssl-curl). Hubungi admin.');
      var appKey = urlB64ToUint8Array(key);
      return reg.pushManager.getSubscription().then(function (old) {
        // jika kunci server berubah (setup ulang), langganan lama harus diganti
        if (old && old.options && old.options.applicationServerKey) {
          var a = new Uint8Array(old.options.applicationServerKey);
          var same = a.length === appKey.length && a.every(function (x, i) { return x === appKey[i]; });
          if (same) return old;
          return old.unsubscribe().then(function () { return null; });
        }
        return old;
      }).then(function (sub) {
        return sub || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: appKey });
      });
    }).then(function (sub) {
      return api('api/push_subscribe.php', {
        method: 'POST',
        body: { endpoint: sub.toJSON().endpoint, keys: sub.toJSON().keys, outlet_filter: outlet, device_label: (isStandalone() ? 'PWA ' : 'Browser ') + navigator.userAgent.slice(0, 80) },
      });
    });
  }

  boot();
})();
