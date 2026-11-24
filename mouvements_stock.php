<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION["id_utilisateur"])) {
    header("Location: login.php");
    exit;
}

$nomUtilisateur = $_SESSION["nom"] ?? "Administrateur";
$roleUtilisateur = $_SESSION["role"] ?? "Administrateur";

$host = "localhost";
$nomBase = "ecommerce";
$utilisateur = "root";
$motDePasse = "";

$erreur = "";
$mouvements = [];
$totalMouvements = 0;
$totalEntrees = 0;
$totalSorties = 0;
$totalAjustements = 0;

$recherche = trim($_GET["q"] ?? "");
$typeFiltre = $_GET["type"] ?? "tous";
$dateDebut = trim($_GET["date_debut"] ?? "");
$dateFin = trim($_GET["date_fin"] ?? "");
$page = max(1, (int)($_GET["page"] ?? 1));
$parPage = 20;
$decalage = ($page - 1) * $parPage;

function h($valeur) {
    return htmlspecialchars((string)$valeur, ENT_QUOTES, "UTF-8");
}
function nombre($valeur) {
    return number_format((float)$valeur, 0, ",", " ");
}

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$nomBase;charset=utf8mb4",
        $utilisateur,
        $motDePasse,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    $conditions = [];
    $params = [];

    if ($recherche !== "") {
        $conditions[] = "(p.nom_produit LIKE :recherche OR m.motif LIKE :recherche OR u.nom LIKE :recherche)";
        $params[":recherche"] = "%" . $recherche . "%";
    }

    if (in_array($typeFiltre, ["Entree", "Sortie", "Ajustement"], true)) {
        $conditions[] = "m.type_mouvement = :type";
        $params[":type"] = $typeFiltre;
    }

    if ($dateDebut !== "") {
        $dateDebutValide = DateTime::createFromFormat("Y-m-d", $dateDebut);
        if (!$dateDebutValide || $dateDebutValide->format("Y-m-d") !== $dateDebut) {
            throw new RuntimeException("La date de début est invalide.");
        }
        $conditions[] = "m.date_mouvement >= :date_debut";
        $params[":date_debut"] = $dateDebut . " 00:00:00";
    }

    if ($dateFin !== "") {
        $dateFinValide = DateTime::createFromFormat("Y-m-d", $dateFin);
        if (!$dateFinValide || $dateFinValide->format("Y-m-d") !== $dateFin) {
            throw new RuntimeException("La date de fin est invalide.");
        }
        $conditions[] = "m.date_mouvement <= :date_fin";
        $params[":date_fin"] = $dateFin . " 23:59:59";
    }

    if ($dateDebut !== "" && $dateFin !== "" && $dateDebut > $dateFin) {
        throw new RuntimeException("La date de début doit être antérieure ou égale à la date de fin.");
    }

    $where = $conditions ? " WHERE " . implode(" AND ", $conditions) : "";

    $sqlBase = "
        FROM mouvements_stock m
        INNER JOIN produits p ON p.id = m.produit_id
        LEFT JOIN utilisateurs u ON u.id = m.utilisateur_id
        $where
    ";

    $stmtStats = $pdo->prepare("
        SELECT COUNT(*) AS total,
               COALESCE(SUM(CASE WHEN m.type_mouvement = 'Entree' THEN m.quantite ELSE 0 END), 0) AS entrees,
               COALESCE(SUM(CASE WHEN m.type_mouvement = 'Sortie' THEN m.quantite ELSE 0 END), 0) AS sorties,
               COALESCE(SUM(CASE WHEN m.type_mouvement = 'Ajustement' THEN 1 ELSE 0 END), 0) AS ajustements
        $sqlBase
    ");
    $stmtStats->execute($params);
    $stats = $stmtStats->fetch();

    $totalMouvements = (int)($stats["total"] ?? 0);
    $totalEntrees = (int)($stats["entrees"] ?? 0);
    $totalSorties = (int)($stats["sorties"] ?? 0);
    $totalAjustements = (int)($stats["ajustements"] ?? 0);
    $nombrePages = max(1, (int)ceil($totalMouvements / $parPage));
    if ($page > $nombrePages) {
        $page = $nombrePages;
        $decalage = ($page - 1) * $parPage;
    }

    $stmtMouvements = $pdo->prepare("
        SELECT m.id, m.type_mouvement, m.quantite, m.motif, m.date_mouvement,
               p.id AS produit_id, p.nom_produit, p.photo,
               u.nom AS nom_utilisateur
        $sqlBase
        ORDER BY m.date_mouvement DESC, m.id DESC
        LIMIT $parPage OFFSET $decalage
    ");
    $stmtMouvements->execute($params);
    $mouvements = $stmtMouvements->fetchAll();

} catch (Throwable $e) {
    $erreur = "Erreur : " . $e->getMessage();
    $nombrePages = 1;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <link rel="stylesheet" href="scrollbar.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mouvements du stock | E-Commerce</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800">
<div class="min-h-screen md:flex">
    <?php include __DIR__ . '/_aside.php'; ?>

    <main class="min-w-0 flex-1 md:ml-64">
        <header class="flex flex-col gap-3 border-b border-slate-200 bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8">
            <div><p class="text-sm text-slate-500">Administration / Stock / Historique</p><h1 class="text-2xl font-bold text-slate-900">Mouvements du stock</h1></div>
            <div class="flex items-center gap-3">
                <div class="hidden text-right sm:block"><p class="text-sm font-semibold"><?= h($nomUtilisateur) ?></p><p class="text-xs text-slate-500"><?= h($roleUtilisateur) ?></p></div>
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100 font-bold text-green-800"><?= h(strtoupper(substr($nomUtilisateur, 0, 1))) ?></div>
                <a href="logout.php" class="rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50">Quitter</a>
            </div>
        </header>

        <div class="p-5 sm:p-8">
            <?php if ($erreur !== ""): ?>
                <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= h($erreur) ?></div>
            <?php endif; ?>

            <div class="mb-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><p class="text-sm text-slate-500">Mouvements trouvés</p><span class="rounded-xl bg-green-100 p-3 text-green-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M7 7h13l-3-3M17 17H4l3 3"/><path d="M20 7a8 8 0 0 1-2.2 5.5M4 17a8 8 0 0 1 2.2-5.5"/></svg></span></div><p class="mt-3 text-3xl font-bold"><?= nombre($totalMouvements) ?></p><p class="mt-1 text-xs text-slate-400">Selon les filtres appliqués</p></div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><p class="text-sm text-slate-500">Quantités entrées</p><span class="rounded-xl bg-green-100 p-3 text-green-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 19V5m-6 6 6-6 6 6"/></svg></span></div><p class="mt-3 text-3xl font-bold text-green-700"><?= nombre($totalEntrees) ?></p><p class="mt-1 text-xs text-slate-400">Total des unités ajoutées</p></div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><p class="text-sm text-slate-500">Quantités sorties</p><span class="rounded-xl bg-red-100 p-3 text-red-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 5v14m6-6-6 6-6-6"/></svg></span></div><p class="mt-3 text-3xl font-bold text-red-600"><?= nombre($totalSorties) ?></p><p class="mt-1 text-xs text-slate-400">Total des unités retirées</p></div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><p class="text-sm text-slate-500">Ajustements</p><span class="rounded-xl bg-blue-100 p-3 text-blue-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.7a8 8 0 0 1-1.8 1l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.8-1l-1.7.7-1.4-2.4 1.4-1.1a7 7 0 0 1 0-2l-1.4-1.1 1.4-2.4 1.7.7a8 8 0 0 1 1.8-1l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.8 1l1.7-.7 1.4 2.4-1.4 1.1a7 7 0 0 1 0 2Z"/></svg></span></div><p class="mt-3 text-3xl font-bold text-blue-700"><?= nombre($totalAjustements) ?></p><p class="mt-1 text-xs text-slate-400">Corrections de stock</p></div>
            </div>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-col gap-4 border-b border-slate-100 p-5">
                    <div><h2 class="text-lg font-bold">Historique des opérations</h2><p class="mt-1 text-sm text-slate-500">Recherchez et consultez les entrées, sorties et ajustements du stock.</p></div>
                    <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <input type="search" name="q" value="<?= h($recherche) ?>" placeholder="Produit, motif, utilisateur..." class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:border-green-600 focus:outline-none">
                        <select name="type" class="rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm">
                            <option value="tous" <?= $typeFiltre === "tous" ? "selected" : "" ?>>Tous les types</option>
                            <option value="Entree" <?= $typeFiltre === "Entree" ? "selected" : "" ?>>Entrées</option>
                            <option value="Sortie" <?= $typeFiltre === "Sortie" ? "selected" : "" ?>>Sorties</option>
                            <option value="Ajustement" <?= $typeFiltre === "Ajustement" ? "selected" : "" ?>>Ajustements</option>
                        </select>
                        <input type="date" name="date_debut" value="<?= h($dateDebut) ?>" aria-label="Date de début" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                        <input type="date" name="date_fin" value="<?= h($dateFin) ?>" aria-label="Date de fin" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                        <div class="flex gap-2">
                            <button class="flex-1 rounded-xl bg-green-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-green-800">Filtrer</button>
                            <a href="mouvements_stock.php" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm hover:bg-slate-50">Effacer</a>
                        </div>
                    </form>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm text-slate-500">
                    <span><?= nombre($totalMouvements) ?> mouvement(s) correspondant aux critères</span>
                    <a href="stock.php" class="font-semibold text-green-700 hover:text-green-900">← Retour à la gestion du stock</a>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[850px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr><th class="px-5 py-4">Date et heure</th><th class="px-5 py-4">Produit</th><th class="px-5 py-4">Type</th><th class="px-5 py-4">Quantité</th><th class="px-5 py-4">Motif</th><th class="px-5 py-4">Utilisateur</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        <?php if (!$mouvements): ?>
                            <tr><td colspan="6" class="px-5 py-12 text-center"><svg class="mx-auto h-8 w-8 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M8 4h8l3 3v13H5V4h3Z"/><path d="M8 4v4h8V4M8 12h8M8 16h8"/></svg><p class="mt-2 font-semibold text-slate-700">Aucun mouvement trouvé</p><p class="mt-1 text-sm text-slate-500">Essayez de modifier vos critères de recherche.</p></td></tr>
                        <?php else: foreach ($mouvements as $m): ?>
                            <tr class="hover:bg-slate-50/70">
                                <td class="whitespace-nowrap px-5 py-4 text-slate-500"><?= h(date("d/m/Y H:i", strtotime($m["date_mouvement"]))) ?></td>
                                <td class="px-5 py-4"><div class="flex items-center gap-3">
                                    <?php if (!empty($m["photo"])): ?><img src="<?= h($m["photo"]) ?>" alt="" class="h-10 w-10 rounded-lg border border-slate-200 object-cover"><?php else: ?><div class="flex h-10 w-10 items-center justify-center rounded-lg bg-green-50 text-green-700"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4.5 7.8 7.5 4.3 7.5-4.3M12 12v9"/></svg></div><?php endif; ?>
                                    <div><p class="font-semibold text-slate-800"><?= h($m["nom_produit"]) ?></p></div>
                                </div></td>
                                <td class="px-5 py-4">
                                    <?php if ($m["type_mouvement"] === "Entree"): ?><span class="rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-700">Entrée</span>
                                    <?php elseif ($m["type_mouvement"] === "Sortie"): ?><span class="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">Sortie</span>
                                    <?php else: ?><span class="rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700">Ajustement</span><?php endif; ?>
                                </td>
                                <td class="px-5 py-4 font-bold"><?= nombre($m["quantite"]) ?></td>
                                <td class="max-w-xs px-5 py-4 text-slate-600"><p class="line-clamp-2"><?= h($m["motif"] ?: "—") ?></p></td>
                                <td class="px-5 py-4 text-slate-500"><?= h($m["nom_utilisateur"] ?? "Utilisateur inconnu") ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-slate-500">Page <?= (int)$page ?> sur <?= (int)($nombrePages ?? 1) ?> — <?= $parPage ?> lignes maximum par page</p>
                    <div class="flex items-center gap-2">
                        <?php
                        $queryParams = $_GET;
                        $queryParams["page"] = max(1, $page - 1);
                        $urlPrecedent = "?" . http_build_query($queryParams);
                        $queryParams["page"] = min($nombrePages ?? 1, $page + 1);
                        $urlSuivant = "?" . http_build_query($queryParams);
                        ?>
                        <a href="<?= h($urlPrecedent) ?>" class="rounded-lg border border-slate-300 px-4 py-2 text-sm <?= $page <= 1 ? "pointer-events-none opacity-40" : "hover:bg-slate-50" ?>">← Précédent</a>
                        <a href="<?= h($urlSuivant) ?>" class="rounded-lg border border-slate-300 px-4 py-2 text-sm <?= $page >= ($nombrePages ?? 1) ? "pointer-events-none opacity-40" : "hover:bg-slate-50" ?>">Suivant →</a>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
