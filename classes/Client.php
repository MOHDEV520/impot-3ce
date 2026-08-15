<?php
/**
 * ============================================
 * CLASSE CLIENT
 * Représente une entreprise suivie fiscalement
 * ============================================
 */

require_once __DIR__ . '/../config/database.php';

class Client
{
    // Attributs
    private ?int $id = null;
    private int $agentId;
    private string $nom = '';
    private ?string $ifu = null;
    private ?string $typeActivite = null;
    private string $secteur = 'officine';
    private string $regimeFiscal = 'reel_normal';
    private ?string $adresse = null;
    private ?string $telephone = null;
    private ?string $email = null;
    private string $statut = 'actif';
    private ?string $dateCreation = null;

    // Instance de Database
    private Database $db;

    // Secteurs valides
    private const SECTEURS_VALIDES = ['officine', 'commerce', 'service', 'industrie', 'ong', 'autre'];
    
    // Régimes fiscaux valides
    private const REGIMES_VALIDES = ['reel_normal', 'reel_simplifie', 'forfaitaire'];

    /**
     * Constructeur
     */
    public function __construct(?int $id = null)
    {
        $this->db = Database::getInstance();
        
        if ($id !== null) {
            $this->charger($id);
        }
    }

    // ========================================
    // GETTERS
    // ========================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAgentId(): int
    {
        return $this->agentId;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getIfu(): ?string
    {
        return $this->ifu;
    }

    public function getTypeActivite(): ?string
    {
        return $this->typeActivite;
    }

    public function getSecteur(): string
    {
        return $this->secteur;
    }

    public function getSecteurLibelle(): string
    {
        $libelles = [
            'officine' => 'Officine (Pharmacie)',
            'commerce' => 'Commerce',
            'service' => 'Service',
            'industrie' => 'Industrie',
            'ong' => 'ONG',
            'autre' => 'Autre'
        ];
        return $libelles[$this->secteur] ?? $this->secteur;
    }

    public function getRegimeFiscal(): string
    {
        return $this->regimeFiscal;
    }

    public function getRegimeFiscalLibelle(): string
    {
        $libelles = [
            'reel_normal' => 'Réel Normal',
            'reel_simplifie' => 'Réel Simplifié',
            'forfaitaire' => 'Forfaitaire'
        ];
        return $libelles[$this->regimeFiscal] ?? $this->regimeFiscal;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getDateCreation(): ?string
    {
        return $this->dateCreation;
    }

    // ========================================
    // SETTERS
    // ========================================

    public function setAgentId(int $agentId): self
    {
        $this->agentId = $agentId;
        return $this;
    }

    public function setNom(string $nom): self
    {
        $nom = trim($nom);
        if (empty($nom)) {
            throw new InvalidArgumentException("Le nom du client est obligatoire.");
        }
        $this->nom = $nom;
        return $this;
    }

    public function setIfu(?string $ifu): self
    {
        $this->ifu = $ifu ? trim($ifu) : null;
        return $this;
    }

    public function setTypeActivite(?string $typeActivite): self
    {
        $this->typeActivite = $typeActivite ? trim($typeActivite) : null;
        return $this;
    }

    public function setSecteur(string $secteur): self
    {
        if (!in_array($secteur, self::SECTEURS_VALIDES)) {
            throw new InvalidArgumentException("Secteur invalide.");
        }
        $this->secteur = $secteur;
        return $this;
    }

    public function setRegimeFiscal(string $regime): self
    {
        if (!in_array($regime, self::REGIMES_VALIDES)) {
            throw new InvalidArgumentException("Régime fiscal invalide.");
        }
        $this->regimeFiscal = $regime;
        return $this;
    }

    public function setAdresse(?string $adresse): self
    {
        $this->adresse = $adresse ? trim($adresse) : null;
        return $this;
    }

    public function setTelephone(?string $telephone): self
    {
        $this->telephone = $telephone ? trim($telephone) : null;
        return $this;
    }

    public function setEmail(?string $email): self
    {
        if ($email) {
            $email = trim(strtolower($email));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException("Email invalide.");
            }
        }
        $this->email = $email ?: null;
        return $this;
    }

    public function setStatut(string $statut): self
    {
        $statutsValides = ['actif', 'inactif', 'archive'];
        if (!in_array($statut, $statutsValides)) {
            throw new InvalidArgumentException("Statut invalide.");
        }
        $this->statut = $statut;
        return $this;
    }

    // ========================================
    // MÉTHODES CRUD
    // ========================================

    /**
     * Charger un client depuis la BD
     */
    public function charger(int $id): bool
    {
        $sql = "SELECT * FROM clients WHERE id = ?";
        $data = $this->db->fetchOne($sql, [$id]);
        
        if ($data === null) {
            return false;
        }
        
        $this->hydrater($data);
        return true;
    }

    /**
     * Hydrater l'objet avec les données
     */
    private function hydrater(array $data): void
    {
        $this->id = (int) $data['id'];
        $this->agentId = (int) $data['agent_id'];
        $this->nom = $data['nom'];
        $this->ifu = $data['ifu'];
        $this->typeActivite = $data['type_activite'];
        $this->secteur = $data['secteur'];
        $this->regimeFiscal = $data['regime_fiscal'];
        $this->adresse = $data['adresse'];
        $this->telephone = $data['telephone'];
        $this->email = $data['email'];
        $this->statut = $data['statut'];
        $this->dateCreation = $data['date_creation'];
    }

    /**
     * Sauvegarder le client (insert ou update)
     */
    public function sauvegarder(): bool
    {
        if ($this->id === null) {
            return $this->inserer();
        }
        return $this->mettreAJour();
    }

    /**
     * Insérer un nouveau client
     */
    private function inserer(): bool
    {
        // Vérifier l'unicité de l'IFU
        if ($this->ifu && $this->ifuExiste($this->ifu)) {
            throw new Exception("Cet IFU est déjà utilisé.");
        }
        
        $sql = "
            INSERT INTO clients (agent_id, nom, ifu, type_activite, secteur, regime_fiscal, adresse, telephone, email, statut)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $this->id = $this->db->insert($sql, [
            $this->agentId,
            $this->nom,
            $this->ifu,
            $this->typeActivite,
            $this->secteur,
            $this->regimeFiscal,
            $this->adresse,
            $this->telephone,
            $this->email,
            $this->statut
        ]);
        
        // Créer les paramètres fiscaux par défaut
        if ($this->id > 0) {
            $this->creerParametresFiscauxDefaut();
            $this->logAction('creation_client', $this->id);
        }
        
        return $this->id > 0;
    }

    /**
     * Mettre à jour un client existant
     */
    private function mettreAJour(): bool
    {
        // Vérifier l'unicité de l'IFU (sauf pour ce client)
        if ($this->ifu && $this->ifuExiste($this->ifu, $this->id)) {
            throw new Exception("Cet IFU est déjà utilisé.");
        }
        
        $sql = "
            UPDATE clients 
            SET agent_id = ?, nom = ?, ifu = ?, type_activite = ?, secteur = ?, 
                regime_fiscal = ?, adresse = ?, telephone = ?, email = ?, statut = ?
            WHERE id = ?
        ";
        
        $result = $this->db->update($sql, [
            $this->agentId,
            $this->nom,
            $this->ifu,
            $this->typeActivite,
            $this->secteur,
            $this->regimeFiscal,
            $this->adresse,
            $this->telephone,
            $this->email,
            $this->statut,
            $this->id
        ]);
        
        $this->logAction('modification_client', $this->id);
        
        return $result >= 0;
    }

    /**
     * Vérifier si un IFU existe déjà
     */
    private function ifuExiste(string $ifu, ?int $excludeId = null): bool
    {
        $sql = "SELECT id FROM clients WHERE ifu = ?";
        $params = [$ifu];
        
        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        
        return $this->db->fetchOne($sql, $params) !== null;
    }

    /**
     * Créer les paramètres fiscaux par défaut
     */
    private function creerParametresFiscauxDefaut(): void
    {
        $sql = "INSERT INTO parametres_fiscaux (client_id) VALUES (?)";
        $this->db->insert($sql, [$this->id]);
    }

    /**
     * Supprimer un client (soft delete)
     */
    public function supprimer(): bool
    {
        $this->statut = 'archive';
        $this->logAction('suppression_client', $this->id);
        return $this->mettreAJour();
    }

    // ========================================
    // PARAMÈTRES FISCAUX
    // ========================================

    /**
     * Obtenir les paramètres fiscaux du client
     */
    public function getParametresFiscaux(): ?array
    {
        $sql = "SELECT * FROM parametres_fiscaux WHERE client_id = ?";
        return $this->db->fetchOne($sql, [$this->id]);
    }

    /**
     * Mettre à jour les paramètres fiscaux
     */
    public function setParametresFiscaux(array $params): bool
    {
        $sql = "
            UPDATE parametres_fiscaux 
            SET type_tva = ?, 
                taux_tva = ?, 
                taux_tva_double = ?, 
                salaires_actif = ?, 
                location_actif = ?, 
                irf_tf_actif = ?,
                css_actif = ?,
                ras_actif = ?,
                sans_marges = ?, 
                marge = ?,
                marge_taxable = ?,
                valeur_locative_mensuelle = ?,
                taux_cf = ?,
                taux_tl = ?,
                taux_css = ?,
                taux_irf = ?,
                taux_tf = ?,
                date_modification = CURRENT_TIMESTAMP
            WHERE client_id = ?
        ";
        
        $result = $this->db->update($sql, [
            $params['type_tva'] ?? 'non_exonere',
            $params['taux_tva'] ?? 18.00,
            $params['taux_tva_double'] ?? 0,
            $params['salaires_actif'] ?? 0,
            $params['location_actif'] ?? 0,
            $params['irf_tf_actif'] ?? 0,
            $params['css_actif'] ?? 1,
            $params['ras_actif'] ?? 0,
            $params['sans_marges'] ?? 0,
            $params['marge'] ?? 1.30,
            $params['marge_taxable'] ?? 1.30,
            $params['valeur_locative_mensuelle'] ?? 0,
            $params['taux_cf'] ?? 3.5,
            $params['taux_tl'] ?? 1.0,
            $params['taux_css'] ?? 0.5,
            $params['taux_irf'] ?? 12.0,
            $params['taux_tf'] ?? 3.0,
            $this->id
        ]);
        
        $this->logAction('modification_parametres_fiscaux', $this->id);
        
        return $result >= 0;
    }

    /**
     * Vérifier si le client est soumis à la TVA
     */
    public function estSoumisTVA(): bool
    {
        $params = $this->getParametresFiscaux();
        return $params && $params['type_tva'] !== 'exonere_total';
    }

    /**
     * Obtenir le taux de TVA applicable
     */
    public function getTauxTVA(): float
    {
        $params = $this->getParametresFiscaux();
        return $params ? (float) $params['taux_tva'] : 18.00;
    }

    /**
     * Vérifier si le client est sans marges (saisie manuelle totale)
     */
    public function isSansMarges(): bool
    {
        $params = $this->getParametresFiscaux();
        return $params && (int) ($params['sans_marges'] ?? 0) === 1;
    }

    // ========================================
    // COMPTE DE GESTION MENSUEL
    // ========================================

    /**
     * Obtenir ou créer le compte de gestion pour un mois
     */
    public function getCompteGestion(int $mois, int $annee): array
    {
        $sql = "SELECT * FROM compte_gestion_mensuel WHERE client_id = ? AND mois = ? AND annee = ?";
        $compte = $this->db->fetchOne($sql, [$this->id, $mois, $annee]);
        
        if ($compte === null) {
            // Créer le compte de gestion
            $sql = "INSERT INTO compte_gestion_mensuel (client_id, mois, annee) VALUES (?, ?, ?)";
            $compteId = $this->db->insert($sql, [$this->id, $mois, $annee]);
            
            return $this->db->fetchOne(
                "SELECT * FROM compte_gestion_mensuel WHERE id = ?",
                [$compteId]
            );
        }
        
        return $compte;
    }

    /**
     * Vérifier si un mois est verrouillé
     */
    public function moisEstVerrouille(int $mois, int $annee): bool
    {
        $compte = $this->getCompteGestion($mois, $annee);
        return $compte['statut'] === 'verrouille';
    }

    /**
     * Obtenir l'historique des mois
     */
    public function getHistoriqueMois(int $annee): array
    {
        $sql = "
            SELECT cgm.*, 
                   COALESCE(im.total_impots, 0) as total_impots
            FROM compte_gestion_mensuel cgm
            LEFT JOIN impots_mensuels im ON cgm.client_id = im.client_id 
                AND cgm.mois = im.mois AND cgm.annee = im.annee
            WHERE cgm.client_id = ? AND cgm.annee = ?
            ORDER BY cgm.mois
        ";
        return $this->db->fetchAll($sql, [$this->id, $annee]);
    }

    // ========================================
    // STATISTIQUES
    // ========================================

    /**
     * Obtenir le résumé annuel
     */
    public function getResumeAnnuel(int $annee): array
    {
        $sql = "
            SELECT 
                SUM(ca_global) as ca_total,
                SUM(masse_salariale) as salaires_total
            FROM compte_gestion_mensuel
            WHERE client_id = ? AND annee = ?
        ";
        $resume = $this->db->fetchOne($sql, [$this->id, $annee]);
        
        $sql = "
            SELECT SUM(total_impots) as impots_total
            FROM impots_mensuels
            WHERE client_id = ? AND annee = ?
        ";
        $impots = $this->db->fetchOne($sql, [$this->id, $annee]);
        
        return [
            'ca_total' => (float) ($resume['ca_total'] ?? 0),
            'salaires_total' => (float) ($resume['salaires_total'] ?? 0),
            'impots_total' => (float) ($impots['impots_total'] ?? 0)
        ];
    }

    /**
     * Obtenir le total des achats pour un mois
     */
    public function getTotalAchatsMois(int $mois, int $annee): array
    {
        $sql = "
            SELECT 
                COALESCE(SUM(montant_ht), 0) as total_ht,
                COALESCE(SUM(montant_tva), 0) as total_tva,
                COALESCE(SUM(montant_ttc), 0) as total_ttc,
                COALESCE(SUM(CASE WHEN tva_deductible = 1 THEN montant_tva ELSE 0 END), 0) as tva_deductible
            FROM achats
            WHERE client_id = ? AND mois = ? AND annee = ?
        ";
        return $this->db->fetchOne($sql, [$this->id, $mois, $annee]);
    }

    /**
     * Obtenir le total des dépenses pour un mois
     */
    public function getTotalDepensesMois(int $mois, int $annee): array
    {
        $sql = "
            SELECT 
                nd.libelle as nature,
                COALESCE(SUM(d.montant), 0) as total
            FROM natures_depenses nd
            LEFT JOIN depenses d ON nd.id = d.nature_id 
                AND d.client_id = ? AND d.mois = ? AND d.annee = ?
            GROUP BY nd.id, nd.libelle
            ORDER BY nd.ordre_affichage
        ";
        $parNature = $this->db->fetchAll($sql, [$this->id, $mois, $annee]);
        
        $sql = "
            SELECT COALESCE(SUM(montant), 0) as total
            FROM depenses
            WHERE client_id = ? AND mois = ? AND annee = ?
        ";
        $total = $this->db->fetchOne($sql, [$this->id, $mois, $annee]);
        
        return [
            'par_nature' => $parNature,
            'total' => (float) $total['total']
        ];
    }

    // ========================================
    // MÉTHODES STATIQUES
    // ========================================

    /**
     * Obtenir tous les clients
     */
    public static function getAll(bool $actifsUniquement = true): array
    {
        $db = Database::getInstance();
        
        $sql = "SELECT * FROM clients";
        if ($actifsUniquement) {
            $sql .= " WHERE statut = 'actif'";
        }
        $sql .= " ORDER BY nom";
        
        return $db->fetchAll($sql);
    }

    /**
     * Obtenir un client par son ID
     */
    public static function getById(int $id): ?array
    {
        $db = Database::getInstance();
        return $db->fetchOne("SELECT * FROM clients WHERE id = ?", [$id]);
    }

    /**
     * Obtenir les clients par agent
     */
    public static function getByAgent(int $agentId, bool $actifsUniquement = true): array
    {
        $db = Database::getInstance();
        
        $sql = "SELECT * FROM clients WHERE agent_id = ?";
        if ($actifsUniquement) {
            $sql .= " AND statut = 'actif'";
        }
        $sql .= " ORDER BY nom";
        
        return $db->fetchAll($sql, [$agentId]);
    }

    /**
     * Rechercher des clients
     */
    public static function rechercher(string $terme, ?int $agentId = null): array
    {
        $db = Database::getInstance();
        
        $terme = '%' . $terme . '%';
        
        $sql = "SELECT * FROM clients WHERE (nom LIKE ? OR ifu LIKE ?) AND statut = 'actif'";
        $params = [$terme, $terme];
        
        if ($agentId !== null) {
            $sql .= " AND agent_id = ?";
            $params[] = $agentId;
        }
        
        $sql .= " ORDER BY nom LIMIT 50";
        
        return $db->fetchAll($sql, $params);
    }

    /**
     * Trouver un client par IFU
     */
    public static function findByIfu(string $ifu): ?Client
    {
        $db = Database::getInstance();
        
        $sql = "SELECT id FROM clients WHERE ifu = ?";
        $result = $db->fetchOne($sql, [$ifu]);
        
        if ($result === null) {
            return null;
        }
        
        return new self((int) $result['id']);
    }

    /**
     * Obtenir les secteurs disponibles
     */
    public static function getSecteurs(): array
    {
        $defaults = [
            'officine' => 'Officine (Pharmacie)',
            'commerce' => 'Commerce',
            'service' => 'Service',
            'industrie' => 'Industrie',
            'ong' => 'ONG',
            'autre' => 'Autre'
        ];

        try {
            $db = Database::getInstance();
            $rows = $db->fetchAll("SELECT DISTINCT secteur FROM clients WHERE secteur IS NOT NULL AND secteur != '' ORDER BY secteur");
            foreach ($rows as $row) {
                $val = trim($row['secteur']);
                if ($val !== '' && !isset($defaults[$val])) {
                    $defaults[$val] = ucfirst($val);
                }
            }
        } catch (Exception $e) {
            // Fallback to defaults
        }

        return $defaults;
    }

    /**
     * Obtenir les régimes fiscaux disponibles
     */
    public static function getRegimesFiscaux(): array
    {
        return [
            'reel_normal' => 'Réel Normal',
            'reel_simplifie' => 'Réel Simplifié',
            'forfaitaire' => 'Forfaitaire'
        ];
    }

    /**
     * Obtenir les types d'activité déjà utilisés (pour autocomplétion)
     */
    public static function getTypesActivite(): array
    {
        try {
            $db = Database::getInstance();
            $rows = $db->fetchAll(
                "SELECT DISTINCT type_activite FROM clients WHERE type_activite IS NOT NULL AND type_activite != '' ORDER BY type_activite"
            );
            return array_column($rows, 'type_activite');
        } catch (Exception $e) {
            return [];
        }
    }

    // ========================================
    // LOGGING
    // ========================================

    /**
     * Logger une action
     */
    private function logAction(string $action, ?int $enregistrementId = null): void
    {
        $agentId = null;
        if (isset($_SESSION['agent_id'])) {
            $agentId = $_SESSION['agent_id'];
        }
        
        $sql = "
            INSERT INTO logs (agent_id, action, table_concernee, enregistrement_id, ip_address)
            VALUES (?, ?, 'clients', ?, ?)
        ";
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'localhost';
        
        $this->db->insert($sql, [$agentId, $action, $enregistrementId, $ip]);
    }

    // ========================================
    // CONVERSION EN TABLEAU
    // ========================================

    /**
     * Convertir le client en tableau
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'agent_id' => $this->agentId,
            'nom' => $this->nom,
            'ifu' => $this->ifu,
            'type_activite' => $this->typeActivite,
            'secteur' => $this->secteur,
            'secteur_libelle' => $this->getSecteurLibelle(),
            'regime_fiscal' => $this->regimeFiscal,
            'regime_fiscal_libelle' => $this->getRegimeFiscalLibelle(),
            'adresse' => $this->adresse,
            'telephone' => $this->telephone,
            'email' => $this->email,
            'statut' => $this->statut,
            'date_creation' => $this->dateCreation,
            'parametres_fiscaux' => $this->getParametresFiscaux()
        ];
    }
}
