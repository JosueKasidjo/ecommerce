
<?php
require_once __DIR__ . "/config.php";

// Supprimer uniquement les informations de session du client
unset($_SESSION["id_client"]);
unset($_SESSION["nom_client"]);
unset($_SESSION["email_client"]);

// Renouveler l'identifiant de session
session_regenerate_id(true);

// Rediriger vers la boutique
header("Location: index.php");
exit;
