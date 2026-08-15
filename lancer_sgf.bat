@echo off
title Lancement SGF - Cabinet 3CE
echo Verification des dependances...
if not exist "node_modules" (
    echo Erreur : Dossier node_modules introuvable. 
    echo Veuillez executer 'npm install' une fois avant de lancer.
    pause
    exit
)
echo Demarrage de l'application desktop...
start "" npx electron .
exit