(() => {
    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-vote-form]');
        if (!form) {
            return;
        }
        event.preventDefault();
        const body = new FormData(form);
        if (event.submitter && event.submitter.name) {
            body.set(event.submitter.name, event.submitter.value);
        }
        const response = await fetch(form.action, {
            method: 'POST',
            body,
            headers: {'Accept': 'application/json'},
        });
        if (!response.ok) {
            return;
        }
        const result = await response.json();
        document.querySelectorAll(`[data-score-for="${result.image_id}"]`).forEach((node) => {
            node.textContent = result.score;
        });
        document.querySelectorAll(`[data-image-id="${result.image_id}"]`).forEach((node) => {
            node.dataset.score = String(result.score);
            node.dataset.userVote = String(result.vote);
        });
        form.querySelectorAll('button[name="vote"]').forEach((button) => {
            const active = button.value === String(result.vote);
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        const lightbox = document.querySelector('[data-lightbox]');
        const lightboxScore = document.querySelector('[data-lightbox-score]');
        if (lightbox && lightboxScore && lightbox.dataset.currentImageId === String(result.image_id)) {
            lightboxScore.textContent = String(result.score);
        }
    });

    document.querySelectorAll('[data-tag-input]').forEach((input) => {
        const list = document.querySelector(`#${input.getAttribute('list')}`);
        const names = list ? Array.from(list.options).map((option) => option.value) : [];
        const suggestions = document.createElement('div');
        suggestions.className = 'tag-suggestions';
        input.insertAdjacentElement('afterend', suggestions);

        function currentPrefix() {
            const parts = input.value.split(',');
            return parts[parts.length - 1].trim().toLowerCase();
        }

        function choose(name) {
            const parts = input.value.split(',');
            parts[parts.length - 1] = ` ${name}`;
            input.value = parts.map((part) => part.trim()).filter(Boolean).join(', ');
            suggestions.innerHTML = '';
            input.focus();
        }

        input.addEventListener('input', () => {
            const prefix = currentPrefix();
            suggestions.innerHTML = '';
            if (!prefix) {
                return;
            }
            names.filter((name) => name.toLowerCase().startsWith(prefix)).slice(0, 6).forEach((name) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = name;
                button.addEventListener('click', () => choose(name));
                suggestions.append(button);
            });
        });
    });

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
        overlay.dataset.currentImageId = card.dataset.imageId || '';
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
            if (event.target.closest('form')) {
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
})();

