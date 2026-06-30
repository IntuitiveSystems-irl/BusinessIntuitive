/* gov.businessintuitive.tech — Federal Capability Statement
 * Externalized so the page can run under a strict CSP (script-src 'self', no inline JS).
 * Handles: contact modal, secure form submit, copy-to-clipboard codes, scroll reveal, print.
 */
(function () {
  'use strict';

  var modal = document.getElementById('leadModal');

  function openModal() {
    if (!modal) return;
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    var first = document.getElementById('lf-name');
    if (first) setTimeout(function () { first.focus(); }, 100);
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.remove('open');
    document.body.style.overflow = '';
  }

  // ── Event delegation for all data-action buttons (CSP-safe, no inline onclick) ──
  document.addEventListener('click', function (e) {
    var actionEl = e.target.closest('[data-action]');
    if (actionEl) {
      var action = actionEl.getAttribute('data-action');
      if (action === 'open-modal') { e.preventDefault(); openModal(); return; }
      if (action === 'close-modal') { e.preventDefault(); closeModal(); return; }
      if (action === 'print') { e.preventDefault(); window.print(); return; }
      if (action === 'backdrop-close') { if (e.target === modal) closeModal(); return; }
    }

    // ── Copy-to-clipboard for NAICS / PSC codes ──
    var copyBtn = e.target.closest('[data-copy]');
    if (copyBtn) {
      e.preventDefault();
      var val = copyBtn.getAttribute('data-copy');
      var done = function () {
        copyBtn.classList.add('copied');
        setTimeout(function () { copyBtn.classList.remove('copied'); }, 1400);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(val).then(done).catch(function () { fallbackCopy(val, done); });
      } else {
        fallbackCopy(val, done);
      }
    }
  });

  function fallbackCopy(text, cb) {
    try {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      if (cb) cb();
    } catch (err) { /* no-op */ }
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeModal();
  });

  // ── Reveal on scroll ──
  (function () {
    var els = document.querySelectorAll('.reveal');
    if (!('IntersectionObserver' in window) || !els.length) {
      els.forEach(function (el) { el.classList.add('in'); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('in');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });
    els.forEach(function (el) { io.observe(el); });
  })();

  // ── Secure form submission ──
  var form = document.getElementById('leadForm');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var submitBtn = document.getElementById('lf-submit');
      var statusEl = document.getElementById('formStatus');

      var data = {
        name:         val('lf-name'),
        organization: val('lf-org'),
        role:         val('lf-role'),
        inquiry_type: val('lf-type'),
        email:        val('lf-email'),
        phone:        val('lf-phone'),
        solicitation: val('lf-solicitation'),
        notes:        val('lf-notes'),
        company_website: val('lf-company-website'), // honeypot
        page:         'gov-capability-statement',
        referrer:     document.referrer || ''
      };

      // Client-side required check
      if (!data.name || !data.organization || !data.role || !data.inquiry_type || !data.email) {
        showStatus(statusEl, 'error', 'Please complete name, organization, role, inquiry type, and email.');
        return;
      }

      submitBtn.disabled = true;
      submitBtn.textContent = 'Sending...';
      statusEl.className = 'form-status';
      statusEl.textContent = '';

      fetch('/api/gov-lead.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
        credentials: 'omit'
      })
        .then(function (resp) { return resp.json().then(function (b) { return { ok: resp.ok, body: b }; }); })
        .then(function (r) {
          if (r.ok && r.body && r.body.success) {
            showStatus(statusEl, 'success', 'Received. We\u2019ll respond within one business day.');
            form.reset();
            setTimeout(closeModal, 2400);
          } else {
            throw new Error((r.body && r.body.message) || 'Submission failed');
          }
        })
        .catch(function () {
          showStatus(statusEl, 'error', 'Could not send right now \u2014 please email hi@businessintuitive.tech directly.');
        })
        .then(function () {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Send Securely';
        });
    });
  }

  function val(id) {
    var el = document.getElementById(id);
    return el ? String(el.value || '').trim() : '';
  }

  function showStatus(el, kind, msg) {
    if (!el) return;
    el.className = 'form-status show ' + kind;
    el.textContent = msg;
  }
})();
