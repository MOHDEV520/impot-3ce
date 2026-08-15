/**
 * Génère icon.png (1024) et logo.png (512) pour Electron et l'interface web
 * à partir de assets/img/icon-source.png
 */
const path = require('path');
const fs = require('fs');

async function main() {
    const sharp = require('sharp');
    const root = path.join(__dirname, '..');
    const sourcePath = path.join(root, 'assets', 'img', 'icon-source.png');
    const iconPath = path.join(root, 'assets', 'img', 'icon.png');
    const logoPath = path.join(root, 'assets', 'img', 'logo.png');

    if (!fs.existsSync(sourcePath)) {
        console.error('icon-source.png introuvable dans assets/img/');
        process.exit(1);
    }

    const resizeOpts = {
        fit: 'contain',
        background: { r: 255, g: 255, b: 255, alpha: 0 },
    };

    await sharp(sourcePath)
        .resize(1024, 1024, resizeOpts)
        .png({ compressionLevel: 9 })
        .toFile(iconPath);

    await sharp(sourcePath)
        .resize(512, 512, resizeOpts)
        .png({ compressionLevel: 9 })
        .toFile(logoPath);

    const iconStat = fs.statSync(iconPath);
    const logoStat = fs.statSync(logoPath);
    console.log('OK:', iconPath, '(' + Math.round(iconStat.size / 1024) + ' Ko)');
    console.log('OK:', logoPath, '(' + Math.round(logoStat.size / 1024) + ' Ko)');
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
