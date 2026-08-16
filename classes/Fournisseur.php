<?php
/**
 * ============================================
 * CLASSE FOURNISSEUR
 * Gestion des fournisseurs des clients
 * ============================================
 */

require_once __DIR__ . '/../config/database.php';

class Fournisseur
{
    // Attributs
    private ?int $id = null;
    private string $nom = '';
    private string $type = 'grossiste';
    private ?string $ifu = null;
    private ?string $adresse = null;
    private ?string $telephone = null;
    private string $statut = 'actif';
    private ?string $dateCreation = null;

    // Instance de Database
    private Database $db;

    // Types de fournisseurs valides (voir schema.sql)
    private const TYPES = ['grossiste', 'fabricant', 'importateur', 'distributeur', 'autre'];

    // Statuts valides
    private const STATUTS = ['actif', 'inactif'];

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

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTypeLibelle(): string
    {
        return self::getTypes()[$this->type] ?? ucfirst($this->type);
    }

    public function getIfu(): ?string
    {
        return $this->ifu;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function estActif(): bool
    {
        return $this->statut === 'actif';
    }

    public function getDateCreation(): ?string
    {
        return $this->dateCreation;
    }

    // ========================================
    // SETTERS
    // ========================================

    public function setNom(string $nom): self
    {
        $nom = trim($nom);
        if ($nom === '') {
            throw new InvalidArgumentException("Le nom du fournisseur est obligatoire.");
        }
        $this->nom = $nom;
        return $this;
    }

    public function setType(string $type): self
    {
        if (!in_array($type, self::TYPES)) {
            throw new InvalidArgumentException("Type de fournisseur invalide.");
        }
        $this->type = $type;
        return $this;
    }

    public function setIfu(?string $ifu): self
    {
        $this->ifu = $ifu ? trim($ifu) : null;
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

    public function setStatut(string $statut): self
    {
        if (!in_array($statut, self::STATUTS)) {
            throw new InvalidArgumentException("Statut de fournisseur invalide.");
        }
        $this->statut = $statut;
        return $this;
    }

    // ========================================
    // MÉTHODES CRUD
    // ========================================

    /**
     * Charger un fournisseur depuis la BD
     */
    public function charger(int $id): bool
    {
        $sql = "SELECT * FROM fournisseurs WHERE id = ?";
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
        $this->nom = $data['nom'];
        $this->type = $data['type'] ?? 'grossiste';
        $this->ifu = $data['ifu'] ?? null;
        $this->adresse = $data['adresse'] ?? null;
        $this->telephone = $data['telephone'] ?? null;
        $this->statut = $data['statut'] ?? 'actif';
        $this->dateCreation = $data['date_creation'] ?? null;
    }

    /**
     * Sauvegarder le fournisseur (insert ou update)
     */
    public function sauvegarder(): bool
    {
        if ($this->nom === '') {
            throw new Exception("Le nom du fournisseur est obligatoire.");
        }

        if ($this->id === null) {
            return $this->inserer();
        }
        return $this->mettreAJour();
    }

    /**
     * Insérer un nouveau fournisseur
     */
    private function inserer(): bool
    {
        $sql = "
            INSERT INTO fournisseurs (nom, type, ifu, adresse, telephone, statut)
            VALUES (?, ?, ?, ?, ?, ?)
        ";

        $this->id = $this->db->insert($sql, [
            $this->nom,
            $this->type,
            $this->ifu,
            $this->adresse,
            $this->telephone,
            $this->statut
        ]);

        $this->logAction('creation_fournisseur', $this->id);

        return $this->id > 0;
    }

    /**
     * Mettre à jour un fournisseur existant
     */
    private function mettreAJour(): bool
    {
        $sql = "
            UPDATE fournisseurs
            SET nom = ?, type = ?, ifu = ?, adresse = ?, telephone = ?, statut = ?
            WHERE id = ?
        ";

        $result = $this->db->update($sql, [
            $this->nom,
            $this->type,
            $this->ifu,
            $this->adresse,
            $this->telephone,
            $this->statut,
            $this->id
        ]);

        $this->logAction('modification_fournisseur', $this->id);

        return $result > 0;
    }

    /**
     * Supprimer un fournisseur (uniquement s'il n'a aucun achat)
     */
    public function supprimer(): bool
    {
        if ($this->id === null) {
            return false;
        }

        // Un fournisseur référencé par des achats ne doit pas être supprimé
        $sql = "SELECT COUNT(*) as nb FROM achats WHERE fournisseur_id = ?";
        $result = $this->db->fetchOne($sql, [$this->id]);

        if ($result && (int) $result['nb'] > 0) {
            throw new Exception("Impossible de supprimer un fournisseur ayant des achats enregistrés. Désactivez-le plutôt.");
        }

        $sql = "DELETE FROM fournisseurs WHERE id = ?";
        $result = $this->db->delete($sql, [$this->id]);

        $this->logAction('suppression_fournisseur', $this->id);

        return $result > 0;
    }

    /**
     * Désactiver le fournisseur (alternative à la suppression)
     */
    public function desactiver(): bool
    {
        $this->statut = 'inactif';
        return $this->mettreAJour();
    }

    // ========================================
    // RELATIONS
    // ========================================

    /**
     * Obtenir les achats de ce fournisseur
     * (optionnellement filtrés par client et/ou période)
     */
    public function getAchats(?int $clientId = null, ?int $mois = null, ?int $annee = null): array
    {
        $sql = "SELECT * FROM achats WHERE fournisseur_id = ?";
        $params = [$this->id];

        if ($clientId !== null) {
            $sql .= " AND client_id = ?";
            $params[] = $clientId;
        }
        if ($mois !== null) {
            $sql .= " AND mois = ?";
            $params[] = $mois;
        }
        if ($annee !== null) {
            $sql .= " AND annee = ?";
            $params[] = $annee;
        }

        $sql .= " ORDER BY annee DESC, mois DESC, date_saisie DESC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Obtenir les totaux des achats de ce fournisseur
     */
    public function getTotauxAchats(?int $clientId = null, ?int $annee = null): array
    {
        $sql = "
            SELECT
                COUNT(*) as nb_achats,
                COALESCE(SUM(montant_ht), 0) as total_ht,
                COALESCE(SUM(montant_tva), 0) as total_tva,
                COALESCE(SUM(montant_ttc), 0) as total_ttc
            FROM achats
            WHERE fournisseur_id = ?
        ";
        $params = [$this->id];

        if ($clientId !== null) {
            $sql .= " AND client_id = ?";
            $params[] = $clientId;
        }
        if ($annee !== null) {
            $sql .= " AND annee = ?";
            $params[] = $annee;
        }

        $result = $this->db->fetchOne($sql, $params);

        return [
            'nb_achats' => (int) $result['nb_achats'],
            'total_ht' => (float) $result['total_ht'],
            'total_tva' => (float) $result['total_tva'],
            'total_ttc' => (float) $result['total_ttc']
        ];
    }

    // ========================================
    // MÉTHODES STATIQUES
    // ========================================

    /**
     * Obtenir tous les fournisseurs
     */
    public static function getAll(bool $actifsSeulement = false): array
    {
        $db = Database::getInstance();

        $sql = "SELECT * FROM fournisseurs";
        if ($actifsSeulement) {
            $sql .= " WHERE statut = 'actif'";
        }
        $sql .= " ORDER BY nom";

        return $db->fetchAll($sql);
    }

    /**
     * Rechercher un fournisseur par son nom exact
     */
    public static function getByNom(string $nom): ?Fournisseur
    {
        $db = Database::getInstance();

        $sql = "SELECT id FROM fournisseurs WHERE nom = ?";
        $data = $db->fetchOne($sql, [trim($nom)]);

        if ($data === null) {
            return null;
        }

        return new self((int) $data['id']);
    }

    /**
     * Trouver un fournisseur par nom, ou le créer s'il n'existe pas.
     * Si le fournisseur existe déjà, complète l'IFU/adresse s'ils sont fournis
     * (même logique que la saisie d'achats — voir pages/achats.php).
     */
    public static function trouverOuCreer(string $nom, ?string $ifu = null, ?string $adresse = null): Fournisseur
    {
        $db = Database::getInstance();
        $nom = trim($nom);

        $existant = $db->fetchOne("SELECT id FROM fournisseurs WHERE nom = ?", [$nom]);

        if ($existant) {
            if (!empty($ifu) || !empty($adresse)) {
                $db->update(
                    "UPDATE fournisseurs SET adresse = COALESCE(NULLIF(?, ''), adresse), ifu = COALESCE(NULLIF(?, ''), ifu) WHERE id = ?",
                    [(string) $adresse, (string) $ifu, $existant['id']]
                );
            }
            return new self((int) $existant['id']);
        }

        $fournisseur = new self();
        $fournisseur->setNom($nom)
                    ->setIfu($ifu)
                    ->setAdresse($adresse)
                    ->sauvegarder();

        return $fournisseur;
    }

    /**
     * Obtenir les fournisseurs utilisés par un client
     */
    public static function getByClient(int $clientId): array
    {
        $db = Database::getInstance();

        $sql = "
            SELECT DISTINCT f.*
            FROM fournisseurs f
            INNER JOIN achats a ON a.fournisseur_id = f.id
            WHERE a.client_id = ?
            ORDER BY f.nom
        ";

        return $db->fetchAll($sql, [$clientId]);
    }

    /**
     * Obtenir les types de fournisseurs
     */
    public static function getTypes(): array
    {
        return [
            'grossiste' => 'Grossiste',
            'fabricant' => 'Fabricant',
            'importateur' => 'Importateur',
            'distributeur' => 'Distributeur',
            'autre' => 'Autre'
        ];
    }

    // ========================================
    // LOGGING
    // ========================================

    /**
     * Logger une action
     */
    private function logAction(string $action, ?int $enregistrementId = null): void
    {
        $agentId = $_SESSION['agent_id'] ?? null;

        $sql = "
            INSERT INTO logs (agent_id, action, table_concernee, enregistrement_id, ip_address)
            VALUES (?, ?, 'fournisseurs', ?, ?)
        ";

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'localhost';

        $this->db->insert($sql, [$agentId, $action, $enregistrementId, $ip]);
    }

    // ========================================
    // CONVERSION EN TABLEAU
    // ========================================

    /**
     * Convertir le fournisseur en tableau
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'type' => $this->type,
            'type_libelle' => $this->getTypeLibelle(),
            'ifu' => $this->ifu,
            'adresse' => $this->adresse,
            'telephone' => $this->telephone,
            'statut' => $this->statut,
            'date_creation' => $this->dateCreation
        ];
    }
}
