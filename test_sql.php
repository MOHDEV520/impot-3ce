<?php
$schemaFile = 'database/schema_mysql.sql';
if (file_exists($schemaFile)) {
    $sql = file_get_contents($schemaFile);
    
    // Nettoyage pour compatibilité SQLite
    $sql = preg_replace('/CREATE DATABASE.*?;/is', '', $sql);
    $sql = preg_replace('/USE .*?;/i', '', $sql);
    
    // Conversion critique : INT AUTO_INCREMENT PRIMARY KEY -> INTEGER PRIMARY KEY AUTOINCREMENT
    $sql = preg_replace('/INT\s+AUTO_INCREMENT\s+PRIMARY\s+KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    
    // Supprimer les options de table MySQL
    $sql = preg_replace('/ENGINE=InnoDB/i', '', $sql);
    $sql = preg_replace('/DEFAULT\s+CHARSET=[^\s;]*/i', '', $sql);
    $sql = preg_replace('/COLLATE=[^\s;]*/i', '', $sql);
    $sql = preg_replace('/CHARACTER\s+SET\s+[^\s;]*/i', '', $sql);
    
    // Gérer les ENUM
    $sql = preg_replace('/ENUM\(.*?\)/i', 'VARCHAR(255)', $sql);
    
    // Autres nettoyages
    $sql = str_replace(['utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'utf8mb4'], ['', '', ''], $sql);
    
    echo substr($sql, 0, 1000); // Voir le début du résultat
} else {
    echo "Fichier non trouve";
}
?>
