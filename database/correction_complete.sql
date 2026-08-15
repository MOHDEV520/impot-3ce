-- =============================================
-- CORRECTION COMPLÈTE - Exécuter dans phpMyAdmin
-- =============================================

USE gestion_fiscale;

-- 1. SUPPRIMER et RECRÉER parametres_fiscaux
DROP TABLE IF EXISTS parametres_fiscaux;

CREATE TABLE parametres_fiscaux (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL UNIQUE,
    type_tva ENUM('non_exonere', 'exonere_partiel', 'exonere_total') DEFAULT 'non_exonere',
    taux_tva DECIMAL(5,2) DEFAULT 18.00,
    salaires_actif TINYINT(1) DEFAULT 0,
    location_actif TINYINT(1) DEFAULT 0,
    css_actif TINYINT(1) DEFAULT 1,
    valeur_locative_mensuelle DECIMAL(15,2) DEFAULT 0,
    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2. AJOUTER les colonnes manquantes à clients
ALTER TABLE clients ADD COLUMN IF NOT EXISTS type_activite VARCHAR(100);
ALTER TABLE clients ADD COLUMN IF NOT EXISTS secteur VARCHAR(100) DEFAULT 'commerce';

-- 3. AJOUTER les colonnes manquantes à compte_gestion_mensuel
ALTER TABLE compte_gestion_mensuel ADD COLUMN IF NOT EXISTS date_validation TIMESTAMP NULL;
ALTER TABLE compte_gestion_mensuel ADD COLUMN IF NOT EXISTS valide_par INT NULL;

-- 4. AJOUTER ordre_affichage à natures_depenses
ALTER TABLE natures_depenses ADD COLUMN IF NOT EXISTS ordre_affichage INT DEFAULT 0;

-- 5. CORRIGER le mot de passe admin
UPDATE agents SET mot_de_passe = '$2y$10$Ow5kzDsAqNTfHRdPCsLZxeRXJz7NzZqKPyNvKwA2mvHfSxZQz1X5K' WHERE email = 'admin@cabinet.local';

-- MESSAGE
SELECT 'Correction terminée! Lancez debug.php pour corriger le mot de passe.' AS Message;
