<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Ce fichier utilise une session distincte de celle de l'administrateur.
if (!isset($_SESSION['csrf_compte_client'])) {
    $_SESSION['csrf_compte_client'] = bin2hex(random_bytes(32));
}

$message = '';
$typeMessage = 'error';
$mode = (($_GET['mode'] ?? '') === 'inscription') ? 'inscription' : 'connexion';

// Ajouter la colonne mot_de_passe à clients si elle n'existe pas encore.
try {
    $verification = $pdo->query("SHOW COLUMNS FROM clients LIKE 'mot_de_passe'");
    if (!$verification->fetch()) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN mot_de_passe VARCHAR(255) NULL AFTER email");
    }
} catch (PDOException $e) {
    http_response_code(500);
    exit("Impossible de préparer les comptes clients. Vérifiez la structure de la table clients. Détail : " .
        htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = (($_POST['action'] ?? '') === 'inscription') ? 'inscription' : 'connexion';

    if (!hash_equals($_SESSION['csrf_compte_client'], (string)($_POST['csrf_token'] ?? ''))) {
        $message = "Votre session a expiré. Actualisez la page puis réessayez.";
    } else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $motDePasse = (string)($_POST['mot_de_passe'] ?? '');

        try {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException("Veuillez saisir une adresse e-mail valide.");
            }

            if ($mode === 'inscription') {
                $nom = trim((string)($_POST['nom'] ?? ''));
                $postnom = trim((string)($_POST['postnom'] ?? ''));
                $prenom = trim((string)($_POST['prenom'] ?? ''));
                $telephone = trim((string)($_POST['telephone'] ?? ''));
                $adresse = trim((string)($_POST['adresse'] ?? ''));
                $confirmation = (string)($_POST['confirmation'] ?? '');

                if ($nom === '' || $prenom === '' || $telephone === '' || $adresse === '') {
                    throw new RuntimeException("Veuillez remplir tous les champs obligatoires.");
                }
                if (strlen($motDePasse) < 8) {
                    throw new RuntimeException("Le mot de passe doit contenir au moins 8 caractères.");
                }
                if ($motDePasse !== $confirmation) {
                    throw new RuntimeException("Les deux mots de passe ne correspondent pas.");
                }

                $verifier = $pdo->prepare("SELECT id FROM clients WHERE email = ? LIMIT 1");
                $verifier->execute([$email]);
                if ($verifier->fetch()) {
                    throw new RuntimeException("Cette adresse e-mail possède déjà un compte. Connectez-vous.");
                }

                $hash = password_hash($motDePasse, PASSWORD_DEFAULT);
                $insert = $pdo->prepare("
                    INSERT INTO clients
                        (nom, postnom, prenom, telephone, email, mot_de_passe, adresse, date_creation)
                    VALUES
                        (:nom, :postnom, :prenom, :telephone, :email, :mot_de_passe, :adresse, NOW())
                ");
                $insert->execute([
                    ':nom' => $nom,
                    ':postnom' => $postnom !== '' ? $postnom : null,
                    ':prenom' => $prenom,
                    ':telephone' => $telephone,
                    ':email' => $email,
                    ':mot_de_passe' => $hash,
                    ':adresse' => $adresse,
                ]);

                session_regenerate_id(true);
                $_SESSION['id_client'] = (int)$pdo->lastInsertId();
                $_SESSION['nom_client'] = trim($prenom . ' ' . $nom);
                $_SESSION['email_client'] = $email;
                $_SESSION['csrf_compte_client'] = bin2hex(random_bytes(32));

                header('Location: panier.php');
                exit;
            } else {
                if ($motDePasse === '') {
                    throw new RuntimeException("Veuillez saisir votre mot de passe.");
                }

                $requete = $pdo->prepare("
                    SELECT id, nom, postnom, prenom, email, mot_de_passe
                    FROM clients
                    WHERE email = ?
                    LIMIT 1
                ");
                $requete->execute([$email]);
                $client = $requete->fetch();

                if (!$client || empty($client['mot_de_passe']) ||
                    !password_verify($motDePasse, (string)$client['mot_de_passe'])) {
                    throw new RuntimeException("E-mail ou mot de passe incorrect. Si votre compte a été créé par l'administrateur, créez un mot de passe avec l'inscription ou demandez de l'aide à l'administrateur.");
                }

                session_regenerate_id(true);
                $_SESSION['id_client'] = (int)$client['id'];
                $_SESSION['nom_client'] = trim((string)$client['prenom'] . ' ' . (string)$client['nom']);
                $_SESSION['email_client'] = (string)$client['email'];
                $_SESSION['csrf_compte_client'] = bin2hex(random_bytes(32));

                header('Location: panier.php');
                exit;
            }
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        } catch (PDOException $e) {
            $message = "Une erreur est survenue lors de l'enregistrement. Vérifiez les champs de la table clients. " . $e->getMessage();
        }
    }
}

if (isset($_SESSION['id_client'])) {
    header('Location: panier.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <link rel="stylesheet" href="scrollbar.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compte client | E-commerce</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50 text-gray-800">
    <header class="bg-white border-b border-gray-100">
        <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
            <a href="index.php" class="text-2xl font-extrabold text-green-700">MaBoutique</a>
            <a href="panier.php" class="text-sm font-semibold text-gray-600 hover:text-green-700">Voir le panier</a>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 py-10">
        <div class="grid md:grid-cols-2 gap-8 items-start">
            <section class="bg-green-700 text-white rounded-3xl p-8 md:p-10">
                <span class="inline-flex rounded-full bg-white/15 px-3 py-1 text-sm">Espace client</span>
                <h1 class="text-3xl font-bold mt-5">Bienvenue dans votre boutique</h1>
                <p class="mt-4 text-green-50 leading-7">
                    Créez votre compte pour retrouver vos informations et passer vos commandes facilement.
                </p>
                <div class="mt-8 space-y-4 text-sm text-green-50">
                    <p class="flex items-center gap-2"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>Création gratuite de votre compte</p>
                    <p class="flex items-center gap-2"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>Connexion sécurisée par mot de passe</p>
                    <p class="flex items-center gap-2"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>Accès rapide à votre panier et à vos commandes</p>
                </div>
                <a href="index.php" class="inline-block mt-10 rounded-xl bg-white px-5 py-3 font-bold text-green-700 hover:bg-green-50">
                    Continuer mes achats
                </a>
            </section>

            <section class="bg-white rounded-3xl shadow-sm border border-gray-100 p-6 sm:p-8">
                <div class="flex gap-2 p-1 bg-gray-100 rounded-xl mb-7">
                    <a href="compte_client.php?mode=connexion"
                       class="flex-1 text-center rounded-lg px-3 py-3 text-sm font-bold <?= $mode === 'connexion' ? 'bg-white text-green-700 shadow-sm' : 'text-gray-500' ?>">
                        Se connecter
                    </a>
                    <a href="compte_client.php?mode=inscription"
                       class="flex-1 text-center rounded-lg px-3 py-3 text-sm font-bold <?= $mode === 'inscription' ? 'bg-white text-green-700 shadow-sm' : 'text-gray-500' ?>">
                        Créer un compte
                    </a>
                </div>

                <?php if ($message !== ''): ?>
                    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if ($mode === 'inscription'): ?>
                    <h2 class="text-2xl font-bold text-gray-900">Créer votre compte</h2>
                    <p class="text-sm text-gray-500 mt-1 mb-6">Remplissez les informations ci-dessous.</p>
                    <form method="POST" action="compte_client.php?mode=inscription" class="space-y-4">
                        <input type="hidden" name="action" value="inscription">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_compte_client'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold mb-1">Nom *</label>
                                <input name="nom" required class="w-full rounded-xl border border-gray-200 px-4 py-3 outline-none focus:ring-2 focus:ring-green-500" placeholder="Votre nom">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold mb-1">Postnom</label>
                                <input name="postnom" class="w-full rounded-xl border border-gray-200 px-4 py-3 outline-none focus:ring-2 focus:ring-green-500" placeholder="Votre postnom">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold mb-1">Prénom *</label>
                                <input name="prenom" required class="w-full rounded-xl border border-gray-200 px-4 py-3 outline-none focus:ring-2 focus:ring-green-500" placeholder="Votre prénom">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold mb-1">Téléphone *</label>
                                <input name="telephone" required type="tel" class="w-full rounded-xl border border-gray-200 px-4 py-3 outline-none focus:ring-2 focus:ring-green-500" placeholder="+243...">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Adresse e-mail *</label>
                            <input name="email" required type="email" autocomplete="email" class="w-full rounded-xl border border-gray-200 px-4 py-3 outline-none focus:ring-2 focus:ring-green-500" placeholder="exemple@email.com">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Adresse physique *</label>
                            <input name="adresse" required class="w-full rounded-xl border border-gray-200 px-4 py-3 outline-none focus:ring-2 focus:ring-green-500" placeholder="Votre adresse">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Mot de passe *</label>
                            <input name="mot_de_passe" required type="password" minlength="8" autocomplete="new-password" class="w-full rounded-xl border border-gray-200 px-4 py-3 outline-none focus:ring-2 focus:ring-green-500" placeholder="8 caractères minimum">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Confirmer le mot de passe *</label>
                            <input name="confirmation" required type="password" minlength="8" autocomplete="new-password" class="w-full rounded-xl border border-gray-200 px-4 py-3 outline-none focus:ring-2 focus:ring-green-500" placeholder="Répétez le mot de passe">
                        </div>
                        <button class="w-full rounded-xl bg-green-700 py-3.5 font-bold text-white hover:bg-green-800">Créer mon compte</button>
                    </form>
                    <p class="text-center text-sm text-gray-500 mt-5">Vous avez déjà un compte ?
                        <a href="compte_client.php?mode=connexion" class="font-bold text-green-700">Connectez-vous</a>
                    </p>
                <?php else: ?>
                    <h2 class="text-2xl font-bold text-gray-900">Connexion client</h2>
                    <p class="text-sm text-gray-500 mt-1 mb-6">Accédez à votre espace client.</p>
                    <form method="POST" action="compte_client.php?mode=connexion" class="space-y-5">
                        <input type="hidden" name="action" value="connexion">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_compte_client'], ENT_QUOTES, 'UTF-8') ?>">
                        <div>
                            <label class="block text-sm font-semibold mb-1">Adresse e-mail</label>
                            <input name="email" required type="email" autocomplete="email" class="w-full rounded-xl border border-gray-200 px-4 py-3 outline-none focus:ring-2 focus:ring-green-500" placeholder="exemple@email.com">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Mot de passe</label>
                            <input name="mot_de_passe" required type="password" autocomplete="current-password" class="w-full rounded-xl border border-gray-200 px-4 py-3 outline-none focus:ring-2 focus:ring-green-500" placeholder="Votre mot de passe">
                        </div>
                        <button class="w-full rounded-xl bg-green-700 py-3.5 font-bold text-white hover:bg-green-800">Se connecter</button>
                    </form>
                    <p class="text-center text-sm text-gray-500 mt-5">Vous n'avez pas encore de compte ?
                        <a href="compte_client.php?mode=inscription" class="font-bold text-green-700">Inscrivez-vous</a>
                    </p>
                <?php endif; ?>
            </section>
        </div>
    </main>
</body>
</html>
