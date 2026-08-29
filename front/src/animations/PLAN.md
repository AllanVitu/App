# Plan d'animations

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

## Décor animé

`components/TechnicalDiagram.vue` : orbites concentriques, couronne graduée
au demi-degré, sphère à méridiens et satellites en révolution lente. Dessiné
au canvas 2D, sans aucune dépendance.

**Ce qu'il a remplacé, et pourquoi.** Une première version utilisait un
dégradé WebGL coloré reproduisant *ShaderGradient* — dont le paquet officiel
est publié pour React et imposerait d'ajouter React, react-dom et three à une
application Vue. La direction visuelle retenue ensuite (monochrome sur fond
papier) rendait ce dégradé incohérent : un aplat violet-bleu au milieu d'une
interface à l'encre. Le dessin au trait dit la même chose — « il se passe
quelque chose ici » — dans le vocabulaire de la page.

Canvas plutôt que SVG : la figure tourne en continu, et animer quelques
dizaines de tracés coûte moins cher que le même nombre de nœuds DOM
réévalués à chaque image.

Garde-fous : rendu suspendu hors écran (`IntersectionObserver`) et onglet
masqué (`visibilitychange`), densité de pixels plafonnée à 2, image fixe si
`prefers-reduced-motion`, périodes orbitales non harmoniques pour que la
figure ne se répète jamais à l'identique.

La couleur du trait est lue sur `--c-ink-3` à chaque image : le dessin suit
le thème clair/sombre sans configuration.

Emplacements : panneau de présentation de l'authentification (grand format,
atténué), en-tête du tableau de bord (petit format, masqué sous `md` où la
largeur doit aller au contenu).
