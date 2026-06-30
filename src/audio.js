export class AudioSystem {
  constructor() {
    this.isPlaying = false;
    this.ctx = null;
    this.masterGain = null;
    this.initialized = false;
  }

  init() {
    if (this.initialized) return;
    this.initialized = true;

    this.ctx = new (window.AudioContext || window.webkitAudioContext)();
    this.masterGain = this.ctx.createGain();
    this.masterGain.gain.value = 0;
    this.masterGain.connect(this.ctx.destination);

    // Create ambient pad layers
    this.createPadLayer(55, 'sine', 0.12);      // Low bass drone
    this.createPadLayer(110, 'sine', 0.06);      // Octave harmonic
    this.createPadLayer(165, 'sine', 0.03);      // Fifth
    this.createPadLayer(220, 'triangle', 0.02);  // High shimmer
    this.createPadLayer(330, 'sine', 0.015);     // Upper harmonic

    // Create subtle noise layer for texture
    this.createNoiseLayer(0.015);

    // LFO modulation for movement
    this.createLFO();
  }

  createPadLayer(freq, type, volume) {
    const osc = this.ctx.createOscillator();
    const gain = this.ctx.createGain();
    const filter = this.ctx.createBiquadFilter();

    osc.type = type;
    osc.frequency.value = freq;

    // Subtle detuning for richness
    osc.detune.value = (Math.random() - 0.5) * 10;

    filter.type = 'lowpass';
    filter.frequency.value = 800;
    filter.Q.value = 1;

    gain.gain.value = volume;

    osc.connect(filter);
    filter.connect(gain);
    gain.connect(this.masterGain);

    osc.start();

    // Slow frequency drift for organic feel
    const drift = () => {
      if (!this.isPlaying) return;
      const now = this.ctx.currentTime;
      osc.frequency.setTargetAtTime(
        freq + (Math.random() - 0.5) * 2,
        now,
        4
      );
      filter.frequency.setTargetAtTime(
        600 + Math.random() * 400,
        now,
        3
      );
      setTimeout(drift, 3000 + Math.random() * 4000);
    };
    drift();
  }

  createNoiseLayer(volume) {
    const bufferSize = this.ctx.sampleRate * 2;
    const buffer = this.ctx.createBuffer(1, bufferSize, this.ctx.sampleRate);
    const data = buffer.getChannelData(0);

    for (let i = 0; i < bufferSize; i++) {
      data[i] = (Math.random() * 2 - 1) * 0.5;
    }

    const noise = this.ctx.createBufferSource();
    noise.buffer = buffer;
    noise.loop = true;

    const filter = this.ctx.createBiquadFilter();
    filter.type = 'bandpass';
    filter.frequency.value = 300;
    filter.Q.value = 0.5;

    const gain = this.ctx.createGain();
    gain.gain.value = volume;

    noise.connect(filter);
    filter.connect(gain);
    gain.connect(this.masterGain);

    noise.start();
  }

  createLFO() {
    const lfo = this.ctx.createOscillator();
    const lfoGain = this.ctx.createGain();

    lfo.type = 'sine';
    lfo.frequency.value = 0.1; // Very slow modulation

    lfoGain.gain.value = 0.02;

    lfo.connect(lfoGain);
    lfoGain.connect(this.masterGain.gain);

    lfo.start();
  }

  toggle() {
    if (!this.initialized) {
      this.init();
    }

    if (this.ctx.state === 'suspended') {
      this.ctx.resume();
    }

    if (this.isPlaying) {
      this.fadeOut();
    } else {
      this.fadeIn();
    }

    this.isPlaying = !this.isPlaying;
    return this.isPlaying;
  }

  fadeIn() {
    const now = this.ctx.currentTime;
    this.masterGain.gain.cancelScheduledValues(now);
    this.masterGain.gain.setValueAtTime(this.masterGain.gain.value, now);
    this.masterGain.gain.linearRampToValueAtTime(0.8, now + 2);
  }

  fadeOut() {
    const now = this.ctx.currentTime;
    this.masterGain.gain.cancelScheduledValues(now);
    this.masterGain.gain.setValueAtTime(this.masterGain.gain.value, now);
    this.masterGain.gain.linearRampToValueAtTime(0, now + 1.5);
  }

  setIntensity(value) {
    if (!this.initialized || !this.isPlaying) return;
    const clampedValue = Math.max(0, Math.min(1, value));
    const now = this.ctx.currentTime;
    this.masterGain.gain.setTargetAtTime(clampedValue * 0.8, now, 0.5);
  }

  playInteractionSound() {
    if (!this.initialized || !this.isPlaying) return;

    const osc = this.ctx.createOscillator();
    const gain = this.ctx.createGain();

    osc.type = 'sine';
    osc.frequency.value = 440 + Math.random() * 440;

    gain.gain.value = 0.05;

    osc.connect(gain);
    gain.connect(this.masterGain);

    const now = this.ctx.currentTime;
    osc.start(now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.3);
    osc.stop(now + 0.3);
  }

  destroy() {
    if (this.ctx) {
      this.ctx.close();
    }
  }
}
