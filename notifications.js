(() => {
    const zone = document.getElementById('zoneNotifications');
    const modele = document.getElementById('modeleNotification');
    const modal = document.getElementById('confirmationApplication');
    const titreModal = document.getElementById('titreConfirmationApplication');
    const messageModal = document.getElementById('messageConfirmationApplication');
    const boutonAnnuler = document.getElementById('annulerConfirmationApplication');
    const boutonConfirmer = document.getElementById('validerConfirmationApplication');
    let actionEnAttente = null;
    let elementActif = null;
    let formulaireAutorise = null;

    window.notifier = (message, type = 'info') => {
        const notification = modele.content.firstElementChild.cloneNode(true);
        const types = ['success', 'warning', 'error', 'info'];
        const typeValide = types.includes(type) ? type : 'info';
        notification.dataset.type = typeValide;
        notification.setAttribute('role', typeValide === 'error' ? 'alert' : 'status');
        notification.querySelector('.app-notification-message').textContent = message;
        notification.querySelector('.app-notification-close').addEventListener('click', () => notification.remove());
        zone.appendChild(notification);
        window.setTimeout(() => notification.remove(), 5000);
    };

    const fermerConfirmation = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        actionEnAttente = null;
        if (elementActif && elementActif.isConnected) elementActif.focus();
    };

    const ouvrirConfirmation = (message, action, options = {}) => {
        elementActif = document.activeElement;
        actionEnAttente = action;
        titreModal.textContent = options.title || 'Confirmer cette action';
        messageModal.textContent = message;
        boutonConfirmer.textContent = options.confirmLabel || 'Confirmer';
        boutonConfirmer.dataset.variant = options.variant === 'danger' ? 'danger' : 'default';
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        boutonAnnuler.focus();
    };

    window.confirmer = (message, action, options = {}) => {
        ouvrirConfirmation(message, action, options);
    };

    boutonAnnuler.addEventListener('click', fermerConfirmation);
    boutonConfirmer.addEventListener('click', () => {
        const action = actionEnAttente;
        fermerConfirmation();
        if (action) action();
    });

    modal.addEventListener('click', event => {
        if (event.target === modal) fermerConfirmation();
    });

    document.addEventListener('keydown', event => {
        if (modal.classList.contains('hidden')) return;
        if (event.key === 'Escape') {
            fermerConfirmation();
            return;
        }

        if (event.key === 'Tab') {
            const elements = modal.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
            const premier = elements[0];
            const dernier = elements[elements.length - 1];
            if (event.shiftKey && document.activeElement === premier) {
                event.preventDefault();
                dernier.focus();
            } else if (!event.shiftKey && document.activeElement === dernier) {
                event.preventDefault();
                premier.focus();
            }
        }
    });

    document.addEventListener('submit', event => {
        const form = event.target.closest('form[data-confirm]');
        if (!form) return;
        if (form === formulaireAutorise) {
            formulaireAutorise = null;
            return;
        }

        event.preventDefault();
        ouvrirConfirmation(form.dataset.confirm, () => {
            formulaireAutorise = form;
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                if (formulaireAutorise === form) formulaireAutorise = null;
            } else {
                form.submit();
            }
        }, {
            title: form.dataset.confirmTitle,
            confirmLabel: form.dataset.confirmLabel,
            variant: form.dataset.confirmVariant
        });
    });
})();
