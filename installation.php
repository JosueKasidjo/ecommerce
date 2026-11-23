<?php

$host = "localhost";
$nomBase = "ecommerce";
$utilisateur = "root";
$motDePasse = "";

$message = "";
$erreur = "";

try {
    // Connexion au serveur MySQL sans sélectionner de base de données.
    $pdo = new PDO(
        "mysql:host=$host;charset=utf8mb4",
        $utilisateur,
        $motDePasse,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    // Création de la base de données.
    $pdo->exec(
        "CREATE DATABASE IF NOT EXISTS `$nomBase`
         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );

    // Sélection de la base de données.
    $pdo->exec("USE `$nomBase`");

    $tables = [];

    $tables[] = "CREATE TABLE IF NOT EXISTS utilisateurs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        mot_de_passe VARCHAR(255) NOT NULL,
        role ENUM('Administrateur','Gestionnaire') NOT NULL DEFAULT 'Administrateur',
        statut ENUM('Actif','Inactif') NOT NULL DEFAULT 'Actif',
        date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB";

    $tables[] = "CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom_categorie VARCHAR(100) NOT NULL,
        description TEXT NULL,
        statut ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB";

    $tables[] = "CREATE TABLE IF NOT EXISTS produits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        categorie_id INT NOT NULL,
        nom_produit VARCHAR(150) NOT NULL,
        description TEXT NULL,
        photo VARCHAR(255) NULL,
        prix_achat DECIMAL(12,2) NOT NULL DEFAULT 0,
        prix_vente DECIMAL(12,2) NOT NULL,
        afficher_accueil TINYINT(1) NOT NULL DEFAULT 1,
        statut ENUM('Disponible','Indisponible') NOT NULL DEFAULT 'Disponible',
        date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_produits_categories
            FOREIGN KEY (categorie_id) REFERENCES categories(id)
            ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB";

    $tables[] = "CREATE TABLE IF NOT EXISTS stocks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        produit_id INT NOT NULL UNIQUE,
        quantite INT NOT NULL DEFAULT 0,
        seuil_alerte INT NOT NULL DEFAULT 5,
        derniere_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_stocks_produits
            FOREIGN KEY (produit_id) REFERENCES produits(id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB";

    $tables[] = "CREATE TABLE IF NOT EXISTS clients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL,
        postnom VARCHAR(100) NULL,
        prenom VARCHAR(100) NULL,
        telephone VARCHAR(30) NOT NULL,
        mot_de_passe VARCHAR(255) NULL,
        email VARCHAR(150) NULL,
        adresse TEXT NULL,
        
        date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB";

    $tables[] = "CREATE TABLE IF NOT EXISTS commandes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        numero_commande VARCHAR(50) NOT NULL UNIQUE,
        client_id INT NOT NULL,
        succursale_id INT NULL,
        date_commande TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        montant_total DECIMAL(12,2) NOT NULL DEFAULT 0,
        statut ENUM(
            'En attente',
            'Confirmee',
            'En preparation',
            'Expediee',
            'Livree',
            'Annulee'
        ) NOT NULL DEFAULT 'En attente',
        CONSTRAINT fk_commandes_clients
            FOREIGN KEY (client_id) REFERENCES clients(id)
            ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB";

    $tables[] = "CREATE TABLE IF NOT EXISTS details_commande (
        id INT AUTO_INCREMENT PRIMARY KEY,
        commande_id INT NOT NULL,
        produit_id INT NOT NULL,
        quantite INT NOT NULL,
        prix_unitaire DECIMAL(12,2) NOT NULL,
        sous_total DECIMAL(12,2) NOT NULL,
        CONSTRAINT fk_details_commandes
            FOREIGN KEY (commande_id) REFERENCES commandes(id)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_details_produits
            FOREIGN KEY (produit_id) REFERENCES produits(id)
            ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB";

    $tables[] = "CREATE TABLE IF NOT EXISTS paiements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        commande_id INT NOT NULL,
        montant DECIMAL(12,2) NOT NULL,
        mode_paiement ENUM(
            'Especes',
            'Mobile Money',
            'Virement',
            'Carte',
            'Autre'
        ) NOT NULL,
        reference_paiement VARCHAR(150) NULL,
        statut ENUM('En attente','Valide','Refuse') NOT NULL DEFAULT 'En attente',
        date_paiement TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_paiements_commandes
            FOREIGN KEY (commande_id) REFERENCES commandes(id)
            ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB";


    $tables[] = "CREATE TABLE IF NOT EXISTS mouvements_stock (
        id INT AUTO_INCREMENT PRIMARY KEY,
        produit_id INT NOT NULL,
        succursale_id INT NULL,
        utilisateur_id INT NULL,
        type_mouvement ENUM('Entree','Sortie','Ajustement') NOT NULL,
        quantite INT NOT NULL,
        motif VARCHAR(255) NULL,
        date_mouvement TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_mouvements_produits
            FOREIGN KEY (produit_id) REFERENCES produits(id)
            ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT fk_mouvements_utilisateurs
            FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id)
            ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB";

    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }

    // Ajouter les colonnes de succursale aux bases déjà installées.
    foreach (['commandes', 'mouvements_stock'] as $table) {
        $verification = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = 'succursale_id'
        ");
        $verification->execute([$table]);
        if ((int)$verification->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN succursale_id INT NULL");
        }
    }

    $message = "La base de données « ecommerce » et ses 9 tables ont été créées avec succès.";
} catch (PDOException $e) {
    $erreur = "Erreur pendant l'installation : " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <link rel="stylesheet" href="scrollbar.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation E-commerce</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 20px;
            background: #f1f5f9;
            font-family: Arial, sans-serif;
            color: #0f172a;
        }
        .carte {
            width: 100%;
            max-width: 620px;
            padding: 32px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(15, 23, 42, .08);
        }
        h1 { margin-top: 0; font-size: 25px; }
        .message {
            padding: 15px;
            border-radius: 10px;
            background: #dcfce7;
            color: #166534;
            line-height: 1.6;
        }
        .erreur {
            padding: 15px;
            border-radius: 10px;
            background: #fee2e2;
            color: #991b1b;
            overflow-wrap: anywhere;
            line-height: 1.6;
        }
        .note { color: #475569; line-height: 1.7; }
        code { background: #e2e8f0; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
    <main class="carte">
        <h1>Installation de la plateforme E-commerce</h1>

        <?php if ($message !== ""): ?>
            <div class="message">
                <strong>Installation réussie !</strong><br>
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <p class="note">
                Les tables créées sont : utilisateurs, categories, produits, stocks,
                clients, commandes, details_commande, paiements et mouvements_stock.
            </p>
            <p class="note">
                Prochaine étape : créez le compte administrateur, puis supprimez
                <code>installation.php</code> du serveur.
            </p>
        <?php else: ?>
            <div class="erreur">
                <strong>Installation échouée.</strong><br>
                <?= htmlspecialchars($erreur, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <p class="note">
                Vérifiez que MySQL est démarré et que les identifiants de connexion
                dans le haut du fichier correspondent à votre configuration XAMPP.
            </p>
        <?php endif; ?>
    </main>
</body>
</html>
