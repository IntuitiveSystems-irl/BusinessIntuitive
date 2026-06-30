/* ════════════════════════════════════════════════════════════════
   CHATBOT — receptionist that texts Lindsay via SMS deeplink
   Conversation flow imported from painted.html (custom build /
   ongoing care / one-off question paths). Styled to match the
   businessintuitive.tech dark Resn aesthetic.
   ════════════════════════════════════════════════════════════════ */

const PHONE = '7602100977';

const SETUP_PACKAGES = [
  {
    id: 'lite', name: 'Startup Lite', price: 350,
    desc: 'Simple landing page, contact form, mobile-ready, domain.',
    outcome: 'designed to get you online quickly and professionally',
    includes: [
      'Polished mobile-friendly landing page',
      'Inquiry / contact setup',
      'Clear positioning for your business',
      'Domain connection',
      'Launch-ready foundation',
    ],
  },
  {
    id: 'foundation', name: 'Startup Foundation', price: 1250,
    desc: '5-page site, license integration, hosting + maintenance.',
    outcome: 'designed to launch a legitimate, trusted presence',
    includes: [
      'Professional 5-page website',
      'License + credential integration',
      'Mobile-friendly across all devices',
      'Domain + professional email',
      'Hosting + maintenance included',
    ],
  },
  {
    id: 'growth', name: 'Growth Builder', price: 1950, recommended: true,
    desc: 'Service pages, local SEO, Google + Yelp, trust sections.',
    outcome: 'designed to actively grow your business and pull in qualified leads',
    includes: [
      'Dedicated service pages',
      'Conversion-focused contact forms',
      'Local SEO setup',
      'Google Business + Yelp integration',
      'Trust-building sections + sharper copy',
    ],
  },
  {
    id: 'authority', name: 'Authority Builder', price: 3000,
    desc: 'Service-area SEO, gallery, monthly content, analytics.',
    outcome: "built to dominate local search and become your area's go-to",
    includes: [
      'Service-area SEO pages',
      'Before/after gallery framework',
      'Ongoing SEO + monthly content',
      'Advanced lead generation setup',
      'Analytics + monthly reporting',
    ],
  },
];

const CARE_PLANS = [
  {
    id: 'startup-care', name: 'Startup Care', price: 99,
    desc: 'Hosting, security, small content edits, on-call support.',
    outcome: 'to keep things running smoothly after launch',
    includes: [
      'Hosting + security updates',
      'Small content edits',
      'On-call support when you need it',
    ],
  },
  {
    id: 'growth-care', name: 'Growth Care', price: 149, recommended: true,
    desc: 'Startup Care + SEO/copy refreshes, performance check-ins.',
    outcome: 'to keep your site fresh and growing',
    includes: [
      'Everything in Startup Care',
      'Regular SEO + copy refreshes',
      'Light content updates',
      'Performance check-ins',
    ],
  },
  {
    id: 'authority-care', name: 'Authority Care', price: 249,
    desc: 'Growth Care + monthly content piece + advanced analytics.',
    outcome: 'to keep you ahead of the competition',
    includes: [
      'Everything in Growth Care',
      'Ongoing SEO optimization',
      'Monthly blog or content piece',
      'Advanced analytics + reporting',
      'Lead-gen tuning',
    ],
  },
  {
    id: 'no-care', name: 'Not at this time', price: 0,
    desc: 'Skip the monthly plan — you can add later.',
    outcome: '',
    includes: [],
  },
];

const STYLES = `
.bi-chat-launcher {
  position: fixed;
  bottom: 2rem;
  right: 2rem;
  z-index: 90;
  display: inline-flex;
  align-items: center;
  gap: 12px;
  padding: 14px 22px;
  background: transparent;
  color: var(--color-text, #e8e8e8);
  border: 1px solid rgba(255,255,255,0.25);
  border-radius: 999px;
  font-family: var(--font-mono, 'JetBrains Mono', monospace);
  font-size: 11px;
  font-weight: 500;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  cursor: none;
  transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  background: rgba(8, 8, 8, 0.55);
}
.bi-chat-launcher:hover {
  border-color: var(--color-accent, #00d4aa);
  color: var(--color-accent, #00d4aa);
  transform: translateY(-2px);
}
.bi-chat-launcher.hidden {
  opacity: 0;
  transform: scale(0.7) translateY(20px);
  pointer-events: none;
}
.bi-chat-launcher__dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--color-accent, #00d4aa);
  box-shadow: 0 0 12px var(--color-accent, #00d4aa);
  animation: biChatPulse 2.4s ease-in-out infinite;
}
@keyframes biChatPulse {
  0%, 100% { opacity: 0.6; transform: scale(1); }
  50%      { opacity: 1; transform: scale(1.3); }
}
.bi-chat-launcher__arrow {
  font-size: 13px;
  transition: transform 0.3s ease;
}
.bi-chat-launcher:hover .bi-chat-launcher__arrow {
  transform: translate(2px, -2px);
}

.bi-chat-panel {
  position: fixed;
  bottom: 2rem;
  right: 2rem;
  z-index: 95;
  width: 400px;
  max-width: calc(100vw - 4rem);
  height: 620px;
  max-height: calc(100vh - 4rem);
  background: #0c0c0c;
  border: 1px solid rgba(255,255,255,0.10);
  border-radius: 16px;
  box-shadow:
    0 30px 80px -20px rgba(0, 0, 0, 0.8),
    0 0 0 1px rgba(0, 212, 170, 0.04),
    inset 0 1px 0 rgba(255,255,255,0.04);
  display: flex;
  flex-direction: column;
  opacity: 0;
  transform: translateY(20px) scale(0.96);
  pointer-events: none;
  transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
  overflow: hidden;
}
.bi-chat-panel.open {
  opacity: 1;
  transform: translateY(0) scale(1);
  pointer-events: auto;
}

.bi-chat-header {
  padding: 18px 20px;
  border-bottom: 1px solid rgba(255,255,255,0.08);
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-shrink: 0;
}
.bi-chat-title { display: flex; align-items: center; gap: 12px; }
.bi-chat-avatar {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--color-accent, #00d4aa), var(--color-accent2, #00b4d8));
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: var(--font-heading, 'Inter', sans-serif);
  font-size: 16px;
  font-weight: 600;
  color: #0a0a0a;
  letter-spacing: -0.02em;
}
.bi-chat-name {
  font-family: var(--font-heading, 'Inter', sans-serif);
  font-size: 14px;
  font-weight: 500;
  color: var(--color-text, #e8e8e8);
  letter-spacing: -0.01em;
}
.bi-chat-status {
  font-family: var(--font-mono, 'JetBrains Mono', monospace);
  font-size: 9px;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: rgba(255,255,255,0.4);
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 2px;
}
.bi-chat-status__dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--color-accent, #00d4aa);
  box-shadow: 0 0 8px var(--color-accent, #00d4aa);
}
.bi-chat-close {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: transparent;
  border: 1px solid rgba(255,255,255,0.12);
  color: var(--color-text, #e8e8e8);
  font-size: 18px;
  line-height: 1;
  cursor: none;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s ease;
  padding: 0;
}
.bi-chat-close:hover {
  border-color: var(--color-accent, #00d4aa);
  color: var(--color-accent, #00d4aa);
}

.bi-chat-messages {
  flex: 1;
  overflow-y: auto;
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  scrollbar-width: thin;
  scrollbar-color: rgba(255,255,255,0.15) transparent;
}
.bi-chat-messages::-webkit-scrollbar { width: 6px; }
.bi-chat-messages::-webkit-scrollbar-track { background: transparent; }
.bi-chat-messages::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 3px; }

@keyframes biChatMsgIn {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: translateY(0); }
}
.bi-chat-msg {
  max-width: 80%;
  padding: 11px 15px;
  border-radius: 14px;
  font-family: var(--font-heading, 'Inter', sans-serif);
  font-size: 13.5px;
  line-height: 1.55;
  animation: biChatMsgIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);
  white-space: pre-wrap;
}
.bi-chat-msg.bot {
  align-self: flex-start;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.08);
  color: var(--color-text, #e8e8e8);
  border-bottom-left-radius: 4px;
}
.bi-chat-msg.user {
  align-self: flex-end;
  background: rgba(0, 212, 170, 0.12);
  border: 1px solid rgba(0, 212, 170, 0.3);
  color: var(--color-text, #e8e8e8);
  border-bottom-right-radius: 4px;
}
.bi-chat-msg.summary {
  align-self: stretch;
  max-width: 100%;
  background: rgba(0, 212, 170, 0.06);
  border: 1px solid rgba(0, 212, 170, 0.2);
  color: var(--color-text, #e8e8e8);
  font-family: var(--font-mono, 'JetBrains Mono', monospace);
  font-size: 11px;
  letter-spacing: 0.04em;
  white-space: pre-line;
  line-height: 1.7;
}

.bi-chat-typing {
  align-self: flex-start;
  padding: 12px 16px;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 14px;
  border-bottom-left-radius: 4px;
  display: flex;
  gap: 4px;
}
.bi-chat-typing span {
  width: 6px; height: 6px;
  border-radius: 50%;
  background: rgba(255,255,255,0.5);
  animation: biChatTyping 1.4s ease-in-out infinite;
}
.bi-chat-typing span:nth-child(2) { animation-delay: 0.15s; }
.bi-chat-typing span:nth-child(3) { animation-delay: 0.3s; }
@keyframes biChatTyping {
  0%, 60%, 100% { opacity: 0.3; transform: translateY(0); }
  30%           { opacity: 1; transform: translateY(-4px); }
}

.bi-chat-input-area {
  padding: 16px 20px 20px;
  border-top: 1px solid rgba(255,255,255,0.08);
  flex-shrink: 0;
  max-height: 56%;
  overflow-y: auto;
  scrollbar-width: thin;
  scrollbar-color: rgba(255,255,255,0.15) transparent;
}
.bi-chat-input-area::-webkit-scrollbar { width: 6px; }
.bi-chat-input-area::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 3px; }

.bi-chat-options {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.bi-chat-option {
  padding: 12px 16px;
  background: transparent;
  border: 1px solid rgba(255,255,255,0.15);
  border-radius: 10px;
  color: var(--color-text, #e8e8e8);
  font-family: var(--font-heading, 'Inter', sans-serif);
  font-size: 13px;
  text-align: left;
  cursor: none;
  transition: all 0.2s ease;
}
.bi-chat-option:hover {
  border-color: var(--color-accent, #00d4aa);
  color: var(--color-accent, #00d4aa);
  transform: translateX(2px);
}

.bi-chat-input {
  width: 100%;
  padding: 12px 14px;
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(255,255,255,0.12);
  border-radius: 10px;
  color: var(--color-text, #e8e8e8);
  font-family: var(--font-heading, 'Inter', sans-serif);
  font-size: 13px;
  outline: none;
  resize: vertical;
  min-height: 44px;
  cursor: none;
  transition: border-color 0.2s ease, background 0.2s ease;
}
textarea.bi-chat-input { min-height: 84px; }
.bi-chat-input::placeholder { color: rgba(255,255,255,0.3); }
.bi-chat-input:focus {
  border-color: var(--color-accent, #00d4aa);
  background: rgba(255,255,255,0.06);
}

.bi-chat-submit-row {
  margin-top: 10px;
  display: flex;
  justify-content: flex-end;
}
.bi-chat-submit {
  padding: 11px 22px;
  background: var(--color-accent, #00d4aa);
  color: #0a0a0a;
  border: 1px solid var(--color-accent, #00d4aa);
  border-radius: 999px;
  font-family: var(--font-mono, 'JetBrains Mono', monospace);
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  cursor: none;
  transition: all 0.2s ease;
}
.bi-chat-submit:hover {
  background: var(--color-accent2, #00b4d8);
  border-color: var(--color-accent2, #00b4d8);
  transform: translateY(-1px);
}

.bi-chat-picker {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.bi-chat-picker-group {
  font-family: var(--font-mono, 'JetBrains Mono', monospace);
  font-size: 9px;
  letter-spacing: 0.24em;
  text-transform: uppercase;
  color: rgba(255,255,255,0.45);
  margin: 2px 0 4px;
}
.bi-chat-pick {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  padding: 12px 14px;
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.10);
  border-radius: 10px;
  cursor: none;
  transition: all 0.2s ease;
  text-align: left;
  font-family: inherit;
  width: 100%;
  color: var(--color-text, #e8e8e8);
}
.bi-chat-pick:hover {
  border-color: var(--color-accent, #00d4aa);
  background: rgba(0, 212, 170, 0.04);
  transform: translateX(2px);
}
.bi-chat-pick-info { flex: 1; min-width: 0; }
.bi-chat-pick-name {
  font-family: var(--font-heading, 'Inter', sans-serif);
  font-size: 13px;
  font-weight: 500;
  color: var(--color-text, #e8e8e8);
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  letter-spacing: -0.01em;
}
.bi-chat-pick-rec {
  font-family: var(--font-mono, 'JetBrains Mono', monospace);
  font-size: 8px;
  letter-spacing: 0.18em;
  background: var(--color-accent, #00d4aa);
  color: #0a0a0a;
  padding: 2px 7px;
  border-radius: 999px;
  font-weight: 600;
}
.bi-chat-pick-desc {
  font-family: var(--font-heading, 'Inter', sans-serif);
  font-size: 11.5px;
  color: rgba(255,255,255,0.5);
  margin-top: 3px;
  line-height: 1.4;
}
.bi-chat-pick-price {
  font-family: var(--font-mono, 'JetBrains Mono', monospace);
  font-size: 13px;
  color: var(--color-accent, #00d4aa);
  white-space: nowrap;
  font-weight: 500;
}
.bi-chat-pick-price small {
  font-size: 10px;
  color: rgba(255,255,255,0.4);
}

/* Editorial recommendation card */
.bi-chat-recommendation {
  align-self: stretch;
  max-width: 100%;
  background: rgba(0, 212, 170, 0.04);
  border: 1px solid rgba(0, 212, 170, 0.2);
  padding: 16px;
  border-radius: 12px;
  display: flex;
  flex-direction: column;
  gap: 14px;
  animation: biChatMsgIn 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}
.bi-chat-rec-section { display: flex; flex-direction: column; gap: 8px; }
.bi-chat-rec-label {
  font-family: var(--font-mono, 'JetBrains Mono', monospace);
  font-size: 9px;
  letter-spacing: 0.24em;
  text-transform: uppercase;
  color: var(--color-accent, #00d4aa);
}
.bi-chat-rec-label.alt { color: var(--color-accent2, #00b4d8); }
.bi-chat-rec-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 5px;
}
.bi-chat-rec-list li {
  font-family: var(--font-heading, 'Inter', sans-serif);
  font-size: 12.5px;
  line-height: 1.5;
  color: var(--color-text, #e8e8e8);
  padding-left: 18px;
  position: relative;
}
.bi-chat-rec-list li::before {
  content: "";
  position: absolute;
  left: 4px; top: 8px;
  width: 5px; height: 5px;
  border-radius: 50%;
  background: var(--color-accent, #00d4aa);
}
.bi-chat-rec-list.alt li::before { background: var(--color-accent2, #00b4d8); }
.bi-chat-rec-divider {
  border: 0;
  border-top: 1px solid rgba(255,255,255,0.08);
  margin: 0;
}
.bi-chat-rec-meta {
  font-family: var(--font-mono, 'JetBrains Mono', monospace);
  font-size: 10px;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: rgba(255,255,255,0.5);
}
.bi-chat-rec-meta strong {
  color: var(--color-text, #e8e8e8);
  font-weight: 500;
  margin-left: 8px;
}

/* When chat is open on mobile, hide the page chrome behind it */
@media (max-width: 640px) {
  body.bi-chat-open .nav,
  body.bi-chat-open .sound-toggle {
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s ease;
  }
  body.bi-chat-open {
    overflow: hidden;
  }
}

@media (max-width: 640px) {
  .bi-chat-launcher {
    bottom: calc(1rem + env(safe-area-inset-bottom, 0px));
    right: 1rem;
    padding: 11px 16px;
    font-size: 10px;
    gap: 9px;
    letter-spacing: 0.14em;
  }
  .bi-chat-launcher__dot { width: 5px; height: 5px; }
  .bi-chat-launcher__arrow { font-size: 12px; }

  .bi-chat-panel {
    bottom: 0; left: 0; right: 0;
    width: 100%;
    max-width: 100%;
    height: 100vh;
    height: 100dvh;
    max-height: 100vh;
    max-height: 100dvh;
    border-radius: 18px 18px 0 0;
    border-bottom: none;
    padding-bottom: env(safe-area-inset-bottom, 0px);
  }

  .bi-chat-header {
    padding: 14px 16px;
  }
  .bi-chat-avatar {
    width: 34px; height: 34px;
    font-size: 14px;
  }
  .bi-chat-name { font-size: 13.5px; }
  .bi-chat-status { font-size: 8px; letter-spacing: 0.16em; }
  .bi-chat-close {
    width: 30px; height: 30px;
    font-size: 17px;
  }

  .bi-chat-messages {
    padding: 16px;
    gap: 10px;
  }
  .bi-chat-msg {
    max-width: 86%;
    padding: 10px 13px;
    font-size: 13px;
    border-radius: 13px;
  }
  .bi-chat-msg.bot { border-bottom-left-radius: 4px; }
  .bi-chat-msg.user { border-bottom-right-radius: 4px; }
  .bi-chat-msg.summary {
    font-size: 10.5px;
    padding: 12px 14px;
    line-height: 1.65;
  }

  .bi-chat-input-area {
    padding: 12px 16px 14px;
    max-height: 62%;
  }

  .bi-chat-options { gap: 7px; }
  .bi-chat-option {
    padding: 11px 14px;
    font-size: 12.5px;
  }

  .bi-chat-picker { gap: 5px; }
  .bi-chat-picker-group { font-size: 8.5px; letter-spacing: 0.22em; }
  .bi-chat-pick {
    padding: 11px 12px;
    gap: 10px;
    border-radius: 9px;
  }
  .bi-chat-pick-name {
    font-size: 12.5px;
    gap: 6px;
  }
  .bi-chat-pick-desc {
    font-size: 11px;
    margin-top: 2px;
  }
  .bi-chat-pick-price {
    font-size: 12px;
  }
  .bi-chat-pick-rec {
    font-size: 7.5px;
    padding: 2px 6px;
    letter-spacing: 0.14em;
  }

  .bi-chat-input {
    padding: 11px 13px;
    font-size: 13px;
    border-radius: 9px;
  }
  textarea.bi-chat-input { min-height: 70px; }

  .bi-chat-submit-row { margin-top: 8px; }
  .bi-chat-submit {
    padding: 10px 18px;
    font-size: 9.5px;
    letter-spacing: 0.16em;
  }

  .bi-chat-recommendation {
    padding: 14px;
    gap: 12px;
    border-radius: 11px;
  }
  .bi-chat-rec-list { gap: 4px; }
  .bi-chat-rec-list li {
    font-size: 12px;
    padding-left: 16px;
  }
  .bi-chat-rec-list li::before { left: 3px; top: 7px; }
  .bi-chat-rec-label { font-size: 8.5px; letter-spacing: 0.22em; }
  .bi-chat-rec-meta { font-size: 9.5px; letter-spacing: 0.16em; }
}

@media (max-width: 380px) {
  .bi-chat-launcher {
    padding: 10px 13px;
    font-size: 9.5px;
    gap: 7px;
  }
  .bi-chat-msg { max-width: 90%; font-size: 12.5px; }
  .bi-chat-pick { padding: 10px 11px; gap: 8px; }
  .bi-chat-pick-name { font-size: 12px; }
  .bi-chat-pick-desc { font-size: 10.5px; }
  .bi-chat-pick-price { font-size: 11.5px; }
}
`;

export class Chatbot {
  constructor(options = {}) {
    this.phone = options.phone || PHONE;
    this.launcherLabel = options.launcherLabel || 'Book a build';
    this.state = this._initialState();

    this._injectStyles();
    this._buildMarkup();
    this._wireEvents();
  }

  _initialState() {
    return {
      intent: null, setup: null, care: null,
      name: '', business: '', project: '', timeline: '', question: '',
      started: false,
    };
  }

  _injectStyles() {
    if (document.getElementById('bi-chat-styles')) return;
    const style = document.createElement('style');
    style.id = 'bi-chat-styles';
    style.textContent = STYLES;
    document.head.appendChild(style);
  }

  _buildMarkup() {
    // Launcher
    const launcher = document.createElement('button');
    launcher.className = 'bi-chat-launcher';
    launcher.id = 'biChatLauncher';
    launcher.setAttribute('aria-label', this.launcherLabel);
    launcher.innerHTML = `
      <span class="bi-chat-launcher__dot"></span>
      ${this.launcherLabel}
      <span class="bi-chat-launcher__arrow">↗</span>
    `;
    document.body.appendChild(launcher);

    // Panel
    const panel = document.createElement('div');
    panel.className = 'bi-chat-panel';
    panel.id = 'biChatPanel';
    panel.setAttribute('aria-hidden', 'true');
    panel.innerHTML = `
      <div class="bi-chat-header">
        <div class="bi-chat-title">
          <div class="bi-chat-avatar">L</div>
          <div>
            <div class="bi-chat-name">Lindsay's Studio</div>
            <div class="bi-chat-status">
              <span class="bi-chat-status__dot"></span>
              usually replies in &lt; 24h
            </div>
          </div>
        </div>
        <button class="bi-chat-close" id="biChatClose" aria-label="Close chat">×</button>
      </div>
      <div class="bi-chat-messages" id="biChatMessages"></div>
      <div class="bi-chat-input-area" id="biChatInputArea"></div>
    `;
    document.body.appendChild(panel);

    this.launcher = launcher;
    this.panel = panel;
    this.closeBtn = panel.querySelector('#biChatClose');
    this.messages = panel.querySelector('#biChatMessages');
    this.inputArea = panel.querySelector('#biChatInputArea');
  }

  _wireEvents() {
    this.launcher.addEventListener('click', () => this.open());
    this.closeBtn.addEventListener('click', () => this.close());
    window.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && this.panel.classList.contains('open')) this.close();
    });

    // External openers
    document.querySelectorAll('[data-chat-open]').forEach((el) => {
      el.addEventListener('click', (e) => {
        e.preventDefault();
        this.open();
      });
    });

    // Cursor hover wiring (matches existing custom cursor system)
    this._wireCursor(this.launcher);
    this._wireCursor(this.closeBtn);

    // Delegate hover for dynamically-created elements inside the panel
    this.panel.addEventListener('mouseover', (e) => {
      if (e.target.closest('button, input, textarea, .bi-chat-pick, .bi-chat-option')) {
        document.body.classList.add('cursor-hover');
      }
    });
    this.panel.addEventListener('mouseout', (e) => {
      if (e.target.closest('button, input, textarea, .bi-chat-pick, .bi-chat-option')) {
        document.body.classList.remove('cursor-hover');
      }
    });
  }

  _wireCursor(el) {
    el.addEventListener('mouseenter', () => document.body.classList.add('cursor-hover'));
    el.addEventListener('mouseleave', () => document.body.classList.remove('cursor-hover'));
  }

  /* ──── DOM helpers ──── */
  _sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }
  _scroll() { this.messages.scrollTop = this.messages.scrollHeight; }

  _showTyping() {
    const t = document.createElement('div');
    t.className = 'bi-chat-typing';
    t.id = 'biChatTyping';
    t.innerHTML = '<span></span><span></span><span></span>';
    this.messages.appendChild(t);
    this._scroll();
  }
  _hideTyping() { document.getElementById('biChatTyping')?.remove(); }

  async _botSay(text, delay = 700) {
    this._showTyping();
    await this._sleep(delay);
    this._hideTyping();
    const m = document.createElement('div');
    m.className = 'bi-chat-msg bot';
    m.textContent = text;
    this.messages.appendChild(m);
    this._scroll();
  }
  _userSay(text) {
    const m = document.createElement('div');
    m.className = 'bi-chat-msg user';
    m.textContent = text;
    this.messages.appendChild(m);
    this._scroll();
  }
  _clearInput() { this.inputArea.innerHTML = ''; }

  _showOptions(opts, onPick) {
    this._clearInput();
    const wrap = document.createElement('div');
    wrap.className = 'bi-chat-options';
    opts.forEach((o) => {
      const b = document.createElement('button');
      b.className = 'bi-chat-option';
      b.textContent = o.label;
      b.onclick = () => {
        this._userSay(o.label);
        this._clearInput();
        onPick(o);
      };
      wrap.appendChild(b);
    });
    this.inputArea.appendChild(wrap);
  }

  _showInput(placeholder, multiline, onSubmit) {
    this._clearInput();
    const input = multiline ? document.createElement('textarea') : document.createElement('input');
    input.className = 'bi-chat-input';
    input.placeholder = placeholder;
    if (!multiline) input.type = 'text';
    const row = document.createElement('div');
    row.className = 'bi-chat-submit-row';
    const submit = document.createElement('button');
    submit.className = 'bi-chat-submit';
    submit.textContent = 'Continue →';
    submit.onclick = () => {
      const v = input.value.trim();
      if (!v) return;
      this._userSay(v);
      this._clearInput();
      onSubmit(v);
    };
    if (!multiline) {
      input.onkeypress = (e) => { if (e.key === 'Enter') submit.click(); };
    }
    row.appendChild(submit);
    this.inputArea.appendChild(input);
    this.inputArea.appendChild(row);
    setTimeout(() => input.focus(), 100);
  }

  _showPicker(items, groupLabel, onPick, formatPrice) {
    this._clearInput();
    const picker = document.createElement('div');
    picker.className = 'bi-chat-picker';
    const label = document.createElement('div');
    label.className = 'bi-chat-picker-group';
    label.textContent = groupLabel;
    picker.appendChild(label);
    items.forEach((p) => {
      const b = document.createElement('button');
      b.className = 'bi-chat-pick';
      b.innerHTML = `
        <div class="bi-chat-pick-info">
          <div class="bi-chat-pick-name">${p.name}${p.recommended ? '<span class="bi-chat-pick-rec">Rec</span>' : ''}</div>
          <div class="bi-chat-pick-desc">${p.desc}</div>
        </div>
        <div class="bi-chat-pick-price">${formatPrice(p)}</div>
      `;
      b.onclick = () => {
        const lbl = `${p.name}${p.price > 0 ? ' — ' + formatPrice(p).replace(/<[^>]+>/g, '') : ''}`;
        this._userSay(lbl);
        this._clearInput();
        onPick(p);
      };
      picker.appendChild(b);
    });
    this.inputArea.appendChild(picker);
  }

  _showSetupPicker(onPick) {
    this._showPicker(SETUP_PACKAGES, 'Setup package', onPick,
      (p) => `$${p.price.toLocaleString()}`);
  }
  _showCarePicker(onPick) {
    this._showPicker(CARE_PLANS, 'Ongoing care plan', onPick,
      (p) => p.price > 0 ? `$${p.price}<small>/mo</small>` : 'Skip');
  }

  _showTotalSummary() {
    const setup = SETUP_PACKAGES.find((p) => p.id === this.state.setup);
    const care = CARE_PLANS.find((p) => p.id === this.state.care);
    const setupPrice = setup ? setup.price : 0;
    const carePrice = care ? care.price : 0;
    const m = document.createElement('div');
    m.className = 'bi-chat-msg summary';
    let txt = '';
    if (setup) txt += `${setup.name}: $${setupPrice.toLocaleString()}\n`;
    if (care && care.price > 0) txt += `${care.name}: $${care.price}/mo\n`;
    if (care && care.price === 0) txt += `No monthly plan\n`;
    txt += `\nTotal today: $${setupPrice.toLocaleString()}`;
    if (carePrice > 0) txt += `\nThen: $${carePrice}/mo`;
    m.textContent = txt;
    this.messages.appendChild(m);
    this._scroll();
  }

  _buildSmsBody() {
    const lines = [];
    const greeting = this.state.intent === 'build' ? 'Custom build request'
                  : this.state.intent === 'care'  ? 'Hosting / care request'
                  : 'Question from your site';
    lines.push(greeting + (this.state.name ? ` from ${this.state.name}` : ''));
    if (this.state.business) lines.push(`Project: ${this.state.business}`);

    if (this.state.intent === 'build') {
      const setup = SETUP_PACKAGES.find((p) => p.id === this.state.setup);
      const care = CARE_PLANS.find((p) => p.id === this.state.care);
      if (setup) lines.push(`Setup: ${setup.name} ($${setup.price.toLocaleString()})`);
      if (care)  lines.push(`Care: ${care.name}${care.price > 0 ? ' ($' + care.price + '/mo)' : ' — skip for now'}`);
    }
    if (this.state.intent === 'care') {
      const care = CARE_PLANS.find((p) => p.id === this.state.care);
      if (care) lines.push(`Plan: ${care.name}${care.price > 0 ? ' ($' + care.price + '/mo)' : ''}`);
    }

    if (this.state.project)  lines.push('', this.state.project);
    if (this.state.question) lines.push('', this.state.question);
    if (this.state.timeline) lines.push('', `Timeline: ${this.state.timeline}`);
    lines.push('', '— sent via businessintuitive.tech');
    return lines.join('\n');
  }

  async _collectEmailAndSend() {
    this._clearInput();
    await this._botSay("What's the best email for you? I'll send this straight to Lindsay and she'll reply there.", 450);
    const ask = () => {
      this._showInput('you@company.com', false, async (email) => {
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
          await this._botSay("That email doesn't look quite right — mind double-checking?", 350);
          ask();
          return;
        }
        await this._deliverViaApi(email);
      });
    };
    ask();
  }

  async _deliverViaApi(email) {
    this._clearInput();
    this._showTyping();
    let ok = false;
    try {
      const res = await fetch('/api/diagnostic-request.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: this.state.name || 'Site visitor',
          email: email,
          firm: this.state.business || this.state.name || 'Not provided',
          challenge: this._buildSmsBody(),
          source: 'homepage-chat',
        }),
      });
      const json = await res.json();
      ok = !!json.success;
    } catch (err) { ok = false; }
    this._hideTyping();
    if (!ok) {
      await this._botSay("Hmm, that didn't go through. Tap below to send it by email instead.", 300);
      const wrap = document.createElement('div');
      wrap.className = 'bi-chat-options';
      const mail = document.createElement('button');
      mail.className = 'bi-chat-submit';
      mail.textContent = 'Open email draft →';
      mail.onclick = () => {
        const body = encodeURIComponent(this._buildSmsBody());
        window.location.href = `mailto:hi@businessintuitive.tech?subject=${encodeURIComponent('Build request — businessintuitive.tech')}&body=${body}`;
      };
      wrap.appendChild(mail);
      this.inputArea.appendChild(wrap);
      return;
    }
    if (window.biTrack) window.biTrack('lead_submit', 1, { form: 'chatbot' });
    await this._botSay("Done — it's in Lindsay's inbox. She usually replies within a day.", 300);
    this._clearInput();
    const restart = document.createElement('button');
    restart.className = 'bi-chat-option';
    restart.textContent = 'Start over';
    restart.onclick = () => this.reset();
    this.inputArea.appendChild(restart);
  }

  _buildRecommendationCard() {
    const card = document.createElement('div');
    card.className = 'bi-chat-recommendation';
    const setup = SETUP_PACKAGES.find((p) => p.id === this.state.setup);
    const care  = CARE_PLANS.find((p) => p.id === this.state.care);

    if (setup && setup.includes.length) {
      const sec = document.createElement('div');
      sec.className = 'bi-chat-rec-section';
      sec.innerHTML = `
        <div class="bi-chat-rec-label">What this could include</div>
        <ul class="bi-chat-rec-list">
          ${setup.includes.map((item) => `<li>${item}</li>`).join('')}
        </ul>
      `;
      card.appendChild(sec);
    }

    if (care && care.includes.length) {
      if (setup && setup.includes.length) {
        const div = document.createElement('hr');
        div.className = 'bi-chat-rec-divider';
        card.appendChild(div);
      }
      const sec = document.createElement('div');
      sec.className = 'bi-chat-rec-section';
      sec.innerHTML = `
        <div class="bi-chat-rec-label alt">Ongoing — ${care.name}</div>
        <ul class="bi-chat-rec-list alt">
          ${care.includes.map((item) => `<li>${item}</li>`).join('')}
        </ul>
      `;
      card.appendChild(sec);
    }

    if (this.state.timeline) {
      const div = document.createElement('hr');
      div.className = 'bi-chat-rec-divider';
      card.appendChild(div);
      const meta = document.createElement('div');
      meta.className = 'bi-chat-rec-meta';
      meta.innerHTML = `Timeline <strong>${this.state.timeline}</strong>`;
      card.appendChild(meta);
    }

    return card;
  }

  async _showSendButton() {
    this._clearInput();
    await this._botSay('Beautiful — I have what I need.', 600);

    let recoLine = '';
    if (this.state.intent === 'build') {
      const pkg = SETUP_PACKAGES.find((p) => p.id === this.state.setup);
      if (pkg) recoLine = `Based on what you shared, I'd likely recommend a ${pkg.name} build ${pkg.outcome}.`;
    } else if (this.state.intent === 'care') {
      const plan = CARE_PLANS.find((p) => p.id === this.state.care);
      if (plan && plan.price > 0) recoLine = `Based on what you shared, I'd recommend ${plan.name} ${plan.outcome}.`;
      else recoLine = "Got it — Lindsay can scope a care plan with you whenever you're ready.";
    } else {
      recoLine = "I'll get this in front of Lindsay directly.";
    }
    if (recoLine) await this._botSay(recoLine, 750);

    const setup = SETUP_PACKAGES.find((p) => p.id === this.state.setup);
    const care  = CARE_PLANS.find((p) => p.id === this.state.care);
    const showCard = (setup && setup.includes.length) || (care && care.includes.length);
    if (showCard) {
      await this._sleep(300);
      const card = this._buildRecommendationCard();
      this.messages.appendChild(card);
      this._scroll();
    }

    await this._sleep(400);
    await this._botSay("Tap below and I'll send this to Lindsay for review.", 500);

    const wrap = document.createElement('div');
    wrap.className = 'bi-chat-options';
    const send = document.createElement('button');
    send.className = 'bi-chat-submit';
    send.textContent = 'Send Request →';
    send.onclick = async () => {
      const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
      if (!isMobile) { await this._collectEmailAndSend(); return; }
      const body = encodeURIComponent(this._buildSmsBody());
      window.location.href = `sms:${this.phone}?&body=${body}`;
      await this._sleep(400);
      await this._botSay('Sending now — your messages app should open. Just hit send to deliver it.', 300);
      this._clearInput();
      const restart = document.createElement('button');
      restart.className = 'bi-chat-option';
      restart.textContent = 'Start over';
      restart.onclick = () => this.reset();
      this.inputArea.appendChild(restart);
    };
    wrap.appendChild(send);
    this.inputArea.appendChild(wrap);
  }

  /* ──── Conversation flows ──── */
  async _start() {
    await this._botSay("Hey 👋 I'm Lindsay's studio assistant.", 300);
    await this._botSay('What brings you in today?');
    this._showOptions([
      { label: 'Custom build',        value: 'build' },
      { label: 'Just hosting / care', value: 'care' },
      { label: 'Have a question',     value: 'question' },
    ], async (opt) => {
      this.state.intent = opt.value;
      if (opt.value === 'build')      await this._flowBuild();
      else if (opt.value === 'care')  await this._flowCare();
      else                            await this._flowQuestion();
    });
  }

  async _flowBuild() {
    await this._botSay('Cool. Pick the setup that fits — you can adjust this with Lindsay later.');
    this._showSetupPicker(async (pkg) => {
      this.state.setup = pkg.id;
      await this._botSay('Want ongoing care? She handles hosting, security, content edits, and SEO.');
      this._showCarePicker(async (plan) => {
        this.state.care = plan.id;
        await this._botSay("Here's where you land:");
        await this._sleep(200);
        this._showTotalSummary();
        await this._sleep(400);
        await this._botSay("What's your name?");
        this._showInput('Your name', false, async (name) => {
          this.state.name = name;
          await this._botSay("What's the business or project called?");
          this._showInput('Project / business', false, async (biz) => {
            this.state.business = biz;
            await this._botSay('Tell me a bit about what you want to build.');
            this._showInput('A few sentences is plenty', true, async (proj) => {
              this.state.project = proj;
              await this._botSay('When are you hoping to launch?');
              this._showOptions([
                { label: 'ASAP',              value: 'asap' },
                { label: 'Within 1 month',    value: '1mo' },
                { label: '1–3 months',        value: '2-3mo' },
                { label: 'Flexible',          value: 'flexible' },
              ], async (opt) => {
                this.state.timeline = opt.label;
                await this._showSendButton();
              });
            });
          });
        });
      });
    });
  }

  async _flowCare() {
    await this._botSay('Got it. Pick a care plan — Lindsay can adjust the details with you over text.');
    this._showCarePicker(async (plan) => {
      this.state.care = plan.id;
      await this._botSay("What's your name?");
      this._showInput('Your name', false, async (name) => {
        this.state.name = name;
        await this._botSay("What's your site URL? (or business name if no site yet)");
        this._showInput('yoursite.com', false, async (biz) => {
          this.state.business = biz;
          await this._showSendButton();
        });
      });
    });
  }

  async _flowQuestion() {
    await this._botSay("What's on your mind? I'll text Lindsay your question.");
    this._showInput('Your question', true, async (q) => {
      this.state.question = q;
      await this._botSay("What's your name?");
      this._showInput('Your name', false, async (name) => {
        this.state.name = name;
        await this._showSendButton();
      });
    });
  }

  reset() {
    this.state = this._initialState();
    this.messages.innerHTML = '';
    this.inputArea.innerHTML = '';
    this.state.started = true;
    this._start();
  }

  open() {
    this.panel.classList.add('open');
    this.launcher.classList.add('hidden');
    this.panel.setAttribute('aria-hidden', 'false');
    document.body.classList.add('bi-chat-open');
    if (!this.state.started) {
      this.state.started = true;
      this._start();
    }
  }

  close() {
    this.panel.classList.remove('open');
    this.launcher.classList.remove('hidden');
    this.panel.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('bi-chat-open');
  }
}
