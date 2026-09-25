/* =====================================================================
   Outline Monitor – shared front-end behaviour (v3.1 premium UI)
   ===================================================================== */
(function ($) {
  'use strict';

  const CRM = window.CRM || {};
  const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  CRM.reduceMotion = reduceMotion;

  // Clean URLs (same rules as clean_route() in PHP): api/forms.php → api/forms, websites/view.php?id=5 → websites/5, x/index.php → x
  CRM.cleanRoute = function (path) {
    path = String(path || '').replace(/^\/+/, '');
    if (/^https?:\/\//i.test(path)) return path;
    let p = path, q = null; const qi = path.indexOf('?'); if (qi > -1) { p = path.slice(0, qi); q = path.slice(qi + 1); }
    const map = { 'index.php': '', 'auth/login.php': 'login', 'auth/logout.php': 'logout', 'auth/register.php': 'register', 'dashboard/index.php': 'dashboard', 'users/index.php': 'team', 'users/profile.php': 'profile', 'notifications/index.php': 'alerts', 'billing/index.php': 'billing', 'incidents/index.php': 'incidents', 'websites/ssl.php': 'ssl', 'websites/pages.php': 'pages', 'status/index.php': 'status-pages', 'platform/index.php': 'platform', 'analytics/index.php': 'analytics' };
    let id = null; if (q) { const m = q.match(/(?:^|&)id=(\d+)(?:&|$)/); if (m) id = m[1]; }
    const stripId = () => { q = q.replace(/(?:^|&)id=\d+/, '').replace(/^&/, ''); if (!q) q = null; };
    let m;
    if (map.hasOwnProperty(p)) p = map[p];
    else if ((m = p.match(/^([a-z0-9_-]+)\/index\.php$/))) p = m[1];
    else if ((m = p.match(/^([a-z0-9_-]+)\/view\.php$/)) && id !== null) { p = m[1] + '/' + id; stripId(); }
    else if ((m = p.match(/^(websites)\/(forms|pages|analytics)\.php$/)) && id !== null) { p = m[1] + '/' + id + '/' + m[2]; stripId(); }
    else if ((m = p.match(/^platform\/customer\.php$/)) && id !== null) { p = 'platform/customers/' + id; stripId(); }
    else if ((m = p.match(/^([a-z0-9_-]+)\/([a-z0-9_-]+)\.php$/))) p = m[1] + '/' + m[2];
    return p + (q ? '?' + q : '');
  };
  CRM.url = function (path) { return CRM.baseUrl + '/' + CRM.cleanRoute(path); };
  CRM.esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };

  /* ---------- AJAX ---------- */
  $.ajaxSetup({ headers: { 'X-CSRF-Token': CRM.csrf, 'X-Requested-With': 'XMLHttpRequest' } });

  CRM.post = function (path, data, opts) {
    opts = opts || {};
    return $.ajax({ url: CRM.url(path), method: 'POST', data: data, dataType: 'json', timeout: opts.timeout || 120000 })
      .fail(function (xhr) {
        let msg = 'Request failed. Please try again.';
        if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
        else if (xhr.status === 0) msg = 'Network error. Check your connection.';
        else if (xhr.status === 429) msg = 'Too many requests – please wait a moment.';
        if (xhr.status === 401) { window.location.href = CRM.url('auth/login.php'); return; }
        if (xhr.responseJSON && xhr.responseJSON.detail) msg += '\n' + xhr.responseJSON.detail;
        CRM.toast(msg, 'error');
      });
  };
  CRM.get = function (path, data) {
    return $.ajax({ url: CRM.url(path), method: 'GET', data: data, dataType: 'json' });
  };

  /* ---------- Toasts & dialogs ---------- */
  const Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3200, timerProgressBar: true, showClass: { popup: reduceMotion ? '' : 'swal2-show' }, iconColor: undefined });
  CRM.toast = function (msg, type) { Toast.fire({ icon: type || 'success', title: msg }); };
  CRM.confirm = function (opts) {
    return Swal.fire($.extend({
      title: 'Are you sure?', text: '', icon: 'warning', showCancelButton: true,
      confirmButtonText: 'Yes, continue', cancelButtonText: 'Cancel', reverseButtons: true, focusCancel: true
    }, opts || {})).then(r => r.isConfirmed);
  };
  CRM.loading = function (title) {
    Swal.fire({ title: title || 'Please wait…', allowOutsideClick: false, allowEscapeKey: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
  };
  CRM.closeLoading = function () { Swal.close(); };

  /* ---------- Sidebar: mobile off-canvas + desktop collapse (persisted) ---------- */
  $('#sidebarToggle').on('click', () => $('body').addClass('sidebar-open'));
  $('#sidebarClose, #sidebarBackdrop').on('click', () => $('body').removeClass('sidebar-open'));
  $(window).on('resize', () => { if (window.innerWidth >= 992) $('body').removeClass('sidebar-open'); });
  $('#sidebarCollapse').on('click', function () {
    const on = !document.documentElement.classList.contains('sidebar-collapsed');
    document.documentElement.classList.toggle('sidebar-collapsed', on);
    try { localStorage.setItem('om.sidebar', on ? 'collapsed' : 'open'); } catch (e) {}
    setTimeout(() => $(window).trigger('resize'), 460); // charts / tables re-measure after the width transition
  });
  // Close the mobile drawer after navigating
  $('.sidebar-nav').on('click', 'a.nav-link:not(.nav-toggle)', () => { if (window.innerWidth < 992) $('body').removeClass('sidebar-open'); });
  $(document).on('keydown', e => { if (e.key === 'Escape') $('body').removeClass('sidebar-open'); });

  /* ---------- Logout confirmation ---------- */
  $('#logoutLink').on('click', function (e) {
    e.preventDefault();
    const href = this.href;
    CRM.confirm({ title: 'Log out?', text: 'You will need to sign in again.', icon: 'question', confirmButtonText: 'Log out' }).then(ok => { if (ok) window.location.href = href; });
  });

  /* ---------- Responsive tables: header text → data-label so rows stack into cards on phones ---------- */
  CRM.labelTables = function (scope) {
    $(scope || document).find('table.table').each(function () {
      const $t = $(this);
      if ($t.hasClass('table-keep')) return;
      $t.addClass('table-stack');
      const labels = [];
      $t.find('thead th').each(function (i) { labels[i] = $(this).text().replace(/\s+/g, ' ').trim(); });
      if (!labels.length) return;
      $t.find('tbody > tr').each(function () {
        if ($(this).hasClass('child')) return;
        $(this).children('td').each(function (i) {
          const $td = $(this);
          if ($td.attr('data-label') !== undefined) return;
          const lbl = labels[i] || '';
          $td.attr('data-label', $td.hasClass('row-actions') || $td.find('.row-actions').length ? '' : lbl);
          if ($td.find('.row-actions').length) $td.addClass('row-actions');
        });
      });
    });
  };

  /* ---------- DataTables ---------- */
  // Tables get their data from api/datatables.php (server-side paging/sorting/search) when data-source is set;
  // small tables without data-source stay client-side. Mobile layout is handled by CSS (table-stack), not by the Responsive plugin.
  CRM.tables = {};
  const emptyHtml = function (icon, title, text) { return '<div class="dt-empty"><i class="bi ' + icon + '"></i><div class="fw-600 text-white">' + title + '</div><div class="small">' + text + '</div></div>'; };
  if ($.fn.dataTable) {
    $.extend(true, $.fn.dataTable.defaults, {
      responsive: false, autoWidth: false, pageLength: 25, lengthMenu: [10, 25, 50, 100, 250], order: [],
      language: { search: '', searchPlaceholder: 'Search…', lengthMenu: 'Show _MENU_', info: '_START_–_END_ of _TOTAL_', infoEmpty: 'No records', infoFiltered: '(filtered from _MAX_)', zeroRecords: emptyHtml('bi-search', 'No matching records', 'Try a different search or clear the filters.'), emptyTable: emptyHtml('bi-inbox', 'Nothing here yet', 'Records will appear here as soon as they are added.'), paginate: { previous: '<i class="bi bi-chevron-left"></i>', next: '<i class="bi bi-chevron-right"></i>' }, processing: '<div class="dt-processing"><i class="bi bi-arrow-repeat spin"></i>Loading…</div>' },
      dom: "<'row align-items-center mb-2'<'col-sm-6'l><'col-sm-6 text-sm-end'f>>rt<'row align-items-center'<'col-sm-5'i><'col-sm-7'p>>"
    });
    CRM.initTables = function (scope) { $(scope || document).find('.datatable').each(function () {
      if ($.fn.dataTable.isDataTable(this)) return;
      const $t = $(this);
      const opts = {};
      if ($t.data('order') !== undefined) opts.order = $t.data('order');
      if ($t.data('pageLength')) opts.pageLength = $t.data('pageLength');
      if ($t.data('empty')) opts.language = { emptyTable: emptyHtml($t.data('emptyIcon') || 'bi-inbox', $t.data('empty'), $t.data('emptyText') || '') };
      const noSort = [];
      $t.find('thead th').each(function (i) { if ($(this).hasClass('no-sort')) noSort.push(i); });
      if (noSort.length) opts.columnDefs = [{ orderable: false, targets: noSort }];
      // Column visibility / print buttons (DataTables Buttons is loaded on pages that set $useDtButtons)
      if ($t.data('buttons') && $.fn.dataTable.Buttons) {
        opts.dom = "<'row align-items-center mb-2'<'col-sm-4'l><'col-sm-8 text-sm-end d-flex flex-wrap justify-content-sm-end align-items-center gap-2'Bf>>rt<'row align-items-center'<'col-sm-5'i><'col-sm-7'p>>";
        opts.buttons = [
          { extend: 'colvis', text: '<i class="bi bi-layout-three-columns"></i> Columns', className: 'btn btn-light btn-sm' },
          { extend: 'print', text: '<i class="bi bi-printer"></i> Print', className: 'btn btn-light btn-sm', exportOptions: { columns: ':visible:not(.no-export)' } }
        ];
        if ($t.data('exportUrl')) opts.buttons.push({ text: '<i class="bi bi-filetype-csv"></i> Export CSV', className: 'btn btn-light btn-sm', action: function () {
          const params = $.param(CRM.tables[$t.data('source')].ajax.params());
          window.location.href = $t.data('exportUrl') + (($t.data('exportUrl').indexOf('?') > -1) ? '&' : '?') + params;
        } });
      }
      if ($t.data('source')) {
        const filterSel = $t.data('filters');
        opts.serverSide = true; opts.processing = true; opts.searchDelay = 400;
        opts.deferRender = true;
        opts.ajax = {
          url: CRM.url('api/datatables.php'),
          data: function (d) {
            d.table = $t.data('source');
            if (filterSel) { $(filterSel).serializeArray().forEach(f => { d[f.name] = f.value; }); }
            const extra = $t.data('params');
            if (extra) $.extend(d, extra);
          },
          error: function (xhr) { if (xhr.status === 401) window.location.href = CRM.url('auth/login.php'); else if (xhr.statusText !== 'abort') CRM.toast('Could not load the table.', 'error'); }
        };
        opts.createdRow = function (row, data) {
          if (data.DT_RowAttr) $.each(data.DT_RowAttr, (k, v) => { if (k === 'class') $(row).addClass(v); else $(row).attr(k, v); });
        };
        // Filter form changes reload the table without a full page load
        if (filterSel) {
          $(filterSel).on('change', 'select, input[type=date], input[type=checkbox]', function () { CRM.tables[$t.data('source')].ajax.reload(); });
          $(filterSel).on('submit', function (e) { e.preventDefault(); CRM.tables[$t.data('source')].ajax.reload(); });
          $(filterSel).on('rs:change', function () { CRM.tables[$t.data('source')].ajax.reload(); });
        }
      }
      opts.drawCallback = function () {
        CRM.labelTables($t.closest('.dataTables_wrapper'));
        const hl = new URLSearchParams(location.search).get('highlight');
        if (hl) { const $row = $t.find('[data-row-id="' + hl + '"]'); if ($row.length && !$row.data('hl')) { $row.data('hl', 1).addClass('row-highlight'); $('html, body').animate({ scrollTop: Math.max(0, $row.offset().top - 120) }, 300); } }
      };
      CRM.tables[$t.data('source') || $t.attr('id') || 'table'] = $t.DataTable(opts);
    }); };
    // Rows with data-href navigate on click (except on links/buttons)
    $(document).on('click', 'tr[data-href]', function (e) { if (!$(e.target).closest('a, button, input, label').length) CRM.navigate($(this).data('href')); });
  }
  if (!CRM.initTables) CRM.initTables = function () {};
  CRM.reloadTable = function () { $.each(CRM.tables, (k, t) => { if (t.ajax && t.ajax.reload) t.ajax.reload(null, false); }); };

  /* ---------- Remote (type-ahead) selects for large lists ---------- */
  (function () {
    let timer = null;
    function render($rs, items) {
      const $res = $rs.find('.rs-results').empty();
      if (!items.length) { $res.append('<div class="rs-empty text-muted small">No matches</div>'); }
      items.forEach(i => $res.append($('<div class="rs-item" tabindex="0">').text(i.label).attr('data-id', i.id).attr('data-client', i.client_id || '')));
      $res.addClass('show');
    }
    function load($rs, q) {
      const data = { type: $rs.data('type'), q: q };
      const dep = $rs.data('depends');
      if (dep) { const $depInput = $rs.closest('form').find('input[name="' + dep + '"]'); if ($depInput.length && $depInput.val()) data.client_id = $depInput.val(); }
      CRM.get('api/lookup.php', data).done(res => render($rs, res.items || []));
    }
    $(document).on('focus input', '.rs-input', function () {
      const $rs = $(this).closest('.remote-select'); const q = $(this).val();
      clearTimeout(timer); timer = setTimeout(() => load($rs, q), 220);
    });
    $(document).on('click', '.rs-item', function () {
      const $rs = $(this).closest('.remote-select');
      $rs.find('input[type=hidden]').val($(this).data('id')).trigger('change');
      $rs.find('.rs-input').val($(this).text());
      $rs.find('.rs-results').removeClass('show');
      $rs.trigger('rs:change');
      $rs.closest('form').find('.remote-select[data-depends="' + $rs.find('input[type=hidden]').attr('name') + '"]').each(function () { $(this).find('input[type=hidden]').val(''); $(this).find('.rs-input').val(''); });
    });
    $(document).on('keydown', '.rs-item', function (e) { if (e.key === 'Enter') { e.preventDefault(); $(this).click(); } });
    $(document).on('click', '.rs-clear', function () {
      const $rs = $(this).closest('.remote-select');
      $rs.find('input[type=hidden]').val('').trigger('change'); $rs.find('.rs-input').val(''); $rs.find('.rs-results').removeClass('show'); $rs.trigger('rs:change');
    });
    $(document).on('click', function (e) { if (!$(e.target).closest('.remote-select').length) $('.rs-results').removeClass('show'); });
    $(document).on('blur', '.rs-input', function () { const $rs = $(this).closest('.remote-select'); if ($(this).val() === '') $rs.find('input[type=hidden]').val(''); });
    CRM.setRemote = function ($rs, id) {
      $rs.find('input[type=hidden]').val(id || '');
      if (!id) { $rs.find('.rs-input').val(''); return; }
      CRM.get('api/lookup.php', { type: $rs.data('type'), id: id }).done(res => { if (res.items && res.items[0]) $rs.find('.rs-input').val(res.items[0].label); });
    };
  })();

  /* ---------- Generic AJAX forms ---------- */
  $(document).on('submit', 'form.ajax-form', function (e) {
    e.preventDefault();
    const $f = $(this);
    if (this.checkValidity && !this.checkValidity()) { this.reportValidity(); return; }
    const $btn = $f.find('[type=submit]');
    const orig = $btn.html();
    $btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat spin me-1"></i>' + ($btn.data('loadingText') || 'Saving…'));
    $f.find('.is-invalid').removeClass('is-invalid');
    $f.find('.invalid-feedback.server').remove();
    const fd = new FormData(this);
    $.ajax({ url: $f.attr('action'), method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json', timeout: parseInt($f.data('timeout'), 10) || 120000 })
      .done(function (res) {
        if (res.success) {
          CRM.toast(res.message || 'Saved');
          if ($f.data('callback') && typeof window[$f.data('callback')] === 'function') { window[$f.data('callback')](res, $f); return; }
          if (res.redirect) { CRM.navigate(res.redirect); return; }
          if ($f.data('redirect')) { CRM.navigate($f.data('redirect')); return; }
          if ($f.data('reload') !== undefined) {
            const $m = $f.closest('.modal');
            if ($m.length) bootstrap.Modal.getOrCreateInstance($m[0]).hide();
            if ($f.data('reload') === 'table' && Object.keys(CRM.tables).length) { CRM.reloadTable(); return; }
            setTimeout(() => CRM.reload(), 350); return;
          }
        } else {
          CRM.toast(res.message || 'Could not save', 'error');
          if (res.errors) {
            let first = null;
            $.each(res.errors, function (field, msg) {
              const $inp = $f.find('[name="' + field + '"]');
              const $target = $inp.closest('.remote-select').length ? $inp.closest('.remote-select').find('.rs-input') : $inp;
              $target.addClass('is-invalid');
              if (!first) first = $target;
              $inp.closest('.mb-3, .mb-2, .col, .col-md-6, .col-md-4, .col-md-3, .col-12, .col-6').append('<div class="invalid-feedback server d-block">' + msg + '</div>');
            });
            if (first && first.length) { const tab = first.closest('.tab-pane'); if (tab.length && !tab.hasClass('active')) { const btn = $('[data-bs-target="#' + tab.attr('id') + '"]'); if (btn.length) bootstrap.Tab.getOrCreateInstance(btn[0]).show(); } first.trigger('focus'); }
          }
        }
      })
      .fail(function (xhr, textStatus) {
        let msg = (xhr.responseJSON && xhr.responseJSON.message) || (textStatus === 'timeout' ? 'The server did not answer in time. Please try again.' : (textStatus === 'parsererror' ? 'The server returned an unexpected reply (HTTP ' + xhr.status + '). Check logs/php-errors.log.' : 'Request failed (HTTP ' + xhr.status + ')'));
        if (xhr.responseJSON && xhr.responseJSON.detail) msg += ' – ' + xhr.responseJSON.detail;
        if (xhr.status === 401) { window.location.href = CRM.url('auth/login.php'); return; }
        CRM.toast(msg, 'error');
      })
      .always(() => $btn.prop('disabled', false).html(orig));
  });

  /* ---------- Generic delete / action buttons ---------- */
  $(document).on('click', '.btn-action', async function (e) {
    e.preventDefault();
    const $b = $(this);
    const params = $b.data('params') || {};
    if ($b.data('confirm')) {
      const ok = await CRM.confirm({ title: $b.data('confirmTitle') || 'Are you sure?', text: $b.data('confirm'), icon: $b.data('icon') || 'warning', confirmButtonText: $b.data('confirmBtn') || 'Yes' });
      if (!ok) return;
    }
    const orig = $b.html();
    $b.prop('disabled', true);
    if ($b.data('loading')) $b.html('<i class="bi bi-arrow-repeat spin"></i>');
    CRM.post($b.data('url'), params).done(function (res) {
      if (res.success) {
        CRM.toast(res.message || 'Done');
        if (res.redirect) { CRM.navigate(res.redirect); return; }
        if ($b.data('redirect')) { CRM.navigate($b.data('redirect')); return; }
        if ($b.data('reload') !== undefined) {
          if ($b.closest('table.datatable[data-source]').length) { CRM.reloadTable(); return; }
          setTimeout(() => CRM.reload(), 350);
        }
      } else {
        CRM.toast(res.message || 'Failed', 'error');
      }
    }).always(() => $b.prop('disabled', false).html(orig));
  });

  /* ---------- Modal helpers ---------- */
  $(document).on('hidden.bs.modal', '.modal', function () {
    const $f = $(this).find('form');
    if ($f.length && !$f.data('noReset')) {
      $f[0].reset();
      $f.find('input[name=id]').val('');
      $f.find('.remote-select').each(function () { if (!$(this).data('keep')) { $(this).find('input[type=hidden]').val(''); $(this).find('.rs-input').val(''); } });
      $f.find('.is-invalid').removeClass('is-invalid');
      $f.find('.invalid-feedback.server').remove();
      $(this).find('.modal-title').text($(this).data('titleAdd') || $(this).find('.modal-title').data('add') || $(this).find('.modal-title').text());
    }
  });
  $(document).on('shown.bs.modal', '.modal', function () { const $first = $(this).find('input:visible:not([type=hidden]):not([readonly]), select:visible, textarea:visible').first(); if ($first.length && window.innerWidth > 767) $first.trigger('focus'); });
  CRM.fillForm = function ($form, data) {
    $.each(data, function (k, v) {
      const $el = $form.find('[name="' + k + '"]');
      if (!$el.length) return;
      if ($el.closest('.remote-select').length) { CRM.setRemote($el.closest('.remote-select'), v); return; }
      if ($el.is(':checkbox')) $el.prop('checked', v == 1 || v === true || v === 'on');
      else if ($el.is(':radio')) $el.filter('[value="' + v + '"]').prop('checked', true);
      else $el.val(v === null ? '' : v);
    });
  };
  CRM.openEdit = function (modalSel, data, title) {
    const $m = $(modalSel);
    const $f = $m.find('form');
    $f[0].reset();
    CRM.fillForm($f, data);
    if (title) $m.find('.modal-title').text(title);
    $f.trigger('crm:filled', [data]);
    bootstrap.Modal.getOrCreateInstance($m[0]).show();
  };
  $(document).on('click', '[data-open-modal]', function (e) {
    const sel = $(this).data('openModal');
    if ($(sel).length) { e.preventDefault(); bootstrap.Modal.getOrCreateInstance($(sel)[0]).show(); }
  });
  CRM.autoOpenModal = function () { if (/[?&]add=1/.test(location.search)) { const $m = $('.modal[data-auto-open]').first(); if ($m.length) bootstrap.Modal.getOrCreateInstance($m[0]).show(); } };

  /* ---------- Copy to clipboard ---------- */
  $(document).on('click', '.copy-btn', function () {
    const txt = $(this).data('copy');
    navigator.clipboard && navigator.clipboard.writeText(txt).then(() => CRM.toast('Copied', 'info'));
  });

  /* ---------- Row highlight via ?highlight=ID (client-side tables) ---------- */
  CRM.highlightRow = function () { const hl = new URLSearchParams(location.search).get('highlight'); if (!hl) return; const $row = $('[data-row-id="' + hl + '"]'); if ($row.length) { $row.addClass('row-highlight'); $('html, body').animate({ scrollTop: Math.max(0, $row.offset().top - 120) }, 300); } };

  /* ---------- Notifications dropdown + heartbeat ---------- */
  function loadNotifications() {
    CRM.get('api/notifications.php', { action: 'recent' }).done(function (res) {
      const $list = $('#notifList').empty();
      if (!res.items || !res.items.length) { $list.html('<div class="empty-state py-3"><i class="bi bi-bell-slash"></i><div class="es-title" style="font-size:1rem">You are all caught up</div><div class="es-text mb-0">New alerts will show up here.</div></div>'); }
      res.items.forEach(function (n) {
        const icon = { critical: 'bi-exclamation-octagon-fill', warning: 'bi-exclamation-triangle-fill', recovery: 'bi-check-circle-fill', info: 'bi-info-circle-fill' }[n.type] || 'bi-bell';
        $list.append('<a href="' + CRM.url('notifications/index.php?open=' + n.id) + '" class="notif-item type-' + n.type + (n.is_read == 0 ? ' unread' : '') + '"><span class="n-icon"><i class="bi ' + icon + '"></i></span><span class="flex-grow-1 min-w-0"><div class="n-title">' + CRM.esc(n.title) + '</div><div class="n-msg">' + CRM.esc(n.message || '') + '</div><div class="n-time">' + n.time_ago + '</div></span></a>');
      });
      updateBell(res.unread);
    });
  }
  function updateBell(unread) {
    const $c = $('#notifCount');
    if (unread > 0) $c.text(unread > 99 ? '99+' : unread).removeClass('d-none'); else $c.addClass('d-none');
  }
  $('#notifBell').on('show.bs.dropdown', loadNotifications);
  $('#notifMarkAll').on('click', function (e) {
    e.preventDefault();
    CRM.post('api/notifications.php', { action: 'mark_all_read' }).done(() => { loadNotifications(); $('.notif-item').removeClass('unread'); });
  });
  // Heartbeat: unread count every 2 minutes while the tab is visible. The same request lets the server run
  // overdue background jobs when no cron/worker is alive (Scheduler web heartbeat), so monitoring never silently stops.
  function heartbeat() { if (document.visibilityState === 'visible' && CRM.user) CRM.get('api/notifications.php', { action: 'count' }).done(r => { updateBell(r.unread); if (r.scheduler) $('[data-scheduler-state]').attr('data-scheduler-state', r.scheduler); }); }
  setInterval(heartbeat, 120000);
  setTimeout(heartbeat, 12000);

  /* ---------- Global search (debounced) ---------- */
  let searchTimer = null, lastSearch = '';
  const $si = $('#globalSearchInput'), $sr = $('#globalSearchResults');
  $si.on('input', function () {
    clearTimeout(searchTimer);
    const q = $(this).val().trim();
    if (q.length < 2) { $sr.removeClass('show').empty(); return; }
    searchTimer = setTimeout(function () {
      if (q === lastSearch) { $sr.addClass('show'); return; }
      lastSearch = q;
      $sr.html('<div class="p-3"><div class="skeleton skeleton-line w-75"></div><div class="skeleton skeleton-line w-50"></div></div>').addClass('show');
      CRM.get('api/search.php', { q: q }).done(function (res) {
        $sr.empty();
        let any = false;
        $.each(res.groups || {}, function (title, items) {
          if (!items.length) return;
          any = true;
          $sr.append('<div class="group-title">' + title + '</div>');
          items.forEach(i => $sr.append('<a href="' + CRM.url(i.url) + '"><i class="bi ' + i.icon + ' text-muted"></i><span>' + CRM.esc(i.label) + '</span><small>' + CRM.esc(i.meta || '') + '</small></a>'));
        });
        if (!any) $sr.append('<div class="p-3 text-muted small text-center">No results for "' + CRM.esc(q) + '"</div>');
        $sr.addClass('show');
      });
    }, 300);
  });
  $(document).on('click', function (e) { if (!$(e.target).closest('#globalSearch').length) $sr.removeClass('show'); });
  $si.on('keydown', function (e) { if (e.key === 'Escape') $sr.removeClass('show'); });
  $(document).on('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k' && $si.length) { e.preventDefault(); $si.trigger('focus'); } });

  /* ---------- Show/hide password ---------- */
  $(document).on('click', '.toggle-password', function () {
    const $i = $($(this).data('target'));
    const show = $i.attr('type') === 'password';
    $i.attr('type', show ? 'text' : 'password');
    $(this).find('i').toggleClass('bi-eye bi-eye-slash');
  });

  /* ---------- Password strength meter (.pw-strength input + .pw-meter + .pw-hint) ---------- */
  CRM.passwordScore = function (v) {
    let s = 0; if (!v) return 0;
    if (v.length >= 8) s++; if (v.length >= 12) s++;
    if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
    if (/\d/.test(v)) s++; if (/[^A-Za-z0-9]/.test(v)) s++;
    return Math.min(4, Math.max(0, s - (v.length < 8 ? 2 : 1)));
  };
  $(document).on('input', '.pw-strength', function () {
    const v = $(this).val(); const sc = CRM.passwordScore(v);
    const $m = $($(this).data('meter')); const $h = $($(this).data('hint'));
    $m.attr('data-level', v ? Math.max(1, sc) : 0);
    $h.text(!v ? '' : ['Too weak – use at least 8 characters', 'Weak – add numbers and capitals', 'Fair – add a symbol or make it longer', 'Good password', 'Strong password'][sc]);
  });

  /* ---------- Motion: reveal on scroll, animated counters ---------- */
  const io = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) {
    entries.forEach(en => {
      if (!en.isIntersecting) return;
      const el = en.target;
      if (el.classList.contains('reveal')) el.classList.add('in');
      if (el.classList.contains('count-up')) CRM.countUp(el);
      io.unobserve(el);
    });
  }, { threshold: .15 }) : null;
  CRM.countUp = function (el) {
    const target = parseFloat(el.getAttribute('data-count') || el.textContent.replace(/[^0-9.]/g, '')) || 0;
    const dec = (String(el.getAttribute('data-count') || '').split('.')[1] || '').length;
    const suffix = el.getAttribute('data-suffix') || '';
    if (reduceMotion || target === 0) { el.textContent = target.toLocaleString(undefined, { maximumFractionDigits: dec }) + suffix; return; }
    const dur = 900, t0 = performance.now();
    (function step(t) {
      const p = Math.min(1, (t - t0) / dur), ease = 1 - Math.pow(1 - p, 3);
      el.textContent = (target * ease).toLocaleString(undefined, { minimumFractionDigits: dec, maximumFractionDigits: dec }) + suffix;
      if (p < 1) requestAnimationFrame(step);
    })(t0);
  };
  CRM.observeMotion = function (scope) {
    if (!io) { $(scope || document).find('.reveal').addClass('in'); return; }
    $(scope || document).find('.reveal, .count-up').each(function () { io.observe(this); });
  };
  CRM.observeMotion(document);

  /* ---------- Charts (Chart.js, lazy) ---------- */
  CRM.charts = [];
  CRM.trackChart = function (c) { if (c) CRM.charts.push(c); return c; };
  CRM.palette = { brand: '#FCAF17', brandSoft: 'rgba(252,175,23,.18)', ink: '#e5e7eb', success: '#22c55e', danger: '#ef4444', warning: '#f97316', info: '#3b82f6', muted: '#6b7280', grid: 'rgba(255,255,255,.06)' };
  CRM.chartDefaults = function () {
    if (!window.Chart) return false;
    Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
    Chart.defaults.font.size = window.innerWidth < 1200 ? 10 : 11;
    Chart.defaults.color = '#8f8f8f';
    Chart.defaults.animation.duration = reduceMotion ? 0 : 900;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 8;
    Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(22,22,22,.96)'; Chart.defaults.plugins.tooltip.borderColor = 'rgba(252,175,23,.35)'; Chart.defaults.plugins.tooltip.borderWidth = 1; Chart.defaults.plugins.tooltip.titleColor = '#fff'; Chart.defaults.plugins.tooltip.bodyColor = '#d6d6d6';
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 10;
    Chart.defaults.plugins.tooltip.titleFont = { weight: '600' };
    return true;
  };
  CRM.gradient = function (ctx, color, alphaTop) {
    const g = ctx.createLinearGradient(0, 0, 0, ctx.canvas.height || 260);
    g.addColorStop(0, color.replace(')', ',' + (alphaTop || .35) + ')').replace('rgb(', 'rgba(').replace('#', ''));
    return g;
  };
  const hexToRgba = (hex, a) => { const h = hex.replace('#', ''); const n = parseInt(h.length === 3 ? h.split('').map(c => c + c).join('') : h, 16); return 'rgba(' + (n >> 16 & 255) + ',' + (n >> 8 & 255) + ',' + (n & 255) + ',' + a + ')'; };
  CRM.rgba = hexToRgba;
  CRM.lineChart = function (canvas, labels, datasets, opts) {
    if (!CRM.chartDefaults()) return null;
    const ctx = canvas.getContext('2d');
    datasets = datasets.map(d => {
      const g = ctx.createLinearGradient(0, 0, 0, canvas.parentNode.clientHeight || 260);
      g.addColorStop(0, hexToRgba(d.color, .32)); g.addColorStop(1, hexToRgba(d.color, 0));
      return $.extend({ borderColor: d.color, backgroundColor: d.fill === false ? 'transparent' : g, fill: d.fill !== false, tension: .38, borderWidth: 2.5, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: d.color, pointHoverBorderColor: '#111', pointHoverBorderWidth: 2 }, d);
    });
    return CRM.trackChart(new Chart(ctx, { type: 'line', data: { labels: labels, datasets: datasets }, options: $.extend(true, {
      responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
      plugins: { legend: { display: datasets.length > 1, position: 'bottom' } },
      scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 8, maxRotation: 0 } }, y: { grid: { color: CRM.palette.grid }, border: { display: false }, ticks: { maxTicksLimit: 5 }, beginAtZero: true } }
    }, opts || {}) }));
  };
  CRM.barChart = function (canvas, labels, datasets, opts) {
    if (!CRM.chartDefaults()) return null;
    datasets = datasets.map(d => $.extend({ backgroundColor: d.color, borderRadius: 6, maxBarThickness: 26, borderSkipped: false }, d));
    return CRM.trackChart(new Chart(canvas.getContext('2d'), { type: 'bar', data: { labels: labels, datasets: datasets }, options: $.extend(true, {
      responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
      plugins: { legend: { display: datasets.length > 1, position: 'bottom' } },
      scales: { x: { stacked: !!(opts && opts.stacked), grid: { display: false }, ticks: { maxTicksLimit: 10, maxRotation: 0 } }, y: { stacked: !!(opts && opts.stacked), grid: { color: CRM.palette.grid }, border: { display: false }, ticks: { maxTicksLimit: 5, precision: 0 }, beginAtZero: true } }
    }, opts || {}) }));
  };
  CRM.donut = function (canvas, labels, data, colors, opts) {
    if (!CRM.chartDefaults()) return null;
    return CRM.trackChart(new Chart(canvas.getContext('2d'), { type: 'doughnut', data: { labels: labels, datasets: [{ data: data, backgroundColor: colors, borderWidth: 0, hoverOffset: 6, spacing: 2 }] }, options: $.extend(true, {
      responsive: true, maintainAspectRatio: false, cutout: '74%',
      plugins: { legend: { display: true, position: 'bottom' } }
    }, opts || {}) }));
  };
  // Lazy chart loader: fetch JSON from an endpoint when the container scrolls into view, then call render(data)
  CRM.lazyCharts = function (sel, path, params, render) {
    const el = $(sel)[0]; if (!el) return;
    const run = () => CRM.get(path, params).done(function (res) { $(el).find('.skeleton').remove(); render(res); }).fail(() => $(el).find('.skeleton').replaceWith('<div class="empty-state py-3"><i class="bi bi-graph-down"></i><div class="es-text mb-0">Charts could not be loaded.</div></div>'));
    if (!('IntersectionObserver' in window)) { run(); return; }
    const o = new IntersectionObserver(en => { if (en[0].isIntersecting) { o.disconnect(); run(); } }, { rootMargin: '120px' });
    o.observe(el);
  };

  /* =====================================================================
   * Instant navigation (v3.5): the shell (sidebar, header, scripts, session) stays; only <main> is replaced.
   * Click → progress bar + faded content → fetch with X-PJAX → swap fragment → run page scripts → history entry.
   * Prefetch on hover, back/forward, deep links and refresh all work like normal pages.
   * ===================================================================== */
  CRM.boot = function (scope) {
    scope = scope || document;
    CRM.initTables(scope);
    CRM.labelTables(scope);
    CRM.observeMotion(scope);
    if (window.bootstrap && bootstrap.Tooltip) $(scope).find('[data-bs-toggle="tooltip"]').each(function () { if (!bootstrap.Tooltip.getInstance(this)) new bootstrap.Tooltip(this); });
    CRM.highlightRow();
    CRM.autoOpenModal();
    $(document).trigger('crm:page', [scope]);
  };
  // page scripts bind on document / window with the ".page" namespace and their timers are tracked, so an AJAX
  // navigation can remove everything the previous page left behind (no duplicate handlers, no zombie polling)
  const pageTimers = [];
  const origOn = $.fn.on, origInterval = window.setInterval, origTimeout = window.setTimeout;
  let pageScope = false;
  $.fn.on = function (types) {
    if (pageScope && typeof types === 'string' && this.length && (this[0] === document || this[0] === window || this[0] === document.body)) {
      const args = Array.prototype.slice.call(arguments); args[0] = types.split(/\s+/).filter(Boolean).map(t => t.indexOf('.') > -1 ? t + '.page' : t + '.page').join(' ');
      return origOn.apply(this, args);
    }
    return origOn.apply(this, arguments);
  };
  window.setInterval = function () { const id = origInterval.apply(window, arguments); if (pageScope) pageTimers.push(['i', id]); return id; };
  window.setTimeout = function () { const id = origTimeout.apply(window, arguments); if (pageScope) pageTimers.push(['t', id]); return id; };
  function cleanupPage() {
    $(document).off('.page'); $(window).off('.page'); $(document.body).off('.page');
    pageTimers.splice(0).forEach(([k, id]) => k === 'i' ? clearInterval(id) : clearTimeout(id));
    $('.modal.show').each(function () { const m = bootstrap.Modal.getInstance(this); if (m) m.hide(); });
    $('.modal-backdrop').remove(); $('body').removeClass('modal-open').css({ overflow: '', paddingRight: '' });
    if (window.Swal && Swal.isVisible()) Swal.close();
    $('.tooltip.show').remove();
    $.each(CRM.tables, (k, t) => { try { t.destroy(true); } catch (e) {} }); CRM.tables = {};
    if (CRM.charts) { CRM.charts.forEach(c => { try { c.destroy(); } catch (e) {} }); CRM.charts = []; }
  }
  const loadedSrc = {}; $('script[src]').each(function () { loadedSrc[this.src] = true; });
  function runScripts(nodes) {
    return nodes.reduce((p, n) => p.then(() => new Promise(resolve => {
      const el = document.createElement('script');
      if (n.src) { if (loadedSrc[n.src]) return resolve(); loadedSrc[n.src] = true; el.src = n.src; el.onload = el.onerror = () => resolve(); document.body.appendChild(el); return; }
      el.text = n.textContent; pageScope = true;
      try { document.body.appendChild(el); } catch (e) { console.error(e); }
      pageScope = false; el.remove(); resolve();
    })), Promise.resolve());
  }
  const $bar = $('#pjaxBar');
  let barTimer = null;
  function barStart() { clearTimeout(barTimer); $bar.removeClass('done').addClass('on').css('width', '30%'); barTimer = setTimeout(() => $bar.css('width', '70%'), 300); }
  function barDone() { clearTimeout(barTimer); $bar.css('width', '100%').addClass('done'); setTimeout(() => $bar.removeClass('on done').css('width', '0'), 320); }
  const cache = new Map();
  function cacheGet(url) { const c = cache.get(url); if (c && Date.now() - c.at < 20000) return c.p; cache.delete(url); return null; }
  function fetchPage(url, viaPrefetch) {
    const p = fetch(url, { headers: { 'X-PJAX': '1', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }).then(async r => {
      const html = await r.text();
      return { ok: r.ok, status: r.status, url: r.url, html: html, pjax: r.headers.get('X-PJAX') === '1' && html.indexOf('id="pjax-meta"') > -1 };
    });
    if (viaPrefetch) { cache.set(url, { p: p, at: Date.now() }); p.catch(() => cache.delete(url)); }
    return p;
  }
  // which shell is loaded: Super Admin console (1) or customer workspace (0) – from the body on a full load, from the fragment meta after PJAX
  CRM.platformArea = (function () { const m = document.getElementById('pjax-meta'); if (m && m.getAttribute('data-platform') !== null) return m.getAttribute('data-platform'); return document.body.getAttribute('data-platform') || '0'; })();
  CRM.prefetch = function (url) { if (!url || cacheGet(url) || url === location.href) return; fetchPage(url, true); };
  let current = null;
  CRM.navigate = function (url, opts) {
    opts = opts || {};
    try { url = new URL(url, location.href).href; } catch (e) { return; }
    if (new URL(url).origin !== location.origin) { window.location.href = url; return; }
    if (/\/(auth|cron|api)\/|\/(login|logout|register|collect|t\.js)(\?|$)|\.(csv|pdf|xml|txt|zip)(\?|$)/.test(url)) { window.location.href = url; return; }
    const token = {}; current = token;
    barStart();
    const $main = $('#main'); $main.addClass('pjax-loading');
    const cached = cacheGet(url);
    const started = performance.now();
    (cached || fetchPage(url, false)).then(res => {
      if (current !== token) return;
      cache.delete(url);
      if (!res.pjax) { // login page, error page or a non-app URL → let the browser do a normal navigation
        if (res.status === 404 || res.status === 403 || res.status >= 500) { showError($main, res.status === 404 ? 'That page could not be found.' : 'The server returned an error (' + res.status + ').', url); barDone(); return; }
        window.location.href = res.url || url; return;
      }
      const doc = new DOMParser().parseFromString(res.html, 'text/html');
      const meta = doc.getElementById('pjax-meta'), main = doc.getElementById('main');
      if (!main) { window.location.href = url; return; }
      // the Super Admin console has its own sidebar – switching areas needs the full shell, not a fragment
      if (meta && meta.getAttribute('data-platform') !== null && meta.getAttribute('data-platform') !== CRM.platformArea) { window.location.href = res.url || url; return; }
      cleanupPage();
      const scripts = Array.prototype.slice.call(doc.querySelectorAll('script')); // document order
      $main.replaceWith(main);
      $('#topbarTitle').html(meta ? meta.innerHTML : '');
      document.title = meta ? meta.getAttribute('data-title') : document.title;
      if (!opts.replace) history.pushState({ pjax: true }, '', res.url && res.url !== url ? res.url : url); else history.replaceState({ pjax: true }, '', url);
      CRM.setActiveNav(meta ? meta.getAttribute('data-nav') : '');
      if (!opts.keepScroll) window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
      // page scripts live after </main> in the fragment (vendor scripts first, then the page's own inline script)
      runScripts(scripts).then(() => { CRM.boot(document.getElementById('main')); barDone(); CRM.lastNavMs = Math.round(performance.now() - started); });
      if (window.innerWidth < 992) $('body').removeClass('sidebar-open');
    }).catch(err => {
      if (current !== token) return;
      showError($main, navigator.onLine === false ? 'You appear to be offline.' : 'Unable to load this page. Please retry.', url);
      barDone();
    });
  };
  function showError($main, msg, url) {
    $main.removeClass('pjax-loading');
    $main.html('<div class="pjax-error card"><div class="card-body text-center py-4"><div class="empty-state mb-0"><i class="bi bi-wifi-off"></i><div class="es-title">' + CRM.esc(msg) + '</div><div class="es-text">The rest of the application keeps working.</div><button class="btn btn-brand btn-sm" data-retry="' + CRM.esc(url) + '"><i class="bi bi-arrow-repeat me-1"></i>Retry</button> <a class="btn btn-light btn-sm" href="' + CRM.esc(url) + '" data-no-pjax>Open normally</a></div></div></div>');
    CRM.toast(msg, 'error');
  }
  $(document).on('click', '[data-retry]', function () { CRM.navigate($(this).data('retry'), { replace: true }); });
  CRM.reload = function (hard) { if (hard) { window.location.reload(); return; } CRM.navigate(location.href, { replace: true, keepScroll: true }); };
  CRM.setActiveNav = function (href) {
    const $links = $('.sidebar-nav a.nav-link:not(.nav-toggle)');
    $links.removeClass('active').removeAttr('aria-current');
    let $a = href ? $links.filter(function () { return this.href === href; }) : $();
    if (!$a.length) { const path = location.pathname; let best = null, len = -1; $links.each(function () { const p = new URL(this.href).pathname; if ((path === p || path.indexOf(p + '/') === 0) && p.length > len) { best = this; len = p.length; } }); if (best) $a = $(best); }
    if (!$a.length) return;
    $a.addClass('active').attr('aria-current', 'page');
    $('.sidebar-nav .nav-group').removeClass('active');
    const $grp = $a.closest('.nav-group'); if ($grp.length) { $grp.addClass('active'); const $sub = $grp.find('.nav-sub'); if (!$sub.hasClass('show')) { $sub.addClass('show'); $grp.find('.nav-toggle').attr('aria-expanded', 'true'); } }
  };
  // intercept internal links (left click, no modifier, same origin, not opted out)
  $(document).on('click', 'a[href]', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = this; if (a.target && a.target !== '_self') return;
    const href = a.getAttribute('href') || ''; if (a.hasAttribute('download') || a.hasAttribute('data-no-pjax') || href.startsWith('#') || href.startsWith('javascript') || href.startsWith('mailto') || href.startsWith('tel')) return;
    if (a.origin !== location.origin) return; if ($(a).is('[data-bs-toggle], [data-open-modal], .btn-action, .btn-edit-user, .dt-button, .page-link')) return;
    if ($(a).closest('.dt-buttons, .dataTables_paginate, .nav-tabs, .pagination, #pjax-meta').length) return;
    if (a.id === 'logoutLink') return;
    e.preventDefault();
    if (a.href === location.href) { CRM.reload(); return; }
    CRM.navigate(a.href);
  });
  window.addEventListener('popstate', function () { CRM.navigate(location.href, { replace: true }); });
  history.replaceState({ pjax: true }, '', location.href);
  // prefetch likely next pages: sidebar items and KPI / list cards on hover (desktop) or first touch (mobile)
  let hoverTimer = null;
  $(document).on('mouseenter touchstart', '.sidebar-nav a.nav-link[href]:not(.nav-toggle), a.stat-card[href], a.list-card[href]', function () {
    const href = this.href; if (!href || this.id === 'logoutLink' || /\/(auth|cron|api)\//.test(href)) return;
    clearTimeout(hoverTimer); hoverTimer = setTimeout(() => CRM.prefetch(href), 90);
  });
  $(document).on('mouseleave', '.sidebar-nav a.nav-link[href]', function () { clearTimeout(hoverTimer); });
  CRM.charts = CRM.charts || [];
  CRM.boot(document);
  window.CRM = CRM;
})(jQuery);
