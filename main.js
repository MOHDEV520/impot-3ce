const { app, BrowserWindow, Menu, dialog, ipcMain } = require('electron');
const path = require('path');
const { spawn } = require('child_process');
const fs = require('fs');
const net = require('net');
const http = require('http');
const { autoUpdater } = require('electron-updater');

// Configuration de l'auto-updater
// Ne distribuer que les versions stables aux PC clients (pas de pré-releases).
autoUpdater.autoDownload = true;
autoUpdater.allowPrerelease = false;

// Gestion des messages IPC
ipcMain.handle('check-for-updates', async () => {
    try {
        const result = await autoUpdater.checkForUpdatesAndNotify();
        return result;
    } catch (error) {
        return { error: error.message };
    }
});

ipcMain.handle('get-version', () => {
    return app.getVersion();
});

let mainWindow;
let phpServer;
// Vrai uniquement quand l'utilisateur ferme volontairement l'application : on
// distingue ainsi un arrêt normal de php.exe d'un crash à superviser/relancer.
let isQuitting = false;
// URL courante du serveur PHP interne (reutilisée lors d'un redémarrage).
let currentServerUrl = null;
// Garde-fou anti-boucle : nombre de redémarrages automatiques déjà tentés.
let phpRestartCount = 0;
const PHP_MAX_RESTARTS = 5;

// Verrou d'instance unique : sans cela, lancer l'application deux fois démarre
// DEUX serveurs php.exe (ports différents) pointant sur le MÊME fichier SQLite
// (%APPDATA%/IMPOT-3CE/database.sqlite) → écritures concurrentes → « database
// is locked » intermittent au moment de valider un formulaire.
const aObtenuLeVerrou = app.requestSingleInstanceLock();
if (!aObtenuLeVerrou) {
    app.quit();
} else {
    app.on('second-instance', () => {
        // L'utilisateur a relancé l'app : on ramène la fenêtre existante au premier
        // plan au lieu d'ouvrir une seconde instance.
        if (mainWindow) {
            if (mainWindow.isMinimized()) mainWindow.restore();
            mainWindow.focus();
        }
    });
}

function getPhpPath() {
    return path.join(__dirname, 'bin/php/php.exe');
}

function isPortAvailable(port) {
    return new Promise((resolve) => {
        const tester = net.createServer();

        tester.once('error', () => {
            resolve(false);
        });

        tester.once('listening', () => {
            tester.close(() => resolve(true));
        });

        tester.listen(port, '127.0.0.1');
    });
}

async function findAvailablePhpPort() {
    const candidates = [8010];
    for (let p = 18010; p <= 18040; p++) {
        candidates.push(p);
    }

    for (const port of candidates) {
        if (await isPortAvailable(port)) {
            return port;
        }
    }

    throw new Error('Aucun port local disponible pour le serveur PHP interne.');
}

/**
 * Sonde une URL jusqu'a obtenir une reponse HTTP (le serveur PHP est pret)
 * ou jusqu'a expiration du delai imparti.
 */
function waitForServerReady(url, { timeoutMs = 8000, intervalMs = 150 } = {}) {
    const deadline = Date.now() + timeoutMs;

    return new Promise((resolve, reject) => {
        const essayer = () => {
            const req = http.get(url, (res) => {
                res.resume();
                resolve();
            });

            req.on('error', () => {
                req.destroy();
                if (Date.now() >= deadline) {
                    reject(new Error('Le serveur PHP interne n a pas repondu avant expiration du delai.'));
                    return;
                }
                setTimeout(essayer, intervalMs);
            });
        };

        essayer();
    });
}

async function startPhpServer() {
    const phpPath = getPhpPath();
    const port = await findAvailablePhpPort();
    const url = `http://127.0.0.1:${port}/index.php`;

    // Lancement du serveur avec logs pour le debug
    phpServer = spawn(phpPath, ['-S', `127.0.0.1:${port}`, '-t', __dirname]);

    phpServer.stdout.on('data', (data) => {
        console.log(`PHP stdout: ${data}`);
    });

    phpServer.stderr.on('data', (data) => {
        console.error(`PHP stderr: ${data}`);
    });

    // Si php.exe echoue a demarrer ou se termine avant d'avoir repondu (DLL
    // manquante, antivirus, port bloque...), on le detecte immediatement au
    // lieu d'attendre betement l'expiration du delai de waitForServerReady.
    const onErrorPrecoce = (err) => rejeter(new Error('Impossible de lancer le serveur PHP interne : ' + err.message));
    let rejeter;
    const echecPrecoce = new Promise((resolve, reject) => {
        rejeter = reject;
        phpServer.once('error', onErrorPrecoce);
        phpServer.once('exit', (code) => {
            if (code !== 0) {
                reject(new Error(`Le serveur PHP interne s est arrete de maniere inattendue (code ${code}).`));
            }
        });
    });

    // On attend que le serveur reponde reellement avant de naviguer vers lui :
    // sans cette verification, un php.exe qui demarre lentement ou echoue
    // produit un ecran blanc silencieux au lieu de la page d'erreur de secours.
    await Promise.race([
        waitForServerReady(url, { timeoutMs: 8000, intervalMs: 150 }),
        echecPrecoce
    ]);

    // Démarrage réussi : on retire les écouteurs de démarrage et on installe une
    // SUPERVISION PERMANENTE. Le serveur PHP intégré (« php -S ») est
    // mono-thread et peut s'arrêter de façon inattendue en cours d'utilisation ;
    // sans surveillance, la fenêtre reste bloquée sur une page morte et
    // l'utilisateur est contraint de quitter puis relancer l'application.
    phpServer.removeListener('error', onErrorPrecoce);
    phpServer.removeAllListeners('exit');
    currentServerUrl = url;
    phpRestartCount = 0;
    phpServer.on('exit', (code, signal) => {
        if (isQuitting) return;
        console.error(`Le serveur PHP interne s'est arrete (code ${code}, signal ${signal}). Tentative de redemarrage...`);
        redemarrerServeurPhp();
    });

    return url;
}

/**
 * Relance le serveur PHP interne après un arrêt inattendu, puis recharge la
 * fenêtre. Limité à PHP_MAX_RESTARTS tentatives pour éviter une boucle infinie
 * si le binaire est réellement cassé.
 */
async function redemarrerServeurPhp() {
    if (isQuitting || !mainWindow) return;

    if (phpRestartCount >= PHP_MAX_RESTARTS) {
        console.error('Nombre maximal de redemarrages du serveur PHP atteint.');
        if (!mainWindow.isDestroyed()) {
            dialog.showMessageBox(mainWindow, {
                type: 'error',
                title: 'Serveur local indisponible',
                message: "Le serveur local s'est arrete a plusieurs reprises. Veuillez fermer puis relancer l'application.",
                buttons: ['Fermer']
            }).then(() => app.quit());
        }
        return;
    }

    phpRestartCount++;
    try {
        const url = await startPhpServer();
        if (mainWindow && !mainWindow.isDestroyed()) {
            mainWindow.loadURL(url);
        }
    } catch (error) {
        console.error('Echec du redemarrage du serveur PHP interne:', error);
        // Nouvelle tentative après un court délai (le port peut se liberer).
        setTimeout(redemarrerServeurPhp, 1000);
    }
}

async function createWindow() {
    // Icône compacte dédiée Electron (barre des tâches, bureau, installeur)
    let iconPath = path.join(__dirname, 'assets/img/icon.png');
    if (!fs.existsSync(iconPath)) {
        iconPath = path.join(__dirname, 'assets/img/logo.png');
    }
    if (!fs.existsSync(iconPath)) {
        iconPath = path.join(__dirname, 'assets/img/logo.svg');
    }

    mainWindow = new BrowserWindow({
        width: 1280,
        height: 850,
        backgroundColor: '#f8fafc',
        title: `3CE FISCUS - Système de Gestion Fiscale (V. ${app.getVersion()})`,
        icon: iconPath,
        show: false,
        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true,
            preload: path.join(__dirname, 'preload.js')
        }
    });

    // Supprimer la barre de menu par défaut pour un look plus "App"
    Menu.setApplicationMenu(null);

    // Filet de sécurité : si la navigation vers le serveur local échoue pour
    // une raison quelconque (non couverte par les vérifications ci-dessous),
    // on affiche une page d'erreur au lieu de laisser un écran blanc silencieux.
    mainWindow.webContents.on('did-fail-load', (event, errorCode, errorDescription, validatedURL) => {
        // -3 = ERR_ABORTED : déclenché par des navigations internes normales, à ignorer.
        if (errorCode === -3) return;

        console.error(`Echec de chargement (${errorCode} ${errorDescription}) : ${validatedURL}`);

        mainWindow.loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(
            '<html><body style="font-family:Segoe UI,Arial,sans-serif;padding:24px;background:#f8fafc;color:#0f172a;">' +
            '<h2>Erreur de chargement</h2>' +
            '<p>L application n a pas pu se connecter au serveur local.</p>' +
            '<p>Verifiez votre antivirus/pare-feu local, puis relancez l application.</p>' +
            '</body></html>'
        ));
    });

    // Si le processus de rendu se fige (« Ne repond pas ») ou plante, on propose
    // de recharger au lieu de laisser une fenetre morte que Windows marque comme
    // bloquee et que l'utilisateur doit fermer de force.
    mainWindow.webContents.on('unresponsive', () => {
        if (!mainWindow || mainWindow.isDestroyed()) return;
        dialog.showMessageBox(mainWindow, {
            type: 'warning',
            title: 'Application qui ne repond pas',
            message: "La page ne repond plus. Voulez-vous la recharger ?",
            buttons: ['Recharger', 'Attendre']
        }).then((result) => {
            if (result.response === 0 && mainWindow && !mainWindow.isDestroyed()) {
                mainWindow.webContents.reload();
            }
        });
    });

    mainWindow.webContents.on('render-process-gone', (event, details) => {
        console.error('Processus de rendu arrete:', details && details.reason);
        if (isQuitting || !mainWindow || mainWindow.isDestroyed()) return;
        // On recharge la derniere URL serveur connue pour retrouver un etat sain.
        if (currentServerUrl) {
            mainWindow.loadURL(currentServerUrl);
        } else {
            mainWindow.webContents.reload();
        }
    });

    // Tentative de lancement du serveur PHP interne (Priorité absolue)
    let url;
    const phpPath = getPhpPath();

    if (fs.existsSync(phpPath)) {
        try {
            url = await startPhpServer();
        } catch (error) {
            console.error('Impossible de demarrer le serveur PHP interne:', error);
            url = 'data:text/html;charset=utf-8,' + encodeURIComponent(
                '<html><body style="font-family:Segoe UI,Arial,sans-serif;padding:24px;background:#f8fafc;color:#0f172a;">' +
                '<h2>Erreur de demarrage local</h2>' +
                '<p>Le serveur PHP interne n a pas pu demarrer.</p>' +
                '<p>Verifiez les permissions reseau locales, puis relancez l application.</p>' +
                '</body></html>'
            );
        }
    } else {
        // Pas de fallback web: l'application doit rester 100% offline.
        console.error('ALERTE : Binaire PHP interne introuvable a : ' + phpPath);
        url = 'data:text/html;charset=utf-8,' + encodeURIComponent(
            '<html><body style="font-family:Segoe UI,Arial,sans-serif;padding:24px;background:#f8fafc;color:#0f172a;">' +
            '<h2>Installation incomplete</h2>' +
            '<p>Le binaire PHP interne est introuvable.</p>' +
            '<p>Reinstallez l application pour un fonctionnement 100% offline.</p>' +
            '</body></html>'
        );
    }

    mainWindow.loadURL(url);

    mainWindow.once('ready-to-show', () => {
        mainWindow.maximize();
        mainWindow.show();
    });

    // Shortcuts pour le développement
    mainWindow.webContents.on('before-input-event', (event, input) => {
        if (input.key === 'F12' && input.type === 'keyDown') {
            mainWindow.webContents.toggleDevTools();
        }
        if (input.key === 'F5' && input.control && input.type === 'keyDown') {
            mainWindow.webContents.reload();
        }
    });

    mainWindow.on('closed', function () {
        mainWindow = null;
        if (phpServer) {
            phpServer.kill();
        }
    });
}

// Fermeture volontaire : on l'indique AVANT que php.exe soit tue, afin que la
// supervision (exit handler) ne tente pas de le relancer.
app.on('before-quit', function () {
    isQuitting = true;
});

app.on('ready', () => {
    createWindow();
    
    // Gestion des mises à jour
    autoUpdater.checkForUpdatesAndNotify();

    autoUpdater.on('update-available', () => {
        console.log('Mise à jour disponible.');
    });

    autoUpdater.on('update-downloaded', (info) => {
        dialog.showMessageBox({
            type: 'info',
            title: 'Mise à jour prête',
            message: 'Une nouvelle version (' + info.version + ') a été téléchargée. Voulez-vous redémarrer pour l\'installer ?',
            buttons: ['Installer maintenant', 'Plus tard']
        }).then((result) => {
            if (result.response === 0) {
                autoUpdater.quitAndInstall();
            }
        });
    });

    autoUpdater.on('error', (err) => {
        console.error('Erreur auto-updater: ' + err);
    });
});

app.on('window-all-closed', function () {
    if (phpServer) phpServer.kill();
    if (process.platform !== 'darwin') {
        app.quit();
    }
});

app.on('activate', function () {
    if (mainWindow === null) {
        createWindow();
    }
});
