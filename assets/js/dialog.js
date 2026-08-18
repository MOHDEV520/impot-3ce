/**
 * ============================================
 * DIALOG — modale de confirmation/alerte partagée
 * Remplace les alert()/confirm() natifs du navigateur par une modale
 * cohérente avec le design system (.btn-primary/.btn-danger/.btn-outline).
 * ============================================
 */
(function () {
    'use strict';

    let modalEl, titleEl, messageEl, iconWrapEl, iconEl, cancelBtn, confirmBtn;
    let activeResolve = null;

    function ensureModal() {
        if (modalEl) return;

        modalEl = document.createElement('div');
        modalEl.className = 'fixed inset-0 bg-black/50 items-center justify-center hidden z-50';
        modalEl.setAttribute('role', 'alertdialog');
        modalEl.setAttribute('aria-modal', 'true');
        modalEl.innerHTML =
            '<div class="bg-white rounded-xl shadow-xl max-w-md w-full mx-4">' +
                '<div class="px-6 py-4 border-b border-slate-100">' +
                    '<h3 class="text-lg font-semibold text-slate-800" data-dialog-title></h3>' +
                '</div>' +
                '<div class="p-6">' +
                    '<div class="flex items-center justify-center w-16 h-16 rounded-full mx-auto mb-4" data-dialog-icon-wrap>' +
                        '<i class="fas text-2xl" data-dialog-icon></i>' +
                    '</div>' +
                    '<p class="text-center text-slate-600 whitespace-pre-line" data-dialog-message></p>' +
                '</div>' +
                '<div class="flex justify-end gap-3 px-6 pb-6">' +
                    '<button type="button" class="btn-outline" data-dialog-cancel></button>' +
                    '<button type="button" class="btn-primary" data-dialog-confirm></button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modalEl);

        titleEl = modalEl.querySelector('[data-dialog-title]');
        messageEl = modalEl.querySelector('[data-dialog-message]');
        iconWrapEl = modalEl.querySelector('[data-dialog-icon-wrap]');
        iconEl = modalEl.querySelector('[data-dialog-icon]');
        cancelBtn = modalEl.querySelector('[data-dialog-cancel]');
        confirmBtn = modalEl.querySelector('[data-dialog-confirm]');

        cancelBtn.addEventListener('click', function () { close(false); });
        confirmBtn.addEventListener('click', function () { close(true); });
        modalEl.addEventListener('click', function (e) {
            if (e.target === modalEl) close(false);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modalEl.classList.contains('hidden')) close(false);
        });
    }

    function close(result) {
        modalEl.classList.add('hidden');
        modalEl.classList.remove('flex');
        const resolve = activeResolve;
        activeResolve = null;
        if (resolve) resolve(result);
    }

    function open(message, opts, showCancel) {
        opts = opts || {};
        const danger = !!opts.danger || opts.kind === 'error';

        titleEl.textContent = opts.title || (showCancel ? 'Confirmation' : (danger ? 'Erreur' : 'Information'));
        messageEl.textContent = message;

        iconWrapEl.className = 'flex items-center justify-center w-16 h-16 rounded-full mx-auto mb-4 ' +
            (danger ? 'bg-red-100' : 'bg-yellow-100');
        iconEl.className = 'fas text-2xl ' +
            (danger ? 'fa-exclamation-triangle text-red-600' : (showCancel ? 'fa-question-circle text-yellow-600' : 'fa-info-circle text-yellow-600'));

        cancelBtn.style.display = showCancel ? '' : 'none';
        cancelBtn.textContent = opts.cancelLabel || 'Annuler';
        confirmBtn.textContent = opts.confirmLabel || (showCancel ? 'Confirmer' : 'OK');
        confirmBtn.className = (danger && showCancel) ? 'btn-danger' : 'btn-primary';

        return new Promise(function (resolve) {
            activeResolve = resolve;
            modalEl.classList.remove('hidden');
            modalEl.classList.add('flex');
            confirmBtn.focus();
        });
    }

    window.Dialog = {
        /**
         * Affiche une modale de confirmation (Annuler/Confirmer).
         * @returns {Promise<boolean>} true si confirmé, false sinon (Annuler, Échap, clic dehors)
         */
        confirm: function (message, opts) {
            ensureModal();
            return open(message, opts, true);
        },

        /**
         * Affiche une modale d'information/erreur avec un seul bouton OK.
         * @returns {Promise<void>}
         */
        alert: function (message, opts) {
            ensureModal();
            return open(message, opts, false).then(function () {});
        },

        /**
         * Helper pour remplacer `onsubmit="return confirm('...')"` : à utiliser
         * comme `onsubmit="return Dialog.confirmSubmit(event, '...', {danger:true})"`.
         * Empêche la soumission immédiate, affiche la modale, et soumet le
         * formulaire seulement si l'utilisateur confirme.
         * @returns {boolean} toujours false (la vraie soumission se fait via form.submit())
         */
        confirmSubmit: function (e, message, opts) {
            e.preventDefault();
            const form = e.target;
            this.confirm(message, opts).then(function (ok) {
                if (ok) form.submit();
            });
            return false;
        }
    };
})();
