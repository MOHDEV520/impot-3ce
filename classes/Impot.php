<?php
/**
 * ============================================
 * CLASSES IMPÔTS
 * Classe abstraite + sous-classes spécifiques
 * ============================================
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Classe abstraite Impot
 * Définit le comportement commun des impôts
 */
abstract class Impot
{
    protected int $clientId;
    protected int $mois;
    protected int $annee;
    protected float $base = 0;
    protected float $taux = 0;
    protected float $montant = 0;
    protected Database $db;

    /**
     * Constructeur
     */
    public function __construct(int $clientId, int $mois, int $annee)
    {
        $this->db = Database::getInstance();
        $this->clientId = $clientId;
        $this->mois = $mois;
        $this->annee = $annee;
    }

    /**
     * Calculer l'impôt (à implémenter)
     */
    abstract public function calculer(): float;

    /**
     * Obtenir le nom de l'impôt
     */
    abstract public function getNom(): string;

    /**
     * Obtenir le code de l'impôt
     */
    abstract public function getCode(): string;

    // Getters communs
    public function getBase(): float
    {
        return $this->base;
    }

    public function getTaux(): float
    {
        return $this->taux;
    }

    public function getMontant(): float
    {
        return $this->montant;
    }

    /**
     * Obtenir les paramètres fiscaux du client
     */
    protected function getParametresFiscaux(): ?array
    {
        $sql = "SELECT * FROM parametres_fiscaux WHERE client_id = ?";
        return $this->db->fetchOne($sql, [$this->clientId]);
    }

    /**
     * Obtenir le compte de gestion mensuel
     */
    protected function getCompteGestion(): ?array
    {
        $sql = "SELECT * FROM compte_gestion_mensuel WHERE client_id = ? AND mois = ? AND annee = ?";
        return $this->db->fetchOne($sql, [$this->clientId, $this->mois, $this->annee]);
    }

    /**
     * Calcule la date limite de déclaration pour un mois donné
     * La date limite est le 15 du mois suivant.
     * Si le 15 tombe un weekend, c'est reporté au lundi suivant.
     * 
     * @param int $mois Mois concerné (1-12)
     * @param int $annee Année concernée
     * @return string Date au format 'Y-m-d'
     */
    public static function calculerDateLimite(int $mois, int $annee): string
    {
        // Mois et année de la déclaration (mois suivant)
        $mDecl = $mois + 1;
        $aDecl = $annee;
        if ($mDecl > 12) {
            $mDecl = 1;
            $aDecl++;
        }

        // Date du 15 du mois suivant
        $dateStr = sprintf('%04d-%02d-15', $aDecl, $mDecl);
        $timestamp = strtotime($dateStr);
        $jourSemaine = (int) date('N', $timestamp); // 1 (Lu) à 7 (Di)

        // Si samedi (6), report au lundi (+2 jours)
        if ($jourSemaine === 6) {
            $timestamp = strtotime('+2 days', $timestamp);
        }
        // Si dimanche (7), report au lundi (+1 jour)
        elseif ($jourSemaine === 7) {
            $timestamp = strtotime('+1 day', $timestamp);
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * Retourne la date d'échéance formatée en français
     * 
     * @param int $mois
     * @param int $annee
     * @return string
     */
    public static function getEcheanceFormatee(int $mois, int $annee): string
    {
        $dateLimite = self::calculerDateLimite($mois, $annee);
        $timestamp = strtotime($dateLimite);
        
        $moisNoms = [
            '01' => 'janvier', '02' => 'février', '03' => 'mars', '04' => 'avril',
            '05' => 'mai', '06' => 'juin', '07' => 'juillet', '08' => 'août',
            '09' => 'septembre', '10' => 'octobre', '11' => 'novembre', '12' => 'décembre'
        ];
        
        $j = date('d', $timestamp);
        $m = $moisNoms[date('m', $timestamp)];
        $a = date('Y', $timestamp);
        
        return "$j $m $a";
    }

    /**
     * Calculer la Retenue à la Source BIC/IS (lignes 400-430, Art.94 CGI et Art.440 LPF)
     *
     * @param array $lignes Clés numériques ('401', '403'...) ou ras_ligne401...
     */
    public static function calculerRetenueSourceBIC(array $lignes): array
    {
        $get = static function (string $n) use ($lignes): float {
            if (isset($lignes[$n])) {
                return (float) $lignes[$n];
            }
            $key = 'ras_ligne' . $n;
            return (float) ($lignes[$key] ?? 0);
        };

        $l401 = $get('401');
        $l403 = $get('403');
        $l404 = $get('404');
        $l405 = $get('405');
        $l406 = $get('406');
        $l411 = $get('411');
        $l412 = $get('412');
        $l413 = $get('413');
        $l418 = $get('418');
        $l419 = $get('419');
        $l425 = $get('425');

        $l410 = $l401 + $l403 + $l404 + $l405 + $l406;
        $l414 = $l411 + $l412 + $l413;
        $l415 = max(0, $l410 - $l414);
        $l416 = round($l415 * 0.50);
        $l417 = $l415 - $l416;
        $l420 = max(0, $l418 - $l419);
        $l421 = round($l420 * 0.90);
        $l422 = $l420 - $l421;
        $l423 = $l417 + $l422;
        $l424 = round($l423 * 0.30);
        $l426 = round($l425 * 0.15);
        $l430 = $l424 + $l426;

        return [
            '401' => $l401, '403' => $l403, '404' => $l404, '405' => $l405, '406' => $l406,
            '410' => $l410, '411' => $l411, '412' => $l412, '413' => $l413, '414' => $l414,
            '415' => $l415, '416' => $l416, '417' => $l417,
            '418' => $l418, '419' => $l419, '420' => $l420, '421' => $l421, '422' => $l422,
            '423' => $l423, '424' => $l424, '425' => $l425, '426' => $l426, '430' => $l430,
            'montant' => $l430,
        ];
    }

    /**
     * Convertir en tableau
     */
    public function toArray(): array
    {
        return [
            'code' => $this->getCode(),
            'nom' => $this->getNom(),
            'base' => $this->base,
            'taux' => $this->taux,
            'montant' => $this->montant
        ];
    }
}

/**
 * ============================================
 * CLASSE ImpotTVA
 * Calcul de la TVA
 * ============================================
 */
class ImpotTVA extends Impot
{
    private float $tvaCollectee = 0;
    private float $tvaDeductible = 0;
    private float $tvaAPayer = 0;
    private float $creditTVA = 0;

    public function getNom(): string
    {
        return 'Taxe sur la Valeur Ajoutée';
    }

    public function getCode(): string
    {
        return 'TVA';
    }

    /**
     * Calculer la TVA
     */
    public function calculer(): float
    {
        $params = $this->getParametresFiscaux();
        $compte = $this->getCompteGestion();

        if (!$params || !$compte) {
            return 0;
        }

        // Si exonéré total, TVA = 0
        if ($params['type_tva'] === 'exonere_total') {
            $this->montant = 0;
            return 0;
        }

        $this->taux = (float) $params['taux_tva'];

        // Calculer le CA taxable
        if ($params['type_tva'] === 'exonere_partiel') {
            $this->base = (float) $compte['ca_taxable'];
        } else {
            $this->base = (float) $compte['ca_global'];
        }

        // TVA collectée
        $this->tvaCollectee = round($this->base * $this->taux / 100, 2);

        // TVA déductible (depuis les achats)
        $sql = "
            SELECT COALESCE(SUM(montant_tva), 0) as tva_deductible
            FROM achats
            WHERE client_id = ? AND mois = ? AND annee = ? AND tva_deductible = 1
        ";
        $result = $this->db->fetchOne($sql, [$this->clientId, $this->mois, $this->annee]);
        $this->tvaDeductible = (float) $result['tva_deductible'];

        // TVA à payer ou crédit
        $difference = $this->tvaCollectee - $this->tvaDeductible;
        
        if ($difference >= 0) {
            $this->tvaAPayer = round($difference, 2);
            $this->creditTVA = 0;
        } else {
            $this->tvaAPayer = 0;
            $this->creditTVA = round(abs($difference), 2);
        }

        $this->montant = $this->tvaAPayer;
        return $this->montant;
    }

    // Getters spécifiques
    public function getTvaCollectee(): float
    {
        return $this->tvaCollectee;
    }

    public function getTvaDeductible(): float
    {
        return $this->tvaDeductible;
    }

    public function getTvaAPayer(): float
    {
        return $this->tvaAPayer;
    }

    public function getCreditTVA(): float
    {
        return $this->creditTVA;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'tva_collectee' => $this->tvaCollectee,
            'tva_deductible' => $this->tvaDeductible,
            'tva_a_payer' => $this->tvaAPayer,
            'credit_tva' => $this->creditTVA
        ]);
    }
}

/**
 * ============================================
 * CLASSE ImpotSalaire
 * Calcul des impôts sur salaires (CF, ITS, TL)
 * ============================================
 */
class ImpotSalaire extends Impot
{
    private float $cf = 0;      // Contribution Forfaitaire 3.5%
    private float $its = 0;     // Impôt sur Traitements et Salaires
    private float $tl = 0;      // Taxe de Logement 1%
    private float $masseSalariale = 0;

    public function getNom(): string
    {
        return 'Impôts sur Salaires';
    }

    public function getCode(): string
    {
        return 'SALAIRES';
    }

    /**
     * Calculer les impôts sur salaires
     */
    public function calculer(): float
    {
        $params = $this->getParametresFiscaux();
        $compte = $this->getCompteGestion();

        if (!$params || !$compte || !$params['salaires_actif']) {
            return 0;
        }

        $this->masseSalariale = (float) $compte['masse_salariale'];
        $this->base = $this->masseSalariale;

        if ($this->masseSalariale <= 0) {
            return 0;
        }

        // CF : 3.5% de la masse salariale
        $this->cf = round($this->masseSalariale * 3.5 / 100, 2);

        // ITS : selon barème (simplifié ici - estimation à 10%)
        $this->its = $this->calculerITS($this->masseSalariale);

        // TL : 1% de la masse salariale
        $this->tl = round($this->masseSalariale * 1 / 100, 2);

        $this->montant = $this->cf + $this->its + $this->tl;
        return $this->montant;
    }

    /**
     * Calculer l'ITS selon le barème
     * Barème simplifié pour l'exemple
     */
    private function calculerITS(float $masseSalariale): float
    {
        // Barème progressif simplifié
        // Tranche 1: 0 - 50 000 : 0%
        // Tranche 2: 50 001 - 130 000 : 10%
        // Tranche 3: 130 001 - 280 000 : 15%
        // Tranche 4: 280 001 - 530 000 : 20%
        // Tranche 5: > 530 000 : 25%

        $its = 0;
        $base = $masseSalariale;

        if ($base > 530000) {
            $its += ($base - 530000) * 0.25;
            $base = 530000;
        }
        if ($base > 280000) {
            $its += ($base - 280000) * 0.20;
            $base = 280000;
        }
        if ($base > 130000) {
            $its += ($base - 130000) * 0.15;
            $base = 130000;
        }
        if ($base > 50000) {
            $its += ($base - 50000) * 0.10;
        }

        return round($its, 2);
    }

    // Getters spécifiques
    public function getCF(): float
    {
        return $this->cf;
    }

    public function getITS(): float
    {
        return $this->its;
    }

    public function getTL(): float
    {
        return $this->tl;
    }

    public function getMasseSalariale(): float
    {
        return $this->masseSalariale;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'masse_salariale' => $this->masseSalariale,
            'cf' => $this->cf,
            'cf_taux' => 3.5,
            'its' => $this->its,
            'tl' => $this->tl,
            'tl_taux' => 1
        ]);
    }
}

/**
 * ============================================
 * CLASSE ImpotLocation
 * Calcul des impôts sur location (IRF, TVA, TF)
 * ============================================
 */
class ImpotLocation extends Impot
{
    private float $irf = 0;         // Impôt sur le Revenu Foncier 12%
    private float $tvaLocation = 0; // TVA sur location 18%
    private float $tf = 0;          // Taxe Foncière 3%
    private float $valeurLocative = 0;

    public function getNom(): string
    {
        return 'Impôts sur Location';
    }

    public function getCode(): string
    {
        return 'LOCATION';
    }

    /**
     * Calculer les impôts sur location
     * TVA/Location dépend de location_actif, IRF+TF dépendent de irf_tf_actif
     */
    public function calculer(): float
    {
        $params = $this->getParametresFiscaux();

        $locationActif = $params && !empty($params['location_actif']);
        $irfTfActif = $params && !empty($params['irf_tf_actif']);

        if (!$locationActif && !$irfTfActif) {
            return 0;
        }

        $this->valeurLocative = (float) ($params['valeur_locative_mensuelle'] ?? 0);
        $this->base = $this->valeurLocative;

        if ($this->valeurLocative <= 0) {
            return 0;
        }

        // IRF : 12% (seulement si irf_tf_actif)
        $this->irf = $irfTfActif ? round($this->valeurLocative * 12 / 100, 2) : 0;

        // TVA sur location : 18% (seulement si location_actif)
        $this->tvaLocation = $locationActif ? round($this->valeurLocative * 18 / 100, 2) : 0;

        // TF : 3% (seulement si irf_tf_actif)
        $this->tf = $irfTfActif ? round($this->valeurLocative * 3 / 100, 2) : 0;

        $this->montant = $this->irf + $this->tvaLocation + $this->tf;
        return $this->montant;
    }

    // Getters spécifiques
    public function getIRF(): float
    {
        return $this->irf;
    }

    public function getTvaLocation(): float
    {
        return $this->tvaLocation;
    }

    public function getTF(): float
    {
        return $this->tf;
    }

    public function getValeurLocative(): float
    {
        return $this->valeurLocative;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'valeur_locative' => $this->valeurLocative,
            'irf' => $this->irf,
            'irf_taux' => 12,
            'tva_location' => $this->tvaLocation,
            'tva_location_taux' => 18,
            'tf' => $this->tf,
            'tf_taux' => 3
        ]);
    }
}

/**
 * ============================================
 * CLASSE ImpotCA (CSS)
 * Contribution du Secteur Spécifique
 * ============================================
 */
class ImpotCA extends Impot
{
    private float $css = 0; // 0.5% du CA

    public function getNom(): string
    {
        return 'Contribution du Secteur Spécifique';
    }

    public function getCode(): string
    {
        return 'CSS';
    }

    /**
     * Calculer la CSS
     */
    public function calculer(): float
    {
        $params = $this->getParametresFiscaux();
        $compte = $this->getCompteGestion();

        if (!$params || !$compte || !$params['css_actif']) {
            return 0;
        }

        $this->base = (float) $compte['ca_global'];
        $this->taux = 0.5;

        if ($this->base <= 0) {
            return 0;
        }

        // CSS : 0.5% du CA
        $this->css = round($this->base * 0.5 / 100, 2);
        $this->montant = $this->css;

        return $this->montant;
    }

    public function getCSS(): float
    {
        return $this->css;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'css' => $this->css
        ]);
    }
}

/**
 * ============================================
 * CLASSE CalculateurImpots
 * Calcule tous les impôts et sauvegarde
 * ============================================
 */
class CalculateurImpots
{
    private int $clientId;
    private int $mois;
    private int $annee;
    private Database $db;

    private ?ImpotTVA $tva = null;
    private ?ImpotSalaire $salaires = null;
    private ?ImpotLocation $location = null;
    private ?ImpotCA $css = null;

    private float $totalImpots = 0;

    public function __construct(int $clientId, int $mois, int $annee)
    {
        $this->db = Database::getInstance();
        $this->clientId = $clientId;
        $this->mois = $mois;
        $this->annee = $annee;
    }

    /**
     * Calculer tous les impôts
     */
    public function calculerTout(): array
    {
        // TVA
        $this->tva = new ImpotTVA($this->clientId, $this->mois, $this->annee);
        $this->tva->calculer();

        // Impôts sur salaires
        $this->salaires = new ImpotSalaire($this->clientId, $this->mois, $this->annee);
        $this->salaires->calculer();

        // Impôts sur location
        $this->location = new ImpotLocation($this->clientId, $this->mois, $this->annee);
        $this->location->calculer();

        // CSS
        $this->css = new ImpotCA($this->clientId, $this->mois, $this->annee);
        $this->css->calculer();

        // Total
        $this->totalImpots = $this->tva->getMontant() 
                          + $this->salaires->getMontant() 
                          + $this->location->getMontant() 
                          + $this->css->getMontant();

        return $this->toArray();
    }

    /**
     * Sauvegarder les impôts calculés
     */
    public function sauvegarder(): bool
    {
        // Vérifier que le compte de gestion existe
        $sql = "SELECT id FROM compte_gestion_mensuel WHERE client_id = ? AND mois = ? AND annee = ?";
        $compte = $this->db->fetchOne($sql, [$this->clientId, $this->mois, $this->annee]);

        if (!$compte) {
            return false;
        }

        // Vérifier si un enregistrement existe déjà
        $sql = "SELECT id FROM impots_mensuels WHERE client_id = ? AND mois = ? AND annee = ?";
        $existing = $this->db->fetchOne($sql, [$this->clientId, $this->mois, $this->annee]);

        if ($existing) {
            // Mise à jour
            $sql = "
                UPDATE impots_mensuels SET
                    tva_collectee = ?, tva_deductible = ?, tva_a_payer = ?, credit_tva = ?,
                    cf = ?, its = ?, tl = ?,
                    irf = ?, tva_location = ?, tf = ?,
                    css = ?, total_impots = ?, date_calcul = CURRENT_TIMESTAMP
                WHERE id = ?
            ";
            $this->db->update($sql, [
                $this->tva->getTvaCollectee(),
                $this->tva->getTvaDeductible(),
                $this->tva->getTvaAPayer(),
                $this->tva->getCreditTVA(),
                $this->salaires->getCF(),
                $this->salaires->getITS(),
                $this->salaires->getTL(),
                $this->location->getIRF(),
                $this->location->getTvaLocation(),
                $this->location->getTF(),
                $this->css->getCSS(),
                $this->totalImpots,
                $existing['id']
            ]);
        } else {
            // Insertion
            $sql = "
                INSERT INTO impots_mensuels (
                    client_id, compte_gestion_id, mois, annee,
                    tva_collectee, tva_deductible, tva_a_payer, credit_tva,
                    cf, its, tl, irf, tva_location, tf, css, total_impots
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            $this->db->insert($sql, [
                $this->clientId,
                $compte['id'],
                $this->mois,
                $this->annee,
                $this->tva->getTvaCollectee(),
                $this->tva->getTvaDeductible(),
                $this->tva->getTvaAPayer(),
                $this->tva->getCreditTVA(),
                $this->salaires->getCF(),
                $this->salaires->getITS(),
                $this->salaires->getTL(),
                $this->location->getIRF(),
                $this->location->getTvaLocation(),
                $this->location->getTF(),
                $this->css->getCSS(),
                $this->totalImpots
            ]);
        }

        $this->logAction('calcul_impots');
        return true;
    }

    /**
     * Obtenir les impôts sauvegardés
     */
    public static function getImpotsMensuels(int $clientId, int $mois, int $annee): ?array
    {
        $db = Database::getInstance();
        
        $sql = "SELECT * FROM impots_mensuels WHERE client_id = ? AND mois = ? AND annee = ?";
        return $db->fetchOne($sql, [$clientId, $mois, $annee]);
    }

    /**
     * Obtenir le total annuel des impôts
     */
    public static function getTotalAnnuel(int $clientId, int $annee): array
    {
        $db = Database::getInstance();

        $sql = "
            SELECT 
                COALESCE(SUM(tva_a_payer), 0) as total_tva,
                COALESCE(SUM(cf), 0) as total_cf,
                COALESCE(SUM(its), 0) as total_its,
                COALESCE(SUM(tl), 0) as total_tl,
                COALESCE(SUM(irf), 0) as total_irf,
                COALESCE(SUM(tva_location), 0) as total_tva_location,
                COALESCE(SUM(tf), 0) as total_tf,
                COALESCE(SUM(css), 0) as total_css,
                COALESCE(SUM(total_impots), 0) as total_general
            FROM impots_mensuels
            WHERE client_id = ? AND annee = ?
        ";

        return $db->fetchOne($sql, [$clientId, $annee]);
    }

    // Getters
    public function getTVA(): ?ImpotTVA
    {
        return $this->tva;
    }

    public function getSalaires(): ?ImpotSalaire
    {
        return $this->salaires;
    }

    public function getLocation(): ?ImpotLocation
    {
        return $this->location;
    }

    public function getCSS(): ?ImpotCA
    {
        return $this->css;
    }

    public function getTotalImpots(): float
    {
        return $this->totalImpots;
    }

    /**
     * Convertir en tableau
     */
    public function toArray(): array
    {
        return [
            'client_id' => $this->clientId,
            'mois' => $this->mois,
            'annee' => $this->annee,
            'tva' => $this->tva ? $this->tva->toArray() : null,
            'salaires' => $this->salaires ? $this->salaires->toArray() : null,
            'location' => $this->location ? $this->location->toArray() : null,
            'css' => $this->css ? $this->css->toArray() : null,
            'total_impots' => $this->totalImpots
        ];
    }

    /**
     * Logger une action
     */
    private function logAction(string $action): void
    {
        $agentId = $_SESSION['agent_id'] ?? null;

        $sql = "
            INSERT INTO logs (agent_id, action, table_concernee, enregistrement_id, ip_address)
            VALUES (?, ?, 'impots_mensuels', ?, ?)
        ";

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'localhost';

        $this->db->insert($sql, [$agentId, $action, $this->clientId, $ip]);
    }
}
