
<?php
require_once __DIR__ . "/config.php";

// Vérifier la connexion du client
if (!isset($_SESSION["id_client"])) {
    header("Location: compte_client.php?mode=connexion");
    exit;
}

$id_client = (int) $_SESSION["id_client"];

$message = "";
$erreur = false;
$succursalesActives = $pdo->query("
    SELECT id
    FROM succursales
    WHERE statut = 'Active'
")->fetchAll(PDO::FETCH_COLUMN);
$idsSuccursalesActives = array_map('intval', $succursalesActives);
$succursaleParDefaut = (int)parametre('succursale_par_defaut', 1);
$succursaleId = (int)($_SESSION['succursale_vente'] ?? $succursaleParDefaut);

if (!in_array($succursaleId, $idsSuccursalesActives, true)) {
    $succursaleId = in_array($succursaleParDefaut, $idsSuccursalesActives, true)
        ? $succursaleParDefaut
        : ($idsSuccursalesActives[0] ?? 0);
    $_SESSION['succursale_vente'] = $succursaleId;
}

if ($succursaleId <= 0) {
    $message = "Aucune succursale active n'est disponible pour prendre une commande.";
    $erreur = true;
}

// Protection CSRF
if (empty($_SESSION["csrf_validation"])) {
    $_SESSION["csrf_validation"] = bin2hex(random_bytes(32));
}

// Vérifier le panier
$panier = $_SESSION["panier"] ?? [];

if (empty($panier)) {
    header("Location: panier.php");
    exit;
}

// Récupérer les informations du client
$requete = $pdo->prepare("
    SELECT id, nom, postnom, prenom, telephone, email, adresse
    FROM clients
    WHERE id = ?
    LIMIT 1
");
$requete->execute([$id_client]);
$client = $requete->fetch();

if (!$client) {
    unset(
        $_SESSION["id_client"],
        $_SESSION["nom_client"],
        $_SESSION["email_client"]
    );

    header("Location: compte_client.php?mode=connexion");
    exit;
}

// Récupérer les produits du panier
$produits = [];
$total = 0;

$ids = [];
foreach ($panier as $id => $quantite) {
    $id = (int) $id;
    $quantite = (int) $quantite;

    if ($id > 0 && $quantite > 0) {
        $ids[$id] = $quantite;
    }
}

if (empty($ids)) {
    unset($_SESSION["panier"]);
    header("Location: panier.php");
    exit;
}

$placeholders = implode(",", array_fill(0, count($ids), "?"));
$jointureStock = $succursaleId === 1
    ? "LEFT JOIN stocks s ON s.produit_id = p.id"
    : "LEFT JOIN stocks_succursales s ON s.produit_id = p.id AND s.succursale_id = ?";

$sql = "
    SELECT
        p.id,
        p.nom_produit,
        p.photo,
        p.prix_vente,
        p.statut,
        s.quantite AS stock_disponible
    FROM produits p
    $jointureStock
    WHERE p.id IN ($placeholders)
";

$requete = $pdo->prepare($sql);
$parametresStock = $succursaleId === 1
    ? array_keys($ids)
    : array_merge([$succursaleId], array_keys($ids));
$requete->execute($parametresStock);

$liste = $requete->fetchAll();

foreach ($liste as $produit) {
    $id = (int) $produit["id"];
    $quantite = $ids[$id];

    $produit["quantite_commande"] = $quantite;
    $produit["sous_total"] =
        (float) $produit["prix_vente"] * $quantite;

    $produits[$id] = $produit;
    $total += $produit["sous_total"];
}

// Vérifier si tous les produits du panier existent
if (count($produits) !== count($ids)) {
    $message = "Certains produits de votre panier n'existent plus.";
    $erreur = true;
}

// Vérifier le stock avant affichage
foreach ($produits as $produit) {
    if (
        $produit["statut"] !== "Disponible" ||
        $produit["stock_disponible"] === null ||
        (int) $produit["stock_disponible"] < $produit["quantite_commande"]
    ) {
        $message = "Stock insuffisant pour le produit : "
                 . $produit["nom_produit"];
        $erreur = true;
        break;
    }
}

// TRAITEMENT DE LA COMMANDE
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!hash_equals(
        $_SESSION["csrf_validation"],
        $_POST["csrf_token"] ?? ""
    )) {
        $message = "Votre session a expiré. Actualisez la page.";
        $erreur = true;

    } elseif (!$erreur) {

        try {
            $pdo->beginTransaction();

            $produits_verifies = [];
            $nouveau_total = 0;

            // Verrouiller et vérifier chaque produit et son stock
            foreach ($ids as $id => $quantite) {

                $jointureStockVerifie = $succursaleId === 1
                    ? "INNER JOIN stocks s ON s.produit_id = p.id"
                    : "INNER JOIN stocks_succursales s ON s.produit_id = p.id AND s.succursale_id = ?";
                $verifier = $pdo->prepare("
                    SELECT
                        p.id,
                        p.nom_produit,
                        p.prix_vente,
                        p.statut,
                        s.quantite AS stock_disponible
                    FROM produits p
                    $jointureStockVerifie
                    WHERE p.id = ?
                    FOR UPDATE
                ");

                $parametresVerifier = $succursaleId === 1
                    ? [$id]
                    : [$succursaleId, $id];
                $verifier->execute($parametresVerifier);
                $produit = $verifier->fetch();

                if (!$produit) {
                    throw new Exception(
                        "Un produit de votre panier n'est plus disponible."
                    );
                }

                if ($produit["statut"] !== "Disponible") {
                    throw new Exception(
                        "Le produit " . $produit["nom_produit"]
                        . " n'est plus disponible."
                    );
                }

                if ((int)$produit["stock_disponible"] < $quantite) {
                    throw new Exception(
                        "Stock insuffisant pour "
                        . $produit["nom_produit"]
                    );
                }

                $produit["quantite_commande"] = $quantite;

                $produit["sous_total"] =
                    (float)$produit["prix_vente"] * $quantite;

                $produits_verifies[] = $produit;

                $nouveau_total += $produit["sous_total"];
            }

            // Générer un numéro de commande
            $numero_commande = "CMD-" .
                date("Ymd") . "-" .
                strtoupper(bin2hex(random_bytes(3)));

            // Enregistrer la commande
            $insert_commande = $pdo->prepare("
                INSERT INTO commandes
                (
                    numero_commande,
                    client_id,
                    succursale_id,
                    date_commande,
                    montant_total,
                    statut
                )
                VALUES (?, ?, ?, NOW(), ?, 'En attente')
            ");

            $insert_commande->execute([
                $numero_commande,
                $id_client,
                $succursaleId,
                $nouveau_total
            ]);

            $id_commande = (int)$pdo->lastInsertId();

            // Préparer les requêtes
            $insert_detail = $pdo->prepare("
                INSERT INTO details_commande
                (
                    commande_id,
                    produit_id,
                    quantite,
                    prix_unitaire,
                    sous_total
                )
                VALUES (?, ?, ?, ?, ?)
            ");

            $update_stock = $succursaleId === 1
                ? $pdo->prepare("
                    UPDATE stocks
                    SET quantite = quantite - ?,
                        derniere_modification = NOW()
                    WHERE produit_id = ?
                ")
                : $pdo->prepare("
                    UPDATE stocks_succursales
                    SET quantite = quantite - ?,
                        date_maj = NOW()
                    WHERE succursale_id = ? AND produit_id = ?
                ");

            $insert_mouvement = $pdo->prepare("
                INSERT INTO mouvements_stock
                (
                    produit_id,
                    utilisateur_id,
                    type_mouvement,
                    quantite,
                    motif,
                    date_mouvement
                )
                VALUES (?, NULL, 'Sortie', ?, ?, NOW())
            ");

            // Enregistrer les détails et déduire le stock
            foreach ($produits_verifies as $produit) {

                $id_produit = (int)$produit["id"];
                $quantite = (int)$produit["quantite_commande"];
                $prix = (float)$produit["prix_vente"];
                $sous_total = (float)$produit["sous_total"];

                $insert_detail->execute([
                    $id_commande,
                    $id_produit,
                    $quantite,
                    $prix,
                    $sous_total
                ]);

                $parametresUpdateStock = $succursaleId === 1
                    ? [$quantite, $id_produit]
                    : [$quantite, $succursaleId, $id_produit];
                $update_stock->execute($parametresUpdateStock);

                $insert_mouvement->execute([
                    $id_produit,
                    $quantite,
                    "Commande " . $numero_commande
                ]);
            }

            // Valider toutes les opérations
            $pdo->commit();

            // Vider le panier après confirmation
            unset($_SESSION["panier"]);

            $_SESSION["derniere_commande"] = $numero_commande;

            header("Location: commande_confirmee.php");
            exit;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $message = $e instanceof PDOException
                ? "Une erreur est survenue lors de l'enregistrement de votre commande. Vérifiez la structure de vos tables."
                : $e->getMessage();

            $erreur = true;
        }
    }
}

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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Validation de commande</title>

    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 min-h-screen">

<header class="bg-white shadow-sm">
    <div class="max-w-6xl mx-auto px-5 py-4 flex justify-between items-center">

        <a href="index.php"
           class="text-2xl font-bold text-green-700">
            MaBoutique
        </a>

        <div class="flex items-center gap-5 text-sm">

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
            Validation de votre commande
        </h1>

        <p class="text-gray-500 mt-2">
            Vérifiez vos informations et confirmez votre achat.
        </p>
    </div>

    <?php if ($message !== ""): ?>
        <div class="mb-6 p-4 rounded-xl
            <?= $erreur
                ? 'bg-red-50 text-red-700 border border-red-200'
                : 'bg-green-50 text-green-700' ?>">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <div class="grid lg:grid-cols-3 gap-8">

        <!-- Informations client -->
        <section class="lg:col-span-1 bg-white p-6 rounded-2xl shadow-sm">

            <h2 class="text-xl font-bold mb-5">
                Informations du client
            </h2>

            <div class="space-y-4 text-sm">

                <div>
                    <p class="text-gray-400">Nom complet</p>
                    <p class="font-semibold">
                        <?= h($client["nom"]) ?>
                        <?= h($client["postnom"]) ?>
                        <?= h($client["prenom"]) ?>
                    </p>
                </div>

                <div>
                    <p class="text-gray-400">Téléphone</p>
                    <p class="font-semibold">
                        <?= h($client["telephone"]) ?>
                    </p>
                </div>

                <div>
                    <p class="text-gray-400">Adresse e-mail</p>
                    <p class="font-semibold break-all">
                        <?= h($client["email"]) ?>
                    </p>
                </div>

                <div>
                    <p class="text-gray-400">Adresse</p>
                    <p class="font-semibold">
                        <?= h($client["adresse"]) ?>
                    </p>
                </div>

            </div>

            <a href="compte_client.php?mode=connexion"
               class="inline-block mt-6 text-green-700 font-semibold">
                Modifier mes informations
            </a>

        </section>

        <!-- Résumé de commande -->
        <section class="lg:col-span-2 bg-white p-6 rounded-2xl shadow-sm">

            <h2 class="text-xl font-bold mb-6">
                Résumé de votre panier
            </h2>

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

                            <p class="text-sm text-gray-500">
                                Quantité :
                                <?= (int)$produit["quantite_commande"] ?>
                            </p>

                            <p class="text-sm text-gray-500">
                                Prix unitaire :
                                <?= number_format(
                                    (float)$produit["prix_vente"],
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

            <div class="flex justify-between items-center mt-7">

                <span class="text-lg font-bold">
                    Montant total
                </span>

                <span class="text-2xl font-bold text-green-700">
                    <?= number_format($total, 2) ?> $
                </span>

            </div>

            <div class="mt-7 bg-yellow-50 border border-yellow-200
                        text-yellow-800 rounded-xl p-4 text-sm">
                Votre commande sera enregistrée avec le statut
                <strong>En attente</strong>.
                Le paiement sera effectué dans une étape ultérieure.
            </div>

            <form method="POST" class="mt-7" data-confirm="Voulez-vous vraiment confirmer cette commande ?" data-confirm-title="Confirmer la commande" data-confirm-label="Confirmer la commande">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= h($_SESSION["csrf_validation"]) ?>">

                <button
                    type="submit"
                    <?= $erreur ? "disabled" : "" ?>
                    class="w-full py-4 rounded-xl font-bold text-white
                    <?= $erreur
                        ? 'bg-gray-400 cursor-not-allowed'
                        : 'bg-green-700 hover:bg-green-800' ?>">
                    Confirmer ma commande
                </button>

            </form>

            <a href="panier.php"
               class="block text-center mt-5 text-gray-500 hover:text-green-700">
                Retourner au panier
            </a>

        </section>

    </div>

</main>

<?php include __DIR__ . '/_notifications.php'; ?>
</body>
</html>
