<?php
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['id_utilisateur'])) { header('Location: login.php'); exit; }

$nomUtilisateur  = $_SESSION['nom'] ?? 'Administrateur';
$roleUtilisateur = $_SESSION['role'] ?? 'Administrateur';
$accesRestreint = utilisateurEstLimiteAuPointDeVente();
$succursaleUtilisateurId = $accesRestreint ? exigerSuccursaleUtilisateur() : 0;
$pointDeVenteCentral = $accesRestreint && $succursaleUtilisateurId === succursaleCentraleId();

$stats = [
    'produits' => 0, 'categories' => 0, 'clients' => 0, 'commandes' => 0,
    'stock_general' => 0, 'stock_succursales' => 0, 'alertes' => 0,
    'ventes' => 0, 'succursales' => 0
];
$commandesRecentes = [];
$stocksFaibles = [];
$ventesParSuccursale = [];
$erreur = '';

try {
    // --- Statistiques de base ---
    $stats['produits']    = (int)$pdo->query("SELECT COUNT(*) FROM produits")->fetchColumn();
    $stats['categories']  = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if (!$accesRestreint) {
        $stats['clients'] = (int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
        $stats['succursales'] = (int)$pdo->query("SELECT COUNT(*) FROM succursales WHERE statut='Active'")->fetchColumn();
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM commandes WHERE succursale_id = ?");
        $stmt->execute([$succursaleUtilisateurId]);
        $stats['commandes'] = (int)$stmt->fetchColumn();
        $stats['succursales'] = 1;
    }
    if (!$accesRestreint) {
        $stats['commandes'] = (int)$pdo->query("SELECT COUNT(*) FROM commandes")->fetchColumn();
    }

    // --- Stocks ---
    if ($accesRestreint) {
        if ($pointDeVenteCentral) {
            $stats['stock_succursales'] = (int)$pdo->query("SELECT COALESCE(SUM(quantite),0) FROM stocks")->fetchColumn();
        } else {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantite),0) FROM stocks_succursales WHERE succursale_id = ?");
            $stmt->execute([$succursaleUtilisateurId]);
            $stats['stock_succursales'] = (int)$stmt->fetchColumn();
        }
    } else {
        $stats['stock_general'] = (int)$pdo->query("SELECT COALESCE(SUM(quantite),0) FROM stocks")->fetchColumn();
        $stats['stock_succursales'] = (int)$pdo->query("SELECT COALESCE(SUM(quantite),0) FROM stocks_succursales")->fetchColumn();
    }

    // Alertes : produits sous le seuil, dans le stock général OU dans une succursale
    $seuilDefaut = (int)parametre('seuil_alerte_defaut', 5);
    if ($pointDeVenteCentral) {
        $stats['alertes'] = (int)$pdo->query("
            SELECT COUNT(*) FROM produits p
            LEFT JOIN stocks s ON s.produit_id = p.id
            WHERE COALESCE(s.quantite, 0) <= COALESCE(s.seuil_alerte, $seuilDefaut)
        ")->fetchColumn();
    } elseif ($accesRestreint) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM stocks_succursales
            WHERE succursale_id = ? AND quantite <= seuil_alerte
        ");
        $stmt->execute([$succursaleUtilisateurId]);
        $stats['alertes'] = (int)$stmt->fetchColumn();
    } else {
        $stats['alertes'] = (int)$pdo->query("
        SELECT COUNT(*) FROM (
            SELECT p.id FROM produits p
            LEFT JOIN stocks s ON s.produit_id = p.id
            WHERE COALESCE(s.quantite,0) <= COALESCE(s.seuil_alerte, $seuilDefaut)
            UNION
            SELECT p.id FROM produits p
            INNER JOIN stocks_succursales ss ON ss.produit_id = p.id
            WHERE ss.quantite <= ss.seuil_alerte
        ) AS alertes
        ")->fetchColumn();
    }

    // --- Ventes ---
    if ($accesRestreint) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(p.montant),0)
            FROM paiements p
            INNER JOIN commandes c ON c.id = p.commande_id
            WHERE p.statut = 'Valide' AND c.succursale_id = ?
        ");
        $stmt->execute([$succursaleUtilisateurId]);
        $stats['ventes'] = (float)$stmt->fetchColumn();
    } else {
        $stats['ventes'] = (float)$pdo->query("
            SELECT COALESCE(SUM(montant),0) FROM paiements WHERE statut='Valide'
        ")->fetchColumn();
    }

    // --- Commandes récentes ---
    $sqlCommandesRecentes = "
        SELECT c.id, c.numero_commande, c.date_commande, c.montant_total, c.statut,
               cl.nom, cl.postnom, cl.prenom,
               s.nom AS succursale_nom
        FROM commandes c
        INNER JOIN clients cl ON cl.id = c.client_id
        LEFT JOIN succursales s ON s.id = c.succursale_id
        " . ($accesRestreint ? "WHERE c.succursale_id = ?" : "") . "
        ORDER BY c.date_commande DESC
        LIMIT 6
    ";
    $stmt = $pdo->prepare($sqlCommandesRecentes);
    $stmt->execute($accesRestreint ? [$succursaleUtilisateurId] : []);
    $commandesRecentes = $stmt->fetchAll();

    // --- Stocks faibles (général pour le central, succursale sinon) ---
    if ($pointDeVenteCentral) {
        $stocksFaibles = $pdo->query("
            SELECT p.nom_produit, 'Stock général' AS emplacement,
                   COALESCE(s.quantite, 0) AS quantite,
                   COALESCE(s.seuil_alerte, $seuilDefaut) AS seuil_alerte
            FROM produits p
            LEFT JOIN stocks s ON s.produit_id = p.id
            WHERE COALESCE(s.quantite, 0) <= COALESCE(s.seuil_alerte, $seuilDefaut)
            ORDER BY quantite ASC
            LIMIT 8
        ")->fetchAll();
    } elseif ($accesRestreint) {
        $stmt = $pdo->prepare("
            SELECT p.nom_produit, sc.nom AS emplacement, ss.quantite, ss.seuil_alerte
            FROM stocks_succursales ss
            INNER JOIN produits p ON p.id = ss.produit_id
            INNER JOIN succursales sc ON sc.id = ss.succursale_id
            WHERE ss.succursale_id = ? AND ss.quantite <= ss.seuil_alerte
            ORDER BY ss.quantite ASC
            LIMIT 8
        ");
        $stmt->execute([$succursaleUtilisateurId]);
        $stocksFaibles = $stmt->fetchAll();
    } else {
        $stocksFaibles = $pdo->query("
        SELECT p.nom_produit, 'Stock général' AS emplacement,
               COALESCE(s.quantite,0) AS quantite, COALESCE(s.seuil_alerte, $seuilDefaut) AS seuil_alerte
        FROM produits p
        LEFT JOIN stocks s ON s.produit_id = p.id
        WHERE COALESCE(s.quantite,0) <= COALESCE(s.seuil_alerte, $seuilDefaut)
        UNION ALL
        SELECT p.nom_produit, sc.nom AS emplacement, ss.quantite, ss.seuil_alerte
        FROM stocks_succursales ss
        INNER JOIN produits p ON p.id = ss.produit_id
        INNER JOIN succursales sc ON sc.id = ss.succursale_id
        WHERE ss.quantite <= ss.seuil_alerte
        ORDER BY quantite ASC
        LIMIT 8
        ")->fetchAll();
    }

    // --- Ventes par succursale ---
    $sqlVentesParSuccursale = "
        SELECT s.nom, s.code, s.type,
               COUNT(c.id) AS nb_commandes,
               COALESCE(SUM(CASE WHEN c.statut != 'Annulee' THEN c.montant_total ELSE 0 END), 0) AS montant
        FROM succursales s
        LEFT JOIN commandes c ON c.succursale_id = s.id
        " . ($accesRestreint ? "WHERE s.id = ?" : "") . "
        GROUP BY s.id
        ORDER BY montant DESC
    ";
    $stmt = $pdo->prepare($sqlVentesParSuccursale);
    $stmt->execute($accesRestreint ? [$succursaleUtilisateurId] : []);
    $ventesParSuccursale = $stmt->fetchAll();

} catch (PDOException $e) {
    $erreur = "Erreur de base de données : " . $e->getMessage();
}

$nomEntreprise = parametre('nom_entreprise', 'NEXIA');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <link rel="stylesheet" href="scrollbar.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord | <?= h($nomEntreprise) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        boutique: {
                            50: '#f0fdf4', 100: '#dcfce7', 500: '#22c55e',
                            600: '#16a34a', 700: '#15803d', 800: '#166534', 900: '#14532d'
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
<div class="min-h-screen md:flex">

    <!-- Barre latérale -->
    <?php include __DIR__ . '/_aside.php'; ?>

    <!-- Contenu principal -->
    <main class="min-w-0 flex-1 md:ml-64">

        <!-- En-tête -->
        <header class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur sm:px-8">
            <div>
                <p class="text-xs font-medium text-slate-400">Administration / Tableau de bord</p>
                <h1 class="mt-1 text-xl font-extrabold text-slate-900 sm:text-2xl">Vue d'ensemble</h1>
            </div>
            <div class="flex items-center gap-3">
                <div class="hidden text-right sm:block">
                    <p class="text-sm font-bold text-slate-800"><?= h($nomUtilisateur) ?></p>
                    <p class="text-xs text-green-600"><?= h($roleUtilisateur) ?></p>
                </div>
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100 font-bold text-green-700">
                    <?= h(strtoupper(substr($nomUtilisateur, 0, 1))) ?>
                </div>
            </div>
        </header>

        <div class="p-5 sm:p-8">

            <?php if ($erreur !== ''): ?>
                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= h($erreur) ?></div>
            <?php endif; ?>

            <!-- Bandeau de bienvenue -->
            <section class="mb-7 overflow-hidden rounded-2xl bg-gradient-to-r from-green-700 to-emerald-500 p-6 text-white shadow-lg shadow-green-900/10 sm:p-8">
                <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-center">
                    <div>
                        <p class="text-sm font-medium text-green-100">Bienvenue sur votre espace</p>
                        <h2 class="mt-2 text-2xl font-extrabold sm:text-3xl">Bonjour, <?= h($nomUtilisateur) ?> !</h2>
                        <p class="mt-2 max-w-xl text-sm leading-6 text-green-50">
                            <?= $accesRestreint
                                ? 'Suivez les ventes, les commandes et le stock de votre point de vente.'
                                : 'Pilotez votre réseau de ' . (int)$stats['succursales'] . ' succursale(s), suivez vos stocks et vos ventes en temps réel.' ?>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a href="transferts_stock.php" class="inline-flex w-fit items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-bold text-green-700 shadow transition hover:-translate-y-0.5 hover:bg-green-50">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 8h15l-3-3M20 16H5l3 3"/><path d="M19 8v4M5 12v4"/></svg>
                            Affecter du stock
                        </a>
                        <a href="commandes.php" class="inline-flex w-fit items-center gap-2 rounded-xl bg-green-900/40 px-5 py-3 text-sm font-bold text-white shadow transition hover:bg-green-900/60">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M7 14h10v7H7zM17 11h.01"/></svg>
                            Commandes et factures
                        </a>
                    </div>
                </div>
            </section>

            <!-- Cartes statistiques -->
            <section class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">

                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-500">Produits</span>
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-green-100 text-green-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4.5 7.8 7.5 4.3 7.5-4.3M12 12v9"/></svg></span>
                    </div>
                    <p class="mt-4 text-3xl font-extrabold text-slate-900"><?= number_format($stats['produits'], 0, ',', ' ') ?></p>
                    <p class="mt-1 text-xs text-slate-400"><?= number_format($stats['categories'], 0, ',', ' ') ?> catégorie(s)</p>
                </div>

                <?php if (!$accesRestreint): ?>
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-500">Clients</span>
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-100 text-blue-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20v-1.5a6.5 6.5 0 0 1 13 0V20M16 5.2a3.5 3.5 0 0 1 0 6.6M18 14a5 5 0 0 1 3.5 4.8V20"/></svg></span>
                    </div>
                    <p class="mt-4 text-3xl font-extrabold text-slate-900"><?= number_format($stats['clients'], 0, ',', ' ') ?></p>
                    <p class="mt-1 text-xs text-slate-400">Clients enregistrés</p>
                </div>
                <?php endif; ?>

                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-500"><?= $accesRestreint ? 'Commandes du point de vente' : 'Commandes' ?></span>
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-100 text-amber-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h8.5a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="10" cy="19" r="1.2"/><circle cx="18" cy="19" r="1.2"/></svg></span>
                    </div>
                    <p class="mt-4 text-3xl font-extrabold text-slate-900"><?= number_format($stats['commandes'], 0, ',', ' ') ?></p>
                    <p class="mt-1 text-xs text-slate-400"><?= $accesRestreint ? 'Votre succursale' : 'Toutes succursales' ?></p>
                </div>

                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-500">Chiffre d'affaires</span>
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 2v20M17 6.5c0-1.7-1.8-3-5-3S7 5 7 6.8s1.8 2.7 5 3.2 5 1.5 5 3.5-2 3.5-5 3.5-5-1.3-5-3"/></svg></span>
                    </div>
                    <p class="mt-4 text-2xl font-extrabold text-green-700"><?= h(formatMontant($stats['ventes'])) ?></p>
                    <p class="mt-1 text-xs text-slate-400">Paiements validés</p>
                </div>
            </section>

            <!-- Cartes réseau / stock -->
            <section class="mb-8 grid grid-cols-1 gap-4 <?= $accesRestreint ? 'sm:grid-cols-1' : 'sm:grid-cols-3' ?>">

                <?php if (!$accesRestreint): ?>
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-500">Réseau NEXIA</span>
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-green-100 text-green-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-5h6v5M8 9h.01M12 9h.01M16 9h.01"/></svg></span>
                    </div>
                    <p class="mt-4 text-3xl font-extrabold text-slate-900"><?= (int)$stats['succursales'] ?></p>
                    <p class="mt-1 text-xs text-slate-400">Succursale(s) active(s)</p>
                    <a href="succursales.php" class="mt-3 inline-block text-xs font-semibold text-green-700 hover:underline">Gérer →</a>
                </div>

                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-500">Stock général</span>
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5v-9Z"/><path d="m3.5 7.8 8.5 4.4 8.5-4.4M12 12.2V21"/></svg></span>
                    </div>
                    <p class="mt-4 text-3xl font-extrabold text-slate-900"><?= number_format($stats['stock_general'], 0, ',', ' ') ?></p>
                    <p class="mt-1 text-xs text-slate-400">Unités en centrale</p>
                </div>
                <?php endif; ?>

                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-500"><?= $accesRestreint ? 'Stock de votre point de vente' : 'Stock succursales' ?></span>
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-violet-100 text-violet-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 8h15l-3-3M20 16H5l3 3"/></svg></span>
                    </div>
                    <p class="mt-4 text-3xl font-extrabold text-slate-900"><?= number_format($stats['stock_succursales'], 0, ',', ' ') ?></p>
                    <p class="mt-1 text-xs text-slate-400">Unités affectées</p>
                </div>
            </section>

            <!-- Deux colonnes : ventes par succursale + alertes -->
            <section class="mb-8 grid grid-cols-1 gap-6 xl:grid-cols-3">

                <!-- Ventes par succursale -->
                <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm xl:col-span-2">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                        <div>
                            <h3 class="font-bold text-slate-900"><?= $accesRestreint ? 'Activité de votre point de vente' : 'Ventes par succursale' ?></h3>
                            <p class="mt-1 text-xs text-slate-400"><?= $accesRestreint ? 'Commandes et montants de votre succursale' : 'Performance du réseau' ?></p>
                        </div>
                        <span class="rounded-lg bg-green-50 px-3 py-1.5 text-xs font-semibold text-green-700">
                            <?= count($ventesParSuccursale) ?> <?= $accesRestreint ? 'point de vente' : 'établissement(s)' ?>
                        </span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[600px] text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 font-semibold">Succursale</th>
                                    <th class="px-5 py-3 font-semibold">Type</th>
                                    <th class="px-5 py-3 font-semibold">Commandes</th>
                                    <th class="px-5 py-3 text-right font-semibold">Montant</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            <?php if (empty($ventesParSuccursale)): ?>
                                <tr>
                                    <td colspan="4" class="px-5 py-10 text-center text-sm text-slate-400">
                                        Aucune succursale enregistrée.
                                    </td>
                                </tr>
                            <?php else: foreach ($ventesParSuccursale as $v): ?>
                                <tr class="transition hover:bg-green-50/40">
                                    <td class="px-5 py-4">
                                        <p class="font-semibold text-slate-800"><?= h($v['nom']) ?></p>
                                        <p class="mt-1 text-xs text-slate-400"><?= h($v['code']) ?></p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">
                                            <?= h(str_replace('_', ' ', $v['type'])) ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-700">
                                            <?= (int)$v['nb_commandes'] ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-right font-bold text-green-700">
                                        <?= h(formatMontant($v['montant'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Alertes stock -->
                <div class="rounded-2xl border border-slate-100 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                        <div>
                            <h3 class="font-bold text-slate-900">Alertes de stock</h3>
                            <p class="mt-1 text-xs text-slate-400"><?= (int)$stats['alertes'] ?> produit(s)</p>
                        </div>
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-100 text-amber-700"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M10.3 4.3 2.8 17.2A2 2 0 0 0 4.5 20h15a2 2 0 0 0 1.7-2.8L13.7 4.3a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4m0 3h.01"/></svg></span>
                    </div>
                    <ul class="divide-y divide-slate-100">
                        <?php if (empty($stocksFaibles)): ?>
                            <li class="px-5 py-8 text-center text-sm text-slate-400">Aucune alerte de stock</li>
                        <?php else: foreach ($stocksFaibles as $sf): ?>
                            <li class="flex items-start justify-between gap-3 px-5 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-slate-800"><?= h($sf['nom_produit']) ?></p>
                                    <p class="mt-0.5 text-xs text-slate-400"><?= h($sf['emplacement']) ?></p>
                                </div>
                                <span class="shrink-0 rounded-full bg-red-50 px-2.5 py-1 text-xs font-bold text-red-700">
                                    <?= (int)$sf['quantite'] ?>
                                </span>
                            </li>
                        <?php endforeach; endif; ?>
                    </ul>
                </div>
            </section>

            <!-- Commandes récentes -->
            <section class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <div>
                        <h3 class="font-bold text-slate-900">Commandes récentes</h3>
                        <p class="mt-1 text-xs text-slate-400">Les 6 dernières commandes</p>
                    </div>
                    <a href="commandes.php" class="rounded-lg bg-green-50 px-3 py-2 text-xs font-semibold text-green-700 hover:bg-green-100">Tout voir</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[700px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Commande</th>
                                <th class="px-5 py-3 font-semibold">Client</th>
                                <th class="px-5 py-3 font-semibold">Succursale</th>
                                <th class="px-5 py-3 font-semibold">Montant</th>
                                <th class="px-5 py-3 font-semibold">Statut</th>
                                <th class="px-5 py-3 text-right font-semibold">Facture</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        <?php if (empty($commandesRecentes)): ?>
                            <tr>
                                <td colspan="6" class="px-5 py-10 text-center text-sm text-slate-400">
                                    Aucune commande enregistrée.
                                </td>
                            </tr>
                        <?php else: foreach ($commandesRecentes as $c): ?>
                            <tr class="transition hover:bg-green-50/40">
                                <td class="px-5 py-4">
                                    <p class="font-bold text-green-700 text-sm"><?= h($c['numero_commande']) ?></p>
                                    <p class="mt-1 text-xs text-slate-400"><?= h(date('d/m/Y H:i', strtotime($c['date_commande']))) ?></p>
                                </td>
                                <td class="px-5 py-4 font-semibold text-slate-800">
                                    <?= h(trim($c['prenom'] . ' ' . $c['nom'] . ' ' . $c['postnom'])) ?>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                        <?= h($c['succursale_nom'] ?? '—') ?>
                                    </span>
                                </td>
                                <td class="px-5 py-4 font-bold text-slate-800"><?= h(formatMontant($c['montant_total'])) ?></td>
                                <td class="px-5 py-4">
                                    <span class="rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">
                                        <?= h($c['statut']) ?>
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <a href="facture.php?id=<?= (int)$c['id'] ?>" target="_blank"
                                       class="inline-flex items-center gap-1 rounded-lg bg-slate-900 px-3 py-2 text-xs font-bold text-white hover:bg-slate-700">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M7 14h10v7H7zM17 11h.01"/></svg>
                                        Imprimer
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Pied de page -->
            <footer class="py-7 text-center text-xs text-slate-400">
                © <?= date('Y') ?> <?= h($nomEntreprise) ?> — Tous droits réservés.
            </footer>
        </div>
    </main>
</div>
</body>
</html>