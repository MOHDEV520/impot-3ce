-- ============================================
-- INSTALLATION RAPIDE - SYSTÈME DE GESTION FISCALE (MySQL)
-- ============================================
--
-- Crée la base `gestion_fiscale` complète en une seule commande,
-- en s'appuyant sur le schéma canonique database/schema_mysql.sql
-- (tables + natures de dépenses + compte admin par défaut).
--
-- ⚠ ATTENTION : schema_mysql.sql supprime les tables existantes
--   (DROP TABLE IF EXISTS). À réserver à une installation NEUVE.
--
-- Usage (depuis la RACINE du projet, MySQL XAMPP sur le port 3406) :
--
--   mysql -u root -P 3406 --default-character-set=utf8mb4 < database/install_rapide.sql
--
-- Connexion par défaut après installation :
--   admin@cabinet.local / admin123
--   (le compte admin est de toute façon re-créé automatiquement au
--   premier démarrage par Database::assurerAdminExiste() s'il manque)
--
-- ============================================

-- Schéma complet + données initiales
source database/schema_mysql.sql;

-- ============================================
-- VÉRIFICATION DE L'INSTALLATION
-- ============================================

USE gestion_fiscale;

SELECT CONCAT('Tables créées : ', COUNT(*)) AS verification
FROM information_schema.tables
WHERE table_schema = 'gestion_fiscale';

SELECT CONCAT('Comptes admin : ', COUNT(*)) AS verification
FROM agents
WHERE role = 'admin' AND statut = 'actif';

SELECT CONCAT('Natures de dépenses : ', COUNT(*)) AS verification
FROM natures_depenses;

SELECT '✔ Installation terminée. Connexion : admin@cabinet.local / admin123' AS resultat;
