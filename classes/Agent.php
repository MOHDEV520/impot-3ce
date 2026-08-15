<?php
/**
 * ============================================
 * CLASSE AGENT
 * Gestion de l'authentification et du portefeuille clients
 * ============================================
 */

require_once __DIR__ . '/../config/database.php';

class Agent
{
    // Attributs
    private ?int $id = null;
    private string $nom = '';
    private string $prenom = '';
    private string $email = '';
    private string $motDePasse = '';
    private string $role = 'agent';
    private string $statut = 'actif';
    private ?string $dateCreation = null;
    private ?string $derniereConnexion = null;

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

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function getNomComplet(): string
    {
        return $this->prenom . ' ' . $this->nom;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getDateCreation(): ?string
    {
        return $this->dateCreation;
    }

    public function getDerniereConnexion(): ?string
    {
        return $this->derniereConnexion;
    }

    // ========================================
    // SETTERS
    // ========================================

    public function setNom(string $nom): self
    {
        $this->nom = trim($nom);
        return $this;
    }

    public function setPrenom(string $prenom): self
    {
        $this->prenom = trim($prenom);
        return $this;
    }

    public function setEmail(string $email): self
    {
        $email = trim(strtolower($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Email invalide.");
        }
        $this->email = $email;
        return $this;
    }

    public function setMotDePasse(string $motDePasse): self
    {
        if (strlen($motDePasse) < 6) {
            throw new InvalidArgumentException("Le mot de passe doit contenir au moins 6 caractères.");
        }
        $this->motDePasse = password_hash($motDePasse, PASSWORD_DEFAULT);
        return $this;
    }

    public function setRole(string $role): self
    {
        $rolesValides = ['agent', 'admin', 'superviseur'];
        if (!in_array($role, $rolesValides)) {
            throw new InvalidArgumentException("Rôle invalide.");
        }
        $this->role = $role;
        return $this;
    }

    public function setStatut(string $statut): self
    {
        $statutsValides = ['actif', 'inactif', 'suspendu'];
        if (!in_array($statut, $statutsValides)) {
            throw new InvalidArgumentException("Statut invalide.");
        }
        $this->statut = $statut;
        return $this;
    }

    // ========================================
    // MÉTHODES D'AUTHENTIFICATION
    // ========================================

    /**
     * Connexion d'un agent
     */
    public static function seConnecter(string $email, string $motDePasse): ?Agent
    {
        $db = Database::getInstance();
        $email = strtolower(trim($email));
        
        $sql = "SELECT * FROM agents WHERE LOWER(email) = ? AND statut = 'actif'";
        $data = $db->fetchOne($sql, [$email]);
        
        if ($data === null) {
            return null;
        }
        
        if (!password_verify($motDePasse, $data['mot_de_passe'])) {
            return null;
        }
        
        // Mettre à jour la dernière connexion
        $db->update(
            "UPDATE agents SET derniere_connexion = CURRENT_TIMESTAMP WHERE id = ?",
            [$data['id']]
        );
        
        // Créer et retourner l'instance
        $agent = new self();
        $agent->hydrater($data);
        
        // Démarrer la session
        $agent->demarrerSession();
        
        // Logger la connexion
        $agent->logAction('connexion', 'agents', $agent->id);
        
        return $agent;
    }

    /**
     * Déconnexion
     */
    public function seDeconnecter(): void
    {
        $this->logAction('deconnexion', 'agents', $this->id);
        
        // Détruire la session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
    }

    /**
     * Démarrer la session pour l'agent
     */
    private function demarrerSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Prevent session fixation after successful authentication.
        session_regenerate_id(true);
        
        $_SESSION['agent_id'] = $this->id;
        $_SESSION['agent_nom'] = $this->getNomComplet();
        $_SESSION['agent_email'] = $this->email;
        $_SESSION['agent_role'] = $this->role;
        $_SESSION['connecte'] = true;
        $_SESSION['derniere_activite'] = time();
    }

    /**
     * Return (and lazily create) CSRF token for the current session.
     */
    public static function getCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Validate CSRF token submitted by forms.
     */
    public static function verifierCsrfToken(?string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (!is_string($sessionToken) || $sessionToken === '' || $token === null) {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    /**
     * Vérifier si un agent est connecté
     */
    public static function estConnecte(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        return isset($_SESSION['connecte']) && $_SESSION['connecte'] === true;
    }

    /**
     * Obtenir l'agent connecté
     */
    public static function getAgentConnecte(): ?Agent
    {
        if (!self::estConnecte()) {
            return null;
        }
        
        return new self($_SESSION['agent_id']);
    }

    /**
     * Vérifier le timeout de session (30 min)
     */
    public static function verifierTimeout(int $duree = 1800): bool
    {
        if (!self::estConnecte()) {
            return false;
        }
        
        if (time() - $_SESSION['derniere_activite'] > $duree) {
            // Session expirée
            $agent = self::getAgentConnecte();
            if ($agent) {
                $agent->seDeconnecter();
            }
            return false;
        }
        
        // Mettre à jour l'activité
        $_SESSION['derniere_activite'] = time();
        return true;
    }

    // ========================================
    // MÉTHODES DE GESTION DES CLIENTS
    // ========================================

    /**
     * Obtenir les clients de l'agent
     */
    public function getClients(): array
    {
        $sql = "SELECT * FROM clients WHERE agent_id = ? AND statut = 'actif' ORDER BY nom";
        return $this->db->fetchAll($sql, [$this->id]);
    }

    /**
     * Compter les clients de l'agent
     */
    public function getNombreClients(): int
    {
        $sql = "SELECT COUNT(*) as total FROM clients WHERE agent_id = ? AND statut = 'actif'";
        $result = $this->db->fetchOne($sql, [$this->id]);
        return (int) $result['total'];
    }

    /**
     * Vérifier si l'agent a accès à un client
     */
    public function aAccesClient(int $clientId): bool
    {
        // Les admins et superviseurs ont accès à tous les clients
        if (in_array($this->role, ['admin', 'superviseur'])) {
            return true;
        }
        
        $sql = "SELECT id FROM clients WHERE id = ? AND agent_id = ?";
        $result = $this->db->fetchOne($sql, [$clientId, $this->id]);
        
        return $result !== null;
    }

    /**
     * Obtenir les clients avec leur état mensuel
     */
    public function getClientsAvecEtat(int $mois, int $annee): array
    {
        $sql = "
            SELECT 
                c.*,
                cgm.statut as etat_mois,
                cgm.ca_global,
                COALESCE(im.total_impots, 0) as total_impots
            FROM clients c
            LEFT JOIN compte_gestion_mensuel cgm 
                ON c.id = cgm.client_id AND cgm.mois = ? AND cgm.annee = ?
            LEFT JOIN impots_mensuels im 
                ON c.id = im.client_id AND im.mois = ? AND im.annee = ?
            WHERE c.agent_id = ? AND c.statut = 'actif'
            ORDER BY c.nom
        ";
        
        return $this->db->fetchAll($sql, [$mois, $annee, $mois, $annee, $this->id]);
    }

    // ========================================
    // MÉTHODES CRUD
    // ========================================

    /**
     * Charger un agent depuis la BD
     */
    public function charger(int $id): bool
    {
        $sql = "SELECT * FROM agents WHERE id = ?";
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
        $this->prenom = $data['prenom'];
        $this->email = $data['email'];
        $this->motDePasse = $data['mot_de_passe'];
        $this->role = $data['role'];
        $this->statut = $data['statut'];
        $this->dateCreation = $data['date_creation'];
        $this->derniereConnexion = $data['derniere_connexion'];
    }

    /**
     * Sauvegarder l'agent (insert ou update)
     */
    public function sauvegarder(): bool
    {
        if ($this->id === null) {
            return $this->inserer();
        }
        return $this->mettreAJour();
    }

    /**
     * Insérer un nouvel agent
     */
    private function inserer(): bool
    {
        // Vérifier que l'email n'existe pas
        if ($this->emailExiste($this->email)) {
            throw new Exception("Cet email est déjà utilisé.");
        }
        
        $sql = "
            INSERT INTO agents (nom, prenom, email, mot_de_passe, role, statut)
            VALUES (?, ?, ?, ?, ?, ?)
        ";
        
        $this->id = $this->db->insert($sql, [
            $this->nom,
            $this->prenom,
            $this->email,
            $this->motDePasse,
            $this->role,
            $this->statut
        ]);
        
        $this->logAction('creation_agent', 'agents', $this->id);
        
        return $this->id > 0;
    }

    /**
     * Mettre à jour un agent existant
     */
    private function mettreAJour(): bool
    {
        $sql = "
            UPDATE agents 
            SET nom = ?, prenom = ?, email = ?, role = ?, statut = ?
            WHERE id = ?
        ";
        
        $result = $this->db->update($sql, [
            $this->nom,
            $this->prenom,
            $this->email,
            $this->role,
            $this->statut,
            $this->id
        ]);
        
        $this->logAction('modification_agent', 'agents', $this->id);
        
        return $result > 0;
    }

    /**
     * Changer le mot de passe
     */
    public function changerMotDePasse(string $nouveauMotDePasse): bool
    {
        $this->setMotDePasse($nouveauMotDePasse);
        
        $sql = "UPDATE agents SET mot_de_passe = ? WHERE id = ?";
        $result = $this->db->update($sql, [$this->motDePasse, $this->id]);
        
        $this->logAction('changement_mot_de_passe', 'agents', $this->id);
        
        return $result > 0;
    }

    /**
     * Vérifier si un email existe déjà
     */
    private function emailExiste(string $email, ?int $excludeId = null): bool
    {
        $sql = "SELECT id FROM agents WHERE email = ?";
        $params = [$email];
        
        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        
        return $this->db->fetchOne($sql, $params) !== null;
    }

    /**
     * Supprimer un agent (soft delete)
     */
    public function supprimer(): bool
    {
        // Vérifier qu'il n'a pas de clients
        if ($this->getNombreClients() > 0) {
            throw new Exception("Impossible de supprimer un agent ayant des clients.");
        }
        
        $this->statut = 'inactif';
        return $this->mettreAJour();
    }

    // ========================================
    // MÉTHODES STATIQUES
    // ========================================

    /**
     * Obtenir tous les agents
     */
    public static function getAll(bool $actifsUniquement = true): array
    {
        $db = Database::getInstance();
        
        $sql = "SELECT * FROM agents";
        if ($actifsUniquement) {
            $sql .= " WHERE statut = 'actif'";
        }
        $sql .= " ORDER BY nom, prenom";
        
        return $db->fetchAll($sql);
    }

    /**
     * Trouver un agent par email
     */
    public static function findByEmail(string $email): ?Agent
    {
        $db = Database::getInstance();
        
        $sql = "SELECT id FROM agents WHERE email = ?";
        $result = $db->fetchOne($sql, [$email]);
        
        if ($result === null) {
            return null;
        }
        
        return new self((int) $result['id']);
    }

    // ========================================
    // VÉRIFICATION DES PERMISSIONS
    // ========================================

    /**
     * Vérifier si l'agent est admin
     */
    public function estAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Vérifier si l'agent est superviseur
     */
    public function estSuperviseur(): bool
    {
        return $this->role === 'superviseur';
    }

    /**
     * Vérifier si l'agent peut gérer les agents
     */
    public function peutGererAgents(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Vérifier si l'agent peut voir tous les clients
     */
    public function peutVoirTousClients(): bool
    {
        return in_array($this->role, ['admin', 'superviseur']);
    }

    // ========================================
    // LOGGING
    // ========================================

    /**
     * Logger une action
     */
    private function logAction(string $action, string $table, ?int $enregistrementId = null, ?string $details = null): void
    {
        $sql = "
            INSERT INTO logs (agent_id, action, table_concernee, enregistrement_id, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ";
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'localhost';
        
        $this->db->insert($sql, [
            $this->id,
            $action,
            $table,
            $enregistrementId,
            $details,
            $ip
        ]);
    }

    // ========================================
    // CONVERSION EN TABLEAU
    // ========================================

    /**
     * Convertir l'agent en tableau (sans mot de passe)
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'nom_complet' => $this->getNomComplet(),
            'email' => $this->email,
            'role' => $this->role,
            'statut' => $this->statut,
            'date_creation' => $this->dateCreation,
            'derniere_connexion' => $this->derniereConnexion,
            'nombre_clients' => $this->getNombreClients()
        ];
    }
}
