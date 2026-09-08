# Plan d'animations

## Un moteur, servi en morceaux

Le front utilise **anime.js 4.5**, et lui seul.

Il en a utilisé deux. La frontière était stricte — anime.js pour `AuthLayout`,
GSAP pour `AppLayout` — parce que les écrans publics n'ont besoin que
d'entrées simples, et que les faire tourner sous GSAP obligeait un visiteur pas
encore identifié à télécharger 92 Ko pour animer un logotype.

Cette frontière a été supprimée, pour deux raisons.

D'abord elle coûtait de l'attention : un seul composant partagé entre les deux
mises en page qui importait GSAP suffisait à le ramener dans le chemin public,
sans erreur pour le signaler. C'est une règle qu'il fallait tenir dans la tête
à chaque nouveau composant.

Ensuite et surtout, elle n'était plus justifiée. anime.js 4.5 couvre
nativement les quatre plugins pour lesquels GSAP avait été retenu :

| Plugin GSAP                    | Remplacé par                     | Où                                 |
| ------------------------------ | -------------------------------- | ---------------------------------- |
| Flip                           | `createLayout`                   | `animations/layout.js`             |
| MorphSVGPlugin                 | `morphTo`                        | `ThemeToggle`                      |
| ScrollTrigger                  | `IntersectionObserver` **natif** | `animations/reveal.js`             |
| Draggable + Observer + Inertia | événements pointeur **natifs**   | `composables/useDrawerGestures.js` |

Les deux derniers ne passent volontairement par **aucune** bibliothèque. Un
observateur d'intersection ne travaille que lorsqu'un élément traverse le bord
de l'écran, là où ScrollTrigger recalcule à chaque défilement : pour « révéler
des lignes en approchant », c'est à la fois plus léger et plus juste. Et deux
gestes tactiles tiennent en soixante lignes d'événements passifs.

### Ce que ça pèse

| Écran        | Avant               | Après                |
| ------------ | ------------------- | -------------------- |
| `/connexion` | anime 16,8 Ko gz    | anime **19,6 Ko gz** |
| Application  | GSAP **81,3 Ko gz** | anime **19,6 Ko gz** |

L'écran de connexion paie 2,8 Ko de plus — le tronc commun a grossi de ce que
les nouveaux usages y ont ajouté. Chaque écran de l'application en économise
**62**. C'est le sens de l'échange, et il est assumé.

### Le découpage en lots, et pourquoi il est fragile

Le moteur est servi en **trois** morceaux (`manualChunks`, `vite.config.js`) :

| Lot            | Contenu                             | Chargé par                       |
| -------------- | ----------------------------------- | -------------------------------- |
| `anime`        | le tronc commun                     | toutes les pages                 |
| `anime-layout` | la mesure de mise en page (ex-Flip) | les vues à tableau, `ModuleView` |
| `anime-svg`    | morphing d'icône, tracé progressif  | bascule de thème, confirmations  |

Sans ce découpage, `/connexion` téléchargeait la mesure de mise en page —
6,2 Ko compressés — pour un écran qui n'anime aucune liste.

**Et cela se casse en silence** : il suffit qu'une règle disparaisse de
`manualChunks`, au fil d'une mise à jour de Vite ou d'un renommage interne
d'anime.js, pour que les trois lots redeviennent un seul. Aucune erreur, aucun
test rouge, juste 7 Ko de plus.

D'où `scripts/check-chunks.mjs`, lancé par `npm run check` : il suit la
fermeture transitive des imports depuis le point d'entrée, sur les octets
réellement émis. Il a été validé en cassant volontairement la règle de
découpage — un garde-fou qui ne peut pas échouer ne garde rien.

Ce contrôle **ne peut pas** vivre dans la suite Playwright : elle tourne contre
le serveur de développement, où Vite sert les modules un par un, sans lot.
L'invariant n'y existe pas.

### Le piège nº 1

**anime.js compte en MILLISECONDES.** GSAP comptait en secondes ; `duration:
0.5` dure ici une demi-milliseconde : invisible, et sans erreur pour le
signaler. D'où les durées nommées dans `animations/motion.js`, à utiliser
plutôt que des nombres écrits à la main.

### Le piège nº 2, plus grave

Les animations d'anime.js partent d'un état explicite `[départ, arrivée]`. Ne
rien jouer laisse donc les éléments à leur état de **départ**, c'est-à-dire
invisibles.

GSAP n'avait pas ce problème : ses tweens `from()` déduisaient leur arrivée de
l'état courant, et ne rien jouer laissait l'interface correcte.

En mouvement réduit, il faut donc **poser** l'état final, pas s'abstenir.
C'est le rôle de `settle()`, appelé par `useMotion` sur tout élément portant
l'attribut `data-anim`. Deux tests de bout en bout le vérifient — sur les
écrans publics et sur le tableau de bord — parce que la régression possible
ici est un écran de connexion entièrement vide.

---

## Principes

L'application est un outil de travail, pas une page de présentation. Une
animation n'est retenue que si elle **explique quelque chose** : d'où vient un
élément, ce qui vient de changer, ce qui a été refusé. Tout le reste est du
bruit qui ralentit l'utilisateur qui revient dix fois par jour.

1. **Durées courtes.** 300 à 620 ms pour une entrée, 150 à 250 ms pour une
   sortie. Au-delà, l'interface donne l'impression de traîner.
2. **Les deux extrémités explicites**, `[départ, arrivée]`, jamais un état
   masqué en CSS. Si l'animation ne joue pas, il ne reste rien à réparer : la
   feuille de style n'a rien caché.
3. **`prefers-reduced-motion` respecté partout**, et en posant l'état final —
   voir le piège nº 2. Le mouvement disparaît, la fonctionnalité reste.
4. **Une entrée ne se rejoue pas.** Le tableau de bord est remonté à chaque
   retour dessus. Rejouée à chaque fois, une cascade cesse d'aider le regard
   et devient un péage : on attend qu'elle finisse pour lire.
5. **Un vocabulaire partagé.** Les courbes (`appEnter`, `appExit`,
   `appOvershoot`, `appBounce`) et les durées sont définies une seule fois,
   dans `animations/motion.js`. Deux écrans différents bougent pareil.

Le nettoyage passe par `createScope({ root })` (composable `useMotion`) : au
démontage d'une vue, ses animations sont révoquées. Sans quoi une animation en
cours continuerait de toucher des nœuds retirés du document.

---

## Où le mouvement sert à quelque chose

| Geste                          | Où                                             | Ce que ça explique                                                                                                                                                                                                     |
| ------------------------------ | ---------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Cascade d'entrée               | `AuthLayout`, `DashboardView`                  | L'ordre de lecture de l'écran — une seule fois par session                                                                                                                                                             |
| **Glissement de mise en page** | `TicketsView`, `SupervisionView`, `ModuleView` | **Le plus utile de tous** : quand une carte change de colonne, elle GLISSE vers sa nouvelle place au lieu de disparaître ici et reparaître là. C'est ce qui fait qu'on suit du regard la carte qu'on vient de déplacer |
| Glissement à la main           | `BoardColumns` (tickets, supervision, design)  | Le geste direct : on prend une carte et on la pose ailleurs. Écrit en événements pointeur natifs (`useBoardDrag`), une seule mesure de mise en page au début du geste                                                  |
| Révélation au défilement       | `ModuleView`                                   | Les lignes au-delà du pli arrivent, sans réanimer ce qui est déjà lu                                                                                                                                                   |
| Secousse                       | `LoginView`, `RegisterView`, `ItemFormModal`   | Le refus est perçu avant d'être lu — le message peut être hors du champ de vision                                                                                                                                      |
| Morphing soleil → lune         | `ThemeToggle`                                  | Un seul objet se transforme : deux icônes qui se remplacent ne racontent rien                                                                                                                                          |
| Tracé progressif de la coche   | `VerifyEmailView`, `ResetPasswordView`         | La fin d'un parcours, pas juste un état                                                                                                                                                                                |
| Gerbe de particules            | `VerifyEmailView`                              | Le seul moment franchement festif de l'application, et il est mérité                                                                                                                                                   |
| Glissement du tiroir           | `AppSidebar` (mobile)                          | Le geste tactile attendu ; le bouton fonctionne sans lui                                                                                                                                                               |

---

## Deux décors retirés

**`components/TechnicalDiagram.vue`** dessinait au canvas des orbites et des
satellites en révolution lente, dans un coin du tableau de bord.

**La spirale d'`ModuleGallery.vue`** disposait les cinq tuiles de modules le
long d'une courbe d'Archimède, avec une bascule spirale/liste.

Même raison dans les deux cas : **ils n'encodaient rien**. Aucune valeur ne
venait des données, et la position d'une tuile sur la spirale ne disait ni
priorité, ni urgence, ni fréquence d'usage. La spirale coûtait en plus 570 px
de hauteur et imposait un carré au milieu d'une page en colonnes ; la grille
qui l'a remplacée dit la même chose en 140 px, et la position d'une tuile y
est stable d'une visite à l'autre — on finit par savoir où regarder sans lire.

Les deux composants restent dans l'historique Git. Y revenir demanderait de
répondre d'abord à la question qu'ils n'avaient jamais posée : que montrent-ils ?
