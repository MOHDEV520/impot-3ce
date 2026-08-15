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
    <script>
        // Formater les montants
        function formatMontant(montant) {
            return new Intl.NumberFormat('fr-FR').format(montant) + ' FCFA';
        }
        
        // Confirmation avant action
        function confirmer(message) {
            return confirm(message || 'Êtes-vous sûr ?');
        }
        
        // Auto-hide des messages après 5 secondes
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('[class*="border-l-4"]');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.transition = 'opacity 0.5s ease-out';
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 500);
                }, 5000);
            });
        });
        
        // Fermer les modals avec Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('[id^="modal-"]').forEach(function(modal) {
                    modal.classList.add('hidden');
                });
            }
        });
    </script>
</body>
</html>
