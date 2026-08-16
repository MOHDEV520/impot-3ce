<?php
/**
 * ============================================
 * CLASSE COMPTE GESTION MENSUEL
 * Centralise toutes les données d'un client pour un mois
 * ============================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Achat.php';
require_once __DIR__ . '/Depense.php';
require_once __DIR__ . '/Impot.php';

class CompteGestionMensuel
{
    // Attributs
    private ?int $id = null;
    private int $clientId;
    private int $mois;
    private int $annee;
    private float $caGlobal = 0;
    private float $caExonere = 0;
    private float $caTaxable = 0;
    private float $masseSalariale = 0;
    private float $loyersPercus = 0;
    private float $locLigne132 = 0; // Imm. nus habitation Art.195-18
    private float $locLigne133 = 0; // Code Investissements, Miniers
    private float $locLigne137 = 0; // Microfinance, Conventions Intern.
    private float $locLigne141 = 0; // Autres revenus exonérés
    private float $locLigne145 = 0; // TVA retenue à la source
    private float $cfLigne243 = 0; // CF - Avantages en espèces/nature
    private float $cfLigne246 = 0; // CF - Exonéré Art.161 Jeunes diplômés
    private float $cfLigne247 = 0; // CF - Exonéré Art.162 Compressés
    private float $cfLigne248 = 0; // CF - Stagiaires Art.163
    private float $cfLigne249 = 0; // CF - Indemnités non imposables
    private float $cfLigne250 = 0; // CF - Code Investissement
    private float $cfLigne251 = 0; // CF - Accord Cadre ONG
    private float $tlLigne212 = 0; // TL - Avantages en espèces/nature
    // TVA - lignes saisies manuellement
    private float $tvaLigne82 = 0;  // CA Exportation
    private float $tvaLigne83 = 0;  // CA Exonéré Investissements
    private float $tvaLigne84 = 0;  // CA Exonéré Microfinance
    private float $tvaLigne85 = 0;  // CA Exonéré Conventions Internationales
    private float $tvaLigne86 = 0;  // Autres Exonérations CA
    private float $tvaLigne101 = 0; // Livraison à soi-même imposable
    private float $tvaLigne102 = 0; // Portion CA taux réduit (double taux)
    private float $tvaLigne103 = 0; // Livraison à soi-même taux réduit
    private float $tvaLigne107 = 0; // Livraison à soi-même taux normal
    private float $tvaLigne110 = 0; // Reversement TVA Régularisation
    private float $tvaLigne112 = 0; // TVA Déductible Achats Locaux
    private float $tvaLigne113 = 0; // TVA Déductible Importations
    private float $tvaLigne114 = 0; // TVA Déductible Prorata Achats Locaux
    private float $tvaLigne115 = 0; // TVA Déductible Prorata Importations
    private float $tvaLigne116 = 0; // TVA Retenue Trésor
    private float $tvaLigne117 = 0; // Complément Déduction Régularisation
    private float $tvaLigne118 = 0; // TVA Retenue Clients
    private float $tvaLigne120 = 0; // Report Crédit Mois Précédents
    // Retenue à la Source BIC/IS (lignes 401-430)
    private float $rasLigne401 = 0;
    private float $rasLigne403 = 0;
    private float $rasLigne404 = 0;
    private float $rasLigne405 = 0;
    private float $rasLigne406 = 0;
    private float $rasLigne411 = 0;
    private float $rasLigne412 = 0;
    private float $rasLigne413 = 0;
    private float $rasLigne418 = 0;
    private float $rasLigne419 = 0;
    private float $rasLigne425 = 0;
    private float $its = 0;
    private float $marge = 1.30;
    private float $margeTaxable = 1.30;
    private string $statut = 'en_preparation';
    private ?string $dateValidation = null;
    private ?int $validePar = null;
    private ?string $dateCreation = null;
    private ?string $dateModification = null;

    // Instance de Database
    private Database $db;

    // Statuts valides
    private const STATUTS_VALIDES = ['en_preparation', 'pret_declaration', 'valide', 'verrouille'];

    /**
     * Constructeur
     */
    public function __construct(?int $clientId = null, ?int $mois = null, ?int $annee = null)
    {
        $this->db = Database::getInstance();
        
        if ($clientId !== null && $mois !== null && $annee !== null) {
            $this->clientId = $clientId;
            $this->mois = $mois;
            $this->annee = $annee;
            $this->chargerOuCreer();
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

    public function getMois(): int
    {
        return $this->mois;
    }

    public function getAnnee(): int
    {
        return $this->annee;
    }

    public function getMoisLibelle(): string
    {
        $moisNoms = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
        ];
        return $moisNoms[$this->mois] ?? '';
    }

    public function getPeriode(): string
    {
        return $this->getMoisLibelle() . ' ' . $this->annee;
    }

    public function getCaGlobal(): float
    {
        return $this->caGlobal;
    }

    public function getCaExonere(): float
    {
        return $this->caExonere;
    }

    public function getCaTaxable(): float
    {
        return $this->caTaxable;
    }

    public function getMasseSalariale(): float
    {
        return $this->masseSalariale;
    }

    public function getLoyersPercus(): float
    {
        return $this->loyersPercus;
    }

    public function getLocLigne132(): float { return $this->locLigne132; }
    public function getLocLigne133(): float { return $this->locLigne133; }
    public function getLocLigne137(): float { return $this->locLigne137; }
    public function getLocLigne141(): float { return $this->locLigne141; }
    public function getLocLigne145(): float { return $this->locLigne145; }

    public function getCfLigne243(): float { return $this->cfLigne243; }
    public function getCfLigne246(): float { return $this->cfLigne246; }
    public function getCfLigne247(): float { return $this->cfLigne247; }
    public function getCfLigne248(): float { return $this->cfLigne248; }
    public function getCfLigne249(): float { return $this->cfLigne249; }
    public function getCfLigne250(): float { return $this->cfLigne250; }
    public function getCfLigne251(): float { return $this->cfLigne251; }
    public function getTlLigne212(): float { return $this->tlLigne212; }

    public function getTvaLigne82(): float { return $this->tvaLigne82; }
    public function getTvaLigne83(): float { return $this->tvaLigne83; }
    public function getTvaLigne84(): float { return $this->tvaLigne84; }
    public function getTvaLigne85(): float { return $this->tvaLigne85; }
    public function getTvaLigne86(): float { return $this->tvaLigne86; }
    public function getTvaLigne101(): float { return $this->tvaLigne101; }
    public function getTvaLigne102(): float { return $this->tvaLigne102; }
    public function getTvaLigne103(): float { return $this->tvaLigne103; }
    public function getTvaLigne107(): float { return $this->tvaLigne107; }
    public function getTvaLigne110(): float { return $this->tvaLigne110; }
    public function getTvaLigne112(): float { return $this->tvaLigne112; }
    public function getTvaLigne113(): float { return $this->tvaLigne113; }
    public function getTvaLigne114(): float { return $this->tvaLigne114; }
    public function getTvaLigne115(): float { return $this->tvaLigne115; }
    public function getTvaLigne116(): float { return $this->tvaLigne116; }
    public function getTvaLigne117(): float { return $this->tvaLigne117; }
    public function getTvaLigne118(): float { return $this->tvaLigne118; }
    public function getTvaLigne120(): float { return $this->tvaLigne120; }

    public function getRasLigne401(): float { return $this->rasLigne401; }
    public function getRasLigne403(): float { return $this->rasLigne403; }
    public function getRasLigne404(): float { return $this->rasLigne404; }
    public function getRasLigne405(): float { return $this->rasLigne405; }
    public function getRasLigne406(): float { return $this->rasLigne406; }
    public function getRasLigne411(): float { return $this->rasLigne411; }
    public function getRasLigne412(): float { return $this->rasLigne412; }
    public function getRasLigne413(): float { return $this->rasLigne413; }
    public function getRasLigne418(): float { return $this->rasLigne418; }
    public function getRasLigne419(): float { return $this->rasLigne419; }
    public function getRasLigne425(): float { return $this->rasLigne425; }

    public function getIts(): float
    {
        return $this->its;
    }

    public function getMarge(): float
    {
        return $this->marge;
    }

    public function getMargeTaxable(): float
    {
        return $this->margeTaxable;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getStatutLibelle(): string
    {
        $libelles = [
            'en_preparation' => 'En préparation',
            'pret_declaration' => 'Prêt pour déclaration',
            'valide' => 'Validé',
            'verrouille' => 'Verrouillé'
        ];
        return $libelles[$this->statut] ?? $this->statut;
    }

    /**
     * Source unique de vérité pour l'affichage d'un statut de mois (label + couleurs).
     * Utilisée par toutes les pages qui listent des clients (dashboard.php, clients.php…)
     * pour éviter que deux écrans affichent un état différent pour le même client —
     * bug déjà survenu : dashboard.php et clients.php testaient chacune des valeurs
     * de statut différentes (voir STATUTS_VALIDES pour les valeurs réelles en base).
     *
     * Fournit deux variantes de couleur pour les deux usages courants : badge
     * (fond clair + texte foncé, contraste AA) et point plein (petit indicateur
     * coloré à côté d'un libellé texte).
     *
     * @param string|null $statut Valeur brute de compte_gestion_mensuel.statut, ou null si
     *                            aucune ligne n'existe encore pour ce client/mois.
     */
    public static function getEtatAffichage(?string $statut): array
    {
        if ($statut === 'valide' || $statut === 'verrouille') {
            return ['label' => 'Complet', 'classeBadge' => 'bg-green-100 text-green-800', 'classePoint' => 'bg-green-600'];
        }
        if ($statut === 'en_preparation' || $statut === 'pret_declaration') {
            return ['label' => 'En cours', 'classeBadge' => 'bg-amber-100 text-amber-900', 'classePoint' => 'bg-amber-500'];
        }
        return ['label' => 'Incomplet', 'classeBadge' => 'bg-red-100 text-red-800', 'classePoint' => 'bg-red-600'];
    }

    public function getDateValidation(): ?string
    {
        return $this->dateValidation;
    }

    public function getValidePar(): ?int
    {
        return $this->validePar;
    }

    public function getDateCreation(): ?string
    {
        return $this->dateCreation;
    }

    public function getDateModification(): ?string
    {
        return $this->dateModification;
    }

    // ========================================
    // SETTERS
    // ========================================

    public function setCaGlobal(float $montant): self
    {
        if ($this->estVerrouille()) {
            throw new Exception("Impossible de modifier un mois verrouillé.");
        }
        $this->caGlobal = round($montant, 2);
        // On ne recalcule plus auto ici pour éviter d'écraser le taxable manuel
        return $this;
    }

    public function setCaExonere(float $montant): self
    {
        if ($this->estVerrouille()) {
            throw new Exception("Impossible de modifier un mois verrouillé.");
        }
        $this->caExonere = round($montant, 2);
        // On ne recalcule plus auto ici
        return $this;
    }

    public function setMasseSalariale(float $montant): self
    {
        if ($this->estVerrouille()) {
            throw new Exception("Impossible de modifier un mois verrouillé.");
        }
        $this->masseSalariale = round($montant, 2);
        return $this;
    }

    public function setLoyersPercus(float $montant): self
    {
        if ($this->estVerrouille()) {
            throw new Exception("Impossible de modifier un mois verrouillé.");
        }
        $this->loyersPercus = round($montant, 2);
        return $this;
    }

    public function setLocLigne132(float $montant): self { $this->locLigne132 = round($montant, 2); return $this; }
    public function setLocLigne133(float $montant): self { $this->locLigne133 = round($montant, 2); return $this; }
    public function setLocLigne137(float $montant): self { $this->locLigne137 = round($montant, 2); return $this; }
    public function setLocLigne141(float $montant): self { $this->locLigne141 = round($montant, 2); return $this; }
    public function setLocLigne145(float $montant): self { $this->locLigne145 = round($montant, 2); return $this; }

    public function setCfLigne243(float $montant): self { $this->cfLigne243 = round($montant, 2); return $this; }
    public function setCfLigne246(float $montant): self { $this->cfLigne246 = round($montant, 2); return $this; }
    public function setCfLigne247(float $montant): self { $this->cfLigne247 = round($montant, 2); return $this; }
    public function setCfLigne248(float $montant): self { $this->cfLigne248 = round($montant, 2); return $this; }
    public function setCfLigne249(float $montant): self { $this->cfLigne249 = round($montant, 2); return $this; }
    public function setCfLigne250(float $montant): self { $this->cfLigne250 = round($montant, 2); return $this; }
    public function setCfLigne251(float $montant): self { $this->cfLigne251 = round($montant, 2); return $this; }
    public function setTlLigne212(float $montant): self { $this->tlLigne212 = round($montant, 2); return $this; }

    public function setTvaLigne82(float $m): self { $this->tvaLigne82 = round($m, 2); return $this; }
    public function setTvaLigne83(float $m): self { $this->tvaLigne83 = round($m, 2); return $this; }
    public function setTvaLigne84(float $m): self { $this->tvaLigne84 = round($m, 2); return $this; }
    public function setTvaLigne85(float $m): self { $this->tvaLigne85 = round($m, 2); return $this; }
    public function setTvaLigne86(float $m): self { $this->tvaLigne86 = round($m, 2); return $this; }
    public function setTvaLigne101(float $m): self { $this->tvaLigne101 = round($m, 2); return $this; }
    public function setTvaLigne102(float $m): self { $this->tvaLigne102 = round($m, 2); return $this; }
    public function setTvaLigne103(float $m): self { $this->tvaLigne103 = round($m, 2); return $this; }
    public function setTvaLigne107(float $m): self { $this->tvaLigne107 = round($m, 2); return $this; }
    public function setTvaLigne110(float $m): self { $this->tvaLigne110 = round($m, 2); return $this; }
    public function setTvaLigne112(float $m): self { $this->tvaLigne112 = round($m, 2); return $this; }
    public function setTvaLigne113(float $m): self { $this->tvaLigne113 = round($m, 2); return $this; }
    public function setTvaLigne114(float $m): self { $this->tvaLigne114 = round($m, 2); return $this; }
    public function setTvaLigne115(float $m): self { $this->tvaLigne115 = round($m, 2); return $this; }
    public function setTvaLigne116(float $m): self { $this->tvaLigne116 = round($m, 2); return $this; }
    public function setTvaLigne117(float $m): self { $this->tvaLigne117 = round($m, 2); return $this; }
    public function setTvaLigne118(float $m): self { $this->tvaLigne118 = round($m, 2); return $this; }
    public function setTvaLigne120(float $m): self { $this->tvaLigne120 = round($m, 2); return $this; }

    public function setRasLigne401(float $m): self { $this->rasLigne401 = round($m, 2); return $this; }
    public function setRasLigne403(float $m): self { $this->rasLigne403 = round($m, 2); return $this; }
    public function setRasLigne404(float $m): self { $this->rasLigne404 = round($m, 2); return $this; }
    public function setRasLigne405(float $m): self { $this->rasLigne405 = round($m, 2); return $this; }
    public function setRasLigne406(float $m): self { $this->rasLigne406 = round($m, 2); return $this; }
    public function setRasLigne411(float $m): self { $this->rasLigne411 = round($m, 2); return $this; }
    public function setRasLigne412(float $m): self { $this->rasLigne412 = round($m, 2); return $this; }
    public function setRasLigne413(float $m): self { $this->rasLigne413 = round($m, 2); return $this; }
    public function setRasLigne418(float $m): self { $this->rasLigne418 = round($m, 2); return $this; }
    public function setRasLigne419(float $m): self { $this->rasLigne419 = round($m, 2); return $this; }
    public function setRasLigne425(float $m): self { $this->rasLigne425 = round($m, 2); return $this; }

    public function setIts(float $montant): self
    {
        if ($this->estVerrouille()) {
            throw new Exception("Impossible de modifier un mois verrouillé.");
        }
        $this->its = round($montant, 2);
        return $this;
    }

    public function setMarge(float $marge): self
    {
        if ($this->estVerrouille()) {
            throw new Exception("Impossible de modifier un mois verrouillé.");
        }
        $this->marge = round($marge, 2);
        return $this;
    }

    public function setMargeTaxable(float $marge): self
    {
        if ($this->estVerrouille()) {
            throw new Exception("Impossible de modifier un mois verrouillé.");
        }
        $this->margeTaxable = round($marge, 2);
        return $this;
    }

    /**
     * Définir manuellement le CA taxable (écrase le calcul auto)
     */
    public function setCaTaxable(float $montant): self
    {
        if ($this->estVerrouille()) {
            throw new Exception("Impossible de modifier un mois verrouillé.");
        }
        $this->caTaxable = round($montant, 2);
        return $this;
    }

    /**
     * Calculer le CA taxable
     */
    private function calculerCaTaxable(): void
    {
        $this->caTaxable = max(0, $this->caGlobal - $this->caExonere);
    }

    // ========================================
    // MÉTHODES DE CHARGEMENT
    // ========================================

    /**
     * Charger ou créer le compte de gestion
     */
    private function chargerOuCreer(): void
    {
        $sql = "SELECT * FROM compte_gestion_mensuel WHERE client_id = ? AND mois = ? AND annee = ?";
        $data = $this->db->fetchOne($sql, [$this->clientId, $this->mois, $this->annee]);
        
        if ($data) {
            $this->hydrater($data);
        } else {
            $this->creer();
        }
    }

    /**
     * Charger par ID
     */
    public function charger(int $id): bool
    {
        $sql = "SELECT * FROM compte_gestion_mensuel WHERE id = ?";
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
        $this->mois = (int) $data['mois'];
        $this->annee = (int) $data['annee'];
        $this->caGlobal = (float) $data['ca_global'];
        $this->caExonere = (float) $data['ca_exonere'];
        $this->caTaxable = (float) $data['ca_taxable'];
        $this->masseSalariale = (float) $data['masse_salariale'];
        $this->loyersPercus = (float) ($data['loyers_percus'] ?? 0);
        $this->locLigne132 = (float) ($data['loc_ligne132'] ?? 0);
        $this->locLigne133 = (float) ($data['loc_ligne133'] ?? 0);
        $this->locLigne137 = (float) ($data['loc_ligne137'] ?? 0);
        $this->locLigne141 = (float) ($data['loc_ligne141'] ?? 0);
        $this->locLigne145 = (float) ($data['loc_ligne145'] ?? 0);
        $this->cfLigne243 = (float) ($data['cf_ligne243'] ?? 0);
        $this->cfLigne246 = (float) ($data['cf_ligne246'] ?? 0);
        $this->cfLigne247 = (float) ($data['cf_ligne247'] ?? 0);
        $this->cfLigne248 = (float) ($data['cf_ligne248'] ?? 0);
        $this->cfLigne249 = (float) ($data['cf_ligne249'] ?? 0);
        $this->cfLigne250 = (float) ($data['cf_ligne250'] ?? 0);
        $this->cfLigne251 = (float) ($data['cf_ligne251'] ?? 0);
        $this->tlLigne212 = (float) ($data['tl_ligne212'] ?? 0);
        $this->tvaLigne82 = (float) ($data['tva_ligne82'] ?? 0);
        $this->tvaLigne83 = (float) ($data['tva_ligne83'] ?? 0);
        $this->tvaLigne84 = (float) ($data['tva_ligne84'] ?? 0);
        $this->tvaLigne85 = (float) ($data['tva_ligne85'] ?? 0);
        $this->tvaLigne86 = (float) ($data['tva_ligne86'] ?? 0);
        $this->tvaLigne101 = (float) ($data['tva_ligne101'] ?? 0);
        $this->tvaLigne102 = (float) ($data['tva_ligne102'] ?? 0);
        $this->tvaLigne103 = (float) ($data['tva_ligne103'] ?? 0);
        $this->tvaLigne107 = (float) ($data['tva_ligne107'] ?? 0);
        $this->tvaLigne110 = (float) ($data['tva_ligne110'] ?? 0);
        $this->tvaLigne112 = (float) ($data['tva_ligne112'] ?? 0);
        $this->tvaLigne113 = (float) ($data['tva_ligne113'] ?? 0);
        $this->tvaLigne114 = (float) ($data['tva_ligne114'] ?? 0);
        $this->tvaLigne115 = (float) ($data['tva_ligne115'] ?? 0);
        $this->tvaLigne116 = (float) ($data['tva_ligne116'] ?? 0);
        $this->tvaLigne117 = (float) ($data['tva_ligne117'] ?? 0);
        $this->tvaLigne118 = (float) ($data['tva_ligne118'] ?? 0);
        $this->tvaLigne120 = (float) ($data['tva_ligne120'] ?? 0);
        $this->rasLigne401 = (float) ($data['ras_ligne401'] ?? 0);
        $this->rasLigne403 = (float) ($data['ras_ligne403'] ?? 0);
        $this->rasLigne404 = (float) ($data['ras_ligne404'] ?? 0);
        $this->rasLigne405 = (float) ($data['ras_ligne405'] ?? 0);
        $this->rasLigne406 = (float) ($data['ras_ligne406'] ?? 0);
        $this->rasLigne411 = (float) ($data['ras_ligne411'] ?? 0);
        $this->rasLigne412 = (float) ($data['ras_ligne412'] ?? 0);
        $this->rasLigne413 = (float) ($data['ras_ligne413'] ?? 0);
        $this->rasLigne418 = (float) ($data['ras_ligne418'] ?? 0);
        $this->rasLigne419 = (float) ($data['ras_ligne419'] ?? 0);
        $this->rasLigne425 = (float) ($data['ras_ligne425'] ?? 0);
        $this->its = (float) ($data['its'] ?? 0);
        $this->marge = (float) ($data['marge'] ?? 1.30);
        $this->margeTaxable = (float) ($data['marge_taxable'] ?? 1.30);
        $this->statut = $data['statut'];
        $this->dateValidation = $data['date_validation'];
        $this->validePar = $data['valide_par'] ? (int) $data['valide_par'] : null;
        $this->dateCreation = $data['date_creation'];
        $this->dateModification = $data['date_modification'];
    }

    /**
     * Créer le compte de gestion
     */
    private function creer(): void
    {
        $sql = "INSERT INTO compte_gestion_mensuel (client_id, mois, annee) VALUES (?, ?, ?)";
        $this->id = $this->db->insert($sql, [$this->clientId, $this->mois, $this->annee]);
        $this->statut = 'en_preparation';
    }

    // ========================================
    // MÉTHODES DE SAUVEGARDE
    // ========================================

    /**
     * Sauvegarder les modifications
     */
    public function sauvegarder(): bool
    {
        if ($this->estVerrouille()) {
            throw new Exception("Impossible de modifier un mois verrouillé.");
        }

        $sql = "
            UPDATE compte_gestion_mensuel 
            SET ca_global = ?, ca_exonere = ?, ca_taxable = ?, masse_salariale = ?,
                loyers_percus = ?, loc_ligne132 = ?, loc_ligne133 = ?, loc_ligne137 = ?, loc_ligne141 = ?, loc_ligne145 = ?,
                cf_ligne243 = ?, cf_ligne246 = ?, cf_ligne247 = ?, cf_ligne248 = ?, cf_ligne249 = ?, cf_ligne250 = ?, cf_ligne251 = ?,
                tl_ligne212 = ?,
                tva_ligne82 = ?, tva_ligne83 = ?, tva_ligne84 = ?, tva_ligne85 = ?, tva_ligne86 = ?,
                tva_ligne101 = ?, tva_ligne102 = ?, tva_ligne103 = ?, tva_ligne107 = ?, tva_ligne110 = ?,
                tva_ligne112 = ?,
                tva_ligne113 = ?, tva_ligne114 = ?, tva_ligne115 = ?, tva_ligne116 = ?, tva_ligne117 = ?, tva_ligne118 = ?,
                tva_ligne120 = ?,
                ras_ligne401 = ?, ras_ligne403 = ?, ras_ligne404 = ?, ras_ligne405 = ?, ras_ligne406 = ?,
                ras_ligne411 = ?, ras_ligne412 = ?, ras_ligne413 = ?,
                ras_ligne418 = ?, ras_ligne419 = ?, ras_ligne425 = ?,
                its = ?, marge = ?, marge_taxable = ?,
                statut = ?, date_modification = CURRENT_TIMESTAMP
            WHERE id = ?
        ";
        
        $result = $this->db->update($sql, [
            $this->caGlobal,
            $this->caExonere,
            $this->caTaxable,
            $this->masseSalariale,
            $this->loyersPercus,
            $this->locLigne132,
            $this->locLigne133,
            $this->locLigne137,
            $this->locLigne141,
            $this->locLigne145,
            $this->cfLigne243,
            $this->cfLigne246,
            $this->cfLigne247,
            $this->cfLigne248,
            $this->cfLigne249,
            $this->cfLigne250,
            $this->cfLigne251,
            $this->tlLigne212,
            $this->tvaLigne82, $this->tvaLigne83, $this->tvaLigne84, $this->tvaLigne85, $this->tvaLigne86,
            $this->tvaLigne101, $this->tvaLigne102, $this->tvaLigne103, $this->tvaLigne107, $this->tvaLigne110,
            $this->tvaLigne112,
            $this->tvaLigne113, $this->tvaLigne114, $this->tvaLigne115, $this->tvaLigne116, $this->tvaLigne117, $this->tvaLigne118,
            $this->tvaLigne120,
            $this->rasLigne401, $this->rasLigne403, $this->rasLigne404, $this->rasLigne405, $this->rasLigne406,
            $this->rasLigne411, $this->rasLigne412, $this->rasLigne413,
            $this->rasLigne418, $this->rasLigne419, $this->rasLigne425,
            $this->its,
            $this->marge,
            $this->margeTaxable,
            $this->statut,
            $this->id
        ]);
        
        $this->logAction('modification_compte_gestion');
        
        return $result > 0;
    }

    // ========================================
    // GESTION DES STATUTS
    // ========================================

    /**
     * Vérifier si le mois est verrouillé
     */
    public function estVerrouille(): bool
    {
        return $this->statut === 'verrouille';
    }

    /**
     * Vérifier si le mois est prêt pour déclaration
     */
    public function estPretPourDeclaration(): bool
    {
        return in_array($this->statut, ['pret_declaration', 'valide', 'verrouille']);
    }

    /**
     * Marquer comme prêt pour déclaration
     */
    public function marquerPretPourDeclaration(): bool
    {
        if ($this->estVerrouille()) {
            throw new Exception("Ce mois est déjà verrouillé.");
        }

        $this->statut = 'pret_declaration';
        return $this->sauvegarder();
    }

    /**
     * Valider le mois
     */
    public function valider(): bool
    {
        if ($this->estVerrouille()) {
            throw new Exception("Ce mois est déjà verrouillé.");
        }

        $agentId = $_SESSION['agent_id'] ?? null;

        $sql = "
            UPDATE compte_gestion_mensuel 
            SET statut = 'valide', date_validation = CURRENT_TIMESTAMP, valide_par = ?
            WHERE id = ?
        ";
        
        $result = $this->db->update($sql, [$agentId, $this->id]);
        
        if ($result > 0) {
            $this->statut = 'valide';
            $this->validePar = $agentId;
            $this->logAction('validation_mois');
        }
        
        return $result > 0;
    }

    /**
     * Verrouiller le mois (lecture seule)
     */
    public function verrouiller(): bool
    {
        if ($this->estVerrouille()) {
            return true;
        }

        $sql = "UPDATE compte_gestion_mensuel SET statut = 'verrouille' WHERE id = ?";
        $result = $this->db->update($sql, [$this->id]);
        
        if ($result > 0) {
            $this->statut = 'verrouille';
            $this->logAction('verrouillage_mois');
        }
        
        return $result > 0;
    }

    /**
     * Déverrouiller le mois (admin uniquement)
     */
    public function deverrouiller(): bool
    {
        $sql = "UPDATE compte_gestion_mensuel SET statut = 'valide' WHERE id = ?";
        $result = $this->db->update($sql, [$this->id]);
        
        if ($result > 0) {
            $this->statut = 'valide';
            $this->logAction('deverrouillage_mois');
        }
        
        return $result > 0;
    }

    // ========================================
    // DONNÉES LIÉES
    // ========================================

    /**
     * Obtenir les achats du mois
     */
    public function getAchats(): array
    {
        return Achat::getByClientMois($this->clientId, $this->mois, $this->annee);
    }

    /**
     * Obtenir les achats groupés par fournisseur
     */
    public function getAchatsParFournisseur(): array
    {
        return Achat::getByClientMoisGroupeParFournisseur($this->clientId, $this->mois, $this->annee);
    }

    /**
     * Obtenir les totaux des achats
     */
    public function getTotauxAchats(): array
    {
        return Achat::getTotaux($this->clientId, $this->mois, $this->annee);
    }

    /**
     * Obtenir les dépenses du mois
     */
    public function getDepenses(): array
    {
        return Depense::getByClientMois($this->clientId, $this->mois, $this->annee);
    }

    /**
     * Obtenir les dépenses groupées par nature
     */
    public function getDepensesParNature(): array
    {
        return Depense::getByClientMoisGroupeParNature($this->clientId, $this->mois, $this->annee);
    }

    /**
     * Obtenir le total des dépenses
     */
    public function getTotalDepenses(): array
    {
        return Depense::getTotal($this->clientId, $this->mois, $this->annee);
    }

    /**
     * Obtenir les impôts calculés
     */
    public function getImpots(): ?array
    {
        return CalculateurImpots::getImpotsMensuels($this->clientId, $this->mois, $this->annee);
    }

    /**
     * Calculer et sauvegarder les impôts
     */
    public function calculerImpots(): array
    {
        $calculateur = new CalculateurImpots($this->clientId, $this->mois, $this->annee);
        $result = $calculateur->calculerTout();
        $calculateur->sauvegarder();
        return $result;
    }

    // ========================================
    // TABLEAU DE BORD
    // ========================================

    /**
     * Générer le tableau de bord complet
     */
    public function genererTableauBord(): array
    {
        $achats = $this->getTotauxAchats();
        $depenses = $this->getTotalDepenses();
        $impots = $this->getImpots();

        return [
            'periode' => $this->getPeriode(),
            'statut' => $this->statut,
            'statut_libelle' => $this->getStatutLibelle(),
            
            // Chiffre d'affaires
            'ca_global' => $this->caGlobal,
            'ca_exonere' => $this->caExonere,
            'ca_taxable' => $this->caTaxable,
            
            // Achats
            'achats' => [
                'total_ht' => $achats['total_ht'],
                'total_tva' => $achats['total_tva'],
                'total_ttc' => $achats['total_ttc'],
                'tva_deductible' => $achats['tva_deductible'],
                'nb_achats' => $achats['nb_achats'],
                'par_fournisseur' => $this->getAchatsParFournisseur()
            ],
            
            // Dépenses
            'depenses' => [
                'total' => $depenses['total'],
                'total_deductible' => $depenses['total_deductible'],
                'nb_depenses' => $depenses['nb_depenses'],
                'par_nature' => $this->getDepensesParNature()
            ],
            
            // Masse salariale
            'masse_salariale' => $this->masseSalariale,
            
            // Impôts
            'impots' => $impots ? [
                'tva_collectee' => (float) $impots['tva_collectee'],
                'tva_deductible' => (float) $impots['tva_deductible'],
                'tva_a_payer' => (float) $impots['tva_a_payer'],
                'credit_tva' => (float) $impots['credit_tva'],
                'cf' => (float) $impots['cf'],
                'its' => (float) $impots['its'],
                'tl' => (float) $impots['tl'],
                'irf' => (float) $impots['irf'],
                'tva_location' => (float) $impots['tva_location'],
                'tf' => (float) $impots['tf'],
                'css' => (float) $impots['css'],
                'total' => (float) $impots['total_impots']
            ] : null,
            
            // Résumé
            'resume' => [
                'total_charges' => $achats['total_ttc'] + $depenses['total'] + $this->masseSalariale,
                'total_impots' => $impots ? (float) $impots['total_impots'] : 0,
                'resultat_brut' => $this->caGlobal - $achats['total_ht'] - $depenses['total'] - $this->masseSalariale
            ]
        ];
    }

    // ========================================
    // MÉTHODES STATIQUES
    // ========================================

    /**
     * Obtenir les comptes de gestion d'un client pour une année
     */
    public static function getByClientAnnee(int $clientId, int $annee): array
    {
        $db = Database::getInstance();
        
        $sql = "
            SELECT cgm.*, 
                   COALESCE(im.total_impots, 0) as total_impots
            FROM compte_gestion_mensuel cgm
            LEFT JOIN impots_mensuels im ON cgm.client_id = im.client_id 
                AND cgm.mois = im.mois AND cgm.annee = im.annee
            WHERE cgm.client_id = ? AND cgm.annee = ?
            ORDER BY cgm.mois
        ";
        
        return $db->fetchAll($sql, [$clientId, $annee]);
    }

    /**
     * Obtenir le résumé annuel
     */
    public static function getResumeAnnuel(int $clientId, int $annee): array
    {
        $db = Database::getInstance();

        // Totaux compte de gestion
        $sql = "
            SELECT 
                COALESCE(SUM(ca_global), 0) as ca_total,
                COALESCE(SUM(ca_exonere), 0) as ca_exonere_total,
                COALESCE(SUM(ca_taxable), 0) as ca_taxable_total,
                COALESCE(SUM(masse_salariale), 0) as masse_salariale_total,
                COUNT(*) as nb_mois
            FROM compte_gestion_mensuel
            WHERE client_id = ? AND annee = ?
        ";
        $comptes = $db->fetchOne($sql, [$clientId, $annee]);

        // Totaux achats
        $sql = "
            SELECT 
                COALESCE(SUM(montant_ht), 0) as achats_ht,
                COALESCE(SUM(montant_tva), 0) as achats_tva,
                COALESCE(SUM(montant_ttc), 0) as achats_ttc
            FROM achats
            WHERE client_id = ? AND annee = ?
        ";
        $achats = $db->fetchOne($sql, [$clientId, $annee]);

        // Totaux dépenses
        $sql = "
            SELECT COALESCE(SUM(montant), 0) as depenses_total
            FROM depenses
            WHERE client_id = ? AND annee = ?
        ";
        $depenses = $db->fetchOne($sql, [$clientId, $annee]);

        // Totaux impôts
        $impots = CalculateurImpots::getTotalAnnuel($clientId, $annee);

        return [
            'annee' => $annee,
            'ca_total' => (float) $comptes['ca_total'],
            'ca_exonere_total' => (float) $comptes['ca_exonere_total'],
            'ca_taxable_total' => (float) $comptes['ca_taxable_total'],
            'masse_salariale_total' => (float) $comptes['masse_salariale_total'],
            'achats_ht' => (float) $achats['achats_ht'],
            'achats_tva' => (float) $achats['achats_tva'],
            'achats_ttc' => (float) $achats['achats_ttc'],
            'depenses_total' => (float) $depenses['depenses_total'],
            'impots' => $impots,
            'nb_mois_saisis' => (int) $comptes['nb_mois']
        ];
    }

    /**
     * Obtenir les mois disponibles
     */
    public static function getMoisDisponibles(): array
    {
        return [
            1 => 'Janvier',
            2 => 'Février',
            3 => 'Mars',
            4 => 'Avril',
            5 => 'Mai',
            6 => 'Juin',
            7 => 'Juillet',
            8 => 'Août',
            9 => 'Septembre',
            10 => 'Octobre',
            11 => 'Novembre',
            12 => 'Décembre'
        ];
    }

    // ========================================
    // LOGGING
    // ========================================

    /**
     * Logger une action
     */
    private function logAction(string $action): void
    {
        $agentId = $_SESSION['agent_id'] ?? null;

        $sql = "
            INSERT INTO logs (agent_id, action, table_concernee, enregistrement_id, details, ip_address)
            VALUES (?, ?, 'compte_gestion_mensuel', ?, ?, ?)
        ";

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'localhost';
        $details = json_encode(['mois' => $this->mois, 'annee' => $this->annee]);

        $this->db->insert($sql, [$agentId, $action, $this->id, $details, $ip]);
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
            'mois' => $this->mois,
            'mois_libelle' => $this->getMoisLibelle(),
            'annee' => $this->annee,
            'periode' => $this->getPeriode(),
            'ca_global' => $this->caGlobal,
            'ca_exonere' => $this->caExonere,
            'ca_taxable' => $this->caTaxable,
            'masse_salariale' => $this->masseSalariale,
            'loyers_percus' => $this->loyersPercus,
            'loc_ligne132' => $this->locLigne132,
            'loc_ligne133' => $this->locLigne133,
            'loc_ligne137' => $this->locLigne137,
            'loc_ligne141' => $this->locLigne141,
            'loc_ligne145' => $this->locLigne145,
            'cf_ligne243' => $this->cfLigne243,
            'cf_ligne246' => $this->cfLigne246,
            'cf_ligne247' => $this->cfLigne247,
            'cf_ligne248' => $this->cfLigne248,
            'cf_ligne249' => $this->cfLigne249,
            'cf_ligne250' => $this->cfLigne250,
            'cf_ligne251' => $this->cfLigne251,
            'tl_ligne212' => $this->tlLigne212,
            'tva_ligne82' => $this->tvaLigne82, 'tva_ligne83' => $this->tvaLigne83,
            'tva_ligne84' => $this->tvaLigne84, 'tva_ligne85' => $this->tvaLigne85,
            'tva_ligne86' => $this->tvaLigne86,             'tva_ligne101' => $this->tvaLigne101,
            'tva_ligne102' => $this->tvaLigne102,
            'tva_ligne103' => $this->tvaLigne103, 'tva_ligne107' => $this->tvaLigne107,
            'tva_ligne110' => $this->tvaLigne110, 'tva_ligne112' => $this->tvaLigne112,
            'tva_ligne113' => $this->tvaLigne113,
            'tva_ligne114' => $this->tvaLigne114, 'tva_ligne115' => $this->tvaLigne115,
            'tva_ligne116' => $this->tvaLigne116, 'tva_ligne117' => $this->tvaLigne117,
            'tva_ligne118' => $this->tvaLigne118, 'tva_ligne120' => $this->tvaLigne120,
            'ras_ligne401' => $this->rasLigne401, 'ras_ligne403' => $this->rasLigne403,
            'ras_ligne404' => $this->rasLigne404, 'ras_ligne405' => $this->rasLigne405,
            'ras_ligne406' => $this->rasLigne406, 'ras_ligne411' => $this->rasLigne411,
            'ras_ligne412' => $this->rasLigne412, 'ras_ligne413' => $this->rasLigne413,
            'ras_ligne418' => $this->rasLigne418, 'ras_ligne419' => $this->rasLigne419,
            'ras_ligne425' => $this->rasLigne425,
            'its' => $this->its,
            'marge' => $this->marge,
            'marge_taxable' => $this->margeTaxable,
            'statut' => $this->statut,
            'statut_libelle' => $this->getStatutLibelle(),
            'date_validation' => $this->dateValidation,
            'valide_par' => $this->validePar,
            'date_creation' => $this->dateCreation,
            'date_modification' => $this->dateModification
        ];
    }
}
