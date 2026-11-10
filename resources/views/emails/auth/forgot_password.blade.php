<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $isNewAccount ? 'Activez votre compte' : 'Réinitialisation du mot de passe' }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { margin:0; padding:0; background:#f5f7fb; font-family: Arial, Helvetica, sans-serif; color:#1f2937; }
        .wrapper { width:100%; background:#f5f7fb; padding:24px 0; }
        .container { max-width:600px; margin:0 auto; background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.06); }
        .header { background:#111827; color:#ffffff; padding:20px 24px; font-size:18px; font-weight:bold; }
        .content { padding:24px; line-height:1.55; font-size:15px; }
        .btn { display:inline-block; background:#2563eb; color:#ffffff !important; text-decoration:none; padding:10px 16px; border-radius:6px; font-weight:600; }
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

                @if ($isNewAccount)
                    <p>Un compte CASOMIREC vient d’être créé pour vous avec l’adresse <strong>{{ $user->email }}</strong>.</p>
                    <p>Pour l’activer, cliquez sur le bouton ci-dessous et choisissez votre mot de passe.</p>
                @else
                    <p>Nous avons reçu une demande de réinitialisation du mot de passe de votre compte CASOMIREC.</p>
                    <p>Cliquez sur le bouton ci-dessous pour choisir un nouveau mot de passe.</p>
                @endif

                <p style="margin:20px 0;">
                    <a class="btn" href="{{ $resetUrl }}" target="_blank" rel="noopener">
                        {{ $isNewAccount ? 'Définir mon mot de passe' : 'Réinitialiser mon mot de passe' }}
                    </a>
                </p>

                <p>Ce lien expirera dans {{ $validity }}.</p>

                <p class="muted">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br>
                    <a href="{{ $resetUrl }}" target="_blank" rel="noopener">{{ $resetUrl }}</a>
                </p>

                @unless ($isNewAccount)
                    <p>Si vous n’êtes pas à l’origine de cette demande, ignorez ce message : votre mot de passe actuel reste inchangé.</p>
                @endunless

                <p>Cordialement,<br>L’équipe CASOMIREC</p>
            </div>
            <div class="footer">
                © {{ now()->year }} CASOMIREC. Tous droits réservés.
            </div>
        </div>
    </div>
</body>
</html>
