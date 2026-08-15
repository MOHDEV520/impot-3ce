<?php
/**
 * ============================================
 * CLASSE DEPENSE
 * Gestion des dépenses courantes
 * ============================================
 */

require_once __DIR__ . '/../config/database.php';

class Depense
{
    // Attributs
    private ?int $id = null;
    private int $clientId;
    private int $compteGestionId;
    private int $natureId;
    private int $mois;
    private int $annee;
    private float $montant = 0;
    private ?string $description = null;
    private ?string $dateSaisie = null;
    private ?int $saisiPar = null;

    // Instance de Database
    private Database $db;

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

    public function getClientId(): int
    {
        return $this->clientId;
    }

    public function getCompteGestionId(): int
    {
        return $this->compteGestionId;
    }

    public function getNatureId(): int
    {
        return $this->natureId;
    }

    public function getMois(): int
    {
        return $this->mois;
    }

    public function getAnnee(): int
    {
        return $this->annee;
    }

    public function getMontant(): float
    {
        return $this->montant;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getDateSaisie(): ?string
    {
        return $this->dateSaisie;
    }

    public function getSaisiPar(): ?int
    {
        return $this->saisiPar;
    }

    /**
     * Obtenir le libellé de la nature
     */
    public function getNatureLibelle(): string
    {
        $sql = "SELECT libelle FROM natures_depenses WHERE id = ?";
        $result = $this->db->fetchOne($sql, [$this->natureId]);
        return $result ? $result['libelle'] : 'Inconnu';
    }

    /**
     * Obtenir le code de la nature
     */
    public function getNatureCode(): string
    {
        $sql = "SELECT code FROM natures_depenses WHERE id = ?";
        $result = $this->db->fetchOne($sql, [$this->natureId]);
        return $result ? $result['code'] : '';
    }

    // ========================================
    // SETTERS
    // ========================================

    public function setClientId(int $clientId): self
    {
        $this->clientId = $clientId;
        return $this;
    }

    public function setCompteGestionId(int $compteGestionId): self
    {
        $this->compteGestionId = $compteGestionId;
        return $this;
    }

    public function setNatureId(int $natureId): self
    {
        // Vérifier que la nature existe
        $sql = "SELECT id FROM natures_depenses WHERE id = ?";
        if (!$this->db->fetchOne($sql, [$natureId])) {
            throw new InvalidArgumentException("Nature de dépense invalide.");
        }
        $this->natureId = $natureId;
        return $this;
    }

    public function setMois(int $mois): self
    {
        if ($mois < 1 || $mois > 12) {
            throw new InvalidArgumentException("Le mois doit être entre 1 et 12.");
        }
        $this->mois = $mois;
        return $this;
    }

    public function setAnnee(int $annee): self
    {
        if ($annee < 2020) {
            throw new InvalidArgumentException("L'année doit être supérieure ou égale à 2020.");
        }
        $this->annee = $annee;
        return $this;
    }

    public function setMontant(float $montant): self
    {
        if ($montant < 0) {
            throw new InvalidArgumentException("Le montant ne peut pas être négatif.");
        }
        $this->montant = round($montant, 2);
        return $this;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description ? trim($description) : null;
        return $this;
    }

    public function setSaisiPar(?int $agentId): self
    {
        $this->saisiPar = $agentId;
        return $this;
    }

    // ========================================
    // MÉTHODES CRUD
    // ========================================

    /**
     * Charger une dépense depuis la BD
     */
    public function charger(int $id): bool
    {
        $sql = "SELECT * FROM depenses WHERE id = ?";
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
        $this->clientId = (int) $data['client_id'];
        $this->compteGestionId = (int) $data['compte_gestion_id'];
        $this->natureId = (int) $data['nature_id'];
        $this->mois = (int) $data['mois'];
        $this->annee = (int) $data['annee'];
        $this->montant = (float) $data['montant'];
        $this->description = $data['description'];
        $this->dateSaisie = $data['date_saisie'];
        $this->saisiPar = $data['saisi_par'] ? (int) $data['saisi_par'] : null;
    }

    /**
     * Sauvegarder la dépense (insert ou update)
     */
    public function sauvegarder(): bool
    {
        // Vérifier que le mois n'est pas verrouillé
        if ($this->moisEstVerrouille()) {
            throw new Exception("Impossible de modifier un mois verrouillé.");
        }
        
        if ($this->id === null) {
            return $this->inserer();
        }
        return $this->mettreAJour();
    }

    /**
     * Insérer une nouvelle dépense
     */
    private function inserer(): bool
    {
        $agentId = $_SESSION['agent_id'] ?? null;
        
        $sql = "
            INSERT INTO depenses (
                client_id, compte_gestion_id, nature_id, mois, annee,
                montant, description, saisi_par
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $this->id = $this->db->insert($sql, [
            $this->clientId,
            $this->compteGestionId,
            $this->natureId,
            $this->mois,
            $this->annee,
            $this->montant,
            $this->description,
            $agentId
        ]);
        
        $this->logAction('creation_depense', $this->id);
        
        return $this->id > 0;
    }

    /**
     * Mettre à jour une dépense existante
     */
    private function mettreAJour(): bool
    {
        $sql = "
            UPDATE depenses 
            SET nature_id = ?, montant = ?, description = ?
            WHERE id = ?
        ";
        
        $result = $this->db->update($sql, [
            $this->natureId,
            $this->montant,
            $this->description,
            $this->id
        ]);
        
        $this->logAction('modification_depense', $this->id);
        
        return $result > 0;
    }

    /**
     * Supprimer une dépense
     */
    public function supprimer(): bool
    {
        if ($this->moisEstVerrouille()) {
            throw new Exception("Impossible de supprimer une dépense d'un mois verrouillé.");
        }
        
        $sql = "DELETE FROM depenses WHERE id = ?";
        $result = $this->db->delete($sql, [$this->id]);
        
        $this->logAction('suppression_depense', $this->id);
        
        return $result > 0;
    }

    /**
     * Vérifier si le mois est verrouillé
     */
    private function moisEstVerrouille(): bool
    {
        $sql = "SELECT statut FROM compte_gestion_mensuel WHERE client_id = ? AND mois = ? AND annee = ?";
        $result = $this->db->fetchOne($sql, [$this->clientId, $this->mois, $this->annee]);
        
        return $result && $result['statut'] === 'verrouille';
    }

    /**
     * Vérifier si la dépense est déductible
     */
    public function estDeductible(): bool
    {
        $sql = "SELECT deductible FROM natures_depenses WHERE id = ?";
        $result = $this->db->fetchOne($sql, [$this->natureId]);
        
        return $result && (bool) $result['deductible'];
    }

    // ========================================
    // MÉTHODES STATIQUES
    // ========================================

    /**
     * Obtenir les dépenses d'un client pour un mois
     */
    public static function getByClientMois(int $clientId, int $mois, int $annee): array
    {
        $db = Database::getInstance();
        
        $sql = "
            SELECT d.*, nd.code as nature_code, nd.libelle as nature_libelle
            FROM depenses d
            JOIN natures_depenses nd ON d.nature_id = nd.id
            WHERE d.client_id = ? AND d.mois = ? AND d.annee = ?
            ORDER BY nd.ordre_affichage, d.date_saisie
        ";
        
        return $db->fetchAll($sql, [$clientId, $mois, $annee]);
    }

    /**
     * Obtenir les dépenses groupées par nature
     */
    public static function getByClientMoisGroupeParNature(int $clientId, int $mois, int $annee): array
    {
        $db = Database::getInstance();
        
        $sql = "
            SELECT 
                nd.id as nature_id,
                nd.code as nature_code,
                nd.libelle as nature_libelle,
                nd.deductible,
                COUNT(d.id) as nb_depenses,
                COALESCE(SUM(d.montant), 0) as total
            FROM natures_depenses nd
            LEFT JOIN depenses d ON nd.id = d.nature_id 
                AND d.client_id = ? AND d.mois = ? AND d.annee = ?
            GROUP BY nd.id, nd.code, nd.libelle, nd.deductible
            ORDER BY nd.ordre_affichage
        ";
        
        return $db->fetchAll($sql, [$clientId, $mois, $annee]);
    }

    /**
     * Obtenir le total des dépenses pour un client/mois
     */
    public static function getTotal(int $clientId, int $mois, int $annee): array
    {
        $db = Database::getInstance();
        
        $sql = "
            SELECT 
                COALESCE(SUM(d.montant), 0) as total,
                COALESCE(SUM(CASE WHEN nd.deductible = 1 THEN d.montant ELSE 0 END), 0) as total_deductible,
                COUNT(d.id) as nb_depenses
            FROM depenses d
            JOIN natures_depenses nd ON d.nature_id = nd.id
            WHERE d.client_id = ? AND d.mois = ? AND d.annee = ?
        ";
        
        $result = $db->fetchOne($sql, [$clientId, $mois, $annee]);
        
        return [
            'total' => (float) $result['total'],
            'total_deductible' => (float) $result['total_deductible'],
            'nb_depenses' => (int) $result['nb_depenses']
        ];
    }

    /**
     * Obtenir l'historique des dépenses d'un client
     */
    public static function getHistorique(int $clientId, int $annee): array
    {
        $db = Database::getInstance();
        
        $sql = "
            SELECT 
                mois,
                COUNT(*) as nb_depenses,
                SUM(montant) as total
            FROM depenses
            WHERE client_id = ? AND annee = ?
            GROUP BY mois
            ORDER BY mois
        ";
        
        return $db->fetchAll($sql, [$clientId, $annee]);
    }

    /**
     * Créer une dépense rapidement
     */
    public static function creer(
        int $clientId,
        int $natureId,
        int $mois,
        int $annee,
        float $montant,
        ?string $description = null
    ): Depense {
        $db = Database::getInstance();
        
        // Obtenir ou créer le compte de gestion
        $sql = "SELECT id FROM compte_gestion_mensuel WHERE client_id = ? AND mois = ? AND annee = ?";
        $compte = $db->fetchOne($sql, [$clientId, $mois, $annee]);
        
        if (!$compte) {
            $sql = "INSERT INTO compte_gestion_mensuel (client_id, mois, annee) VALUES (?, ?, ?)";
            $compteId = $db->insert($sql, [$clientId, $mois, $annee]);
        } else {
            $compteId = $compte['id'];
        }
        
        $depense = new self();
        $depense->setClientId($clientId)
                ->setCompteGestionId($compteId)
                ->setNatureId($natureId)
                ->setMois($mois)
                ->setAnnee($annee)
                ->setMontant($montant)
                ->setDescription($description)
                ->sauvegarder();
        
        return $depense;
    }

    // ========================================
    // GESTION DES NATURES DE DÉPENSES
    // ========================================

    /**
     * Obtenir toutes les natures de dépenses
     */
    public static function getNatures(): array
    {
        $db = Database::getInstance();
        
        $sql = "SELECT * FROM natures_depenses ORDER BY ordre_affichage";
        return $db->fetchAll($sql);
    }

    /**
     * Obtenir une nature par son code
     */
    public static function getNatureByCode(string $code): ?array
    {
        $db = Database::getInstance();
        
        $sql = "SELECT * FROM natures_depenses WHERE code = ?";
        return $db->fetchOne($sql, [$code]);
    }

    /**
     * Ajouter une nouvelle nature de dépense
     */
    public static function ajouterNature(string $code, string $libelle, bool $deductible = true): int
    {
        $db = Database::getInstance();
        
        // Obtenir le dernier ordre
        $sql = "SELECT MAX(ordre_affichage) as max_ordre FROM natures_depenses";
        $result = $db->fetchOne($sql);
        $ordre = ($result['max_ordre'] ?? 0) + 1;
        
        $sql = "INSERT INTO natures_depenses (code, libelle, deductible, ordre_affichage) VALUES (?, ?, ?, ?)";
        return $db->insert($sql, [$code, $libelle, $deductible ? 1 : 0, $ordre]);
    }

    /**
     * Modifier une nature de dépense
     */
    public static function modifierNature(int $id, string $libelle, bool $deductible): bool
    {
        $db = Database::getInstance();
        
        $sql = "UPDATE natures_depenses SET libelle = ?, deductible = ? WHERE id = ?";
        return $db->update($sql, [$libelle, $deductible ? 1 : 0, $id]) > 0;
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
            VALUES (?, ?, 'depenses', ?, ?)
        ";
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'localhost';
        
        $this->db->insert($sql, [$agentId, $action, $enregistrementId, $ip]);
    }

    // ========================================
    // CONVERSION EN TABLEAU
    // ========================================

    /**
     * Convertir la dépense en tableau
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->clientId,
            'compte_gestion_id' => $this->compteGestionId,
            'nature_id' => $this->natureId,
            'nature_code' => $this->getNatureCode(),
            'nature_libelle' => $this->getNatureLibelle(),
            'mois' => $this->mois,
            'annee' => $this->annee,
            'montant' => $this->montant,
            'description' => $this->description,
            'deductible' => $this->estDeductible(),
            'date_saisie' => $this->dateSaisie,
            'saisi_par' => $this->saisiPar
        ];
    }
}
