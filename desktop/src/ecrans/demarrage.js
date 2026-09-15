const etape = document.getElementById('etape')
const progression = document.getElementById('progression')
const erreur = document.getElementById('erreur')

window.relais.surEtape(({ message }) => {
  if (message) {
    etape.textContent = message
  }
})

window.relais.surErreur(({ titre, detail }) => {
  document.getElementById('erreur-titre').textContent = titre
  document.getElementById('erreur-detail').textContent = detail
  progression.hidden = true
  erreur.hidden = false
  document.getElementById('quitter').focus()
})

document.getElementById('journal').addEventListener('click', () => window.relais.action('journal'))
document.getElementById('quitter').addEventListener('click', () => window.relais.action('quitter'))
