
<?php
require_once __DIR__ . "/config.php";

// Protection administrateur
if (!isset($_SESSION["id_utilisateur"])) {
    header("Location: login.php");
    exit;
}

$nomUtilisateur = $_SESSION["nom"] ?? "Administrateur";
$roleUtilisateur = $_SESSION["role"] ?? "Administrateur";
$accesRestreint = utilisateurEstLimiteAuPointDeVente();
$succursaleUtilisateurId = $accesRestreint ? exigerSuccursaleUtilisateur() : 0;

$message = "";
$typeMessage = "success";
$erreur = "";

// Fonction d'affichage sécurisée
if (!function_exists("h")) {
    function h($valeur): string {
        return htmlspecialchars((string)($valeur ?? ""), ENT_QUOTES, "UTF-8");
    }
}

// Statuts autorisés
$statuts = [
    "En attente",
    "Confirmee",
    "En preparation",
    "Expediee",
    "Livree",
    "Annulee"
];

// Couleurs des statuts
function couleurCommande($statut) {
    $couleurs = [
        "En attente" => "bg-amber-50 text-amber-700",
        "Confirmee" => "bg-blue-50 text-blue-700",
        "En preparation" => "bg-indigo-50 text-indigo-700",
        "Expediee" => "bg-violet-50 text-violet-700",
        "Livree" => "bg-green-50 text-green-700",
        "Annulee" => "bg-red-50 text-red-700"
    ];

    return $couleurs[$statut] ?? "bg-slate-100 text-slate-600";
}

// Initialiser le jeton de sécurité
if (empty($_SESSION["csrf_commandes"])) {
    $_SESSION["csrf_commandes"] = bin2hex(random_bytes(32));
}

// Modification du statut
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $jeton = $_POST["csrf_token"] ?? "";

    if (
        empty($_SESSION["csrf_commandes"]) ||
        !hash_equals($_SESSION["csrf_commandes"], $jeton)
    ) {
        $message = "Session expirée. Actualisez la page.";
        $typeMessage = "error";

    } else {

        $idCommande = (int)($_POST["id_commande"] ?? 0);
        $nouveauStatut = $_POST["statut"] ?? "";

        if (
            $idCommande <= 0 ||
            !in_array($nouveauStatut, $statuts, true)
        ) {
            $message = "Informations invalides.";
            $typeMessage = "error";

        } else {

            try {
                if ($accesRestreint) {
                    $verification = $pdo->prepare("SELECT COUNT(*) FROM commandes WHERE id = ? AND succursale_id = ?");
                    $verification->execute([$idCommande, $succursaleUtilisateurId]);
                    if ((int)$verification->fetchColumn() === 0) {
                        $message = "Commande introuvable ou non accessible.";
                        $typeMessage = "error";
                    }
                }

                if ($message === "") {
                    $sqlUpdate = "
                        UPDATE commandes
                        SET statut = ?
                        WHERE id = ?
                    " . ($accesRestreint ? " AND succursale_id = ?" : "");
                    $update = $pdo->prepare($sqlUpdate);
                    $parametresUpdate = [$nouveauStatut, $idCommande];
                    if ($accesRestreint) {
                        $parametresUpdate[] = $succursaleUtilisateurId;
                    }
                    $update->execute($parametresUpdate);
                    $message = "Le statut de la commande a été modifié avec succès.";
                }

            } catch (PDOException $e) {
                $message = "Erreur lors de la modification du statut.";
                $typeMessage = "error";
            }
        }
    }
}

// Recherche et filtre
$recherche = trim($_GET["recherche"] ?? "");
$filtreStatut = $_GET["statut"] ?? "";

// Préparation de la requête
$sql = "
    SELECT
        c.id,
        c.numero_commande,
        c.date_commande,
        c.montant_total,
        c.statut,
        cl.nom,
        cl.postnom,
        cl.prenom,
        cl.telephone,
        cl.email,
        c.succursale_id,
        s.nom AS succursale_nom,
        COUNT(dc.id) AS nombre_produits
    FROM commandes c
    INNER JOIN clients cl ON cl.id = c.client_id
    LEFT JOIN succursales s ON s.id = c.succursale_id
    LEFT JOIN details_commande dc ON dc.commande_id = c.id
    WHERE 1=1
";

$params = [];

if ($recherche !== "") {

    $sql .= "
        AND (
            c.numero_commande LIKE ?
            OR cl.nom LIKE ?
            OR cl.prenom LIKE ?
            OR cl.telephone LIKE ?
            OR cl.email LIKE ?
        )
    ";

    $terme = "%" . $recherche . "%";

    $params = [
        $terme,
        $terme,
        $terme,
        $terme,
        $terme
    ];
}

if (
    $filtreStatut !== "" &&
    in_array($filtreStatut, $statuts, true)
) {
    $sql .= " AND c.statut = ?";
    $params[] = $filtreStatut;
}

if ($accesRestreint) {
    $sql .= " AND c.succursale_id = ?";
    $params[] = $succursaleUtilisateurId;
}

$sql .= "
    GROUP BY
        c.id,
        c.numero_commande,
        c.date_commande,
        c.montant_total,
        c.statut,
        cl.nom,
        cl.postnom,
        cl.prenom,
        cl.telephone,
        cl.email,
        c.succursale_id,
        s.nom
    ORDER BY c.date_commande DESC
";

try {

    $requete = $pdo->prepare($sql);
    $requete->execute($params);
    $commandes = $requete->fetchAll();

    // Statistiques générales
    $sqlStatistiques = "
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(statut = 'En attente'), 0) AS attente,
            COALESCE(SUM(statut = 'Confirmee'), 0) AS confirmee,
            COALESCE(SUM(statut = 'Livree'), 0) AS livree,
            COALESCE(SUM(
                CASE
                    WHEN statut != 'Annulee'
                    THEN montant_total
                    ELSE 0
                END
            ), 0) AS montant
        FROM commandes
        " . ($accesRestreint ? "WHERE succursale_id = ?" : "") . "
    ";
    $stmtStatistiques = $pdo->prepare($sqlStatistiques);
    $stmtStatistiques->execute($accesRestreint ? [$succursaleUtilisateurId] : []);
    $statistiques = $stmtStatistiques->fetch();

} catch (PDOException $e) {

    $erreur = "Impossible de charger les commandes. Vérifiez les tables de votre base de données.";

    $commandes = [];

    $statistiques = [
        "total" => 0,
        "attente" => 0,
        "confirmee" => 0,
        "livree" => 0,
        "montant" => 0
    ];
}

// Nom complet du client
function nomClientCommande($commande) {
    $nom = trim(
        ($commande["prenom"] ?? "") . " " .
        ($commande["nom"] ?? "") . " " .
        ($commande["postnom"] ?? "")
    );

    return $nom !== "" ? $nom : "Client";
}

// Format montant
function formatMontantCommande($montant) {
    return number_format((float)$montant, 2, ",", " ") . " $";
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <link rel="stylesheet" href="scrollbar.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Gestion des commandes | E-commerce</title>

    <link rel="icon" type="image/png" href="ico/spot.png" />
    <link rel="apple-touch-icon" href="ico/spot.png" />

    <script src="https://cdn.tailwindcss.com"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        boutique: {
                            50: '#f0fdf4',
                            100: '#dcfce7',
                            500: '#22c55e',
                            600: '#16a34a',
                            700: '#15803d',
                            800: '#166534',
                            900: '#14532d'
                        }
                    }
                }
            }
        }
    </script>
</head>

<body class="min-h-screen bg-slate-50 text-slate-800">

<div class="min-h-screen md:flex">

    <!-- MENU LATERAL IDENTIQUE AU DASHBOARD -->
    <?php include __DIR__ . '/_aside.php'; ?>

    <!-- CONTENU PRINCIPAL -->
    <main class="min-w-0 flex-1 md:ml-64">

        <!-- EN-TETE IDENTIQUE AU DASHBOARD -->
        <header class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur sm:px-8">

            <div>

                <p class="text-xs font-medium text-slate-400">
                    Administration / Ventes
                </p>

                <h1 class="mt-1 text-xl font-extrabold text-slate-900 sm:text-2xl">
                    Gestion des commandes
                </h1>

            </div>

            <div class="flex items-center gap-3">

                <div class="hidden text-right sm:block">

                    <p class="text-sm font-bold text-slate-800">
                        <?= h($nomUtilisateur) ?>
                    </p>

                    <p class="text-xs text-green-600">
                        <?= h($roleUtilisateur) ?>
                    </p>

                </div>

                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100 font-bold text-green-700">

                    <?= h(strtoupper(substr($nomUtilisateur, 0, 1))) ?>

                </div>

            </div>

        </header>

        <!-- CONTENU -->
        <div class="p-5 sm:p-8">

            <!-- ERREURS -->
            <?php if ($erreur !== ""): ?>

                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    <?= h($erreur) ?>
                </div>

            <?php endif; ?>

            <?php if ($message !== ""): ?>

                <div class="mb-6 rounded-xl border px-4 py-3 text-sm
                    <?= $typeMessage === "error"
                        ? "border-red-200 bg-red-50 text-red-700"
                        : "border-green-200 bg-green-50 text-green-700" ?>">

                    <?= h($message) ?>

                </div>

            <?php endif; ?>

            
            <!-- CARTES STATISTIQUES -->
            <section class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">

                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">

                    <div class="flex items-center justify-between">

                        <span class="text-sm font-medium text-slate-500">
                            Toutes les commandes
                        </span>

                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-green-100 text-xl text-green-700">
                            <svg class="h-8 w-8 text-green-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h8.5a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="10" cy="19" r="1.2"/><circle cx="18" cy="19" r="1.2"/></svg>
                        </span>

                    </div>

                    <p class="mt-4 text-3xl font-extrabold text-slate-900">
                        <?= number_format((int)$statistiques["total"], 0, ",", " ") ?>
                    </p>

                    <p class="mt-1 text-xs text-slate-400">
                        Commandes enregistrées
                    </p>

                </div>

                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">

                    <div class="flex items-center justify-between">

                        <span class="text-sm font-medium text-slate-500">
                            En attente
                        </span>

                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-100 text-xl text-amber-700">
                            ◷
                        </span>

                    </div>

                    <p class="mt-4 text-3xl font-extrabold text-amber-600">
                        <?= number_format((int)$statistiques["attente"], 0, ",", " ") ?>
                    </p>

                    <p class="mt-1 text-xs text-slate-400">
                        À traiter
                    </p>

                </div>

                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">

                    <div class="flex items-center justify-between">

                        <span class="text-sm font-medium text-slate-500">
                            Confirmées
                        </span>

                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-100 text-xl text-blue-700">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
                        </span>

                    </div>

                    <p class="mt-4 text-3xl font-extrabold text-blue-600">
                        <?= number_format((int)$statistiques["confirmee"], 0, ",", " ") ?>
                    </p>

                    <p class="mt-1 text-xs text-slate-400">
                        Commandes confirmées
                    </p>

                </div>

                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">

                    <div class="flex items-center justify-between">

                        <span class="text-sm font-medium text-slate-500">
                            Montant total
                        </span>

                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-xl text-emerald-700">
                            $
                        </span>

                    </div>

                    <p class="mt-4 text-2xl font-extrabold text-green-700">
                        <?= h(formatMontantCommande($statistiques["montant"])) ?>
                    </p>

                    <p class="mt-1 text-xs text-slate-400">
                        Commandes non annulées
                    </p>

                </div>

            </section>

            <!-- RECHERCHE ET FILTRES -->
            <section class="mb-8 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm sm:p-6">

                <div class="mb-5">

                    <h3 class="font-bold text-slate-900">
                        Rechercher une commande
                    </h3>

                    <p class="mt-1 text-xs text-slate-400">
                        Recherchez par numéro de commande ou informations du client.
                    </p>

                </div>

                <form method="GET"
                      class="grid grid-cols-1 gap-4 md:grid-cols-3">

                    <div class="md:col-span-2">

                        <label class="mb-2 block text-sm font-semibold text-slate-600">
                            Recherche
                        </label>

                        <input
                            type="text"
                            name="recherche"
                            value="<?= h($recherche) ?>"
                            placeholder="Numéro, nom, téléphone ou e-mail..."
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100"
                        >

                    </div>

                    <div>

                        <label class="mb-2 block text-sm font-semibold text-slate-600">
                            Statut
                        </label>

                        <select
                            name="statut"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">

                            <option value="">
                                Tous les statuts
                            </option>

                            <?php foreach ($statuts as $s): ?>

                                <option
                                    value="<?= h($s) ?>"
                                    <?= $filtreStatut === $s ? "selected" : "" ?>>

                                    <?= h($s) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="flex flex-wrap gap-3 md:col-span-3">

                        <button
                            type="submit"
                            class="rounded-xl bg-green-700 px-6 py-3 text-sm font-bold text-white transition hover:bg-green-800">

                            Rechercher

                        </button>

                        <a href="commandes.php"
                           class="rounded-xl bg-slate-100 px-6 py-3 text-sm font-bold text-slate-600 transition hover:bg-slate-200">

                            Réinitialiser

                        </a>

                    </div>

                </form>

            </section>

            <!-- TABLEAU DES COMMANDES -->
            <section class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">

                <div class="flex flex-col justify-between gap-3 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-center sm:px-6">

                    <div>

                        <h3 class="font-bold text-slate-900">
                            Liste des commandes
                        </h3>

                        <p class="mt-1 text-xs text-slate-400">
                            <?= count($commandes) ?> commande(s) trouvée(s)
                        </p>

                    </div>

                    <span class="w-fit rounded-lg bg-green-50 px-3 py-2 text-xs font-semibold text-green-700">
                        Historique des ventes
                    </span>

                </div>

                <?php if (!empty($commandes)): ?>

                    <div class="overflow-x-auto">

                        <table class="w-full min-w-[1150px] text-left text-sm">

                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">

                                <tr>

                                    <th class="px-5 py-4 font-semibold">
                                        Commande
                                    </th>

                                    <th class="px-5 py-4 font-semibold">
                                        Client
                                    </th>

                                    <th class="px-5 py-4 font-semibold">
                                        Date
                                    </th>

                                    <th class="px-5 py-4 font-semibold">
                                        Succursale
                                    </th>

                                    <th class="px-5 py-4 font-semibold">
                                        Produits
                                    </th>

                                    <th class="px-5 py-4 font-semibold">
                                        Montant
                                    </th>

                                    <th class="px-5 py-4 font-semibold">
                                        Statut
                                    </th>

                                    <th class="px-5 py-4 font-semibold">
                                        Détails
                                    </th>

                                </tr>

                            </thead>

                            <tbody class="divide-y divide-slate-100">

                                <?php foreach ($commandes as $commande): ?>

                                    <tr class="transition hover:bg-green-50/40">

                                        <!-- NUMERO -->
                                        <td class="px-5 py-4">

                                            <p class="font-bold text-green-700">
                                                <?= h($commande["numero_commande"]) ?>
                                            </p>


                                        </td>

                                        <!-- CLIENT -->
                                        <td class="px-5 py-4">

                                            <p class="font-semibold text-slate-800">
                                                <?= h(nomClientCommande($commande)) ?>
                                            </p>

                                            <p class="mt-1 text-xs text-slate-400">
                                                <?= h($commande["telephone"]) ?>
                                            </p>

                                        </td>

                                        <!-- DATE -->
                                        <td class="px-5 py-4 text-slate-500">

                                            <?= h(date(
                                                "d/m/Y H:i",
                                                strtotime($commande["date_commande"])
                                            )) ?>

                                        </td>

                                        <!-- SUCCURSALE -->
                                        <td class="px-5 py-4 text-slate-500">
                                            <?= h($commande["succursale_nom"] ?? "—") ?>
                                        </td>

                                        <!-- PRODUITS -->
                                        <td class="px-5 py-4">

                                            <span class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700">
                                                <?= (int)$commande["nombre_produits"] ?> produit(s)
                                            </span>

                                        </td>

                                        <!-- MONTANT -->
                                        <td class="px-5 py-4 font-bold text-slate-800">

                                            <?= h(formatMontantCommande($commande["montant_total"])) ?>

                                        </td>

                                        <!-- STATUT -->
                                        <td class="px-5 py-4">

                                            <form method="POST" class="flex min-w-40 flex-col gap-2">

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= h($_SESSION["csrf_commandes"]) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="id_commande"
                                                    value="<?= (int)$commande["id"] ?>"
                                                >

                                                <select
                                                    name="statut"
                                                    class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold outline-none focus:border-green-500 <?= h(couleurCommande($commande["statut"])) ?>">

                                                    <?php foreach ($statuts as $s): ?>

                                                        <option
                                                            value="<?= h($s) ?>"
                                                            <?= $commande["statut"] === $s ? "selected" : "" ?>>

                                                            <?= h($s) ?>

                                                        </option>

                                                    <?php endforeach; ?>

                                                </select>

                                                <button
                                                    type="submit"
                                                    class="rounded-lg bg-green-700 px-3 py-2 text-xs font-bold text-white transition hover:bg-green-800">

                                                    Enregistrer

                                                </button>

                                            </form>

                                        </td>

                                        <!-- DETAILS -->
                                        <td class="px-5 py-4">
                                            <div class="flex flex-wrap gap-2">
                                                <a
                                                    href="voir_commande.php?id=<?= (int)$commande["id"] ?>"
                                                    class="inline-flex items-center gap-2 rounded-xl bg-green-50 px-4 py-2.5 text-xs font-bold text-green-700 transition hover:bg-green-100">
                                                    Voir <span>→</span>
                                                </a>
                                                <a href="facture.php?id=<?= (int)$commande['id'] ?>" target="_blank" rel="noopener"
                                                   class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-xs font-bold text-white transition hover:bg-slate-700">
                                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M7 14h10v7H7zM17 11h.01"/></svg>
                                                    Facture
                                                </a>
                                            </div>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php else: ?>

                    <div class="px-5 py-16 text-center">

                        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-green-50 text-3xl">
                            <svg class="h-8 w-8 text-green-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h8.5a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="10" cy="19" r="1.2"/><circle cx="18" cy="19" r="1.2"/></svg>
                        </div>

                        <h3 class="mt-5 text-lg font-bold text-slate-800">
                            Aucune commande trouvée
                        </h3>

                        <p class="mx-auto mt-2 max-w-md text-sm text-slate-400">
                            Les commandes effectuées par les clients apparaîtront ici automatiquement.
                        </p>

                        <a href="commandes.php"
                           class="mt-6 inline-block rounded-xl bg-green-700 px-6 py-3 text-sm font-bold text-white hover:bg-green-800">

                            Actualiser la liste

                        </a>

                    </div>

                <?php endif; ?>

            </section>

            <!-- PIED DE PAGE -->
            <footer class="py-7 text-center text-xs text-slate-400">

                © <?= date("Y") ?> E-commerce — Gestion des commandes.

            </footer>

        </div>

    </main>

</div>

</body>
</html>
