<script setup>
/**
 * Dégradé animé façon « mesh gradient », rendu en WebGL.
 *
 * Pourquoi pas le paquet `shadergradient` : il est publié pour React
 * (`react` en peerDependency, rendu via react-three-fiber). L'embarquer
 * imposerait d'ajouter React + react-dom + three à une application Vue,
 * pour un fond décoratif. Le même rendu est obtenu ici par un shader écrit
 * à la main : aucune dépendance, quelques kilo-octets.
 *
 * Les props reprennent la nomenclature de ShaderGradient afin que les
 * réglages d'un preset se transposent directement. Correspondances :
 *   uSpeed / uDensity / uFrequency / uAmplitude / uStrength -> bruit simplex
 *   color1..3, brightness, grain                            -> colorimétrie
 *   rotationZ, cameraZoom, cDistance                        -> cadrage 2D
 * Les paramètres purement 3D (envPreset, reflection, fov…) n'ont pas
 * d'équivalent et sont ignorés.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { prefersReducedMotion } from '@/animations/gsap'

const props = defineProps({
  color1: { type: String, default: '#207bd6' },
  color2: { type: String, default: '#910aff' },
  color3: { type: String, default: '#af38ff' },
  brightness: { type: Number, default: 1.5 },
  uSpeed: { type: Number, default: 0.3 },
  uDensity: { type: Number, default: 0.8 },
  uFrequency: { type: Number, default: 5.5 },
  uAmplitude: { type: Number, default: 7 },
  uStrength: { type: Number, default: 0.4 },
  rotationZ: { type: Number, default: 140 },
  cameraZoom: { type: Number, default: 12.5 },
  cDistance: { type: Number, default: 1.5 },
  grain: { type: String, default: 'on' },
  animate: { type: String, default: 'on' },
})

const canvas = ref(null)
const failed = ref(false)

let gl = null
let program = null
let raf = 0
let startedAt = 0
let uniforms = {}
let observer = null

/** #rrggbb -> [r, g, b] normalisé. */
function toRgb(hex) {
  const value = hex.replace('#', '')
  const full = value.length === 3 ? value.replace(/./g, (c) => c + c) : value
  const int = Number.parseInt(full, 16)

  return [((int >> 16) & 255) / 255, ((int >> 8) & 255) / 255, (int & 255) / 255]
}

const VERTEX_SHADER = `
attribute vec2 aPosition;
void main() {
  gl_Position = vec4(aPosition, 0.0, 1.0);
}
`

/**
 * Bruit simplex 3D (implémentation Ashima/Gustavson, domaine public).
 * Le champ de bruit est échantillonné deux fois : une première passe donne
 * la forme générale, la seconde la déforme — c'est ce qui produit
 * l'ondulation lente caractéristique.
 */
const FRAGMENT_SHADER = `
precision highp float;

uniform vec2  uResolution;
uniform float uTime;
uniform vec3  uColor1;
uniform vec3  uColor2;
uniform vec3  uColor3;
uniform float uBrightness;
uniform float uDensity;
uniform float uFrequency;
uniform float uAmplitude;
uniform float uStrength;
uniform float uRotation;
uniform float uZoom;
uniform float uGrain;

vec3 mod289(vec3 x) { return x - floor(x * (1.0 / 289.0)) * 289.0; }
vec4 mod289(vec4 x) { return x - floor(x * (1.0 / 289.0)) * 289.0; }
vec4 permute(vec4 x) { return mod289(((x * 34.0) + 1.0) * x); }
vec4 taylorInvSqrt(vec4 r) { return 1.79284291400159 - 0.85373472095314 * r; }

float snoise(vec3 v) {
  const vec2 C = vec2(1.0 / 6.0, 1.0 / 3.0);
  const vec4 D = vec4(0.0, 0.5, 1.0, 2.0);

  vec3 i  = floor(v + dot(v, C.yyy));
  vec3 x0 = v - i + dot(i, C.xxx);

  vec3 g = step(x0.yzx, x0.xyz);
  vec3 l = 1.0 - g;
  vec3 i1 = min(g.xyz, l.zxy);
  vec3 i2 = max(g.xyz, l.zxy);

  vec3 x1 = x0 - i1 + C.xxx;
  vec3 x2 = x0 - i2 + C.yyy;
  vec3 x3 = x0 - D.yyy;

  i = mod289(i);
  vec4 p = permute(permute(permute(
             i.z + vec4(0.0, i1.z, i2.z, 1.0))
           + i.y + vec4(0.0, i1.y, i2.y, 1.0))
           + i.x + vec4(0.0, i1.x, i2.x, 1.0));

  float n_ = 0.142857142857;
  vec3 ns = n_ * D.wyz - D.xzx;

  vec4 j = p - 49.0 * floor(p * ns.z * ns.z);

  vec4 x_ = floor(j * ns.z);
  vec4 y_ = floor(j - 7.0 * x_);

  vec4 x = x_ * ns.x + ns.yyyy;
  vec4 y = y_ * ns.x + ns.yyyy;
  vec4 h = 1.0 - abs(x) - abs(y);

  vec4 b0 = vec4(x.xy, y.xy);
  vec4 b1 = vec4(x.zw, y.zw);

  vec4 s0 = floor(b0) * 2.0 + 1.0;
  vec4 s1 = floor(b1) * 2.0 + 1.0;
  vec4 sh = -step(h, vec4(0.0));

  vec4 a0 = b0.xzyw + s0.xzyw * sh.xxyy;
  vec4 a1 = b1.xzyw + s1.xzyw * sh.zzww;

  vec3 p0 = vec3(a0.xy, h.x);
  vec3 p1 = vec3(a0.zw, h.y);
  vec3 p2 = vec3(a1.xy, h.z);
  vec3 p3 = vec3(a1.zw, h.w);

  vec4 norm = taylorInvSqrt(vec4(dot(p0, p0), dot(p1, p1), dot(p2, p2), dot(p3, p3)));
  p0 *= norm.x; p1 *= norm.y; p2 *= norm.z; p3 *= norm.w;

  vec4 m = max(0.6 - vec4(dot(x0, x0), dot(x1, x1), dot(x2, x2), dot(x3, x3)), 0.0);
  m = m * m;

  return 42.0 * dot(m * m, vec4(dot(p0, x0), dot(p1, x1), dot(p2, x2), dot(p3, x3)));
}

/** Grain : bruit blanc figé sur la grille de pixels, atténue les bandes. */
float hash(vec2 p) {
  return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453123);
}

void main() {
  // Repère centré, indépendant du rapport d'aspect.
  vec2 uv = (gl_FragCoord.xy - 0.5 * uResolution) / min(uResolution.x, uResolution.y);
  uv *= uZoom;

  float c = cos(uRotation);
  float s = sin(uRotation);
  uv = mat2(c, -s, s, c) * uv;

  float t = uTime;

  // Première passe : structure d'ensemble.
  float base = snoise(vec3(uv * uDensity, t * 0.35));

  // Seconde passe, déformée par la première : ondulation organique.
  float detail = snoise(vec3(
    uv * uDensity * 1.9 + base * uStrength,
    t * 0.22 + base * 0.4
  ));

  // Masque sphérique : concentre la matière au centre, comme le type
  // « sphere » de ShaderGradient.
  float radius = length(uv);
  float blob = smoothstep(1.35, 0.0, radius + base * uStrength * 0.35);

  float mixA = clamp(base * 0.5 + 0.5, 0.0, 1.0);
  float mixB = clamp(detail * 0.5 + 0.5, 0.0, 1.0);

  vec3 color = mix(uColor1, uColor2, smoothstep(0.15, 0.85, mixA));
  color = mix(color, uColor3, smoothstep(0.35, 1.0, mixB) * 0.85);

  // Éclairage directionnel simulé par le gradient du bruit.
  float light = 0.55 + 0.45 * sin(base * uFrequency * 0.35 + t * 0.4);
  color *= mix(0.75, 1.25, light) * uBrightness;

  // Assombrissement des bords : évite le rendu « à plat ».
  color *= mix(0.35, 1.0, blob);
  color += pow(blob, 3.0) * 0.06 * uAmplitude * 0.1;

  if (uGrain > 0.5) {
    color += (hash(gl_FragCoord.xy) - 0.5) * 0.045;
  }

  gl_FragColor = vec4(clamp(color, 0.0, 1.0), 1.0);
}
`

function compile(context, type, source) {
  const shader = context.createShader(type)
  context.shaderSource(shader, source)
  context.compileShader(shader)

  if (!context.getShaderParameter(shader, context.COMPILE_STATUS)) {
    const log = context.getShaderInfoLog(shader)
    context.deleteShader(shader)
    throw new Error('Compilation du shader impossible : ' + log)
  }

  return shader
}

function resize() {
  if (!gl || !canvas.value) return

  // Plafonné à 2 : au-delà, le coût de remplissage double sans gain visible.
  const dpr = Math.min(window.devicePixelRatio || 1, 2)
  const width = Math.floor(canvas.value.clientWidth * dpr)
  const height = Math.floor(canvas.value.clientHeight * dpr)

  if (canvas.value.width === width && canvas.value.height === height) return

  canvas.value.width = width
  canvas.value.height = height
  gl.viewport(0, 0, width, height)
}

function applyColors() {
  if (!gl || !program) return

  gl.useProgram(program)
  gl.uniform3fv(uniforms.uColor1, toRgb(props.color1))
  gl.uniform3fv(uniforms.uColor2, toRgb(props.color2))
  gl.uniform3fv(uniforms.uColor3, toRgb(props.color3))
  gl.uniform1f(uniforms.uBrightness, props.brightness)
  gl.uniform1f(uniforms.uDensity, props.uDensity)
  gl.uniform1f(uniforms.uFrequency, props.uFrequency)
  gl.uniform1f(uniforms.uAmplitude, props.uAmplitude)
  gl.uniform1f(uniforms.uStrength, props.uStrength)
  gl.uniform1f(uniforms.uRotation, (props.rotationZ * Math.PI) / 180)
  gl.uniform1f(uniforms.uZoom, (props.cDistance * 12.5) / Math.max(props.cameraZoom, 0.001))
  gl.uniform1f(uniforms.uGrain, props.grain === 'on' ? 1 : 0)
}

function render(now) {
  if (!gl || !program) return

  resize()

  const elapsed = (now - startedAt) / 1000
  gl.uniform1f(uniforms.uTime, elapsed * props.uSpeed)
  gl.uniform2f(uniforms.uResolution, canvas.value.width, canvas.value.height)
  gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4)

  raf = requestAnimationFrame(render)
}

/** Rendu figé : une seule image, sans boucle. */
function renderStill() {
  resize()
  gl.uniform1f(uniforms.uTime, 12)
  gl.uniform2f(uniforms.uResolution, canvas.value.width, canvas.value.height)
  gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4)
}

function start() {
  if (raf) return

  startedAt = performance.now()
  raf = requestAnimationFrame(render)
}

function stop() {
  cancelAnimationFrame(raf)
  raf = 0
}

onMounted(() => {
  try {
    gl = canvas.value.getContext('webgl', {
      antialias: false,
      alpha: false,
      powerPreference: 'low-power',
    })

    if (!gl) throw new Error('WebGL indisponible')

    program = gl.createProgram()
    gl.attachShader(program, compile(gl, gl.VERTEX_SHADER, VERTEX_SHADER))
    gl.attachShader(program, compile(gl, gl.FRAGMENT_SHADER, FRAGMENT_SHADER))
    gl.linkProgram(program)

    if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
      throw new Error(gl.getProgramInfoLog(program) ?? 'Édition de liens du programme impossible')
    }

    gl.useProgram(program)

    // Deux triangles couvrant tout l'écran.
    const buffer = gl.createBuffer()
    gl.bindBuffer(gl.ARRAY_BUFFER, buffer)
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), gl.STATIC_DRAW)

    const position = gl.getAttribLocation(program, 'aPosition')
    gl.enableVertexAttribArray(position)
    gl.vertexAttribPointer(position, 2, gl.FLOAT, false, 0, 0)

    for (const name of [
      'uResolution', 'uTime', 'uColor1', 'uColor2', 'uColor3', 'uBrightness',
      'uDensity', 'uFrequency', 'uAmplitude', 'uStrength', 'uRotation', 'uZoom', 'uGrain',
    ]) {
      uniforms[name] = gl.getUniformLocation(program, name)
    }

    applyColors()

    // Animation coupée si l'utilisateur a demandé la réduction des
    // mouvements : une image fixe, sans boucle de rendu.
    if (props.animate !== 'on' || prefersReducedMotion()) {
      renderStill()

      return
    }

    // Rien n'est calculé quand le canvas est hors écran ou l'onglet masqué :
    // un fond décoratif ne doit pas consommer de GPU en arrière-plan.
    observer = new IntersectionObserver(([entry]) => (entry.isIntersecting ? start() : stop()))
    observer.observe(canvas.value)

    document.addEventListener('visibilitychange', onVisibilityChange)
  } catch (error) {
    // Pilote logiciel, WebGL désactivé, machine virtuelle… : on retombe sur
    // le dégradé CSS, l'écran reste utilisable.
    failed.value = true
    console.warn('[ShaderBackground]', error.message)
  }
})

function onVisibilityChange() {
  document.hidden ? stop() : start()
}

watch(
  () => [props.color1, props.color2, props.color3, props.brightness, props.grain],
  () => applyColors(),
)

onBeforeUnmount(() => {
  stop()
  observer?.disconnect()
  document.removeEventListener('visibilitychange', onVisibilityChange)

  // Libération explicite du contexte : les contextes WebGL sont une
  // ressource limitée (une poignée par page).
  gl?.getExtension('WEBGL_lose_context')?.loseContext()
  gl = null
  program = null
})
</script>

<template>
  <!-- Repli CSS : même palette, sans WebGL. -->
  <div
    v-if="failed"
    class="absolute inset-0"
    :style="{ background: `radial-gradient(circle at 30% 30%, ${color3}, ${color2} 45%, ${color1})` }"
    aria-hidden="true"
  />

  <canvas
    v-else
    ref="canvas"
    class="absolute inset-0 size-full"
    aria-hidden="true"
  />
</template>
