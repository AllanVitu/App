/*
 * Applique le thème AVANT le premier rendu : sans cela, la page apparaîtrait
 * une fraction de seconde dans le mauvais thème.
 *
 * Un fichier, et non plus un script écrit dans index.html : la politique de
 * sécurité du contenu n'autorise que les scripts servis par l'application
 * (« script-src 'self' »). Un script en ligne l'obligeait à accepter
 * « 'unsafe-inline' », c'est-à-dire n'importe quel script qu'une injection
 * parviendrait à glisser dans la page. Chargé sans « defer » ni « async », il
 * reste bloquant : il s'exécute avant le premier rendu, comme avant.
 *
 * Dans public/, et non dans src/ : Vite le copie tel quel, sans empreinte ni
 * transformation, et il ne dépend d'aucun module.
 *
 * Le sombre étant la référence de cette direction visuelle, c'est la classe
 * « light » qui bascule — l'inverse de l'usage courant.
 */
;(function () {
  try {
    var stored = localStorage.getItem('theme') || 'system'
    var light =
      stored === 'light' ||
      (stored === 'system' && window.matchMedia('(prefers-color-scheme: light)').matches)
    document.documentElement.classList.toggle('light', light)

    // Densité et mouvement suivent le même chemin, et pour la même raison :
    // ils viennent du COMPTE, donc du réseau, et n'arrivent qu'après
    // « /auth/me ». Sans cette avance, la page s'affiche à la densité par
    // défaut puis saute — et joue ses animations d'entrée alors même qu'on les
    // a coupées.
    //
    // Le serveur reste la source de vérité : ce miroir est corrigé dès que la
    // réponse arrive (cf. stores/ui.js, applyDisplay).
    var densite = localStorage.getItem('densite')
    if (densite === 'compact' || densite === 'confortable') {
      document.documentElement.dataset.densite = densite
    }

    var mouvement = localStorage.getItem('mouvement')
    if (mouvement === 'reduit' || mouvement === 'normal') {
      document.documentElement.dataset.mouvement = mouvement
    }
  } catch (e) {
    /* stockage indisponible : les réglages par défaut s'appliquent */
  }
})()
