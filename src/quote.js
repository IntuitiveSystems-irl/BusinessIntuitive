/**
 * Quote Modal — Multi-step form with email submission via /api/send-quote.php
 */

export class QuoteModal {
  constructor() {
    this.currentStep = 1;
    this.totalSteps = 4;
    this.formData = {
      service: '',
      projectDescription: '',
      timeline: '',
      budget: '',
      fullName: '',
      email: '',
      phone: '',
      company: '',
    };

    this.init();
  }

  init() {
    // Open triggers
    document.querySelectorAll('[data-open-quote]').forEach((el) => {
      el.addEventListener('click', (e) => {
        e.preventDefault();
        this.open();
      });
    });

    // Close triggers
    const overlay = document.getElementById('quoteModal');
    if (!overlay) return;

    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) this.close();
    });

    const closeBtn = overlay.querySelector('.quote-modal__close');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => this.close());
    }

    // Escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') this.close();
    });

    // Service option cards (step 1)
    overlay.querySelectorAll('.quote-option[data-service]').forEach((card) => {
      card.addEventListener('click', () => {
        overlay.querySelectorAll('.quote-option[data-service]').forEach((c) => c.classList.remove('selected'));
        card.classList.add('selected');
        this.formData.service = card.dataset.service;
        // Auto-advance after short delay
        setTimeout(() => this.nextStep(), 300);
      });
    });

    // Navigation buttons
    overlay.querySelectorAll('[data-quote-next]').forEach((btn) => {
      btn.addEventListener('click', () => this.nextStep());
    });
    overlay.querySelectorAll('[data-quote-prev]').forEach((btn) => {
      btn.addEventListener('click', () => this.prevStep());
    });

    // Submit
    const submitBtn = overlay.querySelector('[data-quote-submit]');
    if (submitBtn) {
      submitBtn.addEventListener('click', () => this.submit());
    }

    // Close on success
    const doneBtn = overlay.querySelector('[data-quote-done]');
    if (doneBtn) {
      doneBtn.addEventListener('click', () => this.close());
    }
  }

  open() {
    const modal = document.getElementById('quoteModal');
    if (modal) {
      modal.classList.add('active');
      document.body.style.overflow = 'hidden';
    }
  }

  close() {
    const modal = document.getElementById('quoteModal');
    if (modal) {
      modal.classList.remove('active');
      document.body.style.overflow = '';
      this.reset();
    }
  }

  reset() {
    this.currentStep = 1;
    this.formData = {
      service: '',
      projectDescription: '',
      timeline: '',
      budget: '',
      fullName: '',
      email: '',
      phone: '',
      company: '',
    };
    
    const modal = document.getElementById('quoteModal');
    if (!modal) return;

    // Reset steps visibility
    modal.querySelectorAll('.quote-step').forEach((s) => s.classList.remove('active'));
    const step1 = modal.querySelector('.quote-step[data-step="1"]');
    if (step1) step1.classList.add('active');

    // Reset selections
    modal.querySelectorAll('.quote-option').forEach((c) => c.classList.remove('selected'));

    // Reset form fields
    modal.querySelectorAll('input, textarea, select').forEach((el) => {
      el.value = '';
    });

    // Hide spinner/success
    const spinner = modal.querySelector('.quote-spinner');
    if (spinner) spinner.classList.remove('show');

    this.updateProgress();
  }

  updateProgress() {
    const modal = document.getElementById('quoteModal');
    if (!modal) return;

    const fill = modal.querySelector('.quote-progress__fill');
    if (fill) {
      fill.style.width = `${((this.currentStep - 1) / (this.totalSteps - 1)) * 100}%`;
    }

    modal.querySelectorAll('.quote-progress__step').forEach((step) => {
      const num = parseInt(step.dataset.step);
      step.classList.remove('active', 'completed');
      if (num < this.currentStep) step.classList.add('completed');
      else if (num === this.currentStep) step.classList.add('active');
    });
  }

  goToStep(step) {
    const modal = document.getElementById('quoteModal');
    if (!modal) return;

    modal.querySelectorAll('.quote-step').forEach((s) => s.classList.remove('active'));
    const target = modal.querySelector(`.quote-step[data-step="${step}"]`);
    if (target) target.classList.add('active');

    this.currentStep = step;
    this.updateProgress();
  }

  nextStep() {
    const modal = document.getElementById('quoteModal');
    if (!modal) return;

    // Collect data from current step
    if (this.currentStep === 2) {
      this.formData.projectDescription = modal.querySelector('#quoteDescription')?.value || '';
      this.formData.timeline = modal.querySelector('#quoteTimeline')?.value || '';
      this.formData.budget = modal.querySelector('#quoteBudget')?.value || '';
    } else if (this.currentStep === 3) {
      const name = modal.querySelector('#quoteName')?.value || '';
      const email = modal.querySelector('#quoteEmail')?.value || '';

      if (!name.trim() || !email.trim()) {
        // Simple validation highlight
        if (!name.trim()) modal.querySelector('#quoteName')?.classList.add('error');
        if (!email.trim()) modal.querySelector('#quoteEmail')?.classList.add('error');
        return;
      }

      this.formData.fullName = name;
      this.formData.email = email;
      this.formData.phone = modal.querySelector('#quotePhone')?.value || '';
      this.formData.company = modal.querySelector('#quoteCompany')?.value || '';

      // Build review
      this.buildReview();
    }

    if (this.currentStep < 5) {
      this.goToStep(this.currentStep + 1);
    }
  }

  prevStep() {
    if (this.currentStep > 1) {
      this.goToStep(this.currentStep - 1);
    }
  }

  buildReview() {
    const modal = document.getElementById('quoteModal');
    const reviewEl = modal?.querySelector('#quoteReview');
    if (!reviewEl) return;

    const serviceLabels = {
      'diagnostic': 'Strategic Systems Diagnostic',
      'web-app': 'Custom Web App',
      'automation': 'Automation Systems',
      'other': 'Other',
    };

    reviewEl.innerHTML = `
      <div class="review-row"><span class="review-label">Service</span><span class="review-value">${serviceLabels[this.formData.service] || this.formData.service}</span></div>
      <div class="review-row"><span class="review-label">Description</span><span class="review-value">${this.formData.projectDescription || 'Not provided'}</span></div>
      <div class="review-row"><span class="review-label">Timeline</span><span class="review-value">${this.formData.timeline || 'Not specified'}</span></div>
      <div class="review-row"><span class="review-label">Budget</span><span class="review-value">${this.formData.budget || 'Not specified'}</span></div>
      <div class="review-divider"></div>
      <div class="review-row"><span class="review-label">Name</span><span class="review-value">${this.formData.fullName}</span></div>
      <div class="review-row"><span class="review-label">Email</span><span class="review-value">${this.formData.email}</span></div>
      ${this.formData.phone ? `<div class="review-row"><span class="review-label">Phone</span><span class="review-value">${this.formData.phone}</span></div>` : ''}
      ${this.formData.company ? `<div class="review-row"><span class="review-label">Company</span><span class="review-value">${this.formData.company}</span></div>` : ''}
    `;
  }

  async submit() {
    const modal = document.getElementById('quoteModal');
    if (!modal) return;

    const spinner = modal.querySelector('.quote-spinner');
    const step4 = modal.querySelector('.quote-step[data-step="4"]');

    if (spinner) spinner.classList.add('show');
    if (step4) step4.style.display = 'none';

    try {
      const response = await fetch('/api/send-quote.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(this.formData),
      });

      if (!response.ok) {
        const text = await response.text();
        console.error('Server error:', response.status, text);
        throw new Error('Server error: ' + response.status);
      }

      const result = await response.json();

      if (result.success) {
        if (spinner) spinner.classList.remove('show');
        this.goToStep(5);
      } else {
        throw new Error(result.message || 'Failed to send');
      }
    } catch (error) {
      if (spinner) spinner.classList.remove('show');
      if (step4) step4.style.display = 'block';
      console.error('Quote submission error:', error);
      alert('There was an error submitting your request. Please try again.');
    }
  }
}
