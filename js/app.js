// ============================================
// CAMPUSLINK - JavaScript Principal
// ============================================

document.addEventListener('DOMContentLoaded', function () {

    // ── HERO SLIDER ──
    const slides = document.querySelectorAll('.hero-slide');
    const indicators = document.querySelectorAll('.indicator');
    const collegeNameEl = document.querySelector('.college-name');
    const collegeNames = [
        '🏛️ Université Félix Houphouët-Boigny',
        '🏫 Université Nangui Abrogoua',
        '🎓 Université Internationale de Cocody',
        '📚 Institut International Polytechnique des Élites'
    ];
    let currentSlide = 0;

    function showSlide(n) {
        slides.forEach(s => s.classList.remove('active'));
        indicators.forEach(i => i.classList.remove('active'));
        slides[n].classList.add('active');
        if (indicators[n]) indicators[n].classList.add('active');
        if (collegeNameEl) collegeNameEl.textContent = collegeNames[n];
    }

    if (slides.length > 0) {
        showSlide(0);
        setInterval(() => {
            currentSlide = (currentSlide + 1) % slides.length;
            showSlide(currentSlide);
        }, 4500);

        indicators.forEach((ind, i) => {
            ind.addEventListener('click', () => {
                currentSlide = i;
                showSlide(i);
            });
        });
    }

    // ── MODALS ──
    function openModal(id) {
        const m = document.getElementById(id);
        if (m) { m.classList.add('open'); document.body.style.overflow = 'hidden'; }
    }
    function closeModal(id) {
        const m = document.getElementById(id);
        if (m) { m.classList.remove('open'); document.body.style.overflow = ''; }
    }

    // Bouton Connexion
    const btnConnexion = document.getElementById('btn-connexion');
    if (btnConnexion) btnConnexion.addEventListener('click', () => openModal('modal-connexion'));

    // Bouton Inscription
    const btnInscription = document.getElementById('btn-inscription');
    if (btnInscription) btnInscription.addEventListener('click', () => openModal('modal-inscription-choix'));

    // Choisir type inscription
    document.querySelectorAll('[data-open-modal]').forEach(el => {
        el.addEventListener('click', function () {
            const target = this.dataset.openModal;
            closeModal('modal-inscription-choix');
            setTimeout(() => openModal(target), 200);
        });
    });

    // Fermer modals
    document.querySelectorAll('.modal-close').forEach(btn => {
        btn.addEventListener('click', function () {
            this.closest('.modal-overlay').classList.remove('open');
            document.body.style.overflow = '';
        });
    });
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function (e) {
            if (e.target === this) {
                this.classList.remove('open');
                document.body.style.overflow = '';
            }
        });
    });

    // ── TOAST NOTIFICATIONS ──
    window.showToast = function (msg, type = 'success') {
        let toast = document.getElementById('toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'toast';
            toast.className = 'toast';
            document.body.appendChild(toast);
        }
        toast.textContent = msg;
        toast.className = `toast ${type}`;
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => toast.classList.remove('show'), 3500);
    };

    // ── FILTRES ADMIN ──
    const filterForm = document.getElementById('filter-form');
    if (filterForm) {
        filterForm.querySelectorAll('select').forEach(sel => {
            sel.addEventListener('change', () => filterForm.submit());
        });
    }

    // ── TABS ──
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', function () {
            const group = this.closest('.tabs');
            const targetId = this.dataset.tab;
            group.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
            const target = document.getElementById(targetId);
            if (target) target.style.display = 'block';
        });
    });

    // Navbar scroll effect
    window.addEventListener('scroll', () => {
        const nav = document.querySelector('.navbar');
        if (nav) {
            if (window.scrollY > 50) {
                nav.style.boxShadow = '0 4px 30px rgba(0,0,0,.5)';
            } else {
                nav.style.boxShadow = '0 2px 20px rgba(0,0,0,.3)';
            }
        }
    });

});
