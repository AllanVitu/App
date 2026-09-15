<script setup>
/**
 * Politique de confidentialité — l'information due au titre des articles 13
 * et 14 du RGPD.
 *
 * Chaque affirmation de cette page est tenue par le code : les durées par les
 * purges du worker, les droits par les écrans qui les exercent (Profil :
 * rectifier, exporter, supprimer). Une phrase qui ne correspondrait à rien
 * serait pire qu'une phrase absente.
 */
import LegalPage from '@/components/legal/LegalPage.vue'
import { CONSERVATION, EDITEUR, EDITION_BUREAU, HEBERGEUR, PRIVACY_UPDATED } from '@/utils/legal'
</script>

<template>
  <LegalPage :surtitre="`mise à jour le ${PRIVACY_UPDATED}`" titre="Politique de confidentialité">
    <section aria-labelledby="responsable">
      <h2 id="responsable" class="text-[0.95rem] font-semibold text-ink">Qui est responsable</h2>
      <p class="mt-1.5">
        Le responsable des traitements décrits ici est
        <strong v-if="EDITEUR.nom" class="font-medium text-ink">{{ EDITEUR.nom }}</strong>
        <span v-else class="italic text-ochre">l’éditeur (identité à compléter)</span>, que vous
        pouvez joindre à
        <a
          v-if="EDITEUR.email"
          :href="`mailto:${EDITEUR.email}`"
          class="text-ink underline decoration-line-2 underline-offset-[3px]"
          >{{ EDITEUR.email }}</a
        >
        <span v-else class="italic text-ochre">l’adresse de contact (à compléter)</span>.
      </p>
      <p v-if="EDITION_BUREAU" class="mt-2">
        Cette édition fonctionne sur votre ordinateur et n’envoie rien à l’éditeur, qui n’a accès à
        aucune des données que vous y saisissez. Elles sont traitées par vous — ou par
        l’organisation qui a installé Relais sur ce poste, qui en est alors responsable.
      </p>
      <p v-else class="mt-2">
        Quand votre équipe envoie à Relais ses propres données — les erreurs de son application, les
        lignes de ses tables Backend —, c’est elle qui en est responsable ; l’éditeur agit alors
        pour son compte, en sous-traitant (article 28 du RGPD).
      </p>
    </section>

    <section aria-labelledby="finalites">
      <h2 id="finalites" class="text-[0.95rem] font-semibold text-ink">
        Ce qui est traité, et pourquoi
      </h2>
      <ul class="mt-1.5 list-disc space-y-1.5 pl-5">
        <li>
          <strong class="font-medium text-ink">Votre compte et vos contenus</strong>, pour vous
          fournir le service : c’est l’exécution du contrat que vous acceptez en créant un compte
          (article 6.1.b).
        </li>
        <li>
          <strong class="font-medium text-ink"
            >Vos sessions, vos tentatives de connexion et les adresses IP</strong
          >, pour protéger votre compte contre les accès non autorisés : c’est l’intérêt légitime de
          la sécurité (article 6.1.f).
        </li>
        <li>
          <strong class="font-medium text-ink">L’historique de l’espace</strong>, pour que l’équipe
          sache qui a fait quoi : intérêt légitime, limité à douze mois.
        </li>
        <li>
          <strong class="font-medium text-ink">Les e-mails de service</strong> — confirmation
          d’adresse, réinitialisation du mot de passe, invitations, alerte de changement de mot de
          passe —, pour faire fonctionner le compte. Aucun e-mail commercial n’est envoyé.
          <template v-if="EDITION_BUREAU">
            Sur le poste, ils ne partent pas : ils sont déposés dans la boîte d’envoi de
            l’application, où vous les lisez.
          </template>
        </li>
      </ul>
    </section>

    <section aria-labelledby="durees">
      <h2 id="durees" class="text-[0.95rem] font-semibold text-ink">Combien de temps</h2>
      <p class="mt-1.5">
        Ces durées sont appliquées par des purges automatiques, chaque jour : ce qui dépasse sa
        durée est effacé, pas archivé.
      </p>
      <div class="mt-3 overflow-x-auto">
        <table class="w-full border-collapse text-left text-[0.82rem]">
          <thead>
            <tr class="border-b border-line text-ink">
              <th scope="col" class="py-2 pr-4 font-medium">Données</th>
              <th scope="col" class="py-2 font-medium">Conservées</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="ligne in CONSERVATION" :key="ligne.donnees" class="border-b border-line">
              <td class="py-2 pr-4 align-top">{{ ligne.donnees }}</td>
              <td class="py-2 align-top">{{ ligne.duree }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section aria-labelledby="destinataires">
      <h2 id="destinataires" class="text-[0.95rem] font-semibold text-ink">Qui y a accès</h2>
      <p v-if="EDITION_BUREAU" class="mt-1.5">
        Les membres de votre espace sur ce poste, selon leur rôle. Ni l’éditeur ni aucun prestataire
        n’y a accès : la base de données et les fichiers restent sur cet ordinateur, et les e-mails
        de service ne quittent pas l’application. Seules sortent du poste les requêtes que vous
        configurez vous-même — les sondes de disponibilité, vers les adresses que vous choisissez de
        surveiller. Aucune donnée n’est vendue, louée ni utilisée à des fins publicitaires.
      </p>
      <p v-else class="mt-1.5">
        Les membres de votre espace, selon leur rôle ; l’hébergeur
        <template v-if="HEBERGEUR.nom">({{ HEBERGEUR.nom }})</template>
        et le prestataire d’envoi des e-mails, qui n’agissent que sur instruction. Aucune donnée
        n’est vendue, louée ni utilisée à des fins publicitaires. L’application ne transfère
        elle-même aucune donnée hors de l’Union européenne ; les serveurs sont ceux de l’hébergeur
        indiqué dans les mentions légales.
      </p>
    </section>

    <section aria-labelledby="cookies">
      <h2 id="cookies" class="text-[0.95rem] font-semibold text-ink">Cookies et traceurs</h2>
      <p class="mt-1.5">
        Un seul cookie, inaccessible aux scripts, maintient votre session. Il est strictement
        nécessaire au service demandé et n’appelle donc pas de consentement (article 82 de la loi
        Informatique et Libertés). Le navigateur garde aussi vos préférences d’affichage — thème,
        son — dans son stockage local.
        <template v-if="EDITION_BUREAU">
          Sur le poste, un second cookie technique, tiré à chaque lancement, réserve le serveur
          local à la fenêtre de Relais.
        </template>
      </p>
      <p class="mt-2">
        Aucun traceur publicitaire, aucune mesure d’audience, aucune police ni aucun script chargé
        depuis un autre site : la politique de sécurité de l’application l’interdit.
      </p>
    </section>

    <section aria-labelledby="securite">
      <h2 id="securite" class="text-[0.95rem] font-semibold text-ink">Sécurité</h2>
      <p class="mt-1.5">
        Les mots de passe sont hachés (bcrypt) et jamais stockés en clair ; les liens et les jetons
        de session ne sont gardés que sous forme d’empreinte.
        <template v-if="EDITION_BUREAU">
          Les échanges ne quittent pas l’ordinateur : le serveur local n’écoute que la machine
          elle-même, et ne répond qu’à la fenêtre de Relais.
        </template>
        <template v-else>Les échanges sont chiffrés (HTTPS).</template>
        Chaque espace est cloisonné : aucune requête ne lit les données d’un autre. Les fichiers
        téléversés sont vérifiés et débarrassés de leurs métadonnées. Un changement de mot de passe
        ferme toutes les sessions et vous est signalé par e-mail. La double authentification
        s’active depuis le profil : son secret est chiffré en base, et chaque connexion exige alors
        un code de votre application.
      </p>
      <p v-if="EDITION_BUREAU" class="mt-2">
        Les données sont rangées dans votre profil Windows, dans un dossier réservé à votre compte.
        Les secrets de l’installation — la clé de chiffrement, le mot de passe de la base — y sont
        scellés par Windows et ne s’ouvrent qu’avec ce compte. Contre le vol de l’ordinateur,
        activez le chiffrement du disque (BitLocker).
      </p>
    </section>

    <section aria-labelledby="droits">
      <h2 id="droits" class="text-[0.95rem] font-semibold text-ink">Vos droits</h2>
      <ul class="mt-1.5 list-disc space-y-1.5 pl-5">
        <li>
          <strong class="font-medium text-ink">Accès et portabilité</strong> : le bouton «
          Télécharger mes données » de la page Profil remet une archive ZIP — vos données en JSON,
          lisibles par une machine, et les fichiers que vous avez déposés.
        </li>
        <li>
          <strong class="font-medium text-ink">Rectification</strong> : votre nom et votre photo se
          modifient depuis le profil.
        </li>
        <li>
          <strong class="font-medium text-ink">Effacement</strong> : « Supprimer mon compte » est
          immédiat. Vos sessions, préférences, photo et adhésions disparaissent ; un espace dont
          vous étiez le seul membre est supprimé avec tout son contenu. Dans un espace partagé, ce
          que vous y avez écrit reste à l’équipe, sans votre nom — l’historique indique alors «
          Compte supprimé ».
        </li>
        <li>
          <strong class="font-medium text-ink">Limitation et opposition</strong> :
          <template v-if="EDITION_BUREAU">
            auprès de la personne ou de l’organisation responsable de ce poste, qui vous répond dans
            un délai d’un mois.
          </template>
          <template v-else>
            sur demande, à l’adresse de contact ci-dessus. Une réponse vous est apportée dans un
            délai d’un mois.
          </template>
        </li>
      </ul>
      <p class="mt-2">
        Si vous estimez que vos droits ne sont pas respectés, vous pouvez adresser une réclamation à
        la CNIL —
        <a
          href="https://www.cnil.fr/fr/plaintes"
          target="_blank"
          rel="noopener noreferrer"
          class="text-ink underline decoration-line-2 underline-offset-[3px]"
          >cnil.fr/fr/plaintes</a
        >.
      </p>
    </section>
  </LegalPage>
</template>
