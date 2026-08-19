    </main>
    
    <!-- Footer -->
    <footer class="bg-white border-t border-gray-200 mt-8 no-print">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                <p class="text-center md:text-left text-sm text-gray-500">
                    &copy; <?= date('Y') ?> Système de Gestion Fiscale - Cabinet d'Expertise Comptable
                </p>
                <p class="text-center md:text-right text-xs text-gray-400 mt-2 md:mt-0">
                    Version 1.0 | Application 100% Offline
                </p>
            </div>
        </div>
    </footer>
    
    <!-- Scripts communs -->
    <script src="<?= $basePath ?>assets/js/dialog.js"></script>
    <script>
        // Formater les montants
        function formatMontant(montant) {
            return new Intl.NumberFormat('fr-FR').format(montant) + ' FCFA';
        }

        // Messages : les succès s'effacent seuls, les erreurs restent jusqu'à fermeture manuelle
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            // Le message peut apparaître loin sous le pli (ex: bouton "Sauvegarder"
            // au milieu d'une longue page) : on le ramène toujours à l'écran pour
            // qu'un succès/échec ne passe jamais inaperçu.
            if (alerts.length) {
                alerts[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            alerts.forEach(function(alert) {
                const estSucces = alert.classList.contains('alert-success');
                if (estSucces) {
                    setTimeout(function() {
                        alert.style.transition = 'opacity 0.5s ease-out';
                        alert.style.opacity = '0';
                        setTimeout(function() { alert.remove(); }, 500);
                    }, 5000);
                } else {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.setAttribute('aria-label', 'Fermer le message');
                    btn.className = 'ml-auto pl-4 font-bold opacity-60 hover:opacity-100';
                    btn.innerHTML = '&times;';
                    btn.addEventListener('click', function() { alert.remove(); });
                    alert.appendChild(btn);
                }
            });
        });

        // Fermer les modals avec Escape (couvre modal-* et modalAchat, modalDepense, etc.)
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('[id^="modal"]').forEach(function(modal) {
                    modal.classList.add('hidden');
                });
            }
        });
    </script>
</body>
</html>
