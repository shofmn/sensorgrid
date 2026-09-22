/* Sensor Grid UI behaviour. Plain JS, no dependencies. */
(() => {
  'use strict';

  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
  const csrf = $('meta[name="csrf-token"]')?.content ?? '';

  /* ---------- Toasts ---------- */

  const toastBox = $('#toasts');

  function dismissLater(el) {
    setTimeout(() => el.remove(), 5000);
  }

  function toast(message, type = 'info') {
    const el = document.createElement('div');
    el.className = `toast toast--${type}`;
    el.textContent = message;
    toastBox.append(el);
    dismissLater(el);
  }

  $$('.toast').forEach(dismissLater);

  /* ---------- AJAX ---------- */

  // Built from location.origin: a relative URL would inherit user:pass@ if the page was opened that way,
  // and fetch() refuses URLs with credentials.
  const endpoint = location.origin + location.pathname.replace(/[^/]*$/, '') + 'index.php';

  async function post(fields) {
    const body = new FormData();
    body.set('csrf', csrf);
    for (const [k, v] of Object.entries(fields)) body.set(k, v);
    const res = await fetch(endpoint, { method: 'POST', body, headers: { 'X-Requested-With': 'fetch' } });
    try {
      return await res.json();
    } catch {
      return { ok: false, error: `Unexpected response (HTTP ${res.status})` };
    }
  }

  /** Render a title, optional key/value rows and optional preview into a result panel. */
  function showResult(box, { ok, title, rows = [], preview = '' }) {
    box.replaceChildren();
    box.hidden = false;
    box.className = `test-result ${ok ? 'is-ok' : 'is-error'}`;

    const h = document.createElement('h3');
    h.textContent = title;
    box.append(h);

    if (rows.length) {
      const dl = document.createElement('dl');
      for (const [k, v] of rows) {
        const dt = document.createElement('dt');
        const dd = document.createElement('dd');
        dt.textContent = k;
        dd.textContent = v; // textContent: fetched page content must never be parsed as HTML
        dl.append(dt, dd);
      }
      box.append(dl);
    }
    if (preview) {
      const pre = document.createElement('pre');
      pre.textContent = preview;
      box.append(pre);
    }
  }

  /* ---------- Test run ---------- */

  const form       = $('#monitor-form');
  const testResult = $('#test-result');

  async function runTest(url, elementId, label) {
    showResult(testResult, { ok: true, title: `Testing ${label}…` });
    testResult.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    try {
      const r = await post({ action: 'test', url, elementId });
      const rows = [
        ['HTTP code', r.httpCode || '—'],
        ['Final URL', r.finalUrl || '—'],
        ['Elapsed', `${r.elapsedMs ?? 0} ms`],
        ['Element found', r.elementFound ? 'yes' : 'no'],
        ['Extracted', `${r.extractedLength ?? 0} characters`],
      ];
      showResult(testResult, {
        ok: r.ok,
        title: r.ok ? `Test passed — ${label}` : `Test failed — ${r.error}`,
        rows,
        preview: r.preview,
      });
    } catch (e) {
      showResult(testResult, { ok: false, title: `Test failed — ${e.message}` });
    }
  }

  $('#f-test').addEventListener('click', () => {
    if (!form.elements.url.reportValidity()) return;
    runTest(form.elements.url.value, form.elements.elementId.value, form.elements.name.value || form.elements.url.value);
  });

  $$('[data-test]').forEach((btn) =>
    btn.addEventListener('click', () => runTest(btn.dataset.url, btn.dataset.element, btn.dataset.name)));

  /* ---------- Send test email ---------- */

  const mailBtn    = $('#send-test-mail');
  const mailResult = $('#mail-result');

  mailBtn.addEventListener('click', async () => {
    const fields = Object.fromEntries(new FormData($('#settings-form')));
    fields.action = 'sendTestMail';
    mailBtn.disabled = true;
    showResult(mailResult, { ok: true, title: 'Sending…' });
    try {
      const r = await post(fields);
      showResult(mailResult, {
        ok: r.ok,
        title: r.ok ? `Test email sent to ${r.to} via ${r.transport}` : `Sending failed — ${r.error}`,
      });
    } catch (e) {
      showResult(mailResult, { ok: false, title: `Sending failed — ${e.message}` });
    } finally {
      mailBtn.disabled = false;
    }
  });

  /* ---------- Add / edit form ---------- */

  const submitBtn = $('#f-submit');
  const cancelBtn = $('#f-cancel');

  function setFormMode(editing) {
    $('#form-title').textContent = editing ? 'Edit monitor' : 'Add monitor';
    submitBtn.textContent = editing ? 'Update monitor' : 'Add monitor';
    $('#f-action').value = editing ? 'update' : 'add';
    cancelBtn.hidden = !editing;
  }

  if ($('#f-id').value) setFormMode(true); // form re-shown after a failed update

  $$('[data-edit]').forEach((btn) =>
    btn.addEventListener('click', () => {
      const d = btn.dataset;
      $('#f-id').value = d.id;
      form.elements.name.value = d.name;
      form.elements.url.value = d.url;
      form.elements.elementId.value = d.element;
      form.elements.intervalMinutes.value = d.interval;
      setFormMode(true);
      testResult.hidden = true;
      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
      form.elements.name.focus({ preventScroll: true });
    }));

  cancelBtn.addEventListener('click', () => {
    form.reset();
    $('#f-id').value = '';
    form.elements.intervalMinutes.value = 60;
    setFormMode(false);
  });

  $$('[data-minutes]').forEach((btn) =>
    btn.addEventListener('click', () => { form.elements.intervalMinutes.value = btn.dataset.minutes; }));

  /* ---------- Disclosure: history drawers, settings panel, SMTP fields ---------- */

  function toggleDisclosure(btn, panel) {
    const open = panel.hidden;
    panel.hidden = !open;
    btn.setAttribute('aria-expanded', String(open));
  }

  $$('[data-history]').forEach((btn) =>
    btn.addEventListener('click', () => toggleDisclosure(btn, document.getElementById(`history-${btn.dataset.history}`))));

  $('#settings-toggle').addEventListener('click', (e) => toggleDisclosure(e.currentTarget, $('#settings-body')));

  const transport = $('#transport');
  transport.addEventListener('change', () => { $('#smtp-fields').hidden = transport.value !== 'smtp'; });

  /* ---------- Destructive actions ---------- */

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-confirm]');
    if (btn && !window.confirm(btn.dataset.confirm)) e.preventDefault();
  });

  /* ---------- Live relative time ---------- */

  // Mirrors sg_relative() in lib.php.
  function relative(ts) {
    const d = Math.floor((Date.now() - ts) / 1000);
    if (d < 5) return 'just now';
    if (d < 60) return `${d}s ago`;
    if (d < 3600) return `${Math.floor(d / 60)}m ago`;
    if (d < 86400) return `${Math.floor(d / 3600)}h ago`;
    return `${Math.floor(d / 86400)}d ago`;
  }

  function refreshTimes() {
    $$('time[data-ts]').forEach((el) => {
      const ts = Date.parse(el.dataset.ts);
      if (!Number.isNaN(ts)) el.textContent = relative(ts);
    });
    // A dead cron is otherwise silent, so the pill is re-evaluated client-side too.
    const pill = $('#cron-pill');
    if (pill) {
      const ts = Date.parse(pill.dataset.ts);
      const stale = Number.isNaN(ts) || Date.now() - ts > 300000;
      if (stale && !pill.classList.contains('is-alert')) {
        pill.classList.replace('lcars-pill--sky', 'lcars-pill--red');
        pill.classList.add('is-alert');
        pill.replaceChildren('Cron offline · last run ', $('time', pill) ?? '');
      }
    }
  }

  setInterval(refreshTimes, 30000);
})();
