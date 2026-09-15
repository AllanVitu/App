// Pont de l'écran de démarrage : recevoir les étapes, demander le journal ou
// quitter. Rien d'autre n'est exposé à la page.
const { contextBridge, ipcRenderer } = require('electron')

contextBridge.exposeInMainWorld('relais', {
  surEtape: (rappel) => ipcRenderer.on('demarrage:etape', (_, e) => rappel({ message: String(e?.message ?? '') })),
  surErreur: (rappel) =>
    ipcRenderer.on('demarrage:erreur', (_, e) => rappel({ titre: String(e?.titre ?? ''), detail: String(e?.detail ?? '') })),
  action: (nom) => ipcRenderer.invoke('demarrage:action', String(nom)),
})
