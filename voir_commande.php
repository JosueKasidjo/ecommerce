
<?php
require_once __DIR__ . "/config.php";

// Vérifier la connexion de l'administrateur
if (!isset($_SESSION["id_utilisateur"])) {
    header("Location: login.php");
    exit;
}

// Récupérer l'identifiant de la commande
$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id) {
    header("Location: commandes.php");
    exit;
}

// Fonction de protection contre les injections HTML
if (!function_exists("h")) {
    function h($valeur) {
        return htmlspecialchars((string)$valeur, ENT_QUOTES, "UTF-8");
    }
}

try {
    // Informations de la commande et du client
    $requete = $pdo->prepare("
        SELECT
            c.*,
            cl.nom,
            cl.postnom,
            cl.prenom,
            cl.telephone,
            cl.email,
            cl.adresse
        FROM commandes c
        LEFT JOIN clients cl ON cl.id = c.client_id
        WHERE c.id = ?
        LIMIT 1
    ");

    $requete->execute([$id]);
    $commande = $requete->fetch(PDO::FETCH_ASSOC);

    if (!$commande) {
        header("Location: commandes.php");
        exit;
    }

    // Produits de la commande
    $requeteDetails = $pdo->prepare("
        SELECT
            dc.*,
            p.nom_produit,
            p.photo
        FROM details_commande dc
        LEFT JOIN produits p ON p.id = dc.produit_id
        WHERE dc.commande_id = ?
    ");

    $requeteDetails->execute([$id]);
    $details = $requeteDetails->fetchAll(PDO::FETCH_ASSOC);

    // Paiements de la commande
    $requetePaiements = $pdo->prepare("
        SELECT *
        FROM paiements
        WHERE commande_id = ?
        ORDER BY date_paiement DESC
    ");

    $requetePaiements->execute([$id]);
    $paiements = $requetePaiements->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erreur lors de la récupération de la commande : " . h($e->getMessage()));
}

$nomClient = trim(
    ($commande["nom"] ?? "") . " " .
    ($commande["postnom"] ?? "") . " " .
    ($commande["prenom"] ?? "")
);

if ($nomClient === "") {
    $nomClient = "Client non renseigné";
}

$totalPaye = 0;
foreach ($paiements as $paiement) {
    if (($paiement["statut"] ?? "") === "Valide") {
        $totalPaye += (float)$paiement["montant"];
    }
}

$montantTotal = (float)$commande["montant_total"];
$resteAPayer = max(0, $montantTotal - $totalPaye);

function couleurStatutCommande($statut) {
    return match ($statut) {
        "Livree" => "bg-green-100 text-green-700",
        "Expediee" => "bg-blue-100 text-blue-700",
        "Confirmee" => "bg-indigo-100 text-indigo-700",
        "En preparation" => "bg-purple-100 text-purple-700",
        "Annulee" => "bg-red-100 text-red-700",
        default => "bg-orange-100 text-orange-700"
    };
}

function couleurStatutPaiement($statut) {
    return match ($statut) {
        "Valide" => "bg-green-100 text-green-700",
        "Refuse" => "bg-red-100 text-red-700",
        default => "bg-orange-100 text-orange-700"
    };
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails de la commande</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 text-gray-800">

<div class="min-h-screen flex">

    <!-- MENU LATERAL -->
    <?php include __DIR__ . '/_aside.php'; ?>

    <!-- CONTENU PRINCIPAL -->
    <main class="flex-1 md:ml-64">

        <!-- BARRE SUPERIEURE -->
        <header class="bg-white shadow-sm px-5 md:px-8 py-5 flex flex-wrap justify-between items-center gap-3">
            <div>
                <h2 class="text-xl font-bold text-gray-800">Détails de la commande</h2>
                <p class="text-sm text-gray-500">Consultation complète de la commande</p>
            </div>

            <a href="commandes.php"
               class="bg-green-700 hover:bg-green-800 text-white px-5 py-2.5 rounded-lg text-sm font-semibold">
                ← Retour aux commandes
            </a>
        </header>

        <div class="p-5 md:p-8">

            <!-- TITRE COMMANDE -->
            <div class="bg-gradient-to-r from-green-800 to-green-600 rounded-2xl p-6 text-white mb-6">
                <div class="flex flex-wrap justify-between items-center gap-4">
                    <div>
                        <p class="text-green-100 text-sm">Numéro de commande</p>
                        <h1 class="text-2xl md:text-3xl font-bold mt-1">
                            <?= h($commande["numero_commande"]) ?>
                        </h1>
                        <p class="text-green-100 text-sm mt-2">
                            Date :
                            <?= h(date("d/m/Y à H:i", strtotime($commande["date_commande"]))) ?>
                        </p>
                    </div>

                    <span class="px-4 py-2 rounded-full text-sm font-bold bg-white text-green-800">
                        <?= h($commande["statut"]) ?>
                    </span>
                </div>
            </div>

            <!-- INFORMATIONS CLIENT ET COMMANDE -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">

                <section class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="text-lg font-bold mb-5 border-b pb-3">
                        Informations du client
                    </h3>

                    <div class="space-y-4 text-sm">
                        <div>
                            <p class="text-gray-500">Nom complet</p>
                            <p class="font-semibold"><?= h($nomClient) ?></p>
                        </div>

                        <div>
                            <p class="text-gray-500">Téléphone</p>
                            <p class="font-semibold"><?= h($commande["telephone"] ?? "Non renseigné") ?></p>
                        </div>

                        <div>
                            <p class="text-gray-500">Adresse e-mail</p>
                            <p class="font-semibold"><?= h($commande["email"] ?? "Non renseigné") ?></p>
                        </div>

                        <div>
                            <p class="text-gray-500">Adresse</p>
                            <p class="font-semibold"><?= h($commande["adresse"] ?? "Non renseignée") ?></p>
                        </div>
                    </div>
                </section>

                <section class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="text-lg font-bold mb-5 border-b pb-3">
                        Résumé financier
                    </h3>

                    <div class="space-y-4">
                        <div class="flex justify-between gap-3 text-sm">
                            <span class="text-gray-500">Montant total</span>
                            <strong><?= number_format($montantTotal, 2, ",", " ") ?></strong>
                        </div>

                        <div class="flex justify-between gap-3 text-sm">
                            <span class="text-gray-500">Total payé</span>
                            <strong class="text-green-700">
                                <?= number_format($totalPaye, 2, ",", " ") ?>
                            </strong>
                        </div>

                        <div class="flex justify-between gap-3 text-sm">
                            <span class="text-gray-500">Reste à payer</span>
                            <strong class="text-red-600">
                                <?= number_format($resteAPayer, 2, ",", " ") ?>
                            </strong>
                        </div>

                        <div class="pt-3 border-t">
                            <p class="text-xs text-gray-500 mb-2">Progression du paiement</p>
                            <?php $pourcentage = $montantTotal > 0 ? min(100, ($totalPaye / $montantTotal) * 100) : 0; ?>

                            <div class="w-full bg-gray-200 rounded-full h-3">
                                <div class="bg-green-600 h-3 rounded-full"
                                     style="width: <?= $pourcentage ?>%">
                                </div>
                            </div>

                            <p class="text-right text-xs font-semibold mt-2">
                                <?= number_format($pourcentage, 1, ",", " ") ?> %
                            </p>
                        </div>
                    </div>
                </section>
            </div>

            <!-- PRODUITS COMMANDES -->
            <section class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                <div class="flex justify-between items-center mb-5">
                    <h3 class="text-lg font-bold">Produits commandés</h3>
                    <span class="text-sm text-gray-500">
                        <?= count($details) ?> ligne(s)
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                            <tr>
                                <th class="text-left p-4">Produit</th>
                                <th class="text-center p-4">Quantité</th>
                                <th class="text-right p-4">Prix unitaire</th>
                                <th class="text-right p-4">Sous-total</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y">
                            <?php if (empty($details)): ?>
                                <tr>
                                    <td colspan="4" class="p-6 text-center text-gray-500">
                                        Aucun produit trouvé pour cette commande.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($details as $detail): ?>
                                    <tr>
                                        <td class="p-4">
                                            <div class="flex items-center gap-3">
                                                <?php if (!empty($detail["photo"])): ?>
                                                    <img
                                                        src="uploads/produits/<?= h(basename($detail["photo"])) ?>"
                                                        alt="Produit"
                                                        class="w-12 h-12 rounded-lg object-cover bg-gray-100"
                                                    >
                                                <?php else: ?>
                                                    <div class="w-12 h-12 rounded-lg bg-green-50 flex items-center justify-center text-green-700">
                                                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4.5 7.8 7.5 4.3 7.5-4.3M12 12v9"/></svg>
                                                    </div>
                                                <?php endif; ?>

                                                <span class="font-semibold">
                                                    <?= h($detail["nom_produit"] ?? "Produit supprimé") ?>
                                                </span>
                                            </div>
                                        </td>

                                        <td class="p-4 text-center">
                                            <?= (int)$detail["quantite"] ?>
                                        </td>

                                        <td class="p-4 text-right">
                                            <?= number_format((float)$detail["prix_unitaire"], 2, ",", " ") ?>
                                        </td>

                                        <td class="p-4 text-right font-bold">
                                            <?= number_format((float)$detail["sous_total"], 2, ",", " ") ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>

                        <tfoot class="bg-green-50">
                            <tr>
                                <td colspan="3" class="p-4 text-right font-bold">
                                    TOTAL DE LA COMMANDE
                                </td>
                                <td class="p-4 text-right text-green-800 font-bold text-lg">
                                    <?= number_format($montantTotal, 2, ",", " ") ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <!-- HISTORIQUE DES PAIEMENTS -->
            <section class="bg-white rounded-2xl shadow-sm p-6">
                <h3 class="text-lg font-bold mb-5">Historique des paiements</h3>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                            <tr>
                                <th class="text-left p-4">Date</th>
                                <th class="text-left p-4">Mode de paiement</th>
                                <th class="text-left p-4">Référence</th>
                                <th class="text-right p-4">Montant</th>
                                <th class="text-center p-4">Statut</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y">
                            <?php if (empty($paiements)): ?>
                                <tr>
                                    <td colspan="5" class="p-6 text-center text-gray-500">
                                        Aucun paiement enregistré pour cette commande.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($paiements as $paiement): ?>
                                    <tr>
                                        <td class="p-4">
                                            <?= h(date("d/m/Y H:i", strtotime($paiement["date_paiement"]))) ?>
                                        </td>

                                        <td class="p-4">
                                            <?= h($paiement["mode_paiement"]) ?>
                                        </td>

                                        <td class="p-4">
                                            <?= h($paiement["reference_paiement"] ?: "—") ?>
                                        </td>

                                        <td class="p-4 text-right font-semibold">
                                            <?= number_format((float)$paiement["montant"], 2, ",", " ") ?>
                                        </td>

                                        <td class="p-4 text-center">
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold <?= couleurStatutPaiement($paiement["statut"]) ?>">
                                                <?= h($paiement["statut"]) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="commandes.php"
                   class="bg-green-700 hover:bg-green-800 text-white px-6 py-3 rounded-lg font-semibold">
                    Retour aux commandes
                </a>
            <button onclick="imprimerTicket()"
                class="bg-gray-700 hover:bg-gray-800 text-white px-6 py-3 rounded-lg font-semibold">
                Imprimer le reçu
            </button>
            </div>

        </div>
    </main>
</div>

<style>
@media print {
    aside, header, button, main > div > div:last-child a {
        display: none !important;
    }

    main {
        margin: 0 !important;
    }

    body {
        background: white !important;
    }

    section, .bg-gradient-to-r {
        box-shadow: none !important;
        break-inside: avoid;
    }
}

</style>

<style>
#ticket-impression {
    display: none;
}

.receipt-paper {
    width: 606px;
    height: 1541px;
    box-sizing: border-box;
}

@media print {
    @page {
        size: 606px 1541px;
        margin: 0;
    }

    body {
        background: white !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 606px;
        height: 1541px;
    }

    body * {
        visibility: hidden !important;
    }

    #ticket-impression,
    #ticket-impression * {
        visibility: visible !important;
    }

    #ticket-impression {
        display: block !important;
        position: absolute;
        top: 0;
        left: 0;
        width: 606px;
        height: 1541px;
        box-sizing: border-box;
        padding: 48px 44px;
        margin: 0;
        color: black !important;
        background: white !important;
        font-family: Arial, sans-serif;
        font-size: 14px;
    }

    .ticket-entete,
    .ticket-pied {
        text-align: center;
    }

    .ticket-entete {
        padding-bottom: 20px;
        border-bottom: 2px solid #222;
    }

    .ticket-entete h2 {
        font-size: 28px;
        font-weight: bold;
        margin: 12px 0;
    }

    .ticket-title {
        margin: 14px 0 6px;
        font-size: 18px;
        font-weight: bold;
        letter-spacing: 2px;
    }

    .ticket-infos p {
        margin: 10px 0;
        overflow-wrap: anywhere;
    }

    .ticket-infos {
        margin-top: 24px;
        padding: 18px 20px;
        border: 1px solid #aaa;
        border-radius: 8px;
    }

    .ticket-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        margin: 20px 0;
    }

    .ticket-table thead {
        border-bottom: 2px solid #222;
        text-transform: uppercase;
    }

    .ticket-table th,
    .ticket-table td {
        padding: 12px 6px;
        border-bottom: 1px solid #bbb;
        text-align: left;
        overflow-wrap: anywhere;
    }

    .ticket-table th:last-child,
    .ticket-table td:last-child {
        text-align: right;
    }

    .ticket-total p {
        text-align: right;
        margin: 12px 0;
    }

    .ticket-total {
        margin-top: 18px;
        padding: 14px 20px;
        border: 1px solid #aaa;
        border-radius: 8px;
    }

    .ticket-total .ticket-balance {
        margin-top: 18px;
        padding-top: 12px;
        border-top: 1px solid #777;
        font-size: 18px;
        font-weight: bold;
    }

    .ticket-pied {
        margin-top: auto;
        padding-top: 20px;
        border-top: 1px solid #777;
    }

    #ticket-impression hr {
        border: none;
        border-top: 1px dashed black;
        margin: 8px 0;
    }
}
</style>

<script>
function imprimerTicket() {
    window.print();
}
</script>

<div id="ticket-impression" class="receipt-paper">

    <div class="ticket-entete">
        <h2><?= h(parametre('nom_entreprise', 'MA BOUTIQUE')) ?></h2>
        <?php if (parametre('adresse_entreprise', '') !== ''): ?>
            <p><?= h(parametre('adresse_entreprise', '')) ?></p>
        <?php endif; ?>
        <?php if (parametre('telephone_entreprise', '') !== ''): ?>
            <p>Tél. : <?= h(parametre('telephone_entreprise', '')) ?></p>
        <?php endif; ?>
        <p class="ticket-title">REÇU DE PAIEMENT</p>
        <p>Merci pour votre confiance</p>
    </div>

    <div class="ticket-infos">
        <p><strong>COMMANDE :</strong> <?= h($commande["numero_commande"]) ?></p>
        <p><strong>DATE :</strong> <?= h(date("d/m/Y H:i", strtotime($commande["date_commande"]))) ?></p>
        <p><strong>CLIENT :</strong> <?= h($nomClient) ?></p>
        <p><strong>TEL :</strong> <?= h($commande["telephone"] ?? "") ?></p>
        <p><strong>STATUT :</strong> <?= h($commande["statut"]) ?></p>
    </div>

    <hr>

    <table class="ticket-table">
        <thead>
            <tr>
                <th>Produit</th>
                <th>Qté</th>
                <th>Total</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($details as $detail): ?>
                <tr>
                    <td><?= h($detail["nom_produit"] ?? "Produit") ?></td>
                    <td><?= (int)$detail["quantite"] ?></td>
                    <td><?= number_format((float)$detail["sous_total"], 2, ",", " ") ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <hr>

    <div class="ticket-total">
        <p>TOTAL : <?= number_format($montantTotal, 2, ",", " ") ?></p>
        <p>PAYÉ : <?= number_format($totalPaye, 2, ",", " ") ?></p>
        <p class="ticket-balance">RESTE À PAYER : <?= number_format($resteAPayer, 2, ",", " ") ?></p>
    </div>

    <hr>

    <div class="ticket-pied">
        <p>Merci pour votre achat !</p>
        <p>À bientôt.</p>
    </div>

</div>

</body>
</html>
