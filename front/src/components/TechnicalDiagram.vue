<script setup>
/**
 * Diagramme technique décoratif : orbites concentriques, couronne graduée,
 * points en révolution lente.
 *
 * Remplace le dégradé WebGL coloré, incompatible avec une direction
 * monochrome sur fond papier. Le dessin au trait dit la même chose —
 * « il se passe quelque chose ici » — sans introduire de couleur.
 *
 * Canvas 2D plutôt que SVG : les orbites tournent en continu, et animer
 * quelques dizaines de tracés au trait coûte moins cher qu'autant de nœuds
 * DOM ré-évalués à chaque image.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'

import { prefersReducedMotion } from '@/animations/gsap'

const props = defineProps({
  /** Points en révolution autour du centre. */
  satellites: { type: Number, default: 7 },
  /** Vitesse globale ; 0 fige le dessin. */
  speed: { type: Number, default: 1 },
})

const canvas = ref(null)

let ctx = null
let raf = 0
let started = 0
let observer = null
let resizeObserver = null

/** Couleur du trait, lue sur le thème courant : le dessin suit clair/sombre. */
function strokeColor(alpha) {
  const ink = getComputedStyle(document.documentElement).getPropertyValue('--c-ink-3').trim()

  // --c-ink-3 est un hex ; on le convertit en rgba pour moduler l'opacité.
  const value = ink.replace('#', '')
  const r = parseInt(value.slice(0, 2), 16)
  const g = parseInt(value.slice(2, 4), 16)
  const b = parseInt(value.slice(4, 6), 16)

  return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

function resize() {
  if (!canvas.value) return

  const dpr = Math.min(window.devicePixelRatio || 1, 2)
  const { clientWidth: w, clientHeight: h } = canvas.value

  if (!w || !h) return

  canvas.value.width = Math.floor(w * dpr)
  canvas.value.height = Math.floor(h * dpr)
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
}

function draw(elapsed) {
  if (!canvas.value || !ctx) return

  const w = canvas.value.clientWidth
  const h = canvas.value.clientHeight
  const cx = w / 2
  const cy = h / 2
  // 2.75 et non 2.35 : les lettres cardinales sont tracées à 1,19 unité du
  // centre et débordaient du canvas, donc étaient rognées.
  const unit = Math.min(w, h) / 2.75

  ctx.clearRect(0, 0, w, h)
  ctx.lineWidth = 1

  // --- Couronne graduée : 72 traits, un tous les 5 degrés ------------------
  ctx.strokeStyle = strokeColor(0.5)
  for (let i = 0; i < 72; i++) {
    const angle = (i / 72) * Math.PI * 2
    const long = i % 9 === 0
    const r1 = unit * (long ? 0.95 : 0.98)
    const r2 = unit * 1.03

    ctx.beginPath()
    ctx.moveTo(cx + Math.cos(angle) * r1, cy + Math.sin(angle) * r1)
    ctx.lineTo(cx + Math.cos(angle) * r2, cy + Math.sin(angle) * r2)
    ctx.stroke()
  }

  // --- Orbites -------------------------------------------------------------
  const orbits = [
    { r: 0.34, dashed: true },
    { r: 0.58, dashed: false },
    { r: 0.82, dashed: true },
  ]

  for (const orbit of orbits) {
    ctx.strokeStyle = strokeColor(orbit.dashed ? 0.4 : 0.6)
    ctx.setLineDash(orbit.dashed ? [3, 5] : [])
    ctx.beginPath()
    ctx.arc(cx, cy, unit * orbit.r, 0, Math.PI * 2)
    ctx.stroke()
  }
  ctx.setLineDash([])

  // --- Axes ----------------------------------------------------------------
  ctx.strokeStyle = strokeColor(0.35)
  ctx.beginPath()
  ctx.moveTo(cx - unit * 1.12, cy)
  ctx.lineTo(cx + unit * 1.12, cy)
  ctx.moveTo(cx, cy - unit * 1.12)
  ctx.lineTo(cx, cy + unit * 1.12)
  ctx.stroke()

  // --- Sphère centrale : méridiens aplatis ---------------------------------
  const core = unit * 0.19
  ctx.strokeStyle = strokeColor(0.55)
  ctx.beginPath()
  ctx.arc(cx, cy, core, 0, Math.PI * 2)
  ctx.stroke()

  for (let i = 1; i <= 3; i++) {
    const squash = (i / 4) * core
    ctx.beginPath()
    ctx.ellipse(cx, cy, squash, core, 0, 0, Math.PI * 2)
    ctx.stroke()
    ctx.beginPath()
    ctx.ellipse(cx, cy, core, squash, 0, 0, Math.PI * 2)
    ctx.stroke()
  }

  ctx.fillStyle = strokeColor(0.9)
  ctx.beginPath()
  ctx.arc(cx, cy, unit * 0.035, 0, Math.PI * 2)
  ctx.fill()

  // --- Satellites ----------------------------------------------------------
  for (let i = 0; i < props.satellites; i++) {
    const orbit = orbits[i % orbits.length]
    // Périodes volontairement non harmoniques : la figure ne se répète jamais
    // à l'identique, ce qui évite l'effet de boucle mécanique.
    const period = 26 + i * 7.3
    const angle = (elapsed / period) * Math.PI * 2 + i * 1.7
    const r = unit * orbit.r

    ctx.fillStyle = strokeColor(i % 3 === 0 ? 0.85 : 0.5)
    ctx.beginPath()
    ctx.arc(cx + Math.cos(angle) * r, cy + Math.sin(angle) * r, i % 3 === 0 ? 3 : 2, 0, Math.PI * 2)
    ctx.fill()
  }

  // --- Points cardinaux ----------------------------------------------------
  ctx.fillStyle = strokeColor(0.75)
  ctx.font = '500 11px "Source Code Pro", monospace'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'

  const marks = [
    ['N', 0, -1],
    ['E', 1, 0],
    ['S', 0, 1],
    ['O', -1, 0],
  ]

  for (const [label, dx, dy] of marks) {
    ctx.fillText(label, cx + dx * unit * 1.19, cy + dy * unit * 1.19)
  }
}

function frame(now) {
  draw(((now - started) / 1000) * props.speed)
  raf = requestAnimationFrame(frame)
}

function start() {
  if (raf) return

  started = performance.now()
  raf = requestAnimationFrame(frame)
}

function stop() {
  cancelAnimationFrame(raf)
  raf = 0
}

function onVisibility() {
  document.hidden ? stop() : start()
}

onMounted(() => {
  ctx = canvas.value?.getContext('2d')
  if (!ctx) return

  resize()

  // Réglage « animations réduites » : une image fixe, sans boucle.
  if (prefersReducedMotion() || props.speed === 0) {
    draw(14)
  } else {
    // Rien n'est calculé quand le dessin est hors écran ou l'onglet masqué.
    observer = new IntersectionObserver(([entry]) => (entry.isIntersecting ? start() : stop()))
    observer.observe(canvas.value)
    document.addEventListener('visibilitychange', onVisibility)
  }

  resizeObserver = new ResizeObserver(() => {
    resize()
    if (!raf) draw(14)
  })
  resizeObserver.observe(canvas.value)
})

onBeforeUnmount(() => {
  stop()
  observer?.disconnect()
  resizeObserver?.disconnect()
  document.removeEventListener('visibilitychange', onVisibility)
})
</script>

<template>
  <canvas ref="canvas" class="size-full" aria-hidden="true" />
</template>
