<?php
$pageCourante = basename($_SERVER['PHP_SELF'] ?? '');
$accesRestreint = in_array($_SESSION['role'] ?? '', ['Caissier', 'Vendeur'], true);
if ($pageCourante === 'voir_commande.php' || $pageCourante === 'facture.php') {
    $pageCourante = 'commandes.php';
} elseif ($pageCourante === 'stocks.php') {
    $pageCourante = 'stock.php';
}
$classesLienNav = static function (string $fichier) use ($pageCourante): string {
    $base = 'flex shrink-0 items-center gap-3 rounded-xl px-4 py-3 text-sm transition';
    return $pageCourante === $fichier
        ? $base . ' bg-green-800 font-semibold text-white'
        : $base . ' text-green-100 hover:bg-white/10';
};
?>
<style>
    @media (min-width: 768px) {
        html,
        body {
            height: 100%;
        }

        body {
            overflow: hidden;
        }

        body > .min-h-screen {
            height: 100vh;
        }

        main {
            height: 100vh;
            overflow-y: auto;
            overscroll-behavior-y: contain;
        }
    }
</style>
<aside class="w-full bg-green-950 text-white md:fixed md:inset-y-0 md:left-0 md:flex md:w-64 md:flex-col">
    <div class="flex items-center gap-3 border-b border-white/10 px-6 py-5">
        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-green-500 text-xl font-black">E</div>
        <div>
            <p class="text-lg font-extrabold tracking-wide">E-COMMERCE</p>
            <p class="text-xs text-green-200">Administration</p>
        </div>
    </div>

    <nav aria-label="Navigation principale" class="flex gap-1 overflow-x-auto p-3 md:flex-1 md:flex-col md:overflow-y-auto">
        <p class="hidden px-3 pb-2 pt-3 text-[10px] font-bold uppercase tracking-[.18em] text-green-300 md:block">Menu principal</p>
        <a href="dashboard.php" class="<?= $classesLienNav('dashboard.php') ?>" <?= $pageCourante === 'dashboard.php' ? 'aria-current="page"' : '' ?>>
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="5" rx="1"/><rect x="13" y="10" width="8" height="11" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/></svg>
            Tableau de bord
        </a>
        <?php if (!$accesRestreint): ?>
            <a href="produits.php" class="<?= $classesLienNav('produits.php') ?>" <?= $pageCourante === 'produits.php' ? 'aria-current="page"' : '' ?>>
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4.5 7.8 7.5 4.3 7.5-4.3M12 12v9"/></svg>
                Produits
            </a>
            <a href="categories.php" class="<?= $classesLienNav('categories.php') ?>" <?= $pageCourante === 'categories.php' ? 'aria-current="page"' : '' ?>>
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 5h16M4 12h16M4 19h16"/><circle cx="8" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="10" cy="19" r="1"/></svg>
                Catégories
            </a>
        <?php endif; ?>
        <a href="stock.php" class="<?= $classesLienNav('stock.php') ?>" <?= $pageCourante === 'stock.php' ? 'aria-current="page"' : '' ?>>
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5v-9Z"/><path d="m3.5 7.8 8.5 4.4 8.5-4.4M12 12.2V21"/></svg>
            Gestion de stock
        </a>
        <a href="mouvements_stock.php" class="<?= $classesLienNav('mouvements_stock.php') ?>" <?= $pageCourante === 'mouvements_stock.php' ? 'aria-current="page"' : '' ?>>
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M7 7h13l-3-3M17 17H4l3 3"/><path d="M20 7a8 8 0 0 1-2.2 5.5M4 17a8 8 0 0 1 2.2-5.5"/></svg>
            Mouvements de stock
        </a>
        <a href="transferts_stock.php" class="<?= $classesLienNav('transferts_stock.php') ?>" <?= $pageCourante === 'transferts_stock.php' ? 'aria-current="page"' : '' ?>>
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 8h15l-3-3M20 16H5l3 3"/><path d="M19 8v4M5 12v4"/></svg>
            Transferts
        </a>

        <p class="hidden px-3 pb-2 pt-5 text-[10px] font-bold uppercase tracking-[.18em] text-green-300 md:block">Ventes</p>
        <a href="vente_directe.php" class="<?= $classesLienNav('vente_directe.php') ?>" <?= $pageCourante === 'vente_directe.php' ? 'aria-current="page"' : '' ?>>
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h8.5a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="10" cy="19" r="1.2"/><circle cx="18" cy="19" r="1.2"/></svg>
            Vente directe
        </a>
        <?php if (!$accesRestreint): ?>
            <a href="clients.php" class="<?= $classesLienNav('clients.php') ?>" <?= $pageCourante === 'clients.php' ? 'aria-current="page"' : '' ?>>
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20v-1.5a6.5 6.5 0 0 1 13 0V20M16 5.2a3.5 3.5 0 0 1 0 6.6M18 14a5 5 0 0 1 3.5 4.8V20"/></svg>
                Clients
            </a>
        <?php endif; ?>
        <a href="commandes.php" class="<?= $classesLienNav('commandes.php') ?>" <?= $pageCourante === 'commandes.php' ? 'aria-current="page"' : '' ?>>
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h8.5a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="10" cy="19" r="1.2"/><circle cx="18" cy="19" r="1.2"/></svg>
            Commandes
        </a>

        <?php if (!$accesRestreint): ?>
        <p class="hidden px-3 pb-2 pt-5 text-[10px] font-bold uppercase tracking-[.18em] text-green-300 md:block">Réseau</p>
        <a href="succursales.php" class="<?= $classesLienNav('succursales.php') ?>" <?= $pageCourante === 'succursales.php' ? 'aria-current="page"' : '' ?>>
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-5h6v5M8 9h.01M12 9h.01M16 9h.01"/></svg>
            Succursales
        </a>
         <a href="caisse.php" class="<?= $classesLienNav('caisse.php') ?>" <?= $pageCourante === 'caisse.php' ? 'aria-current="page"' : '' ?>>
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 8h15l-3-3M20 16H5l3 3"/><path d="M19 8v4M5 12v4"/></svg>
            caisse
        </a>
        
        <a href="utilisateurs.php" class="<?= $classesLienNav('utilisateurs.php') ?>" <?= $pageCourante === 'utilisateurs.php' ? 'aria-current="page"' : '' ?>>
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20v-1.5a6.5 6.5 0 0 1 13 0V20M16 5.2a3.5 3.5 0 0 1 0 6.6M18 14a5 5 0 0 1 3.5 4.8V20"/></svg>
            utilisateurs
        </a>
        <a href="parametres.php" class="<?= $classesLienNav('parametres.php') ?>" <?= $pageCourante === 'parametres.php' ? 'aria-current="page"' : '' ?>>
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.7a8 8 0 0 1-1.8 1l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.8-1l-1.7.7-1.4-2.4 1.4-1.1a7 7 0 0 1 0-2l-1.4-1.1 1.4-2.4 1.7.7a8 8 0 0 1 1.8-1l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.8 1l1.7-.7 1.4 2.4-1.4 1.1a7 7 0 0 1 0 2Z"/></svg>
            Paramètres
        </a>
        <?php endif; ?>

        <div class="mt-auto hidden border-t border-white/10 pt-4 md:block">
            <a href="index.php" target="_blank" rel="noopener" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm text-green-100 transition hover:bg-white/10">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9"/><path d="M18 13v6a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h6"/></svg>
                Voir la boutique
            </a>
            <a href="logout.php" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm text-red-200 transition hover:bg-red-500/10">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg>
                Déconnexion
            </a>
        </div>
    </nav>
    <div class="flex items-center justify-between border-t border-white/10 px-5 py-3 md:hidden">
        <a href="index.php" target="_blank" rel="noopener" class="text-xs text-green-100">Voir la boutique</a>
        <a href="logout.php" class="text-xs text-red-200">Déconnexion</a>
    </div>
</aside>
