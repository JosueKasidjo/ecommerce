<div id="zoneNotifications" class="fixed right-4 top-4 z-[80] flex w-[calc(100%-2rem)] max-w-sm flex-col gap-3" aria-live="polite" aria-relevant="additions text"></div>

<template id="modeleNotification">
    <div class="app-notification" role="status">
        <span class="app-notification-message"></span>
        <button type="button" class="app-notification-close" aria-label="Fermer la notification">&times;</button>
    </div>
</template>

<div id="confirmationApplication" class="fixed inset-0 z-[90] hidden items-center justify-center bg-slate-900/50 p-4" role="dialog" aria-modal="true" aria-labelledby="titreConfirmationApplication" aria-describedby="messageConfirmationApplication">
    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
        <h2 id="titreConfirmationApplication" class="text-lg font-bold text-slate-900">Confirmer cette action</h2>
        <p id="messageConfirmationApplication" class="mt-2 text-sm text-slate-500"></p>
        <div class="mt-6 flex justify-end gap-3">
            <button type="button" id="annulerConfirmationApplication" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Annuler</button>
            <button type="button" id="validerConfirmationApplication" class="confirmation-submit rounded-lg px-4 py-2 text-sm font-semibold text-white">Confirmer</button>
        </div>
    </div>
</div>

<style>
    .app-notification {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
        border: 1px solid #cbd5e1;
        border-radius: 0.75rem;
        background: #fff;
        padding: 0.75rem 1rem;
        color: #1e293b;
        font-size: 0.875rem;
        line-height: 1.25rem;
        box-shadow: 0 10px 15px -3px rgb(15 23 42 / 0.12);
    }
    .app-notification[data-type="success"] { border-color: #bbf7d0; background: #f0fdf4; color: #166534; }
    .app-notification[data-type="warning"] { border-color: #fde68a; background: #fffbeb; color: #92400e; }
    .app-notification[data-type="error"] { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
    .app-notification-close { flex-shrink: 0; font-weight: 700; opacity: 0.6; }
    .app-notification-close:hover { opacity: 1; }
    .confirmation-submit { background: #15803d; }
    .confirmation-submit:hover { background: #166534; }
    .confirmation-submit[data-variant="danger"] { background: #dc2626; }
    .confirmation-submit[data-variant="danger"]:hover { background: #b91c1c; }
</style>

<script src="notifications.js"></script>
