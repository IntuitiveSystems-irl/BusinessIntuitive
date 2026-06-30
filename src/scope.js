/**
 * Scope Intake Funnel — "Let's Scope Your Build"
 *
 * Guided 5-step intake rendered in the hero:
 *   intro → 1 Business Profile → 2 Operational Bottleneck →
 *   3 What You Want Built → 4 How You Want to Start → 5 Your Details → confirmation
 *
 * Captures the lead via /api/scope-intake.php, then routes to either a
 * paid strategy-session checkout or a free fit-call calendar.
 */

const CONFIG = {
  // Lead capture (emails the team + logs to JSONL)
  intakeEndpoint: '/api/scope-intake.php',

  // Paid strategy session ($250) — reuses the proposal-platform checkout
  strategyCheckout: 'https://proposal.businessintuitive.tech/api/checkout/strategy-session',
  strategyAmountCents: 25000,

  // Calendars
  calFitCall: 'https://cal.com/businessintuitive',
  calStrategy: 'https://cal.com/businessintuitive',
};

export class ScopeFunnel {
  constructor() {
    this.root = document.getElementById('scope');
    if (!this.root) return;

    this.totalSteps = 5;
    this.currentStep = 1;
    this.view = 'intro';

    this.data = {
      businessType: '',
      revenue: '',
      teamSize: '',
      website: '',
      bottlenecks: [],
      wants: [],
      startPath: '',
      name: '',
      email: '',
      company: '',
      notes: '',
    };

    this.init();
  }

  init() {
    // Launch triggers (hero CTA + lower-page "Request a Proposal" buttons)
    document.querySelectorAll('[data-scope-start]').forEach((el) => {
      el.addEventListener('click', (e) => {
        e.preventDefault();
        this.start();
      });
    });

    // Single-select chip groups (business type)
    this.root.querySelectorAll('[data-chips]').forEach((group) => {
      const field = group.dataset.chips;
      group.querySelectorAll('.scope__chip').forEach((chip) => {
        chip.addEventListener('click', () => {
          group.querySelectorAll('.scope__chip').forEach((c) => c.classList.remove('is-selected'));
          chip.classList.add('is-selected');
          this.data[field] = chip.dataset.value;
          this.clearError(chip);
        });
      });
    });

    // Multi-select check groups (bottlenecks, wants)
    this.root.querySelectorAll('[data-checks]').forEach((group) => {
      const field = group.dataset.checks;
      group.querySelectorAll('.scope__check').forEach((check) => {
        check.addEventListener('click', () => {
          this.toggleCheck(group, field, check);
          this.clearError(check);
        });
      });
    });

    // Single-select path cards (start choice)
    this.root.querySelectorAll('[data-paths]').forEach((group) => {
      const field = group.dataset.paths;
      group.querySelectorAll('.scope__path').forEach((path) => {
        path.addEventListener('click', () => {
          group.querySelectorAll('.scope__path').forEach((p) => p.classList.remove('is-selected'));
          path.classList.add('is-selected');
          this.data[field] = path.dataset.value;
          this.clearError(path);
        });
      });
    });

    // Free-text + select fields (live bind)
    this.root.querySelectorAll('[data-field]').forEach((input) => {
      const field = input.dataset.field;
      const evt = input.tagName === 'SELECT' ? 'change' : 'input';
      input.addEventListener(evt, () => {
        this.data[field] = input.value.trim();
        input.classList.remove('is-invalid');
      });
      // Enter advances (except multi-line)
      if (input.tagName !== 'TEXTAREA') {
        input.addEventListener('keydown', (e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            this.currentStep === this.totalSteps ? this.submit() : this.next();
          }
        });
      }
    });

    // Navigation
    this.root.querySelectorAll('[data-scope-next]').forEach((b) => b.addEventListener('click', () => this.next()));
    this.root.querySelectorAll('[data-scope-prev]').forEach((b) => b.addEventListener('click', () => this.prev()));
    this.root.querySelectorAll('[data-scope-submit]').forEach((b) => b.addEventListener('click', () => this.submit()));
  }

  toggleCheck(group, field, check) {
    const value = check.dataset.value;
    const exclusive = check.dataset.exclusive === 'true';
    const isSelected = check.classList.contains('is-selected');

    if (exclusive) {
      // Selecting an exclusive option clears everything else
      group.querySelectorAll('.scope__check').forEach((c) => c.classList.remove('is-selected'));
      if (!isSelected) check.classList.add('is-selected');
    } else {
      // Selecting a normal option clears any exclusive option
      group.querySelectorAll('.scope__check[data-exclusive="true"]').forEach((c) => c.classList.remove('is-selected'));
      check.classList.toggle('is-selected');
    }

    this.data[field] = Array.from(group.querySelectorAll('.scope__check.is-selected')).map((c) => c.dataset.value);
  }

  /* ---------- View + step management ---------- */

  showView(name) {
    this.view = name;
    this.root.dataset.view = name;
    this.root.querySelectorAll(':scope > .scope__view').forEach((v) => {
      v.classList.toggle('is-active', v.dataset.view === name);
    });
    document.body.classList.toggle('scope-active', name !== 'intro');
  }

  start() {
    // Bring the hero into view, then open the funnel
    const hero = document.getElementById('hero');
    if (hero) window.scrollTo({ top: 0, behavior: 'smooth' });
    if (this.view === 'done') this.reset();
    this.showView('form');
    this.goToStep(this.currentStep);
  }

  goToStep(step) {
    this.currentStep = step;
    this.root.querySelectorAll('.scope__step').forEach((s) => {
      s.classList.toggle('is-active', parseInt(s.dataset.step, 10) === step);
    });
    this.updateProgress();
  }

  updateProgress() {
    const pct = Math.round(((this.currentStep - 1) / (this.totalSteps - 1)) * 100);
    const fill = this.root.querySelector('[data-fill]');
    const pctEl = this.root.querySelector('[data-pct]');
    const countEl = this.root.querySelector('[data-step-count]');
    if (fill) fill.style.width = `${pct}%`;
    if (pctEl) pctEl.textContent = `${pct}%`;
    if (countEl) countEl.textContent = `Step ${this.currentStep} of ${this.totalSteps}`;
  }

  next() {
    const check = this.validateStep(this.currentStep);
    if (!check.valid) {
      this.setError(check.message);
      return;
    }
    this.setError('');
    if (this.currentStep < this.totalSteps) this.goToStep(this.currentStep + 1);
  }

  prev() {
    this.setError('');
    if (this.currentStep > 1) {
      this.goToStep(this.currentStep - 1);
    } else {
      this.showView('intro');
    }
  }

  /* ---------- Validation ---------- */

  validateStep(step) {
    if (step === 1) {
      if (!this.data.businessType) return { valid: false, message: 'Select a business type to continue.' };
      const revenue = this.root.querySelector('#sc-revenue');
      const team = this.root.querySelector('#sc-team');
      if (!this.data.revenue) { revenue?.classList.add('is-invalid'); return { valid: false, message: 'Select your annual revenue range.' }; }
      if (!this.data.teamSize) { team?.classList.add('is-invalid'); return { valid: false, message: 'Select your team size.' }; }
      return { valid: true };
    }
    if (step === 2) {
      if (!this.data.bottlenecks.length) return { valid: false, message: 'Choose at least one bottleneck.' };
      return { valid: true };
    }
    if (step === 3) {
      if (!this.data.wants.length) return { valid: false, message: 'Choose at least one option.' };
      return { valid: true };
    }
    if (step === 4) {
      if (!this.data.startPath) return { valid: false, message: 'Choose how you want to start.' };
      return { valid: true };
    }
    if (step === 5) {
      const nameEl = this.root.querySelector('#sc-name');
      const emailEl = this.root.querySelector('#sc-email');
      if (!this.data.name) { nameEl?.classList.add('is-invalid'); return { valid: false, message: 'Please enter your name.' }; }
      if (!this.isEmail(this.data.email)) { emailEl?.classList.add('is-invalid'); return { valid: false, message: 'Please enter a valid email.' }; }
      return { valid: true };
    }
    return { valid: true };
  }

  isEmail(v) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v || '');
  }

  setError(message) {
    const active = this.root.querySelector('.scope__step.is-active [data-error]');
    if (active) active.textContent = message || '';
  }

  clearError() {
    this.setError('');
  }

  /* ---------- Submit ---------- */

  async submit() {
    const check = this.validateStep(5);
    if (!check.valid) {
      this.setError(check.message);
      return;
    }
    this.setError('');

    const spinner = this.root.querySelector('[data-spinner]');
    if (spinner) spinner.classList.add('is-active');

    const payload = {
      ...this.data,
      page: 'home-scope',
      referrer: document.referrer || '',
    };

    try {
      await fetch(CONFIG.intakeEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
    } catch (err) {
      // Best-effort capture — never block the user on a network hiccup
      console.error('scope intake error', err);
    }

    if (typeof window.gtag === 'function') {
      window.gtag('event', 'generate_lead', {
        event_category: 'scope_intake',
        event_label: this.data.startPath,
      });
    }

    if (spinner) spinner.classList.remove('is-active');
    this.renderDone();
    this.showView('done');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  /* ---------- Confirmation ---------- */

  renderDone() {
    const isStrategy = this.data.startPath === 'strategy';

    const prepare = isStrategy
      ? ['Your current site, tools, or stack', 'The metrics or bottlenecks that matter most', 'Examples of work you admire']
      : ['A rough idea of your goal', 'Your ideal timeline', 'Any must-have features'];

    const receive = isStrategy
      ? ['A 45-min strategy call', 'A system architecture map', 'A build recommendation', 'Cost + timeline', 'Recording + notes']
      : ['A quick fit assessment', 'Honest go / no-go guidance', 'A clear recommended next step'];

    this.fillList('[data-prepare-list]', prepare);
    this.fillList('[data-receive-list]', receive);

    const ctaWrap = this.root.querySelector('[data-done-cta]');
    if (!ctaWrap) return;
    ctaWrap.innerHTML = '';

    if (isStrategy) {
      const pay = document.createElement('button');
      pay.type = 'button';
      pay.className = 'cta-button cta-button--large';
      pay.setAttribute('data-hover', '');
      pay.textContent = 'Complete Payment — $250';
      pay.addEventListener('click', () => this.payStrategy(pay));
      ctaWrap.appendChild(pay);

      const note = document.createElement('p');
      note.className = 'scope__done-subnote';
      note.textContent = "You'll pick your session time right after checkout.";
      ctaWrap.appendChild(note);

      const alt = document.createElement('a');
      alt.className = 'scope__textlink';
      alt.href = CONFIG.calFitCall;
      alt.target = '_blank';
      alt.rel = 'noopener';
      alt.setAttribute('data-hover', '');
      alt.textContent = 'Prefer a free fit call instead? →';
      ctaWrap.appendChild(alt);
    } else {
      const book = document.createElement('a');
      book.className = 'cta-button cta-button--large';
      book.href = CONFIG.calFitCall;
      book.target = '_blank';
      book.rel = 'noopener';
      book.setAttribute('data-hover', '');
      book.textContent = 'Book Your 15-min Fit Call →';
      ctaWrap.appendChild(book);

      const note = document.createElement('p');
      note.className = 'scope__done-subnote';
      note.textContent = 'We just emailed you a copy of your intake.';
      ctaWrap.appendChild(note);
    }
  }

  fillList(selector, items) {
    const ul = this.root.querySelector(selector);
    if (!ul) return;
    ul.innerHTML = '';
    items.forEach((text) => {
      const li = document.createElement('li');
      li.textContent = text;
      ul.appendChild(li);
    });
  }

  async payStrategy(btn) {
    const original = btn.textContent;
    btn.textContent = 'Redirecting…';
    btn.style.pointerEvents = 'none';
    try {
      const resp = await fetch(CONFIG.strategyCheckout, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...this.data, amountCents: CONFIG.strategyAmountCents, source: 'home-scope' }),
      });
      const result = await resp.json();
      if (result && result.url) {
        window.location.href = result.url;
        return;
      }
      throw new Error('No checkout URL returned');
    } catch (err) {
      console.error('strategy checkout error', err);
      btn.textContent = original;
      btn.style.pointerEvents = '';
      const note = this.root.querySelector('[data-done-cta] .scope__done-subnote');
      if (note) note.textContent = 'Checkout is taking a moment — or book a call and we\'ll send a payment link.';
    }
  }

  /* ---------- Reset ---------- */

  reset() {
    this.currentStep = 1;
    this.data = {
      businessType: '', revenue: '', teamSize: '', website: '',
      bottlenecks: [], wants: [], startPath: '',
      name: '', email: '', company: '', notes: '',
    };
    this.root.querySelectorAll('.is-selected').forEach((el) => el.classList.remove('is-selected'));
    this.root.querySelectorAll('[data-field]').forEach((el) => { el.value = ''; el.classList.remove('is-invalid'); });
    this.setError('');
  }
}
