<?php
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['id_utilisateur'])) { header('Location: login.php'); exit; }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { exit('Commande invalide.'); }
$accesRestreint = utilisateurEstLimiteAuPointDeVente();
$succursaleUtilisateurId = $accesRestreint ? exigerSuccursaleUtilisateur() : 0;

$stmt = $pdo->prepare("
    SELECT c.*, cl.nom, cl.postnom, cl.prenom, cl.telephone, cl.email, cl.adresse,
           s.nom AS succursale_nom, s.code AS succursale_code
    FROM commandes c
    INNER JOIN clients cl ON cl.id = c.client_id
    LEFT JOIN succursales s ON s.id = c.succursale_id
    WHERE c.id = ?
    " . ($accesRestreint ? "AND c.succursale_id = ?" : "") . "
    LIMIT 1
");
$stmt->execute($accesRestreint ? [$id, $succursaleUtilisateurId] : [$id]);
$commande = $stmt->fetch();
if (!$commande) {
    http_response_code(404);
    exit('Commande introuvable.');
}

$lignes = $pdo->prepare("
    SELECT dc.quantite, dc.prix_unitaire, dc.sous_total, p.nom_produit
    FROM details_commande dc
    INNER JOIN produits p ON p.id = dc.produit_id
    WHERE dc.commande_id = ?
");
$lignes->execute([$id]);
$details = $lignes->fetchAll();

$tva = (float)parametre('taux_tva', 0);
$totalHT = (float)$commande['montant_total'];
$montantTVA = $totalHT * $tva / 100;
$totalTTC = $totalHT + $montantTVA;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <link rel="stylesheet" href="scrollbar.css">
<meta charset="UTF-8">
<title>Facture <?= h($commande['numero_commande']) ?> | NEXIA</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
    .document-shell {
        width: 606px;
        max-width: calc(100vw - 24px);
        margin: 0 auto;
    }

    .invoice-paper {
        width: 606px;
        max-width: 100%;
        height: 1541px;
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
    }

    .invoice-footer {
        margin-top: auto;
    }

    @page {
        size: 606px 1541px;
        margin: 0;
    }

    @media print {
        .no-print { display: none !important; }
        html, body {
            width: 606px;
            height: 1541px;
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
        }
        .document-shell {
            width: 606px;
            max-width: none;
            margin: 0;
        }
        .invoice-paper {
            width: 606px;
            max-width: none;
            height: 1541px;
            border: 0 !important;
            border-radius: 0 !important;
            box-shadow: none !important;
        }
    }
</style>
</head>
<body class="bg-slate-100 min-h-screen py-8">

<div class="document-shell">

    <!-- Barre d'actions (non imprimée) -->
    <div class="no-print mb-5 flex justify-between items-center">
        <a href="commandes.php" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">← Retour aux commandes</a>
        <button onclick="window.print()" class="inline-flex items-center gap-2 rounded-lg bg-green-700 px-5 py-2 text-sm font-bold text-white hover:bg-green-800"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M7 14h10v7H7zM17 11h.01"/></svg>Imprimer la facture</button>
    </div>

    <!-- Facture -->
    <div class="invoice-paper bg-white rounded-2xl shadow-sm border border-slate-200 p-8">

        <!-- En-tête -->
        <div class="flex justify-between items-start border-b border-slate-200 pb-6">
            <div>
                <div class="flex items-center gap-3">
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-green-700 text-2xl font-black text-white">
                        <?= h(strtoupper(substr(parametre('sigle_entreprise', 'N'), 0, 1))) ?>
                    </div>
                    <div>
                        <h1 class="text-2xl font-extrabold text-slate-900"><?= h(parametre('nom_entreprise', 'NEXIA')) ?></h1>
                        <p class="text-xs text-slate-500"><?= h(parametre('adresse_entreprise', '')) ?></p>
                    </div>
                </div>
                <div class="mt-3 text-xs text-slate-500 space-y-0.5">
                    <p>Tél : <?= h(parametre('telephone_entreprise', '')) ?></p>
                    <p>E-mail : <?= h(parametre('email_entreprise', '')) ?></p>
                </div>
            </div>
            <div class="text-right">
                <p class="text-xs uppercase tracking-wide text-slate-400">Facture</p>
                <p class="text-xl font-extrabold text-green-700"><?= h($commande['numero_commande']) ?></p>
                <p class="text-xs text-slate-500 mt-1"><?= h(date('d/m/Y H:i', strtotime($commande['date_commande']))) ?></p>
                <?php if ($commande['succursale_nom']): ?>
                    <p class="text-xs text-slate-500 mt-2">Point de vente : <span class="font-semibold"><?= h($commande['succursale_nom']) ?></span></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Client -->
        <div class="grid grid-cols-2 gap-6 py-6 border-b border-slate-200">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-400 mb-2">Client</p>
                <p class="font-bold text-slate-800"><?= h(trim($commande['prenom'] . ' ' . $commande['nom'] . ' ' . $commande['postnom'])) ?></p>
                <p class="text-sm text-slate-600"><?= h($commande['telephone']) ?></p>
                <?php if ($commande['email']): ?><p class="text-sm text-slate-600"><?= h($commande['email']) ?></p><?php endif; ?>
                <?php if ($commande['adresse']): ?><p class="text-sm text-slate-600"><?= h($commande['adresse']) ?></p><?php endif; ?>
            </div>
            <div class="text-right">
                <p class="text-xs uppercase tracking-wide text-slate-400 mb-2">Statut</p>
                <span class="inline-block rounded-full bg-green-50 px-3 py-1 text-sm font-semibold text-green-700"><?= h($commande['statut']) ?></span>
            </div>
        </div>

        <!-- Détails -->
        <div class="py-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b-2 border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <th class="py-2 text-left">Désignation</th>
                        <th class="py-2 text-right">Qté</th>
                        <th class="py-2 text-right">P.U.</th>
                        <th class="py-2 text-right">Sous-total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($details as $d): ?>
                    <tr class="border-b border-slate-100">
                        <td class="py-3 font-medium text-slate-800"><?= h($d['nom_produit']) ?></td>
                        <td class="py-3 text-right text-slate-600"><?= (int)$d['quantite'] ?></td>
                        <td class="py-3 text-right text-slate-600"><?= h(formatMontant($d['prix_unitaire'])) ?></td>
                        <td class="py-3 text-right font-semibold text-slate-800"><?= h(formatMontant($d['sous_total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Totaux -->
        <div class="flex justify-end border-t border-slate-200 pt-4">
            <div class="w-64 space-y-1.5 text-sm">
                <div class="flex justify-between text-slate-600">
                    <span>Total HT</span>
                    <span><?= h(formatMontant($totalHT)) ?></span>
                </div>
                <div class="flex justify-between text-slate-600">
                    <span>TVA (<?= h($tva) ?>%)</span>
                    <span><?= h(formatMontant($montantTVA)) ?></span>
                </div>
                <div class="flex justify-between border-t border-slate-200 pt-2 text-lg font-extrabold text-green-700">
                    <span>Total TTC</span>
                    <span><?= h(formatMontant($totalTTC)) ?></span>
                </div>
            </div>
        </div>

        <!-- Pied de page -->
        <div class="invoice-footer mt-8 pt-4 border-t border-slate-200 text-center text-xs text-slate-500">
            <p class="font-semibold"><?= h(parametre('facture_mention_legale', 'Merci de votre confiance !')) ?></p>
            <p class="mt-1"><?= h(parametre('facture_pied_page', '')) ?></p>
        </div>
    </div>

</div>
</body>
</html>