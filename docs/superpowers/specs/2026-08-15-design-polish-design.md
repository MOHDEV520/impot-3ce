# Design polish — 3CE FISCUS (sous-projet 1 : composants partagés + 3 écrans pilotes)

## Contexte

L'app (~30 pages PHP sous `pages/`) utilise déjà Tailwind avec une palette
`primary-*` dérivée du logo (#1d3a5f, voir `assets/css/input.css`). Le rendu
actuel souffre de deux problèmes, confirmés en lisant le code (pas seulement
les captures de `DESIGN/`) :

1. **Deux systèmes de navigation non harmonisés** :
   - `includes/header.php` (dashboard.php, clients.php, agents.php, …) :
     fond `bg-gray-100`, nav + sous-menu horizontal.
   - `includes/navbar-impots.php` (impots.php, achats.php, depenses.php,
     recapitulatif.php, sauvegarde.php, …) : fond `bg-slate-100`,
     header + breadcrumb + onglets contextuels au client.
   Ces deux navs ne partagent aucune classe commune ; l'agent qui navigue
   entre "Clients" et un dossier client voit deux styles différents.

2. **Finition plate/incohérente des composants** : cartes KPI en blocs de
   couleur pleine sans relief (`bg-primary-600` uni), pas d'échelle
   d'ombre/rayon cohérente, badges de statut (Complet/En cours/Incomplet/
   Retard) stylés au cas par cas, boutons sans état hover/focus harmonisé.

## Objectif de ce sous-projet

Poser une petite couche de composants CSS partagés puis l'appliquer à 3
écrans pilotes à fort trafic, **sans changer la palette de couleurs ni la
structure de données**. Les ~20 pages restantes seront traitées dans un
sous-projet de rollout séparé une fois ces 3 écrans validés.

Écrans pilotes :
- `pages/dashboard.php` (Accueil de l'agent) — utilise `includes/header.php`
- `pages/clients.php` (Liste des clients) — utilise `includes/header.php`
- `pages/impots.php` (Gestion des impôts) — utilise `includes/navbar-impots.php`

## Composants partagés à introduire

Dans `assets/css/input.css`, sous `@layer components` :

- `.card` — conteneur blanc, `rounded-xl`, `shadow-sm`, bordure `slate-100`,
  padding standard. Remplace les `bg-white rounded-xl shadow-sm p-6` répétés
  inline.
- `.card-stat` — variante carte KPI (remplace les blocs `bg-primary-600
  text-white px-6 py-4` pleins) : fond blanc ou pâle, valeur en grand,
  liseré/icône coloré selon le statut (neutre/alerte), au lieu d'un aplat de
  couleur.
- `.badge-complet` / `.badge-en-cours` / `.badge-incomplet` /
  `.badge-retard` — pastille de statut unifiée (remplace les
  implémentations ad hoc par page).
- `.btn-primary` / `.btn-secondary` / `.btn-danger` — boutons avec état
  hover/focus/disabled cohérent (remplace les classes Tailwind répétées
  inline sur chaque `<a>`/`<button>`).
- Échelle d'ombre/rayon documentée en commentaire dans le fichier (ex.
  `shadow-sm` + `rounded-xl` pour les cartes de contenu, `shadow-xl` réservé
  à la nav globale) pour que les pages suivantes du rollout appliquent la
  même règle sans deviner.

Aucune nouvelle couleur : on réutilise `primary-*`/`slate-*`/`gray-*` déjà
définis.

## Harmonisation des deux navs

`header.php` et `navbar-impots.php` gardent leurs responsabilités actuelles
(nav globale vs nav contextuelle client) mais partagent désormais :
- même fond de page (`bg-slate-100` partout — on aligne `header.php` sur
  `navbar-impots.php`, qui est le style le plus récent),
- même style d'onglet actif/inactif,
- même hauteur/ombre de barre de nav.

## Rollout (hors périmètre de ce sous-projet, pour référence)

Une fois les 3 pilotes validés en local par l'utilisateur : propager les
mêmes classes aux pages restantes par lot (achats/dépenses/annexes, puis
rapports/sauvegarde/agents/profil). Chaque lot = vérification manuelle en
navigateur (pas de suite de tests automatisée dans ce repo).

## Vérification

Pas de suite de tests. Pour chaque écran pilote : lancer le serveur
(XAMPP ou `npm start`), se connecter (`admin@cabinet.local` / `admin123`),
naviguer sur l'écran modifié et confirmer visuellement que :
- les cartes/tableaux/badges/boutons utilisent les nouvelles classes,
- rien n'est cassé fonctionnellement (liens, formulaires, sélecteurs de
  mois/année),
- l'impression (`@media print`) n'est pas dégradée sur les pages qui
  l'utilisent.
