-- ============================================
-- SYSTÈME DE GESTION FISCALE - SCHÉMA SQLite
-- ============================================

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
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    mot_de_passe VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'agent' CHECK (role IN ('agent', 'admin', 'superviseur')),
    statut VARCHAR(20) DEFAULT 'actif' CHECK (statut IN ('actif', 'inactif', 'suspendu')),
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    derniere_connexion DATETIME
);

-- ============================================
-- TABLE: clients
-- Entreprises suivies fiscalement
-- ============================================
CREATE TABLE clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    agent_id INTEGER NOT NULL,
    nom VARCHAR(200) NOT NULL,
    ifu VARCHAR(50) UNIQUE,
    type_activite VARCHAR(100),
    secteur VARCHAR(50) DEFAULT 'officine' CHECK (secteur IN ('officine', 'commerce', 'service', 'industrie', 'ong', 'autre')),
    regime_fiscal VARCHAR(50) DEFAULT 'reel_normal' CHECK (regime_fiscal IN ('reel_normal', 'reel_simplifie', 'forfaitaire')),
    adresse TEXT,
    telephone VARCHAR(20),
    email VARCHAR(150),
    statut VARCHAR(20) DEFAULT 'actif' CHECK (statut IN ('actif', 'inactif', 'archive')),
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE RESTRICT
);

-- ============================================
-- TABLE: parametres_fiscaux
-- Configuration fiscale par client
-- ============================================
CREATE TABLE parametres_fiscaux (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER UNIQUE NOT NULL,
    
    -- TVA
    type_tva VARCHAR(20) DEFAULT 'non_exonere' CHECK (type_tva IN ('non_exonere', 'exonere_partiel', 'exonere_total')),
    taux_tva DECIMAL(5,2) DEFAULT 18.00 CHECK (taux_tva IN (18.00, 5.00)),
    
    -- Sections actives
    salaires_actif BOOLEAN DEFAULT 0,
    location_actif BOOLEAN DEFAULT 0,
    irf_tf_actif BOOLEAN DEFAULT 0,
    css_actif BOOLEAN DEFAULT 1,
    taxe_touristique_actif BOOLEAN DEFAULT 0,
    
    -- Location (si applicable)
    valeur_locative_mensuelle DECIMAL(15,2) DEFAULT 0,
    
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);

-- ============================================
-- TABLE: fournisseurs
-- Fournisseurs des clients
-- ============================================
CREATE TABLE fournisseurs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nom VARCHAR(200) NOT NULL,
    type VARCHAR(50) DEFAULT 'grossiste' CHECK (type IN ('grossiste', 'fabricant', 'importateur', 'distributeur', 'autre')),
    ifu VARCHAR(50),
    adresse TEXT,
    telephone VARCHAR(20),
    statut VARCHAR(20) DEFAULT 'actif' CHECK (statut IN ('actif', 'inactif')),
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- TABLE: compte_gestion_mensuel
-- Données consolidées par mois/client
-- ============================================
CREATE TABLE compte_gestion_mensuel (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    mois INTEGER NOT NULL CHECK (mois BETWEEN 1 AND 12),
    annee INTEGER NOT NULL CHECK (annee >= 2020),
    
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
    
    -- CF et TL
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
    
    -- Taxe Touristique (Loi n°96-052) - lignes 110/510/520
    taxe_touristique_type VARCHAR(20) DEFAULT '',
    taxe_touristique_ligne510 DECIMAL(15,2) DEFAULT 0,
    taxe_touristique_ligne520 DECIMAL(15,2) DEFAULT 0,

    -- Impôts directs et paramètres
    its DECIMAL(15,2) DEFAULT 0,
    marge DECIMAL(5,3) DEFAULT 1.30,
    marge_taxable DECIMAL(5,3) DEFAULT 1.30,
    
    -- Statut
    statut VARCHAR(30) DEFAULT 'en_preparation' CHECK (statut IN ('en_preparation', 'pret_declaration', 'valide', 'verrouille')),
    date_validation DATETIME,
    valide_par INTEGER,
    
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE(client_id, mois, annee),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (valide_par) REFERENCES agents(id)
);

-- ============================================
-- TABLE: achats
-- Achats fournisseurs
-- ============================================
CREATE TABLE achats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    fournisseur_id INTEGER NOT NULL,
    compte_gestion_id INTEGER NOT NULL,
    
    mois INTEGER NOT NULL CHECK (mois BETWEEN 1 AND 12),
    annee INTEGER NOT NULL CHECK (annee >= 2020),
    
    -- Montants
    montant_ht DECIMAL(15,2) NOT NULL DEFAULT 0,
    montant_tva DECIMAL(15,2) NOT NULL DEFAULT 0,
    montant_ttc DECIMAL(15,2) NOT NULL DEFAULT 0,
    
    -- Document
    type_document VARCHAR(30) DEFAULT 'releve' CHECK (type_document IN ('releve', 'facture')),
    reference_document VARCHAR(100),
    date_document DATE,
    
    -- TVA déductible
    tva_deductible BOOLEAN DEFAULT 1,
    
    -- Nature de l'opération (pour les annexes)
    nature_operation VARCHAR(255) DEFAULT 'achat',
    
    -- Optionnel
    exclure_ca BOOLEAN DEFAULT 0,
    
    date_saisie DATETIME DEFAULT CURRENT_TIMESTAMP,
    saisi_par INTEGER,
    
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (fournisseur_id) REFERENCES fournisseurs(id) ON DELETE RESTRICT,
    FOREIGN KEY (compte_gestion_id) REFERENCES compte_gestion_mensuel(id) ON DELETE CASCADE,
    FOREIGN KEY (saisi_par) REFERENCES agents(id)
);

-- ============================================
-- TABLE: services_annexe_tva
-- Services spécifiques pour annexe 1.1 TVA
-- ============================================
CREATE TABLE services_annexe_tva (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    mois INTEGER NOT NULL CHECK (mois BETWEEN 1 AND 12),
    annee INTEGER NOT NULL CHECK (annee >= 2020),
    prestataire_nom VARCHAR(200) NOT NULL,
    prestataire_adresse TEXT,
    prestataire_nif VARCHAR(50),
    montant_ht DECIMAL(15,2) NOT NULL DEFAULT 0,
    taux_tva DECIMAL(5,2) DEFAULT 18.00,
    montant_tva DECIMAL(15,2) NOT NULL DEFAULT 0,
    reference_document VARCHAR(100),
    date_document DATE,
    date_saisie DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);

-- ============================================
-- TABLE: exonerations_client
-- Lignes d'exonération configurables par client
-- ============================================
CREATE TABLE exonerations_client (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    numero_ordre INTEGER DEFAULT 0,
    code_produit VARCHAR(50),
    nature VARCHAR(255) NOT NULL,
    montant_ht DECIMAL(15,2) DEFAULT 0,
    taux VARCHAR(50),
    actif BOOLEAN DEFAULT 1,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);

-- ============================================
-- TABLE: natures_depenses
-- Catégories de dépenses paramétrables
-- ============================================
CREATE TABLE natures_depenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    libelle VARCHAR(100) NOT NULL,
    deductible BOOLEAN DEFAULT 1,
    ordre_affichage INTEGER DEFAULT 0
);

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
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    compte_gestion_id INTEGER NOT NULL,
    nature_id INTEGER NOT NULL,
    
    mois INTEGER NOT NULL CHECK (mois BETWEEN 1 AND 12),
    annee INTEGER NOT NULL CHECK (annee >= 2020),
    
    montant DECIMAL(15,2) NOT NULL DEFAULT 0,
    description TEXT,
    
    date_saisie DATETIME DEFAULT CURRENT_TIMESTAMP,
    saisi_par INTEGER,
    
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (compte_gestion_id) REFERENCES compte_gestion_mensuel(id) ON DELETE CASCADE,
    FOREIGN KEY (nature_id) REFERENCES natures_depenses(id) ON DELETE RESTRICT,
    FOREIGN KEY (saisi_par) REFERENCES agents(id)
);

-- ============================================
-- TABLE: impots_mensuels
-- Calculs d'impôts par mois
-- ============================================
CREATE TABLE impots_mensuels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    compte_gestion_id INTEGER NOT NULL,
    
    mois INTEGER NOT NULL CHECK (mois BETWEEN 1 AND 12),
    annee INTEGER NOT NULL CHECK (annee >= 2020),
    
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

    -- Retenue à la Source BIC/IS
    ras DECIMAL(15,2) DEFAULT 0,

    -- Taxe Touristique (Loi n°96-052) - Lig. 510 x 520
    taxe_touristique DECIMAL(15,2) DEFAULT 0,

    -- Total
    total_impots DECIMAL(15,2) DEFAULT 0,
    
    date_calcul DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE(client_id, mois, annee),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (compte_gestion_id) REFERENCES compte_gestion_mensuel(id) ON DELETE CASCADE
);

-- ============================================
-- TABLE: annexes
-- Justificatifs d'exonération
-- ============================================
CREATE TABLE annexes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    
    type_annexe VARCHAR(1) NOT NULL CHECK (type_annexe IN ('A', 'B', 'C', 'D')),
    type_impot VARCHAR(30) NOT NULL,
    
    reference VARCHAR(200) NOT NULL,
    base_legale TEXT,
    date_debut DATE NOT NULL,
    date_fin DATE,
    
    fichier_path VARCHAR(500),
    
    statut VARCHAR(20) DEFAULT 'valide' CHECK (statut IN ('valide', 'expire', 'annule')),
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);

-- ============================================
-- TABLE: logs
-- Journal des actions (sécurité/traçabilité)
-- ============================================
CREATE TABLE logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    agent_id INTEGER,
    action VARCHAR(100) NOT NULL,
    table_concernee VARCHAR(50),
    enregistrement_id INTEGER,
    details TEXT,
    ip_address VARCHAR(45),
    date_action DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE SET NULL
);

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
-- L'agent admin est créé via install.php
-- avec le bon hash de mot de passe
-- ============================================

-- ============================================
-- FIN DU SCHÉMA
-- ============================================
