<?php
session_start();

$host = "localhost";
$nomBase = "ecommerce";
$utilisateur = "root";
$motDePasse = "";

$message = "";
$typeMessage = "erreur";
$premierAdministrateur = false;

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

    // Vérifier si un compte existe déjà.
    $nombreUtilisateurs = (int) $pdo->query(
        "SELECT COUNT(*) FROM utilisateurs"
    )->fetchColumn();

    // Si la table est vide, afficher le formulaire de création du premier administrateur.
    $premierAdministrateur = ($nombreUtilisateurs === 0);

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        if ($premierAdministrateur && isset($_POST["creer_admin"])) {
            $nom = trim($_POST["nom"] ?? "");
            $email = trim($_POST["email"] ?? "");
            $motDePasseAdmin = $_POST["mot_de_passe"] ?? "";
            $confirmation = $_POST["confirmation"] ?? "";

            if ($nom === "" || $email === "" || $motDePasseAdmin === "") {
                $message = "Veuillez remplir tous les champs.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = "Veuillez saisir une adresse e-mail valide.";
            } elseif (strlen($motDePasseAdmin) < 8) {
                $message = "Le mot de passe doit contenir au moins 8 caractères.";
            } elseif ($motDePasseAdmin !== $confirmation) {
                $message = "Les deux mots de passe ne correspondent pas.";
            } else {
                $hash = password_hash($motDePasseAdmin, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare(
                    "INSERT INTO utilisateurs (nom, email, mot_de_passe, role, statut)
                     VALUES (?, ?, ?, 'Administrateur', 'Actif')"
                );
                $stmt->execute([$nom, $email, $hash]);

                $message = "Compte administrateur créé. Vous pouvez maintenant vous connecter.";
                $typeMessage = "succes";
                $premierAdministrateur = false;
            }
        } elseif (isset($_POST["connexion"])) {
            $email = trim($_POST["email"] ?? "");
            $motDePasseSaisi = $_POST["mot_de_passe"] ?? "";

            if ($email === "" || $motDePasseSaisi === "") {
                $message = "Veuillez saisir votre e-mail et votre mot de passe.";
            } else {
                $stmt = $pdo->prepare(
                    "SELECT id, nom, email, mot_de_passe, role, statut, succursale_id
                     FROM utilisateurs
                     WHERE email = ?
                     LIMIT 1"
                );
                $stmt->execute([$email]);
                $compte = $stmt->fetch();

                if (!$compte || !password_verify($motDePasseSaisi, $compte["mot_de_passe"])) {
                    $message = "Adresse e-mail ou mot de passe incorrect.";
                } elseif ($compte["statut"] !== "Actif") {
                    $message = "Ce compte est désactivé. Contactez l'administrateur.";
                } else {
                    session_regenerate_id(true);

                    $_SESSION["id_utilisateur"] = (int) $compte["id"];
                    $_SESSION["nom"] = $compte["nom"];
                    $_SESSION["role"] = $compte["role"];
                    $_SESSION["succursale_id"] = (int)($compte["succursale_id"] ?? 0);

                    header("Location: dashboard.php");
                    exit;
                }
            }
        }
    }
} catch (PDOException $e) {
    $message = "Impossible de se connecter à la base de données. Vérifiez que la base ecommerce est installée et que MySQL est démarré.";
} catch (Throwable $e) {
    $message = "Une erreur est survenue. Veuillez réessayer.";
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <link rel="stylesheet" href="scrollbar.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion | E-commerce</title>
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
<body class="min-h-screen bg-green-50 text-slate-800">
    <main class="min-h-screen flex items-center justify-center px-4 py-10">
        <div class="w-full max-w-5xl overflow-hidden rounded-3xl bg-white shadow-2xl shadow-green-900/10 grid md:grid-cols-2">

            <!-- Partie de présentation -->
            <section class="hidden md:flex flex-col justify-between bg-gradient-to-br from-green-700 via-green-600 to-emerald-500 p-10 text-white">
                <div>
                    <div class="flex items-center gap-3">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/15 text-2xl">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l2.2 11.2a2 2 0 002 1.6h8.9a2 2 0 002-1.6L22 7H6M10 21a1 1 0 100-2 1 1 0 000 2zm8 0a1 1 0 100-2 1 1 0 000 2z"/>
                            </svg>
                        </div>
                        <span class="text-xl font-bold tracking-wide">E-COMMERCE</span>
                    </div>

                    <div class="mt-24">
                        <p class="text-sm font-semibold uppercase tracking-[0.25em] text-green-100">Espace sécurisé</p>
                        <h1 class="mt-4 text-4xl font-extrabold leading-tight">
                            Gérez votre boutique en toute simplicité.
                        </h1>
                        <p class="mt-5 max-w-sm text-base leading-7 text-green-50">
                            Gérez vos produits, surveillez vos stocks et suivez les commandes de vos clients depuis un seul espace.
                        </p>
                    </div>
                </div>

                <div class="border-t border-white/20 pt-5 text-sm text-green-100">
                    Plateforme de vente en ligne et de gestion de stock
                </div>
            </section>

            <!-- Formulaire -->
            <section class="px-6 py-10 sm:px-10 md:px-12 md:py-14">
                <div class="mx-auto max-w-md">
                    <div class="mb-8 md:hidden">
                        <div class="flex items-center gap-3 text-green-700">
                            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-green-100">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l2.2 11.2a2 2 0 002 1.6h8.9a2 2 0 002-1.6L22 7H6"/>
                                </svg>
                            </div>
                            <span class="text-lg font-extrabold">E-COMMERCE</span>
                        </div>
                    </div>

                    <div class="mb-8">
                        <span class="inline-flex items-center rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-700">
                            <?= $premierAdministrateur ? "Première configuration" : "Bienvenue" ?>
                        </span>

                        <?php if ($premierAdministrateur): ?>
                            <h2 class="mt-4 text-3xl font-extrabold tracking-tight text-slate-900">Créer l'administrateur</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-500">
                                Aucun compte n'existe encore. Créez le premier compte administrateur pour sécuriser votre plateforme.
                            </p>
                        <?php else: ?>
                            <h2 class="mt-4 text-3xl font-extrabold tracking-tight text-slate-900">Connexion</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-500">
                                Connectez-vous à votre espace d'administration.
                            </p>
                        <?php endif; ?>
                    </div>

                    <?php if ($message !== ""): ?>
                        <div class="mb-6 rounded-xl border px-4 py-3 text-sm <?= $typeMessage === "succes" ? "border-green-200 bg-green-50 text-green-800" : "border-red-200 bg-red-50 text-red-700" ?>">
                            <?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($premierAdministrateur): ?>
                        <form method="POST" class="space-y-5">
                            <div>
                                <label for="nom" class="mb-2 block text-sm font-semibold text-slate-700">Nom complet</label>
                                <input type="text" id="nom" name="nom" required autocomplete="name"
                                    value="<?= htmlspecialchars($_POST["nom"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                                    placeholder="Votre nom"
                                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 outline-none transition focus:border-green-500 focus:bg-white focus:ring-4 focus:ring-green-100">
                            </div>

                            <div>
                                <label for="email" class="mb-2 block text-sm font-semibold text-slate-700">Adresse e-mail</label>
                                <input type="email" id="email" name="email" required autocomplete="email"
                                    value="<?= htmlspecialchars($_POST["email"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                                    placeholder="admin@exemple.com"
                                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 outline-none transition focus:border-green-500 focus:bg-white focus:ring-4 focus:ring-green-100">
                            </div>

                            <div>
                                <label for="mot_de_passe" class="mb-2 block text-sm font-semibold text-slate-700">Mot de passe</label>
                                <input type="password" id="mot_de_passe" name="mot_de_passe" required minlength="8" autocomplete="new-password"
                                    placeholder="Minimum 8 caractères"
                                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 outline-none transition focus:border-green-500 focus:bg-white focus:ring-4 focus:ring-green-100">
                            </div>

                            <div>
                                <label for="confirmation" class="mb-2 block text-sm font-semibold text-slate-700">Confirmer le mot de passe</label>
                                <input type="password" id="confirmation" name="confirmation" required minlength="8" autocomplete="new-password"
                                    placeholder="Répétez le mot de passe"
                                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 outline-none transition focus:border-green-500 focus:bg-white focus:ring-4 focus:ring-green-100">
                            </div>

                            <button type="submit" name="creer_admin"
                                class="flex w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-5 py-3.5 font-bold text-white shadow-lg shadow-green-600/20 transition hover:-translate-y-0.5 hover:bg-green-700 focus:outline-none focus:ring-4 focus:ring-green-200">
                                Créer le compte administrateur
                                <span aria-hidden="true">→</span>
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="POST" class="space-y-5">
                            <div>
                                <label for="email" class="mb-2 block text-sm font-semibold text-slate-700">Adresse e-mail</label>
                                <div class="relative">
                                    <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-slate-400">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 7l9 6 9-6M5 5h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z"/>
                                        </svg>
                                    </span>
                                    <input type="email" id="email" name="email" required autocomplete="username"
                                        value="<?= htmlspecialchars($_POST["email"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                                        placeholder="admin@exemple.com"
                                        class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3.5 pl-12 pr-4 outline-none transition focus:border-green-500 focus:bg-white focus:ring-4 focus:ring-green-100">
                                </div>
                            </div>

                            <div>
                                <label for="mot_de_passe" class="mb-2 block text-sm font-semibold text-slate-700">Mot de passe</label>
                                <div class="relative">
                                    <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-slate-400">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 10V7a5 5 0 0110 0v3m-12 0h14v11H5V10z"/>
                                        </svg>
                                    </span>
                                    <input type="password" id="mot_de_passe" name="mot_de_passe" required autocomplete="current-password"
                                        placeholder="Votre mot de passe"
                                        class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3.5 pl-12 pr-4 outline-none transition focus:border-green-500 focus:bg-white focus:ring-4 focus:ring-green-100">
                                </div>
                            </div>

                            <button type="submit" name="connexion"
                                class="flex w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-5 py-3.5 font-bold text-white shadow-lg shadow-green-600/20 transition hover:-translate-y-0.5 hover:bg-green-700 focus:outline-none focus:ring-4 focus:ring-green-200">
                                Se connecter
                                <span aria-hidden="true">→</span>
                            </button>
                        </form>
                    <?php endif; ?>

                    <p class="mt-8 text-center text-xs leading-5 text-slate-400">
                        © <?= date("Y") ?> E-commerce. Tous droits réservés.
                    </p>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
