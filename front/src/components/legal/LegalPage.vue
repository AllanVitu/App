<script setup>
/**
 * La mise en page des textes légaux.
 *
 * Hors layout et sans garde : on ne peut pas demander d'accepter, ni même de
 * lire, un texte qu'il faudrait un compte pour atteindre. Les trois textes se
 * répondent — chacun mène aux deux autres.
 */
import BaseButton from '@/components/ui/BaseButton.vue'
import { useAuthStore } from '@/stores/auth'

defineProps({
  surtitre: { type: String, default: '' },
  titre: { type: String, required: true },
})

const auth = useAuthStore()

const TEXTES = [
  { name: 'terms', label: 'Conditions générales' },
  { name: 'privacy', label: 'Confidentialité' },
  { name: 'legal', label: 'Mentions légales' },
]
</script>

<template>
  <div class="mx-auto w-full max-w-2xl px-5 py-12">
    <p v-if="surtitre" class="label-caps">{{ surtitre }}</p>
    <h1 class="mt-2 text-2xl font-semibold">{{ titre }}</h1>

    <div class="mt-8 space-y-7 text-[0.88rem] leading-relaxed text-ink-2">
      <slot />
    </div>

    <nav
      aria-label="Textes légaux"
      class="mt-10 flex flex-wrap gap-x-4 gap-y-1 border-t border-line pt-6 text-[0.8rem] text-ink-3"
    >
      <RouterLink
        v-for="texte in TEXTES"
        :key="texte.name"
        :to="{ name: texte.name }"
        class="transition-colors hover:text-ink-2"
        exact-active-class="font-medium text-ink"
      >
        {{ texte.label }}
      </RouterLink>
    </nav>

    <div class="mt-6 flex flex-wrap gap-3">
      <BaseButton :to="auth.isAuthenticated ? { name: 'dashboard' } : { name: 'login' }">
        {{ auth.isAuthenticated ? 'Retour au tableau de bord' : 'Se connecter' }}
      </BaseButton>
      <BaseButton v-if="!auth.isAuthenticated" :to="{ name: 'register' }" variant="secondary">
        Créer un espace
      </BaseButton>
    </div>
  </div>
</template>
