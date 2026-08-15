-- ============================================
-- SYSTÈME DE GESTION FISCALE - SCHÉMA MySQL
-- ============================================

-- Création de la base de données
CREATE DATABASE IF NOT EXISTS gestion_fiscale
CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

USE gestion_fiscale;

-- Suppression des tables existantes (ordre inverse des dépendances)
DROP TABLE IF EXISTS logs;
DROP TABLE IF EXISTS exonerations_client;
DROP TABLE IF EXISTS services_annexe_tva;
DROP TABLE IF EXISTS annexes;
DROP TABLE IF EXISTS impots_mensuels;
DROP TABLE IF EXISTS depenses;
DROP TABLE IF EXISTS achats;
DROP TABLE IF EXISTS fournisseurs;
DROP TABLE IF EXISTS parametres_fiscaux;
DROP TABLE IF EXISTS compte_gestion_mensuel;
DROP TABLE IF EXISTS clients;
DROP TABLE IF EXISTS agents;
DROP TABLE IF EXISTS natures_depenses;

-- ============================================
-- TABLE: agents
-- Gestion des comptes agents du cabinet
-- ============================================
CREATE TABLE agents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    mot_de_passe VARCHAR(255) NOT NULL,
    role ENUM('agent', 'admin', 'superviseur') DEFAULT 'agent',
    statut ENUM('actif', 'inactif', 'suspendu') DEFAULT 'actif',
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    derniere_connexion DATETIME
) ENGINE=InnoDB;

-- ============================================
-- TABLE: clients
-- Entreprises suivies fiscalement
-- ============================================
CREATE TABLE clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    nom VARCHAR(200) NOT NULL,
    ifu VARCHAR(50) UNIQUE,
    type_activite VARCHAR(100),
    secteur VARCHAR(100) DEFAULT 'officine',
    regime_fiscal ENUM('reel_normal', 'reel_simplifie', 'forfaitaire') DEFAULT 'reel_normal',
    adresse TEXT,
    telephone VARCHAR(20),
    email VARCHAR(150),
    statut ENUM('actif', 'inactif', 'archive') DEFAULT 'actif',
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================
-- TABLE: parametres_fiscaux
-- Configuration fiscale par client
-- ============================================
CREATE TABLE parametres_fiscaux (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNIQUE NOT NULL,
    
    -- TVA
    type_tva ENUM('non_exonere', 'exonere_partiel', 'exonere_total') DEFAULT 'non_exonere',
    taux_tva DECIMAL(5,2) DEFAULT 18.00,
    taux_tva_double TINYINT(1) DEFAULT 0,
    
    -- Sections actives
    salaires_actif TINYINT(1) DEFAULT 1,
    location_actif TINYINT(1) DEFAULT 0,
    irf_tf_actif TINYINT(1) DEFAULT 0,
    css_actif TINYINT(1) DEFAULT 1,
    sans_marges TINYINT(1) DEFAULT 0,
    
    -- Location (si applicable)
    valeur_locative_mensuelle DECIMAL(15,2) DEFAULT 0,
    
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- TABLE: fournisseurs
-- Fournisseurs des clients
-- ============================================
CREATE TABLE fournisseurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(200) NOT NULL,
    type ENUM('grossiste', 'fabricant', 'importateur', 'distributeur', 'autre') DEFAULT 'grossiste',
    ifu VARCHAR(50),
    adresse TEXT,
    telephone VARCHAR(20),
    statut ENUM('actif', 'inactif') DEFAULT 'actif',
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================
-- TABLE: compte_gestion_mensuel
-- Données consolidées par mois/client
-- ============================================
CREATE TABLE compte_gestion_mensuel (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    mois INT NOT NULL,
    annee INT NOT NULL,
    
    -- Chiffre d'affaires
    ca_global DECIMAL(15,2) DEFAULT 0,
    ca_exonere DECIMAL(15,2) DEFAULT 0,
    ca_taxable DECIMAL(15,2) DEFAULT 0,
    
    -- Masse salariale
    masse_salariale DECIMAL(15,2) DEFAULT 0,
    
    -- Loyers et TVA Location
    loyers_percus DECIMAL(15,2) DEFAULT 0,
    loc_ligne132 DECIMAL(15,2) DEFAULT 0,
    loc_ligne133 DECIMAL(15,2) DEFAULT 0,
    loc_ligne137 DECIMAL(15,2) DEFAULT 0,
    loc_ligne141 DECIMAL(15,2) DEFAULT 0,
    loc_ligne145 DECIMAL(15,2) DEFAULT 0,
    cf_ligne243 DECIMAL(15,2) DEFAULT 0,
    cf_ligne246 DECIMAL(15,2) DEFAULT 0,
    cf_ligne247 DECIMAL(15,2) DEFAULT 0,
    cf_ligne248 DECIMAL(15,2) DEFAULT 0,
    cf_ligne249 DECIMAL(15,2) DEFAULT 0,
    cf_ligne250 DECIMAL(15,2) DEFAULT 0,
    cf_ligne251 DECIMAL(15,2) DEFAULT 0,
    tl_ligne212 DECIMAL(15,2) DEFAULT 0,
    -- TVA - lignes saisies manuellement
    tva_ligne82 DECIMAL(15,2) DEFAULT 0,
    tva_ligne83 DECIMAL(15,2) DEFAULT 0,
    tva_ligne84 DECIMAL(15,2) DEFAULT 0,
    tva_ligne85 DECIMAL(15,2) DEFAULT 0,
    tva_ligne86 DECIMAL(15,2) DEFAULT 0,
    tva_ligne101 DECIMAL(15,2) DEFAULT 0,
    tva_ligne102 DECIMAL(15,2) DEFAULT 0,
    tva_ligne103 DECIMAL(15,2) DEFAULT 0,
    tva_ligne107 DECIMAL(15,2) DEFAULT 0,
    tva_ligne110 DECIMAL(15,2) DEFAULT 0,
    tva_ligne113 DECIMAL(15,2) DEFAULT 0,
    tva_ligne114 DECIMAL(15,2) DEFAULT 0,
    tva_ligne115 DECIMAL(15,2) DEFAULT 0,
    tva_ligne116 DECIMAL(15,2) DEFAULT 0,
    tva_ligne117 DECIMAL(15,2) DEFAULT 0,
    tva_ligne118 DECIMAL(15,2) DEFAULT 0,
    tva_ligne120 DECIMAL(15,2) DEFAULT 0,
    
    -- Impôts directs et paramètres
    its DECIMAL(15,2) DEFAULT 0,
    marge DECIMAL(5,3) DEFAULT 1.30,
    marge_taxable DECIMAL(5,3) DEFAULT 1.30,
    
    -- Statut
    statut ENUM('en_preparation', 'pret_declaration', 'valide', 'verrouille') DEFAULT 'en_preparation',
    date_validation DATETIME,
    valide_par INT,
    
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_client_mois_annee (client_id, mois, annee),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (valide_par) REFERENCES agents(id),
    
    CHECK (mois BETWEEN 1 AND 12),
    CHECK (annee >= 2020)
) ENGINE=InnoDB;

-- ============================================
-- TABLE: achats
-- Achats fournisseurs
-- ============================================
CREATE TABLE achats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    fournisseur_id INT NOT NULL,
    compte_gestion_id INT NOT NULL,
    
    mois INT NOT NULL,
    annee INT NOT NULL,
    
    -- Montants
    montant_ht DECIMAL(15,2) NOT NULL DEFAULT 0,
    montant_tva DECIMAL(15,2) NOT NULL DEFAULT 0,
    montant_ttc DECIMAL(15,2) NOT NULL DEFAULT 0,
    
    -- Document
    type_document ENUM('releve', 'facture') DEFAULT 'releve',
    reference_document VARCHAR(100),
    date_document DATE,
    
    -- TVA déductible
    tva_deductible TINYINT(1) DEFAULT 1,
    
    -- Nouveau : Nature opération et Exclusion CA
    nature_operation ENUM('achat', 'service') DEFAULT 'achat',
    exclure_ca TINYINT(1) DEFAULT 0,
    
    date_saisie DATETIME DEFAULT CURRENT_TIMESTAMP,
    saisi_par INT,
    
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (fournisseur_id) REFERENCES fournisseurs(id) ON DELETE RESTRICT,
    FOREIGN KEY (compte_gestion_id) REFERENCES compte_gestion_mensuel(id) ON DELETE CASCADE,
    FOREIGN KEY (saisi_par) REFERENCES agents(id),
    
    CHECK (mois BETWEEN 1 AND 12),
    CHECK (annee >= 2020)
) ENGINE=InnoDB;

-- ============================================
-- TABLE: services_annexe_tva
-- Services spécifiques pour annexe 1.1 TVA
-- ============================================
CREATE TABLE services_annexe_tva (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    mois INT NOT NULL,
    annee INT NOT NULL,
    prestataire_nom VARCHAR(200) NOT NULL,
    prestataire_adresse TEXT,
    prestataire_nif VARCHAR(50),
    montant_ht DECIMAL(15,2) NOT NULL DEFAULT 0,
    taux_tva DECIMAL(5,2) DEFAULT 18.00,
    montant_tva DECIMAL(15,2) NOT NULL DEFAULT 0,
    reference_document VARCHAR(100),
    date_document DATE,
    date_saisie DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CHECK (mois BETWEEN 1 AND 12),
    CHECK (annee >= 2020)
) ENGINE=InnoDB;

-- ============================================
-- TABLE: exonerations_client
-- Lignes d'exonération configurables par client
-- ============================================
CREATE TABLE exonerations_client (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    numero_ordre INT DEFAULT 0,
    code_produit VARCHAR(50),
    nature VARCHAR(255) NOT NULL,
    montant_ht DECIMAL(15,2) DEFAULT 0,
    taux VARCHAR(50),
    actif TINYINT(1) DEFAULT 1,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- TABLE: natures_depenses
-- Catégories de dépenses paramétrables
-- ============================================
CREATE TABLE natures_depenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) UNIQUE NOT NULL,
    libelle VARCHAR(100) NOT NULL,
    deductible TINYINT(1) DEFAULT 1,
    ordre_affichage INT DEFAULT 0
) ENGINE=InnoDB;

-- Insertion des natures de dépenses par défaut
INSERT INTO natures_depenses (code, libelle, deductible, ordre_affichage) VALUES
('LOYER', 'Loyer', 1, 1),
('EAU_ELEC', 'Eau / Électricité', 1, 2),
('TEL_NET', 'Téléphone / Internet', 1, 3),
('TRANSPORT', 'Transport', 1, 4),
('FOURNITURES', 'Fournitures de bureau', 1, 5),
('ENTRETIEN', 'Entretien et réparations', 1, 6),
('ASSURANCE', 'Assurances', 1, 7),
('HONORAIRES', 'Honoraires', 1, 8),
('PUBLICITE', 'Publicité', 1, 9),
('AUTRES', 'Autres charges', 1, 10);

-- ============================================
-- TABLE: depenses
-- Dépenses courantes
-- ============================================
CREATE TABLE depenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    compte_gestion_id INT NOT NULL,
    nature_id INT NOT NULL,
    
    mois INT NOT NULL,
    annee INT NOT NULL,
    
    montant DECIMAL(15,2) NOT NULL DEFAULT 0,
    description TEXT,
    
    date_saisie DATETIME DEFAULT CURRENT_TIMESTAMP,
    saisi_par INT,
    
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (compte_gestion_id) REFERENCES compte_gestion_mensuel(id) ON DELETE CASCADE,
    FOREIGN KEY (nature_id) REFERENCES natures_depenses(id) ON DELETE RESTRICT,
    FOREIGN KEY (saisi_par) REFERENCES agents(id),
    
    CHECK (mois BETWEEN 1 AND 12),
    CHECK (annee >= 2020)
) ENGINE=InnoDB;

-- ============================================
-- TABLE: impots_mensuels
-- Calculs d'impôts par mois
-- ============================================
CREATE TABLE impots_mensuels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    compte_gestion_id INT NOT NULL,
    
    mois INT NOT NULL,
    annee INT NOT NULL,
    
    -- TVA
    tva_collectee DECIMAL(15,2) DEFAULT 0,
    tva_deductible DECIMAL(15,2) DEFAULT 0,
    tva_a_payer DECIMAL(15,2) DEFAULT 0,
    credit_tva DECIMAL(15,2) DEFAULT 0,
    
    -- Impôts sur salaires
    cf DECIMAL(15,2) DEFAULT 0,           -- 3.5%
    its DECIMAL(15,2) DEFAULT 0,          -- Barème
    tl DECIMAL(15,2) DEFAULT 0,           -- 1%
    
    -- Impôts sur location
    irf DECIMAL(15,2) DEFAULT 0,          -- 12%
    tva_location DECIMAL(15,2) DEFAULT 0, -- 18%
    tf DECIMAL(15,2) DEFAULT 0,           -- 3%
    
    -- CSS
    css DECIMAL(15,2) DEFAULT 0,          -- 0.5%
    
    -- Total
    total_impots DECIMAL(15,2) DEFAULT 0,
    
    date_calcul DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_impots_client_mois (client_id, mois, annee),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (compte_gestion_id) REFERENCES compte_gestion_mensuel(id) ON DELETE CASCADE,
    
    CHECK (mois BETWEEN 1 AND 12),
    CHECK (annee >= 2020)
) ENGINE=InnoDB;

-- ============================================
-- TABLE: annexes
-- Justificatifs d'exonération
-- ============================================
CREATE TABLE annexes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    
    type_annexe ENUM('A', 'B', 'C', 'D') NOT NULL,
    type_impot VARCHAR(30) NOT NULL,
    
    reference VARCHAR(200) NOT NULL,
    base_legale TEXT,
    date_debut DATE NOT NULL,
    date_fin DATE,
    
    fichier_path VARCHAR(500),
    
    statut ENUM('valide', 'expire', 'annule') DEFAULT 'valide',
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- TABLE: logs
-- Journal des actions (sécurité/traçabilité)
-- ============================================
CREATE TABLE logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT,
    action VARCHAR(100) NOT NULL,
    table_concernee VARCHAR(50),
    enregistrement_id INT,
    details TEXT,
    ip_address VARCHAR(45),
    date_action DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================
-- INDEX pour optimisation
-- ============================================
CREATE INDEX idx_clients_agent ON clients(agent_id);
CREATE INDEX idx_achats_client_mois ON achats(client_id, mois, annee);
CREATE INDEX idx_depenses_client_mois ON depenses(client_id, mois, annee);
CREATE INDEX idx_compte_gestion_client ON compte_gestion_mensuel(client_id, mois, annee);
CREATE INDEX idx_impots_client_mois ON impots_mensuels(client_id, mois, annee);
CREATE INDEX idx_logs_agent ON logs(agent_id);
CREATE INDEX idx_logs_date ON logs(date_action);

-- ============================================
-- Insertion de l'agent admin par défaut
-- Mot de passe: admin123 (à changer après installation)
-- ============================================
INSERT INTO agents (nom, prenom, email, mot_de_passe, role, statut) VALUES
('Admin', 'Système', 'admin@cabinet.local', '$2y$10$8K1p/a0dL1LXMIgoEDFrwOfMQkLgY1iOp/6xPs5xzMKqqG2y8rSuS', 'admin', 'actif');

-- ============================================
-- FIN DU SCHÉMA MySQL
-- ============================================
