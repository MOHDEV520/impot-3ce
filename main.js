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
    const echecPrecoce = new Promise((resolve, reject) => {
        phpServer.once('error', (err) => {
            reject(new Error('Impossible de lancer le serveur PHP interne : ' + err.message));
        });
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

    return url;
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
