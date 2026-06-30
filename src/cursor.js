export class CustomCursor {
  constructor() {
    this.cursor = document.getElementById('cursor');
    this.dot = this.cursor.querySelector('.cursor__dot');
    this.ring = this.cursor.querySelector('.cursor__ring');

    this.pos = { x: 0, y: 0 };
    this.ringPos = { x: 0, y: 0 };
    this.visible = false;

    this.init();
  }

  init() {
    document.addEventListener('mousemove', (e) => {
      this.pos.x = e.clientX;
      this.pos.y = e.clientY;

      if (!this.visible) {
        this.visible = true;
        this.cursor.style.opacity = '1';
        this.ringPos.x = this.pos.x;
        this.ringPos.y = this.pos.y;
      }
    });

    document.addEventListener('mouseenter', () => {
      this.cursor.style.opacity = '1';
    });

    document.addEventListener('mouseleave', () => {
      this.cursor.style.opacity = '0';
    });

    // Hover detection for interactive elements
    const hoverElements = document.querySelectorAll(
      'a, button, [data-hover], .work__item, .nav__link, .sound-toggle, .contact__email'
    );

    hoverElements.forEach((el) => {
      el.addEventListener('mouseenter', () => {
        document.body.classList.add('cursor-hover');
      });
      el.addEventListener('mouseleave', () => {
        document.body.classList.remove('cursor-hover');
      });
    });

    this.cursor.style.opacity = '0';
    this.animate();
  }

  animate() {
    // Dot follows mouse directly
    this.dot.style.left = `${this.pos.x}px`;
    this.dot.style.top = `${this.pos.y}px`;

    // Ring follows with lerp for smooth trailing effect
    this.ringPos.x += (this.pos.x - this.ringPos.x) * 0.12;
    this.ringPos.y += (this.pos.y - this.ringPos.y) * 0.12;

    this.ring.style.left = `${this.ringPos.x}px`;
    this.ring.style.top = `${this.ringPos.y}px`;

    requestAnimationFrame(() => this.animate());
  }
}
