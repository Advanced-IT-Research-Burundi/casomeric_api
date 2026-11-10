<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Mot de passe modifié</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { margin:0; padding:0; background:#f5f7fb; font-family: Arial, Helvetica, sans-serif; color:#1f2937; }
        .wrapper { width:100%; background:#f5f7fb; padding:24px 0; }
        .container { max-width:600px; margin:0 auto; background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.06); }
        .header { background:#111827; color:#ffffff; padding:20px 24px; font-size:18px; font-weight:bold; }
        .content { padding:24px; line-height:1.55; font-size:15px; }
        .btn { display:inline-block; background:#2563eb; color:#ffffff !important; text-decoration:none; padding:10px 16px; border-radius:6px; font-weight:600; }
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

                <p>Le mot de passe de votre compte CASOMIREC (<strong>{{ $user->email }}</strong>) a été modifié le {{ $changedAt }}.</p>

                <p style="margin:20px 0;">
                    <a class="btn" href="{{ $loginUrl }}" target="_blank" rel="noopener">Se connecter</a>
                </p>

                <p>Si vous n’êtes pas à l’origine de ce changement, demandez immédiatement un nouveau lien
                    <a href="{{ $forgotUrl }}" target="_blank" rel="noopener">ici</a>
                    et prévenez un administrateur.</p>

                <p>Cordialement,<br>L’équipe CASOMIREC</p>
            </div>
            <div class="footer">
                © {{ now()->year }} CASOMIREC. Tous droits réservés.
            </div>
        </div>
    </div>
</body>
</html>
