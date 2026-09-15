// Pont de la boîte d'envoi : lister, lire, suivre un lien. Les identifiants
// passent en texte et sont revérifiés côté principal — un nom de fichier, jamais
// un chemin.
const { contextBridge, ipcRenderer } = require('electron')

contextBridge.exposeInMainWorld('relais', {
  lister: () => ipcRenderer.invoke('boite:lister'),
  lire: (id) => ipcRenderer.invoke('boite:lire', String(id)),
  ouvrir: (url) => ipcRenderer.invoke('boite:ouvrir', String(url)),
  surNouveau: (rappel) => ipcRenderer.on('boite:nouveau', (_, id) => rappel(String(id))),
  surSelection: (rappel) => ipcRenderer.on('boite:selection', (_, id) => rappel(String(id))),
})
