(() => {
    const cards = Array.from(document.querySelectorAll('[data-lightbox-image]'));
    const overlay = document.querySelector('[data-lightbox]');
    if (!overlay || cards.length === 0) {
        return;
    }

    const image = overlay.querySelector('[data-lightbox-img]');
    const title = overlay.querySelector('[data-lightbox-title]');
    const description = overlay.querySelector('[data-lightbox-description]');
    const score = overlay.querySelector('[data-lightbox-score]');
    let currentIndex = 0;

    function openAt(index) {
        const card = cards[index];
        if (!card) {
            return;
        }
        currentIndex = index;
        image.src = card.dataset.fullSrc;
        image.alt = card.dataset.title || '';
        title.textContent = card.dataset.title || '';
        description.textContent = card.dataset.description || '';
        score.textContent = card.dataset.score || '0';
        overlay.hidden = false;
        document.body.classList.add('has-lightbox');
    }

    function close() {
        overlay.hidden = true;
        image.removeAttribute('src');
        document.body.classList.remove('has-lightbox');
    }

    function step(offset) {
        openAt((currentIndex + offset + cards.length) % cards.length);
    }

    cards.forEach((card, index) => {
        card.addEventListener('click', (event) => {
            if (event.target.closest('form') || event.target.closest('a')) {
                return;
            }
            event.preventDefault();
            openAt(index);
        });
    });

    overlay.addEventListener('click', (event) => {
        const action = event.target.dataset.lightboxAction;
        if (action === 'close' || event.target === overlay) {
            close();
        }
        if (action === 'previous') {
            step(-1);
        }
        if (action === 'next') {
            step(1);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (overlay.hidden) {
            return;
        }
        if (event.key === 'Escape') {
            close();
        }
        if (event.key === 'ArrowLeft') {
            step(-1);
        }
        if (event.key === 'ArrowRight') {
            step(1);
        }
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-vote-form]');
        if (!form) {
            return;
        }
        event.preventDefault();
        const response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: {'Accept': 'application/json'},
        });
        if (!response.ok) {
            return;
        }
        const result = await response.json();
        document.querySelectorAll(`[data-score-for="${result.image_id}"]`).forEach((node) => {
            node.textContent = result.score;
        });
        const active = cards[currentIndex];
        if (active && active.dataset.imageId === String(result.image_id)) {
            active.dataset.score = String(result.score);
            score.textContent = String(result.score);
        }
    });
})();

