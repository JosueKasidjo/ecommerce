
<?php
require_once __DIR__ . "/config.php";

// Vérifier la connexion du client
if (!isset($_SESSION["id_client"])) {
    header("Location: compte_client.php?mode=connexion");
    exit;
}

// Vérifier qu'une commande vient d'être enregistrée
if (empty($_SESSION["derniere_commande"])) {
    header("Location: index.php");
    exit;
}

$id_client = (int) $_SESSION["id_client"];
$numero_commande = $_SESSION["derniere_commande"];

// Récupérer la commande du client connecté
$requete = $pdo->prepare("
    SELECT
        id,
        numero_commande,
        date_commande,
        montant_total,
        statut
    FROM commandes
    WHERE numero_commande = ?
    AND client_id = ?
    LIMIT 1
");

$requete->execute([
    $numero_commande,
    $id_client
]);

$commande = $requete->fetch();

if (!$commande) {
    unset($_SESSION["derniere_commande"]);
    header("Location: index.php");
    exit;
}

// Récupérer les détails de la commande
$details = $pdo->prepare("
    SELECT
        dc.quantite,
        dc.prix_unitaire,
        dc.sous_total,
        p.nom_produit,
        p.photo
    FROM details_commande dc
    INNER JOIN produits p ON p.id = dc.produit_id
    WHERE dc.commande_id = ?
");

$details->execute([(int)$commande["id"]]);

$produits = $details->fetchAll();

$nom_client = $_SESSION["nom_client"] ?? "Cher client";

// Fonction d'affichage sécurisée
if (!function_exists("h")) {
    function h($valeur): string {
        return htmlspecialchars(
            (string)($valeur ?? ""),
            ENT_QUOTES,
            "UTF-8"
        );
    }
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <link rel="stylesheet" href="scrollbar.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Commande confirmée - MaBoutique</title>

    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 min-h-screen">

<header class="bg-white shadow-sm">
    <div class="max-w-6xl mx-auto px-5 py-4 flex justify-between items-center">

        <a href="index.php"
           class="text-2xl font-bold text-green-700">
            MaBoutique
        </a>

        <a href="deconnexion_client.php"
           class="text-red-600 hover:text-red-700 font-medium">
            Déconnexion
        </a>

    </div>
</header>

<main class="max-w-4xl mx-auto px-4 py-12">

    <!-- Message de confirmation -->
    <div class="bg-white rounded-3xl shadow-sm border p-8 text-center">

        <div class="w-20 h-20 rounded-full bg-green-100
                    flex items-center justify-center mx-auto mb-5">

            <svg xmlns="http://www.w3.org/2000/svg"
                 class="w-12 h-12 text-green-700"
                 fill="none"
                 viewBox="0 0 24 24"
                 stroke="currentColor"
                 stroke-width="2">

                <path stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M5 13l4 4L19 7"/>

            </svg>

        </div>

        <h1 class="text-3xl font-bold text-green-700">
            Commande confirmée !
        </h1>

        <p class="text-gray-500 mt-3">
            Merci <?= h($nom_client) ?> pour votre achat.
        </p>

        <p class="text-gray-500 mt-2">
            Votre commande a été enregistrée avec succès.
        </p>

        <div class="bg-green-50 rounded-2xl p-5 mt-7">

            <p class="text-sm text-gray-500">
                Numéro de votre commande
            </p>

            <p class="text-2xl font-bold text-green-700 mt-2">
                <?= h($commande["numero_commande"]) ?>
            </p>

        </div>

    </div>

    <!-- Informations de commande -->
    <div class="bg-white rounded-2xl shadow-sm border p-6 mt-7">

        <h2 class="text-xl font-bold text-gray-800 mb-5">
            Informations de la commande
        </h2>

        <div class="grid sm:grid-cols-2 gap-5">

            <div>
                <p class="text-sm text-gray-400">
                    Date de commande
                </p>

                <p class="font-semibold text-gray-800">
                    <?= h(date(
                        "d/m/Y à H:i",
                        strtotime($commande["date_commande"])
                    )) ?>
                </p>
            </div>

            <div>
                <p class="text-sm text-gray-400">
                    Statut
                </p>

                <span class="inline-block mt-1 bg-yellow-100
                             text-yellow-800 px-3 py-1 rounded-full
                             text-sm font-semibold">
                    <?= h($commande["statut"]) ?>
                </span>
            </div>

        </div>

    </div>

    <!-- Liste des produits -->
    <div class="bg-white rounded-2xl shadow-sm border p-6 mt-7">

        <h2 class="text-xl font-bold text-gray-800 mb-6">
            Détails des produits achetés
        </h2>

        <?php if (!empty($produits)): ?>

            <div class="space-y-5">

                <?php foreach ($produits as $produit): ?>

                    <div class="flex items-center gap-4 border-b pb-5">

                        <?php if (!empty($produit["photo"])): ?>

                            <img
                                src="<?= h($produit["photo"]) ?>"
                                alt="<?= h($produit["nom_produit"]) ?>"
                                class="w-20 h-20 object-cover rounded-xl"
                            >

                        <?php else: ?>

                            <div class="w-20 h-20 bg-gray-100 rounded-xl
                                        flex items-center justify-center">

                                <span class="text-gray-400 text-xs">
                                    Sans photo
                                </span>

                            </div>

                        <?php endif; ?>

                        <div class="flex-1">

                            <h3 class="font-bold text-gray-800">
                                <?= h($produit["nom_produit"]) ?>
                            </h3>

                            <p class="text-sm text-gray-500 mt-1">
                                Quantité :
                                <?= (int)$produit["quantite"] ?>
                            </p>

                            <p class="text-sm text-gray-500">
                                Prix unitaire :
                                <?= number_format(
                                    (float)$produit["prix_unitaire"],
                                    2
                                ) ?> $
                            </p>

                        </div>

                        <div class="font-bold text-green-700">

                            <?= number_format(
                                (float)$produit["sous_total"],
                                2
                            ) ?> $

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <p class="text-gray-500">
                Aucun détail de produit trouvé.
            </p>

        <?php endif; ?>

        <!-- Total -->
        <div class="flex justify-between items-center mt-7">

            <span class="text-lg font-bold text-gray-800">
                Total de la commande
            </span>

            <span class="text-2xl font-bold text-green-700">
                <?= number_format(
                    (float)$commande["montant_total"],
                    2
                ) ?> $
            </span>

        </div>

    </div>

    <!-- Actions -->
    <div class="grid sm:grid-cols-2 gap-4 mt-8">

        <a href="index.php"
           class="text-center bg-green-700 hover:bg-green-800
                  text-white font-bold py-4 rounded-xl">

            Continuer mes achats

        </a>

        <a href="panier.php"
           class="text-center bg-white border border-gray-300
                  hover:bg-gray-100 text-gray-700 font-bold
                  py-4 rounded-xl">

            Retour au panier

        </a>

    </div>

</main>

<footer class="text-center text-gray-400 text-sm py-8">
    &copy; <?= date("Y") ?> MaBoutique - Tous droits réservés.
</footer>

</body>
</html>
