import './style.css';
import { HeroScene } from './hero.js';
import { AboutBackground } from './aboutBg.js';
import { AudioSystem } from './audio.js';
import { CustomCursor } from './cursor.js';
import { ScopeFunnel } from './scope.js';
import { Chatbot } from './chatbot.js';

// Initialize all modules
const hero = new HeroScene();
const aboutBg = new AboutBackground();
const audio = new AudioSystem();
const cursor = new CustomCursor();
const scopeFunnel = new ScopeFunnel();
const chatbot = new Chatbot();

// Sound toggle
const soundToggle = document.getElementById('soundToggle');
soundToggle.addEventListener('click', () => {
  const isPlaying = audio.toggle();
  document.body.classList.toggle('sound-on', isPlaying);
  audio.playInteractionSound();
});

// Nav active state + smooth scroll
const navLinks = document.querySelectorAll('.nav__link');
const sections = document.querySelectorAll('.section');

navLinks.forEach((link) => {
  link.addEventListener('click', (e) => {
    const targetId = link.getAttribute('data-section');
    // Only intercept clicks on links that point to in-page sections.
    // External links and route links (Web Services, Investor Financing, etc.)
    // have no data-section and should navigate normally.
    if (targetId) {
      const target = document.getElementById(targetId);
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth' });
      }
    }
    audio.playInteractionSound();
  });
});

// Intersection observer for active nav links + section visibility
const observer = new IntersectionObserver(
  (entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        const id = entry.target.id;
        navLinks.forEach((link) => {
          link.classList.toggle('active', link.getAttribute('data-section') === id);
        });
      }
    });
  },
  { threshold: 0.4 }
);

sections.forEach((section) => observer.observe(section));

// Scroll-based effects
let ticking = false;
window.addEventListener('scroll', () => {
  if (!ticking) {
    requestAnimationFrame(() => {
      const scrollY = window.scrollY;
      const windowHeight = window.innerHeight;
      const docHeight = document.documentElement.scrollHeight - windowHeight;
      const progress = scrollY / docHeight;

      // Hero scroll parallax
      hero.setScrollProgress(Math.min(scrollY / windowHeight, 1));

      // Audio intensity based on scroll
      audio.setIntensity(0.5 + progress * 0.5);

      // Scroll indicator fade
      const scrollIndicator = document.getElementById('scrollIndicator');
      if (scrollIndicator) {
        scrollIndicator.style.opacity = Math.max(0, 1 - scrollY / (windowHeight * 0.3));
      }

      ticking = false;
    });
    ticking = true;
  }
});

// Reveal animations on scroll
const revealElements = document.querySelectorAll(
  '.about__content, .about__text, .about__name, .stat, .section__title, .work__item, .instrument__content, .instrument__headline, .instrument__sub, .cta-button, .case-study, .case-study__col, .contact__eyebrow, .contact__links, .services__content'
);

const revealObserver = new IntersectionObserver(
  (entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.style.opacity = '1';
        entry.target.style.transform = 'translateY(0)';
        revealObserver.unobserve(entry.target);
      }
    });
  },
  { threshold: 0.15 }
);

revealElements.forEach((el) => {
  el.style.opacity = '0';
  el.style.transform = 'translateY(40px)';
  el.style.transition = 'opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1)';
  revealObserver.observe(el);
});

// Work items hover sound
document.querySelectorAll('.work__item').forEach((item) => {
  item.addEventListener('mouseenter', () => {
    audio.playInteractionSound();
  });
});
