/**
 * animations.js — shared entrance animation engine
 * Injects keyframes + applies staggered animations on DOMContentLoaded.
 * Used by: find_order, loyalty_dashboard, employees, recipes_view, ingredients, report
 */
(function () {
    'use strict';

    // ── Keyframes ──────────────────────────────────────────────────────────
    document.head.insertAdjacentHTML('beforeend', `<style id="ent-anim-css">
        @keyframes entFadeUp    { from{opacity:0;transform:translateY(22px)} to{opacity:1;transform:translateY(0)} }
        @keyframes entPopUp     { from{opacity:0;transform:scale(.93) translateY(14px)} to{opacity:1;transform:scale(1) translateY(0)} }
        @keyframes entSlideDown { from{opacity:0;transform:translateY(-14px)} to{opacity:1;transform:translateY(0)} }
        @keyframes entFadeIn    { from{opacity:0} to{opacity:1} }
        @keyframes entPulse     { 0%,100%{box-shadow:0 0 0 0 rgba(209,144,75,0)} 50%{box-shadow:0 0 0 8px rgba(209,144,75,.18)} }
    </style>`);

    const E = 'cubic-bezier(0.4,0,0.2,1)';

    /** Apply an animation to one element. */
    function a(el, kf, ms, dur) {
        if (!el) return;
        el.style.animation = `${kf} ${dur || .45}s ${E} ${ms}ms both`;
    }

    /** Apply staggered animation to a NodeList / array. */
    function stagger(sel, kf, base, step, dur) {
        document.querySelectorAll(sel).forEach((el, i) => a(el, kf, base + i * step, dur));
    }

    // ── Scroll-reveal (IntersectionObserver) ─────────────────────────────
    const io = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (!e.isIntersecting) return;
            const delay = parseInt(e.target.dataset.entDelay || '0');
            a(e.target, 'entFadeUp', delay, 0.45);
            io.unobserve(e.target);
        });
    }, { threshold: 0.06, rootMargin: '0px 0px -30px 0px' });

    function reveal(el, delay) {
        if (!el) return;
        el.dataset.entDelay = delay;
        // Keep invisible until IO fires
        el.style.opacity = '0';
        io.observe(el);
    }

    // ── Main entrance logic ───────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {

        // Skip entrance animations on a data-poll reload (avoid annoying re-fade)
        if (sessionStorage.getItem('skipEntranceAnim')) {
            sessionStorage.removeItem('skipEntranceAnim');
            return;
        }

        // 1. Topbar / header — slides down immediately
        a(document.querySelector('.topbar, .top'), 'entSlideDown', 0, 0.38);

        // 2. Controls / filter bar
        a(document.querySelector('.controls-bar, .controls, .filters'), 'entFadeIn', 70, 0.4);

        // 3. Stat cards & KPI chips — pop up, staggered
        stagger('.kpi', 'entPopUp', 70, 68, 0.45);

        // 4. Podium / medal cards (employees leaderboard top-3)
        stagger('.podium-card, .medal-card', 'entPopUp', 180, 95, 0.52);

        // 5. Above-fold grid/order/recipe cards — staggered pop
        const cardSel = '.order-card, .grid .card, .recipe-card';
        document.querySelectorAll(cardSel).forEach((el, i) => {
            if (i < 16) a(el, 'entPopUp', 90 + i * 52, 0.42);
            else        reveal(el, Math.min(i * 35, 400));
        });

        // 6. Table rows — fast stagger (cap at 28 rows for perf)
        document.querySelectorAll('tbody tr').forEach((el, i) => {
            if (i < 28) a(el, 'entFadeUp', 170 + i * 26, 0.33);
            else        a(el, 'entFadeUp', 870, 0.28);
        });

        // 7. Report/section cards — scroll-reveal (they're often below the fold)
        document.querySelectorAll('.card:not(.kpi-wrap)').forEach((el, i) => {
            // Only scroll-reveal if not already animated by rules above
            if (!el.style.animation) reveal(el, i * 55);
        });

        // 8. Chart wrappers inside cards
        document.querySelectorAll('.chart-wrap').forEach((el, i) => {
            if (!el.style.animation) reveal(el, i * 45);
        });
    });
})();
