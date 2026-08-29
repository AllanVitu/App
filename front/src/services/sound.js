/**
 * ---------------------------------------------------------------------------
 * Moteur sonore
 *
 * Les sons sont SYNTHÉTISÉS à la volée par Web Audio : aucun fichier à
 * télécharger, aucune dépendance, et rien qui appartienne à quelqu'un
 * d'autre. Le coût total est celui de ce fichier — quelques kilo-octets.
 *
 * Trois règles gouvernent tout ce qui suit.
 *
 * 1. Rien ne sonne sans consentement explicite. Les navigateurs refusent de
 *    toute façon de démarrer un contexte audio avant un geste de
 *    l'utilisateur : l'écran d'entrée « avec le son / sans le son » est
 *    donc autant un choix de conception qu'une nécessité technique.
 * 2. Le son commente, il n'informe jamais seul. Une action réussie l'est
 *    aussi à l'écran : couper le son ne doit rien faire perdre.
 * 3. Discrétion. Des cues très courtes, à faible niveau, et un survol
 *    limité en fréquence — sinon un simple mouvement de souris mitraille.
 * ---------------------------------------------------------------------------
 */

const STORAGE_KEY = 'sound'

/** Niveau maître : volontairement bas, ces sons accompagnent un outil. */
const MASTER_GAIN = 0.22

/** Deux survols rapprochés ne déclenchent qu'un son. */
const HOVER_THROTTLE_MS = 70

let context = null
let master = null
let ambientNodes = null
let enabled = false
let unlocked = false
let lastHoverAt = 0

// -----------------------------------------------------------------------------
// Préférence
// -----------------------------------------------------------------------------

/**
 * Lit le choix mémorisé. `null` signifie « jamais demandé » : c'est ce qui
 * déclenche l'affichage de l'écran d'entrée.
 *
 * @returns {boolean|null}
 */
export function storedPreference() {
  try {
    const value = localStorage.getItem(STORAGE_KEY)

    return value === null ? null : value === 'on'
  } catch {
    // Navigation privée ou stockage bloqué : on redemandera, sans casser.
    return null
  }
}

function persist(value) {
  try {
    localStorage.setItem(STORAGE_KEY, value ? 'on' : 'off')
  } catch {
    /* le choix vaut alors pour la session courante seulement */
  }
}

export function isEnabled() {
  return enabled
}

/**
 * Suspension par zone.
 *
 * Les écrans publics — connexion, inscription, mot de passe oublié — sont
 * volontairement muets : on n'y accueille pas quelqu'un avec du son, et un
 * utilisateur qui avait activé le son en session ne doit pas le retrouver
 * en arrivant sur un écran d'identification.
 *
 * Distinct de `enabled` : la préférence de l'utilisateur est conservée, elle
 * est seulement ignorée le temps de la traversée.
 */
let suspended = false

export function setSuspended(value) {
  suspended = Boolean(value)

  if (suspended) stopAmbient()

  return suspended
}

// -----------------------------------------------------------------------------
// Contexte
// -----------------------------------------------------------------------------

/**
 * Crée le contexte audio. Doit être appelé DEPUIS un gestionnaire d'événement
 * utilisateur, sinon le navigateur le crée suspendu.
 */
function unlock() {
  if (unlocked) return true

  const AudioContextClass = window.AudioContext ?? window.webkitAudioContext

  if (!AudioContextClass) return false

  try {
    context = new AudioContextClass()
    master = context.createGain()
    master.gain.value = MASTER_GAIN
    master.connect(context.destination)
    unlocked = true
  } catch {
    return false
  }

  return true
}

/**
 * Active ou coupe le son, et mémorise le choix.
 *
 * @param {boolean} value
 * @param {{ persist?: boolean }} options
 */
export function setEnabled(value, { persist: shouldPersist = true } = {}) {
  enabled = Boolean(value)

  if (shouldPersist) persist(enabled)

  if (enabled) {
    unlock()
    // Un contexte créé hors geste utilisateur démarre suspendu.
    context?.resume?.()
  } else {
    stopAmbient()
  }

  return enabled
}

// -----------------------------------------------------------------------------
// Briques de synthèse
// -----------------------------------------------------------------------------

/**
 * Enveloppe exponentielle : une coupure franche produirait un « clic »
 * parasite bien plus audible que le son voulu.
 */
function envelope(gain, peak, attack, decay, startAt) {
  gain.gain.setValueAtTime(0.0001, startAt)
  gain.gain.exponentialRampToValueAtTime(peak, startAt + attack)
  gain.gain.exponentialRampToValueAtTime(0.0001, startAt + attack + decay)
}

/**
 * Une note simple : oscillateur, enveloppe, éventuel glissando.
 *
 * @param {{ type?: OscillatorType, from: number, to?: number, peak?: number,
 *           attack?: number, decay?: number, delay?: number }} spec
 */
function tone({ type = 'sine', from, to, peak = 0.5, attack = 0.004, decay = 0.09, delay = 0 }) {
  const startAt = context.currentTime + delay
  const oscillator = context.createOscillator()
  const gain = context.createGain()

  oscillator.type = type
  oscillator.frequency.setValueAtTime(from, startAt)

  if (to !== undefined) {
    oscillator.frequency.exponentialRampToValueAtTime(to, startAt + attack + decay)
  }

  envelope(gain, peak, attack, decay, startAt)

  oscillator.connect(gain).connect(master)
  oscillator.start(startAt)
  oscillator.stop(startAt + attack + decay + 0.02)
}

/**
 * Bruit filtré : ce qui donne sa matière au clic. Un oscillateur seul sonne
 * synthétique ; un souffle très court sonne mécanique.
 */
function noise({ peak = 0.3, decay = 0.05, frequency = 2400, q = 1.2, delay = 0 }) {
  const startAt = context.currentTime + delay
  const length = Math.ceil(context.sampleRate * (decay + 0.02))
  const buffer = context.createBuffer(1, length, context.sampleRate)
  const data = buffer.getChannelData(0)

  for (let i = 0; i < length; i++) {
    data[i] = Math.random() * 2 - 1
  }

  const source = context.createBufferSource()
  source.buffer = buffer

  const filter = context.createBiquadFilter()
  filter.type = 'bandpass'
  filter.frequency.value = frequency
  filter.Q.value = q

  const gain = context.createGain()
  envelope(gain, peak, 0.002, decay, startAt)

  source.connect(filter).connect(gain).connect(master)
  source.start(startAt)
  source.stop(startAt + decay + 0.02)
}

// -----------------------------------------------------------------------------
// Répertoire
//
// Chaque cue tient en quelques oscillateurs. Les fréquences suivent une
// gamme pentatonique : n'importe quelle combinaison reste consonante, y
// compris deux sons déclenchés en même temps.
// -----------------------------------------------------------------------------

const CUES = {
  /** Survol : le plus discret de tous, il se répète des centaines de fois. */
  hover: () => tone({ from: 1180, peak: 0.06, attack: 0.002, decay: 0.035 }),

  /** Clic : une impulsion et un souffle, comme un interrupteur. */
  click: () => {
    tone({ type: 'triangle', from: 620, to: 380, peak: 0.34, decay: 0.07 })
    noise({ peak: 0.14, decay: 0.035, frequency: 2600 })
  },

  /** Bascule : deux notes montantes ou descendantes selon l'état. */
  switchOn: () => {
    tone({ from: 587, peak: 0.24, decay: 0.06 })
    tone({ from: 880, peak: 0.2, decay: 0.09, delay: 0.055 })
  },
  switchOff: () => {
    tone({ from: 880, peak: 0.22, decay: 0.06 })
    tone({ from: 587, peak: 0.18, decay: 0.09, delay: 0.055 })
  },

  /** Ouverture d'un panneau : glissando montant. */
  open: () => {
    tone({ type: 'triangle', from: 330, to: 740, peak: 0.22, attack: 0.01, decay: 0.16 })
    noise({ peak: 0.06, decay: 0.09, frequency: 1400, delay: 0.01 })
  },

  /** Fermeture : le même mouvement, à l'envers. */
  close: () =>
    tone({ type: 'triangle', from: 660, to: 300, peak: 0.2, attack: 0.008, decay: 0.13 }),

  /** Succès : tierce ascendante, brève. */
  success: () => {
    tone({ from: 659, peak: 0.22, decay: 0.1 })
    tone({ from: 988, peak: 0.2, decay: 0.16, delay: 0.08 })
  },

  /** Erreur : deux notes basses et proches — dissonance volontaire. */
  error: () => {
    tone({ type: 'sawtooth', from: 196, peak: 0.14, decay: 0.13 })
    tone({ type: 'sawtooth', from: 185, peak: 0.12, decay: 0.17, delay: 0.02 })
  },

  /** Notification : discrète, deux tons courts. */
  notify: () => {
    tone({ from: 784, peak: 0.16, decay: 0.07 })
    tone({ from: 1046, peak: 0.14, decay: 0.1, delay: 0.06 })
  },

  /** Battement : navigation au clavier, changement de page. */
  tick: () => noise({ peak: 0.09, decay: 0.02, frequency: 3200, q: 2 }),

  /** Saisie : très bref, pour les compteurs et le texte animé. */
  type: () => tone({ type: 'square', from: 1500, peak: 0.02, attack: 0.001, decay: 0.014 }),
}

/**
 * Joue une cue. Sans effet si le son est coupé ou le nom inconnu — un appel
 * dans un composant ne doit jamais avoir à vérifier l'état du moteur.
 *
 * @param {keyof typeof CUES} name
 */
export function play(name) {
  if (!enabled || suspended || !CUES[name]) return

  if (!unlocked && !unlock()) return
  if (context.state === 'suspended') context.resume()

  // Le survol est limité en fréquence : un déplacement de souris traverse
  // parfois dix éléments en un dixième de seconde.
  if (name === 'hover') {
    const now = performance.now()
    if (now - lastHoverAt < HOVER_THROTTLE_MS) return
    lastHoverAt = now
  }

  try {
    CUES[name]()
  } catch {
    /* contexte fermé par le navigateur : sans conséquence */
  }
}

// -----------------------------------------------------------------------------
// Nappe d'ambiance
// -----------------------------------------------------------------------------

/**
 * Bourdon très grave et très bas en niveau, à la limite de l'audible.
 * Deux oscillateurs légèrement désaccordés produisent un battement lent qui
 * évite l'impression de son figé.
 */
export function startAmbient() {
  if (!enabled || ambientNodes) return
  if (!unlocked && !unlock()) return

  const gain = context.createGain()
  gain.gain.setValueAtTime(0.0001, context.currentTime)
  gain.gain.exponentialRampToValueAtTime(0.045, context.currentTime + 4)

  const filter = context.createBiquadFilter()
  filter.type = 'lowpass'
  filter.frequency.value = 320

  const oscillators = [110, 110.6, 165].map((frequency) => {
    const oscillator = context.createOscillator()
    oscillator.type = 'sine'
    oscillator.frequency.value = frequency
    oscillator.connect(filter)
    oscillator.start()

    return oscillator
  })

  filter.connect(gain).connect(master)

  ambientNodes = { gain, oscillators }
}

export function stopAmbient() {
  if (!ambientNodes) return

  const { gain, oscillators } = ambientNodes
  ambientNodes = null

  try {
    gain.gain.cancelScheduledValues(context.currentTime)
    gain.gain.setValueAtTime(gain.gain.value, context.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 1.2)

    // Arrêt différé : couper les oscillateurs avant la fin du fondu
    // produirait exactement le clic que l'enveloppe cherche à éviter.
    oscillators.forEach((oscillator) => oscillator.stop(context.currentTime + 1.4))
  } catch {
    /* contexte déjà fermé */
  }
}

/**
 * Branche les sons d'interface sur le document entier, par délégation.
 *
 * Un seul couple d'écouteurs pour toute l'application : les composants n'ont
 * rien à déclarer, et un bouton ajouté demain sonnera sans modification.
 * Les éléments qui portent leur propre son se signalent par `data-sound`.
 *
 * @returns {() => void} fonction de retrait
 */
export function bindInterfaceSounds() {
  const INTERACTIVE = 'button, a[href], [role="menuitem"], select, summary, .sound-hover'

  const onOver = (event) => {
    const target = event.target?.closest?.(INTERACTIVE)

    if (target && !target.disabled) play(target.dataset.sound ?? 'hover')
  }

  const onDown = (event) => {
    const target = event.target?.closest?.(INTERACTIVE)

    if (target && !target.disabled) play(target.dataset.soundClick ?? 'click')
  }

  document.addEventListener('pointerover', onOver, { passive: true })
  document.addEventListener('pointerdown', onDown, { passive: true })

  return () => {
    document.removeEventListener('pointerover', onOver)
    document.removeEventListener('pointerdown', onDown)
  }
}
