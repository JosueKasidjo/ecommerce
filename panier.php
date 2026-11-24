<?php
require_once "config.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialiser le panier
if (!isset($_SESSION['panier'])) {
    $_SESSION['panier'] = [];
}

$message = "";
$type_message = "success";

// --- NEXIA : paramètres de la boutique ---
$devise = parametre('devise', 'FC');

// Fonction de protection
if (!function_exists('h')) {
    function h($valeur) {
        return htmlspecialchars((string)$valeur, ENT_QUOTES, "UTF-8");
    }
}

/**
 * Récupère un produit et le stock cumulé de tous les points de vente.
 */
function obtenirProduit($pdo, $id) {
    $req = $pdo->prepare("
        SELECT p.id, p.nom_produit, p.photo, p.prix_vente, p.statut,
               COALESCE(s.quantite, 0) + COALESCE(ss.quantite, 0) AS quantite
        FROM produits p
        LEFT JOIN stocks s ON s.produit_id = p.id
        LEFT JOIN (
            SELECT produit_id, SUM(quantite) AS quantite
            FROM stocks_succursales
            GROUP BY produit_id
        ) ss ON ss.produit_id = p.id
        WHERE p.id = :id
        LIMIT 1
    ");
    $req->execute([':id' => $id]);
    return $req->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ============================================================
// TRAITEMENT DES ACTIONS (logique existante conservée)
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST['action'] ?? '';

    // Ajouter un produit
    if ($action === "ajouter") {

        $id = (int)($_POST['produit_id'] ?? 0);
        $quantite = max(1, (int)($_POST['quantite'] ?? 1));

        $produit = obtenirProduit($pdo, $id);

        if (!$produit) {
            $message = "Ce produit n'existe pas.";
            $type_message = "error";

        } elseif ($produit['statut'] !== 'Disponible') {
            $message = "Ce produit n'est pas disponible.";
            $type_message = "error";

        } elseif ((int)$produit['quantite'] < 1) {
            $message = "Ce produit est actuellement en rupture de stock.";
            $type_message = "error";

        } else {
            $quantite_actuelle = (int)($_SESSION['panier'][$id] ?? 0);
            $nouvelle_quantite = $quantite_actuelle + $quantite;

            if ($nouvelle_quantite > (int)$produit['quantite']) {
                $message = "Stock insuffisant. Quantité disponible : " . (int)$produit['quantite'];
                $type_message = "error";
            } else {
                $_SESSION['panier'][$id] = $nouvelle_quantite;
                $message = "Produit ajouté au panier avec succès.";
            }
        }
    }

    // Modifier les quantités
    elseif ($action === "modifier") {

        $quantites = $_POST['quantites'] ?? [];

        foreach ($quantites as $id => $quantite) {

            $id = (int)$id;
            $quantite = (int)$quantite;

            if (!isset($_SESSION['panier'][$id])) {
                continue;
            }

            if ($quantite <= 0) {
                unset($_SESSION['panier'][$id]);
                continue;
            }

            $produit = obtenirProduit($pdo, $id);

            if (!$produit || $produit['statut'] !== 'Disponible') {
                unset($_SESSION['panier'][$id]);
                continue;
            }

            $stock = (int)$produit['quantite'];

            if ($quantite > $stock) {
                $quantite = $stock;
            }

            if ($quantite > 0) {
                $_SESSION['panier'][$id] = $quantite;
            } else {
                unset($_SESSION['panier'][$id]);
            }
        }

        $message = "Votre panier a été mis à jour.";
    }

    // Supprimer un produit
    elseif ($action === "supprimer") {

        $id = (int)($_POST['produit_id'] ?? 0);

        if (isset($_SESSION['panier'][$id])) {
            unset($_SESSION['panier'][$id]);
            $message = "Produit retiré du panier.";
        }
    }

    // Vider le panier
    elseif ($action === "vider") {

        $_SESSION['panier'] = [];
        $message = "Votre panier a été vidé.";
    }
}

// ============================================================
// LECTURE DU PANIER (stock cumulé de tous les points de vente)
// ============================================================
$articles = [];
$total = 0;
$total_articles = 0;

if (!empty($_SESSION['panier'])) {

    $ids = array_map('intval', array_keys($_SESSION['panier']));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $sql = "SELECT p.id, p.nom_produit, p.photo, p.prix_vente, p.statut
            FROM produits p
            WHERE p.id IN ($placeholders)";

    $req = $pdo->prepare($sql);
    $req->execute($ids);
    $produits = $req->fetchAll(PDO::FETCH_ASSOC);

    // Récupérer le stock disponible au niveau de toute la boutique.
    $stockMap = [];
    $stmtS = $pdo->prepare("
        SELECT stocks_disponibles.produit_id, SUM(stocks_disponibles.quantite) AS quantite
        FROM (
            SELECT produit_id, quantite FROM stocks WHERE produit_id IN ($placeholders)
            UNION ALL
            SELECT produit_id, quantite FROM stocks_succursales WHERE produit_id IN ($placeholders)
        ) stocks_disponibles
        GROUP BY stocks_disponibles.produit_id
    ");
    $stmtS->execute(array_merge($ids, $ids));
    foreach ($stmtS->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $stockMap[(int)$r['produit_id']] = (int)$r['quantite'];
    }

    foreach ($produits as $produit) {

        $id = (int)$produit['id'];
        $quantite = (int)$_SESSION['panier'][$id];
        $produit['stock'] = $stockMap[$id] ?? 0;

        // Retirer les produits devenus indisponibles
        if ($produit['statut'] !== 'Disponible' || (int)$produit['stock'] < 1) {
            unset($_SESSION['panier'][$id]);
            continue;
        }

        // Limiter la quantité au stock restant
        if ($quantite > (int)$produit['stock']) {
            $quantite = (int)$produit['stock'];
            $_SESSION['panier'][$id] = $quantite;
        }

        $prix = (float)$produit['prix_vente'];
        $sous_total = $prix * $quantite;

        $produit['quantite_panier'] = $quantite;
        $produit['sous_total'] = $sous_total;

        $articles[] = $produit;

        $total += $sous_total;
        $total_articles += $quantite;
    }
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <link rel="stylesheet" href="scrollbar.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Mon panier | E-COMMERCE</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        boutique: {
                            fonce: '#052e16',
                            principal: '#166534',
                            vert: '#15803d',
                            clair: '#f0fdf4'
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body {
            background:
                radial-gradient(circle at 8% 2%, rgba(220, 252, 231, .72), transparent 28rem),
                #f8fafc;
        }

        .cart-main {
            width: 100%;
            max-width: 1200px;
            min-height: calc(100vh - 150px);
        }

        .cart-header {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            padding: clamp(1.5rem, 4vw, 2.5rem);
            border: 1px solid #e2e8f0;
            border-radius: 1.5rem;
            background: linear-gradient(115deg, #fff 15%, #f0fdf4 100%);
            box-shadow: 0 12px 32px rgba(15, 23, 42, .04);
        }

        .cart-header::after {
            position: absolute;
            z-index: -1;
            top: -5rem;
            right: -3rem;
            width: 15rem;
            height: 15rem;
            border-radius: 50%;
            background: rgba(187, 247, 208, .34);
            content: "";
        }

        .cart-card {
            border: 1px solid #e2e8f0;
            border-radius: 1.25rem;
            background: #fff;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .045);
        }

        .cart-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.8fr) minmax(18rem, .8fr);
            gap: 2rem;
        }

        .cart-item {
            display: grid;
            grid-template-columns: 7rem minmax(0, 1fr) auto;
            align-items: start;
            gap: 1.25rem;
            padding: 1.5rem;
            transition: background-color .2s ease;
        }

        .cart-item:hover {
            background: #fcfdfc;
        }

        .cart-item-info {
            min-width: 0;
        }

        .cart-item-remove button {
            white-space: nowrap;
        }

        .cart-item-image {
            width: 7rem;
            height: 7rem;
            overflow: hidden;
            border-radius: .9rem;
            background: #f1f5f9;
        }

        .cart-item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .cart-line-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 1rem;
            margin-top: 1rem;
        }

        .cart-summary {
            position: sticky;
            top: 1.5rem;
            padding: 1.5rem;
        }

        .cart-empty {
            padding: clamp(2.5rem, 8vw, 5rem) 1.25rem;
        }

        .cart-main button,
        .cart-main a,
        .cart-main select,
        .cart-main input {
            transition: color .18s ease, background-color .18s ease, border-color .18s ease, box-shadow .18s ease, transform .18s ease;
        }

        .cart-main :focus-visible {
            outline: 3px solid rgba(22, 101, 52, .35);
            outline-offset: 3px;
        }

        @media (max-width: 640px) {
            .cart-main {
                padding-top: 1.5rem;
                padding-bottom: 2rem;
            }

            .cart-header {
                border-radius: 1.25rem;
            }

            .cart-item {
                grid-template-columns: 4.5rem minmax(0, 1fr);
                gap: .85rem;
                padding: 1rem;
            }

            .cart-item-image {
                grid-column: 1;
                grid-row: 1;
                width: 4.5rem;
                height: 4.5rem;
            }

            .cart-item-info {
                grid-column: 2;
                grid-row: 1;
            }

            .cart-item-info h4 {
                overflow-wrap: anywhere;
                font-size: 1rem;
                line-height: 1.35;
            }

            .cart-line-controls {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                gap: .65rem;
                margin-top: .8rem;
            }

            .cart-quantity {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: .5rem;
            }

            .cart-quantity label {
                margin: 0;
            }

            .cart-quantity input {
                width: 4.5rem;
            }

            .cart-line-subtotal {
                display: flex;
                width: 100%;
                align-items: center;
                justify-content: space-between;
                margin: 0;
                border-top: 1px solid #e2e8f0;
                padding-top: .55rem;
                text-align: left;
            }

            .cart-item-remove {
                grid-column: 2;
                grid-row: 2;
                justify-content: flex-end;
            }

            .cart-items-footer {
                flex-direction: column-reverse;
            }

            .cart-items-footer a,
            .cart-items-footer button {
                width: 100%;
                justify-content: center;
                text-align: center;
            }

            .cart-summary {
                position: static;
            }
        }

        @media (max-width: 1023px) {
            .cart-layout {
                grid-template-columns: minmax(0, 1fr);
                gap: 1.5rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>

<body class="bg-slate-50 text-slate-800">

<main class="cart-main mx-auto px-4 py-10">

    <div class="cart-header mb-8">
        <p class="inline-flex items-center gap-2 rounded-full bg-green-100 px-3 py-1 text-xs font-bold uppercase tracking-wider text-green-800">
            <span class="h-2 w-2 rounded-full bg-green-600"></span>
            Votre espace client
        </p>

        <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
                    Mon panier
                </h1>

                <p class="mt-2 text-sm text-slate-600 sm:text-base">
                    Consultez vos articles et préparez votre commande.
                </p>
            </div>
            <?php if (!empty($articles)): ?>
                <span class="rounded-full border border-green-200 bg-white/80 px-4 py-2 text-sm font-semibold text-green-800">
                    <?= $total_articles ?> article(s) dans votre panier
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- MESSAGE -->
    <?php if ($message !== ""): ?>
        <div class="mb-6 p-4 rounded-xl border
            <?= $type_message === 'error'
                ? 'bg-red-50 border-red-200 text-red-700'
                : 'bg-green-50 border-green-200 text-green-700' ?>">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($articles)): ?>

        <!-- PANIER VIDE -->
        <div class="cart-card cart-empty text-center">

            <div class="w-20 h-20 bg-green-50 rounded-full flex items-center justify-center mx-auto mb-5">
                <svg xmlns="http://www.w3.org/2000/svg"
                     class="w-10 h-10 text-green-700"
                     fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="1.7">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M3 3h2l2.4 12.2a2 2 0 002 1.6h8.8a2 2 0 002-1.6L22 7H6"/>
                    <circle cx="10" cy="21" r="1"/>
                    <circle cx="18" cy="21" r="1"/>
                </svg>
            </div>

            <h3 class="text-xl font-bold text-slate-900">
                Votre panier est vide
            </h3>

            <p class="text-slate-500 mt-2">
                Vous n'avez pas encore ajouté de produit à votre panier.
            </p>

            <a href="index.php"
               class="inline-block mt-6 bg-green-700 hover:bg-green-800 text-white px-7 py-3 rounded-xl font-semibold transition">
                Découvrir les produits
            </a>

        </div>

    <?php else: ?>

        <div class="cart-layout min-w-0">

            <!-- LISTE DES PRODUITS -->
            <div class="min-w-0">

                <form method="POST" action="panier.php">

                    <input type="hidden" name="action" value="modifier">

                    <div class="cart-card cart-items overflow-hidden">

                        <div class="p-5 border-b border-slate-100 flex justify-between items-center">
                            <h3 class="font-bold text-lg">
                                Articles sélectionnés
                            </h3>

                            <span class="text-sm text-slate-500">
                                <?= $total_articles ?> article(s)
                            </span>
                        </div>

                        <?php foreach ($articles as $article): ?>

                            <div class="cart-item border-b border-slate-100">

                                <!-- IMAGE -->
                                <div class="cart-item-image flex-shrink-0">

                                    <?php if (!empty($article['photo'])): ?>
                                        <img src="<?= h($article['photo']) ?>"
                                             alt="<?= h($article['nom_produit']) ?>"
                                             loading="lazy">
                                    <?php else: ?>
                                        <div class="w-full h-full flex items-center justify-center text-slate-400 text-sm">
                                            Sans image
                                        </div>
                                    <?php endif; ?>

                                </div>

                                <!-- INFORMATIONS -->
                                <div class="cart-item-info flex-1">

                                    <h4 class="font-bold text-lg text-slate-900">
                                        <?= h($article['nom_produit']) ?>
                                    </h4>

                                    <p class="text-green-700 font-semibold mt-1">
                                        <?= number_format((float)$article['prix_vente'], 2, ',', ' ') ?> <?= h($devise) ?>
                                    </p>

                                    <p class="text-xs text-slate-500 mt-1">
                                        Stock disponible : <?= (int)$article['stock'] ?>
                                    </p>

                                    <div class="cart-line-controls">

                                        <div>
                                            <label class="text-xs text-slate-500 block mb-1">
                                                Quantité
                                            </label>

                                            <input
                                                type="number"
                                                name="quantites[<?= (int)$article['id'] ?>]"
                                                value="<?= (int)$article['quantite_panier'] ?>"
                                                min="0"
                                                max="<?= (int)$article['stock'] ?>"
                                                class="w-24 border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-green-500 outline-none">
                                        </div>

                                        <div class="cart-line-subtotal ml-auto text-right">
                                            <p class="text-xs text-slate-500">
                                                Sous-total
                                            </p>

                                            <p class="font-bold text-slate-900">
                                                <?= number_format($article['sous_total'], 2, ',', ' ') ?> <?= h($devise) ?>
                                            </p>
                                        </div>

                                    </div>

                                </div>

                                <div class="cart-item-remove flex items-start">

                                    <button
                                        type="button"
                                        onclick="supprimerProduit(<?= (int)$article['id'] ?>)"
                                        class="text-red-600 hover:text-red-800 text-sm font-semibold">
                                        Supprimer
                                    </button>

                                </div>

                            </div>

                        <?php endforeach; ?>

                        <div class="cart-items-footer p-5 flex flex-wrap gap-3 justify-between">

                            <a href="index.php"
                               class="border border-slate-300 hover:bg-slate-50 px-5 py-3 rounded-xl font-semibold text-sm">
                                Continuer les achats
                            </a>

                            <button type="submit"
                                    class="bg-green-700 hover:bg-green-800 text-white px-5 py-3 rounded-xl font-semibold text-sm">
                                Mettre à jour le panier
                            </button>

                        </div>

                    </div>

                </form>

                <!-- FORMULAIRE DE SUPPRESSION -->
                <form id="formSuppression" method="POST" action="panier.php" class="hidden" data-confirm="Voulez-vous retirer ce produit du panier ?" data-confirm-title="Retirer ce produit" data-confirm-label="Retirer" data-confirm-variant="danger">
                    <input type="hidden" name="action" value="supprimer">
                    <input type="hidden" name="produit_id" id="idSuppression">
                </form>

                <!-- VIDER LE PANIER -->
                <form method="POST" action="panier.php" class="mt-4" data-confirm="Voulez-vous vraiment vider votre panier ?" data-confirm-title="Vider le panier" data-confirm-label="Vider le panier" data-confirm-variant="danger">
                    <input type="hidden" name="action" value="vider">

                    <button type="submit"
                            class="text-red-600 hover:text-red-800 text-sm font-semibold">
                        Vider complètement le panier
                    </button>
                </form>

            </div>

            <!-- RÉSUMÉ DE LA COMMANDE -->
            <div>

                <div class="cart-card cart-summary">

                    <h3 class="text-lg font-bold text-slate-900 mb-6">
                        Résumé de la commande
                    </h3>

                    <div class="flex justify-between text-sm mb-4">
                        <span class="text-slate-500">
                            Nombre d'articles
                        </span>

                        <span class="font-semibold">
                            <?= $total_articles ?>
                        </span>
                    </div>

                    <div class="flex justify-between text-sm mb-4">
                        <span class="text-slate-500">
                            Sous-total
                        </span>

                        <span class="font-semibold">
                            <?= number_format($total, 2, ',', ' ') ?> <?= h($devise) ?>
                        </span>
                    </div>

                    <div class="flex justify-between text-sm mb-5">
                        <span class="text-slate-500">
                            Livraison
                        </span>

                        <span class="text-green-700 font-semibold">
                            À déterminer
                        </span>
                    </div>

                    <div class="border-t border-slate-200 pt-5">

                        <div class="flex justify-between items-center">
                            <span class="font-bold text-slate-900">
                                Total
                            </span>

                            <span class="text-xl font-bold text-green-700">
                                <?= number_format($total, 2, ',', ' ') ?> <?= h($devise) ?>
                            </span>
                        </div>

                    </div>

                    <a href="validation_commande.php"
                       class="block text-center bg-green-700 hover:bg-green-800 text-white font-bold py-4 rounded-xl mt-6 transition">
                        Valider ma commande
                    </a>

                    <p class="text-xs text-center text-slate-400 mt-4">
                        Le stock sera vérifié à la validation de votre commande.
                    </p>

                </div>

            </div>

        </div>

    <?php endif; ?>

</main>

<footer class="bg-green-950 text-green-100 py-6 mt-12">
    <div class="max-w-7xl mx-auto px-4 text-center text-sm">
        &copy; <?= date('Y') ?> NEXIA - Tous droits réservés.
    </div>
</footer>

<script>
function supprimerProduit(id) {
    document.getElementById("idSuppression").value = id;
    const form = document.getElementById("formSuppression");
    if (typeof form.requestSubmit === "function") form.requestSubmit();
    else form.submit();
}
</script>

<?php include __DIR__ . '/_notifications.php'; ?>
</body>
</html>