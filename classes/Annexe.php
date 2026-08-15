<?php
/**
 * ============================================
 * CLASSE ANNEXE
 * Gestion des justificatifs d'exonération
 * ============================================
 */

require_once __DIR__ . '/../config/database.php';

class Annexe
{
    // Attributs
    private ?int $id = null;
    private int $clientId;
    private string $typeAnnexe;      // A, B, C, D
    private string $typeImpot;
    private string $reference = '';
    private ?string $baseLegale = null;
    private string $dateDebut;
    private ?string $dateFin = null;
    private ?string $fichierPath = null;
    private string $statut = 'valide';
    private ?string $dateCreation = null;

    // Instance de Database
    private Database $db;

    // Types d'annexes
    private const TYPES_ANNEXE = [
        'A' => 'Exonération TVA',
        'B' => 'Taux réduit (5%)',
        'C' => 'Exonération impôts sur location',
        'D' => 'Aménagements salariaux'
    ];

    // Types d'impôts concernés
    private const TYPES_IMPOT = [
        'TVA' => 'TVA',
        'TVA_REDUIT' => 'TVA Taux réduit',
        'IRF' => 'Impôt sur le Revenu Foncier',
        'TVA_LOCATION' => 'TVA sur Location',
        'TF' => 'Taxe Foncière',
        'CF' => 'Contribution Forfaitaire',
        'ITS' => 'Impôt sur Traitements et Salaires',
        'TL' => 'Taxe de Logement',
        'CSS' => 'Contribution Secteur Spécifique',
        'RAS' => 'Retenue à la Source BIC/IS'
    ];

    // Statuts valides
    private const STATUTS_VALIDES = ['valide', 'expire', 'annule'];

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

    public function getTypeAnnexe(): string
    {
        return $this->typeAnnexe;
    }

    public function getTypeAnnexeLibelle(): string
    {
        return self::TYPES_ANNEXE[$this->typeAnnexe] ?? $this->typeAnnexe;
    }

    public function getTypeImpot(): string
    {
        return $this->typeImpot;
    }

    public function getTypeImpotLibelle(): string
    {
        return self::TYPES_IMPOT[$this->typeImpot] ?? $this->typeImpot;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getBaseLegale(): ?string
    {
        return $this->baseLegale;
    }

    public function getDateDebut(): string
    {
        return $this->dateDebut;
    }

    public function getDateFin(): ?string
    {
        return $this->dateFin;
    }

    public function getFichierPath(): ?string
    {
        return $this->fichierPath;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getStatutLibelle(): string
    {
        $libelles = [
            'valide' => 'Valide',
            'expire' => 'Expiré',
            'annule' => 'Annulé'
        ];
        return $libelles[$this->statut] ?? $this->statut;
    }

    public function getDateCreation(): ?string
    {
        return $this->dateCreation;
    }

    // ========================================
    // SETTERS
    // ========================================

    public function setClientId(int $clientId): self
    {
        $this->clientId = $clientId;
        return $this;
    }

    public function setTypeAnnexe(string $type): self
    {
        if (!array_key_exists($type, self::TYPES_ANNEXE)) {
            throw new InvalidArgumentException("Type d'annexe invalide. Valeurs acceptées: A, B, C, D");
        }
        $this->typeAnnexe = $type;
        return $this;
    }

    public function setTypeImpot(string $type): self
    {
        if (!array_key_exists($type, self::TYPES_IMPOT)) {
            throw new InvalidArgumentException("Type d'impôt invalide.");
        }
        $this->typeImpot = $type;
        return $this;
    }

    public function setReference(string $reference): self
    {
        $reference = trim($reference);
        if (empty($reference)) {
            throw new InvalidArgumentException("La référence est obligatoire.");
        }
        $this->reference = $reference;
        return $this;
    }

    public function setBaseLegale(?string $baseLegale): self
    {
        $this->baseLegale = $baseLegale ? trim($baseLegale) : null;
        return $this;
    }

    public function setDateDebut(string $dateDebut): self
    {
        if (!$this->validerDate($dateDebut)) {
            throw new InvalidArgumentException("Date de début invalide.");
        }
        $this->dateDebut = $dateDebut;
        return $this;
    }

    public function setDateFin(?string $dateFin): self
    {
        if ($dateFin && !$this->validerDate($dateFin)) {
            throw new InvalidArgumentException("Date de fin invalide.");
        }
        $this->dateFin = $dateFin;
        return $this;
    }

    public function setFichierPath(?string $path): self
    {
        $this->fichierPath = $path ? trim($path) : null;
        return $this;
    }

    public function setStatut(string $statut): self
    {
        if (!in_array($statut, self::STATUTS_VALIDES)) {
            throw new InvalidArgumentException("Statut invalide.");
        }
        $this->statut = $statut;
        return $this;
    }

    /**
     * Valider le format de date
     */
    private function validerDate(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    // ========================================
    // MÉTHODES CRUD
    // ========================================

    /**
     * Charger une annexe depuis la BD
     */
    public function charger(int $id): bool
    {
        $sql = "SELECT * FROM annexes WHERE id = ?";
        $data = $this->db->fetchOne($sql, [$id]);
        
        if ($data === null) {
            return false;
        }
        
        $this->hydrater($data);
        return true;
    }

    /**
     * Hydrater l'objet
     */
    private function hydrater(array $data): void
    {
        $this->id = (int) $data['id'];
        $this->clientId = (int) $data['client_id'];
        $this->typeAnnexe = $data['type_annexe'];
        $this->typeImpot = $data['type_impot'];
        $this->reference = $data['reference'];
        $this->baseLegale = $data['base_legale'];
        $this->dateDebut = $data['date_debut'];
        $this->dateFin = $data['date_fin'];
        $this->fichierPath = $data['fichier_path'];
        $this->statut = $data['statut'];
        $this->dateCreation = $data['date_creation'];
    }

    /**
     * Sauvegarder l'annexe
     */
    public function sauvegarder(): bool
    {
        if ($this->id === null) {
            return $this->inserer();
        }
        return $this->mettreAJour();
    }

    /**
     * Insérer une nouvelle annexe
     */
    private function inserer(): bool
    {
        $sql = "
            INSERT INTO annexes (
                client_id, type_annexe, type_impot, reference, base_legale,
                date_debut, date_fin, fichier_path, statut
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $this->id = $this->db->insert($sql, [
            $this->clientId,
            $this->typeAnnexe,
            $this->typeImpot,
            $this->reference,
            $this->baseLegale,
            $this->dateDebut,
            $this->dateFin,
            $this->fichierPath,
            $this->statut
        ]);
        
        $this->logAction('creation_annexe', $this->id);
        
        return $this->id > 0;
    }

    /**
     * Mettre à jour une annexe
     */
    private function mettreAJour(): bool
    {
        $sql = "
            UPDATE annexes 
            SET type_annexe = ?, type_impot = ?, reference = ?, base_legale = ?,
                date_debut = ?, date_fin = ?, fichier_path = ?, statut = ?
            WHERE id = ?
        ";
        
        $result = $this->db->update($sql, [
            $this->typeAnnexe,
            $this->typeImpot,
            $this->reference,
            $this->baseLegale,
            $this->dateDebut,
            $this->dateFin,
            $this->fichierPath,
            $this->statut,
            $this->id
        ]);
        
        $this->logAction('modification_annexe', $this->id);
        
        return $result > 0;
    }

    /**
     * Supprimer une annexe
     */
    public function supprimer(): bool
    {
        $sql = "DELETE FROM annexes WHERE id = ?";
        $result = $this->db->delete($sql, [$this->id]);
        
        $this->logAction('suppression_annexe', $this->id);
        
        return $result > 0;
    }

    /**
     * Annuler une annexe (soft delete)
     */
    public function annuler(): bool
    {
        $this->statut = 'annule';
        $this->logAction('annulation_annexe', $this->id);
        return $this->mettreAJour();
    }

    // ========================================
    // VÉRIFICATION DE VALIDITÉ
    // ========================================

    /**
     * Vérifier si l'annexe est valide à une date donnée
     */
    public function estValide(?string $date = null): bool
    {
        if ($this->statut !== 'valide') {
            return false;
        }

        $dateVerif = $date ?? date('Y-m-d');
        
        // Vérifier la date de début
        if ($this->dateDebut > $dateVerif) {
            return false;
        }
        
        // Vérifier la date de fin si elle existe
        if ($this->dateFin && $this->dateFin < $dateVerif) {
            return false;
        }
        
        return true;
    }

    /**
     * Vérifier si l'annexe est expirée
     */
    public function estExpiree(): bool
    {
        if ($this->dateFin === null) {
            return false;
        }
        
        return $this->dateFin < date('Y-m-d');
    }

    /**
     * Mettre à jour le statut si expiré
     */
    public function verifierExpiration(): bool
    {
        if ($this->statut === 'valide' && $this->estExpiree()) {
            $this->statut = 'expire';
            return $this->mettreAJour();
        }
        return false;
    }

    // ========================================
    // MÉTHODES STATIQUES
    // ========================================

    /**
     * Obtenir les annexes d'un client
     */
    public static function getByClient(int $clientId, bool $validesUniquement = false): array
    {
        $db = Database::getInstance();
        
        $sql = "SELECT * FROM annexes WHERE client_id = ?";
        if ($validesUniquement) {
            $sql .= " AND statut = 'valide' AND (date_fin IS NULL OR date_fin >= ?)";
        }
        $sql .= " ORDER BY type_annexe, date_debut DESC";
        $params = [$clientId];
        if ($validesUniquement) {
            $params[] = date('Y-m-d');
        }
        return $db->fetchAll($sql, $params);
    }

    /**
     * Obtenir les annexes par type
     */
    public static function getByClientEtType(int $clientId, string $typeAnnexe): array
    {
        $db = Database::getInstance();
        
        $sql = "
            SELECT * FROM annexes 
            WHERE client_id = ? AND type_annexe = ?
            ORDER BY date_debut DESC
        ";
        
        return $db->fetchAll($sql, [$clientId, $typeAnnexe]);
    }

    /**
     * Obtenir les annexes valides pour un impôt
     */
    public static function getAnnexeValidePourImpot(int $clientId, string $typeImpot, ?string $date = null): ?array
    {
        $db = Database::getInstance();
        $dateVerif = $date ?? date('Y-m-d');
        
        $sql = "
            SELECT * FROM annexes 
            WHERE client_id = ? 
                AND type_impot = ? 
                AND statut = 'valide'
                AND date_debut <= ?
                AND (date_fin IS NULL OR date_fin >= ?)
            ORDER BY date_debut DESC
            LIMIT 1
        ";
        
        return $db->fetchOne($sql, [$clientId, $typeImpot, $dateVerif, $dateVerif]);
    }

    /**
     * Vérifier si une exonération est justifiée
     */
    public static function exonerationJustifiee(int $clientId, string $typeImpot, ?string $date = null): bool
    {
        $annexe = self::getAnnexeValidePourImpot($clientId, $typeImpot, $date);
        return $annexe !== null;
    }

    /**
     * Obtenir les types d'annexes
     */
    public static function getTypesAnnexe(): array
    {
        return self::TYPES_ANNEXE;
    }

    /**
     * Obtenir les types d'impôts
     */
    public static function getTypesImpot(): array
    {
        return self::TYPES_IMPOT;
    }

    /**
     * Obtenir les annexes qui vont expirer bientôt
     */
    public static function getAnnexesExpirantBientot(int $joursAvant = 30): array
    {
        $db = Database::getInstance();
        $dateLimite = date('Y-m-d', strtotime("+{$joursAvant} days"));
        
        $sql = "
            SELECT a.*, c.nom as client_nom
            FROM annexes a
            JOIN clients c ON a.client_id = c.id
            WHERE a.statut = 'valide'
                AND a.date_fin IS NOT NULL
                AND a.date_fin <= ?
                AND a.date_fin >= ?
            ORDER BY a.date_fin
        ";
        
        return $db->fetchAll($sql, [$dateLimite, date('Y-m-d')]);
    }

    /**
     * Mettre à jour les statuts des annexes expirées
     */
    public static function mettreAJourStatutsExpires(): int
    {
        $db = Database::getInstance();
        
        $sql = "
            UPDATE annexes 
            SET statut = 'expire'
            WHERE statut = 'valide' 
                AND date_fin IS NOT NULL 
                AND date_fin < ?
        ";
        
        return $db->update($sql, [date('Y-m-d')]);
    }

    // ========================================
    // GESTION DES FICHIERS
    // ========================================

    /**
     * Télécharger un fichier justificatif
     */
    public function telechargerFichier(array $fichier): bool
    {
        $dossierUpload = APP_ROOT . '/uploads/annexes/' . $this->clientId;
        
        // Créer le dossier si nécessaire
        if (!is_dir($dossierUpload)) {
            mkdir($dossierUpload, 0755, true);
        }
        
        // Vérifier le type de fichier
        $extensionsAutorisees = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
        $extension = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));
        
        if (!in_array($extension, $extensionsAutorisees)) {
            throw new Exception("Type de fichier non autorisé.");
        }
        
        // Générer un nom unique
        $nomFichier = 'annexe_' . $this->typeAnnexe . '_' . date('YmdHis') . '.' . $extension;
        $cheminComplet = $dossierUpload . '/' . $nomFichier;
        
        // Déplacer le fichier
        if (move_uploaded_file($fichier['tmp_name'], $cheminComplet)) {
            $this->fichierPath = 'uploads/annexes/' . $this->clientId . '/' . $nomFichier;
            return $this->sauvegarder();
        }
        
        throw new Exception("Erreur lors du téléchargement du fichier.");
    }

    /**
     * Supprimer le fichier associé
     */
    public function supprimerFichier(): bool
    {
        if ($this->fichierPath) {
            $cheminComplet = APP_ROOT . '/' . $this->fichierPath;
            if (file_exists($cheminComplet)) {
                unlink($cheminComplet);
            }
            $this->fichierPath = null;
            return $this->sauvegarder();
        }
        return false;
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
            VALUES (?, ?, 'annexes', ?, ?)
        ";

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'localhost';

        $this->db->insert($sql, [$agentId, $action, $enregistrementId, $ip]);
    }

    // ========================================
    // CONVERSION EN TABLEAU
    // ========================================

    /**
     * Convertir en tableau
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->clientId,
            'type_annexe' => $this->typeAnnexe,
            'type_annexe_libelle' => $this->getTypeAnnexeLibelle(),
            'type_impot' => $this->typeImpot,
            'type_impot_libelle' => $this->getTypeImpotLibelle(),
            'reference' => $this->reference,
            'base_legale' => $this->baseLegale,
            'date_debut' => $this->dateDebut,
            'date_fin' => $this->dateFin,
            'fichier_path' => $this->fichierPath,
            'statut' => $this->statut,
            'statut_libelle' => $this->getStatutLibelle(),
            'est_valide' => $this->estValide(),
            'est_expiree' => $this->estExpiree(),
            'date_creation' => $this->dateCreation
        ];
    }
}
