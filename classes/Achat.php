<?php
/**
 * ============================================
 * CLASSE ACHAT
 * Gestion des achats fournisseurs
 * ============================================
 */

require_once __DIR__ . '/../config/database.php';

class Achat
{
    // Attributs
    private ?int $id = null;
    private int $clientId;
    private int $fournisseurId;
    private int $compteGestionId;
    private int $mois;
    private int $annee;
    private float $montantHT = 0;
    private float $montantTVA = 0;
    private float $montantTTC = 0;
    private string $typeDocument = 'releve';
    private ?string $referenceDocument = null;
    private ?string $dateDocument = null;
    private bool $tvaDeductible = true;
    private bool $exclureCA = false;
    private ?string $dateSaisie = null;
    private ?int $saisiPar = null;

    // Instance de Database
    private Database $db;

    // Types de documents valides
    private const TYPES_DOCUMENT = ['releve', 'facture'];

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

    public function getFournisseurId(): int
    {
        return $this->fournisseurId;
    }

    public function getCompteGestionId(): int
    {
        return $this->compteGestionId;
    }

    public function getMois(): int
    {
        return $this->mois;
    }

    public function getAnnee(): int
    {
        return $this->annee;
    }

    public function getMontantHT(): float
    {
        return $this->montantHT;
    }

    public function getMontantTVA(): float
    {
        return $this->montantTVA;
    }

    public function getMontantTTC(): float
    {
        return $this->montantTTC;
    }

    public function getTypeDocument(): string
    {
        return $this->typeDocument;
    }

    public function getTypeDocumentLibelle(): string
    {
        return $this->typeDocument === 'releve' ? 'Relevé fournisseur' : 'Facture';
    }

    public function getReferenceDocument(): ?string
    {
        return $this->referenceDocument;
    }

    public function getDateDocument(): ?string
    {
        return $this->dateDocument;
    }

    public function isTvaDeductible(): bool
    {
        return $this->tvaDeductible;
    }

    public function getExclureCA(): bool
    {
        return $this->exclureCA;
    }

    public function getDateSaisie(): ?string
    {
        return $this->dateSaisie;
    }

    public function getSaisiPar(): ?int
    {
        return $this->saisiPar;
    }

    // ========================================
    // SETTERS
    // ========================================

    public function setClientId(int $clientId): self
    {
        $this->clientId = $clientId;
        return $this;
    }

    public function setFournisseurId(int $fournisseurId): self
    {
        $this->fournisseurId = $fournisseurId;
        return $this;
    }

    public function setCompteGestionId(int $compteGestionId): self
    {
        $this->compteGestionId = $compteGestionId;
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

    public function setMontantHT(float $montant): self
    {
        if ($montant < 0) {
            throw new InvalidArgumentException("Le montant HT ne peut pas être négatif.");
        }
        $this->montantHT = round($montant, 2);
        return $this;
    }

    public function setMontantTVA(float $montant): self
    {
        if ($montant < 0) {
            throw new InvalidArgumentException("Le montant TVA ne peut pas être négatif.");
        }
        $this->montantTVA = round($montant, 2);
        return $this;
    }

    public function setMontantTTC(float $montant): self
    {
        if ($montant < 0) {
            throw new InvalidArgumentException("Le montant TTC ne peut pas être négatif.");
        }
        $this->montantTTC = round($montant, 2);
        return $this;
    }

    public function setTypeDocument(string $type): self
    {
        if (!in_array($type, self::TYPES_DOCUMENT)) {
            throw new InvalidArgumentException("Type de document invalide.");
        }
        $this->typeDocument = $type;
        return $this;
    }

    public function setReferenceDocument(?string $reference): self
    {
        $this->referenceDocument = $reference ? trim($reference) : null;
        return $this;
    }

    public function setDateDocument(?string $date): self
    {
        $this->dateDocument = $date;
        return $this;
    }

    public function setTvaDeductible(bool $deductible): self
    {
        $this->tvaDeductible = $deductible;
        return $this;
    }

    public function setExclureCA(bool $exclureCA): self
    {
        $this->exclureCA = $exclureCA;
        return $this;
    }

    public function setSaisiPar(?int $agentId): self
    {
        $this->saisiPar = $agentId;
        return $this;
    }

    // ========================================
    // CALCULS AUTOMATIQUES
    // ========================================

    /**
     * Calculer le TTC à partir du HT et TVA
     */
    public function calculerTTC(): self
    {
        $this->montantTTC = round($this->montantHT + $this->montantTVA, 2);
        return $this;
    }

    /**
     * Calculer la TVA à partir du HT et d'un taux
     */
    public function calculerTVA(float $taux = 18.00): self
    {
        $this->montantTVA = round($this->montantHT * $taux / 100, 2);
        $this->calculerTTC();
        return $this;
    }

    /**
     * Calculer le HT à partir du TTC et d'un taux
     */
    public function calculerHTDepuisTTC(float $taux = 18.00): self
    {
        $this->montantHT = round($this->montantTTC / (1 + $taux / 100), 2);
        $this->montantTVA = round($this->montantTTC - $this->montantHT, 2);
        return $this;
    }

    // ========================================
    // MÉTHODES CRUD
    // ========================================

    /**
     * Charger un achat depuis la BD
     */
    public function charger(int $id): bool
    {
        $sql = "SELECT * FROM achats WHERE id = ?";
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
        $this->fournisseurId = (int) $data['fournisseur_id'];
        $this->compteGestionId = (int) $data['compte_gestion_id'];
        $this->mois = (int) $data['mois'];
        $this->annee = (int) $data['annee'];
        $this->montantHT = (float) $data['montant_ht'];
        $this->montantTVA = (float) $data['montant_tva'];
        $this->montantTTC = (float) $data['montant_ttc'];
        $this->typeDocument = $data['type_document'];
        $this->referenceDocument = $data['reference_document'];
        $this->dateDocument = $data['date_document'];
        $this->tvaDeductible = (bool) $data['tva_deductible'];
        $this->exclureCA = (bool) ($data['exclure_ca'] ?? false);
        $this->dateSaisie = $data['date_saisie'];
        $this->saisiPar = $data['saisi_par'] ? (int) $data['saisi_par'] : null;
    }

    /**
     * Sauvegarder l'achat (insert ou update)
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
     * Insérer un nouvel achat
     */
    private function inserer(): bool
    {
        // Récupérer l'agent connecté
        $agentId = $_SESSION['agent_id'] ?? null;
        
        $sql = "
            INSERT INTO achats (
                client_id, fournisseur_id, compte_gestion_id, mois, annee,
                montant_ht, montant_tva, montant_ttc, type_document,
                reference_document, date_document, tva_deductible, exclure_ca, saisi_par
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $this->id = $this->db->insert($sql, [
            $this->clientId,
            $this->fournisseurId,
            $this->compteGestionId,
            $this->mois,
            $this->annee,
            $this->montantHT,
            $this->montantTVA,
            $this->montantTTC,
            $this->typeDocument,
            $this->referenceDocument,
            $this->dateDocument,
            $this->tvaDeductible ? 1 : 0,
            $this->exclureCA ? 1 : 0,
            $agentId
        ]);
        
        $this->logAction('creation_achat', $this->id);
        
        return $this->id > 0;
    }

    /**
     * Mettre à jour un achat existant
     */
    private function mettreAJour(): bool
    {
        $sql = "
            UPDATE achats 
            SET fournisseur_id = ?, montant_ht = ?, montant_tva = ?, montant_ttc = ?,
                type_document = ?, reference_document = ?, date_document = ?, tva_deductible = ?,
                exclure_ca = ?
            WHERE id = ?
        ";
        
        $result = $this->db->update($sql, [
            $this->fournisseurId,
            $this->montantHT,
            $this->montantTVA,
            $this->montantTTC,
            $this->typeDocument,
            $this->referenceDocument,
            $this->dateDocument,
            $this->tvaDeductible ? 1 : 0,
            $this->exclureCA ? 1 : 0,
            $this->id
        ]);
        
        $this->logAction('modification_achat', $this->id);
        
        return $result > 0;
    }

    /**
     * Supprimer un achat
     */
    public function supprimer(): bool
    {
        // Vérifier que le mois n'est pas verrouillé
        if ($this->moisEstVerrouille()) {
            throw new Exception("Impossible de supprimer un achat d'un mois verrouillé.");
        }
        
        $sql = "DELETE FROM achats WHERE id = ?";
        $result = $this->db->delete($sql, [$this->id]);
        
        $this->logAction('suppression_achat', $this->id);
        
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
     * Vérifier si la TVA est déductible selon les règles
     */
    public function estDeductibleTVA(): bool
    {
        if (!$this->tvaDeductible) {
            return false;
        }
        
        // Vérifier si le client est soumis à la TVA
        $sql = "SELECT type_tva FROM parametres_fiscaux WHERE client_id = ?";
        $params = $this->db->fetchOne($sql, [$this->clientId]);
        
        if (!$params || $params['type_tva'] === 'exonere_total') {
            return false;
        }
        
        return true;
    }

    // ========================================
    // MÉTHODES STATIQUES
    // ========================================

    /**
     * Obtenir les achats d'un client pour un mois
     */
    public static function getByClientMois(int $clientId, int $mois, int $annee): array
    {
        $db = Database::getInstance();
        
        $sql = "
            SELECT a.*, f.nom as fournisseur_nom
            FROM achats a
            JOIN fournisseurs f ON a.fournisseur_id = f.id
            WHERE a.client_id = ? AND a.mois = ? AND a.annee = ?
            ORDER BY f.nom, a.date_saisie
        ";
        
        return $db->fetchAll($sql, [$clientId, $mois, $annee]);
    }

    /**
     * Obtenir les achats groupés par fournisseur
     */
    public static function getByClientMoisGroupeParFournisseur(int $clientId, int $mois, int $annee): array
    {
        $db = Database::getInstance();
        
        $sql = "
            SELECT 
                f.id as fournisseur_id,
                f.nom as fournisseur_nom,
                COUNT(a.id) as nb_achats,
                SUM(a.montant_ht) as total_ht,
                SUM(a.montant_tva) as total_tva,
                SUM(a.montant_ttc) as total_ttc,
                SUM(CASE WHEN a.tva_deductible = 1 THEN a.montant_tva ELSE 0 END) as tva_deductible
            FROM fournisseurs f
            JOIN achats a ON f.id = a.fournisseur_id
            WHERE a.client_id = ? AND a.mois = ? AND a.annee = ?
            GROUP BY f.id, f.nom
            ORDER BY f.nom
        ";
        
        return $db->fetchAll($sql, [$clientId, $mois, $annee]);
    }

    /**
     * Obtenir les totaux pour un client/mois
     */
    public static function getTotaux(int $clientId, int $mois, int $annee): array
    {
        $db = Database::getInstance();
        
        $sql = "
            SELECT 
                COALESCE(SUM(montant_ht), 0) as total_ht,
                COALESCE(SUM(CASE WHEN exclure_ca = 0 THEN montant_ht ELSE 0 END), 0) as total_ht_ca,
                COALESCE(SUM(montant_tva), 0) as total_tva,
                COALESCE(SUM(montant_ttc), 0) as total_ttc,
                COALESCE(SUM(CASE WHEN tva_deductible = 1 THEN montant_tva ELSE 0 END), 0) as tva_deductible,
                COUNT(*) as nb_achats
            FROM achats
            WHERE client_id = ? AND mois = ? AND annee = ?
        ";
        
        $result = $db->fetchOne($sql, [$clientId, $mois, $annee]);
        
        return [
            'total_ht' => (float) $result['total_ht'],
            'total_ht_ca' => (float) $result['total_ht_ca'],
            'total_tva' => (float) $result['total_tva'],
            'total_ttc' => (float) $result['total_ttc'],
            'tva_deductible' => (float) $result['tva_deductible'],
            'nb_achats' => (int) $result['nb_achats']
        ];
    }

    /**
     * Obtenir l'historique des achats d'un client
     */
    public static function getHistorique(int $clientId, int $annee): array
    {
        $db = Database::getInstance();
        
        $sql = "
            SELECT 
                mois,
                COUNT(*) as nb_achats,
                SUM(montant_ht) as total_ht,
                SUM(montant_tva) as total_tva,
                SUM(montant_ttc) as total_ttc
            FROM achats
            WHERE client_id = ? AND annee = ?
            GROUP BY mois
            ORDER BY mois
        ";
        
        return $db->fetchAll($sql, [$clientId, $annee]);
    }

    /**
     * Créer un achat rapidement
     */
    public static function creer(
        int $clientId,
        int $fournisseurId,
        int $mois,
        int $annee,
        float $montantHT,
        float $montantTVA,
        string $typeDocument = 'releve',
        ?string $reference = null
    ): Achat {
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
        
        $achat = new self();
        $achat->setClientId($clientId)
              ->setFournisseurId($fournisseurId)
              ->setCompteGestionId($compteId)
              ->setMois($mois)
              ->setAnnee($annee)
              ->setMontantHT($montantHT)
              ->setMontantTVA($montantTVA)
              ->calculerTTC()
              ->setTypeDocument($typeDocument)
              ->setReferenceDocument($reference)
              ->sauvegarder();
        
        return $achat;
    }

    /**
     * Obtenir les types de documents
     */
    public static function getTypesDocument(): array
    {
        return [
            'releve' => 'Relevé fournisseur',
            'facture' => 'Facture'
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
            VALUES (?, ?, 'achats', ?, ?)
        ";
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'localhost';
        
        $this->db->insert($sql, [$agentId, $action, $enregistrementId, $ip]);
    }

    // ========================================
    // RELATIONS
    // ========================================

    /**
     * Obtenir le fournisseur
     */
    public function getFournisseur(): ?array
    {
        $sql = "SELECT * FROM fournisseurs WHERE id = ?";
        return $this->db->fetchOne($sql, [$this->fournisseurId]);
    }

    /**
     * Obtenir le client
     */
    public function getClient(): ?array
    {
        $sql = "SELECT * FROM clients WHERE id = ?";
        return $this->db->fetchOne($sql, [$this->clientId]);
    }

    // ========================================
    // CONVERSION EN TABLEAU
    // ========================================

    /**
     * Convertir l'achat en tableau
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->clientId,
            'fournisseur_id' => $this->fournisseurId,
            'compte_gestion_id' => $this->compteGestionId,
            'mois' => $this->mois,
            'annee' => $this->annee,
            'montant_ht' => $this->montantHT,
            'montant_tva' => $this->montantTVA,
            'montant_ttc' => $this->montantTTC,
            'type_document' => $this->typeDocument,
            'type_document_libelle' => $this->getTypeDocumentLibelle(),
            'reference_document' => $this->referenceDocument,
            'date_document' => $this->dateDocument,
            'tva_deductible' => $this->tvaDeductible,
            'date_saisie' => $this->dateSaisie,
            'saisi_par' => $this->saisiPar
        ];
    }
}
