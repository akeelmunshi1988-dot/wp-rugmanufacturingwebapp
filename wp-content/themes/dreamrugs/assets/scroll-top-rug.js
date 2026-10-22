/**
 * Alpine component for template-parts/scroll-to-top-rug.php — port of the
 * original app's frontend/src/components/ScrollToTopRug.tsx. A rolled
 * checkerboard rug unrolls into view once the visitor scrolls 700px, and
 * rolls back up when clicked while the page scrolls to the top.
 * Reduced-motion visitors get a static rug and an instant jump.
 *
 * Plain (non-deferred) script so window.drcRugTop exists before Alpine
 * (deferred) evaluates x-data — same arrangement as rug-detail.js.
 */
window.drcRugTop = function () {
  const SHOW_AFTER_PX = 700;
  const ROLL_UP_MS = 550;
  const UNROLL_MS = 750;
  const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  return {
    visible: false,
    // true = flat rug, false = rolled up at the top.
    unrolled: false,
    rollingUp: false,
    rugHeight: 65,
    ease: 'cubic-bezier(0.22, 0.8, 0.3, 1)',

    get duration() {
      return this.unrolled ? UNROLL_MS : ROLL_UP_MS;
    },

    init() {
      let frame = 0;
      const update = () => {
        frame = 0;
        const past = window.scrollY > SHOW_AFTER_PX;
        if (past && !this.rollingUp && !this.visible) {
          this.visible = true;
          // Unroll one frame after showing so the rolled state paints first and the transition runs.
          requestAnimationFrame(() => requestAnimationFrame(() => { this.unrolled = true; }));
        }
        // While rolling up, stay on screen until the animation finishes (see backToTop).
        if (!past && !this.rollingUp) {
          this.visible = false;
          this.unrolled = false;
        }
      };
      window.addEventListener('scroll', () => { if (!frame) frame = requestAnimationFrame(update); }, { passive: true });
      update();
    },

    backToTop() {
      const instant = reducedMotion();
      this.rollingUp = true;
      this.unrolled = false;
      window.scrollTo({ top: 0, behavior: instant ? 'auto' : 'smooth' });
      window.setTimeout(() => {
        this.rollingUp = false;
        if (window.scrollY <= SHOW_AFTER_PX) this.visible = false;
      }, instant ? 0 : ROLL_UP_MS + 150);
    },

    shadowStyle() {
      return `opacity:${this.unrolled ? 1 : 0};transition-duration:${this.duration}ms`;
    },
    rugStyle() {
      return `height:${this.rugHeight}px;clip-path:${this.unrolled ? 'inset(0 0 0 0)' : 'inset(0 0 100% 0)'};transition:clip-path ${this.duration}ms ${this.ease}`;
    },
    arrowStyle() {
      return `opacity:${this.unrolled ? 1 : 0};transition-delay:${this.unrolled ? this.duration - 150 : 0}ms`;
    },
    rollStyle() {
      const transform = this.unrolled ? `translateY(${this.rugHeight - 9}px) scaleY(0.35)` : 'translateY(0) scaleY(1)';
      const opacityTiming = this.unrolled ? `250ms ease ${this.duration - 250}ms` : '150ms ease 0ms';
      return 'background:linear-gradient(180deg, rgba(255,255,255,0.4) 0%, rgba(255,255,255,0) 45%, rgba(59,47,34,0.35) 100%), repeating-linear-gradient(90deg, #9C8366 0 4px, #F1EADC 4px 8px);'
        + `transform:${transform};opacity:${this.unrolled ? 0 : 1};transition:transform ${this.duration}ms ${this.ease}, opacity ${opacityTiming}`;
    },
  };
};
