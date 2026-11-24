<?php
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['id_utilisateur'])) { header('Location: login.php'); exit; }

$nomUtilisateur  = $_SESSION['nom'] ?? 'Administrateur';
$roleUtilisateur = $_SESSION['role'] ?? 'Administrateur';
$nomEntreprise   = parametre('nom_entreprise', 'NEXIA');
$devise          = parametre('devise', 'USD');
$userId          = (int)($_SESSION['id_utilisateur'] ?? 0);

$erreur = ''; $message = '';

// --- Succursales ---
$succursales = $pdo->query("SELECT id, nom, code, type FROM succursales WHERE statut='Active' ORDER BY type='CENTRALE' DESC, nom")->fetchAll();
$succDefaut  = (int)parametre('succursale_par_defaut', 1);
if (!array_filter($succursales, fn($s) => (int)$s['id'] === $succDefaut)) {
    $succDefaut = (int)($succursales[0]['id'] ?? 0);
}
$filtreSucc = (int)($_GET['succursale'] ?? $succDefaut);

// ============================================================
// SESSION DE CAISSE OUVERTE ?
// ============================================================
$stmtSession = $pdo->prepare("
    SELECT * FROM caisse_sessions
    WHERE succursale_id = ? AND statut = 'Ouverte'
    ORDER BY date_ouverture DESC LIMIT 1
");
$stmtSession->execute([$filtreSucc]);
$sessionOuverte = $stmtSession->fetch() ?: null;

// ============================================================
// ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Ouvrir une caisse ---
    if ($action === 'ouvrir') {
        $succId = (int)($_POST['succursale_id'] ?? 0);
        $montant = max(0, (float)($_POST['montant_ouverture'] ?? 0));
        if ($succId <= 0) {
            $erreur = "Sélectionnez un point de vente.";
        } else {
            $chk = $pdo->prepare("SELECT id FROM caisse_sessions WHERE succursale_id=? AND statut='Ouverte' LIMIT 1");
            $chk->execute([$succId]);
            if ($chk->fetch()) {
                $erreur = "Une caisse est déjà ouverte pour ce point de vente.";
            } else {
                $pdo->prepare("
                    INSERT INTO caisse_sessions (succursale_id, utilisateur_id, montant_ouverture, statut)
                    VALUES (?, ?, ?, 'Ouverte')
                ")->execute([$succId, $userId, $montant]);
                $message = "Caisse ouverte avec un fond de " . formatMontant($montant) . ".";
            }
        }
    }

    // --- Encaissement / Décaissement ---
    elseif ($action === 'mouvement') {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $type      = $_POST['type'] ?? 'Encaissement';
        $montant   = max(0, (float)($_POST['montant'] ?? 0));
        $libelle   = trim($_POST['libelle'] ?? '');
        $reference = trim($_POST['reference'] ?? '');

        if ($sessionId <= 0 || $montant <= 0 || $libelle === '') {
            $erreur = "Renseignez le montant et le libellé.";
        } elseif (!in_array($type, ['Encaissement','Decaissement'], true)) {
            $erreur = "Type de mouvement invalide.";
        } else {
            $pdo->prepare("
                INSERT INTO caisse_mouvements (session_id, utilisateur_id, type, montant, libelle, reference)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$sessionId, $userId, $type, $montant, $libelle, $reference !== '' ? $reference : null]);
            $message = ucfirst($type) . " de " . formatMontant($montant) . " enregistré.";
        }
    }

    // --- Fermer la caisse ---
    elseif ($action === 'fermer') {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $montantCompte = max(0, (float)($_POST['montant_fermeture'] ?? 0));
        $note = trim($_POST['note'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM caisse_sessions WHERE id = ? AND statut='Ouverte'");
        $stmt->execute([$sessionId]);
        $sess = $stmt->fetch();

        if (!$sess) {
            $erreur = "Session de caisse introuvable ou déjà fermée.";
        } else {
            // Solde théorique = ouverture + encaissements - décaissements
            $mvt = $pdo->prepare("
                SELECT
                  COALESCE(SUM(CASE WHEN type='Encaissement' THEN montant ELSE 0 END),0) AS enc,
                  COALESCE(SUM(CASE WHEN type='Decaissement' THEN montant ELSE 0 END),0) AS decaissements
                FROM caisse_mouvements WHERE session_id = ?
            ");
            $mvt->execute([$sessionId]);
            $tot = $mvt->fetch();
            $soldeTheorique = (float)$sess['montant_ouverture'] + (float)$tot['enc'] - (float)$tot['decaissements'];
            $ecart = $montantCompte - $soldeTheorique;

            $pdo->prepare("
                UPDATE caisse_sessions
                SET date_fermeture = NOW(), montant_fermeture = ?, solde_theorique = ?, ecart = ?, statut='Fermee', note = ?
                WHERE id = ?
            ")->execute([$montantCompte, $soldeTheorique, $ecart, $note !== '' ? $note : null, $sessionId]);

            $message = "Caisse fermée. Solde théorique : " . formatMontant($soldeTheorique)
                     . " — Écart : " . formatMontant($ecart) . ".";
        }
    }
}

// Recharger la session après action
$stmtSession->execute([$filtreSucc]);
$sessionOuverte = $stmtSession->fetch() ?: null;

// --- Mouvements de la session ouverte ---
$mouvements = [];
$totaux = ['ouverture' => 0, 'enc' => 0, 'dec' => 0, 'solde' => 0];
if ($sessionOuverte) {
    $stmtMv = $pdo->prepare("
        SELECT cm.*, u.nom AS utilisateur_nom
        FROM caisse_mouvements cm
        LEFT JOIN utilisateurs u ON u.id = cm.utilisateur_id
        WHERE cm.session_id = ?
        ORDER BY cm.date_mouvement DESC
    ");
    $stmtMv->execute([(int)$sessionOuverte['id']]);
    $mouvements = $stmtMv->fetchAll();

    $totaux['ouverture'] = (float)$sessionOuverte['montant_ouverture'];
    foreach ($mouvements as $m) {
        if ($m['type'] === 'Encaissement') $totaux['enc'] += (float)$m['montant'];
        else $totaux['dec'] += (float)$m['montant'];
    }
    $totaux['solde'] = $totaux['ouverture'] + $totaux['enc'] - $totaux['dec'];
}

// --- Historique des sessions fermées ---
$historique = $pdo->prepare("
    SELECT cs.*, s.nom AS succursale_nom, u.nom AS utilisateur_nom,
           (SELECT COUNT(*) FROM caisse_mouvements cm WHERE cm.session_id = cs.id) AS nb_mvts
    FROM caisse_sessions cs
    INNER JOIN succursales s ON s.id = cs.succursale_id
    LEFT JOIN utilisateurs u ON u.id = cs.utilisateur_id
    WHERE cs.statut = 'Fermee'
    ORDER BY cs.date_fermeture DESC LIMIT 20
");
$historique->execute();
$historique = $historique->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caisse | <?= h($nomEntreprise) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
<div class="min-h-screen md:flex">

    <!-- Aside -->
    <aside class="w-full bg-green-950 text-white md:fixed md:inset-y-0 md:left-0 md:flex md:w-64 md:flex-col">
        <div class="flex items-center gap-3 border-b border-white/10 px-6 py-5">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-green-500 text-xl font-black"><?= h(strtoupper(substr($nomEntreprise,0,1))) ?></div>
            <div>
                <p class="text-lg font-extrabold tracking-wide"><?= h($nomEntreprise) ?></p>
                <p class="text-xs text-green-200">Administration</p>
            </div>
        </div>
     <?php include __DIR__ . '/_aside.php'; ?>
    </aside>

    <main class="min-w-0 flex-1 md:ml-64">
        <header class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur sm:px-8">
            <div>
                <p class="text-xs font-medium text-slate-400">Administration / Finance</p>
                <h1 class="mt-1 text-xl font-extrabold text-slate-900 sm:text-2xl">Caisse</h1>
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

            <?php if ($erreur): ?><div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= h($erreur) ?></div><?php endif; ?>
            <?php if ($message): ?><div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= h($message) ?></div><?php endif; ?>

            <!-- Filtre point de vente -->
            <div class="mb-6 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                <form method="GET" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <label class="text-sm font-semibold text-slate-600">Point de vente :</label>
                    <select name="succursale" onchange="this.form.submit()" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm">
                        <?php foreach ($succursales as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= $filtreSucc === (int)$s['id'] ? 'selected' : '' ?>>
                                <?= h($s['nom']) ?> (<?= h($s['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php if (!$sessionOuverte): ?>

                <!-- Caisse fermée : ouverture -->
                <section class="mx-auto max-w-lg rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                    <div class="mb-5 text-center">
                        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-amber-100 text-3xl">🔒</div>
                        <h2 class="mt-4 text-lg font-bold text-slate-900">Aucune caisse ouverte</h2>
                        <p class="mt-1 text-sm text-slate-500">Ouvrez une caisse pour commencer à encaisser.</p>
                    </div>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="ouvrir">
                        <input type="hidden" name="succursale_id" value="<?= $filtreSucc ?>">
                        <div>
                            <label class="mb-1 block text-sm font-semibold">Fond de caisse d'ouverture (<?= h($devise) ?>)</label>
                            <input type="number" name="montant_ouverture" step="0.01" min="0" value="0" required
                                   class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm focus:border-green-500 outline-none">
                        </div>
                        <button class="w-full rounded-xl bg-green-700 px-5 py-3 text-sm font-bold text-white hover:bg-green-800">
                            Ouvrir la caisse
                        </button>
                    </form>
                </section>

            <?php else: ?>

                <!-- Cartes résumé -->
                <section class="mb-7 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                        <p class="text-sm text-slate-500">Fond d'ouverture</p>
                        <p class="mt-3 text-2xl font-extrabold text-slate-900"><?= h(formatMontant($totaux['ouverture'])) ?></p>
                        <p class="mt-1 text-xs text-slate-400"><?= h(date('d/m/Y H:i', strtotime($sessionOuverte['date_ouverture']))) ?></p>
                    </div>
                    <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                        <p class="text-sm text-slate-500">Encaissements</p>
                        <p class="mt-3 text-2xl font-extrabold text-green-700">+<?= h(formatMontant($totaux['enc'])) ?></p>
                        <p class="mt-1 text-xs text-slate-400"><?= count(array_filter($mouvements, fn($m) => $m['type'] === 'Encaissement')) ?> mouvement(s)</p>
                    </div>
                    <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                        <p class="text-sm text-slate-500">Décaissements</p>
                        <p class="mt-3 text-2xl font-extrabold text-red-600">−<?= h(formatMontant($totaux['dec'])) ?></p>
                        <p class="mt-1 text-xs text-slate-400"><?= count(array_filter($mouvements, fn($m) => $m['type'] === 'Decaissement')) ?> mouvement(s)</p>
                    </div>
                    <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm bg-green-50">
                        <p class="text-sm text-green-800 font-semibold">Solde en caisse</p>
                        <p class="mt-3 text-2xl font-extrabold text-green-700"><?= h(formatMontant($totaux['solde'])) ?></p>
                        <p class="mt-1 text-xs text-green-700">Théorique</p>
                    </div>
                </section>

                <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">

                    <!-- Formulaire mouvement -->
                    <div class="h-fit rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                        <h3 class="font-bold text-slate-900 mb-4">Nouveau mouvement</h3>
                        <form method="POST" class="space-y-3">
                            <input type="hidden" name="action" value="mouvement">
                            <input type="hidden" name="session_id" value="<?= (int)$sessionOuverte['id'] ?>">
                            <div>
                                <label class="mb-1 block text-sm font-semibold">Type</label>
                                <select name="type" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm">
                                    <option value="Encaissement">Encaissement (+)</option>
                                    <option value="Decaissement">Décaissement (−)</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-semibold">Montant (<?= h($devise) ?>)</label>
                                <input type="number" name="montant" step="0.01" min="0.01" required
                                       class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-green-500 outline-none">
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-semibold">Libellé *</label>
                                <input type="text" name="libelle" required maxlength="255" placeholder="Ex. : Vente comptoir, achat fournitures"
                                       class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-green-500 outline-none">
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-semibold">Référence (facultatif)</label>
                                <input type="text" name="reference" maxlength="100" placeholder="N° facture, reçu..."
                                       class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm">
                            </div>
                            <button class="w-full rounded-xl bg-green-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-green-800">Enregistrer</button>
                        </form>

                        <hr class="my-5 border-slate-100">

                        <form method="POST" class="space-y-3" onsubmit="return confirm('Fermer la caisse ?')">
                            <input type="hidden" name="action" value="fermer">
                            <input type="hidden" name="session_id" value="<?= (int)$sessionOuverte['id'] ?>">
                            <div>
                                <label class="mb-1 block text-sm font-semibold">Montant compté en caisse</label>
                                <input type="number" name="montant_fermeture" step="0.01" min="0" value="<?= h($totaux['solde']) ?>" required
                                       class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-amber-500 outline-none">
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-semibold">Note (facultatif)</label>
                                <input type="text" name="note" maxlength="255" placeholder="Écart, incident..."
                                       class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm">
                            </div>
                            <button class="w-full rounded-xl bg-amber-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-amber-700">
                                Fermer la caisse
                            </button>
                        </form>
                    </div>

                    <!-- Mouvements -->
                    <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm xl:col-span-2">
                        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                            <div>
                                <h3 class="font-bold text-slate-900">Mouvements de la session</h3>
                                <p class="mt-1 text-xs text-slate-400"><?= count($mouvements) ?> mouvement(s)</p>
                            </div>
                            <span class="rounded-lg bg-green-50 px-3 py-1.5 text-xs font-semibold text-green-700">Session #<?= (int)$sessionOuverte['id'] ?></span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[600px] text-left text-sm">
                                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-5 py-3">Date</th>
                                        <th class="px-5 py-3">Type</th>
                                        <th class="px-5 py-3">Libellé</th>
                                        <th class="px-5 py-3">Par</th>
                                        <th class="px-5 py-3 text-right">Montant</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                <?php if (empty($mouvements)): ?>
                                    <tr><td colspan="5" class="px-5 py-10 text-center text-sm text-slate-400">Aucun mouvement pour le moment.</td></tr>
                                <?php else: foreach ($mouvements as $m): ?>
                                    <tr class="hover:bg-green-50/40">
                                        <td class="px-5 py-3 text-xs text-slate-500"><?= h(date('d/m/Y H:i', strtotime($m['date_mouvement']))) ?></td>
                                        <td class="px-5 py-3">
                                            <?php if ($m['type'] === 'Encaissement'): ?>
                                                <span class="rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">Encaissement</span>
                                            <?php else: ?>
                                                <span class="rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700">Décaissement</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-3">
                                            <p class="font-semibold text-slate-800"><?= h($m['libelle']) ?></p>
                                            <?php if ($m['reference']): ?><p class="text-xs text-slate-400">Réf. <?= h($m['reference']) ?></p><?php endif; ?>
                                        </td>
                                        <td class="px-5 py-3 text-xs text-slate-500"><?= h($m['utilisateur_nom'] ?? '—') ?></td>
                                        <td class="px-5 py-3 text-right font-bold <?= $m['type'] === 'Encaissement' ? 'text-green-700' : 'text-red-600' ?>">
                                            <?= $m['type'] === 'Encaissement' ? '+' : '−' ?><?= h(formatMontant($m['montant'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

            <!-- Historique des sessions fermées -->
            <section class="mt-7 overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <div>
                        <h3 class="font-bold text-slate-900">Historique des sessions fermées</h3>
                        <p class="mt-1 text-xs text-slate-400">20 dernières sessions</p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[800px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-3">Session</th>
                                <th class="px-5 py-3">Point de vente</th>
                                <th class="px-5 py-3">Ouverture</th>
                                <th class="px-5 py-3">Fermeture</th>
                                <th class="px-5 py-3">Théorique</th>
                                <th class="px-5 py-3">Compté</th>
                                <th class="px-5 py-3 text-right">Écart</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        <?php if (empty($historique)): ?>
                            <tr><td colspan="7" class="px-5 py-10 text-center text-sm text-slate-400">Aucune session fermée.</td></tr>
                        <?php else: foreach ($historique as $hs): ?>
                            <tr class="hover:bg-green-50/40">
                                <td class="px-5 py-3 font-bold text-slate-700">#<?= (int)$hs['id'] ?></td>
                                <td class="px-5 py-3"><?= h($hs['succursale_nom']) ?></td>
                                <td class="px-5 py-3 text-xs text-slate-500"><?= h(date('d/m/Y H:i', strtotime($hs['date_ouverture']))) ?></td>
                                <td class="px-5 py-3 text-xs text-slate-500"><?= $hs['date_fermeture'] ? h(date('d/m/Y H:i', strtotime($hs['date_fermeture']))) : '—' ?></td>
                                <td class="px-5 py-3"><?= h(formatMontant($hs['solde_theorique'])) ?></td>
                                <td class="px-5 py-3"><?= h(formatMontant($hs['montant_fermeture'])) ?></td>
                                <td class="px-5 py-3 text-right font-bold <?= (float)$hs['ecart'] == 0 ? 'text-slate-600' : ((float)$hs['ecart'] > 0 ? 'text-green-700' : 'text-red-600') ?>">
                                    <?= h(formatMontant($hs['ecart'])) ?>
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
</body>
</html>