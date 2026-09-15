// Relais, application de bureau : les fenêtres, et ce qui tourne derrière.
//
// Le processus principal lance la pile locale (services/pile.js), puis ouvre
// une fenêtre sur http://127.0.0.1:<port>. Il ne fait confiance à rien de ce
// qui s'affiche : chaque fenêtre est isolée (sandbox, contextIsolation, pas de
// Node), ne navigue que sur l'origine locale, n'émet aucune requête ailleurs,
// et n'obtient aucune permission du navigateur hormis l'écriture dans le
// presse-papiers.
import { app, BrowserWindow, dialog, ipcMain, Menu, nativeTheme, Notification, safeStorage, screen, session, shell } from 'electron'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { lireMessage, listerMessages, surveiller } from './services/boite-envoi.js'
import { creerJournal } from './services/journal.js'
import { cheminsDonnees, cheminsProgrammes, demarrerPile, ecrireReglages, lireReglages } from './services/pile.js'
import { COOKIE_POSTE } from './services/serveur.js'

const ICI = dirname(fileURLToPath(import.meta.url))
const ECRANS = join(ICI, 'ecrans')

// Tout ce qui appartient à l'installation vit dans un seul dossier, local au
// poste : %LOCALAPPDATA%\Relais. Pas dans le profil itinérant (Roaming) — une
// base PostgreSQL n'a rien à faire dans une synchronisation de session.
const RACINE = join(process.env.LOCALAPPDATA ?? app.getPath('appData'), 'Relais')
const DONNEES = cheminsDonnees(join(RACINE, 'donnees'))

app.setPath('userData', join(RACINE, 'fenetre'))
app.setAppUserModelId('fr.relais.bureau')
app.enableSandbox()

const journal = creerJournal(join(DONNEES.journaux, 'relais.log'))

const programmes = cheminsProgrammes({
  emballee: app.isPackaged,
  ressources: process.resourcesPath,
  desktop: dirname(ICI),
})

const icone = app.isPackaged ? undefined : join(dirname(ICI), 'build', 'icon.ico')
const fondSombre = '#0a0a0c'
const fondClair = '#f0f0f2'

let pile = null
let fenetre = null
let demarrage = null
let boite = null
let arreterSurveillance = null
let arretEnCours = false
let arretTermine = false

// ---------------------------------------------------------------------------
// Ce qui peut s'afficher, et où
// ---------------------------------------------------------------------------

const estLocale = (url) => pile !== null && (url === pile.origine || String(url).startsWith(`${pile.origine}/`))

/** Les ressources qu'une fenêtre peut charger : l'origine locale, ses propres écrans, et rien d'autre. */
function requeteAutorisee(url) {
  return (
    estLocale(url) ||
    url.startsWith(pathToFileURL(ECRANS).href + '/') ||
    (pile !== null && url.startsWith(`blob:${pile.origine}/`)) ||
    url.startsWith('data:') ||
    url.startsWith('devtools:')
  )
}

/** Un lien vers l'extérieur s'ouvre dans le navigateur de l'utilisateur, jamais dans Relais. */
function ouvrirAilleurs(url) {
  if (estLocale(url)) {
    fenetre?.loadURL(url)

    return
  }

  try {
    const { protocol } = new URL(url)

    if (protocol === 'https:' || protocol === 'http:' || protocol === 'mailto:') {
      shell.openExternal(url)
    }
  } catch {
    // Adresse invalide : ignorée.
  }
}

function webPreferences(preload) {
  return {
    sandbox: true,
    contextIsolation: true,
    nodeIntegration: false,
    webSecurity: true,
    // Le correcteur d'Electron télécharge ses dictionnaires chez Google : une
    // requête qui quitterait le poste, contraire à ce que promet la politique
    // de confidentialité de cette édition.
    spellcheck: false,
    devTools: !app.isPackaged,
    ...(preload ? { preload: join(ECRANS, preload) } : {}),
  }
}

app.on('web-contents-created', (_, contenu) => {
  contenu.on('will-attach-webview', (evenement) => evenement.preventDefault())

  contenu.setWindowOpenHandler(({ url }) => {
    ouvrirAilleurs(url)

    return { action: 'deny' }
  })

  const retenir = (evenement, url) => {
    if (!estLocale(url)) {
      evenement.preventDefault()
      ouvrirAilleurs(url)
    }
  }

  contenu.on('will-navigate', retenir)
  contenu.on('will-redirect', retenir)
})

function durcirSession() {
  const ses = session.defaultSession

  ses.setSpellCheckerEnabled(false)
  ses.setPermissionRequestHandler((_, permission, rappel, details) =>
    rappel(permission === 'clipboard-sanitized-write' && estLocale(details.requestingUrl)),
  )
  ses.setPermissionCheckHandler((_, permission, origine) => permission === 'clipboard-sanitized-write' && estLocale(origine))

  // Au-delà de la CSP de la page : aucune requête, d'où qu'elle vienne dans la
  // fenêtre (préchargement, icône, extension), ne sort de l'origine locale.
  ses.webRequest.onBeforeRequest((details, rappel) => rappel({ cancel: !requeteAutorisee(details.url) }))

  ses.on('will-download', (_, element) => element.setSaveDialogOptions({ title: 'Enregistrer sous' }))
}

// ---------------------------------------------------------------------------
// Écran de démarrage
// ---------------------------------------------------------------------------

const depuis = (evenement, fenetreAttendue) =>
  fenetreAttendue !== null && !fenetreAttendue.isDestroyed() && evenement.sender === fenetreAttendue.webContents

function ouvrirDemarrage() {
  demarrage = new BrowserWindow({
    width: 460,
    height: 300,
    frame: false,
    resizable: false,
    maximizable: false,
    show: false,
    center: true,
    title: 'Relais',
    icon: icone,
    backgroundColor: fondSombre,
    webPreferences: webPreferences('demarrage.cjs'),
  })

  const pret = new Promise((resoudre) => demarrage.webContents.once('did-finish-load', resoudre))

  demarrage.loadFile(join(ECRANS, 'demarrage.html'))
  demarrage.once('ready-to-show', () => demarrage.show())
  demarrage.on('closed', () => {
    demarrage = null
  })

  return pret
}

function versDemarrage(canal, donnees) {
  if (demarrage && !demarrage.isDestroyed()) {
    demarrage.webContents.send(canal, donnees)
  }
}

ipcMain.handle('demarrage:action', (evenement, action) => {
  if (!depuis(evenement, demarrage)) {
    return
  }

  if (action === 'journal') {
    shell.openPath(journal.fichier)
  } else if (action === 'quitter') {
    app.quit()
  }
})

function expliquer(erreur) {
  switch (erreur.code) {
    case 'COFFRE_ILLISIBLE':
      return {
        titre: 'Le coffre ne s’ouvre pas avec ce compte',
        detail: 'Les secrets de cette installation sont scellés par Windows pour le compte qui l’a créée. Ouvrez Relais depuis ce compte-là.',
      }
    case 'COFFRE_PERDU':
      return {
        titre: 'Le coffre de cette installation a disparu',
        detail: 'La base existe encore, mais les secrets qui l’ouvrent ont été supprimés. Rien n’a été modifié : restaurez le fichier coffre.bin, ou déplacez le dossier de données pour repartir de zéro.',
      }
    default:
      return {
        titre: 'Relais n’a pas pu démarrer',
        detail: `${String(erreur.message).split('\n')[0]} Le journal contient le détail.`,
      }
  }
}

// ---------------------------------------------------------------------------
// Fenêtre principale
// ---------------------------------------------------------------------------

/** La dernière position, si elle tombe encore sur un écran branché. */
function positionMemorisee() {
  const etat = lireReglages(DONNEES.reglages).fenetre ?? {}
  const largeur = Number.isInteger(etat.largeur) ? etat.largeur : 1360
  const hauteur = Number.isInteger(etat.hauteur) ? etat.hauteur : 860

  const visible =
    Number.isInteger(etat.x) &&
    Number.isInteger(etat.y) &&
    screen.getAllDisplays().some(({ workArea: z }) => etat.x < z.x + z.width - 80 && etat.x + largeur > z.x + 80 && etat.y >= z.y - 10 && etat.y < z.y + z.height - 80)

  return { width: largeur, height: hauteur, ...(visible ? { x: etat.x, y: etat.y } : {}), maximisee: etat.maximisee === true }
}

function memoriserPosition() {
  if (!fenetre || fenetre.isDestroyed()) {
    return
  }

  const { x, y, width, height } = fenetre.getNormalBounds()
  const reglages = lireReglages(DONNEES.reglages)

  ecrireReglages(DONNEES.reglages, { ...reglages, fenetre: { x, y, largeur: width, hauteur: height, maximisee: fenetre.isMaximized() } })
}

function ouvrirFenetre() {
  const { maximisee, ...position } = positionMemorisee()

  fenetre = new BrowserWindow({
    ...position,
    minWidth: 960,
    minHeight: 600,
    show: false,
    title: 'Relais',
    icon: icone,
    autoHideMenuBar: true,
    backgroundColor: nativeTheme.shouldUseDarkColors ? fondSombre : fondClair,
    webPreferences: webPreferences(),
  })

  if (maximisee) {
    fenetre.maximize()
  }

  fenetre.once('ready-to-show', () => {
    fenetre.show()
    demarrage?.close()
  })

  fenetre.on('close', memoriserPosition)
  fenetre.on('closed', () => {
    fenetre = null
    app.quit()
  })

  fenetre.loadURL(`${pile.origine}/`)
}

// ---------------------------------------------------------------------------
// Boîte d'envoi
// ---------------------------------------------------------------------------

function ouvrirBoite(selection) {
  if (!pile) {
    return
  }

  if (boite && !boite.isDestroyed()) {
    if (selection) {
      boite.webContents.send('boite:selection', selection)
    }

    boite.show()
    boite.focus()

    return
  }

  boite = new BrowserWindow({
    width: 920,
    height: 640,
    minWidth: 640,
    minHeight: 420,
    show: false,
    title: 'Boîte d’envoi — Relais',
    icon: icone,
    autoHideMenuBar: true,
    backgroundColor: nativeTheme.shouldUseDarkColors ? fondSombre : fondClair,
    webPreferences: webPreferences('boite-envoi.cjs'),
  })

  boite.loadFile(join(ECRANS, 'boite-envoi.html'), selection ? { query: { message: selection } } : undefined)
  boite.once('ready-to-show', () => boite.show())
  boite.on('closed', () => {
    boite = null
  })
}

ipcMain.handle('boite:lister', (evenement) => (depuis(evenement, boite) && pile ? listerMessages(pile.dossiers.boiteEnvoi) : []))

ipcMain.handle('boite:lire', (evenement, id) => (depuis(evenement, boite) && pile ? lireMessage(pile.dossiers.boiteEnvoi, String(id)) : null))

ipcMain.handle('boite:ouvrir', (evenement, url) => {
  if (!depuis(evenement, boite) || typeof url !== 'string') {
    return
  }

  // Un lien de l'application (choisir un nouveau mot de passe, rejoindre un
  // espace) s'ouvre dans la fenêtre de Relais : ailleurs, le cookie du poste
  // manque, et le serveur local refuserait.
  if (estLocale(url)) {
    fenetre?.loadURL(url)
    fenetre?.show()
    fenetre?.focus()
  } else {
    ouvrirAilleurs(url)
  }
})

function signalerMessage(id) {
  boite?.webContents.send('boite:nouveau', id)

  if (!Notification.isSupported()) {
    return
  }

  const message = lireMessage(pile.dossiers.boiteEnvoi, id)
  const notification = new Notification({
    title: 'Nouvel e-mail dans la boîte d’envoi',
    body: message?.sujet ?? '',
    icon: icone,
  })

  notification.on('click', () => ouvrirBoite(id))
  notification.show()
}

// ---------------------------------------------------------------------------
// Menu
// ---------------------------------------------------------------------------

function aPropos() {
  let versions = {}

  try {
    versions = JSON.parse(readFileSync(join(programmes.php, '..', 'versions.json'), 'utf8'))
  } catch {
    // Absent en développement si runtime/ n'a pas été préparé par le script.
  }

  dialog.showMessageBox(fenetre ?? undefined, {
    type: 'info',
    title: 'À propos de Relais',
    message: `Relais ${app.getVersion()}`,
    detail: [
      'Vos données restent sur cet ordinateur.',
      '',
      `Dossier : ${RACINE}`,
      `PHP ${versions.php ?? '?'} · PostgreSQL ${versions.postgres?.split('-')[0] ?? '?'} · Electron ${process.versions.electron}`,
    ].join('\n'),
    buttons: ['Fermer'],
    noLink: true,
  })
}

function installerMenu() {
  const separateur = { type: 'separator' }

  Menu.setApplicationMenu(
    Menu.buildFromTemplate([
      {
        label: '&Fichier',
        submenu: [
          { label: 'Boîte d’envoi', accelerator: 'CmdOrCtrl+Shift+E', click: () => ouvrirBoite() },
          separateur,
          { label: 'Ouvrir le dossier des données', click: () => shell.openPath(DONNEES.racine) },
          { label: 'Ouvrir le journal', click: () => shell.openPath(journal.fichier) },
          separateur,
          { label: 'Quitter', accelerator: 'CmdOrCtrl+Q', role: 'quit' },
        ],
      },
      {
        label: '&Édition',
        submenu: [
          { label: 'Annuler', role: 'undo' },
          { label: 'Rétablir', role: 'redo' },
          separateur,
          { label: 'Couper', role: 'cut' },
          { label: 'Copier', role: 'copy' },
          { label: 'Coller', role: 'paste' },
          { label: 'Tout sélectionner', role: 'selectAll' },
        ],
      },
      {
        label: '&Affichage',
        submenu: [
          { label: 'Actualiser', role: 'reload' },
          separateur,
          { label: 'Zoom avant', role: 'zoomIn' },
          { label: 'Zoom arrière', role: 'zoomOut' },
          { label: 'Taille réelle', role: 'resetZoom' },
          separateur,
          { label: 'Plein écran', role: 'togglefullscreen' },
          ...(app.isPackaged ? [] : [separateur, { label: 'Outils de développement', role: 'toggleDevTools' }]),
        ],
      },
      {
        label: 'Aid&e',
        submenu: [{ label: 'À propos de Relais', click: aPropos }],
      },
    ]),
  )
}

// ---------------------------------------------------------------------------
// Cycle de vie
// ---------------------------------------------------------------------------

async function lancer() {
  installerMenu()
  durcirSession()
  await ouvrirDemarrage()

  try {
    if (!safeStorage.isEncryptionAvailable()) {
      throw new Error('La protection des données de Windows (DPAPI) est indisponible : le coffre ne peut pas être ouvert.')
    }

    pile = await demarrerPile({
      programmes,
      racine: DONNEES.racine,
      journal,
      production: app.isPackaged,
      sceller: (texte) => safeStorage.encryptString(texte),
      desceller: (octets) => safeStorage.decryptString(octets),
      surEtape: ({ message }) => versDemarrage('demarrage:etape', { message }),
    })

    // Session uniquement (aucune date d'expiration) : il meurt avec la fenêtre.
    await session.defaultSession.cookies.set({
      url: pile.origine,
      name: COOKIE_POSTE,
      value: pile.jetonPoste,
      path: '/',
      httpOnly: true,
      sameSite: 'strict',
    })

    arreterSurveillance = surveiller(pile.dossiers.boiteEnvoi, signalerMessage)
    ouvrirFenetre()
  } catch (erreur) {
    journal.erreur('lancement', erreur.stack ?? erreur.message)
    versDemarrage('demarrage:erreur', expliquer(erreur))
  }
}

async function arreterTout() {
  arreterSurveillance?.()

  if (pile) {
    await pile.arreter()
  }
}

if (!app.requestSingleInstanceLock()) {
  // Une seconde instance démarrerait une seconde base sur les mêmes fichiers.
  app.quit()
} else {
  app.on('second-instance', () => {
    const cible = fenetre ?? demarrage

    if (cible) {
      if (cible.isMinimized()) {
        cible.restore()
      }

      cible.show()
      cible.focus()
    }
  })

  app.on('before-quit', (evenement) => {
    if (arretTermine) {
      return
    }

    evenement.preventDefault()

    if (arretEnCours) {
      return
    }

    arretEnCours = true
    memoriserPosition()

    for (const f of BrowserWindow.getAllWindows()) {
      f.hide()
    }

    arreterTout()
      .catch((erreur) => journal.erreur('arret', erreur.message))
      .finally(() => {
        arretTermine = true
        app.quit()
      })
  })

  // Fermeture de session Windows : pas le temps d'attendre, mais la base
  // reçoit au moins sa demande d'arrêt rapide.
  app.on('session-end', () => {
    arreterTout().catch(() => {})
  })

  app.on('window-all-closed', () => app.quit())

  app.whenReady().then(lancer)
}
