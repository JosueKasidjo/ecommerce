
<?php
require_once __DIR__ . "/config.php";

// Vérifier la connexion du client
if (!isset($_SESSION["id_client"])) {
    header("Location: compte_client.php?mode=connexion");
    exit;
}

$id_client = (int) $_SESSION["id_client"];

// Fonction de sécurité
if (!function_exists("h")) {
    function h($valeur): string {
        return htmlspecialchars(
            (string)($valeur ?? ""),
            ENT_QUOTES,
            "UTF-8"
        );
    }
}

// Récupérer les commandes du client
$requete = $pdo->prepare("
    SELECT
        c.id,
        c.numero_commande,
        c.date_commande,
        c.montant_total,
        c.statut,
        COUNT(dc.id) AS nombre_produits
    FROM commandes c
    LEFT JOIN details_commande dc
        ON dc.commande_id = c.id
    WHERE c.client_id = ?
    GROUP BY
        c.id,
        c.numero_commande,
        c.date_commande,
        c.montant_total,
        c.statut
    ORDER BY c.date_commande DESC
");

$requete->execute([$id_client]);
$commandes = $requete->fetchAll();

// Définir les couleurs des statuts
function couleurStatut($statut) {
    switch ($statut) {
        case "En attente":
            return "bg-yellow-100 text-yellow-800";

        case "Confirmee":
        case "Confirmée":
            return "bg-blue-100 text-blue-800";

        case "En preparation":
        case "En préparation":
            return "bg-purple-100 text-purple-800";

        case "Expediee":
        case "Expédiée":
            return "bg-indigo-100 text-indigo-800";

        case "Livree":
        case "Livrée":
            return "bg-green-100 text-green-800";

        case "Annulee":
        case "Annulée":
            return "bg-red-100 text-red-800";

        default:
            return "bg-gray-100 text-gray-700";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <link rel="stylesheet" href="scrollbar.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Mes commandes - MaBoutique</title>

    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 min-h-screen">

<header class="bg-white shadow-sm">

    <div class="max-w-6xl mx-auto px-5 py-4
                flex justify-between items-center">

        <a href="index.php"
           class="text-2xl font-bold text-green-700">
            MaBoutique
        </a>

        <div class="flex items-center gap-5 text-sm">

            <a href="index.php"
               class="text-gray-600 hover:text-green-700">
                Boutique
            </a>

            <a href="panier.php"
               class="text-gray-600 hover:text-green-700">
                Mon panier
            </a>

            <a href="deconnexion_client.php"
               class="text-red-600 hover:text-red-700">
                Déconnexion
            </a>

        </div>

    </div>

</header>

<main class="max-w-6xl mx-auto px-4 py-10">

    <div class="mb-8">

        <h1 class="text-3xl font-bold text-gray-800">
            Mes commandes
        </h1>

        <p class="text-gray-500 mt-2">
            Retrouvez ici toutes les commandes que vous avez effectuées.
        </p>

    </div>

    <!-- Statistiques -->
    <div class="grid sm:grid-cols-2 gap-5 mb-8">

        <div class="bg-white p-6 rounded-2xl shadow-sm border">

            <p class="text-gray-500 text-sm">
                Nombre de commandes
            </p>

            <p class="text-3xl font-bold text-green-700 mt-2">
                <?= count($commandes) ?>
            </p>

        </div>

        <div class="bg-white p-6 rounded-2xl shadow-sm border">

            <p class="text-gray-500 text-sm">
                Montant total des commandes
            </p>

            <p class="text-3xl font-bold text-green-700 mt-2">
                <?= number_format(
                    array_sum(array_column($commandes, "montant_total")),
                    2
                ) ?> $
            </p>

        </div>

    </div>

    <!-- Liste des commandes -->
    <div class="bg-white rounded-2xl shadow-sm border overflow-hidden">

        <div class="p-6 border-b">

            <h2 class="text-xl font-bold text-gray-800">
                Historique de mes achats
            </h2>

        </div>

        <?php if (!empty($commandes)): ?>

            <div class="overflow-x-auto">

                <table class="w-full text-sm text-left">

                    <thead class="bg-gray-50 text-gray-600">

                        <tr>

                            <th class="px-6 py-4">N° Commande</th>

                            <th class="px-6 py-4">Date</th>

                            <th class="px-6 py-4">Produits</th>

                            <th class="px-6 py-4">Montant</th>

                            <th class="px-6 py-4">Statut</th>

                            <th class="px-6 py-4">Action</th>

                        </tr>

                    </thead>

                    <tbody class="divide-y">

                        <?php foreach ($commandes as $commande): ?>

                            <tr class="hover:bg-gray-50">

                                <td class="px-6 py-4 font-semibold text-green-700">
                                    <?= h($commande["numero_commande"]) ?>
                                </td>

                                <td class="px-6 py-4 text-gray-600">
                                    <?= h(date(
                                        "d/m/Y H:i",
                                        strtotime($commande["date_commande"])
                                    )) ?>
                                </td>

                                <td class="px-6 py-4">
                                    <?= (int)$commande["nombre_produits"] ?>
                                </td>

                                <td class="px-6 py-4 font-bold">
                                    <?= number_format(
                                        (float)$commande["montant_total"],
                                        2
                                    ) ?> $
                                </td>

                                <td class="px-6 py-4">

                                    <span class="inline-block px-3 py-1
                                        rounded-full text-xs font-semibold
                                        <?= couleurStatut($commande["statut"]) ?>">

                                        <?= h($commande["statut"]) ?>

                                    </span>

                                </td>

                                <td class="px-6 py-4">

                                    <a href="detail_commande.php?id=<?= (int)$commande["id"] ?>"
                                       class="text-green-700 font-semibold
                                              hover:underline">

                                        Voir détails

                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="text-center py-16 px-5">

                <div class="text-5xl mb-5">
                    <svg class="mx-auto h-12 w-12 text-green-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 8h14l1 13H4L5 8Z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/></svg>
                </div>

                <h3 class="text-xl font-bold text-gray-800">
                    Aucune commande pour le moment
                </h3>

                <p class="text-gray-500 mt-2">
                    Vous n'avez pas encore effectué d'achat.
                </p>

                <a href="index.php"
                   class="inline-block mt-6 bg-green-700
                          hover:bg-green-800 text-white
                          px-7 py-3 rounded-xl font-bold">

                    Découvrir la boutique

                </a>

            </div>

        <?php endif; ?>

    </div>

    <div class="mt-8">

        <a href="index.php"
           class="text-green-700 font-semibold hover:underline">

            ← Retour à la boutique

        </a>

    </div>

</main>

<footer class="text-center text-gray-400 text-sm py-8">
    &copy; <?= date("Y") ?> MaBoutique - Tous droits réservés.
</footer>

</body>
</html>
