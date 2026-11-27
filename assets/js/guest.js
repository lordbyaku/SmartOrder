/* =====================================================================
 * GAHC Smart Outlet Ordering — Halaman Tamu (index.html)
 * Vanilla JS ES2017+, tanpa dependency.
 * ===================================================================== */
(function () {
  'use strict';

  var OUTLETS = ['Pandan Coffee Shop', 'Kemangi Lobby Lounge', 'Limaran Sky Lounge', 'Room Service'];
  var ROOM_SERVICE = 'Room Service';
  var CATEGORIES = ['Signature', 'Makanan Utama', 'Minuman', 'Camilan & Pastry'];
  var CAT_ICON = { 'Signature': '⭐', 'Makanan Utama': '🍛', 'Minuman': '☕', 'Camilan & Pastry': '🥐' };
  var LS_SESSION = 'gahc_guest';
  var LS_LANG = 'gahc_lang';

  /* ------------------------------ i18n ------------------------------ */
  var I18N = {
    id: {
      hotel: 'Grand Artos Hotel & Convention',
      chooseOutlet: 'Pilih Outlet', chooseOutletSub: 'Silakan pilih outlet tempat Anda memesan.',
      welcome: 'Selamat datang', authSub: 'Isi data singkat untuk mulai memesan. Tanpa password.',
      tabRegister: 'Daftar', tabLogin: 'Masuk (No. HP)',
      phone: 'Nomor HP', phoneHint: 'Contoh 0812xxxx. Tamu asing: gunakan kode negara, misal +65 9123 4567.',
      name: 'Nama Lengkap', room: 'Nomor Kamar', roomHint: 'Wajib untuk Room Service.',
      continue: 'Lanjutkan', loginBtn: 'Masuk',
      chooseType: 'Pilih Tipe Pesanan', dineIn: 'DINE IN', takeAway: 'TAKE AWAY',
      tabMenu: 'Daftar Menu', tabOrders: 'Pesanan Saya',
      search: 'Cari menu…', all: 'Semua Menu',
      cat: { 'Signature': 'Chef Signature', 'Makanan Utama': 'Makanan Utama', 'Minuman': 'Minuman & Kopi', 'Camilan & Pastry': 'Camilan & Pastry' },
      add: 'Tambah', soldOut: 'Habis', noMenu: 'Menu tidak ditemukan.',
      closedForce: 'Outlet sedang tutup sementara. Anda tetap dapat melihat menu.',
      closedHours: function (o, c) { return 'Outlet sedang tutup. Jam operasional ' + o + ' – ' + c + ' WIB. Anda tetap dapat melihat menu.'; },
      viewCart: 'Lihat Keranjang', items: 'item',
      cart: 'Keranjang Pesanan', cartEmpty: 'Keranjang masih kosong.',
      itemNote: 'Catatan item (opsional)', globalNote: 'Catatan untuk pesanan (opsional)',
      subtotal: 'Subtotal Menu', taxService: 'Tax & Service', total: 'Total Estimasi',
      sendOrder: 'Kirim Pesanan', sending: 'Mengirim…', orderType: 'Tipe Pesanan',
      room2: 'Kamar', changeRoom: 'Nomor kamar pengantaran',
      cashierTitle: 'Pesanan Diterima',
      cashierMsg: 'Pesanan telah kami terima. Silahkan ke kasir untuk melakukan pembayaran.',
      ok: 'OK', orderCode: 'Kode Pesanan',
      rsToast: function (r) { return 'Pesanan diterima! Akan kami antar ke Kamar ' + r + '.'; },
      noOrders: 'Belum ada pesanan.', refresh: 'Muat ulang',
      status: { NEW: 'Menunggu Konfirmasi Outlet', VHP_INPUTTED: 'Pesanan Sedang Disiapkan', COMPLETED: 'Pesanan Siap / Diantar', CANCELLED: 'Pesanan Dibatalkan' },
      reason: 'Alasan', logout: 'Ganti Akun', account: 'Akun',
      errNetwork: 'Koneksi bermasalah. Periksa internet lalu coba lagi.',
      errServer: 'Server sedang sibuk. Silakan coba lagi.',
      closedCannotOrder: 'Outlet sedang tutup, pesanan belum dapat dikirim.',
      added: 'ditambahkan ke keranjang', changeType: 'Ubah tipe pesanan',
      menuChanged: 'Menu diperbarui. Silakan periksa kembali keranjang Anda.',
      invalidOutlet: 'Link outlet tidak dikenal. Silakan pilih outlet.',
      reqName: 'Nama lengkap wajib diisi.', reqPhone: 'Nomor HP tidak valid.', reqRoom: 'Nomor kamar wajib diisi.',
    },
    en: {
      hotel: 'Grand Artos Hotel & Convention',
      chooseOutlet: 'Choose Outlet', chooseOutletSub: 'Please choose the outlet you are ordering from.',
      welcome: 'Welcome', authSub: 'Fill in a few details to start ordering. No password needed.',
      tabRegister: 'Register', tabLogin: 'Sign in (Phone)',
      phone: 'Phone Number', phoneHint: 'International guests: include country code, e.g. +65 9123 4567.',
      name: 'Full Name', room: 'Room Number', roomHint: 'Required for Room Service.',
      continue: 'Continue', loginBtn: 'Sign in',
      chooseType: 'Choose Order Type', dineIn: 'DINE IN', takeAway: 'TAKE AWAY',
      tabMenu: 'Menu', tabOrders: 'My Orders',
      search: 'Search menu…', all: 'All Menu',
      cat: { 'Signature': 'Chef Signature', 'Makanan Utama': 'Main Course', 'Minuman': 'Drinks & Coffee', 'Camilan & Pastry': 'Snacks & Pastry' },
      add: 'Add', soldOut: 'Sold Out', noMenu: 'No menu found.',
      closedForce: 'This outlet is temporarily closed. You can still browse the menu.',
      closedHours: function (o, c) { return 'This outlet is currently closed. Opening hours ' + o + ' – ' + c + ' (WIB). You can still browse the menu.'; },
      viewCart: 'View Cart', items: 'item(s)',
      cart: 'Your Order', cartEmpty: 'Your cart is empty.',
      itemNote: 'Item note (optional)', globalNote: 'Order note (optional)',
      subtotal: 'Menu Subtotal', taxService: 'Tax & Service', total: 'Estimated Total',
      sendOrder: 'Place Order', sending: 'Sending…', orderType: 'Order Type',
      room2: 'Room', changeRoom: 'Delivery room number',
      cashierTitle: 'Order Received',
      cashierMsg: 'We have received your order. Please proceed to the cashier to complete your payment.',
      ok: 'OK', orderCode: 'Order Code',
      rsToast: function (r) { return 'Order received! We will deliver it to Room ' + r + '.'; },
      noOrders: 'No orders yet.', refresh: 'Refresh',
      status: { NEW: 'Waiting Order to Accept', VHP_INPUTTED: 'Order is Being Prepared', COMPLETED: 'Order Ready / On Delivery', CANCELLED: 'Order Cancelled' },
      reason: 'Reason', logout: 'Switch Account', account: 'Account',
      errNetwork: 'Connection problem. Please check your internet and try again.',
      errServer: 'Server is busy. Please try again.',
      closedCannotOrder: 'The outlet is closed, your order cannot be sent yet.',
      added: 'added to cart', changeType: 'Change order type',
      menuChanged: 'The menu was updated. Please review your cart.',
      invalidOutlet: 'Unknown outlet link. Please choose an outlet.',
      reqName: 'Full name is required.', reqPhone: 'Invalid phone number.', reqRoom: 'Room number is required.',
    },
  };

  /* ------------------------------ utils ----------------------------- */
  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"'`]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' }[c];
    });
  }
  function lsGet(k, def) { try { var v = localStorage.getItem(k); return v == null ? def : JSON.parse(v); } catch (e) { return def; } }
  function lsSet(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* mode privat */ } }
  function lsDel(k) { try { localStorage.removeItem(k); } catch (e) { /* */ } }
  function ssGet(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function ssSet(k, v) { try { sessionStorage.setItem(k, v); } catch (e) { /* */ } }
  function fmt(n) { return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(Number(n) || 0)); }
  function rateLabel(r) { return String(Math.round(Number(r) * 100) / 100); }
  function uuid() {
    if (window.crypto && crypto.randomUUID) { try { return crypto.randomUUID(); } catch (e) { /* non-secure ctx */ } }
    var b = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(b);
    b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
    var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }
  function dt(s) { // "2026-11-26 17:05:00" -> "26/11 17:05" (tanpa konversi zona waktu)
    if (!s) return '';
    var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    return m ? m[3] + '/' + m[2] + ' ' + m[4] + ':' + m[5] : s;
  }
  function $(sel, root) { return (root || document).querySelector(sel); }

  /* ------------------------------ state ----------------------------- */
  var params = new URLSearchParams(location.search);
  var rawOutlet = params.get('outlet') || '';
  var outletMatch = OUTLETS.filter(function (o) { return o.toLowerCase() === rawOutlet.trim().toLowerCase(); })[0] || null;

  var state = {
    outlet: outletMatch,
    outletInfo: null,
    menus: [],
    lang: detectLang(),
    session: loadSession(),
    orderType: null,
    cart: [],
    category: 'ALL',
    search: '',
    view: 'menu',
    authTab: 'register',
    submitting: false,
    orders: [],
    ordersLoading: false,
    loadError: null,
  };
  if (state.outlet) {
    state.cart = lsGet('gahc_cart_' + state.outlet, []);
    if (!Array.isArray(state.cart)) state.cart = [];
    var t = ssGet('gahc_type_' + state.outlet);
    state.orderType = state.outlet === ROOM_SERVICE ? 'Room Service' : (t === 'Dine In' || t === 'Take Away' ? t : null);
  }

  function T() { return I18N[state.lang]; }
  function detectLang() {
    var saved = lsGet(LS_LANG, null);
    if (saved === 'id' || saved === 'en') return saved;
    var nav = (navigator.language || navigator.userLanguage || 'en').toLowerCase();
    return (nav.indexOf('id') === 0 || nav.indexOf('in-') === 0 || nav === 'in') ? 'id' : 'en'; // Android lama memakai kode "in-ID"
  }
  function loadSession() {
    var s = lsGet(LS_SESSION, null);
    return s && s.phone && s.token && s.name ? s : null;
  }
  function saveSession(s) { state.session = s; lsSet(LS_SESSION, s); }
  function saveCart() { if (state.outlet) lsSet('gahc_cart_' + state.outlet, state.cart); }
  function isRS() { return state.outlet === ROOM_SERVICE; }
  function isOpen() { return !!(state.outletInfo && state.outletInfo.is_open); }
  function menuById(id) { return state.menus.filter(function (m) { return m.id === id; })[0] || null; }

  /* ------------------------------ API ------------------------------- */
  function api(url, opts) {
    opts = opts || {};
    var init = { method: opts.method || 'GET', headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' };
    if (opts.body !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(opts.body);
    }
    return fetch(url, init).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (json) {
        if (!json) {
          var e1 = new Error(T().errServer); e1.status = res.status; throw e1;
        }
        if (!json.success) {
          var e2 = new Error(json.message || T().errServer); e2.status = res.status; e2.data = json.data || {}; throw e2;
        }
        return json.data;
      });
    }, function () {
      var e3 = new Error(T().errNetwork); e3.status = 0; throw e3;
    });
  }

  /* ------------------------------ UI helpers ------------------------ */
  function toast(msg, type) {
    var root = $('#toastRoot');
    var el = document.createElement('div');
    el.className = 'toast ' + (type || '');
    el.textContent = msg;
    root.appendChild(el);
    setTimeout(function () { el.remove(); }, type === 'err' ? 5000 : 3200);
  }
  function openModal(html, opts) {
    opts = opts || {};
    var root = $('#modalRoot');
    var wrap = document.createElement('div');
    wrap.className = 'modal-backdrop' + (opts.sheet ? ' sheet' : '');
    wrap.innerHTML = '<div class="modal' + (opts.wide ? ' wide' : '') + '" role="dialog" aria-modal="true">' + html + '</div>';
    if (!opts.locked) {
      wrap.addEventListener('click', function (e) { if (e.target === wrap) closeModal(wrap); });
    }
    root.appendChild(wrap);
    document.body.style.overflow = 'hidden';
    return wrap;
  }
  function closeModal(wrap) {
    if (wrap && wrap.parentNode) wrap.parentNode.removeChild(wrap);
    if (!$('#modalRoot').children.length) document.body.style.overflow = '';
  }

  /* ------------------------------ Render ---------------------------- */
  var app = $('#app');

  function headerHTML(withNav) {
    var t = T();
    var s = state.session;
    var chips = '';
    if (s && withNav) {
      chips += '<button class="guest-chip" data-action="account">👤 ' + esc(s.name) + '</button>';
      if (isRS()) {
        chips += '<button class="guest-chip" data-action="change-room">🛏️ ' + esc(t.room2) + ' ' + esc(s.lastRoom || '-') + '</button>';
      } else if (state.orderType) {
        chips += '<button class="guest-chip" data-action="change-type" title="' + esc(t.changeType) + '">' +
          (state.orderType === 'Dine In' ? '🍽️ Dine In' : '🛍️ Take Away') + ' ▾</button>';
      }
    }
    return '<header class="app-header"><div class="container">' +
      '<div class="bar">' +
        '<div class="brand"><img src="assets/icons/icon-192.png" alt="" width="36" height="36">' +
          '<div class="brand-text"><div class="t1">' + esc(state.outlet || t.hotel) + '</div><div class="t2">' + esc(t.hotel) + '</div></div></div>' +
        '<div class="spacer"></div>' +
        '<div class="lang-toggle" role="group" aria-label="Language">' +
          '<button data-action="lang" data-lang="id" class="' + (state.lang === 'id' ? 'active' : '') + '">ID</button>' +
          '<button data-action="lang" data-lang="en" class="' + (state.lang === 'en' ? 'active' : '') + '">EN</button>' +
        '</div>' +
      '</div>' +
      (chips ? '<div class="row wrap" style="padding-bottom:8px">' + chips + '</div>' : '') +
      (withNav ? '<nav class="tabs">' +
        '<button class="tab ' + (state.view === 'menu' ? 'active' : '') + '" data-action="view" data-view="menu">' + esc(t.tabMenu) + '</button>' +
        '<button class="tab ' + (state.view === 'orders' ? 'active' : '') + '" data-action="view" data-view="orders">' + esc(t.tabOrders) + '</button>' +
      '</nav>' : '') +
    '</div></header>';
  }

  function render() {
    var t = T();
    document.documentElement.lang = state.lang;
    document.title = (state.outlet ? state.outlet + ' — ' : '') + 'Smart Outlet Ordering';

    if (!state.outlet) return renderOutletPicker();
    if (state.loadError) {
      app.innerHTML = headerHTML(false) + '<div class="container narrow"><div class="empty"><div class="big">⚠️</div><p>' +
        esc(state.loadError) + '</p><button class="btn" data-action="reload">' + esc(t.refresh) + '</button></div></div>';
      return;
    }
    if (!state.outletInfo) return; // masih loading (skeleton bawaan HTML)
    if (!state.session || (isRS() && !state.session.lastRoom)) return renderAuth();
    if (!isRS() && !state.orderType) return renderTypeChoice();
    renderMain();
  }

  function renderOutletPicker() {
    var t = T();
    app.innerHTML = headerHTML(false) +
      '<div class="container narrow" style="padding-top:18px">' +
        (rawOutlet ? '<div class="banner warn">' + esc(t.invalidOutlet) + '</div>' : '') +
        '<h2 class="section-title">' + esc(t.chooseOutlet) + '</h2><p class="muted">' + esc(t.chooseOutletSub) + '</p>' +
        '<div class="stack">' + OUTLETS.map(function (o) {
          return '<a class="card row" style="text-decoration:none;color:inherit" href="?outlet=' + encodeURIComponent(o) + '">' +
            '<span style="font-size:26px">' + (o === ROOM_SERVICE ? '🛎️' : '☕') + '</span><strong class="spacer">' + esc(o) + '</strong><span>›</span></a>';
        }).join('') + '</div></div>';
  }

  function renderAuth() {
    var t = T();
    var s = lsGet(LS_SESSION, null) || {};
    var isLogin = state.authTab === 'login';
    app.innerHTML = headerHTML(false) +
      '<div class="guest-hero"><div class="container narrow"><p>' + esc(t.welcome) + '</p><h1>' + esc(state.outlet) + '</h1>' +
      '<p class="small">' + esc(t.authSub) + '</p></div></div>' +
      '<div class="container narrow" style="margin-top:-14px">' +
        '<div class="card">' +
          '<div class="seg" style="width:100%;margin-bottom:14px">' +
            '<button style="flex:1" class="' + (!isLogin ? 'active' : '') + '" data-action="auth-tab" data-tab="register">' + esc(t.tabRegister) + '</button>' +
            '<button style="flex:1" class="' + (isLogin ? 'active' : '') + '" data-action="auth-tab" data-tab="login">' + esc(t.tabLogin) + '</button>' +
          '</div>' +
          '<form id="authForm" class="stack" novalidate>' +
            '<label class="field"><span>' + esc(t.phone) + '</span><input class="input" name="phone" type="tel" inputmode="tel" autocomplete="tel" required maxlength="25" value="' + esc(s.phone || '') + '" placeholder="0812 3456 7890"><div class="hint">' + esc(t.phoneHint) + '</div></label>' +
            (!isLogin ? '<label class="field"><span>' + esc(t.name) + '</span><input class="input" name="name" autocomplete="name" required maxlength="120" value="' + esc(s.name || '') + '"></label>' : '') +
            (isRS() ? '<label class="field"><span>' + esc(t.room) + '</span><input class="input" name="room_number" required maxlength="20" inputmode="text" autocapitalize="characters" value="' + esc(s.lastRoom || '') + '" placeholder="508"><div class="hint">' + esc(t.roomHint) + '</div></label>' : '') +
            '<div class="error-text" id="authErr" hidden></div>' +
            '<button class="btn gold lg block" type="submit">' + esc(isLogin ? t.loginBtn : t.continue) + '</button>' +
          '</form>' +
        '</div>' +
      '</div>';
    var form = $('#authForm');
    form.addEventListener('submit', onAuthSubmit);
  }

  function onAuthSubmit(e) {
    e.preventDefault();
    var t = T();
    var form = e.target;
    var btn = form.querySelector('button[type=submit]');
    var err = $('#authErr');
    function val(n) { var el = form.elements.namedItem(n); return el && el.value ? el.value.trim() : ''; }
    var data = {
      mode: state.authTab === 'login' ? 'login' : 'register',
      phone: val('phone'),
      name: val('name'),
      outlet: state.outlet,
      room_number: val('room_number').toUpperCase(),
    };
    err.hidden = true;
    if (data.phone.replace(/\D/g, '').length < 8) { err.textContent = t.reqPhone; err.hidden = false; return; }
    if (data.mode === 'register' && data.name.length < 2) { err.textContent = t.reqName; err.hidden = false; return; }
    if (isRS() && !data.room_number) { err.textContent = t.reqRoom; err.hidden = false; return; }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';
    api('api/auth_guest.php', { method: 'POST', body: data }).then(function (p) {
      saveSession({ phone: p.phone, name: p.name, lastRoom: data.room_number || p.last_room || null, preferredLang: state.lang, token: p.guest_token });
      render();
    }).catch(function (ex) {
      err.textContent = ex.message; err.hidden = false;
      btn.disabled = false; btn.textContent = state.authTab === 'login' ? t.loginBtn : t.continue;
      if (ex.status === 404 && data.mode === 'login') {
        state.authTab = 'register';
        setTimeout(function () { render(); var e2 = $('#authErr'); if (e2) { e2.textContent = ex.message; e2.hidden = false; } }, 0);
      }
    });
  }

  function renderTypeChoice() {
    var t = T();
    app.innerHTML = headerHTML(false) +
      '<div class="guest-hero"><div class="container narrow"><p>' + esc(t.welcome) + ', ' + esc(state.session.name) + '</p><h1>' + esc(state.outlet) + '</h1></div></div>' +
      '<div class="container narrow" style="padding-top:18px">' +
        '<h2 class="section-title center">' + esc(t.chooseType) + '</h2>' +
        '<div class="type-choice">' +
          '<button data-action="set-type" data-type="Dine In"><span class="ico">🍽️</span>' + esc(t.dineIn) + '</button>' +
          '<button data-action="set-type" data-type="Take Away"><span class="ico">🛍️</span>' + esc(t.takeAway) + '</button>' +
        '</div>' +
      '</div>';
  }

  function renderMain() {
    var t = T();
    var info = state.outletInfo;
    var closed = '';
    if (!info.is_open) {
      closed = '<div class="closed-banner">🕒 ' + esc(info.is_force_closed ? t.closedForce : t.closedHours(info.open_time, info.close_time)) + '</div>';
    }
    if (state.view === 'orders') {
      app.innerHTML = headerHTML(true) + '<main class="container narrow" style="padding-top:14px">' + closed +
        '<div class="row" style="margin:10px 0"><h2 class="section-title spacer" style="margin:0">' + esc(t.tabOrders) + '</h2>' +
        '<button class="btn ghost sm" data-action="refresh-orders">↻ ' + esc(t.refresh) + '</button></div>' +
        '<div id="ordersList"></div></main>';
      renderOrders();
      renderCartBar();
      return;
    }
    var cats = ['ALL'].concat(CATEGORIES.filter(function (c) { return state.menus.some(function (m) { return m.category === c; }); }));
    app.innerHTML = headerHTML(true) +
      '<main class="container">' + closed +
        '<div class="menu-toolbar">' +
          '<div class="search"><span class="ico">🔍</span><input class="input" id="searchInput" type="search" placeholder="' + esc(t.search) + '" value="' + esc(state.search) + '" autocomplete="off"></div>' +
          '<div class="chips" style="margin-top:10px">' + cats.map(function (c) {
            return '<button class="chip ' + (state.category === c ? 'active' : '') + '" data-action="cat" data-cat="' + esc(c) + '">' +
              esc(c === 'ALL' ? t.all : t.cat[c]) + '</button>';
          }).join('') + '</div>' +
        '</div>' +
        '<div id="menuList"></div>' +
      '</main>';
    $('#searchInput').addEventListener('input', function (e) { state.search = e.target.value; renderMenuList(); });
    renderMenuList();
    renderCartBar();
  }

  function qtyInCart(id) {
    return state.cart.filter(function (c) { return c.menu_id === id; }).reduce(function (a, c) { return a + c.qty; }, 0);
  }

  function menuCardHTML(m) {
    var t = T();
    var q = qtyInCart(m.id);
    var canOrder = isOpen() && m.is_available;
    var action;
    if (!m.is_available) action = '<span class="badge red">' + esc(t.soldOut) + ' / Sold Out</span>';
    else if (q > 0) action = '<div class="qty"><button data-action="dec" data-id="' + m.id + '" aria-label="-">−</button><span>' + q + '</span><button data-action="inc" data-id="' + m.id + '" aria-label="+"' + (canOrder ? '' : ' disabled') + '>+</button></div>';
    else action = '<button class="add-btn" data-action="inc" data-id="' + m.id + '"' + (canOrder ? '' : ' disabled') + '>+ ' + esc(t.add) + '</button>';
    var img = m.image_url
      ? '<img src="' + esc(m.image_url) + '" alt="" loading="lazy" onerror="this.parentNode.textContent=\'' + (CAT_ICON[m.category] || '🍽️') + '\'">'
      : (CAT_ICON[m.category] || '🍽️');
    return '<div class="menu-card' + (m.is_available ? '' : ' soldout') + '">' +
      '<div class="thumb">' + img + '</div>' +
      '<div class="info"><div class="name">' + esc(m.name) + '</div>' +
        (m.description ? '<div class="desc">' + esc(m.description) + '</div>' : '') +
        '<div class="foot"><span class="price">' + fmt(m.price) + '</span>' + action + '</div></div></div>';
  }

  function renderMenuList() {
    var t = T();
    var el = $('#menuList');
    if (!el) return;
    var q = state.search.trim().toLowerCase();
    var list = state.menus.filter(function (m) {
      if (state.category !== 'ALL' && m.category !== state.category) return false;
      if (q && (m.name + ' ' + (m.description || '')).toLowerCase().indexOf(q) === -1) return false;
      return true;
    });
    if (!list.length) { el.innerHTML = '<div class="empty"><div class="big">🍽️</div>' + esc(t.noMenu) + '</div>'; return; }
    if (state.category === 'ALL' && !q) {
      el.innerHTML = CATEGORIES.map(function (c) {
        var items = list.filter(function (m) { return m.category === c; });
        if (!items.length) return '';
        return '<h3 class="cat-title">' + (CAT_ICON[c] || '') + ' ' + esc(t.cat[c]) + '</h3><div class="menu-grid">' + items.map(menuCardHTML).join('') + '</div>';
      }).join('');
    } else {
      el.innerHTML = '<div class="menu-grid" style="margin-top:8px">' + list.map(menuCardHTML).join('') + '</div>';
    }
  }

  function cartTotals() {
    var subtotal = 0, tax = 0, rates = {};
    state.cart.forEach(function (c) {
      var m = menuById(c.menu_id);
      if (!m) return;
      var line = m.price * c.qty;
      subtotal += line;
      tax += line * m.tax_service_rate / 100;
      rates[rateLabel(m.tax_service_rate)] = true;
    });
    tax = Math.round(tax);
    var keys = Object.keys(rates);
    return { subtotal: subtotal, tax: tax, total: subtotal + tax, rateText: keys.length === 1 ? ' (' + keys[0] + '%)' : '', count: state.cart.reduce(function (a, c) { return a + c.qty; }, 0) };
  }

  function renderCartBar() {
    var t = T();
    var bar = $('#cartBar');
    var tot = cartTotals();
    if (!tot.count) { if (bar) bar.remove(); return; }
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'cartBar';
      bar.className = 'cart-bar';
      document.body.appendChild(bar);
    }
    bar.innerHTML = '<button class="btn gold lg" data-action="open-cart"><span>🧺 ' + tot.count + ' ' + esc(t.items) + ' · ' + esc(t.viewCart) + '</span><span>' + fmt(tot.total) + '</span></button>';
  }

  /* ------------------------------ Cart ------------------------------ */
  function cartSignature() {
    return JSON.stringify([state.outlet, state.orderType, state.cart.map(function (c) { return [c.menu_id, c.qty, c.note || '']; })]);
  }
  function invalidateRequestId() { lsDel('gahc_req_' + state.outlet); }

  function addToCart(id, delta) {
    var m = menuById(id);
    if (!m) return;
    if (delta > 0 && (!m.is_available || !isOpen())) return;
    var lines = state.cart.filter(function (c) { return c.menu_id === id; });
    if (delta > 0) {
      var plain = lines.filter(function (c) { return !c.note; })[0];
      if (plain) plain.qty = Math.min(99, plain.qty + 1);
      else state.cart.push({ menu_id: id, qty: 1, note: '' });
    } else if (lines.length) {
      var last = lines[lines.length - 1];
      last.qty -= 1;
      if (last.qty <= 0) state.cart.splice(state.cart.indexOf(last), 1);
    }
    invalidateRequestId();
    saveCart();
    renderMenuList();
    renderCartBar();
  }

  var cartModal = null;
  function openCart() {
    if (cartModal) closeModal(cartModal);
    cartModal = openModal('<div class="modal-head"><h3>' + esc(T().cart) + '</h3><button class="icon-btn" data-action="close-cart" aria-label="Close">✕</button></div>' +
      '<div class="modal-body" id="cartBody"></div><div class="modal-foot" id="cartFoot"></div>', { sheet: true });
    renderCartContent();
  }
  function closeCart() { if (cartModal) { closeModal(cartModal); cartModal = null; } }

  function renderCartContent() {
    var t = T();
    var body = $('#cartBody'), foot = $('#cartFoot');
    if (!body) return;
    if (!state.cart.length) {
      body.innerHTML = '<div class="empty"><div class="big">🧺</div>' + esc(t.cartEmpty) + '</div>';
      foot.innerHTML = '<button class="btn ghost" data-action="close-cart">' + esc(t.ok) + '</button>';
      return;
    }
    var tot = cartTotals();
    var typeBlock = isRS()
      ? '<label class="field"><span>' + esc(t.changeRoom) + '</span><input class="input" id="cartRoom" maxlength="20" autocapitalize="characters" value="' + esc(state.session.lastRoom || '') + '"></label>'
      : '<div><span class="label">' + esc(t.orderType) + '</span><div class="seg">' +
          '<button class="' + (state.orderType === 'Dine In' ? 'active' : '') + '" data-action="cart-type" data-type="Dine In">🍽️ Dine In</button>' +
          '<button class="' + (state.orderType === 'Take Away' ? 'active' : '') + '" data-action="cart-type" data-type="Take Away">🛍️ Take Away</button>' +
        '</div></div>';
    body.innerHTML =
      state.cart.map(function (c, i) {
        var m = menuById(c.menu_id);
        if (!m) return '';
        return '<div class="cart-line"><div class="grow"><div class="bold">' + esc(m.name) + '</div>' +
          '<div class="small muted">' + fmt(m.price) + '</div>' +
          '<input class="input sm note" data-note-index="' + i + '" maxlength="255" placeholder="' + esc(t.itemNote) + '" value="' + esc(c.note || '') + '"></div>' +
          '<div class="right"><div class="bold">' + fmt(m.price * c.qty) + '</div><div class="qty" style="margin-top:6px">' +
            '<button data-action="line-dec" data-index="' + i + '">−</button><span>' + c.qty + '</span>' +
            '<button data-action="line-inc" data-index="' + i + '"' + (m.is_available ? '' : ' disabled') + '>+</button></div></div></div>';
      }).join('') +
      '<div class="stack" style="margin-top:12px">' + typeBlock +
        '<label class="field"><span>' + esc(t.globalNote) + '</span><textarea class="input" id="globalNote" maxlength="500">' + esc(lsGet('gahc_note_' + state.outlet, '') || '') + '</textarea></label>' +
      '</div>' +
      '<div class="summary">' +
        '<div class="line"><span>' + esc(t.subtotal) + '</span><span>' + fmt(tot.subtotal) + '</span></div>' +
        '<div class="line"><span>' + esc(t.taxService + tot.rateText) + '</span><span>' + fmt(tot.tax) + '</span></div>' +
        '<div class="line total"><span>' + esc(t.total) + '</span><span>' + fmt(tot.total) + '</span></div>' +
      '</div>' +
      (!isOpen() ? '<div class="closed-banner">' + esc(t.closedCannotOrder) + '</div>' : '');
    foot.innerHTML = '<button class="btn gold lg block" id="sendBtn" data-action="send"' + (isOpen() && !state.submitting ? '' : ' disabled') + '>' +
      (state.submitting ? '<span class="spinner"></span> ' + esc(t.sending) : esc(t.sendOrder) + ' · ' + fmt(tot.total)) + '</button>';

    body.querySelectorAll('[data-note-index]').forEach(function (inp) {
      inp.addEventListener('input', function () {
        var line = state.cart[Number(inp.getAttribute('data-note-index'))];
        if (line) { line.note = inp.value.slice(0, 255); invalidateRequestId(); saveCart(); }
      });
    });
    var gn = $('#globalNote');
    gn.addEventListener('input', function () { lsSet('gahc_note_' + state.outlet, gn.value); invalidateRequestId(); });
    var room = $('#cartRoom');
    if (room) room.addEventListener('input', function () { invalidateRequestId(); });
  }

  function submitOrder() {
    var t = T();
    if (state.submitting) return;
    if (!isOpen()) { toast(t.closedCannotOrder, 'err'); return; }
    if (!state.cart.length) return;
    var room = null;
    if (isRS()) {
      room = ($('#cartRoom') ? $('#cartRoom').value : state.session.lastRoom || '').trim().toUpperCase();
      if (!room) { toast(t.reqRoom, 'err'); return; }
    }
    var note = ($('#globalNote') ? $('#globalNote').value : '').trim();

    // client_request_id: dipakai ulang bila keranjang sama (retry setelah jaringan putus = tidak dobel).
    var sig = cartSignature() + '|' + room + '|' + note;
    var saved = lsGet('gahc_req_' + state.outlet, null);
    var requestId = saved && saved.sig === sig ? saved.id : uuid();
    lsSet('gahc_req_' + state.outlet, { id: requestId, sig: sig });

    state.submitting = true;
    renderCartContent();

    api('api/orders.php', {
      method: 'POST',
      body: {
        client_request_id: requestId,
        outlet: state.outlet,
        order_type: isRS() ? 'Room Service' : state.orderType,
        phone: state.session.phone,
        guest_token: state.session.token,
        room_number: room,
        global_note: note,
        items: state.cart.map(function (c) { return { menu_id: c.menu_id, qty: c.qty, note: c.note || '' }; }),
      },
    }).then(function (data) {
      state.submitting = false;
      state.cart = [];
      saveCart();
      lsDel('gahc_req_' + state.outlet);
      lsDel('gahc_note_' + state.outlet);
      closeCart();
      renderCartBar();
      if (isRS()) {
        var s = state.session; s.lastRoom = room; saveSession(s);
        toast(t.rsToast(room), 'ok');
        state.view = 'orders';
        render();
        loadOrders();
      } else {
        render();
        showCashierModal(data.order);
      }
    }).catch(function (e) {
      state.submitting = false;
      var code = e.data && e.data.code;
      if (e.status !== 0 && e.status < 500) lsDel('gahc_req_' + state.outlet); // ditolak server => id baru saat kirim ulang
      if (code === 'GUEST_SESSION') {
        closeCart(); lsDel(LS_SESSION); state.session = null; toast(e.message, 'err'); render(); return;
      }
      if (code === 'MENU_CHANGED' || code === 'SOLD_OUT' || code === 'OUTLET_CLOSED') {
        toast(e.message, 'err');
        loadMenus(true).then(function () {
          state.cart = state.cart.filter(function (c) { var m = menuById(c.menu_id); return m && m.is_available; });
          saveCart(); renderCartContent(); renderCartBar();
        });
        return;
      }
      toast(e.message, 'err');
      renderCartContent();
    });
  }

  function showCashierModal(order) {
    var t = T();
    var w = openModal('<div class="modal-body center" style="padding-top:24px">' +
      '<div class="success-ico">✓</div><h3 style="margin:0 0 8px;color:var(--navy)">' + esc(t.cashierTitle) + '</h3>' +
      '<p style="font-size:16px;margin:0 0 12px">' + esc(t.cashierMsg) + '</p>' +
      '<div class="badge gold" style="font-size:14px;padding:6px 14px">' + esc(t.orderCode) + ': ' + esc(order.order_code) + '</div>' +
      '<p class="bold" style="margin:12px 0 0">' + esc(t.total) + ': ' + fmt(order.grand_total) + '</p></div>' +
      '<div class="modal-foot"><button class="btn gold lg" data-close>' + esc(t.ok) + '</button></div>', { locked: true });
    w.querySelector('[data-close]').addEventListener('click', function () {
      closeModal(w); state.view = 'orders'; render(); loadOrders();
    });
  }

  /* ------------------------------ Orders ---------------------------- */
  var ordersTimer = null;
  function loadOrders() {
    if (!state.session) return Promise.resolve();
    state.ordersLoading = true;
    return api('api/orders.php?guest_phone=' + encodeURIComponent(state.session.phone) + '&guest_token=' + encodeURIComponent(state.session.token))
      .then(function (d) { state.orders = d.orders || []; state.ordersLoading = false; renderOrders(); })
      .catch(function (e) {
        state.ordersLoading = false;
        if (e.status === 403) { lsDel(LS_SESSION); state.session = null; render(); return; }
        renderOrders(e.message);
      });
  }
  function statusBadge(s) {
    var t = T();
    var cls = { NEW: 'amber', VHP_INPUTTED: 'blue', COMPLETED: 'green', CANCELLED: 'grey' }[s] || 'grey';
    return '<span class="badge ' + cls + '">' + esc(t.status[s] || s) + '</span>';
  }
  function renderOrders(errMsg) {
    var t = T();
    var el = $('#ordersList');
    if (!el) return;
    if (errMsg) { el.innerHTML = '<div class="banner warn">' + esc(errMsg) + '</div>'; return; }
    if (state.ordersLoading && !state.orders.length) { el.innerHTML = '<div class="skeleton" style="height:120px"></div>'; return; }
    if (!state.orders.length) { el.innerHTML = '<div class="empty"><div class="big">🧾</div>' + esc(t.noOrders) + '</div>'; return; }
    var stepIdx = { NEW: 1, VHP_INPUTTED: 2, COMPLETED: 3, CANCELLED: 0 };
    el.innerHTML = state.orders.map(function (o) {
      var steps = o.status === 'CANCELLED' ? '' : '<div class="status-steps">' + [1, 2, 3].map(function (i) { return '<i class="' + (stepIdx[o.status] >= i ? 'on' : '') + '"></i>'; }).join('') + '</div>';
      return '<div class="order-card">' +
        '<div class="row"><strong class="spacer">' + esc(o.order_code) + '</strong>' + statusBadge(o.status) + '</div>' +
        '<div class="small muted">' + esc(o.outlet_name) + ' · ' + esc(o.order_type === 'Room Service' ? T().room2 + ' ' + (o.room_number || '') : o.order_type) + ' · ' + esc(dt(o.created_at)) + '</div>' +
        steps +
        '<div style="margin-top:8px">' + o.items.map(function (it) {
          return '<div class="row small"><span class="bold">' + it.qty + '×</span><span class="spacer">' + esc(it.menu_name) +
            (it.item_note ? ' <em class="muted">(' + esc(it.item_note) + ')</em>' : '') + '</span><span>' + fmt(it.line_total) + '</span></div>';
        }).join('') + '</div>' +
        (o.status === 'CANCELLED' && o.cancel_reason ? '<div class="banner warn small">' + esc(t.reason) + ': ' + esc(o.cancel_reason) + '</div>' : '') +
        '<div class="summary small"><div class="line"><span>' + esc(t.subtotal) + '</span><span>' + fmt(o.subtotal) + '</span></div>' +
        '<div class="line"><span>' + esc(t.taxService) + '</span><span>' + fmt(o.tax_service_amount) + '</span></div>' +
        '<div class="line total"><span>' + esc(t.total) + '</span><span>' + fmt(o.grand_total) + '</span></div></div>' +
      '</div>';
    }).join('');
  }
  function startOrdersPolling() {
    clearInterval(ordersTimer);
    ordersTimer = setInterval(function () {
      if (state.view === 'orders' && document.visibilityState === 'visible') loadOrders();
    }, 15000);
  }

  /* ------------------------------ Actions --------------------------- */
  function showAccountMenu() {
    var t = T();
    var s = state.session;
    var w = openModal('<div class="modal-head"><h3>' + esc(t.account) + '</h3><button class="icon-btn" data-close>✕</button></div>' +
      '<div class="modal-body"><p class="bold" style="margin:0">' + esc(s.name) + '</p><p class="muted" style="margin:2px 0 0">' + esc(s.phone) + '</p></div>' +
      '<div class="modal-foot"><button class="btn ghost" data-close>' + esc(t.ok) + '</button><button class="btn red" data-logout>' + esc(t.logout) + '</button></div>', { sheet: true });
    w.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', function () { closeModal(w); }); });
    w.querySelector('[data-logout]').addEventListener('click', function () {
      closeModal(w);
      var keepPhone = { phone: s.phone };
      lsSet(LS_SESSION, keepPhone); // simpan no. HP sebagai prefill, tetapi sesi dihapus
      state.session = null; state.orders = []; state.view = 'menu';
      render();
    });
  }

  function changeRoom() {
    var t = T();
    var w = openModal('<div class="modal-head"><h3>' + esc(t.changeRoom) + '</h3><button class="icon-btn" data-close>✕</button></div>' +
      '<div class="modal-body"><input class="input" id="roomInp" maxlength="20" autocapitalize="characters" value="' + esc(state.session.lastRoom || '') + '"></div>' +
      '<div class="modal-foot"><button class="btn gold" data-save>' + esc(t.ok) + '</button></div>', { sheet: true });
    w.querySelector('[data-close]').addEventListener('click', function () { closeModal(w); });
    w.querySelector('[data-save]').addEventListener('click', function () {
      var v = w.querySelector('#roomInp').value.trim().toUpperCase();
      if (!v) { toast(t.reqRoom, 'err'); return; }
      var s = state.session; s.lastRoom = v; saveSession(s); invalidateRequestId();
      closeModal(w); render();
    });
  }

  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-action]');
    if (!el) return;
    var a = el.getAttribute('data-action');
    switch (a) {
      case 'lang':
        state.lang = el.getAttribute('data-lang');
        lsSet(LS_LANG, state.lang);
        if (state.session) { state.session.preferredLang = state.lang; saveSession(state.session); }
        render();
        if (cartModal) { closeCart(); openCart(); }
        break;
      case 'auth-tab': state.authTab = el.getAttribute('data-tab'); render(); break;
      case 'set-type':
        state.orderType = el.getAttribute('data-type');
        ssSet('gahc_type_' + state.outlet, state.orderType);
        render();
        break;
      case 'change-type':
        state.orderType = state.orderType === 'Dine In' ? 'Take Away' : 'Dine In';
        ssSet('gahc_type_' + state.outlet, state.orderType);
        invalidateRequestId();
        render();
        toast(state.orderType);
        break;
      case 'cart-type':
        state.orderType = el.getAttribute('data-type');
        ssSet('gahc_type_' + state.outlet, state.orderType);
        invalidateRequestId();
        renderCartContent();
        var hdr = document.querySelector('[data-action="change-type"]');
        if (hdr) hdr.textContent = (state.orderType === 'Dine In' ? '🍽️ Dine In' : '🛍️ Take Away') + ' ▾';
        break;
      case 'change-room': changeRoom(); break;
      case 'account': showAccountMenu(); break;
      case 'view':
        state.view = el.getAttribute('data-view');
        render();
        if (state.view === 'orders') loadOrders();
        window.scrollTo(0, 0);
        break;
      case 'cat': state.category = el.getAttribute('data-cat'); renderMain(); break;
      case 'inc': addToCart(Number(el.getAttribute('data-id')), 1); break;
      case 'dec': addToCart(Number(el.getAttribute('data-id')), -1); break;
      case 'open-cart': openCart(); break;
      case 'close-cart': closeCart(); break;
      case 'line-inc':
      case 'line-dec': {
        var i = Number(el.getAttribute('data-index'));
        var line = state.cart[i];
        if (!line) break;
        if (a === 'line-inc') line.qty = Math.min(99, line.qty + 1); else line.qty -= 1;
        if (line.qty <= 0) state.cart.splice(i, 1);
        invalidateRequestId(); saveCart();
        renderCartContent(); renderCartBar(); renderMenuList();
        break;
      }
      case 'send': submitOrder(); break;
      case 'refresh-orders': loadOrders(); break;
      case 'reload': location.reload(); break;
    }
  });

  /* ------------------------------ Boot ------------------------------ */
  function loadMenus(silent) {
    return api('api/menus.php?outlet=' + encodeURIComponent(state.outlet)).then(function (d) {
      state.outletInfo = d.outlet;
      state.menus = d.menus || [];
      state.loadError = null;
      // buang item keranjang yang sudah tidak ada di outlet ini
      var before = state.cart.length;
      state.cart = state.cart.filter(function (c) { return c && menuById(c.menu_id) && c.qty > 0; });
      if (state.cart.length !== before) saveCart();
    }).catch(function (e) {
      if (!silent || !state.outletInfo) state.loadError = e.message; // refresh latar: abaikan gangguan sesaat
    });
  }

  function boot() {
    if (!state.outlet) { render(); return; }
    loadMenus().then(render);
    startOrdersPolling();
    // segarkan status buka/tutup & stok setiap 2 menit
    setInterval(function () {
      if (document.visibilityState !== 'visible' || state.submitting || cartModal || $('#modalRoot').children.length) return;
      var wasOpen = isOpen();
      loadMenus(true).then(function () {
        if (!state.session || state.loadError) return;
        if (wasOpen !== isOpen()) render(); // status buka/tutup berubah -> perbarui banner
        else if (state.view === 'menu') { renderMenuList(); renderCartBar(); }
      });
    }, 120000);
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible' && state.view === 'orders') loadOrders();
    });
  }
  boot();
})();
