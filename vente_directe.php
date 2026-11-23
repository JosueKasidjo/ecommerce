<?php
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['id_utilisateur'])) { header('Location: login.php'); exit; }

$nomUtilisateur  = $_SESSION['nom'] ?? 'Administrateur';
$roleUtilisateur = $_SESSION['role'] ?? 'Administrateur';
$nomEntreprise   = parametre('nom_entreprise', 'NEXIA');
$tauxTVA         = (float)parametre('taux_tva', 0);

$erreur = ''; $message = ''; $derniereCommandeId = 0;

// --- Succursales actives ---
$succursales = $pdo->query("SELECT id, nom, code, type FROM succursales WHERE statut='Active' ORDER BY type='CENTRALE' DESC, nom")->fetchAll();
$succursaleDefaut = (int)parametre('succursale_par_defaut', 1);
if (!array_filter($succursales, fn($s) => (int)$s['id'] === $succursaleDefaut)) {
    $succursaleDefaut = (int)($succursales[0]['id'] ?? 0);
}
$succursaleCentraleId = 0;
foreach ($succursales as $succursale) {
    if (strtoupper((string)$succursale['type']) === 'CENTRALE') {
        $succursaleCentraleId = (int)$succursale['id'];
        break;
    }
}

// ============================================================
// TRAITEMENT DE LA VENTE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vendre') {
    $succId     = (int)($_POST['succursale_id'] ?? 0);
    $clientNom  = trim($_POST['client_nom'] ?? '');
    $clientTel  = trim($_POST['client_telephone'] ?? '');
    $methode    = $_POST['methode_paiement'] ?? 'Espece';
    $panierJson = $_POST['panier_json'] ?? '[]';
    $panier     = json_decode($panierJson, true);

    if ($succId <= 0) {
        $erreur = "Veuillez choisir un point de vente.";
    } elseif (!is_array($panier) || empty($panier)) {
        $erreur = "Le panier est vide. Ajoutez au moins un produit.";
    } elseif ($clientNom === '') {
        $erreur = "Veuillez indiquer le nom du client (ou 'Client anonyme').";
    } else {
        try {
            $pdo->beginTransaction();

            // 1) Récupérer ou créer le client (par téléphone si fourni, sinon anonyme)
            $clientId = 0;
            if ($clientTel !== '') {
                $stmt = $pdo->prepare("SELECT id FROM clients WHERE telephone = ? LIMIT 1");
                $stmt->execute([$clientTel]);
                $clientId = (int)($stmt->fetchColumn() ?: 0);
            }
            if ($clientId === 0) {
                // Créer client minimal
                $parts = preg_split('/\s+/', $clientNom, 2);
                $prenom = $parts[0] ?? 'Client';
                $nom    = $parts[1] ?? 'Anonyme';
                $stmt = $pdo->prepare("
                    INSERT INTO clients (nom, postnom, prenom, telephone, email, adresse, date_creation)
                    VALUES (?, '', ?, ?, NULL, NULL, NOW())
                ");
                $stmt->execute([$nom, $prenom, $clientTel ?: '000000']);
                $clientId = (int)$pdo->lastInsertId();
            }

            // 2) Calcul du total + vérification du stock
            $total = 0;
            $lignesValides = [];
            foreach ($panier as $item) {
                $pid = (int)($item['produit_id'] ?? 0);
                $qte = max(1, (int)($item['quantite'] ?? 1));
                if ($pid <= 0) continue;

                // Récupérer produit + prix
                $stmt = $pdo->prepare("SELECT id, nom_produit, prix_vente FROM produits WHERE id = ? AND statut='Disponible'");
                $stmt->execute([$pid]);
                $prod = $stmt->fetch();
                if (!$prod) throw new RuntimeException("Produit #$pid introuvable ou inactif.");

                // Vérifier le stock au bon endroit
                if ($succId === $succursaleCentraleId) {
                    // Centrale → stock général
                    $stmtS = $pdo->prepare("SELECT quantite FROM stocks WHERE produit_id = ? FOR UPDATE");
                    $stmtS->execute([$pid]);
                    $stockDispo = (int)($stmtS->fetchColumn() ?: 0);
                } else {
                    $stmtS = $pdo->prepare("SELECT quantite FROM stocks_succursales WHERE produit_id = ? AND succursale_id = ? FOR UPDATE");
                    $stmtS->execute([$pid, $succId]);
                    $stockDispo = (int)($stmtS->fetchColumn() ?: 0);
                }

                if ($stockDispo < $qte) {
                    throw new RuntimeException("Stock insuffisant pour « {$prod['nom_produit']} » (disponible : $stockDispo, demandé : $qte).");
                }

                $prix  = (float)$prod['prix_vente'];
                $sous  = $prix * $qte;
                $total += $sous;
                $lignesValides[] = [
                    'produit_id' => $pid,
                    'nom'        => $prod['nom_produit'],
                    'prix'       => $prix,
                    'quantite'   => $qte,
                    'sous_total' => $sous,
                ];
            }

            if (empty($lignesValides)) {
                throw new RuntimeException("Aucune ligne valide dans le panier.");
            }

            // 3) Créer la commande (statut Livree car vente directe)
            $numero = numeroDocument('VD');
            $pdo->prepare("
                INSERT INTO commandes (numero_commande, client_id, succursale_id, montant_total, statut, date_commande)
                VALUES (?, ?, ?, ?, 'Livree', NOW())
            ")->execute([$numero, $clientId, $succId, $total]);
            $commandeId = (int)$pdo->lastInsertId();

            // 4) Détails + décrément stock
            $stmtDetail = $pdo->prepare("
                INSERT INTO details_commande (commande_id, produit_id, quantite, prix_unitaire, sous_total)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmtDecrGen = $pdo->prepare("UPDATE stocks SET quantite = quantite - ? WHERE produit_id = ?");
            $stmtDecrSuc = $pdo->prepare("UPDATE stocks_succursales SET quantite = quantite - ? WHERE produit_id = ? AND succursale_id = ?");
            $stmtMvt     = $pdo->prepare("
                INSERT INTO mouvements_stock (produit_id, succursale_id, utilisateur_id, type_mouvement, quantite, motif)
                VALUES (?, ?, ?, 'Vente', ?, ?)
            ");

            foreach ($lignesValides as $l) {
                $stmtDetail->execute([$commandeId, $l['produit_id'], $l['quantite'], $l['prix'], $l['sous_total']]);

                if ($succId === $succursaleCentraleId) {
                    $stmtDecrGen->execute([$l['quantite'], $l['produit_id']]);
                } else {
                    $stmtDecrSuc->execute([$l['quantite'], $l['produit_id'], $succId]);
                }

                $stmtMvt->execute([$l['produit_id'], $succId === $succursaleCentraleId ? null : $succId,
                                   $_SESSION['id_utilisateur'], $l['quantite'], "Vente directe $numero"]);
            }

            // 5) Paiement
            $pdo->prepare("
                INSERT INTO paiements (commande_id, montant, methode, statut, date_paiement)
                VALUES (?, ?, ?, 'Valide', NOW())
            ")->execute([$commandeId, $total, $methode]);

            $pdo->commit();

            $message = "Vente $numero enregistrée avec succès (" . formatMontant($total) . ").";
            $derniereCommandeId = $commandeId;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $erreur = $e->getMessage();
        }
    }
}

// ============================================================
// DONNÉES POUR L'INTERFACE
// ============================================================
$produits = $pdo->query("
    SELECT p.id, p.nom_produit, p.prix_vente,
           COALESCE(s.quantite, 0) AS stock_general,
           (SELECT COALESCE(SUM(ss.quantite),0) FROM stocks_succursales ss WHERE ss.produit_id = p.id) AS stock_succursales
    FROM produits p
    LEFT JOIN stocks s ON s.produit_id = p.id
    WHERE p.statut = 'Disponible'
    ORDER BY p.nom_produit
")->fetchAll();

// Stock par succursale pour le JS (filtre dynamique)
$stockParSucc = [];
$rows = $pdo->query("SELECT succursale_id, produit_id, quantite FROM stocks_succursales")->fetchAll();
foreach ($rows as $r) {
    $stockParSucc[(int)$r['succursale_id']][(int)$r['produit_id']] = (int)$r['quantite'];
}
// Stock général = succursale centrale
foreach ($pdo->query("SELECT produit_id, quantite FROM stocks")->fetchAll() as $r) {
    $stockParSucc[$succursaleCentraleId][(int)$r['produit_id']] = (int)$r['quantite'];
}

// Ventes récentes (toutes succursales)
$ventesRecentes = $pdo->query("
    SELECT c.id, c.numero_commande, c.date_commande, c.montant_total,
           cl.nom, cl.postnom, cl.prenom,
           s.nom AS succursale_nom, s.code AS succursale_code,
           (SELECT COUNT(*) FROM details_commande dc WHERE dc.commande_id = c.id) AS nb_produits
    FROM commandes c
    INNER JOIN clients cl ON cl.id = c.client_id
    LEFT JOIN succursales s ON s.id = c.succursale_id
    WHERE c.statut = 'Livree'
    ORDER BY c.date_commande DESC
    LIMIT 15
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vente directe | <?= h($nomEntreprise) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
<div class="min-h-screen md:flex">

    <?php include __DIR__ . '/_aside.php'; ?>

    <main class="min-w-0 flex-1 md:ml-64">

        <!-- En-tête -->
        <header class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur sm:px-8">
            <div>
                <p class="text-xs font-medium text-slate-400">Administration / Ventes</p>
                <h1 class="mt-1 text-xl font-extrabold text-slate-900 sm:text-2xl">Vente directe</h1>
            </div>
            <div class="flex items-center gap-3">
                <div class="hidden text-right sm:block">
                    <p class="text-sm font-bold text-slate-800"><?= h($nomUtilisateur) ?></p>
                    <p class="text-xs text-green-600"><?= h($roleUtilisateur) ?></p>
                </div>
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100 font-bold text-green-700"><?= h(strtoupper(substr($nomUtilisateur,0,1))) ?></div>
            </div>
        </header>

        <div class="p-5 sm:p-8">

            <?php if ($erreur !== ''): ?>
                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= h($erreur) ?></div>
            <?php endif; ?>
            <?php if ($message !== ''): ?>
                <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 flex items-center justify-between">
                    <span><?= h($message) ?></span>
                    <?php if ($derniereCommandeId): ?>
                        <a href="facture.php?id=<?= (int)$derniereCommandeId ?>" target="_blank"
                           class="rounded-lg bg-green-700 px-3 py-2 text-xs font-bold text-white hover:bg-green-800">
                            🖨 Imprimer la facture
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">

                <!-- ============================== -->
                <!-- COLONNE GAUCHE : Produits      -->
                <!-- ============================== -->
                <section class="xl:col-span-2">
                    <div class="mb-4 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-500">Point de vente</label>
                                <select id="selectSucc" onchange="onSuccursaleChange()" required
                                        class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-green-500 outline-none">
                                    <?php foreach ($succursales as $s): ?>
                                        <option value="<?= (int)$s['id'] ?>" <?= $succursaleDefaut === (int)$s['id'] ? 'selected' : '' ?>>
                                            <?= h($s['nom']) ?> (<?= h($s['code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-500">Rechercher un produit</label>
                                <input type="text" id="rechercheProduit" oninput="filtrerProduits()" placeholder="Nom du produit..."
                                       class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-green-500 outline-none">
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-100 bg-white shadow-sm">
                        <div class="border-b border-slate-100 px-5 py-4">
                            <h3 class="font-bold text-slate-900">Catalogue</h3>
                            <p class="mt-1 text-xs text-slate-400">Cliquez sur un produit pour l'ajouter au panier</p>
                        </div>
                        <div id="grilleProduits" class="grid grid-cols-1 gap-2 p-4 sm:grid-cols-2 lg:grid-cols-3 max-h-[500px] overflow-y-auto">
                            <?php foreach ($produits as $p): ?>
                                <button type="button"
                                        data-id="<?= (int)$p['id'] ?>"
                                        data-nom="<?= h($p['nom_produit']) ?>"
                                        data-prix="<?= (float)$p['prix_vente'] ?>"
                                        onclick="ajouterAuPanier(this)"
                                        class="group flex min-h-16 items-center justify-between gap-3 rounded-xl border border-slate-100 bg-white p-3 text-left transition hover:border-green-300 hover:bg-green-50/40">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-slate-800"><?= h($p['nom_produit']) ?></p>
                                        <p class="mt-1 text-xs font-bold text-green-700"><?= h(formatMontant($p['prix_vente'])) ?></p>
                                    </div>
                                    <span class="stock-info shrink-0 text-right text-[10px] text-slate-400">Stock : —</span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <!-- ============================== -->
                <!-- COLONNE DROITE : Panier        -->
                <!-- ============================== -->
                <section class="h-fit rounded-2xl border border-slate-100 bg-white p-5 shadow-sm xl:sticky xl:top-24">
                    <div class="mb-4 flex items-center justify-between border-b border-slate-100 pb-4">
                        <div>
                            <h3 class="font-bold text-slate-900">Panier</h3>
                            <p class="mt-1 text-xs text-slate-400"><span id="nbArticles">0</span> article(s)</p>
                        </div>
                        <button type="button" onclick="viderPanier()" class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">Vider</button>
                    </div>

                    <ul id="listePanier" class="mb-4 max-h-56 space-y-2 overflow-y-auto text-sm">
                        <li class="py-8 text-center text-xs text-slate-400" id="panierVide">Panier vide</li>
                    </ul>

                    <form method="POST" id="formVente" class="space-y-3 border-t border-slate-100 pt-4">
                        <input type="hidden" name="action" value="vendre">
                        <input type="hidden" name="panier_json" id="panierJson">
                        <input type="hidden" name="succursale_id" id="succursaleIdForm" value="<?= $succursaleDefaut ?>">

                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-500">Nom du client *</label>
                            <input type="text" name="client_nom" required placeholder="Ex. : Jean Kabila"
                                   class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-green-500 outline-none">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-500">Téléphone (facultatif)</label>
                            <input type="tel" name="client_telephone" placeholder="+243..."
                                   class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-green-500 outline-none">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-500">Méthode de paiement</label>
                            <select name="methode_paiement" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                                <option value="Espece">Espèces</option>
                                <option value="Mobile Money">Mobile Money</option>
                                <option value="Carte">Carte</option>
                                <option value="Virement">Virement</option>
                            </select>
                        </div>

                        <div class="flex items-center justify-between border-t border-slate-100 pt-3">
                            <span class="text-sm text-slate-500">Total</span>
                            <span id="totalPanier" class="text-xl font-extrabold text-green-700">0,00</span>
                        </div>

                        <button type="submit"
                                class="w-full rounded-xl bg-green-700 px-4 py-3 text-sm font-bold text-white hover:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50"
                                id="btnValider" disabled>
                            ✓ Valider la vente
                        </button>
                    </form>
                </section>
            </div>

            <!-- Ventes récentes -->
            <section class="mt-7 overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <div>
                        <h3 class="font-bold text-slate-900">Ventes directes récentes</h3>
                        <p class="mt-1 text-xs text-slate-400">15 dernières ventes enregistrées</p>
                    </div>
                    <span class="rounded-lg bg-green-50 px-3 py-1.5 text-xs font-semibold text-green-700"><?= count($ventesRecentes) ?> vente(s)</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[800px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Commande</th>
                                <th class="px-5 py-3 font-semibold">Client</th>
                                <th class="px-5 py-3 font-semibold">Point de vente</th>
                                <th class="px-5 py-3 font-semibold">Produits</th>
                                <th class="px-5 py-3 font-semibold">Montant</th>
                                <th class="px-5 py-3 font-semibold">Date</th>
                                <th class="px-5 py-3 text-right font-semibold">Facture</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        <?php if (empty($ventesRecentes)): ?>
                            <tr><td colspan="7" class="px-5 py-10 text-center text-sm text-slate-400">Aucune vente directe enregistrée.</td></tr>
                        <?php else: foreach ($ventesRecentes as $v): ?>
                            <tr class="hover:bg-green-50/40">
                                <td class="px-5 py-4 font-bold text-green-700 text-xs"><?= h($v['numero_commande']) ?></td>
                                <td class="px-5 py-4 font-semibold text-slate-800"><?= h(trim($v['prenom'] . ' ' . $v['nom'] . ' ' . $v['postnom'])) ?></td>
                                <td class="px-5 py-4">
                                    <span class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                        <?= h($v['succursale_nom'] ?? 'Centrale') ?>
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-xs text-slate-500"><?= (int)$v['nb_produits'] ?> produit(s)</td>
                                <td class="px-5 py-4 font-bold text-slate-800"><?= h(formatMontant($v['montant_total'])) ?></td>
                                <td class="px-5 py-4 text-xs text-slate-500"><?= h(date('d/m/Y H:i', strtotime($v['date_commande']))) ?></td>
                                <td class="px-5 py-4 text-right">
                                    <a href="facture.php?id=<?= (int)$v['id'] ?>" target="_blank"
                                       class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-bold text-white hover:bg-slate-700">🖨 Facture</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>

<script>
// Stock par succursale (injecté depuis PHP)
const stockParSucc = <?= json_encode($stockParSucc, JSON_NUMERIC_CHECK) ?>;
const SUCC_CENTRALE = <?= (int)$succursaleCentraleId ?>;

let panier = [];

function getStock(produitId, succId) {
    if (!stockParSucc[succId]) return 0;
    return stockParSucc[succId][produitId] || 0;
}

function onSuccursaleChange() {
    const succ = parseInt(document.getElementById('selectSucc').value);
    document.getElementById('succursaleIdForm').value = succ;
    document.querySelectorAll('#grilleProduits button').forEach(btn => {
        const pid = parseInt(btn.dataset.id);
        const dispo = getStock(pid, succ);
        const info = btn.querySelector('.stock-info');
        info.textContent = 'Stock : ' + dispo;
        info.className = 'shrink-0 text-right text-[10px] stock-info ' + (dispo <= 0 ? 'text-red-500' : 'text-slate-400');
        btn.disabled = dispo <= 0;
        btn.hidden = dispo <= 0;
        btn.classList.toggle('opacity-40', dispo <= 0);
        btn.classList.toggle('cursor-not-allowed', dispo <= 0);
    });
    // Revalider le panier (quantités éventuellement supérieures au stock)
    panier = panier.filter(l => l.quantite <= getStock(l.produit_id, succ));
    if (panier.length > 0) {
        // Ajuster les quantités trop élevées
        panier.forEach(l => {
            const max = getStock(l.produit_id, succ);
            if (l.quantite > max) l.quantite = max;
        });
        panier = panier.filter(l => l.quantite > 0);
    }
    renderPanier();
}

function ajouterAuPanier(btn) {
    const succ = parseInt(document.getElementById('selectSucc').value);
    const pid = parseInt(btn.dataset.id);
    const dispo = getStock(pid, succ);
    if (dispo <= 0) { alert('Stock épuisé dans ce point de vente.'); return; }

    const existing = panier.find(l => l.produit_id === pid);
    if (existing) {
        if (existing.quantite + 1 > dispo) { alert('Stock maximum atteint (' + dispo + ').'); return; }
        existing.quantite++;
    } else {
        panier.push({
            produit_id: pid,
            nom: btn.dataset.nom,
            prix: parseFloat(btn.dataset.prix),
            quantite: 1
        });
    }
    renderPanier();
}

function retirerDuPanier(pid) {
    panier = panier.filter(l => l.produit_id !== pid);
    renderPanier();
}

function changerQte(pid, delta) {
    const succ = parseInt(document.getElementById('selectSucc').value);
    const l = panier.find(x => x.produit_id === pid);
    if (!l) return;
    const max = getStock(pid, succ);
    const nv = l.quantite + delta;
    if (nv <= 0) { retirerDuPanier(pid); return; }
    if (nv > max) { alert('Stock maximum atteint (' + max + ').'); return; }
    l.quantite = nv;
    renderPanier();
}

function viderPanier() {
    if (!confirm('Vider le panier ?')) return;
    panier = [];
    renderPanier();
}

function renderPanier() {
    const ul = document.getElementById('listePanier');
    const nb = panier.reduce((s, l) => s + l.quantite, 0);
    document.getElementById('nbArticles').textContent = nb;

    if (panier.length === 0) {
        ul.innerHTML = '<li class="py-8 text-center text-xs text-slate-400" id="panierVide">Panier vide</li>';
        document.getElementById('totalPanier').textContent = '0,00';
        document.getElementById('btnValider').disabled = true;
        return;
    }

    ul.innerHTML = panier.map(l => `
        <li class="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2">
            <div class="min-w-0 flex-1">
                <p class="truncate text-xs font-semibold text-slate-800">${escapeHtml(l.nom)}</p>
                <p class="text-[10px] text-slate-400">${l.prix.toFixed(2)} × ${l.quantite}</p>
            </div>
            <div class="flex items-center gap-1">
                <button type="button" onclick="changerQte(${l.produit_id}, -1)" class="flex h-6 w-6 items-center justify-center rounded bg-white text-xs font-bold text-slate-600 hover:bg-slate-200">−</button>
                <span class="w-6 text-center text-xs font-bold">${l.quantite}</span>
                <button type="button" onclick="changerQte(${l.produit_id}, 1)" class="flex h-6 w-6 items-center justify-center rounded bg-white text-xs font-bold text-slate-600 hover:bg-slate-200">+</button>
                <button type="button" onclick="retirerDuPanier(${l.produit_id})" class="ml-1 flex h-6 w-6 items-center justify-center rounded bg-red-50 text-xs text-red-600 hover:bg-red-100">✕</button>
            </div>
        </li>
    `).join('');

    const total = panier.reduce((s, l) => s + l.prix * l.quantite, 0);
    document.getElementById('totalPanier').textContent = total.toFixed(2).replace('.', ',');
    document.getElementById('btnValider').disabled = false;
}

function escapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

function filtrerProduits() {
    const q = document.getElementById('rechercheProduit').value.toLowerCase();
    document.querySelectorAll('#grilleProduits button').forEach(btn => {
        const nom = btn.dataset.nom.toLowerCase();
        btn.style.display = nom.includes(q) ? '' : 'none';
    });
}

document.getElementById('formVente').addEventListener('submit', function(e) {
    if (panier.length === 0) { e.preventDefault(); alert('Panier vide.'); return; }
    document.getElementById('panierJson').value = JSON.stringify(panier);
});

// Initialisation
onSuccursaleChange();
</script>
</body>
</html>