<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Bienvenue à CASOMIREC</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { margin:0; padding:0; background:#f5f7fb; font-family: Arial, Helvetica, sans-serif; color:#1f2937; }
        .wrapper { width:100%; background:#f5f7fb; padding:24px 0; }
        .container { max-width:600px; margin:0 auto; background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.06); }
        .header { background:#111827; color:#ffffff; padding:20px 24px; font-size:18px; font-weight:bold; }
        .content { padding:24px; line-height:1.55; font-size:15px; }
        .btn { display:inline-block; background:#2563eb; color:#ffffff !important; text-decoration:none; padding:10px 16px; border-radius:6px; font-weight:600; }
        .btn-secondary { background:#111827; }
        .step { background:#f5f7fb; border-radius:6px; padding:12px 16px; margin:12px 0; }
        .muted { color:#6b7280; font-size:13px; word-break:break-all; }
        .footer { padding:16px 24px; text-align:center; color:#6b7280; font-size:12px; }
        a { color:#2563eb; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <div class="header">CASOMIREC</div>
            <div class="content">
                <p>Bonjour {{ trim(($user->prenom ?? '') . ' ' . ($user->nom ?? '')) ?: ($user->name ?? '') }},</p>

                <p>Bienvenue à la caisse sociale CASOMIREC ! Votre compte a été créé.</p>

                <p>Récapitulatif de votre compte :</p>
                <ul>
                    <li><strong>Nom :</strong> {{ trim(($user->nom ?? '') . ' ' . ($user->prenom ?? '')) ?: $user->name }}</li>
                    <li><strong>Identifiant de connexion (e-mail) :</strong> {{ $user->email }}</li>
                    @if (!empty($user->telephone))
                        <li><strong>Téléphone :</strong> {{ $user->telephone }}</li>
                    @endif
                </ul>

                @if ($setPasswordUrl)
                    <div class="step">
                        <p><strong>Étape 1 – Définissez votre mot de passe</strong></p>
                        <p style="margin:12px 0;">
                            <a class="btn" href="{{ $setPasswordUrl }}" target="_blank" rel="noopener">Définir mon mot de passe</a>
                        </p>
                        <p class="muted">Ce lien est valable {{ $validity }}. S’il ne fonctionne pas, copiez-le dans votre navigateur :<br>
                            <a href="{{ $setPasswordUrl }}" target="_blank" rel="noopener">{{ $setPasswordUrl }}</a>
                        </p>
                    </div>

                    <div class="step">
                        <p><strong>Étape 2 – Connectez-vous</strong></p>
                        <p>Connectez-vous ensuite avec votre adresse e-mail et le mot de passe que vous venez de choisir.</p>
                        <p style="margin:12px 0;">
                            <a class="btn btn-secondary" href="{{ $loginUrl }}" target="_blank" rel="noopener">Se connecter</a>
                        </p>
                    </div>

                    <p class="muted">Lien expiré ? Demandez-en un nouveau sur la page
                        <a href="{{ $forgotUrl }}" target="_blank" rel="noopener">Mot de passe oublié</a>.
                    </p>
                @else
                    <p>Vous pouvez vous connecter avec votre adresse e-mail et le mot de passe choisi lors de l’inscription.</p>
                    <p style="margin:20px 0;">
                        <a class="btn" href="{{ $loginUrl }}" target="_blank" rel="noopener">Se connecter</a>
                    </p>
                @endif

                <p>Si vous n’êtes pas à l’origine de cette inscription, ignorez ce message ou contactez un administrateur.</p>

                <p>Cordialement,<br>L’équipe CASOMIREC</p>
            </div>
            <div class="footer">
                © {{ now()->year }} CASOMIREC. Tous droits réservés.
            </div>
        </div>
    </div>
</body>
</html>
