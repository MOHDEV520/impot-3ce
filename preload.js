const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electronAPI', {
    checkForUpdates: () => ipcRenderer.invoke('check-for-updates'),
    getVersion: () => ipcRenderer.invoke('get-version')
});
