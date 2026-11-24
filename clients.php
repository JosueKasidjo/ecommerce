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
$message = "";
$clients = [];
$clientAModifier = null;
$totalClients = 0;
$recherche = trim($_GET["q"] ?? "");

function h($valeur) {
    return htmlspecialchars((string)$valeur, ENT_QUOTES, "UTF-8");
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

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $action = $_POST["action"] ?? "";

        if ($action === "ajouter" || $action === "modifier") {
            $id = (int)($_POST["id"] ?? 0);
            $nom = trim($_POST["nom"] ?? "");
            $postnom = trim($_POST["postnom"] ?? "");
            $prenom = trim($_POST["prenom"] ?? "");
            $telephone = trim($_POST["telephone"] ?? "");
            $email = trim($_POST["email"] ?? "");
            $adresse = trim($_POST["adresse"] ?? "");

            if ($nom === "" || $postnom === "" || $prenom === "" || $telephone === "") {
                $erreur = "Veuillez remplir le nom, le postnom, le prénom et le téléphone.";
            } elseif (mb_strlen($nom) > 100 || mb_strlen($postnom) > 100 || mb_strlen($prenom) > 100) {
                $erreur = "Les champs nom, postnom et prénom ne doivent pas dépasser 100 caractères.";
            } elseif (mb_strlen($telephone) > 30) {
                $erreur = "Le numéro de téléphone ne doit pas dépasser 30 caractères.";
            } elseif ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $erreur = "Veuillez saisir une adresse e-mail valide.";
            } elseif (mb_strlen($email) > 150 || mb_strlen($adresse) > 255) {
                $erreur = "L'e-mail ou l'adresse dépasse la longueur autorisée.";
            } else {
                if ($action === "ajouter") {
                    $stmt = $pdo->prepare(
                        "INSERT INTO clients (nom, postnom, prenom, telephone, email, adresse, date_creation)
                         VALUES (:nom, :postnom, :prenom, :telephone, :email, :adresse, NOW())"
                    );
                    $stmt->execute([
                        ":nom" => $nom,
                        ":postnom" => $postnom,
                        ":prenom" => $prenom,
                        ":telephone" => $telephone,
                        ":email" => $email !== "" ? $email : null,
                        ":adresse" => $adresse !== "" ? $adresse : null
                    ]);
                    $message = "Le client a été ajouté avec succès.";
                } else {
                    if ($id <= 0) {
                        throw new RuntimeException("Client invalide.");
                    }
                    $stmt = $pdo->prepare(
                        "UPDATE clients
                         SET nom = :nom, postnom = :postnom, prenom = :prenom,
                             telephone = :telephone, email = :email, adresse = :adresse
                         WHERE id = :id"
                    );
                    $stmt->execute([
                        ":nom" => $nom,
                        ":postnom" => $postnom,
                        ":prenom" => $prenom,
                        ":telephone" => $telephone,
                        ":email" => $email !== "" ? $email : null,
                        ":adresse" => $adresse !== "" ? $adresse : null,
                        ":id" => $id
                    ]);
                    $message = "Les informations du client ont été modifiées.";
                }
            }
        } elseif ($action === "supprimer") {
            $id = (int)($_POST["id"] ?? 0);
            if ($id <= 0) {
                $erreur = "Client invalide.";
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM commandes WHERE client_id = :id");
                $stmt->execute([":id" => $id]);
                $nombreCommandes = (int)$stmt->fetchColumn();

                if ($nombreCommandes > 0) {
                    $erreur = "Ce client possède déjà " . $nombreCommandes . " commande(s). Il ne peut pas être supprimé afin de conserver l'historique.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM clients WHERE id = :id");
                    $stmt->execute([":id" => $id]);
                    $message = $stmt->rowCount() > 0 ? "Le client a été supprimé." : "Client introuvable.";
                }
            }
        }
    }

    if (isset($_GET["modifier"])) {
        $idModifier = (int)$_GET["modifier"];
        if ($idModifier > 0) {
            $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = :id");
            $stmt->execute([":id" => $idModifier]);
            $clientAModifier = $stmt->fetch() ?: null;
        }
    }

    $sql = "
        SELECT c.*,
               COUNT(DISTINCT co.id) AS nombre_commandes,
               COALESCE(SUM(CASE WHEN co.statut <> 'Annulee' THEN co.montant_total ELSE 0 END), 0) AS montant_commandes
        FROM clients c
        LEFT JOIN commandes co ON co.client_id = c.id
    ";
    $params = [];
    if ($recherche !== "") {
        $sql .= " WHERE c.nom LIKE :q OR c.postnom LIKE :q OR c.prenom LIKE :q
                  OR c.telephone LIKE :q OR c.email LIKE :q";
        $params[":q"] = "%" . $recherche . "%";
    }
    $sql .= " GROUP BY c.id ORDER BY c.date_creation DESC, c.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clients = $stmt->fetchAll();

    $totalClients = (int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();

} catch (Throwable $e) {
    $erreur = $erreur !== "" ? $erreur : "Erreur : " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <link rel="stylesheet" href="scrollbar.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des clients | E-Commerce</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800">
<div class="min-h-screen md:flex">
   <?php include __DIR__ . '/_aside.php'; ?>
    <main class="min-w-0 flex-1 md:ml-64">
        <header class="flex flex-col gap-3 border-b border-slate-200 bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8">
            <div><p class="text-sm text-slate-500">Administration / Clients</p><h1 class="text-2xl font-bold text-slate-900">Gestion des clients</h1></div>
            <div class="flex items-center gap-3">
                <div class="hidden text-right sm:block"><p class="text-sm font-semibold"><?= h($nomUtilisateur) ?></p><p class="text-xs text-slate-500"><?= h($roleUtilisateur) ?></p></div>
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100 font-bold text-green-800"><?= h(strtoupper(substr($nomUtilisateur, 0, 1))) ?></div>
                <a href="logout.php" class="rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50">Quitter</a>
            </div>
        </header>

        <div class="p-5 sm:p-8">
            <?php if ($erreur !== ""): ?><div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= h($erreur) ?></div><?php endif; ?>
            <?php if ($message !== ""): ?><div class="mb-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?= h($message) ?></div><?php endif; ?>

            <div class="mb-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><p class="text-sm text-slate-500">Total des clients</p><span class="rounded-xl bg-green-100 p-3 text-green-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20v-1.5a6.5 6.5 0 0 1 13 0V20M16 5.2a3.5 3.5 0 0 1 0 6.6M18 14a5 5 0 0 1 3.5 4.8V20"/></svg></span></div><p class="mt-3 text-3xl font-bold"><?= number_format($totalClients, 0, ",", " ") ?></p><p class="mt-1 text-xs text-slate-400">Clients enregistrés dans la base</p></div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><p class="text-sm text-slate-500">Résultats affichés</p><span class="rounded-xl bg-blue-100 p-3 text-blue-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 4.5 4.5"/></svg></span></div><p class="mt-3 text-3xl font-bold"><?= number_format(count($clients), 0, ",", " ") ?></p><p class="mt-1 text-xs text-slate-400">Selon votre recherche</p></div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:col-span-2 xl:col-span-1"><div class="flex items-center justify-between"><p class="text-sm text-slate-500">Espace clients</p><span class="rounded-xl bg-amber-100 p-3 text-amber-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M5 4h14v16H5zM8 8h8M8 12h5M8 16h3"/></svg></span></div><p class="mt-3 text-lg font-bold">Carnet clientèle</p><p class="mt-1 text-xs text-slate-400">Coordonnées et historique des commandes</p></div>
            </div>

            <div class="mb-7 grid gap-6 xl:grid-cols-3">
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-1">
                    <div class="mb-5 flex items-center justify-between">
                        <div><h2 class="text-lg font-bold"><?= $clientAModifier ? "Modifier le client" : "Ajouter un client" ?></h2><p class="mt-1 text-sm text-slate-500">Renseignez les coordonnées du client.</p></div>
                        <span class="rounded-xl bg-green-100 p-3 text-green-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20v-1.5a6.5 6.5 0 0 1 13 0V20"/></svg></span>
                    </div>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="<?= $clientAModifier ? "modifier" : "ajouter" ?>">
                        <?php if ($clientAModifier): ?><input type="hidden" name="id" value="<?= (int)$clientAModifier["id"] ?>"><?php endif; ?>
                        <div><label class="mb-1.5 block text-sm font-medium">Nom *</label><input type="text" name="nom" required maxlength="100" value="<?= h($clientAModifier["nom"] ?? "") ?>" placeholder="Nom de famille" class="w-full rounded-xl border border-slate-300 px-3 py-3 text-sm focus:border-green-600 focus:outline-none"></div>
                        <div><label class="mb-1.5 block text-sm font-medium">Postnom *</label><input type="text" name="postnom" required maxlength="100" value="<?= h($clientAModifier["postnom"] ?? "") ?>" placeholder="Postnom" class="w-full rounded-xl border border-slate-300 px-3 py-3 text-sm focus:border-green-600 focus:outline-none"></div>
                        <div><label class="mb-1.5 block text-sm font-medium">Prénom *</label><input type="text" name="prenom" required maxlength="100" value="<?= h($clientAModifier["prenom"] ?? "") ?>" placeholder="Prénom" class="w-full rounded-xl border border-slate-300 px-3 py-3 text-sm focus:border-green-600 focus:outline-none"></div>
                        <div><label class="mb-1.5 block text-sm font-medium">Téléphone *</label><input type="tel" name="telephone" required maxlength="30" value="<?= h($clientAModifier["telephone"] ?? "") ?>" placeholder="+243..." class="w-full rounded-xl border border-slate-300 px-3 py-3 text-sm focus:border-green-600 focus:outline-none"></div>
                        <div><label class="mb-1.5 block text-sm font-medium">Adresse e-mail</label><input type="email" name="email" maxlength="150" value="<?= h($clientAModifier["email"] ?? "") ?>" placeholder="client@exemple.com" class="w-full rounded-xl border border-slate-300 px-3 py-3 text-sm focus:border-green-600 focus:outline-none"></div>
                        <div><label class="mb-1.5 block text-sm font-medium">Adresse physique</label><textarea name="adresse" rows="3" maxlength="255" placeholder="Commune, avenue, numéro..." class="w-full rounded-xl border border-slate-300 px-3 py-3 text-sm focus:border-green-600 focus:outline-none"><?= h($clientAModifier["adresse"] ?? "") ?></textarea></div>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="flex-1 rounded-xl bg-green-700 px-5 py-3 text-sm font-semibold text-white hover:bg-green-800"><?= $clientAModifier ? "Enregistrer les modifications" : "Ajouter le client" ?></button>
                            <?php if ($clientAModifier): ?><a href="client.php" class="rounded-xl border border-slate-300 px-4 py-3 text-sm hover:bg-slate-50">Annuler</a><?php endif; ?>
                        </div>
                        <p class="text-xs text-slate-400">* Champs obligatoires.</p>
                    </form>
                </section>

                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm xl:col-span-2">
                    <div class="flex flex-col gap-4 border-b border-slate-100 p-5 sm:flex-row sm:items-center sm:justify-between">
                        <div><h2 class="text-lg font-bold">Liste des clients</h2><p class="mt-1 text-sm text-slate-500">Consultez et gérez les clients enregistrés.</p></div>
                        <form method="GET" class="flex gap-2">
                            <input type="search" name="q" value="<?= h($recherche) ?>" placeholder="Nom, téléphone, e-mail..." class="min-w-0 rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:border-green-600 focus:outline-none">
                            <button class="rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-700">Chercher</button>
                        </form>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[760px] text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-4">Client</th><th class="px-5 py-4">Contact</th><th class="px-5 py-4">Commandes</th><th class="px-5 py-4">Date d'inscription</th><th class="px-5 py-4">Actions</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                            <?php if (!$clients): ?>
                                <tr><td colspan="5" class="px-5 py-12 text-center"><svg class="mx-auto h-8 w-8 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20v-1.5a6.5 6.5 0 0 1 13 0V20"/></svg><p class="mt-2 font-semibold text-slate-700">Aucun client trouvé</p><p class="mt-1 text-sm text-slate-500">Ajoutez un client ou modifiez votre recherche.</p></td></tr>
                            <?php else: foreach ($clients as $c): ?>
                                <tr class="hover:bg-slate-50/70">
                                    <td class="px-5 py-4"><div class="flex items-center gap-3"><div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-green-100 font-bold text-green-800"><?= h(strtoupper(substr($c["prenom"], 0, 1) . substr($c["nom"], 0, 1))) ?></div><div><p class="font-semibold text-slate-800"><?= h($c["nom"] . " " . $c["postnom"] . " " . $c["prenom"]) ?></p></div></div></td>
                                    <td class="px-5 py-4"><p class="font-medium text-slate-700"><?= h($c["telephone"]) ?></p><p class="text-xs text-slate-400"><?= h($c["email"] ?: "Aucun e-mail") ?></p></td>
                                    <td class="px-5 py-4"><span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700"><?= (int)$c["nombre_commandes"] ?> commande(s)</span></td>
                                    <td class="whitespace-nowrap px-5 py-4 text-xs text-slate-500"><?= $c["date_creation"] ? h(date("d/m/Y", strtotime($c["date_creation"]))) : "—" ?></td>
                                    <td class="px-5 py-4"><div class="flex items-center gap-2">
                                        <a href="client.php?modifier=<?= (int)$c["id"] ?>" class="rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-100">Modifier</a>
                                        <form method="POST" data-confirm="Voulez-vous vraiment supprimer ce client ?" data-confirm-title="Supprimer ce client" data-confirm-label="Supprimer" data-confirm-variant="danger"><input type="hidden" name="action" value="supprimer"><input type="hidden" name="id" value="<?= (int)$c["id"] ?>"><button type="submit" class="rounded-lg bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-100">Supprimer</button></form>
                                    </div></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>
<?php include __DIR__ . '/_notifications.php'; ?>
</body>
</html>
