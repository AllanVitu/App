# Plan d'animations

## Deux moteurs, une frontière

Le front utilise **deux** bibliothèques d'animation, et la frontière est
stricte :

| Moitié                                               | Moteur           | Poids      |
| ---------------------------------------------------- | ---------------- | ---------- |
| `AuthLayout` — connexion, inscription, mot de passe… | **anime.js 4.5** | 16,8 Ko gz |
| `AppLayout` — tableau de bord, modules, profil…      | **GSAP 3.15**    | 81,3 Ko gz |

Ce n'est pas une préférence de style. Les écrans publics n'ont besoin que
d'entrées simples ; les faire tourner sous GSAP obligeait le visiteur pas
encore identifié à télécharger 92 Ko pour animer un logotype. L'écran de
connexion est passé de **174 à 99 Ko compressés**.

Trois règles tiennent cette frontière :

1. **`main.js` n'importe aucune bibliothèque d'animation.** Elle l'était —
   pour enregistrer les plugins GSAP une fois pour toutes — et l'effet de bord
   était de placer GSAP dans le morceau d'entrée, donc dans toutes les pages.
2. **Aucun composant chargé par les DEUX mises en page n'importe GSAP.**
   `ToastHost`, monté dans `App.vue`, utilise donc anime.js. Un seul import de
   travers ramènerait les 92 Ko dans le chemin public — sans la moindre erreur
   pour le signaler.
3. **Chaque moteur a son morceau** (`manualChunks` dans `vite.config.js`).
   Laissés dans `vendor`, ils seraient chargés par tout le monde et la
   séparation n'existerait que sur le papier.

Un test de bout en bout (`e2e/tests/animation.spec.js`) vérifie ces trois
points à chaque exécution : il échoue si GSAP réapparaît sur `/connexion`.

### Le piège quand les deux cohabitent

**anime.js compte en MILLISECONDES, GSAP en SECONDES.** `duration: 0.5` dure
une demi-milliseconde chez anime.js : invisible, et sans erreur. D'où les
durées nommées dans `animations/anime.js`, à utiliser plutôt que des nombres
écrits à la main.

Second écart, plus grave : côté GSAP, toutes les animations sont des tweens
`from()` — ne rien jouer laisse l'interface dans son état final. Côté
anime.js, elles partent d'un état explicite `[départ, arrivée]` : ne rien
jouer laisserait les éléments **invisibles**. En mouvement réduit, il faut
donc POSER l'état final, ce que fait `useAnime` via l'attribut `data-anim`.

---

## Principes

L'application est un outil de travail, pas une page de présentation. Une
animation n'est retenue que si elle **explique quelque chose** : d'où vient un
élément, ce qui vient de changer, ce qui a été refusé. Tout le reste est du
bruit qui ralentit l'utilisateur qui revient dix fois par jour.

Quatre règles en découlent.

1. **Durées courtes.** 0,3 s à 0,6 s pour une entrée, 0,15 s à 0,25 s pour une
   sortie. Au-delà, l'interface donne l'impression de traîner.
2. **`fromTo()` pour toute animation d'entrée**, jamais d'état masqué en CSS.
   Deux raisons :
   - si l'animation ne joue pas — préférence système, erreur JS, plugin
     absent — l'interface reste dans son état final correct, sans code de
     secours, puisque rien n'a été masqué au préalable ;
   - `from()` **déduit** son état d'arrivée de la valeur courante au moment
     du rendu. Si l'élément a déjà été touché par une autre animation, il
     mémorise `opacity: 0` comme état d'arrivée et reste invisible pour
     toujours. Le bug s'est produit sur les cartes du tableau de bord ;
     écrire les deux extrémités le rend impossible.
   Ajouter `overwrite: 'auto'` sur les animations rejouables (listes
   rechargées, filtres) : la précédente est tuée au lieu de se superposer.
3. **`prefers-reduced-motion` respecté globalement**, dans `useGsap()` :
   le mouvement disparaît, la fonctionnalité reste.
4. **Un vocabulaire partagé.** Les courbes (`appEnter`, `appExit`,
   `appOvershoot`, `appBounce`, `appShake`) sont définies une seule fois dans
   `animations/gsap.js`. Deux écrans différents bougent de la même façon.

Le nettoyage passe par `gsap.context()` (composable `useGsap`) : au démontage
d'une vue, ses ScrollTriggers, Draggables et timelines sont révoqués.

---

## Plugins activés

| Plugin | Où | Ce que ça apporte |
|---|---|---|
| **Core (tweens, timelines)** | Partout | Entrées échelonnées des cartes, listes, champs de formulaire |
| **CustomEase** | `animations/gsap.js` | Les 3 courbes maison qui donnent leur signature aux mouvements |
| **CustomWiggle** | `LoginView`, `RegisterView`, `ItemFormModal` | Secousse du formulaire refusé : l'échec est perçu avant d'être lu |
| **CustomBounce** | `VerifyEmailView`, `ResetPasswordView` | Rebond de la pastille de succès |
| **DrawSVGPlugin** | Pastilles de succès | La coche se **trace** au lieu d'apparaître : marque la fin d'un parcours |
| **MorphSVGPlugin** | `AppTopbar` | Le soleil se transforme en lune : la bascule de thème devient un objet unique, pas deux icônes qui se remplacent |
| **SplitText** | `AuthLayout` | Titre du panneau de présentation révélé mot à mot |
| **ScrambleTextPlugin** | `DashboardView` | Le prénom se stabilise à l'arrivée — signale que la donnée vient d'être chargée |
| **Flip** | `ModuleView` | **Le plus utile de tous** : au changement de filtre, les lignes conservées glissent vers leur nouvelle position au lieu de sauter. On suit ce qui reste |
| **Draggable + InertiaPlugin** | `AppSidebar` (mobile) | Le tiroir se ferme au glissement, avec inertie : geste tactile attendu |
| **Observer** | `AppSidebar` (mobile) | Détection du geste de glissement vers la droite pour ouvrir le tiroir |
| **ScrollTrigger** | `ModuleView` | Les lignes au-delà du pli se révèlent à l'approche, sans animer ce qui est déjà visible |
| **ScrollToPlugin** | `ModuleView` | Retour en haut de liste animé au changement de page |
| **Physics2DPlugin** | `VerifyEmailView` | Gerbe de particules à la confirmation d'adresse — le seul moment franchement festif de l'application |
| **SlowMo** (EasePack) | `DashboardView` | Les compteurs défilent vite sur les valeurs intermédiaires et s'attardent sur le chiffre final, seul réellement lisible |
| **GSDevTools / MotionPathHelper** | Développement uniquement | Chargés dynamiquement si `import.meta.env.DEV`. `window.GSDevTools.create()` en console |

## Plugins enregistrés mais volontairement inemployés

| Plugin | Pourquoi |
|---|---|
| **ScrollSmoother** | Détourne le défilement natif. Sur des vues de données que l'on parcourt à la recherche d'une ligne précise, c'est une gêne, pas un agrément. Il impose de plus une structure DOM wrapper/content qui contraint tout le layout. Enregistré pour être disponible ; ne pas l'activer sur les écrans de listes |
| **MotionPathPlugin** | Aucun trajet courbe ne se justifie ici. Le déplacer pour le plaisir de l'utiliser produirait un mouvement décoratif que l'œil suit sans raison |
| **PixiPlugin / EaselPlugin** | Ponts vers Pixi.js et EaselJS, absents du projet. Sans effet |
| **PhysicsPropsPlugin** | Redondant avec `InertiaPlugin`, déjà employé pour le tiroir |
| **TextPlugin** | Recouvert par `ScrambleTextPlugin`, plus expressif au même endroit |

## Non disponible en Vue

`@gsap/react` / `useGSAP` est un hook React. L'équivalent est le composable
maison `composables/useGsap.js` : même contrat (contexte porté par la racine,
révocation au démontage), écrit avec `onMounted` / `onUnmounted`.

---

## Décor animé — retiré

`components/TechnicalDiagram.vue` dessinait au canvas des orbites, une
couronne graduée et des satellites en révolution lente, dans un coin du
tableau de bord. Il a été supprimé lors du partage GSAP / anime.js.

La raison tient en une phrase : **il n'encodait rien**. Aucune de ses valeurs
ne venait des données, aucune de ses rotations ne signalait un état. La place
qu'il occupait revient à l'état des modules, qui, lui, dit quelque chose.

Le composant reste dans l'historique Git si le besoin d'un décor se
représentait — mais il faudrait alors répondre d'abord à la question qu'il
n'avait jamais posée : que montre-t-il ?
