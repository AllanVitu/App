import assert from 'node:assert/strict'
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { after, test } from 'node:test'
import { analyserEml, decoderEntete, extraireLiens, lireMessage, listerMessages } from '../src/services/boite-envoi.js'

const dossiers = []

function dossierJetable() {
  const dossier = mkdtempSync(join(tmpdir(), 'relais-boite-'))
  dossiers.push(dossier)

  return dossier
}

after(() => dossiers.forEach((dossier) => rmSync(dossier, { recursive: true, force: true })))

/** Un message construit comme Mailer::buildMessage le construit. */
function commeLApi({ sujet, texte, a = 'Marie Dupont <marie@exemple.fr>' }) {
  const frontiere = 'bnd_0123456789abcdef01234567'
  const entete = (v) => (/^[\x20-\x7e]*$/.test(v) ? v : `=?UTF-8?B?${Buffer.from(v).toString('base64')}?=`)
  const base64 = (t) => Buffer.from(t).toString('base64').replace(/.{76}/g, '$&\r\n') + '\r\n'

  return (
    [
      'Date: Tue, 15 Sep 2026 10:00:00 +0000',
      'Message-ID: <0a1b2c@poste.local>',
      'From: Relais <relais@poste.local>',
      `To: ${a}`,
      `Subject: ${entete(sujet)}`,
      'MIME-Version: 1.0',
      `Content-Type: multipart/alternative; boundary="${frontiere}"`,
    ].join('\r\n') +
    '\r\n\r\n' +
    [
      `--${frontiere}`,
      'Content-Type: text/plain; charset=UTF-8',
      'Content-Transfer-Encoding: base64',
      '',
      base64(texte),
      `--${frontiere}`,
      'Content-Type: text/html; charset=UTF-8',
      'Content-Transfer-Encoding: base64',
      '',
      base64('<p onclick="x()">html</p>'),
      `--${frontiere}--`,
      '',
    ].join('\r\n')
  )
}

test('un message de l’API se relit : en-têtes décodés, texte brut seul', () => {
  const texte = 'Bonjour Marie,\n\nConfirmez votre adresse : http://127.0.0.1:41234/verifier-email?token=' + 'a'.repeat(64) + '\n\nÀ bientôt.'
  const message = analyserEml(commeLApi({ sujet: 'Confirmez votre adresse — Relais', texte }))

  assert.equal(message.sujet, 'Confirmez votre adresse — Relais')
  assert.equal(message.de, 'Relais <relais@poste.local>')
  assert.equal(message.a, 'Marie Dupont <marie@exemple.fr>')
  assert.equal(message.texte, texte)
  assert.doesNotMatch(message.texte, /onclick/)
})

test('mots encodés des en-têtes, en base64 comme en quoted-printable', () => {
  assert.equal(decoderEntete('=?UTF-8?Q?Caf=C3=A9_cr=C3=A8me?='), 'Café crème')
  assert.equal(decoderEntete(`=?utf-8?b?${Buffer.from('Invitation à « Atelier »').toString('base64')}?=`), 'Invitation à « Atelier »')
  assert.equal(decoderEntete('Texte simple'), 'Texte simple')
})

test('les liens d’un texte, sans la ponctuation de la phrase', () => {
  assert.deepEqual(
    extraireLiens('Confirmez : http://127.0.0.1:5000/verifier?token=abc. Aide (https://relais.example/aide). Encore http://127.0.0.1:5000/verifier?token=abc'),
    ['http://127.0.0.1:5000/verifier?token=abc', 'https://relais.example/aide'],
  )
  assert.deepEqual(extraireLiens('javascript:alert(1) file:///C:/x ftp://x'), [])
})

test('un message se désigne par son nom exact, jamais par un chemin', () => {
  const dossier = dossierJetable()
  const nom = '20260915T100000Z-0123456789ab.eml'

  writeFileSync(join(dossier, nom), commeLApi({ sujet: 'Sujet', texte: 'corps' }))
  writeFileSync(join(dossier, 'coffre.bin'), 'secret')

  assert.equal(lireMessage(dossier, nom)?.sujet, 'Sujet')
  assert.equal(lireMessage(dossier, nom)?.date, '2026-09-15T10:00:00Z')

  for (const id of ['../coffre.bin', '..\\coffre.bin', 'coffre.bin', `${nom}/../coffre.bin`, `x/${nom}`, '', null, 42]) {
    assert.equal(lireMessage(dossier, id), null, String(id))
  }
})

test('la liste : les plus récents d’abord, ni fichier temporaire ni intrus', () => {
  const dossier = dossierJetable()

  writeFileSync(join(dossier, '20260915T090000Z-aaaaaaaaaaaa.eml'), commeLApi({ sujet: 'Premier', texte: '1' }))
  writeFileSync(join(dossier, '20260915T110000Z-bbbbbbbbbbbb.eml'), commeLApi({ sujet: 'Second', texte: '2' }))
  writeFileSync(join(dossier, '.20260915T120000Z-cccccccccccc.eml.part'), 'en cours')
  writeFileSync(join(dossier, 'notes.txt'), 'intrus')

  assert.deepEqual(
    listerMessages(dossier).map((m) => m.sujet),
    ['Second', 'Premier'],
  )
  assert.deepEqual(listerMessages(join(dossier, 'absent')), [])
})
